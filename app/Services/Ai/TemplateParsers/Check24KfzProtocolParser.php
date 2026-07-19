<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer das CHECK24-Beratungsprotokoll zur Kfz-Versicherung.
 *
 * Dieses Formular ist ueber alle Kunden hinweg identisch aufgebaut (nur die
 * Werte unterscheiden sich) - es per fester Regel aus der Textebene zu lesen
 * ist kostenlos, fehlerfrei und sofort, statt jedes Mal die (kostenpflichtige)
 * KI zu bemuehen. Alle Werte durchlaufen dieselbe harte Feldvalidierung wie
 * die KI-Antwort; unsichere Felder bleiben leer statt falsch.
 */
class Check24KfzProtocolParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    public function parse(string $text): ?array
    {
        // Nur zustaendig fuer das CHECK24-Kfz-Beratungsprotokoll.
        $upper = mb_strtoupper($text);
        if (!str_contains($upper, 'BERATUNGSPROTOKOLL') || !str_contains($upper, 'KFZ')
            || !str_contains($upper, 'CHECK24')) {
            return null;
        }

        $person = $this->parsePerson($text);
        $kfz = $this->parseVehicle($text);
        $versicherung = $this->parseInsurance($text);

        // Ohne belastbare Kern-Felder lieber nicht als Template ausgeben
        // (dann uebernimmt die normale Analyse/KI).
        if ($person === [] && $kfz === []) {
            return null;
        }

        return [
            'type' => 'beratungsprotokoll',
            // Deterministisch aus einem bekannten Formular - hoehere Konfidenz
            // als die generische OCR-Heuristik, aber weiter mit Mitarbeiter-
            // Pruefung (der Kunde wird i.d.R. neu angelegt).
            'confidence' => 75,
            'summary' => 'CHECK24-Beratungsprotokoll Kfz - Felder gratis aus der Textebene gelesen (ohne KI).',
            'title' => 'Beratungsprotokoll Kfz'
                . (isset($versicherung['insurer']) ? ' ' . $versicherung['insurer'] : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $versicherung,
                'kfz' => $kfz,
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function parsePerson(string $text): array
    {
        $raw = [
            'birth_date' => $this->germanDate($this->label($text, 'Geburtsdatum') ?? $this->afterWord($text, 'Geboren am')),
            'email' => $this->customerEmail($text),
            'phone' => $this->phone($text),
        ];

        // Geschlecht -> nur intern fuer die Person nicht noetig, aber Wohnort
        // liefert PLZ + Ort zuverlaessig ueber ein Label.
        if (preg_match('/(?:Wohnort|Zulassung in)\s*:\s*(\d{5})\s+([A-Za-zÄÖÜäöüß .\-]+?)(?:\s{2,}|$)/u', $text, $m)) {
            $raw['zip'] = $m[1];
            $raw['city'] = trim($m[2]);
        }

        // Name + Strasse aus dem Kopf-Block (3-Spalten-Layout: Anrede |
        // Anschrift | E-Mail). Die Zeile NACH der Anrede-Zeile traegt in der
        // ersten Spalte den Namen, in der zweiten die Strasse.
        [$name, $street] = $this->nameAndStreet($text);
        if ($name !== null) {
            $parts = preg_split('/\s+/', $name);
            $raw['last_name'] = array_pop($parts);
            $raw['first_name'] = implode(' ', $parts);
        }
        if ($street !== null) {
            // Hausnummer am Ende abspalten - auch mit Leerzeichen vor dem
            // Zusatzbuchstaben ("Mittelstr. 21 b") oder ohne ("Alleestr. 43").
            if (preg_match('/^(.*?)\s+(\d+\s*[a-zA-Z]?)$/u', $street, $m)) {
                $raw['street'] = trim($m[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
            } else {
                $raw['street'] = $street;
            }
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseVehicle(string $text): array
    {
        $raw = [];
        if (preg_match('/HSN\/TSN\s*:\s*(\d{4})\s*\/\s*([A-Z0-9]{3})/i', $text, $m)) {
            $raw['hsn'] = $m[1];
            $raw['tsn'] = strtoupper($m[2]);
        }
        $halter = $this->label($text, 'Halter');
        if ($halter !== null) {
            $raw['holder_type'] = stripos($halter, 'Versicherungsnehmer') !== false
                ? 'versicherungsnehmer' : 'abweichender_halter';
        }
        $mileage = $this->label($text, 'Jährliche Fahrleistung');
        if ($mileage !== null && preg_match('/([\d.]+)/', $mileage, $m)) {
            $raw['annual_mileage'] = (int) str_replace('.', '', $m[1]);
        }
        $deckung = $this->label($text, 'Deckung');
        if ($deckung !== null) {
            $raw['has_vollkasko'] = stripos($deckung, 'Vollkasko') !== false;
            $raw['has_teilkasko'] = !$raw['has_vollkasko'] && stripos($deckung, 'Teilkasko') !== false;
        }
        $sb = $this->label($text, 'Selbstbeteiligung');
        if ($sb !== null && preg_match('/([\d.]+)\s*€/', $sb, $m)) {
            $deductible = (int) str_replace('.', '', $m[1]);
            if (!empty($raw['has_vollkasko'])) {
                $raw['vollkasko_deductible'] = $deductible;
            } elseif (!empty($raw['has_teilkasko'])) {
                $raw['teilkasko_deductible'] = $deductible;
            }
        }
        // Tatsaechliche SF-Klasse Haftpflicht (die reale Einstufung; die
        // "Angegebene" ist oft "keine"). Steht im Spaltenlayout OHNE Doppel-
        // punkt, daher direkt per Regex. Der Betrieb musste sie bisher
        // manuell nachtragen.
        if (preg_match('/Tats[äa]chliche SF-Klasse Haftpflicht\s+SF\s*(\d{1,2}(?:\/\d)?|[MS])/ui', $text, $m)) {
            $raw['sf_liability_class'] = strtoupper($m[1]);
        }

        return $this->validatedVehicle($raw);
    }

    /** @return array<string,mixed> */
    private function parseInsurance(string $text): array
    {
        $raw = ['sparte' => 'kfz'];

        // Ausgewaehlter Tarif: die Zeile direkt nach "folgenden Tarif:".
        if (preg_match('/folgenden Tarif:\s*\R+\s*([^\r\n]+)/u', $text, $m)) {
            $tarif = trim($m[1]);
            // Versicherer = erstes Wort der Tarifzeile (z.B. "ADAC Basis ...").
            $raw['insurer'] = preg_split('/\s+/', $tarif)[0] ?? null;
        }

        $raw['start_date'] = $this->germanDate($this->label($text, 'Versicherungsbeginn'));

        // Gesamtbeitrag (inkl. Versicherungssteuer)  222,65 € vierteljährlich
        if (preg_match('/Gesamtbeitrag[^\d]*([\d.]+,\d{2})\s*€\s*([a-zäöü]+)/ui', $text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = $this->interval($m[2]);
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Wert nach "Label:" bis zum Spaltenumbruch (2+ Leerzeichen) oder Zeilenende. */
    private function label(string $text, string $label): ?string
    {
        $pattern = '/' . preg_quote($label, '/') . '\s*:\s*([^\n]+?)(?:\s{2,}|$)/u';
        return preg_match($pattern, $text, $m) ? trim($m[1]) : null;
    }

    private function afterWord(string $text, string $word): ?string
    {
        return preg_match('/' . preg_quote($word, '/') . '\s+([^\s]+)/u', $text, $m) ? $m[1] : null;
    }

    /** Erste E-Mail, die NICHT von CHECK24 stammt (= die des Kunden). */
    private function customerEmail(string $text): ?string
    {
        if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $mm)) {
            foreach ($mm[0] as $email) {
                if (stripos($email, '@check24') === false) {
                    return strtolower($email);
                }
            }
        }
        return null;
    }

    private function phone(string $text): ?string
    {
        // Erste 0-Nummer, die WIE eine deutsche Telefon-/Mobilnummer aussieht -
        // so wird nicht versehentlich eine lange 0-Vertrags-/Referenznummer als
        // Telefon uebernommen.
        if (preg_match_all('/\b(0\d{9,14})\b/', $text, $mm)) {
            foreach ($mm[1] as $candidate) {
                if (\App\Support\GermanPhone::isMobile($candidate) || \App\Support\GermanPhone::isLandline($candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /** @return array{0:?string,1:?string} [Name, Strasse] aus dem Kopf-Block. */
    private function nameAndStreet(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(Herr|Frau)\b.*Anschrift/u', $line) && isset($lines[$i + 1])) {
                $cols = preg_split('/\s{2,}/', trim($lines[$i + 1]));
                $name = ($cols[0] ?? '');
                $street = $cols[1] ?? null;
                // Name muss wie ein Name aussehen (>= 2 Woerter, nur Buchstaben).
                if (!preg_match('/^[A-Za-zÄÖÜäöüß\-]+(?:\s+[A-Za-zÄÖÜäöüß\-]+)+$/u', $name)) {
                    $name = null;
                }
                return [$name ?: null, $street];
            }
        }
        return [null, null];
    }

    private function germanDate(?string $value): ?string
    {
        if ($value !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return null;
    }

    private function interval(string $german): ?string
    {
        return match (mb_strtolower($german)) {
            'monatlich' => 'monthly',
            'vierteljährlich' => 'quarterly',
            'halbjährlich' => 'semiannual',
            'jährlich' => 'yearly',
            default => null,
        };
    }
}
