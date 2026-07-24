<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer die Meldebestaetigung/Meldebescheinigung eines deutschen
 * Buergerbueros/Einwohnermeldeamts. Diese Behoerden-Schreiben tragen die
 * bestaetigte Meldeadresse des Kunden in klar beschrifteten Feldern
 * (Familienname, Vorname, Geburtsdatum, Anschrift) - besonders wertvoll, weil
 * die aktuelle deutsche Wohnadresse amtlich bestaetigt ist.
 *
 * Bewusst NICHT uebernommen werden die Kontaktdaten der Behoerde selbst
 * (Telefon, E-Mail des Buergerbueros) und deren Bankverbindung im Fussbereich
 * (z.B. Sparkasse mit IBAN) - das ist NICHT die Bank des Kunden. Alle Werte
 * durchlaufen die harte Feldvalidierung; unsichere Felder bleiben leer statt
 * falsch.
 */
class MeldebestaetigungParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        if (!str_contains($upper, 'MELDEBEST') && !str_contains($upper, 'MELDEBESCH')) {
            return null;
        }

        $this->lines = preg_split('/\R/', $text) ?: [];

        $raw = [];
        $raw['last_name'] = $this->labelValue('Familienname');
        // "Vorname" bevorzugt; sonst der gebraeuchliche Vorname.
        $raw['first_name'] = $this->labelValue('Vorname') ?? $this->labelValue('Gebräuchlicher Vorname');
        $birth = $this->labelValue('Geburtsdatum');
        if ($birth !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $birth, $m)) {
            $raw['birth_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        // Geschlecht aus der Anrede im Kopf ("Frau"/"Herr").
        foreach ($this->lines as $line) {
            if (preg_match('/^\s*(Herrn|Herr|Frau)\s*$/u', trim($line), $g)) {
                $raw['gender'] = mb_strtolower($g[1]) === 'frau' ? 'female' : 'male';
                break;
            }
        }

        // Anschrift (Kunde, NICHT die "Hausanschrift" der Behoerde): Strasse +
        // Hausnummer in der Label-Zeile, PLZ + Ort in der Folgezeile.
        $this->fillAddress($raw);

        $person = $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));

        if (($person['last_name'] ?? null) === null && ($person['first_name'] ?? null) === null) {
            return null;
        }

        $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $regDate = $this->labelValue('Anmeldedatum') ?? $this->labelValue('Einzugsdatum');
        return [
            'type' => 'meldebescheinigung',
            'confidence' => 74,
            'summary' => 'Meldebestaetigung (Buergerbuero/Einwohnermeldeamt)'
                . ($name !== '' ? ' - ' . $name : '')
                . (isset($person['zip']) ? ' - ' . $person['zip'] . ' ' . ($person['city'] ?? '') : '')
                . ($regDate !== null ? ' - angemeldet ' . $regDate : '')
                . ' - Felder gratis aus dem Schreiben gelesen (ohne KI).',
            'title' => 'Meldebestaetigung' . ($name !== '' ? ' ' . $name : ''),
            'data' => [
                'person' => $person,
                'versicherung' => [],
                'kfz' => [],
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /** @param array<string,mixed> $raw */
    private function fillAddress(array &$raw): void
    {
        foreach ($this->lines as $i => $line) {
            // Nur "Anschrift:" (Kunde), nicht "Hausanschrift" (Behoerde).
            if (!preg_match('/(?:^|\s)Anschrift\s*:\s*(\S.*?)\s*$/u', $line, $m)
                || preg_match('/Hausanschrift/u', $line)) {
                continue;
            }
            $street = trim($m[1]);
            if (preg_match('/^(.*\D)\s+(\d+(?:\s*[a-zA-Z])?)\s*$/u', $street, $s)) {
                $raw['street'] = trim($s[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $s[2]));
            } elseif (preg_match('/\p{L}/u', $street)) {
                $raw['street'] = $street;
            }
            for ($j = $i + 1; $j < count($this->lines) && $j <= $i + 4; $j++) {
                if (preg_match('/(?<!\d)(\d{5})\s+([A-ZÄÖÜ][\p{L}.\-]+(?:[ \-][A-ZÄÖÜ]?[\p{L}.\-]+)*)/u', $this->lines[$j], $z)) {
                    $raw['zip'] = $z[1];
                    $raw['city'] = trim((string) preg_replace('/\s{2,}.*$/u', '', $z[2]));
                    break;
                }
            }
            return;
        }
    }

    /** Wert nach "Label:" bis Zeilenende (Label links, Wert nach Doppelpunkt). */
    private function labelValue(string $label): ?string
    {
        foreach ($this->lines as $line) {
            if (preg_match('/^\s*' . preg_quote($label, '/') . '\s*:\s*(\S.*?)\s*$/u', $line, $m)) {
                $val = trim($m[1]);
                if ($val !== '') {
                    return $val;
                }
            }
        }
        return null;
    }
}
