<?php
namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Parser fuer die "Beratungsdokumentation" gewerblicher Versicherungen aus der
 * Makler-Vergleichssoftware (z.B. Frachtfuehrerhaftpflicht, Betriebs-/
 * Inhaltsversicherung). Aufbau ist ueber die Sparten hinweg gleich:
 *
 *   Kopf     : "Beratungsdokumentation ... Vermittlungsauftrags: <Sparte>"
 *              + Vorgangsnummer
 *   Kunde    : Block "Vorschlag fuer:" (LINKE Spalte) - rechts daneben steht
 *              der "Ansprechpartner" (= der Makler, NICHT der Kunde!)
 *   Vertrag  : Block "Unsere Empfehlung" mit "Versicherer:/Produkt:/
 *              Versicherungssumme:/Zahlweise:/Selbstbehalt:/Jahrespraemie:"
 *
 * Wichtig: Es wird die LINKE Spalte (Kunde) gelesen, damit nicht der Makler
 * ("Ansprechpartner", info@dienstly24.de) als Kunde uebernommen wird. Alle
 * Werte durchlaufen die harte Feldvalidierung.
 */
class GewerbeBeratungsdokumentationParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        if (!str_contains($upper, 'BERATUNGSDOKUMENTATION')
            || !str_contains($upper, 'VORGANGSNUMMER')
            || !str_contains($upper, 'VORSCHLAG FÜR')) {
            return null;
        }

        $person = $this->parsePerson($text);
        $versicherung = $this->parseContract($text);

        // Ohne belastbaren Kern (Versicherer/Sparte oder Name/Firma) lieber der
        // KI ueberlassen.
        if ($person === [] && ($versicherung['insurer'] ?? null) === null) {
            return null;
        }

        $who = ($person['company_name'] ?? '') !== ''
            ? $person['company_name']
            : trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));

        return [
            'type' => 'beratungsprotokoll',
            'confidence' => 72,
            'summary' => 'Beratungsdokumentation (Gewerbe)'
                . (isset($versicherung['tariff']) ? ' - ' . $versicherung['tariff'] : '')
                . (isset($versicherung['insurer']) ? ' - ' . $versicherung['insurer'] : '')
                . ($who !== '' ? ' - ' . $who : '')
                . $this->contractExtras($text)
                . ' - Felder gratis aus der Dokumentation gelesen (ohne KI).',
            'title' => 'Beratungsdokumentation'
                . (isset($versicherung['tariff']) ? ' ' . $versicherung['tariff'] : '')
                . ($who !== '' ? ' - ' . $who : ''),
            'data' => [
                'person' => $person,
                'versicherung' => $versicherung,
                'kfz' => [],
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
        $raw = [];
        $lines = preg_split('/\R/', $text) ?: [];

        // Block "Vorschlag fuer:" finden; die Kundendaten stehen in der LINKEN
        // Spalte der Folgezeilen (rechts steht der Makler/Ansprechpartner).
        $start = -1;
        foreach ($lines as $i => $line) {
            if (mb_stripos($line, 'Vorschlag für') !== false) {
                $start = $i;
                break;
            }
        }
        if ($start < 0) {
            return [];
        }

        $end = min($start + 8, count($lines));
        for ($i = $start + 1; $i < $end; $i++) {
            // Nur die linke Spalte (bis zum ersten grossen Spaltenabstand).
            $left = trim((string) (preg_split('/\s{2,}/', trim($lines[$i]))[0] ?? ''));
            if ($left === '') {
                continue;
            }
            // PLZ + Ort.
            if (preg_match('/^(\d{5})\s+([A-ZÄÖÜ][\p{L}\-. ]+)$/u', $left, $m)) {
                $raw['zip'] = $m[1];
                $raw['city'] = trim($m[2]);
                continue;
            }
            // Strasse + Hausnummer.
            if (preg_match('/^([A-ZÄÖÜ][\p{L}.\- ]*?\D)\s+(\d+\s*[a-zA-Z]?)$/u', $left, $m)
                && preg_match('/\p{L}{3,}/u', $m[1])) {
                $raw['street'] = trim($m[1]);
                $raw['house_number'] = trim((string) preg_replace('/\s+/', ' ', $m[2]));
                continue;
            }
            // Firmenname.
            if ($this->looksLikeCompany($left)) {
                $raw['company_name'] ??= $left;
                continue;
            }
            // Personenname (2-4 Grosswoerter, keine Firma).
            if (!isset($raw['first_name'])
                && preg_match('/^[A-ZÄÖÜ][\p{L}\-]+(?:\s+[A-ZÄÖÜ][\p{L}\-]+){1,3}$/u', $left)) {
                $parts = preg_split('/\s+/', $left) ?: [];
                $raw['first_name'] = array_shift($parts);
                $raw['last_name'] = implode(' ', $parts) ?: null;
            }
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string,mixed> */
    private function parseContract(string $text): array
    {
        $raw = [];

        // Sparte/Produkt aus dem Kopf ("Vermittlungsauftrags:\n<Sparte>").
        $sparteName = null;
        if (preg_match('/Vermittlungsauftrags:\s*\R+\s*([^\r\n]+)/u', $text, $m)) {
            $sparteName = trim($m[1]);
        } elseif (preg_match('/^\s*Produkt:\s+([^\r\n]+)/um', $text, $m)) {
            $sparteName = trim($m[1]);
        }
        $raw['sparte'] = $this->mapSparte((string) $sparteName);
        if ($sparteName !== null && $sparteName !== '') {
            $raw['tariff'] = $sparteName;
        }

        // Versicherer (Empfehlungs-Block: "Versicherer:   <Name>").
        if (preg_match('/Versicherer:\s+([^\r\n]+)/u', $text, $m)) {
            $raw['insurer'] = trim($m[1]);
        }

        // Versicherungsbeginn (aus den Risikoangaben).
        if (preg_match('/Gewünschter Versicherungsbeginn\s+(\d{2})\.(\d{2})\.(\d{4})/u', $text, $m)) {
            $raw['start_date'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        // Beitrag: Jahrespraemie bevorzugt, sonst "Praemie gemaess Zahlweise".
        $interval = null;
        if (preg_match('/Zahlweise:\s+([^\r\n]+)/u', $text, $m)) {
            $interval = $this->interval(trim($m[1]));
        }
        if (preg_match('/Jahrespr[äa]mie:\s+([\d.]+,\d{2})/u', $text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = 'yearly';
        } elseif (preg_match('/Pr[äa]mie gem[äa][ßs]{1,2} Zahlweise:\s+([\d.]+,\d{2})/u', $text, $m)) {
            $raw['premium_amount'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
            $raw['premium_interval'] = $interval ?? 'yearly';
        }

        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /** Zusatzinfos (Versicherungssumme, SB, Betrieb) fuer die Zusammenfassung. */
    private function contractExtras(string $text): string
    {
        $out = '';
        if (preg_match('/Versicherungssumme:\s+([^\r\n]+)/u', $text, $m)) {
            $out .= ' Versicherungssumme: ' . trim($m[1]) . '.';
        }
        if (preg_match('/Selbstbehalt:\s+([^\r\n]+)/u', $text, $m)) {
            $out .= ' Selbstbehalt: ' . trim($m[1]) . '.';
        }
        if (preg_match('/Betriebsart\s+([^\r\n.]+)/u', $text, $m)) {
            $out .= ' Betrieb: ' . trim($m[1]) . '.';
        }
        return $out;
    }

    private function mapSparte(string $name): string
    {
        $n = mb_strtolower($name);
        return match (true) {
            str_contains($n, 'rechtsschutz')                                   => 'rechtsschutz',
            str_contains($n, 'unfall')                                         => 'unfall',
            str_contains($n, 'inhalt') || str_contains($n, 'geschäft')
                || str_contains($n, 'gebäude') || str_contains($n, 'gebaeude')
                || str_contains($n, 'sach')                                    => 'sach',
            str_contains($n, 'leben') || str_contains($n, 'rente')             => 'leben',
            // Frachtfuehrer-/Verkehrshaftung, Betriebs-/Berufshaftpflicht etc.
            default                                                            => 'haftpflicht',
        };
    }

    private function looksLikeCompany(string $name): bool
    {
        return (bool) preg_match(
            '/(GmbH|mbH|\bUG\b|\bOHG\b|\bKG\b|\bGbR\b|\bAG\b|\be\.?\s?K\b|Einzelunt|Einzelfirma|Gewerbe|Unternehmen|Betrieb|Spedition|Logistik|Transport)/ui',
            $name
        );
    }

    private function interval(string $german): ?string
    {
        return match (mb_strtolower(trim($german))) {
            'monatlich'        => 'monthly',
            'vierteljährlich'  => 'quarterly',
            'halbjährlich'     => 'semiannual',
            'jährlich'         => 'yearly',
            default            => null,
        };
    }
}
