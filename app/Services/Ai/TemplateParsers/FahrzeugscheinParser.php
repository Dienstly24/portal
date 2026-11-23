<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer das FOTO eines Fahrzeugscheins (Zulassungsbescheinigung
 * Teil I), Betreiber-Wunsch 23.11.2026 (KI-130): "wenn ich den Fahrzeugschein
 * schicke, soll er Kunde und Vertrag ueber Kennzeichen oder FIN finden und
 * die Daten ins System bringen".
 *
 * Mit Chromium + Tesseract an einer Nachbildung gemessen (PNG und
 * JPEG-komprimiert): die OCR liest die Werte gut, aber die drei Spalten des
 * Scheins kommen in WECHSELNDER Reihenfolge an, die Feldkennungen ("E",
 * "2.1", "C.1.2") fast nie sauber, und einmal fehlte die FIN ganz. Gelesen
 * wird deshalb nach der FORM der Werte, nicht nach Spalten oder Kennungen
 * (dieselbe Lehre wie bei eAT-Rueckseite und Energieportal):
 * - FIN: das EINZIGE 17-stellige Zeichen-Wort ohne I/O/Q mit Buchstaben UND
 *   Ziffern. Zwei verschiedene -> keine FIN (nie raten).
 * - Kennzeichen: die Zeile nach "Amtliches Kennzeichen".
 * - Name: die Zeilen zwischen Kennzeichen und Anschrift - erst Nachname
 *   (C.1.1), dann Vorname(n) (C.1.2). Eine Firma (Rechtsform) wird
 *   Firmenname.
 * - Erstzulassung + HSN/TSN: das Datum, auf das in DERSELBEN Zeile die
 *   4-stellige Herstellernummer folgt (Feld B, 2.1, 2.2).
 * - Leistung "88 /3600" (P.2/P.4), nur wenn genau ein solcher Wert.
 *
 * Bewusst NICHT: Gewichte, Reifen, Nummer der Bescheinigung, Datum der
 * Typgenehmigung (Feld 6) - sie haben in der Kundenakte kein Feld. Teil II
 * (Fahrzeugbrief) ist ein anderes Dokument und wird hier nicht gelesen.
 */
class FahrzeugscheinParser implements DocumentTemplateParser
{
    use ValidatesExtractedFields;

    /** Woerter der Formularbeschriftung - nie ein Name. */
    private const BESCHRIFTUNG = '/\b(Name|Vorname|Firmenname|Anschrift|Kennzeichen|Amtliches|Datum|Fahrzeugschein|Zulassungsbescheinigung|Gemeinschaft|Bundesrepublik|Deutschland|Inhaber|Eigent|Teil|Registration|Certificate)\b/iu';

    /** @var list<string> */
    private array $lines = [];

    /** Foto ohne lesbare Beschriftungen (KI-132) - nur dann gilt das Kennzeichen ohne Beschriftung. */
    private bool $ohneBeschriftungen = false;

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        // "Teil I" kommt am Handyfoto als "Teil |" oder "Teil!" an (KI-132).
        $teil1 = (bool) preg_match('/ZULASSUNGSBESCHEINIGUNG\s+TEIL\s*[I1|!](?![I1|!])/u', $upper)
            || str_contains($upper, 'FAHRZEUGSCHEIN');
        if (preg_match('/ZULASSUNGSBESCHEINIGUNG\s+TEIL\s*(II|2)\b|FAHRZEUGBRIEF/u', $upper)) {
            return null;
        }
        // Erwaehnt ein Antrag/eine Police den Fahrzeugschein nur, fehlt das
        // Formularvokabular.
        $vokabular = (bool) preg_match('/KENNZEICHEN|C\.?1\.?1|VORNAME|ANSCHRIFT/u', $upper);
        // Am echten Handyfoto (KI-132) sind die kleinen Beschriftungen nicht
        // lesbar, die Ueberschrift oft zerrissen. Dann genuegt der Anfang
        // der Ueberschrift + eine FIN-artige Kennung + der Aufdruck des
        // Formulars - aber nie in einem Text, der von Versicherung, Beitrag
        // oder Antrag spricht.
        $foto = ! $vokabular
            && str_contains($upper, 'ZULASSUNGSBES')
            && preg_match('/(?<![A-Z0-9])[A-HJ-NPR-Z0-9]{17}(?![A-Z0-9])/u', $upper)
            && preg_match('/DEUTSCHLAND|BUNDESREPUBLIK|GEMEINSCHAFT|PERSONENKRAFTWAGEN/u', $upper)
            && ! preg_match('/VERSICHERUNG|BEITRAG|ANTRAG/u', $upper);
        if (! ($teil1 && $vokabular) && ! $foto) {
            return null;
        }
        $this->ohneBeschriftungen = $foto;

        $zeilen = array_map(fn ($l) => trim((string) $l), preg_split('/\R/u', $text) ?: []);
        $this->lines = array_values(array_filter($zeilen, fn ($l) => $l !== ''));

        $vehicle = $this->parseVehicle($text);
        if (! isset($vehicle['vin']) && ! isset($vehicle['license_plate'])) {
            return null; // ohne Fahrzeug-Identitaet ist das Ergebnis wertlos
        }
        $person = $this->parsePerson();

        $name = trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? $person['company_name'] ?? ''));
        $summary = 'Fahrzeugschein (Zulassungsbescheinigung Teil I)'
            .($name !== '' ? ' - Halter '.$name : '')
            .(isset($vehicle['license_plate']) ? ' - '.$vehicle['license_plate'] : '')
            .(isset($vehicle['vin']) ? ' - FIN '.$vehicle['vin'] : ' - FIN nicht lesbar')
            .(isset($vehicle['manufacturer']) ? ' - '.trim($vehicle['manufacturer'].' '.($vehicle['model'] ?? '')) : '')
            .(isset($vehicle['first_registration']) ? ' - Erstzulassung '.date('d.m.Y', (int) strtotime($vehicle['first_registration'])) : '')
            .(isset($vehicle['acquisition_date']) ? ' - zugelassen auf Halter am '.date('d.m.Y', (int) strtotime($vehicle['acquisition_date'])) : '')
            .' - Felder gratis per OCR gelesen (ohne KI), bitte pruefen.';

        return [
            'type' => 'fahrzeugschein',
            'confidence' => isset($vehicle['vin']) ? 70 : 55,
            'summary' => $summary,
            'title' => 'Fahrzeugschein'.(isset($vehicle['license_plate']) ? ' '.$vehicle['license_plate'] : ''),
            'data' => [
                'person' => $person,
                'versicherung' => [],
                'kfz' => $vehicle,
                'gesundheit' => [],
                'bank' => [],
                'personen' => [],
                'energie' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function parseVehicle(string $text): array
    {
        $raw = [];

        $vins = [];
        if (preg_match_all('/(?<![A-Z0-9])([A-HJ-NPR-Z0-9]{17})(?![A-Z0-9])/u', mb_strtoupper($text), $mm)) {
            foreach ($mm[1] as $kandidat) {
                if (preg_match('/\d/', $kandidat) && preg_match_all('/[A-Z]/', $kandidat) >= 2) {
                    $vins[$kandidat] = true;
                }
            }
        }
        if (count($vins) === 1) {
            $raw['vin'] = array_key_first($vins);
        }

        if (($i = $this->plateLine()) !== null) {
            $raw['license_plate'] = $this->plateFrom($this->lines[$i]);
        } elseif ($this->ohneBeschriftungen && ($ohne = $this->plateOhneBeschriftung()) !== null) {
            $raw['license_plate'] = $ohne[1];
        }

        // Feld B (Erstzulassung) + 2.1 (HSN) + 2.2 (TSN) stehen in einer Zeile.
        foreach ($this->lines as $line) {
            if (! preg_match('/(\d{2})\.(\d{2})\.((?:19|20)\d{2})(.*)$/u', $line, $m)) {
                continue;
            }
            $rest = (string) preg_replace('/(?<!\d)2[.,]?[12](?!\d)/u', ' ', $m[4]);
            // TSN = die ersten DREI Zeichen von Feld 2.2 - sie darf rein
            // numerisch sein (BMW "733" aus "73301A"), dann muss der Rest
            // von 2.2 einen Buchstaben tragen (sonst ist es irgendeine Zahl).
            if (preg_match('/(?<![\d.])(\d{4})(?![\d.])\W{0,4}\s*([A-Z0-9]{3})([A-Z0-9]{0,8})/u', $rest, $h)
                && preg_match('/[A-Z]/', $h[2].$h[3])) {
                $raw['first_registration'] = $m[3].'-'.$m[2].'-'.$m[1];
                $raw['hsn'] = $h[1];
                $raw['tsn'] = $h[2];
                break;
            }
        }

        // Feld I: Datum der Zulassung auf den Halter.
        if (preg_match('/\bDatum\b\W{0,4}(\d{2})\.(\d{2})\.(\d{4})\b/u', $text, $m)) {
            $datum = $m[3].'-'.$m[2].'-'.$m[1];
            $heute = date('Y-m-d');
            if ($datum <= $heute) {
                $raw['acquisition_date'] = $datum;
            }
        }

        // Feld 2: "FIAT (I)" - Herstellerkurzname mit Laenderkennung.
        if (preg_match('/\b(\p{Lu}[\p{Lu}\-]{1,20}(?:\s\p{Lu}[\p{Lu}\-]{1,20})?)\s*\((?:[A-Z]{1,3})\)/u', $text, $m)) {
            $raw['manufacturer'] = $m[1];
            // Feld D.3: "FIAT Ducato" - Handelsbezeichnung hinter der Marke.
            if (preg_match('/\b'.preg_quote($m[1], '/').'\h+(\p{L}[\p{L}0-9\-]{1,20}(?:\h[\p{L}0-9\-]{1,15})?)/u', $text, $d)
                && ! str_starts_with($d[1], '(')) {
                $raw['model'] = trim($d[1]);
            }
        }

        if (preg_match('/\b(Diesel|Benzin|Elektro|Hybrid\S*|Super)\b/iu', $text, $m)) {
            $raw['fuel_type'] = $m[1];
        }

        // P.2/P.4 "88 /3600": kW + Nenndrehzahl - nur ein eindeutiger Wert.
        if (preg_match_all('#(?<![\d/])(\d{2,3})\s?/\s?([1-9]\d{3})(?![\d/])#u', $text, $mm) && count(array_unique($mm[1])) === 1) {
            $raw['power_kw'] = (int) $mm[1][0];
        }

        if (preg_match('/\bPKW\b/u', $text)) {
            $raw['vehicle_type'] = 'pkw';
        } elseif (preg_match('/WOHNMOBIL/u', mb_strtoupper($text))) {
            $raw['vehicle_type'] = 'wohnmobil';
        }

        return $this->validatedVehicle($raw);
    }

    /** Index der Zeile mit dem Kennzeichen (nach "Amtliches Kennzeichen"). */
    private function plateLine(): ?int
    {
        foreach ($this->lines as $i => $line) {
            if (! preg_match('/Amt\p{L}{0,4}ches\s+Kennzeichen/u', $line)) {
                continue;
            }
            for ($j = $i + 1; $j <= $i + 3 && $j < count($this->lines); $j++) {
                if ($this->plateFrom($this->lines[$j]) !== null) {
                    return $j;
                }
            }
        }

        return null;
    }

    /**
     * Kennzeichen OHNE lesbare Beschriftung (KI-132): am Handyfoto steht
     * "f : KI X77 PERSONENKRAFTWAGEN" - die kleine Zeile "Amtliches
     * Kennzeichen" fehlt. Genommen wird ein kennzeichenfoermiges Wort nur,
     * wenn es im GANZEN Text genau EIN solches gibt; die Nummer der
     * Bescheinigung ("SE-K-0-173/26-...") faellt durch den Bindestrich danach
     * heraus. Zwei verschiedene -> keins (nie raten).
     *
     * @return array{0:int,1:string}|null Zeilenindex + Kennzeichen
     */
    private function plateOhneBeschriftung(): ?array
    {
        $gefunden = [];
        foreach ($this->lines as $i => $line) {
            if (preg_match_all('/(?<![\p{L}\d\-.\/])([A-ZÄÖÜ]{1,3})[ \-]([A-Z]{1,2})[ \-]?(\d{1,4})([EH]?)(?![\p{L}\d\-.\/])/u', $line, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) {
                    $gefunden[$m[1].' '.$m[2].' '.$m[3].$m[4]] ??= $i;
                }
            }
        }

        return count($gefunden) === 1 ? [(int) reset($gefunden), (string) array_key_first($gefunden)] : null;
    }

    /** "KI AB123 2 LKW ..." -> "KI AB 123"; nur ein Kennzeichen am Zeilenanfang. */
    private function plateFrom(string $line): ?string
    {
        if (! preg_match('/^([A-ZÄÖÜ]{1,3})[\s\-]+([A-Z]{1,2})[\s\-]?(\d{1,4})([EH]?)(?![\d.\/])/u', $line, $m)) {
            return null;
        }

        return $m[1].' '.$m[2].' '.$m[3].$m[4];
    }

    /** @return array<string,mixed> */
    private function parsePerson(): array
    {
        $street = null;
        foreach ($this->lines as $i => $line) {
            $next = $this->lines[$i + 1] ?? '';
            if (preg_match('/^(\p{L}[\p{L}ß.\- ]{2,40}?)\s+(\d{1,4}(?:\s?[a-zA-Z](?!\p{L}))?)(?![\d.,\/])/u', $line, $s)
                && preg_match('/^(\d{5})\s+(\p{Lu}[\p{L}\-]+(?:\s\p{Lu}[\p{L}\-]+)?)/u', $next, $z)
                && ! preg_match(self::BESCHRIFTUNG, $s[1])) {
                $street = ['index' => $i, 'street' => trim($s[1]).' '.trim($s[2]), 'zip' => $z[1], 'city' => $z[2]];
                break;
            }
        }

        $start = $this->plateLine() ?? ($this->ohneBeschriftungen ? $this->plateOhneBeschriftung()[0] ?? null : null);
        $end = $street['index'] ?? count($this->lines);
        $from = $start !== null ? $start + 1 : max(0, $end - 6);
        $namen = [];
        for ($i = $from; $i < $end; $i++) {
            if (preg_match('/^(\p{Lu}[\p{L}\-\']+(?:\s+\p{Lu}[\p{L}\-\']+){0,3})(?=\s+[^\p{L}\s]|\s{2,}|$)/u', $this->lines[$i], $m)
                && ! preg_match(self::BESCHRIFTUNG, $m[1])
                && mb_strlen($m[1]) >= 3) {
                $namen[] = $m[1];
            }
        }

        $raw = [];
        if ($namen !== [] && preg_match('/\b(GmbH|AG|KG|UG|e\.K\.|OHG|GbR|mbH)\b/u', $namen[0])) {
            $raw['company_name'] = $namen[0];
        } elseif (count($namen) >= 2) {
            $raw['last_name'] = $namen[0];
            $raw['first_name'] = $namen[1];
        }
        if ($street !== null) {
            $raw['street'] = $street['street'];
            $raw['zip'] = $street['zip'];
            $raw['city'] = $street['city'];
        }

        return $this->validatedPerson($raw);
    }
}
