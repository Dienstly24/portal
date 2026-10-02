<?php

namespace App\Services\Ai\TemplateParsers;

use App\Services\Ai\Concerns\LiestSpalten;
use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;

/**
 * Gratis-Parser fuer den Fragebogen zur Familienversicherung (gesetzliche
 * Krankenversicherung, u.a. KKH). Das Formular fuehrt das Mitglied und seine
 * familienversicherten Angehoerigen (Ehegatte + Kinder) in einer festen
 * Spaltentabelle - es laesst sich per fester Regel aus der PDF-Textebene lesen
 * (kostenlos, deterministisch).
 *
 * Anders als beim Kfz-Beratungsprotokoll werden hier die Angehoerigen bewusst
 * MITgelesen (Betreiber-Regel: "keine Kinder" gilt nur fuer Kfz). Die
 * gelesenen Personen (data.person = Mitglied, data.personen = Angehoerige)
 * speisen den bestehenden Kranken-Familien-Workflow (Haupt-Frage,
 * familienversichert, Wechseldatum).
 *
 * ZWEI BAUFORMEN, ein Dokumenttyp:
 *
 * (1) der aeltere Fragebogen ("Vorname Name des Mitglieds", Spalte
 *     "Ehegatte") - Werte stehen UEBER der Beschriftung.
 * (2) der KKH-Vordruck 0765 "Angaben zur beitragsfreien
 *     Familienversicherung" (FAVE, Betreiber-Auftrag 02.10.2026): Mitglied im
 *     Kopf, darunter eine DREISPALTIGE Tabelle
 *     "Ehe-/Lebenspartner | Kind | Kind" mit eigenen Beschriftungen
 *     (Nachname, Vorname, Geburtsdatum, Verwandtschaftsverhaeltnis) und auf
 *     Seite 2 Geburtsort/Geburtsland/Staatsangehoerigkeit.
 *
 * Bewusst KEIN zweiter Parser (ARCH-8): es ist derselbe Dokumenttyp, dieselbe
 * Ausgabeform und derselbe Folge-Workflow - nur ein anderes Layout desselben
 * Formulars. Ein 45. Parser haette dieselbe Aussage an zwei Stellen gefuehrt.
 */
class FamilienversicherungParser implements DocumentTemplateParser
{
    use LiestSpalten;
    use ValidatesExtractedFields;

    /** Die Kasse des Vordrucks 0765 steht im Formular selbst. */
    private const FAVE_KASSE = 'KKH';

    /** @var list<string> */
    private array $lines = [];

    public function parse(string $text): ?array
    {
        $upper = mb_strtoupper($text);
        $this->lines = array_map('rtrim', preg_split('/\R/', $text) ?: []);

        // Bauform (2): KKH-Vordruck 0765. Eigene Beschriftungen, eigene
        // Spaltentabelle - deshalb ein eigener Leseweg, aber derselbe Typ.
        if ($this->istFaveVordruck()) {
            return $this->parseFave();
        }

        // Bauform (1): aelterer Fragebogen (Mitglied + Angehoerige).
        if (! str_contains($upper, 'FAMILIENVERSICHERUNG')
            || ! str_contains($upper, 'NAME DES MITGLIEDS')
            || ! str_contains($upper, 'EHEGATTE')) {
            return null;
        }

        $member = $this->parseMember();
        $personen = $this->parseAngehoerige($member['last_name'] ?? null);

        // Ohne Mitglied UND ohne Angehoerige nicht als Template ausgeben.
        if ($member === [] && $personen === []) {
            return null;
        }

        $memberName = trim(($member['first_name'] ?? '').' '.($member['last_name'] ?? ''));
        return [
            'type' => 'familienversicherung',
            'confidence' => 70,
            'summary' => 'Familienversicherung (gesetzliche Krankenversicherung)'
                .($memberName !== '' ? ' - Mitglied '.$memberName : '')
                .' + '.count($personen).' Angehoerige - gratis aus dem Formular gelesen (ohne KI).',
            'title' => 'Familienversicherung'.($memberName !== '' ? ' '.$memberName : ''),
            'data' => [
                'person' => $member,
                'personen' => $personen,
                'versicherung' => $this->parseInsurance(),
                'gesundheit' => ['health_insurance_type' => 'gesetzlich'],
                'kfz' => [],
                'bank' => [],
                'energie' => [],
            ],
        ];
    }

    /** @return array<string,mixed> Mitglied (Vorname Nachname). */
    private function parseMember(): array
    {
        $raw = [];
        // "Hussam Eshak" steht ueber der Beschriftung "Vorname Name des Mitglieds".
        $line = $this->valueAbove('Name des Mitglieds');
        if ($line !== null) {
            $tokens = array_values(array_filter(
                preg_split('/\s+/', $line) ?: [],
                fn ($t) => preg_match('/^\p{L}[\p{L}\-]+$/u', $t) === 1
            ));
            if (count($tokens) >= 2) {
                $raw['first_name'] = $tokens[0];
                $raw['last_name'] = implode(' ', array_slice($tokens, 1));
            }
        }
        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Angehoerige aus der Spaltentabelle (Ehegatte | Kind | Kind | Kind).
     * Vorname- und Geburtsort-Zeile stehen spaltenweise (2+ Leerzeichen),
     * die Geburtsdaten (oft eng gesetzt) werden der Reihe nach zugeordnet.
     *
     * @return list<array<string,mixed>>
     */
    private function parseAngehoerige(?string $memberLastName): array
    {
        $names = $this->columnValues('Vorname');
        if ($names === []) {
            return [];
        }
        $places = $this->columnValues('Geburtsort');
        $dates = $this->datesInRow('Geburtsdatum');

        $out = [];
        foreach ($names as $i => $first) {
            $raw = ['first_name' => $first];
            if ($memberLastName) {
                // Angehoerige tragen i.d.R. den Nachnamen des Mitglieds.
                $raw['last_name'] = $memberLastName;
            }
            if (isset($dates[$i])) {
                $raw['birth_date'] = $this->germanDate($dates[$i]);
            }
            if (isset($places[$i])) {
                $raw['birth_place'] = $places[$i];
            }
            $out[] = $raw;
        }

        return $this->validatedPersons($out);
    }

    /** @return array<string,mixed> */
    private function parseInsurance(): array
    {
        $raw = ['sparte' => 'krankenversicherung'];
        if (preg_match('/Beginn der Familienversicherung:?\s*(\d{2}\.\d{2}\.\d{4})/u', $this->text(), $m)) {
            $raw['start_date'] = $this->germanDate($m[1]);
        }
        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Werte einer spaltenweisen Datenzeile (Label + 2+ Leerzeichen getrennte
     * Spalten), gefiltert auf echte Werte (keine Platzhalter/Labels).
     * @return list<string>
     */
    private function columnValues(string $label): array
    {
        foreach ($this->lines as $line) {
            if (! preg_match('/^'.preg_quote($label, '/').'\s{2,}(.+)$/u', $line, $m)) {
                continue;
            }
            $cells = preg_split('/\s{2,}/', trim($m[1])) ?: [];
            $values = [];
            foreach ($cells as $cell) {
                $cell = trim($cell);
                // Nur ein einzelnes Namens-/Ortswort je Spalte (erstes Token),
                // Platzhalter ("____") und Leerzellen ueberspringen.
                if (preg_match('/^([\p{L}][\p{L}\-]+)/u', $cell, $mm)) {
                    $values[] = $mm[1];
                }
            }
            if ($values !== []) {
                return $values;
            }
        }
        return [];
    }

    /** Alle Datumsangaben (dd.mm.yyyy) einer Zeile in Reihenfolge. @return list<string> */
    private function datesInRow(string $label): array
    {
        foreach ($this->lines as $line) {
            if (mb_stripos($line, $label) === 0 && preg_match_all('/\b(\d{2}\.\d{2}\.\d{4})\b/', $line, $m)) {
                return $m[1];
            }
        }
        return [];
    }

    private function text(): string
    {
        return implode("\n", $this->lines);
    }

    private function labelIndex(string $needle): ?int
    {
        foreach ($this->lines as $i => $line) {
            if (trim($line) !== '' && mb_stripos($line, $needle) !== false) {
                return $i;
            }
        }
        return null;
    }

    private function valueAbove(string $needle): ?string
    {
        $i = $this->labelIndex($needle);
        if ($i === null) {
            return null;
        }
        for ($j = $i - 1; $j >= 0; $j--) {
            if (trim($this->lines[$j]) !== '') {
                return trim($this->lines[$j]);
            }
        }
        return null;
    }

    private function germanDate(?string $value): ?string
    {
        if ($value !== null && preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $value, $m)) {
            return $m[3].'-'.$m[2].'-'.$m[1];
        }
        return null;
    }

    /**
     * Erkennung des KKH-Vordrucks 0765 "Angaben zur beitragsfreien
     * Familienversicherung".
     *
     * STRENG, mit drei Signalen: die Ueberschrift steht auf ZWEI Zeilen,
     * deshalb wird auf dem zusammengezogenen Text gesucht. Ein einzelnes
     * Stichwort genuegt hier nicht - "Familienversicherung" steht auch im
     * Kleingedruckten anderer Kassen-Formulare, und ein Fehlalarm haelt ein
     * echtes Kundendokument von seiner Akte fern.
     */
    private function istFaveVordruck(): bool
    {
        $kompakt = (string) preg_replace('/\s+/u', ' ', implode(' ', $this->lines));

        return mb_stripos($kompakt, 'Angaben zur beitragsfreien Familienversicherung') !== false
            && mb_stripos($kompakt, 'Name, Vorname (Mitglied)') !== false
            && mb_stripos($kompakt, 'Allgemeine Angaben zu Familienangeh') !== false;
    }

    /** @return array<string,mixed>|null */
    private function parseFave(): ?array
    {
        $mitglied = $this->faveMitglied();
        $spalten = $this->faveSpalten();
        $rollen = $this->faveRollen($spalten);
        $personen = $this->faveAngehoerige($spalten, $rollen);

        // Ohne Mitglied UND ohne Angehoerige nicht als Template ausgeben -
        // dann liest die KI das Formular vollstaendig, statt mit einer fast
        // leeren Akte zu "gewinnen".
        if ($mitglied === [] && $personen === []) {
            return null;
        }

        $name = trim(($mitglied['first_name'] ?? '').' '.($mitglied['last_name'] ?? ''));
        $kinder = count(array_filter($personen, fn ($p) => ($p['relation'] ?? null) === 'kind'));
        $partner = count(array_filter($personen, fn ($p) => ($p['relation'] ?? null) === 'ehepartner'));

        return [
            'type' => 'familienversicherung',
            'confidence' => 82,
            'summary' => $this->faveSummary($mitglied, $personen, $partner, $kinder),
            'title' => 'Familienversicherung'.($name !== '' ? ' '.$name : ''),
            // ZWECK VOR "ERKANNT": dieser Vordruck wird fuer die ANGEHOERIGEN
            // hochgeladen. Hat die Spaltentabelle keine Person ergeben (z.B.
            // weil ein Scan das Raster zerlegt), ist das Ergebnis nicht
            // fertig - dann liest die KI das Formular. Das Feld ist ein
            // Steuersignal und wird nie gespeichert.
            'pflichtangaben' => ['personen'],
            'data' => [
                'person' => $mitglied,
                'personen' => $personen,
                'versicherung' => $this->faveInsurance(),
                'gesundheit' => $this->faveHealth(),
                'kfz' => [],
                'bank' => [],
                'energie' => [],
            ],
        ];
    }

    /**
     * Mitglied aus dem Kopf des Vordrucks. Die Beschriftung steht VOR dem
     * Wert auf derselben Zeile ("Name, Vorname (Mitglied) Beispiel, Hamid").
     *
     * @return array<string,mixed>
     */
    private function faveMitglied(): array
    {
        $raw = [];

        // "Nachname, Vorname" - die Reihenfolge steht in der Beschriftung.
        $wert = $this->faveWert('/Name,\s*Vorname\s*\(Mitglied\)/u');
        if ($wert !== null) {
            if (str_contains($wert, ',')) {
                [$nach, $vor] = array_map('trim', explode(',', $wert, 2));
                $raw['last_name'] = $nach;
                $raw['first_name'] = $vor;
            } else {
                // Ohne Komma: letztes Wort ist der Nachname. Die Partikel-Regel
                // ("Yusuf Al Rahman") wendet die gemeinsame Pruefung an.
                $teile = preg_split('/\s+/u', $wert) ?: [];
                if (count($teile) >= 2) {
                    $raw['last_name'] = (string) array_pop($teile);
                    $raw['first_name'] = implode(' ', $teile);
                }
            }
        }

        // Anschrift in EINER Zelle: "Musterweg 12 24105 Kiel".
        // Anker ist die PLZ, nicht ein Trennzeichen - der Vordruck hat keines.
        $anschrift = $this->faveWert('/^Anschrift\b/u');
        if ($anschrift !== null && preg_match('/^(.*?\p{L}.*?)\s+(\d{5})\s+(\p{L}[\p{L}\s.\-]*)$/u', $anschrift, $m)) {
            $raw['street'] = trim($m[1]);
            $raw['zip'] = $m[2];
            $raw['city'] = trim($m[3]);
        }

        // NUR im Kopf des Mitglieds suchen: in der Angehoerigen-Tabelle steht
        // dieselbe Beschriftung, und ein Kinder-Geburtsdatum am Mitglied ist
        // schlimmer als ein leeres Feld.
        $kopf = $this->faveLabelZeile('Angaben zum Ehe-/Lebenspartner', 0, count($this->lines))
            ?? $this->faveLabelZeile('Allgemeine Angaben zu Familienangeh', 0, count($this->lines))
            ?? count($this->lines);
        $geburtsdatum = implode("\n", array_slice($this->lines, 0, $kopf));
        if (preg_match('/Geburtsdatum\s+(\d{2}\.\d{2}\.\d{4})/u', $geburtsdatum, $m)) {
            $raw['birth_date'] = $this->germanDate($m[1]);
        }

        $familienstand = $this->faveAngekreuzt(
            '/^Familienstand\b/u',
            ['ledig', 'verheiratet', 'geschieden', 'verwitwet']
        );
        if ($familienstand !== null) {
            $raw['marital_status'] = $familienstand;
        }

        return $this->validatedPerson(array_filter($raw, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Spaltenpositionen der dreispaltigen Angehoerigen-Tabelle
     * ("Ehe-/Lebenspartner | Kind | Kind").
     *
     * Gemessen wird an den DATENzeilen (Vorname, Nachname, "beantragt ab"),
     * nicht an der Kopfzeile: die Kopfzeile traegt die Geschlechts-Kaestchen
     * und kommt aus der Textebene zerlegt an. Mehrere Zeilen werden
     * zusammengefasst, damit eine Spalte auch dann gefunden wird, wenn sie in
     * einer der Zeilen leer ist.
     *
     * @return list<int>
     */
    private function faveSpalten(): array
    {
        [$von, $bis] = $this->faveAbschnitt();
        $positionen = [];
        foreach (['Vorname', 'Nachname', 'Familienversicherung wird beantragt ab'] as $label) {
            $i = $this->faveLabelZeile($label, $von, $bis);
            if ($i === null) {
                continue;
            }
            foreach ($this->zellenDerZeile($this->lines[$i]) as $zelle) {
                // Die Beschriftung selbst steht am Zeilenanfang.
                if ($zelle['spalte'] < 20) {
                    continue;
                }
                $positionen[] = $zelle['spalte'];
            }
        }

        return $this->faveGruppiere($positionen);
    }

    /**
     * Rolle je Spalte aus der Kopfzeile "Ehe-/Lebenspartner | Kind | Kind".
     * Zugeordnet wird nach SPALTENPOSITION - der Vordruck hat die
     * Partner-Spalte immer links, aber ist sie leer (nur Kinder werden
     * familienversichert), waere "die erste Spalte ist der Partner" falsch.
     *
     * @param  list<int>  $spalten
     * @return array<int,string> Spaltenposition => 'ehepartner'|'kind'
     */
    private function faveRollen(array $spalten): array
    {
        foreach ($this->lines as $line) {
            $zellen = $this->zellenDerZeile($line);
            $partner = false;
            $kinder = 0;
            foreach ($zellen as $zelle) {
                if (mb_stripos($zelle['text'], 'Ehe-/Lebenspartner') === 0) {
                    $partner = true;
                } elseif (mb_stripos($zelle['text'], 'Kind') === 0) {
                    $kinder++;
                }
            }
            if (! $partner || $kinder === 0) {
                continue;
            }

            $rollen = [];
            foreach ($zellen as $zelle) {
                $spalte = $this->faveNaechsteSpalte($zelle['spalte'], $spalten);
                if ($spalte === null) {
                    continue;
                }
                if (mb_stripos($zelle['text'], 'Ehe-/Lebenspartner') === 0) {
                    $rollen[$spalte] = 'ehepartner';
                } elseif (mb_stripos($zelle['text'], 'Kind') === 0) {
                    $rollen[$spalte] = 'kind';
                }
            }
            if ($rollen !== []) {
                return $rollen;
            }
        }

        return [];
    }

    /**
     * Angehoerige aus der Spaltentabelle. Je Spalte eine Person; die Rolle
     * kommt aus der Kopfzeile, nicht aus dem Nachnamen (der Vordruck rechnet
     * ausdruecklich mit abweichenden Familiennamen).
     *
     * @param  list<int>  $spalten
     * @param  array<int,string>  $rollen
     * @return list<array<string,mixed>>
     */
    private function faveAngehoerige(array $spalten, array $rollen): array
    {
        [$von, $bis] = $this->faveAbschnitt();
        $nachnamen = $this->faveZeileNachSpalten('Nachname', $spalten, $von, $bis);
        $vornamen = $this->faveZeileNachSpalten('Vorname', $spalten, $von, $bis);
        // Das Geburtsdatum steht UEBER seiner Beschriftung (wie bei LichtBlick).
        $geburtstage = $this->faveZeileNachSpalten('Geburtsdatum', $spalten, $von, $bis, true);
        // Geburtsort und Staatsangehoerigkeit stehen auf SEITE 2 - in einer
        // Tabelle mit EIGENEM Spaltenraster (gemessen 47/79/117 gegen 54/94/137
        // auf Seite 1). Mit dem Raster der ersten Seite fielen alle drei Werte
        // auf dieselbe Spalte und nur der erste ueberlebte; deshalb wird dort
        // der REIHENFOLGE nach zugeordnet - und nur, wenn die Anzahl der Werte
        // genau zur Anzahl der Spalten passt (sonst wird nichts zugeordnet).
        $anzahl = count($spalten);
        $geburtsorte = $this->faveZeileNachReihenfolge('Geburtsort', $anzahl);
        $staaten = $this->faveZeileNachReihenfolge('Staatsangeh', $anzahl);
        $geschlechter = $this->faveGeschlechter($spalten, $von, $bis);
        $verhaeltnisse = $this->faveVerhaeltnisse($spalten);

        $out = [];
        foreach ($spalten as $index => $spalte) {
            $vor = $vornamen[$spalte] ?? null;
            $nach = $nachnamen[$spalte] ?? null;
            if ($vor === null && $nach === null) {
                continue;
            }
            $rolle = $rollen[$spalte] ?? null;
            // Nur ein BELEGTES leibliches Kind bzw. der Partner bekommen eine
            // Rolle. Stiefkind/Enkel/Pflegekind sind KEINE Kinder des
            // Mitglieds - sie stehen als "angehoerig" da und der Mitarbeiter
            // vergibt die Rolle (nie raten).
            if ($rolle === 'kind' && ($verhaeltnisse[$spalte] ?? null) !== null
                && ($verhaeltnisse[$spalte] ?? null) !== 'leibl. Kind') {
                $rolle = 'angehoerig';
            }

            $out[] = array_filter([
                'first_name' => $vor,
                'last_name' => $nach,
                'birth_date' => $this->germanDate($geburtstage[$spalte] ?? null),
                'birth_place' => $geburtsorte[$index] ?? null,
                'nationality' => $staaten[$index] ?? null,
                'gender' => $geschlechter[$spalte] ?? null,
                'relation' => $rolle,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return $this->validatedPersons($out);
    }

    /**
     * Geschlecht je Spalte aus der Kaestchen-Zeile
     * "<Rolle> [ ] w [ ] m [ ] x [ ] d".
     *
     * Ein Kreuz ersetzt in der Textebene das leere Kaestchen und steht
     * unmittelbar VOR seinem Buchstaben. Uebernommen wird nur, wenn in der
     * Spalte GENAU EIN solches Kreuz steht: die Zeile kommt je nach
     * Zeichensatz zerlegt an, und ein geratenes Geschlecht erzeugt eine
     * falsche Anrede im Schreiben. "x" (unbestimmt) und "d" (divers) haben in
     * der Kundenakte keinen Wert und bleiben deshalb leer.
     *
     * @param  list<int>  $spalten
     * @return array<int,string>
     */
    private function faveGeschlechter(array $spalten, int $von, int $bis): array
    {
        $treffer = [];
        $ende = $this->faveLabelZeile('Familienversicherung wird beantragt ab', $von, $bis) ?? $bis;
        for ($i = $von; $i < $ende; $i++) {
            foreach ($this->zellenDerZeile($this->lines[$i] ?? '') as $zelle) {
                $spalte = $this->faveNaechsteSpalte($zelle['spalte'], $spalten);
                if ($spalte === null) {
                    continue;
                }
                if (! preg_match_all('/(?<!\p{L})X\h?([wmxd])(?!\p{L})/u', $zelle['text'], $mm)) {
                    continue;
                }
                foreach ($mm[1] as $buchstabe) {
                    $treffer[$spalte][] = mb_strtolower($buchstabe);
                }
            }
        }

        $out = [];
        foreach ($treffer as $spalte => $liste) {
            if (count($liste) !== 1) {
                continue;
            }
            if ($liste[0] === 'w') {
                $out[$spalte] = 'female';
            } elseif ($liste[0] === 'm') {
                $out[$spalte] = 'male';
            }
        }

        return $out;
    }

    /**
     * Verwandtschaftsverhaeltnis je Spalte (leibl. Kind / Stiefkind / Enkel /
     * Pflegekind). Mehrere Kreuze in einer Spalte heissen "unklar" -> null.
     *
     * @param  list<int>  $spalten
     * @return array<int,?string>
     */
    private function faveVerhaeltnisse(array $spalten): array
    {
        $i = $this->faveLabelZeile('Verwandtschaftsverh', 0, count($this->lines));
        if ($i === null) {
            return [];
        }

        $treffer = [];
        // Der Block laeuft ueber mehrere Zeilen (Kaestchen und Beschriftungen
        // stehen versetzt); er endet an der naechsten eigenen Beschriftung.
        for ($j = $i; $j < min($i + 10, count($this->lines)); $j++) {
            $line = $this->lines[$j];
            if ($j > $i && preg_match('/^\S.*(Ehe-\/Lebenspartner des Mitglieds|Angaben zur Vorversicherung)/u', $line)) {
                break;
            }
            foreach ($this->zellenDerZeile($line) as $zelle) {
                $spalte = $this->faveNaechsteSpalte($zelle['spalte'], $spalten);
                if ($spalte === null) {
                    continue;
                }
                if (preg_match('/(?<!\p{L})X\h*(leibl\.\s*Kind|Stiefkind|Enkel|Pflegekind)/u', $zelle['text'], $m)) {
                    $treffer[$spalte][] = (string) preg_replace('/\s+/', ' ', $m[1]);
                }
            }
        }

        $out = [];
        foreach ($treffer as $spalte => $liste) {
            $liste = array_values(array_unique($liste));
            $out[$spalte] = count($liste) === 1 ? $liste[0] : null;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function faveInsurance(): array
    {
        [$von, $bis] = $this->faveAbschnitt();
        $raw = [
            'sparte' => 'krankenversicherung',
            // Der Vordruck ist ein ANTRAG - er traegt keine Vertragsnummer.
            'document_stage' => 'antrag',
            'insurer' => self::FAVE_KASSE,
        ];

        // Beginn = frueheste beantragte Familienversicherung.
        $spalten = $this->faveSpalten();
        $daten = $this->faveZeileNachSpalten('Familienversicherung wird beantragt ab', $spalten, $von, $bis);
        $iso = array_values(array_filter(array_map(fn ($d) => $this->germanDate($d), $daten)));
        if ($iso !== []) {
            sort($iso);
            $raw['start_date'] = $iso[0];
        }

        $vorkasse = $this->faveVorkasse();
        if ($vorkasse !== null) {
            $raw['previous_insurer'] = $vorkasse;
        }

        // $raw traegt hier ausschliesslich Zeichenketten - eine
        // null-Pruefung waere toter Code.
        return $this->validatedInsurance(array_filter($raw, fn ($v) => $v !== ''));
    }

    /** @return array<string,mixed> */
    private function faveHealth(): array
    {
        $raw = [
            'health_insurance_company' => self::FAVE_KASSE,
            'health_insurance_type' => 'gesetzlich',
        ];
        // Die KVNR steht im Kopf neben dem Geburtsdatum.
        if (preg_match('/\bKVNR\b\s*([A-Z]\d{9})\b/u', $this->text(), $m)) {
            $raw['health_insurance_number'] = $m[1];
        }
        $vorkasse = $this->faveVorkasse();
        if ($vorkasse !== null) {
            $raw['previous_insurer'] = $vorkasse;
        }
        return $this->validatedHealth(array_filter($raw, fn ($v) => $v !== ''));
    }

    /** Bisherige Kasse der Angehoerigen (im Vordruck je Spalte, meist gleich). */
    private function faveVorkasse(): ?string
    {
        $i = $this->faveLabelZeile('bestand bei (Name der Krankenkasse)', 0, count($this->lines));
        if ($i === null) {
            return null;
        }
        // Der Wert steht UEBER der Beschriftung, spaltenweise.
        for ($j = $i - 1; $j >= max(0, $i - 3); $j--) {
            $zellen = $this->zellenDerZeile($this->lines[$j]);
            $werte = [];
            foreach ($zellen as $zelle) {
                if ($zelle['spalte'] >= 20 && preg_match('/^[\p{L}][\p{L}\d\s.\-]{1,60}$/u', $zelle['text'])) {
                    $werte[] = trim($zelle['text']);
                }
            }
            $werte = array_values(array_unique($werte));
            if (count($werte) === 1) {
                return $werte[0];
            }
            if ($werte !== []) {
                // Mehrere verschiedene Kassen -> keine EINE Vorkasse.
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $mitglied
     * @param  list<array<string,mixed>>  $personen
     */
    private function faveSummary(array $mitglied, array $personen, int $partner, int $kinder): string
    {
        $name = trim(($mitglied['first_name'] ?? '').' '.($mitglied['last_name'] ?? ''));
        $teile = ['Antrag auf beitragsfreie Familienversicherung (KKH, Vordruck 0765)'];
        if ($name !== '') {
            $teile[] = 'Mitglied: '.$name;
        }
        $namen = [];
        foreach ($personen as $p) {
            $rolle = match ($p['relation'] ?? null) {
                'ehepartner' => 'Ehe-/Lebenspartner',
                'kind' => 'Kind',
                default => 'Angehoeriger',
            };
            $namen[] = $rolle.' '.trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? ''));
        }
        if ($namen !== []) {
            $teile[] = 'Mitzuversichern: '.implode(', ', $namen);
        }
        $teile[] = $partner.' Ehe-/Lebenspartner, '.$kinder.' Kind(er)';
        $ohneGeschlecht = count(array_filter($personen, fn ($p) => ! isset($p['gender'])));
        if ($ohneGeschlecht > 0) {
            $teile[] = 'Geschlecht bei '.$ohneGeschlecht.' Person(en) nicht eindeutig angekreuzt - bitte pruefen';
        }
        $teile[] = 'Antrag - noch keine Vertragsnummer';
        $teile[] = 'gratis aus dem Formular gelesen (ohne KI)';

        return implode('. ', $teile).'.';
    }

    // --- Hilfsmittel fuer den FAVE-Vordruck -------------------------------

    /**
     * Grenzen des Abschnitts "Allgemeine Angaben zu Familienangehoerigen".
     * Ausserhalb liegen Mitglied und Partner-Block, die eigene Beschriftungen
     * "Name, Vorname" tragen - wer sie mitliest, nimmt den Partner als Kind.
     *
     * @return array{0:int,1:int}
     */
    private function faveAbschnitt(): array
    {
        $von = $this->faveLabelZeile('Allgemeine Angaben zu Familienangeh', 0, count($this->lines)) ?? 0;
        $bis = $this->faveLabelZeile('Angaben zur Vorversicherung', $von, count($this->lines)) ?? count($this->lines);

        return [$von, $bis];
    }

    private function faveLabelZeile(string $label, int $von, int $bis): ?int
    {
        for ($i = max(0, $von); $i < min($bis, count($this->lines)); $i++) {
            if (mb_stripos($this->lines[$i], $label) !== false) {
                return $i;
            }
        }

        return null;
    }

    /** Wert hinter einer Beschriftung auf DERSELBEN Zeile. */
    private function faveWert(string $pattern): ?string
    {
        foreach ($this->lines as $line) {
            if (! preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $rest = trim(substr($line, (int) $m[0][1] + strlen($m[0][0])));
            // Weitere Beschriftungen derselben Zeile abschneiden.
            $rest = (string) preg_replace('/\s{2,}(KVNR|ggf\. abweichende Anschrift).*$/u', '', $rest);
            $rest = trim((string) preg_replace('/\s{2,}.*$/u', '', $rest));
            if ($rest !== '') {
                return $rest;
            }
        }

        return null;
    }

    /**
     * Angekreuzte Auswahl einer Zeile ("X verheiratet"). Genau ein Kreuz,
     * sonst null - zwei Kreuze sind ein Lesefehler, nicht eine Angabe.
     *
     * @param  list<string>  $werte
     */
    private function faveAngekreuzt(string $pattern, array $werte): ?string
    {
        foreach ($this->lines as $line) {
            if (! preg_match($pattern, $line)) {
                continue;
            }
            $gefunden = [];
            foreach ($werte as $wert) {
                if (preg_match('/(?<!\p{L})X\h*'.preg_quote($wert, '/').'(?!\p{L})/ui', $line)) {
                    $gefunden[] = $wert;
                }
            }
            if (count($gefunden) === 1) {
                return $gefunden[0];
            }
        }

        return null;
    }

    /**
     * Werte einer Tabellenzeile, nach Spalte zugeordnet.
     *
     * @param  list<int>  $spalten
     * @return array<int,string>
     */
    private function faveZeileNachSpalten(string $label, array $spalten, int $von, int $bis, bool $wertDarueber = false): array
    {
        $i = $this->faveLabelZeile($label, $von, $bis);
        if ($i === null) {
            return [];
        }
        $quelle = $i;
        if ($wertDarueber) {
            // Werte stehen UEBER der Beschriftung (so setzt der Vordruck
            // Geburtsdatum, Geburtsort und Staatsangehoerigkeit).
            $quelle = null;
            for ($j = $i - 1; $j >= max(0, $i - 2); $j--) {
                if (trim($this->lines[$j]) !== '') {
                    $quelle = $j;
                    break;
                }
            }
            if ($quelle === null) {
                return [];
            }
        }

        $out = [];
        foreach ($this->zellenDerZeile($this->lines[$quelle]) as $zelle) {
            if ($zelle['spalte'] < 20) {
                continue;
            }
            $spalte = $this->faveNaechsteSpalte($zelle['spalte'], $spalten);
            if ($spalte === null || isset($out[$spalte])) {
                continue;
            }
            $text = trim($zelle['text']);
            // Leere Kaestchen und Rest-Beschriftungen sind keine Werte.
            $text = trim((string) preg_replace('/[\x{F0A0}-\x{F0FF}]/u', '', $text));
            if ($text === '' || mb_strlen($text) > 60) {
                continue;
            }
            $out[$spalte] = $text;
        }

        return $out;
    }

    /**
     * Spaltenpositionen zusammenfassen.
     *
     * Die drei Spalten des Vordrucks liegen rund 40 Zeichen auseinander,
     * waehrend ein Wert innerhalb SEINER Spalte bis zu acht Zeichen von der
     * Beschriftung abweicht (gemessen: Nachname/Vorname bei 94, das Datum
     * derselben Spalte bei 101). Getrennt wird daher erst ab 20 Zeichen -
     * weniger als eine halbe Spaltenbreite. Mit einer engeren Toleranz
     * entstand aus der zweiten Spalte eine vierte, und die Reihenfolge der
     * Spalten stimmte nicht mehr mit der Tabelle auf Seite 2 ueberein.
     *
     * @param  list<int>  $positionen
     * @return list<int>
     */
    private function faveGruppiere(array $positionen): array
    {
        sort($positionen);
        $out = [];
        foreach ($positionen as $position) {
            if ($out !== [] && $position - $out[count($out) - 1] <= 20) {
                continue;
            }
            $out[] = $position;
        }

        return $out;
    }

    /**
     * Spalte, zu der eine Zelle gehoert: die naechstgelegene links von ihr
     * bzw. innerhalb der Toleranz. Weiter als eine halbe Spaltenbreite
     * entfernt -> keine Zuordnung (nie raten).
     *
     * @param  list<int>  $spalten
     */
    private function faveNaechsteSpalte(int $spalte, array $spalten): ?int
    {
        $treffer = null;
        $abstand = PHP_INT_MAX;
        foreach ($spalten as $position) {
            // Eine Zelle darf rechts von ihrer Spalte beginnen (Kaestchen),
            // aber nur knapp links davon.
            $delta = $spalte - $position;
            // Knapp links der Spalte ist noch dieselbe Spalte; weiter rechts
            // als 30 Zeichen (Spaltenabstand ~40) gehoert die Zelle zur
            // naechsten - dann wird nicht zugeordnet.
            if ($delta < -8) {
                continue;
            }
            if (abs($delta) < $abstand) {
                $abstand = abs($delta);
                $treffer = $position;
            }
        }

        return $abstand <= 30 ? $treffer : null;
    }

    /**
     * Werte einer Tabellenzeile der REIHENFOLGE nach (links nach rechts).
     *
     * Fuer die Tabelle auf Seite 2, die ihr eigenes Spaltenraster hat. Die
     * Reihenfolge der Spalten ist in beiden Tabellen dieselbe (fester
     * Vordruck), die Positionen sind es nicht. Zugeordnet wird nur bei
     * GENAU passender Anzahl - sonst waere die Zuordnung eine Vermutung, und
     * ein Geburtsort am falschen Kind ist schlimmer als ein leeres Feld.
     *
     * @return list<string>
     */
    private function faveZeileNachReihenfolge(string $label, int $anzahl): array
    {
        if ($anzahl < 1) {
            return [];
        }
        $i = $this->faveLabelZeile($label, 0, count($this->lines));
        if ($i === null) {
            return [];
        }

        // Der Wert steht UEBER seiner Beschriftung.
        for ($j = $i - 1; $j >= max(0, $i - 2); $j--) {
            if (trim($this->lines[$j]) === '') {
                continue;
            }
            $werte = [];
            foreach ($this->zellenDerZeile($this->lines[$j]) as $zelle) {
                if ($zelle['spalte'] < 20) {
                    continue;
                }
                $text = trim((string) preg_replace('/[\x{F0A0}-\x{F0FF}]/u', '', $zelle['text']));
                if ($text === '' || mb_strlen($text) > 60) {
                    continue;
                }
                $werte[] = $text;
            }

            return count($werte) === $anzahl ? $werte : [];
        }

        return [];
    }
}
