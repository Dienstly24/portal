<?php

namespace App\Services\Energy;

/**
 * Liest Zaehlernummer und Zaehlerstand aus dem Text eines Zaehlerfotos -
 * KOSTENLOS, ohne KI (Betreiber-Prinzip "kostenlos zuerst"). Grundlage ist
 * der Text, den die OCR-Stufe (Tesseract) bzw. die PDF-Textebene liefert.
 *
 * Bewusst konservativ wie die uebrige Heuristik: Ein Wert wird nur
 * uebernommen, wenn er klar als solcher gekennzeichnet ist - der Stand nur
 * mit Einheit (kWh / m3) direkt dahinter, die Nummer nur beschriftet oder im
 * genormten eHZ-Format. Lieber nichts erkennen als einen falschen Zaehler
 * einem Kunden zuordnen; erkennt die kostenlose Stufe nichts, eskaliert der
 * DocumentAnalyzer wie gewohnt zur KI.
 */
class MeterPhotoReader
{
    /** Ein Foto zeigt wenig Text - laengere Dokumente sind Rechnungen o.ae. */
    private const MAX_PHOTO_TEXT_CHARS = 1500;

    /** Hoechster plausibler Zaehlerstand (6-7 Stellen sind ueblich). */
    private const MAX_READING = 10000000;

    private const METER_KEYWORDS = [
        'ZAHLERSTAND', 'ZAEHLERSTAND', 'ZAHLERNUMMER', 'ZAEHLERNUMMER',
        'ZAHLER-NR', 'ZAEHLER-NR', 'ZAHLERNR', 'ZAEHLERNR',
        'ZWEIRICHTUNGSZAHLER', 'ZWEIRICHTUNGSZAEHLER', 'EINTARIFZAHLER',
        'DREHSTROMZAHLER', 'WANDLERZAHLER', 'STROMZAHLER', 'GASZAHLER',
        'IDENTIFIKATIONSNUMMER', 'IMP/KWH', 'OBIS', 'MESSSTELLE',
    ];

    /**
     * @return array{meter_number: ?string, meter_reading: ?float, meter_register: ?string, meter_unit: ?string}
     */
    public function read(string $text): array
    {
        $normalized = $this->normalize($text);
        $reading = $this->findReading($normalized);

        return [
            'meter_number' => $this->findMeterNumber($normalized),
            'meter_reading' => $reading['value'] ?? null,
            'meter_register' => $reading['register'] ?? null,
            'meter_unit' => $reading['unit'] ?? null,
        ];
    }

    /**
     * Sieht der Text nach dem Foto eines Strom-/Gaszaehlers aus? Verlangt
     * wird ein verwertbarer Fund (Stand oder Nummer) UND entweder ein
     * eindeutiges Zaehler-Stichwort oder beide Angaben zusammen - eine
     * einzelne Zahl mit "kWh" allein reicht nicht (die steht auch auf jeder
     * Energierechnung).
     */
    public function looksLikeMeterPhoto(string $text): bool
    {
        if (mb_strlen(trim($text)) > self::MAX_PHOTO_TEXT_CHARS) {
            return false;
        }

        $found = $this->read($text);
        if ($found['meter_reading'] === null && $found['meter_number'] === null) {
            return false;
        }

        $normalized = $this->normalize($text);
        foreach (self::METER_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return $found['meter_reading'] !== null && $found['meter_number'] !== null;
    }

    /**
     * Zaehlernummer: zuerst beschriftet ("Zaehlernummer: ..."), dann im
     * genormten Format moderner Zaehler (1 + 3 Herstellerbuchstaben + Ziffern,
     * z.B. "1 LOG00 9228 3078"), zuletzt eine kurze "Nr."-Zeile.
     */
    private function findMeterNumber(string $normalized): ?string
    {
        $labelPattern = '/(?:ZAHLERNUMMER|ZAEHLERNUMMER|ZAHLER\s?-?\s?NR\.?|ZAEHLER\s?-?\s?NR\.?|'
            .'IDENTIFIKATIONSNUMMER|GERATENUMMER|GERAETENUMMER)\s*[:.\-]?\s*([0-9A-Z][0-9A-Z \-]{5,40})/';
        foreach ($this->lines($normalized) as $line) {
            if (preg_match($labelPattern, $line, $m)) {
                $candidate = $this->cleanNumber($m[1]);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        // Genormtes Format (eHZ/moderne Messeinrichtung): fuehrende 1, drei
        // Herstellerbuchstaben (LOG, EMH, ITF ...), dann Ziffern. Auf dem
        // Zaehler stehen Leerzeichen dazwischen, daher je Zeile verdichten.
        foreach ($this->lines($normalized) as $line) {
            $compact = (string) preg_replace('/[^A-Z0-9]/', '', $line);
            if (preg_match('/\b(1[A-Z]{3}\d{8,14})/', $compact, $m)) {
                return $m[1];
            }
        }

        // Letzte Stufe: eine kurze, klar beschriftete "Nr."-Zeile.
        foreach ($this->lines($normalized) as $line) {
            if (mb_strlen($line) <= 30 && preg_match('/^NR\.?\s*[:.\-]?\s*(\d{6,14})$/', trim($line), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Zaehlerstand samt Zaehlwerk. Der Wert wird nur mit direkt folgender
     * Einheit uebernommen; die OBIS-Kennzahl davor (1.8.0 Bezug, 2.8.0
     * Einspeisung) bestimmt das Zaehlwerk. Ein Zweirichtungszaehler zeigt
     * beides - fuer den Verbrauch zaehlt der BEZUG, er hat daher Vorrang.
     *
     * @return array{value: float, register: string, unit: string}|null
     */
    private function findReading(string $normalized): ?array
    {
        $found = [];
        foreach ($this->lines($normalized) as $line) {
            // Einheit nur als eigenstaendiges Wort: "Imp/kWh" (Zaehlerkonstante
            // auf dem Typenschild) darf nie als Zaehlerstand durchgehen.
            $pattern = '/(?<![\d.,A-Z\/])(\d[\d ]*(?:\.\d{3})*(?:[.,]\d{1,3})?)\s*(KWH|M3|M³)(?![A-Z0-9])/';
            if (! preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $tokens = preg_split('/\s+/', trim($m[1][0])) ?: [];
            $value = $this->parseValue((string) array_pop($tokens));
            if ($value === null) {
                continue;
            }

            // Alles vor dem eigentlichen Zahlenwert kann die OBIS-Kennzahl
            // tragen (z.B. "1.8.0" oder - ohne Punkte gelesen - "180").
            $prefix = mb_substr($line, 0, (int) $m[1][1]).' '.implode(' ', $tokens);
            $found[] = [
                'value' => $value,
                'register' => $this->detectRegister($prefix),
                'unit' => $m[2][0] === 'KWH' ? 'kWh' : 'm³',
            ];
        }

        if ($found === []) {
            return null;
        }

        foreach ($found as $entry) {
            if ($entry['register'] === '1.8.0') {
                return $entry;
            }
        }

        return $found[0];
    }

    /** OBIS-Kennzahl aus dem Text vor dem Zaehlerstand ableiten. */
    private function detectRegister(string $prefix): string
    {
        if (preg_match('/\b([12])\s*[.,]\s*8\s*[.,]\s*([0-2])\b/', $prefix, $m)) {
            return $m[1].'.8.'.$m[2];
        }
        // Displays zeigen die Kennzahl oft ohne Trennzeichen ("180").
        if (preg_match('/(?<!\d)([12])8([0-2])(?!\d)/', $prefix, $m)) {
            return $m[1].'.8.'.$m[2];
        }
        return '1.8.0';
    }

    /**
     * Zahl aus dem Foto in einen Wert wandeln - deutsche wie englische
     * Schreibweise ("4.680,5" und "4,680.5"). Ein einzelner Punkt/Komma mit
     * genau drei Folgeziffern ist ein Tausendertrenner, sonst Dezimaltrenner.
     */
    private function parseValue(string $token): ?float
    {
        $token = trim($token);
        if ($token === '' || ! preg_match('/^\d[\d.,]*$/', $token)) {
            return null;
        }

        $lastDot = strrpos($token, '.');
        $lastComma = strrpos($token, ',');
        $decimalPos = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);

        if ($decimalPos < 0) {
            $value = (float) $token;
        } else {
            $decimals = strlen($token) - $decimalPos - 1;
            if ($decimals === 3 && substr_count($token, '.') + substr_count($token, ',') === 1) {
                $value = (float) preg_replace('/[.,]/', '', $token); // Tausendertrenner
            } else {
                $whole = (string) preg_replace('/[.,]/', '', substr($token, 0, $decimalPos));
                $fraction = substr($token, $decimalPos + 1);
                $value = (float) ($whole.'.'.$fraction);
            }
        }

        if ($value <= 0 || $value >= self::MAX_READING) {
            return null;
        }
        return round($value, 3);
    }

    /** Beschriftete Nummer saeubern und auf Plausibilitaet pruefen. */
    private function cleanNumber(string $raw): ?string
    {
        $clean = (string) preg_replace('/[^A-Z0-9]/', '', $raw);
        if (mb_strlen($clean) < 6 || mb_strlen($clean) > 40) {
            return null;
        }
        // Eine Zaehlernummer besteht ueberwiegend aus Ziffern - reine
        // Buchstabenfolgen sind Beschriftungsreste, keine Nummer.
        if (preg_match_all('/\d/', $clean) < 4) {
            return null;
        }
        return $clean;
    }

    /** Text vereinheitlichen: Grossschreibung, Umlaute gefaltet. */
    private function normalize(string $text): string
    {
        $upper = mb_strtoupper($text);
        return strtr($upper, ['Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ß' => 'SS']);
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        return preg_split('/\R/', $text) ?: [];
    }
}
