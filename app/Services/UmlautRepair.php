<?php

namespace App\Services;

/**
 * Repariert deutsche Texte, die ohne Umlaute erfasst wurden (P0-7):
 * "verstaendlich erklaert" -> "verständlich erklärt".
 *
 * Bewusst KONSERVATIV: keine generische ae/oe/ue-Ersetzung (die wuerde
 * Woerter wie "aktuell", "zuerst", "Dauer" oder Eigennamen zerstoeren),
 * sondern eine kuratierte Liste vollstaendiger Wortformen aus dem
 * Versicherungs-/Energie-Vokabular der Website. Gross-/Kleinschreibung
 * des ersten Buchstabens bleibt erhalten.
 */
class UmlautRepair
{
    /** Wortformen (klein geschrieben) -> korrekte Schreibweise. */
    private const WORDS = [
        // fuer/ueber & Co.
        'fuer' => 'für', 'ueber' => 'über', 'uebrigens' => 'übrigens',
        'darueber' => 'darüber', 'worueber' => 'worüber', 'gegenueber' => 'gegenüber',
        // verstaendlich erklaert
        'verstaendlich' => 'verständlich', 'verstaendliche' => 'verständliche',
        'verstaendlichen' => 'verständlichen', 'verstaendlicher' => 'verständlicher',
        'erklaert' => 'erklärt', 'erklaeren' => 'erklären', 'erklaerung' => 'erklärung',
        'erklaerungen' => 'erklärungen', 'erklaere' => 'erkläre',
        // schuetzen/Schaeden
        'schuetzt' => 'schützt', 'schuetzen' => 'schützen', 'geschuetzt' => 'geschützt',
        'schaeden' => 'schäden',
        'zufuegen' => 'zufügen', 'zufuegt' => 'zufügt',
        // Wuensche
        'gewuenschte' => 'gewünschte', 'gewuenschten' => 'gewünschten',
        'gewuenschter' => 'gewünschter', 'wuensche' => 'wünsche', 'wuenschen' => 'wünschen',
        'wunschgemaess' => 'wunschgemäß',
        // ueber-Verben
        'uebernimmt' => 'übernimmt', 'uebernehmen' => 'übernehmen',
        'uebernommen' => 'übernommen', 'uebernahme' => 'übernahme',
        'ueberschrieben' => 'überschrieben', 'ueberweisung' => 'überweisung',
        'ueberpruefen' => 'überprüfen', 'ueberprueft' => 'überprüft',
        'uebersicht' => 'übersicht', 'uebersichtlich' => 'übersichtlich',
        'uebertragen' => 'übertragen', 'uebergang' => 'übergang',
        // hoeher/groesser
        'hoeher' => 'höher', 'hoehere' => 'höhere', 'hoeheren' => 'höheren',
        'hoechste' => 'höchste', 'hoechsten' => 'höchsten',
        'groesser' => 'größer', 'groessere' => 'größere', 'groesseren' => 'größeren',
        'groesse' => 'größe', 'grösse' => 'größe',
        // aendern/staendig
        'aendern' => 'ändern', 'aendert' => 'ändert', 'aenderung' => 'änderung',
        'aenderungen' => 'änderungen', 'geaendert' => 'geändert',
        'staendig' => 'ständig', 'selbststaendig' => 'selbstständig',
        'vollstaendig' => 'vollständig', 'vollstaendige' => 'vollständige',
        'zustaendig' => 'zuständig', 'zustaendige' => 'zuständige', 'zustaendigen' => 'zuständigen',
        // pruefen/koennen/muessen
        'pruefen' => 'prüfen', 'prueft' => 'prüft', 'gepueft' => 'geprüft', 'geprueft' => 'geprüft',
        'pruefung' => 'prüfung', 'koennen' => 'können', 'koennte' => 'könnte',
        'koennten' => 'könnten', 'muessen' => 'müssen', 'muesste' => 'müsste',
        'moechten' => 'möchten', 'moechte' => 'möchte',
        // Kuendigung
        'kuendigung' => 'kündigung', 'kuendigungen' => 'kündigungen',
        'kuendigen' => 'kündigen', 'gekuendigt' => 'gekündigt', 'kuendigt' => 'kündigt',
        'kuendigungsfrist' => 'kündigungsfrist',
        // persoenlich/moeglich
        'persoenlich' => 'persönlich', 'persoenliche' => 'persönliche',
        'persoenlichen' => 'persönlichen', 'persoenlicher' => 'persönlicher',
        'moeglich' => 'möglich', 'moegliche' => 'mögliche', 'moeglichen' => 'möglichen',
        'moeglichkeit' => 'möglichkeit', 'moeglichkeiten' => 'möglichkeiten',
        'moeglichst' => 'möglichst',
        // guenstig/spaeter/waehlen
        'guenstig' => 'günstig', 'guenstige' => 'günstige', 'guenstigen' => 'günstigen',
        'guenstiger' => 'günstiger', 'guenstigere' => 'günstigere', 'guenstigeren' => 'günstigeren',
        'spaeter' => 'später', 'spaetestens' => 'spätestens',
        'waehlen' => 'wählen', 'waehlt' => 'wählt', 'gewaehlt' => 'gewählt',
        'auswaehlen' => 'auswählen', 'waehrend' => 'während',
        // unabhaengig/zusaetzlich
        'unabhaengig' => 'unabhängig', 'unabhaengige' => 'unabhängige',
        'unabhaengigen' => 'unabhängigen', 'anbieterunabhaengig' => 'anbieterunabhängig',
        'abhaengig' => 'abhängig', 'zusaetzlich' => 'zusätzlich', 'zusaetzliche' => 'zusätzliche',
        'zusaetzlichen' => 'zusätzlichen', 'grundsaetzlich' => 'grundsätzlich',
        // haeufig/regelmaessig/gemaess
        'haeufig' => 'häufig', 'haeufige' => 'häufige', 'haeufigsten' => 'häufigsten',
        'regelmaessig' => 'regelmäßig', 'regelmaessige' => 'regelmäßige',
        'gemaess' => 'gemäß', 'zuverlaessig' => 'zuverlässig', 'verlaesslich' => 'verlässlich',
        // Domaenen-Woerter
        'strasse' => 'straße', 'anschluesse' => 'anschlüsse', 'abschluesse' => 'abschlüsse',
        'buendeln' => 'bündeln', 'gebuehr' => 'gebühr', 'gebuehren' => 'gebühren',
        'zaehler' => 'zähler', 'zaehlerstand' => 'zählerstand', 'zaehlernummer' => 'zählernummer',
        'vertraege' => 'verträge', 'vertraegen' => 'verträgen', 'beitraege' => 'beiträge',
        'beitraegen' => 'beiträgen', 'antraege' => 'anträge',
        'buergen' => 'bürgen', 'buerger' => 'bürger', 'behoerde' => 'behörde',
        'behoerden' => 'behörden', 'behoerdengang' => 'behördengang',
        'gruen' => 'grün', 'gruene' => 'grüne', 'gruenen' => 'grünen',
        'oekostrom' => 'ökostrom', 'waermepumpe' => 'wärmepumpe',
        'naehe' => 'nähe', 'naeher' => 'näher', 'erhaeltlich' => 'erhältlich',
        'zahnaerzte' => 'zahnärzte', 'zahnaerztliche' => 'zahnärztliche',
        'aerzte' => 'ärzte', 'aerztliche' => 'ärztliche',
        'kostenguenstig' => 'kostengünstig', 'schluessel' => 'schlüssel',
        'fahrzeugschluessel' => 'fahrzeugschlüssel', 'tueren' => 'türen',
        'fruehestens' => 'frühestens', 'frueher' => 'früher', 'fruehzeitig' => 'frühzeitig',
        'natuerlich' => 'natürlich', 'saemtliche' => 'sämtliche',
        'ausfuellen' => 'ausfüllen', 'fuellen' => 'füllen',
        'anhaenger' => 'anhänger', 'raeder' => 'räder', 'gelaende' => 'gelände',
        'staerke' => 'stärke', 'staerken' => 'stärken', 'schwaechen' => 'schwächen',
        'qualitaet' => 'qualität', 'mobilitaet' => 'mobilität', 'flexibilitaet' => 'flexibilität',
        'zufaellig' => 'zufällig', 'unfaelle' => 'unfälle', 'faelle' => 'fälle',
        'faellig' => 'fällig', 'jaehrlich' => 'jährlich', 'jaehrliche' => 'jährliche',
        'jaehrlichen' => 'jährlichen', 'taeglich' => 'täglich', 'monatlich' => 'monatlich',
        'waehlbar' => 'wählbar', 'erwaehnt' => 'erwähnt', 'gewaehrleistet' => 'gewährleistet',
        'gewaehrleistung' => 'gewährleistung', 'waehrung' => 'währung',
        'loesung' => 'lösung', 'loesungen' => 'lösungen', 'abloesung' => 'ablösung',
        'einloesen' => 'einlösen', 'ausloesen' => 'auslösen',
        'stromstaerke' => 'stromstärke', 'staedte' => 'städte', 'staedten' => 'städten',
        'laender' => 'länder', 'laendern' => 'ländern', 'auslaendische' => 'ausländische',
        'europaeische' => 'europäische', 'europaeischen' => 'europäischen',
    ];

    /** Repariert einen Text; unbekannte Woerter bleiben unangetastet. */
    public static function fix(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return $text;
        }

        return preg_replace_callback('/\pL[\pL]*/u', function ($m) {
            $word = $m[0];
            $lower = mb_strtolower($word);
            $fixed = self::WORDS[$lower] ?? null;
            if ($fixed === null) {
                return $word;
            }
            // Gross-/Kleinschreibung des ersten Buchstabens erhalten.
            if (mb_strtoupper(mb_substr($word, 0, 1)) === mb_substr($word, 0, 1)) {
                return mb_strtoupper(mb_substr($fixed, 0, 1)).mb_substr($fixed, 1);
            }
            return $fixed;
        }, $text);
    }

    /** Verdaechtige Woerter eines Texts (fuer den Hinweis beim Speichern). */
    public static function findSuspicious(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }
        preg_match_all('/\pL[\pL]*/u', $text, $m);
        $hits = [];
        foreach ($m[0] as $word) {
            if (isset(self::WORDS[mb_strtolower($word)])) {
                $hits[$word] = true;
            }
        }
        return array_keys($hits);
    }
}
