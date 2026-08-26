<?php
namespace App\Services\CommissionImport;

use App\Models\CommissionImport;
use App\Models\Contract;
use App\Models\Customer;
use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\CustomerCreation\DuplicateCustomerException;
use App\Services\Matching\CustomerMatchingService;
use App\Services\Vermittler\VermittlerReference;

/**
 * Aus einer nicht zugeordneten Zeile einen VERTRAG (und notfalls einen
 * KUNDEN) anlegen (Betreiber-Entscheidung 26.08.2026).
 *
 * WARUM ES DAS GIBT: In den echten Dateien des Betriebs findet der Abgleich
 * den Vertrag meistens NICHT - nicht weil die Zuordnung schlecht waere,
 * sondern weil der Vertrag nie im Portal erfasst wurde. Die Datei traegt
 * dann Angaben, die es sonst nirgends gibt (Kunde, Gesellschaft, Sparte,
 * beide Vertragsnummern). Diese Zeilen wegzuwerfen hiesse, den Bestand
 * dauerhaft unvollstaendig zu lassen.
 *
 * WARUM ES TROTZDEM NICHT AUTOMATISCH LAEUFT: Ein einziger Lauf kann
 * mehrere hundert Kunden und Vertraege anlegen. Das ist eine Entscheidung,
 * kein Nebeneffekt - deshalb ist es ein eigener, ausdruecklich angehakter
 * Schritt mit vorher sichtbarer Anzahl.
 *
 * DIE GRENZEN, die auch dieser Schritt nicht ueberschreitet:
 *  - Ohne verwertbaren KUNDENNAMEN entsteht nichts. Eine Zeile, die nur
 *    "Kfz-Versicherung Abschluss" und einen Betrag kennt (so sieht der
 *    Vergleichsportal-Export aus), ergaebe eine leere Akte ohne Menschen
 *    darin - die waere schaedlicher als die fehlende Zeile.
 *  - Der neue Vertrag ist NIE "aktiv". Dass Geld geflossen ist, belegt,
 *    dass es den Vertrag GAB - nicht, dass er heute laeuft. Er entsteht als
 *    "In Bearbeitung" und zaehlt damit nicht zum aktiven Bestand, bis ein
 *    Mensch ihn bestaetigt.
 *  - Ein VORHANDENER Kunde wird nie dupliziert: gesucht wird zuerst ueber
 *    den Duplikatsschutz des Hauses (CustomerMatchingService).
 */
class CommissionContractBuilder
{
    /**
     * Gedaechtnis fuer den Namensabgleich innerhalb EINES Laufs:
     * normalisierter Name => customer_id, null (nicht vorhanden) oder
     * 'ambiguous'. Ohne es waere jede der tausenden Zeilen eine eigene
     * Datenbankabfrage.
     *
     * @var array<string,string|null>
     */
    private array $nameCache = [];

    public function __construct(
        private CustomerMatchingService $matcher,
        private CustomerAutoCreationService $creator,
        private CommissionAuditLogger $audit,
        private PersonNameParser $names = new PersonNameParser(),
    ) {
    }

    /**
     * Sparte aus den Angaben der Quelle. Erkannt wird ueber den PRODUKTNAMEN
     * (er ist konkret: "Gewerbe-Haftpflicht", "Kfz (inkl. Smart-Tarife)"),
     * ersatzweise ueber die grobe Sparte der Quelle. Was hier nicht steht,
     * wird `andere` - nie geraten, denn eine falsche Sparte verschiebt den
     * Vertrag in die falsche Bestandsgruppe.
     *
     * @var array<string,string> Stichwort (klein) => Contract::TYPES-Schluessel
     */
    private const TYPE_KEYWORDS = [
        'frachtfuehrer' => 'frachtfuehrerhaftpflicht',
        'frachtführer' => 'frachtfuehrerhaftpflicht',
        'verkehrshaftung' => 'frachtfuehrerhaftpflicht',
        'gewerbe-haftpflicht' => 'betriebshaftpflicht',
        'betriebshaftpflicht' => 'betriebshaftpflicht',
        'firmenhaftpflicht' => 'betriebshaftpflicht',
        'rechtsschutz' => 'rechtsschutz',
        'hausrat' => 'hausrat',
        'unfall' => 'unfall',
        'wohngebaeude' => 'sach',
        'wohngebäude' => 'sach',
        'kfz' => 'kfz',
        'auto' => 'kfz',
        'motorrad' => 'kfz',
        'krankenzusatz' => 'krankenzusatz',
        'zusatzversicherung' => 'krankenzusatz',
        'kranken' => 'krankenversicherung',
        'pflege' => 'krankenversicherung',
        'lebensversicherung' => 'leben',
        'rente' => 'leben',
        'risikoleben' => 'leben',
        'berufsunfaehigkeit' => 'leben',
        'schutzbrief' => 'schutzbrief',
        'e-scooter' => 'escooter',
        'escooter' => 'escooter',
        'dsl' => 'internet',
        'internet' => 'internet',
        'oekostrom' => 'strom',
        'ökostrom' => 'strom',
        'strom' => 'strom',
        'erdgas' => 'gas',
        'gas' => 'gas',
        'haftpflicht' => 'haftpflicht', // ZULETZT: sonst schluckt es die gewerblichen
    ];

    /**
     * Vertrag (und ggf. Kunde) fuer eine gedeutete Zeile herstellen.
     *
     * @param array<string,mixed> $mapped
     * @return array{contract:?Contract,customer:?Customer,created_contract:bool,created_customer:bool,note:string}
     */
    public function build(array $mapped, CommissionImport $import, ?int $userId = null): array
    {
        $nothing = fn (string $note) => [
            'contract' => null, 'customer' => null,
            'created_contract' => false, 'created_customer' => false, 'note' => $note,
        ];

        $parsed = $this->names->parse(is_string($mapped['customer_name'] ?? null) ? $mapped['customer_name'] : null);
        if ($parsed['name'] === null) {
            return $nothing('Kein verwertbarer Kundenname in der Zeile – es wurde bewusst nichts angelegt.');
        }

        $identity = $this->identity($mapped);
        if ($identity === null) {
            return $nothing('Keine verwertbare Kennung – ohne sie liesse sich der Vertrag später nicht wiederfinden.');
        }

        // --- Kunde: erst suchen, dann anlegen ---------------------------
        $criteria = $this->criteria($parsed, $mapped);
        $createdCustomer = false;

        // ZUERST der EXAKTE Namenstreffer - er ist die entscheidendere und
        // zugleich billigste Frage. Der uebliche Abgleich gewichtet
        // Geburtsdatum, Anschrift und Kontaktdaten; eine Abrechnung liefert
        // davon meist NICHTS ausser dem Namen und kommt nie ueber die
        // Schwelle. Ohne diese Frage entstuende zu jedem Bestandskunden eine
        // zweite Akte. Der Treffer zaehlt nur, wenn er EINDEUTIG ist: tragen
        // zwei Kunden denselben Namen, wird nichts angelegt und nichts
        // verknuepft.
        $exact = $this->byExactName($parsed['name']);
        if ($exact === 'ambiguous') {
            return $nothing(
                'Mehrere Kundenakten heißen „' . $parsed['name'] . '“. '
                . 'Es wurde bewusst nichts angelegt – bitte von Hand zuordnen.'
            );
        }
        $customer = $exact;

        if ($customer === null) {
            // Der unscharfe Abgleich laeuft NUR EINMAL - naemlich im
            // Duplikatsschutz der Neuanlage selbst. Ihn vorher noch einmal
            // aufzurufen hiesse, die teuerste Abfrage des Laufs je Zeile zu
            // verdoppeln (bei der Auftragsliste des Betriebs: 581 Zeilen).
            try {
                $customer = $this->creator->createFromUnmatched($criteria, 'import', $userId);
            } catch (DuplicateCustomerException $e) {
                // Sichere Stufe "auto" = derselbe Mensch, also verknuepfen.
                // Die Zwischenstufe "confirm" heisst "koennte sein" - und aus
                // einem Vielleicht darf hier nichts werden.
                if ($e->matchResult->tier() === 'auto' && $e->matchResult->customer !== null) {
                    $customer = $e->matchResult->customer;
                    $this->rememberCustomer($customer, $parsed['name']);
                    return $this->withContract($customer, $parsed, $mapped, $import, false);
                }
                $candidate = $e->matchResult->customer;
                return $nothing(
                    'Es gibt bereits eine ähnliche Kundenakte ('
                    . ($candidate?->user?->name ?? $candidate?->customer_number ?? 'unbekannt')
                    . ', Übereinstimmung ' . $e->matchResult->score . '%). '
                    . 'Es wurde bewusst nichts angelegt – bitte von Hand zuordnen.'
                );
            }
            $createdCustomer = true;
            $this->rememberCustomer($customer, $parsed['name']);
            if ($createdCustomer) {
                $customer->forceFill(['commission_import_id' => $import->id])->saveQuietly();
                $this->audit->log('kunde_angelegt', null, [
                    'import_id' => $import->id,
                    'source_file' => $import->filename,
                    'new_value' => $parsed['name'] . ' (' . $customer->customer_number . ')',
                ]);
            }
        }

        return $this->withContract($customer, $parsed, $mapped, $import, $createdCustomer);
    }

    /**
     * Den Vertrag zum (gefundenen oder neuen) Kunden anlegen.
     *
     * Eigene Methode, weil beide Wege hier zusammenlaufen: der neu angelegte
     * Kunde und der ueber den Duplikatsschutz wiedergefundene. Zwei Kopien
     * dieses Blocks wuerden frueher oder spaeter auseinanderlaufen.
     *
     * @param array{name:?string,gender:?string,company:bool} $parsed
     * @param array<string,mixed> $mapped
     * @return array{contract:?Contract,customer:?Customer,created_contract:bool,created_customer:bool,note:string}
     */
    private function withContract(Customer $customer, array $parsed, array $mapped, CommissionImport $import, bool $createdCustomer): array
    {
        $contract = Contract::create(array_filter([
            'customer_id' => $customer->id,
            'contract_number' => VermittlerReference::display($mapped['external_contract_number'] ?? null),
            'internal_contract_number' => VermittlerReference::display($mapped['internal_contract_number'] ?? null),
            'reference_number' => VermittlerReference::display($mapped['reference_number'] ?? null)
                ?? VermittlerReference::display($mapped['order_number'] ?? null),
            'vermittler_id' => VermittlerReference::display($mapped['vermittler_id'] ?? null),
            'type' => $this->type($mapped),
            'insurer' => ValueParser::text($mapped['company'] ?? null)
                ?? ValueParser::text($mapped['product_name'] ?? null)
                ?? 'Unbekannt (aus Abrechnung)',
            // NIE "aktiv": die Abrechnung belegt, dass es den Vertrag GAB -
            // nicht, dass er heute laeuft. "In Bearbeitung" haelt ihn aus dem
            // aktiven Bestand heraus, bis ein Mensch ihn bestaetigt.
            'status' => Contract::STATUS_PENDING,
            'start_date' => ($mapped['contract_start'] ?? null)?->format('Y-m-d'),
            'notes' => $this->note($mapped, $import),
        ], fn ($v) => $v !== null && $v !== ''));

        $contract->forceFill(['commission_import_id' => $import->id])->saveQuietly();

        $this->audit->log('vertrag_angelegt', null, [
            'contract_id' => $contract->id,
            'internal_contract_number' => $contract->internal_contract_number,
            'import_id' => $import->id,
            'source_file' => $import->filename,
            'new_value' => trim($contract->typeLabel() . ' · ' . $contract->insurer . ' · ' . $parsed['name']),
        ]);

        return [
            'contract' => $contract,
            'customer' => $customer,
            'created_contract' => true,
            'created_customer' => $createdCustomer,
            'note' => $createdCustomer
                ? 'Kunde und Vertrag neu angelegt (Status „In Bearbeitung“ – bitte prüfen).'
                : 'Vertrag beim vorhandenen Kunden ' . ($customer->user?->name ?? $customer->customer_number)
                    . ' angelegt (Status „In Bearbeitung“ – bitte prüfen).',
        ];
    }

    /**
     * Traegt die Zeile ueberhaupt genug, um daraus einen Vertrag zu machen?
     * Wird VOR dem Anlegen gefragt, damit die Vorschau eine ehrliche Anzahl
     * nennen kann.
     *
     * @param array<string,mixed> $mapped
     */
    public function isBuildable(array $mapped): bool
    {
        return $this->names->parse(is_string($mapped['customer_name'] ?? null) ? $mapped['customer_name'] : null)['name'] !== null
            && $this->identity($mapped) !== null;
    }

    /**
     * Kunde ueber einen EXAKTEN Namenstreffer.
     *
     * Verglichen wird eine normalisierte Fassung (Kleinschreibung, einfache
     * Leerzeichen) - "Mohamad Adnan Ranko" trifft also auch
     * "VN RANKO, MOHAMAD ADNAN" nach dem Umdrehen. Bewusst KEIN Teiltreffer
     * und keine Aehnlichkeit: "Ahmad" darf nicht auf "Ahmad Al Huweij"
     * passen. Interne Platzhalter-Namen zaehlen nie.
     *
     * @return Customer|string|null Customer, 'ambiguous' oder null
     */
    private function byExactName(string $name): Customer|string|null
    {
        $needle = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
        if ($needle === '' || mb_strlen($needle) < 4) {
            return null;
        }

        // Innerhalb EINES Laufs wird derselbe Name vielfach gesucht (in der
        // Abrechnung des Betriebs stehen 1969 Zeilen auf 274 Namen). Ohne
        // dieses Gedaechtnis waere das je Zeile eine Datenbankabfrage.
        if (array_key_exists($needle, $this->nameCache)) {
            $cached = $this->nameCache[$needle];
            return is_string($cached) ? $cached : ($cached === null ? null : Customer::with('user')->find($cached));
        }

        $matches = Customer::query()
            ->whereHas('user', fn ($q) => $q->where('role', 'customer')->whereRaw('LOWER(TRIM(name)) = ?', [$needle]))
            ->with('user')
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            $this->nameCache[$needle] = 'ambiguous';
            return 'ambiguous';
        }
        $found = $matches->first();
        $this->nameCache[$needle] = $found?->id;
        return $found;
    }

    /**
     * Einen frisch angelegten Kunden sofort merken - sonst legt die naechste
     * Zeile desselben Namens eine zweite Akte an, weil die Datenbankabfrage
     * zwar traefe, der Cache aber noch "nicht vorhanden" saegte.
     */
    private function rememberCustomer(Customer $customer, string $name): void
    {
        $needle = mb_strtolower(trim(preg_replace('/\\s+/u', ' ', $name) ?? $name));
        $this->nameCache[$needle] = $customer->id;
    }

    /** @param array<string,mixed> $mapped */
    private function identity(array $mapped): ?string
    {
        foreach (ColumnMap::KEY_FIELDS as $field) {
            $key = VermittlerReference::key(is_string($mapped[$field] ?? null) ? $mapped[$field] : null);
            if ($key !== null) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Suchkriterien fuer den Duplikatsschutz und die Neuanlage.
     *
     * @param array{name:?string,gender:?string,company:bool} $parsed
     * @param array<string,mixed> $mapped
     * @return array<string,mixed>
     */
    private function criteria(array $parsed, array $mapped): array
    {
        $address = ValueParser::address(is_string($mapped['customer_address'] ?? null) ? $mapped['customer_address'] : null);

        return array_filter([
            'full_name' => $parsed['name'],
            'last_name' => $this->names->lastName(is_string($mapped['customer_name'] ?? null) ? $mapped['customer_name'] : null),
            'gender' => $parsed['gender'],
            'company_name' => $parsed['company'] ? $parsed['name'] : null,
            'customer_type' => $parsed['company'] ? 'business' : null,
            'birth_date' => ($mapped['customer_birth_date'] ?? null)?->format('Y-m-d'),
            'phone' => ValueParser::text($mapped['customer_phone'] ?? null, 60),
            'street' => $address['street'],
            'house_number' => $address['house_number'],
            'zip' => $address['zip'],
            'city' => $address['city'],
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @param array<string,mixed> $mapped */
    private function type(array $mapped): string
    {
        $haystack = mb_strtolower(trim(
            (string) ($mapped['product_name'] ?? '') . ' ' . (string) ($mapped['sparte'] ?? '')
        ));
        if ($haystack === '') {
            return 'andere';
        }
        foreach (self::TYPE_KEYWORDS as $needle => $type) {
            if (str_contains($haystack, $needle)) {
                return $type;
            }
        }
        return 'andere';
    }

    /**
     * Die Herkunft steht IM Vertrag, nicht nur im Protokoll: wer die Akte
     * oeffnet, soll ohne Umweg sehen, dass diese Angaben aus einer fremden
     * Datei stammen und nicht geprueft sind.
     *
     * @param array<string,mixed> $mapped
     */
    private function note(array $mapped, CommissionImport $import): string
    {
        $lines = [
            'Automatisch aus der Datei "' . $import->filename . '" angelegt (' . now()->format('d.m.Y') . ').',
            'Die Angaben stammen aus einer fremden Abrechnung und sind NICHT geprüft.',
        ];
        foreach ([
            'Produkt der Quelle' => $mapped['product_name'] ?? null,
            'Sparte der Quelle' => $mapped['sparte'] ?? null,
            'Status der Quelle' => $mapped['status_text'] ?? null,
            'Zählernummer' => $mapped['meter_number'] ?? null,
            'Verbrauch' => $mapped['consumption'] ?? null,
        ] as $label => $value) {
            if (filled($value)) {
                $lines[] = $label . ': ' . $value;
            }
        }
        return implode("\n", $lines);
    }
}
