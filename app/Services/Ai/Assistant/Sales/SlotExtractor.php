<?php
namespace App\Services\Ai\Assistant\Sales;

/**
 * Deterministische Vorstufe, die sensible Angaben aus der Kundennachricht
 * herausloest, BEVOR die Nachricht das Modell erreicht
 * (Spezifikation Abschnitte 9, 10 und 11).
 *
 * Der Betreiber will Vertragsdaten im Chat einsammeln; das Projekt
 * verbietet gleichzeitig IBAN, Geburtsdatum & Co. im Modellkontext.
 * Beides geht, wenn die Reihenfolge stimmt:
 *
 *   Kundennachricht -> hier erkennen und serverseitig speichern ->
 *   im Text durch einen Platzhalter ersetzen -> erst dann zum Modell.
 *
 * Das Modell erfaehrt also "IBAN liegt vor", nie den Wert. Damit kann
 * auch keine Antwort und kein Protokoll den Wert je enthalten.
 *
 * KOSTENLOS: reine Mustererkennung, kein API-Aufruf. Bewusst
 * KONSERVATIV - lieber ein Feld nicht erkennen, als einen falschen Wert
 * in die Akte schreiben.
 */
class SlotExtractor
{
    /**
     * @return array{text: string, found: array<string,string>}
     *         text  = bereinigte Nachricht fuer das Modell
     *         found = erkannte Felder (Schluessel => Rohwert)
     */
    public function extract(string $message): array
    {
        $found = [];
        $text = $message;

        // --- IBAN: nur mit gueltiger Pruefziffer (Mod 97) ----------------
        $text = $this->replace($text, '/\b([A-Z]{2}\d{2}(?:[ ]?[A-Za-z0-9]{4}){2,7}[ ]?[A-Za-z0-9]{0,4})\b/', function ($treffer) use (&$found) {
            $iban = strtoupper(preg_replace('/\s+/', '', $treffer));
            if (!$this->ibanValid($iban)) {
                return null;
            }
            $found['iban'] = $iban;

            return '[IBAN erfasst]';
        });

        // --- E-Mail ------------------------------------------------------
        $text = $this->replace($text, '/\b[\w.+-]+@[\w-]+\.[\w.-]{2,}\b/', function ($treffer) use (&$found) {
            $found['email'] = strtolower($treffer);

            return '[E-Mail erfasst]';
        });

        // --- Geburtsdatum: nur mit Schluesselwort ------------------------
        // Ohne Schluesselwort waere jedes Datum ein Geburtsdatum - auch der
        // Wunschtermin des Anschlusses. Deshalb nur, wenn der Kunde es
        // selbst so benennt, und nur, wenn es plausibel in der
        // Vergangenheit liegt.
        if (preg_match('/(?:geboren|geburtsdatum|geb\.|birth\s*date|born|تاريخ\s*الميلاد|مواليد)\D{0,20}(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})/iu', $text, $m)) {
            $datum = $this->normalizeDate($m[1]);
            if ($datum !== null) {
                $found['birthdate'] = $datum;
                $text = str_replace($m[1], '[Geburtsdatum erfasst]', $text);
            }
        }

        // --- Telefonnummer: mind. 7 Ziffern, mit Schluesselwort ----------
        if (preg_match('/(?:telefon|handy|mobil|rufnummer|phone|هاتف|جوال)\D{0,15}(\+?[\d][\d\s\/().-]{6,20}\d)/iu', $text, $m)) {
            $nummer = preg_replace('/[^\d+]/', '', $m[1]);
            if (strlen((string) $nummer) >= 7) {
                $found['phone'] = $nummer;
                $text = str_replace($m[1], '[Telefonnummer erfasst]', $text);
            }
        }

        return ['text' => $text, 'found' => $found];
    }

    /**
     * Treffer ersetzen; gibt der Rueckruf null zurueck, bleibt der Text
     * unveraendert (Fund verworfen).
     */
    private function replace(string $text, string $muster, callable $callback): string
    {
        return (string) preg_replace_callback($muster, function ($m) use ($callback) {
            $ersatz = $callback($m[0]);

            return $ersatz ?? $m[0];
        }, $text);
    }

    /** IBAN-Pruefziffer nach ISO 13616 (Mod 97 = 1). */
    private function ibanValid(string $iban): bool
    {
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }

        $umgestellt = substr($iban, 4) . substr($iban, 0, 4);
        $ziffern = '';
        foreach (str_split($umgestellt) as $zeichen) {
            $ziffern .= ctype_alpha($zeichen)
                ? (string) (ord(strtoupper($zeichen)) - 55)
                : $zeichen;
        }
        if (!ctype_digit($ziffern)) {
            return false;
        }

        // Stueckweise rechnen - die Zahl ist fuer int zu gross.
        $rest = 0;
        foreach (str_split($ziffern, 7) as $block) {
            $rest = (int) (($rest . $block) % 97);
        }

        return $rest === 1;
    }

    /** TT.MM.JJJJ, nur plausible Geburtsdaten der Vergangenheit. */
    private function normalizeDate(string $roh): ?string
    {
        $teile = preg_split('/[.\/-]/', $roh);
        if (!$teile || count($teile) !== 3) {
            return null;
        }

        [$tag, $monat, $jahr] = array_map('intval', $teile);
        if ($jahr < 100) {
            $jahr += $jahr > 30 ? 1900 : 2000;
        }
        if (!checkdate($monat, $tag, $jahr)) {
            return null;
        }
        if ($jahr < 1900 || $jahr > (int) date('Y')) {
            return null;
        }

        return sprintf('%02d.%02d.%04d', $tag, $monat, $jahr);
    }
}
