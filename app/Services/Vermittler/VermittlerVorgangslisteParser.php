<?php
namespace App\Services\Vermittler;

/**
 * Liest die VORGANGSLISTE des Vermittler-Portals ("offene Vorgänge"):
 * eine Tabelle Datum / Produkt / Id / Status, bei der die Referenz-Nr. als
 * eigene Zeile UNTER ihrem Vorgang steht.
 *
 * WOZU: Diese Liste ist die einzige Stelle, an der die beiden Nummern
 * gemeinsam auftauchen, BEVOR die Abrechnung kommt. Wer sie einliest, hat
 * die Bruecke Referenz-Nr. -> Vermittler-ID fertig - und die spaetere
 * Abrechnungsdatei findet ihren Vertrag auch dann, wenn sie nur noch die
 * `Id` enthaelt.
 *
 * WICHTIG - diese Liste ist KEINE Abrechnung: sie nennt keinen Betrag und
 * keine Bestaetigung. Sie stellt nur die Zuordnung her.
 *
 * OCR-LEHRE (wie beim Energieportal und bei CHECK24): auf Spaltenabstaende
 * ist kein Verlass. Deshalb wird ausschliesslich ueber ANKER gelesen:
 *  - eine Vorgangs-Id ist eine allein stehende 6- bis 10-stellige Zahl,
 *  - eine Referenz-Nr. haengt an der Beschriftung "Referenznummer",
 *  - zugeordnet wird sie dem zuletzt gesehenen Vorgang.
 * Faellt dabei EINE Referenz-Nr. zweimal auf denselben Vorgang, ist die
 * Reihenfolge der Erkennung nicht vertrauenswuerdig (Tesseract hat die
 * Tabelle spaltenweise statt zeilenweise gelesen) - dann meldet der Parser
 * das ausdruecklich, statt Paare zu erfinden.
 */
class VermittlerVorgangslisteParser
{
    /**
     * Untergrenzen der Erkennung. Eine Liste ist erst eine Liste, wenn sie
     * MEHRERE Vorgaenge mit MEHREREN verschiedenen Referenz-Nummern zeigt -
     * genau das unterscheidet sie von jedem einzelnen Kundendokument.
     */
    private const MIN_VORGAENGE = 3;
    private const MIN_REFERENZEN = 2;

    /** Beschriftung der Referenz-Nr. (Schreibweisen des Portals). */
    private const REFERENCE_LABEL = '/referenz(?:\s*-?\s*n(?:umme)?r\.?)?\s*[:.]?\s*([0-9][0-9\s\-\/]{6,})/iu';

    /**
     * @return array{
     *   rows: array<int, array{vermittler_id: string, reference_number: ?string, produkt: ?string, datum: ?string, status: ?string}>,
     *   ambiguous: bool,
     *   notes: array<int, string>
     * }
     */
    public function parse(string $text): array
    {
        $rows = [];
        $notes = [];
        $ambiguous = false;
        $current = null; // Index der zuletzt gesehenen Vorgangs-Id

        foreach (preg_split('/\R/u', $text) ?: [] as $rawLine) {
            $line = trim(preg_replace('/[ \t]+/u', ' ', $rawLine) ?? '');
            if ($line === '') {
                continue;
            }

            // 1) Referenz-Nr.: haengt IMMER an ihrer Beschriftung.
            $reference = $this->reference($line);

            // 2) Vorgangs-Id: allein stehende 6-10-stellige Zahl. Datum und
            //    Referenz-Nr. werden vorher entfernt, damit weder "20.08.2026"
            //    noch "1477-6741-9200-53" als Id durchgeht.
            $id = $this->vorgangsId($line);

            if ($id !== null) {
                $rows[] = [
                    'vermittler_id' => $id,
                    'reference_number' => null,
                    'produkt' => $this->produkt($line),
                    'datum' => $this->datum($line),
                    'status' => $this->status($line),
                ];
                $current = array_key_last($rows);
            }

            if ($reference === null) {
                continue;
            }

            if ($current === null) {
                // Referenz-Nr. vor dem ersten Vorgang: die Reihenfolge stimmt
                // nicht - nie an den naechsten Vorgang "weiterreichen".
                $ambiguous = true;
                $notes[] = 'Referenz-Nr. "' . $reference . '" steht vor jedem Vorgang.';
                continue;
            }

            if ($rows[$current]['reference_number'] !== null
                && $rows[$current]['reference_number'] !== $reference) {
                // Zwei Referenz-Nummern auf denselben Vorgang: die Tabelle
                // wurde nicht zeilenweise gelesen. Ab hier ist JEDE Zuordnung
                // dieser Datei ein Ratespiel.
                $ambiguous = true;
                $notes[] = 'Vorgang ' . $rows[$current]['vermittler_id']
                    . ' bekaeme zwei Referenz-Nummern ("' . $rows[$current]['reference_number']
                    . '" und "' . $reference . '").';
                continue;
            }

            $rows[$current]['reference_number'] = $reference;
        }

        return ['rows' => $rows, 'ambiguous' => $ambiguous, 'notes' => array_slice($notes, 0, 5)];
    }

    /**
     * Sieht der Text ueberhaupt nach einer Vorgangsliste aus?
     *
     * BEWUSST STRENG. Diese Frage entscheidet im Dokumenten-Eingang, ob ein
     * Dokument als "kein Kundendokument" behandelt wird - ein Fehlalarm
     * wuerde also ein ECHTES Kundendokument von seiner Kundenakte
     * fernhalten. Deshalb reicht kein Stichwort: verlangt werden MEHRERE
     * Vorgaenge UND mehrere VERSCHIEDENE Referenz-Nummern. Ein einzelner
     * Antrag, eine Police, ein Deckungsauftrag tragen genau EINE Nummer -
     * sie koennen diese Huerde gar nicht nehmen.
     */
    public function looksLikeVorgangsliste(string $text): bool
    {
        $parsed = $this->parse($text);
        if (count($parsed['rows']) < self::MIN_VORGAENGE) {
            return false;
        }

        $referenzen = array_unique(array_filter(array_column($parsed['rows'], 'reference_number')));

        return count($referenzen) >= self::MIN_REFERENZEN;
    }

    private function reference(string $line): ?string
    {
        if (!preg_match(self::REFERENCE_LABEL, $line, $m)) {
            return null;
        }
        $value = VermittlerReference::display(preg_replace('/\s+/u', '', $m[1]) ?? '');
        return VermittlerReference::key($value) !== null ? $value : null;
    }

    private function vorgangsId(string $line): ?string
    {
        // Referenz-Nummern und Datumsangaben zuerst entfernen - sonst liest
        // sich "1477-6741-9200-53" als mehrere Zahlen und "2026" als Id.
        $clean = preg_replace(self::REFERENCE_LABEL, ' ', $line) ?? $line;
        $clean = preg_replace('/\b\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}\b/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/u', ' ', $clean) ?? $clean;
        // Gruppierte Nummern (1477-6741-...) sind nie eine Vorgangs-Id.
        $clean = preg_replace('/\b\d{2,}(?:[-\/]\d{2,}){2,}\b/u', ' ', $clean) ?? $clean;

        if (!preg_match_all('/(?<![\d.,\-\/])(\d{6,10})(?![\d.,\-\/])/u', $clean, $m)) {
            return null;
        }
        // Genau EINE Zahl darf die Id sein. Mehrere allein stehende Zahlen in
        // einer Zeile heissen: die Zeile ist keine saubere Vorgangszeile.
        return count($m[1]) === 1 ? $m[1][0] : null;
    }

    private function produkt(string $line): ?string
    {
        // Produktname = der zusammenhaengende Buchstabenteil der Zeile.
        if (!preg_match('/([A-Za-zÄÖÜäöüß][A-Za-zÄÖÜäöüß\-\. ]{4,60})/u', $line, $m)) {
            return null;
        }
        $value = trim($m[1]);
        foreach (['offen', 'Datum', 'Produkt', 'Status'] as $noise) {
            $value = trim(preg_replace('/\b' . preg_quote($noise, '/') . '\b/iu', '', $value) ?? $value);
        }
        return $value !== '' ? mb_substr($value, 0, 190) : null;
    }

    private function datum(string $line): ?string
    {
        if (preg_match('/\b(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})\b/u', $line, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $line, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        return null;
    }

    private function status(string $line): ?string
    {
        foreach (VermittlerStatusMap::TEXT_STATUSES as $text => $_) {
            if (preg_match('/\b' . preg_quote($text, '/') . '\b/iu', $line)) {
                return $text;
            }
        }
        return null;
    }
}
