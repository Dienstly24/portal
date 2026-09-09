<?php

namespace App\Services\Signature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerTimeline;
use App\Models\Document;
use App\Models\SignatureRequest;
use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\CustomerCreation\DuplicateCustomerException;
use App\Services\Matching\CustomerMatchingService;
use App\Services\Matching\MatchResult;
use App\Support\LocalTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Die ZUORDNUNG - der Grund, warum eine Signaturanfrage ohne Kunden
 * existieren darf.
 *
 * REGEL: es wird NIE automatisch zugeordnet, auch nicht bei einem exakten
 * Treffer der E-Mail-Adresse. Eine Adresse ist kein Identitaetsnachweis
 * (Familienpostfach, Firmenpostfach, Namensvetter) - und ein
 * unterschriebener Vertrag in der falschen Akte ist ein Datenschutzvorfall,
 * kein Schoenheitsfehler. Das System SCHLAEGT VOR und nennt den Grund; die
 * Entscheidung trifft ein Mensch per Klick. Dieselbe Haltung wie im
 * Dokumenten-Eingang und beim Provisions-Abgleich.
 */
class SignatureDocumentService
{
    public function __construct(
        private readonly SignatureStorage $storage,
        private readonly SignatureAuditService $audit,
        private readonly CustomerMatchingService $matcher,
        private readonly CustomerAutoCreationService $creator,
    ) {
    }

    /**
     * Kundenvorschlaege zu einer Anfrage - je Vorschlag mit GRUND, damit der
     * Mitarbeiter die Entscheidung treffen kann statt sie zu raten.
     *
     * @return list<array{customer: Customer, grund: string}>
     */
    public function suggestions(SignatureRequest $request, ?array $visibleCustomerIds = null): array
    {
        $out = [];
        $seen = [];

        foreach ($request->signers as $signer) {
            $query = Customer::query()
                ->with('user')
                ->whereHas('user', fn ($q) => $q->where('email', $signer->email))
                ->limit(5);
            if ($visibleCustomerIds !== null) {
                $query->whereIn('customers.id', $visibleCustomerIds);
            }
            foreach ($query->get() as $customer) {
                if (isset($seen[$customer->id])) {
                    continue;
                }
                $seen[$customer->id] = true;
                $out[] = ['customer' => $customer, 'grund' => 'E-Mail-Adresse stimmt überein ('.$signer->email.')'];
            }

            foreach ($this->matcher->topMatches(['full_name' => $signer->name, 'email' => $signer->email], 5) as $result) {
                $customer = $result->customer;
                if (! $customer instanceof Customer || isset($seen[$customer->id])) {
                    continue;
                }
                if ($visibleCustomerIds !== null && ! in_array((string) $customer->id, array_map('strval', $visibleCustomerIds), true)) {
                    continue;
                }
                $seen[$customer->id] = true;
                $out[] = ['customer' => $customer, 'grund' => $this->reason($result)];
            }
        }

        return array_slice($out, 0, 8);
    }

    /**
     * Ordnet die Anfrage einem bestehenden Kunden zu und legt das
     * unterschriebene PDF in seine Akte.
     */
    public function assignCustomer(SignatureRequest $request, Customer $customer, ?Contract $contract = null): Document
    {
        return DB::transaction(function () use ($request, $customer, $contract) {
            $request->forceFill([
                'customer_id' => $customer->id,
                'contract_id' => $contract->id ?? $request->contract_id,
            ])->save();

            $this->audit->record($request, 'customer_linked', description: $customer->customer_number.' / '.($customer->user->name ?? ''));
            if ($contract !== null) {
                $this->audit->record($request, 'contract_linked', description: (string) $contract->contract_number);
            }

            $document = $this->storeAsDocument($request, $customer, $contract);

            CustomerTimeline::create([
                'customer_id' => $customer->id,
                'type' => 'document',
                'title' => 'Unterschriebenes Dokument zugeordnet',
                'description' => $request->title.' ('.$request->signers->count().' Unterzeichner)',
                'user_id' => auth()->id(),
            ]);

            return $document;
        });
    }

    /**
     * Legt aus den Angaben des Unterzeichners einen NEUEN Kunden an und
     * ordnet zu. Der Duplikatsschutz des Bestands gilt unveraendert - findet
     * er einen Kandidaten, wird nichts angelegt und der Mitarbeiter sieht
     * den Vorschlag.
     *
     * @throws DuplicateCustomerException
     */
    public function createCustomer(SignatureRequest $request, string $signerId): Customer
    {
        $signer = $request->signers->firstWhere('id', $signerId) ?? $request->signers->first();
        if ($signer === null) {
            throw new \RuntimeException('Die Signaturanfrage hat keinen Unterzeichner.');
        }

        $customer = $this->creator->createFromUnmatched([
            'full_name' => $signer->name,
            'email' => $signer->email,
        ], 'manual', auth()->id());

        $this->audit->record($request, 'customer_created', description: $customer->customer_number.' / '.$signer->name);
        $this->assignCustomer($request, $customer);

        return $customer;
    }

    /**
     * Legt das unterschriebene PDF als normales Kundendokument ab.
     *
     * BEWUSST EIN `documents`-EINTRAG und keine eigene Tabelle: damit
     * erscheint es von selbst in der Kundenakte, im Kundenportal, in der
     * Suche und in den Berichten. Eine zweite Dokumentenwelt haette jede
     * dieser Stellen doppelt zu bedienen - und eine davon waere vergessen
     * worden.
     *
     * Die Datei wird KOPIERT, nicht verschoben: das Original der
     * Signaturanfrage bleibt, wo es ist, und der Nachweis bleibt
     * vollstaendig, auch wenn jemand spaeter das Kundendokument loescht.
     */
    private function storeAsDocument(SignatureRequest $request, Customer $customer, ?Contract $contract): Document
    {
        if ($request->completed_document_id !== null) {
            $existing = Document::find($request->completed_document_id);
            if ($existing !== null) {
                $existing->forceFill([
                    'customer_id' => $customer->id,
                    'contract_id' => $contract->id ?? $existing->contract_id,
                ])->save();

                return $existing;
            }
        }

        $binary = $this->storage->read($request->signed_path);
        if ($binary === null) {
            throw new \RuntimeException('Das unterschriebene PDF fehlt im Speicher.');
        }

        $path = 'customers/'.$customer->id.'/signaturen/'.$request->id.'.pdf';
        $this->storage->disk()->put($path, $binary);

        $document = Document::create([
            'id' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'contract_id' => $contract->id ?? $request->contract_id,
            'category' => 'contract',
            'file_name' => $this->fileName($request),
            'file_path' => $path,
            'disk' => SignatureStorage::DISK,
            'visibility' => 'customer',
            'uploaded_by' => auth()->id(),
            'file_size' => strlen($binary),
            'content_hash' => $request->signed_hash,
            'page_count' => $request->page_count + 1, // Protokollseite
            'ai_status' => 'done',
            'ai_type' => 'sonstiges',
            'ai_source' => 'signatur',
            'ai_summary' => 'Elektronisch unterschrieben am '
                .(LocalTime::for($request->completed_at)?->format('d.m.Y H:i') ?? '-').' Uhr.',
        ]);

        $request->forceFill(['completed_document_id' => $document->id])->save();

        return $document;
    }

    private function fileName(SignatureRequest $request): string
    {
        $base = Str::slug($request->title) ?: 'dokument';

        return mb_substr($base, 0, 80).'-unterschrieben.pdf';
    }

    /** Der staerkste Grund eines Treffers, im Klartext. */
    private function reason(MatchResult $result): string
    {
        $parts = array_filter($result->breakdown, fn ($b) => $b['points'] > 0);
        uasort($parts, fn ($a, $b) => $b['points'] <=> $a['points']);
        $first = reset($parts);

        return $first === false ? 'Ähnlichkeit' : (string) $first['reason'];
    }
}
