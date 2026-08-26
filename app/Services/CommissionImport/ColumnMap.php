<?php
namespace App\Services\CommissionImport;

/**
 * Die Systemfelder des Provisions-Imports und ihre Erkennung in fremden
 * Kopfzeilen (Betreiber-Auftrag 26.08.2026).
 *
 * WARUM ERKENNUNG UND HANDARBEIT: Der Vorschlag spart im Regelfall jede
 * Zuordnung von Hand - die drei Formate des Betriebs (Maklerpool,
 * Vergleichsportal, Energie-Vertriebsportal) werden vollstaendig erkannt.
 * Aber er bleibt ein VORSCHLAG: die naechste Gesellschaft nennt ihre Spalte
 * anders, und dann muss der Admin sie zuweisen koennen, ohne dass jemand
 * Code aendert. Genau deshalb steht die Zuordnung als Schritt in der
 * Oberflaeche und nicht nur in dieser Liste.
 *
 * Die Erkennung vergleicht EXAKT (normalisiert), nicht per Teiltreffer:
 * sonst greift "Auftr.-Nr." auch auf "Ihre Auftr.-Nr." zu - zwei Spalten,
 * die in derselben Datei nebeneinander stehen und Verschiedenes bedeuten.
 */
class ColumnMap
{
    /**
     * Systemfeld => [Bezeichnung, Art, Erklaerung, Schreibweisen].
     * Art steuert die Deutung: text | zahl | datum | status.
     */
    public const FIELDS = [
        'internal_contract_number' => [
            'label' => 'Interne Vertragsnummer',
            'type' => 'text',
            'hint' => 'Der Schlüssel für die Zuordnung. Beispiel: V19613073',
            'aliases' => ['vertragsnummerintern', 'internevertragsnummer', 'internevertragsnr',
                'vertragsnrintern', 'internervertrag', 'internecontractnumber', 'internenummer'],
        ],
        'external_contract_number' => [
            'label' => 'Vertragsnummer der Gesellschaft',
            'type' => 'text',
            'hint' => 'Die Nummer beim Versicherer/Versorger (im Portal: Vertragsnummer)',
            'aliases' => ['vertragsnummerextern', 'externevertragsnummer', 'vertragsnummer',
                'vertragsnr', 'policennummer', 'scheinnummer', 'versicherungsscheinnummer'],
        ],
        'reference_number' => [
            'label' => 'Referenz-Nr. / Vorgangsnummer',
            'type' => 'text',
            'hint' => 'Kennung der Antragsstrecke, z. B. 1477-6741-9200-53',
            'aliases' => ['referenznr', 'referenznummer', 'referenz', 'reference', 'vorgangsnr',
                'vorgangsnummer', 'protokollnr', 'antragsnummer'],
        ],
        'vermittler_id' => [
            'label' => 'Vermittler-Id (Vorgangs-Id)',
            'type' => 'text',
            'hint' => 'Die Id des Vermittlers, z. B. 9753224',
            'aliases' => ['id', 'vorgangsid', 'datensatzid', 'vermittlerid'],
        ],
        'order_number' => [
            'label' => 'Auftr.-Nr. (Vertriebsportal)',
            'type' => 'text',
            'hint' => 'Auftragsnummer des Energie-Vertriebsportals',
            'aliases' => ['auftrnr', 'auftragsnr', 'auftragsnummer', 'ordernumber'],
        ],
        'external_id' => [
            'label' => 'Datensatz-Nr. der Quelle',
            'type' => 'text',
            'hint' => 'z. B. Abrechnungsnummer – trennt zwei Provisionen desselben Vertrags',
            'aliases' => ['abrechnungsnummer', 'abrechnungsnr', 'belegnummer', 'belegnr',
                'datensatznr', 'laufendenummer', 'buchungsnummer'],
        ],
        'customer_name' => [
            'label' => 'Kunde (nur zur Anzeige)',
            'type' => 'text',
            'hint' => 'Wird NIE zur Zuordnung benutzt – nur als Klartext gespeichert',
            'aliases' => ['kunde', 'kunden', 'kundenname', 'versicherungsnehmer', 'vn', 'customer'],
        ],
        'recipient_name' => [
            'label' => 'Provisionsempfänger',
            'type' => 'text',
            'hint' => 'Wer die Provision bekommt (Kontoinhaber, Vermittler)',
            'aliases' => ['provisionsempfaenger', 'empfaenger', 'kontoinhaber', 'vermittler',
                'vpname', 'berater', 'recipient'],
        ],
        'recipient_number' => [
            'label' => 'Vermittlernummer',
            'type' => 'text',
            'aliases' => ['vermittlernummer', 'vermittlernr', 'maklernummer', 'vpnummer', 'agenturnummer'],
        ],
        'commission_type' => [
            'label' => 'Provisionsart',
            'type' => 'text',
            'hint' => 'Abschlussprovision, Bestandsprovision, Dynamik …',
            'aliases' => ['provisionsart', 'provisionsartkuerzel', 'artderprovision', 'buchungsart', 'commissiontype'],
        ],
        'product_name' => [
            'label' => 'Produkt / Tarif',
            'type' => 'text',
            'aliases' => ['produktname', 'produkt', 'tarif', 'tarifprodukt', 'product'],
        ],
        'company' => [
            'label' => 'Gesellschaft / Anbieter',
            'type' => 'text',
            'aliases' => ['gesellschaft', 'versicherer', 'anbieter', 'lieferant', 'company'],
        ],
        'sparte' => [
            'label' => 'Sparte',
            'type' => 'text',
            'aliases' => ['sparte', 'produktsparte', 'branche'],
        ],
        'amount' => [
            'label' => 'Provisionsbetrag',
            'type' => 'zahl',
            'hint' => 'Pflichtfeld – ohne Betrag ist es keine Provision',
            'aliases' => ['provisionsbetrag', 'provision', 'betrag', 'summeineur', 'summe',
                'verguetung', 'courtage', 'amount', 'commission'],
        ],
        'currency' => [
            'label' => 'Währung',
            'type' => 'text',
            'aliases' => ['waehrung', 'currency', 'wkz'],
        ],
        'vat_amount' => [
            'label' => 'USt.-Betrag',
            'type' => 'zahl',
            'aliases' => ['ustbetrag', 'umsatzsteuer', 'mwstbetrag', 'steuerbetrag'],
        ],
        'reserve_amount' => [
            'label' => 'Stornoreserve',
            'type' => 'zahl',
            'aliases' => ['stornoreserve', 'reserve', 'einbehalt'],
        ],
        'paid_amount' => [
            'label' => 'Bereits gezahlt',
            'type' => 'zahl',
            'hint' => 'Für Teilzahlungen – leer heißt: nichts gezahlt',
            'aliases' => ['gezahlt', 'gezahlterbetrag', 'zahlbetrag', 'auszahlungsbetrag', 'paidamount'],
        ],
        'commission_date' => [
            'label' => 'Provisionsdatum',
            'type' => 'datum',
            'aliases' => ['provisionsdatum', 'abrechnungsdatum', 'datum', 'buchungsdatum',
                'abschlussdatum', 'anlagedatum', 'date'],
        ],
        'due_date' => [
            'label' => 'Fälligkeitsdatum',
            'type' => 'datum',
            'aliases' => ['faelligkeitsdatum', 'faelligam', 'faelligkeit', 'duedate'],
        ],
        'payment_date' => [
            'label' => 'Zahlungsdatum',
            'type' => 'datum',
            'hint' => 'Gesetzt = die Provision gilt als bezahlt',
            'aliases' => ['zahlungsdatum', 'bezahltam', 'auszahlungsdatum', 'zahldatum', 'paymentdate'],
        ],
        'status' => [
            'label' => 'Status',
            'type' => 'status',
            'hint' => 'offen / fällig / bezahlt / storniert – Unbekanntes wird „unklar“',
            'aliases' => ['status', 'provisionsstatus', 'zahlstatus', 'statustext'],
        ],
        'storno_reason' => [
            'label' => 'Stornogrund',
            'type' => 'text',
            'aliases' => ['stornogrund', 'storno', 'stornierungsgrund'],
        ],
        'invoice_number' => [
            'label' => 'Rechnungsnummer',
            'type' => 'text',
            'hint' => 'Falls die Quelle sie schon mitliefert',
            'aliases' => ['rechnungsnummer', 'rechnungsnr', 'invoicenumber', 'rechnung'],
        ],
        'notes' => [
            'label' => 'Bemerkung (interne Notiz)',
            'type' => 'text',
            'aliases' => ['bemerkung', 'notiz', 'hinweis', 'kommentar', 'anmerkung', 'notes'],
        ],
    ];

    /** Felder, die eine Zuordnung zum Vertrag herstellen koennen. */
    public const KEY_FIELDS = [
        'internal_contract_number', 'reference_number', 'vermittler_id',
        'order_number', 'external_contract_number',
    ];

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    public static function label(string $field): string
    {
        return self::FIELDS[$field]['label'] ?? $field;
    }

    public static function type(string $field): string
    {
        return self::FIELDS[$field]['type'] ?? 'text';
    }

    /**
     * Kopfzeile normalisieren: klein, ohne Sonderzeichen, Umlaute aufgeloest.
     * "USt.-Betrag", "Ust Betrag" und "ust_betrag" ergeben denselben Wert -
     * eine Datei soll nicht an einem Bindestrich scheitern.
     */
    public static function normalize(string $label): string
    {
        $label = mb_strtolower(trim($label));
        $label = strtr($label, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        return preg_replace('/[^a-z0-9]/', '', $label) ?? '';
    }

    /**
     * Vorschlag: Systemfeld => Spaltenindex.
     *
     * Je Feld gewinnt die ERSTE passende Spalte, und jede Spalte wird nur
     * EINMAL vergeben - sonst zeigen zwei Felder auf dieselbe Spalte und der
     * Admin sieht in der Vorschau zweimal denselben Wert, ohne zu merken,
     * dass eine Angabe fehlt.
     *
     * @param array<int,string> $header
     * @return array<string,int>
     */
    public static function suggest(array $header): array
    {
        $normalized = [];
        foreach ($header as $index => $label) {
            $key = self::normalize((string) $label);
            if ($key !== '') {
                $normalized[$index] = $key;
            }
        }

        $map = [];
        $used = [];
        foreach (self::FIELDS as $field => $definition) {
            foreach ($normalized as $index => $key) {
                if (isset($used[$index]) || !in_array($key, $definition['aliases'], true)) {
                    continue;
                }
                $map[$field] = $index;
                $used[$index] = true;
                break;
            }
        }
        return $map;
    }

    /**
     * Zuordnung pruefen. Zurueck kommen KLARTEXT-Fehler, keine Codes - sie
     * stehen so in der Oberflaeche.
     *
     * @param array<string,int|null> $map
     * @return array<int,string>
     */
    public static function validate(array $map): array
    {
        $errors = [];
        $map = array_filter($map, fn ($v) => $v !== null && $v !== '');

        if (!isset($map['amount'])) {
            $errors[] = 'Die Spalte für den Provisionsbetrag ist nicht zugeordnet. Ohne Betrag lässt sich keine Provision anlegen.';
        }
        if (array_intersect_key($map, array_flip(self::KEY_FIELDS)) === []) {
            $errors[] = 'Es ist keine Kennung zugeordnet. Mindestens eine von: '
                . implode(', ', array_map(fn ($f) => self::label($f), self::KEY_FIELDS))
                . ' wird gebraucht, um die Provision einem Vertrag zuzuordnen.';
        }

        $duplicates = array_diff_assoc($map, array_unique($map));
        if ($duplicates !== []) {
            $errors[] = 'Dieselbe Spalte ist mehreren Feldern zugeordnet: '
                . implode(', ', array_map(fn ($f) => self::label($f), array_keys($duplicates)))
                . '. Bitte je Feld eine eigene Spalte wählen.';
        }
        return $errors;
    }
}
