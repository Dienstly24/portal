<?php
namespace App\Services\CommissionImport;

/**
 * Namen aus fremden Abrechnungs- und Auftragsdateien lesbar machen.
 *
 * WARUM EIGENS DAFUER: Die Quellen schreiben denselben Menschen auf drei
 * Arten - der Maklerpool als "VN RANKO, MOHAMAD ADNAN" (Praefix, Nachname
 * zuerst, Versalien), das Energieportal als "Herr Saddam Alahmad Al Hakkar"
 * (Anrede davor, natuerliche Reihenfolge), und beide gelegentlich mit
 * haengendem Komma, wenn der Vorname fehlt ("VN Ahmed Al Huweij, ").
 * Wuerde man das ungeprueft uebernehmen, hiesse der Kunde in der Akte
 * "VN RANKO, MOHAMAD ADNAN" - und keine Suche und kein Serienbrief kaeme
 * damit zurecht.
 *
 * GRUNDSATZ: Aus dem Namen wird NIE eine Zuordnung abgeleitet (das entscheidet
 * `CommissionMatcher` ueber Kennungen). Er dient allein der Anzeige und der
 * Neuanlage.
 */
class PersonNameParser
{
    /** Praefixe der Quellen, die kein Namensbestandteil sind. */
    private const PREFIXES = ['vn', 'vp', 'kunde', 'kd', 'firma'];

    /** Anreden - sie verraten zusaetzlich das Geschlecht. */
    private const SALUTATIONS = [
        'herr' => 'male', 'herrn' => 'male', 'hr' => 'male',
        'frau' => 'female', 'fr' => 'female',
    ];

    /** Namenszusaetze, die klein bleiben bzw. nicht als Nachname zaehlen. */
    private const PARTICLES = ['al', 'el', 'van', 'von', 'de', 'da', 'di', 'du', 'la', 'le', 'bin', 'ben', 'abu'];

    /**
     * @return array{name:?string,gender:?string,company:bool}
     *         name = null, wenn nichts Brauchbares uebrig bleibt.
     */
    public function parse(?string $raw): array
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return ['name' => null, 'gender' => null, 'company' => false];
        }

        // Praefix "VN " und Anrede abtrennen (beides kann vorkommen).
        $gender = null;
        $changed = true;
        while ($changed) {
            $changed = false;
            if (preg_match('/^([\p{L}]+)\.?\s+(.*)$/u', $value, $m)) {
                $word = mb_strtolower($m[1]);
                if (in_array($word, self::PREFIXES, true)) {
                    $value = trim($m[2]);
                    $changed = true;
                    continue;
                }
                if (isset(self::SALUTATIONS[$word])) {
                    $gender ??= self::SALUTATIONS[$word];
                    $value = trim($m[2]);
                    $changed = true;
                }
            }
        }

        $value = trim($value, " \t\n\r\0\x0B,;");
        if ($value === '') {
            return ['name' => null, 'gender' => $gender, 'company' => false];
        }

        // Eine Firma bleibt unangetastet: "Muster GmbH & Co. KG" darf weder
        // umgedreht noch in Vor-/Nachname zerlegt werden.
        if ($this->looksLikeCompany($value)) {
            return ['name' => $this->collapseSpaces($value), 'gender' => null, 'company' => true];
        }

        // "Nachname, Vorname" -> "Vorname Nachname". Ein LEERER Teil hinter
        // dem Komma (haeufig in der Pool-Datei) heisst: es gibt nur einen
        // Namen - dann wird nichts gedreht.
        if (str_contains($value, ',')) {
            [$last, $first] = array_pad(array_map('trim', explode(',', $value, 2)), 2, '');
            $value = $first !== '' ? $first . ' ' . $last : $last;
        }

        return [
            'name' => $this->normalizeCase($this->collapseSpaces($value)),
            'gender' => $gender,
            'company' => false,
        ];
    }

    /** Nur der Nachname - fuer die Suche nach einem vorhandenen Kunden. */
    public function lastName(?string $raw): ?string
    {
        $parsed = $this->parse($raw);
        if ($parsed['name'] === null || $parsed['company']) {
            return null;
        }
        $words = preg_split('/\s+/', $parsed['name'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return null;
        }

        // Von hinten sammeln, solange Namenszusaetze davorstehen:
        // "Ahmad Al Khatib Al Jashi" -> "Al Jashi".
        $parts = [array_pop($words)];
        while ($words !== [] && in_array(mb_strtolower((string) end($words)), self::PARTICLES, true)) {
            array_unshift($parts, (string) array_pop($words));
        }
        return implode(' ', $parts);
    }

    private function looksLikeCompany(string $value): bool
    {
        return (bool) preg_match(
            '/\b(gmbh|ag|kg|ohg|ug|mbh|e\.?k\.?|e\.?v\.?|gbr|se|ltd|inc|s\.?a\.?r\.?l)\b/i',
            $value
        );
    }

    /**
     * VERSALIEN in normale Schreibweise bringen ("RANKO" -> "Ranko"), aber
     * eine bereits gemischte Schreibweise NICHT anfassen: "McDonald" und
     * "Al Huweij" wuerden sonst schlechter, nicht besser.
     */
    private function normalizeCase(string $value): string
    {
        if ($value !== mb_strtoupper($value)) {
            return $value;
        }
        return implode(' ', array_map(
            fn ($word) => mb_convert_case($word, MB_CASE_TITLE, 'UTF-8'),
            preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []
        ));
    }

    private function collapseSpaces(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
