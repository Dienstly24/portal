<?php

namespace App\Services\Family;

use App\Models\ActivityLog;
use App\Models\ArchivierteKundennummer;
use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\CustomerTimeline;
use App\Models\InternalNotification;
use App\Models\Task;
use App\Models\User;
use App\Services\CustomerNumberGenerator;
use App\Services\Matching\CustomerMergeService;
use App\Support\FamilienAlter;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Kinder unter dem Selbststaendigkeitsalter (Betreiber-Auftrag 07.10.2026,
 * KI-094) - die EINE Stelle fuer alles, was ueber das blosse Verknuepfen
 * hinausgeht:
 *
 *  - BEFUND: welche Kinder tragen eine eigene Kundennummer, und was haengt
 *    an ihrer Akte? STRENG LESEND.
 *  - UMSTELLEN: ein Kind mit Nummer wird abhaengiges Familienmitglied unter
 *    dem Elternteil. Die Akte bleibt, die Nummer wird ARCHIVIERT (nie
 *    geloescht, nie neu vergeben). Haengt an der Akte ein Vertrag, eine
 *    Provision, ein Zahlungs- oder Signaturvorgang, wird NICHTS umgestellt -
 *    das entscheidet der Betreiber (Auftrag: "bitte zuerst melden").
 *  - ERINNERN: mit 15 (Einstellung) bekommt das Team eine Aufgabe und eine
 *    Glocke, den eigenen Zugang vorzubereiten - einmal je Kind.
 *  - KUNDENNUMMER VERGEBEN: erst ab 16 (Einstellung), als bewusste Aktion
 *    des Teams; die Familienbeziehung bleibt.
 */
class AbhaengigesKindService
{
    /**
     * Tabellen, deren Eintraege an einer Kinderakte eine ENTSCHEIDUNG des
     * Betreibers verlangen, bevor umgestellt wird: Geld und Willenserklaerung.
     */
    public const BLOCKIERENDE_TABELLEN = [
        'contracts' => 'Verträge',
        'provisions' => 'Provisionen (Ausgang)',
        'contract_commissions' => 'Provisionen (Eingang)',
        'signature_requests' => 'Signaturvorgänge',
    ];

    public function __construct(private readonly CustomerMergeService $mergeService) {}

    /**
     * Kinder mit eigener Kundennummer, die nach der Regel keine tragen
     * duerften (Alter unter dem Selbststaendigkeitsalter). Archivierte
     * Huellen (zusammengefuehrt) zaehlen nicht - sie sind keine Akte mehr.
     *
     * @return Collection<int, Customer>
     */
    public function kinderMitKundennummer(): Collection
    {
        $grenze = Carbon::today()->subYears(FamilienAlter::selbststaendig());

        return Customer::with('user')
            ->whereNotNull('customer_number')
            ->whereNotNull('birth_date')
            ->whereDate('birth_date', '>', $grenze)
            ->orderBy('birth_date')
            ->get();
    }

    /**
     * Akten, die als KIND verknuepft sind, eine Nummer tragen, deren Alter
     * aber unbekannt ist (kein Geburtsdatum). Sie werden NICHT umgestellt -
     * ein Alter wird nie geraten - aber genannt.
     *
     * @return Collection<int, Customer>
     */
    public function kinderOhneGeburtsdatum(): Collection
    {
        $ids = CustomerFamilyRelation::whereIn('relationship_type', CustomerFamilyRelation::CHILD_ROLES)
            ->pluck('related_customer_id');

        return Customer::with('user')
            ->whereIn('id', $ids)
            ->whereNotNull('customer_number')
            ->whereNull('birth_date')
            ->get();
    }

    /**
     * Befund zu EINER Akte - STRENG LESEND.
     *
     * @return array{
     *   customer: Customer, alter: ?int, bezugspersonen: list<array{customer:Customer, rolle:string}>,
     *   bezugsperson: ?Customer, verknuepft: array<string,int>, blockiert: array<string,int>,
     *   portal: array{echte_email:bool, angemeldet:bool, aktiv:bool}, herkunft: ?string, angelegt: ?string
     * }
     */
    public function befund(Customer $kind): array
    {
        $kind->loadMissing('user');
        $verknuepft = $this->mergeService->preview($kind);

        $blockiert = [];
        foreach (self::BLOCKIERENDE_TABELLEN as $tabelle => $label) {
            if (($verknuepft[$tabelle] ?? 0) > 0) {
                $blockiert[$tabelle] = $verknuepft[$tabelle];
            }
        }
        // Eingangs-Provisionen haengen am VERTRAG, nicht am Kunden.
        if (! isset($blockiert['contract_commissions']) && DB::getSchemaBuilder()->hasTable('contract_commissions')) {
            $n = DB::table('contract_commissions')
                ->whereIn('contract_id', DB::table('contracts')->select('id')->where('customer_id', $kind->id))
                ->count();
            if ($n > 0) {
                $blockiert['contract_commissions'] = $n;
            }
        }

        $herkunft = ActivityLog::where('entity_type', 'customer')->where('entity_id', (string) $kind->id)
            ->where('action', 'customer_auto_created')->latest('id')->first();

        $user = $kind->user;

        return [
            'customer' => $kind,
            'alter' => $kind->age(),
            'bezugspersonen' => $this->eltern($kind),
            'bezugsperson' => $this->vorgeschlageneBezugsperson($kind),
            'verknuepft' => $verknuepft,
            'blockiert' => $blockiert,
            'portal' => [
                'echte_email' => (bool) $user?->hasRealEmail(),
                'angemeldet' => $user?->first_login_at !== null,
                'aktiv' => $user !== null && ! (isset($user->is_active) && ! $user->is_active),
            ],
            'herkunft' => $herkunft ? (string) (($herkunft->meta['source'] ?? null) ?: 'automatisch') : ($kind->source ?: null),
            'angelegt' => LocalTime::for($kind->created_at)?->format('d.m.Y H:i'),
        ];
    }

    /**
     * Eltern einer Akte laut Familienbeziehung (Rolle aus Sicht des Kindes:
     * Zeile customer=Kind, related=Elternteil, Rolle vater/mutter/elternteil).
     *
     * @return list<array{customer:Customer, rolle:string}>
     */
    public function eltern(Customer $kind): array
    {
        return CustomerFamilyRelation::with('relatedCustomer.user')
            ->where('customer_id', $kind->id)
            ->whereIn('relationship_type', CustomerFamilyRelation::PARENT_ROLES)
            ->get()
            ->filter(fn (CustomerFamilyRelation $r) => $r->relatedCustomer !== null)
            ->map(fn (CustomerFamilyRelation $r) => ['customer' => $r->relatedCustomer, 'rolle' => $r->relationship_type])
            ->values()->all();
    }

    /**
     * Vater vor Mutter vor sonstigem Elternteil (Betreiber-Vorgabe). Gibt es
     * keinen eindeutigen Elternteil, wird KEINER vorgeschlagen.
     */
    public function vorgeschlageneBezugsperson(Customer $kind): ?Customer
    {
        $eltern = collect($this->eltern($kind));
        foreach (['vater', 'mutter'] as $rolle) {
            $treffer = $eltern->where('rolle', $rolle);
            if ($treffer->count() === 1) {
                return $treffer->first()['customer'];
            }
            if ($treffer->count() > 1) {
                return null;
            }
        }

        return $eltern->count() === 1 ? $eltern->first()['customer'] : null;
    }

    /**
     * Kind mit Kundennummer zum abhaengigen Familienmitglied umstellen.
     *
     * Ablauf in EINER Transaktion: Nummer ins Archiv, Nummer an der Akte
     * leeren, Kind als Kind des Elternteils verknuepfen (bzw. die
     * Abhaengigkeit an der vorhandenen Beziehung setzen), Portalzugang
     * stilllegen, Vermerk in BEIDEN Akten und im ActivityLog. Geloescht wird
     * nichts; Dokumente, Vorgaenge und Historie bleiben an der Kinderakte,
     * die unter dem Elternteil steht.
     *
     * @throws \DomainException wenn die Akte nicht umgestellt werden darf
     */
    public function umstellen(Customer $kind, Customer $elternteil, ?int $byUserId = null): string
    {
        $befund = $this->befund($kind);
        if (blank($kind->customer_number)) {
            throw new \DomainException('Die Akte trägt bereits keine Kundennummer.');
        }
        if (! $kind->unterSelbststaendigkeitsalter()) {
            throw new \DomainException('Die Person ist nicht jünger als '.FamilienAlter::selbststaendig().' Jahre (oder das Geburtsdatum fehlt) – keine Umstellung.');
        }
        if ((string) $kind->id === (string) $elternteil->id) {
            throw new \DomainException('Kind und Elternteil sind dieselbe Akte.');
        }
        if (! $elternteil->istEigenstaendig()) {
            throw new \DomainException('Der gewählte Elternteil ist selbst kein eigenständiger Kunde.');
        }
        if ($befund['blockiert'] !== []) {
            $liste = collect($befund['blockiert'])->map(fn ($n, $t) => (self::BLOCKIERENDE_TABELLEN[$t] ?? $t).': '.$n)->implode(', ');
            throw new \DomainException('An der Akte hängen '.$liste.' – bitte zuerst mit dem Betreiber klären, nichts wurde geändert.');
        }

        $alteNummer = (string) $kind->customer_number;

        DB::transaction(function () use ($kind, $elternteil, $alteNummer, $byUserId) {
            ArchivierteKundennummer::create([
                'customer_number' => $alteNummer,
                'customer_id' => $kind->id,
                'bezugsperson_customer_id' => $elternteil->id,
                'grund' => ArchivierteKundennummer::GRUND_MINDERJAEHRIG,
                'notiz' => 'Kind unter '.FamilienAlter::selbststaendig().' Jahren – Nummer zu Unrecht vergeben, jetzt abhängiges Familienmitglied.',
                'archiviert_von' => $byUserId,
            ]);

            $kind->forceFill(['customer_number' => null])->save();

            // Rolle: vorhandene Kind-Rolle bleibt; sonst nach Geschlecht.
            $vorhanden = CustomerFamilyRelation::where('customer_id', $elternteil->id)
                ->where('related_customer_id', $kind->id)->value('relationship_type');
            $rolle = in_array($vorhanden, CustomerFamilyRelation::CHILD_ROLES, true)
                ? $vorhanden
                : match ($kind->gender) {
                    'male' => 'sohn',
                    'female' => 'tochter',
                    default => 'kind',
                };
            $relation = app(FamilyRelationService::class)->link($elternteil, $kind, $rolle, $byUserId, 'Abhängiges Familienmitglied (KI-094)');
            if (! $relation->is_dependent) {
                $relation->forceFill(['is_dependent' => true, 'independent_since' => null])->save();
            }

            // Ein Kind hat kein eigenes Portal - ein vorhandener Zugang wird
            // STILLGELEGT, nicht geloescht (wie beim Zusammenfuehren).
            if ($kind->user && ! (isset($kind->user->is_active) && ! $kind->user->is_active)) {
                $kind->user->forceFill(['is_active' => false])->save();
            }
        });

        $kindName = $kind->user?->name ?: 'Kind';
        $this->vermerk($kind, 'Abhängiges Familienmitglied',
            'Kundennummer '.$alteNummer.' archiviert (nicht gelöscht, wird nie neu vergeben). '
            .'Geführt unter der Akte von '.($elternteil->user?->name ?: 'Elternteil').' ('.$elternteil->customer_number.'). '
            .'Eigene Kundennummer und Portal ab '.FamilienAlter::selbststaendig().' Jahren.', $byUserId);
        $this->vermerk($elternteil, 'Kind als abhängiges Familienmitglied geführt',
            $kindName.' (frühere Kundennummer '.$alteNummer.', archiviert) wird jetzt unter dieser Akte geführt.', $byUserId);

        ActivityLog::record('customer_dependent_converted', 'customer', (string) $kind->id, [
            'archivierte_nummer' => $alteNummer,
            'bezugsperson_customer_id' => (string) $elternteil->id,
        ], $byUserId);

        return $alteNummer;
    }

    /**
     * Faellige Erinnerungen: abhaengige Kinder, die das Erinnerungsalter
     * erreicht, das Selbststaendigkeitsalter aber noch NICHT erreicht haben
     * und noch nicht erinnert wurden.
     *
     * @return Collection<int, CustomerFamilyRelation> je Kind EINE Beziehung
     *         (die zum Vater, sonst zur Mutter, sonst zur ersten Bezugsperson)
     */
    public function faelligeErinnerungen(): Collection
    {
        $heute = Carbon::today();
        $erinnerungAb = $heute->copy()->subYears(FamilienAlter::erinnerung());   // geboren am/vor
        $selbstAb = $heute->copy()->subYears(FamilienAlter::selbststaendig());   // geboren nach

        return CustomerFamilyRelation::query()
            ->where('is_dependent', true)
            ->with(['customer.user', 'relatedCustomer.user'])
            ->whereHas('relatedCustomer', fn ($q) => $q->whereNotNull('birth_date')
                ->whereNull('portal_vorbereitung_erinnert_at')
                ->whereDate('birth_date', '<=', $erinnerungAb)
                ->whereDate('birth_date', '>', $selbstAb))
            ->get()
            ->filter(fn (CustomerFamilyRelation $r) => $r->isCurrent() && $r->customer !== null)
            ->groupBy('related_customer_id')
            ->map(function (Collection $gruppe) {
                $kind = $gruppe->first()->relatedCustomer;
                $vorzug = $kind?->familyGuardian();

                return $gruppe->first(fn (CustomerFamilyRelation $r) => $vorzug && (string) $r->customer_id === (string) $vorzug->id)
                    ?? $gruppe->first();
            })
            ->values();
    }

    /**
     * Erinnerung "Kind wird 15 - Zugang vorbereiten": Aufgabe an der Akte
     * des ELTERNTEILS (dort steht sie in der Kundenakte und in der
     * Aufgabenliste des Teams), Glocke an dessen Betreuer, Vermerk in beiden
     * Akten. Genau einmal je Kind (`portal_vorbereitung_erinnert_at`).
     */
    public function erinnern(CustomerFamilyRelation $relation): ?Task
    {
        $kind = $relation->relatedCustomer;
        $elternteil = $relation->customer;
        if ($kind === null || $elternteil === null || $kind->portal_vorbereitung_erinnert_at !== null) {
            return null;
        }

        $name = $kind->user?->name ?: 'Familienmitglied';
        $alter = $kind->age() ?? FamilienAlter::erinnerung();
        $stichtag = $relation->independenceDate();
        $zustaendig = $this->zustaendig($elternteil, $kind);
        $titel = 'Das abhängige Familienmitglied '.$name.' ist '.$alter.' – Portal und eigenes Konto vorbereiten';

        $task = null;
        DB::transaction(function () use (&$task, $kind, $elternteil, $titel, $name, $stichtag, $zustaendig) {
            if ($zustaendig !== null) {
                $task = Task::forceCreate([
                    'title' => $titel,
                    'description' => $name.' ist Kind von '.($elternteil->user?->name ?: '—').'. '
                        .'Ab '.FamilienAlter::selbststaendig().' Jahren ('.($stichtag ? $stichtag->format('d.m.Y') : 'Datum unbekannt').') '
                        .'darf eine eigene Kundennummer vergeben und das Portal aktiviert werden. '
                        .'Vorbereiten: eigene E-Mail-Adresse und Telefonnummer erfragen, Verträge/Vorgänge prüfen. '
                        .'Vorher wird nichts automatisch geändert.',
                    'type' => 'reminder',
                    'status' => 'open',
                    'priority' => 'medium',
                    'due_date' => $stichtag ? $stichtag->toDateString() : now()->addMonths(6)->toDateString(),
                    'created_by' => null,
                    'assigned_to' => $zustaendig->id,
                    'customer_id' => $elternteil->id,
                ]);
            }
            $kind->forceFill(['portal_vorbereitung_erinnert_at' => now()])->save();
        });

        foreach ($this->empfaenger($elternteil, $kind) as $user) {
            try {
                InternalNotification::updateOrCreate(
                    ['dedup_key' => 'kind-erinnerung-'.$kind->id.'-'.$user->id],
                    [
                        'user_id' => $user->id,
                        'type' => 'family_transition',
                        'title' => $titel,
                        'body' => 'Bitte eigenen Portalzugang vorbereiten. Eigene Kundennummer ab '.FamilienAlter::selbststaendig().' Jahren.',
                        'link' => route('admin.customer', $elternteil->id),
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('Glocke zur Kinder-Erinnerung fehlgeschlagen: '.$e->getMessage());
            }
        }

        $this->vermerk($elternteil, 'Erinnerung: Zugang für '.$name.' vorbereiten', $titel.'.', null);
        $this->vermerk($kind, 'Erinnerung: eigenen Zugang vorbereiten', $titel.'.', null);

        ActivityLog::record('family_dependent_reminder', 'customer', (string) $kind->id, [
            'bezugsperson_customer_id' => (string) $elternteil->id,
            'task_id' => $task?->id,
        ], null);

        return $task;
    }

    /**
     * Eigene Kundennummer vergeben - erst ab dem Selbststaendigkeitsalter
     * und nur fuer eine Akte, die noch keine traegt. Die Familienbeziehung
     * bleibt bestehen, nur die Abhaengigkeit endet.
     *
     * @throws \DomainException
     */
    public function kundennummerVergeben(Customer $kind, ?int $byUserId = null): string
    {
        if (filled($kind->customer_number)) {
            throw new \DomainException('Die Akte hat bereits eine Kundennummer.');
        }
        $alter = $kind->age();
        if ($alter === null) {
            throw new \DomainException('Ohne Geburtsdatum kann das Alter nicht belegt werden – bitte zuerst das Geburtsdatum erfassen.');
        }
        if ($alter < FamilienAlter::selbststaendig()) {
            throw new \DomainException('Eine eigene Kundennummer ist erst ab '.FamilienAlter::selbststaendig().' Jahren möglich (derzeit '.$alter.').');
        }

        $nummer = DB::transaction(function () use ($kind) {
            $nummer = app(CustomerNumberGenerator::class)->generate();
            $kind->forceFill(['customer_number' => $nummer])->save();

            CustomerFamilyRelation::where('related_customer_id', $kind->id)->where('is_dependent', true)
                ->update(['is_dependent' => false, 'independent_since' => now()]);

            return $nummer;
        });

        $this->vermerk($kind, 'Eigene Kundennummer vergeben',
            'Kundennummer '.$nummer.' vergeben (ab '.FamilienAlter::selbststaendig().' Jahren). Die Familienbeziehung bleibt bestehen. '
            .'Portal: Einladung über „Portal-Zugang" senden.', $byUserId);

        ActivityLog::record('customer_number_granted_dependent', 'customer', (string) $kind->id, [
            'customer_number' => $nummer,
        ], $byUserId);

        return $nummer;
    }

    /**
     * Am Selbststaendigkeitstag (Tageslauf): Aufgabe "Kundennummer vergeben
     * und Portal aktivieren" - nur, wenn die Akte noch keine Nummer traegt.
     */
    public function aufgabeBeiSelbststaendigkeit(CustomerFamilyRelation $relation): ?Task
    {
        $kind = $relation->relatedCustomer;
        $elternteil = $relation->customer;
        if ($kind === null || $elternteil === null || filled($kind->customer_number)) {
            return null;
        }
        $zustaendig = $this->zustaendig($elternteil, $kind);
        if ($zustaendig === null) {
            return null;
        }

        return Task::forceCreate([
            'title' => ($kind->user?->name ?: 'Familienmitglied').' ist '.FamilienAlter::selbststaendig().' – Kundennummer vergeben und Portal aktivieren',
            'description' => 'In der Akte des Kindes „Kundennummer vergeben" wählen, danach zum Portal einladen. Die Familienbeziehung bleibt bestehen.',
            'type' => 'reminder',
            'status' => 'open',
            'priority' => 'medium',
            'due_date' => now()->toDateString(),
            'created_by' => null,
            'assigned_to' => $zustaendig->id,
            'customer_id' => $kind->id,
        ]);
    }

    /** Betreuer des Elternteils, sonst des Kindes, sonst der erste Admin. */
    private function zustaendig(Customer $elternteil, Customer $kind): ?User
    {
        return $elternteil->betreuerPrimary()
            ?? $kind->betreuerPrimary()
            ?? User::where('role', 'admin')->orderBy('id')->first();
    }

    /** @return Collection<int, User> */
    private function empfaenger(Customer $elternteil, Customer $kind): Collection
    {
        $users = $elternteil->betreuer()->get()->merge($kind->betreuer()->get())->unique('id');
        if ($users->isEmpty() && ($admin = User::where('role', 'admin')->orderBy('id')->first())) {
            $users = collect([$admin]);
        }

        return $users->values();
    }

    /** Vermerk in der Kundenakte. Darf den Vorgang nie scheitern lassen. */
    private function vermerk(Customer $customer, string $titel, string $text, ?int $byUserId): void
    {
        try {
            CustomerTimeline::create([
                'customer_id' => $customer->id,
                'user_id' => $byUserId,
                'type' => 'family',
                'title' => $titel,
                'description' => $text,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Timeline-Vermerk (Kind) fehlgeschlagen: '.$e->getMessage());
        }
    }
}
