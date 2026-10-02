<?php

namespace App\Services\Ai\Concerns;

/**
 * Spaltenweises Lesen eines Brief-Layouts.
 *
 * WARUM ES DAS GIBT: "auf Spaltenabstaende ist kein Verlass" ist in diesem
 * Projekt bereits viermal dieselbe Ursache gewesen (Gruenwelt-Briefkopf,
 * eAT-Rueckseite, Entgeltabrechnung, Energieportal). Im Empfaengerblock eines
 * Geschaeftsbriefs steht RECHTS auf DERSELBEN Zeile die Service-Spalte des
 * Absenders; wo der Empfaengerblock eine Zeile auslaesst, ist die erste Zelle
 * der Zeile ploetzlich "Telefon ..." - und wer "die erste Zelle der Zeile"
 * liest, bekommt sie als Strasse.
 *
 * Gelesen wird deshalb an der SPALTENPOSITION, und zwar in ZEICHEN, nicht in
 * Bytes: ein Umlaut weiter links verschoebe sie sonst.
 */
trait LiestSpalten
{
    /**
     * Zellen des Textes, nach Spalten gruppiert und von links nach rechts
     * sortiert. Eine Zelle endet an zwei aufeinanderfolgenden Leerzeichen.
     *
     * @param  list<string>  $zeilen
     * @return list<list<array{zeile:int,spalte:int,text:string}>>
     */
    private function spaltenAus(array $zeilen): array
    {
        $zellen = [];
        foreach ($zeilen as $i => $line) {
            if (! preg_match_all('/\S(?:.*?\S)?(?=\h{2,}|$)/u', $line, $mm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($mm[0] as $treffer) {
                $text = trim($treffer[0]);
                if ($text === '') {
                    continue;
                }
                $zellen[] = [
                    'zeile' => $i,
                    // Position in ZEICHEN, nicht in Bytes.
                    'spalte' => mb_strlen(substr($line, 0, (int) $treffer[1])),
                    'text' => $text,
                ];
            }
        }

        // Nach Spalte gruppieren, kleine Abweichungen (+/- 2 Zeichen) gelten
        // als dieselbe Spalte.
        $gruppen = [];
        foreach ($zellen as $zelle) {
            foreach ($gruppen as $position => $liste) {
                if (abs($position - $zelle['spalte']) <= 2) {
                    $gruppen[$position][] = $zelle;

                    continue 2;
                }
            }
            $gruppen[$zelle['spalte']] = [$zelle];
        }
        ksort($gruppen);

        return array_values($gruppen);
    }
}
