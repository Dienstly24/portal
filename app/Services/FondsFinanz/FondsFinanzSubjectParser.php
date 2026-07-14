<?php
namespace App\Services\FondsFinanz;

/**
 * Liest die Kundendaten aus dem BETREFF echter Fonds-Finanz-Mails.
 * Praxisbefund: Anders als das idealisierte "Label: Wert"-Format stehen
 * bei den realen Benachrichtigungen Kunde, Sparte und Vorgangsnummer im
 * Betreff, z. B.:
 *   "Fonds Finanz Info No. 2959197012 zum Kunden Al Ali Ahmad"
 *   "Neues Dokument zum Kunden Alibrahim, Omar, Sach"
 *   "Neues Dokument zum Kunden Tiger Snacks, Sach"
 *
 * Ohne diese Auswertung scheiterte der Import an jeder realen Mail und
 * erzeugte nur "konnte nicht gelesen werden"-Aufgaben. Bewusst
 * deterministisch (feste Muster), keine KI - leicht testbar.
 */
class FondsFinanzSubjectParser
{
    /**
     * Bekannte Sparten-Kuerzel am Ende der Kundenangabe ("..., Sach").
     * lowercase; auf die contracts.type-Normalisierung wirkt spaeter der
     * bestehende FondsFinanzImportService::normalizeLine().
     */
    private const LINE_TOKENS = [
        'sach', 'sachversicherung', 'kranken', 'krankenversicherung', 'kv', 'pkv', 'gkv',
        'leben', 'lv', 'lebensversicherung', 'kfz', 'kraftfahrt', 'unfall', 'uv',
        'rechtsschutz', 'rs', 'haftpflicht', 'phv', 'gewerbe', 'shu', 'shuk',
        'wohngebaeude', 'wohngebäude', 'hausrat', 'bu', 'rente', 'vorsorge',
        'tier', 'tierkranken', 'tierhalter', 'gesundheit', 'firmen',
    ];

    public function parse(?string $subject): FondsFinanzData
    {
        $subject = trim((string) $subject);
        if ($subject === '') {
            return new FondsFinanzData();
        }

        return new FondsFinanzData(
            customerName: $this->customerName($subject),
            line: $this->line($subject),
            fondsFinanzNumber: $this->referenceNumber($subject),
        );
    }

    /** Kunde aus "zum Kunden <...>" / "fuer Kunde <...>" / "Kunde: <...>". */
    private function customerName(string $subject): ?string
    {
        if (!preg_match('/(?:zum kunden|zu kunde|fuer kunde|für kunde|kunde|kundin)[:\s]+(.+)$/iu', $subject, $m)) {
            return null;
        }

        $tail = trim($m[1], " \t.-");
        $parts = array_values(array_filter(array_map('trim', explode(',', $tail)), fn ($p) => $p !== ''));
        if (empty($parts)) {
            return null;
        }

        // Trailing Sparten-Token entfernen ("Alibrahim, Omar, Sach" -> Name-Teile).
        if (count($parts) > 1 && $this->isLineToken($parts[count($parts) - 1])) {
            array_pop($parts);
        }

        if (empty($parts)) {
            return null;
        }

        // "Nachname, Vorname" -> "Vorname Nachname"; Firmen-/Volltext-Namen bleiben unveraendert.
        if (count($parts) === 2) {
            return trim($parts[1] . ' ' . $parts[0]);
        }

        return trim(implode(' ', $parts));
    }

    private function line(string $subject): ?string
    {
        if (!preg_match('/(?:zum kunden|zu kunde|fuer kunde|für kunde|kunde|kundin)[:\s]+(.+)$/iu', $subject, $m)) {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode(',', trim($m[1]))), fn ($p) => $p !== ''));
        if (count($parts) > 1 && $this->isLineToken($parts[count($parts) - 1])) {
            return $parts[count($parts) - 1];
        }
        return null;
    }

    /** "Info No. 2959197012", "No. 12345", "Nr. 12345", "Vorgang 12345". */
    private function referenceNumber(string $subject): ?string
    {
        if (preg_match('/(?:info\s+no|no|nr|vorgang(?:snummer)?)[.:\s]+([0-9]{4,})/iu', $subject, $m)) {
            return $m[1];
        }
        return null;
    }

    private function isLineToken(string $value): bool
    {
        return in_array(mb_strtolower(trim($value, " \t.-")), self::LINE_TOKENS, true);
    }
}
