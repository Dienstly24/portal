<?php

namespace App\Services\Ai\Training;

use Illuminate\Support\Carbon;

/**
 * Liest die Datei aus "Chat exportieren" der WhatsApp-App.
 *
 * WARUM DIESER WEG: der bisherige Verlauf-Import laeuft ueber das
 * `history`-Ereignis von Meta und setzt damit die Coexistence-Anbindung
 * voraus. Der Betreiber hat die Gespraeche aber HEUTE auf seinem Telefon
 * und will sie heute einlesen - ein Knopf im Telefon, eine Datei, fertig.
 *
 * DIE DATEI IST UNZUVERLAESSIGER, ALS SIE AUSSIEHT. Sie sieht aus wie
 * ein simples "Datum - Absender: Text", und genau daran scheitert jeder
 * naive Ausdruck. Was wirklich drinsteht (alles hier als Test
 * festgehalten):
 *
 *  1. ZWEI Zeilenformate: `[17.09.26, 14:03:22] Name: Text` (iOS) und
 *     `17.09.26, 14:03 - Name: Text` (Android).
 *  2. UNSICHTBARE ZEICHEN. iOS schreibt vor jede Zeile ein
 *     Links-nach-rechts-Zeichen (U+200E) und vor AM/PM ein schmales
 *     geschuetztes Leerzeichen (U+202F). Beide sind im Editor nicht zu
 *     sehen und lassen jeden Ausdruck ins Leere laufen - die Datei
 *     "funktioniert einfach nicht", ohne dass man saehe, warum.
 *  3. MEHRZEILIGE Nachrichten: Folgezeilen tragen KEINEN Kopf. Sie
 *     gehoeren zur vorherigen Nachricht und duerfen nie eine eigene
 *     werden.
 *  4. SYSTEMZEILEN ohne Absender ("Nachrichten und Anrufe sind
 *     Ende-zu-Ende-verschluesselt", "... hat die Gruppenbeschreibung
 *     geaendert"). Sie haben keinen Menschen als Urheber und gehoeren
 *     nicht in einen Kundenverlauf.
 *  5. MEDIEN SIND NICHT DABEI ("<Medien ausgeschlossen>"). Der Text
 *     bleibt, die Datei fehlt - das muss die Vorschau ehrlich sagen,
 *     sonst sucht ein Mitarbeiter spaeter ein Bild, das es nie gab.
 *
 * WER WIR SIND, WIRD NIE GERATEN. Der Export nennt nur Anzeigenamen;
 * welcher davon der Betrieb ist, steht nicht darin. Der Parser zaehlt
 * die Absender und legt sie dem Menschen vor - dieselbe Haltung wie
 * ueberall: lieber eine Frage als eine Vermutung.
 */
class WhatsAppExportParser
{
    /** Zeichen, die WhatsApp einstreut und die kein Mensch sieht. */
    private const UNSICHTBAR = ["\u{200E}", "\u{200F}", "\u{FEFF}", "\u{00A0}", "\u{202F}"];

    /**
     * Zeilen, die KEIN Mensch geschrieben hat.
     *
     * Bewusst eine Liste von Textbausteinen und kein Ausdruck auf "kein
     * Doppelpunkt": ein Kunde schreibt durchaus eine Zeile ohne
     * Doppelpunkt, und eine Systemzeile enthaelt durchaus einen.
     */
    private const SYSTEMTEXTE = [
        'ende-zu-ende-verschlüsselt',
        'end-to-end encrypted',
        'sicherheitsnummer',
        'security code changed',
        'hat die gruppe erstellt',
        'created group',
        'hat dich hinzugefügt',
        'added you',
        'verpasster anruf',
        'missed voice call',
        'missed video call',
        'diese nachricht wurde gelöscht',
        'this message was deleted',
        'nachricht gelöscht',
    ];

    /** Hinweise darauf, dass eine Datei im Export FEHLT. */
    private const MEDIENHINWEISE = [
        'medien ausgeschlossen',
        'media omitted',
        'bild weggelassen',
        'image omitted',
        'audio weggelassen',
        'video weggelassen',
        'dokument weggelassen',
        'sticker weggelassen',
        'gif weggelassen',
    ];

    /**
     * Die Datei in Nachrichten zerlegen.
     *
     * @return array{
     *   messages: array<int, array{row:int,sent_at:?string,sender:string,body:string,has_media_note:bool}>,
     *   senders: array<string,int>,
     *   system_lines: int,
     *   media_notes: int,
     *   unparsed_lines: int,
     *   first_at: ?string,
     *   last_at: ?string,
     * }
     */
    public function parse(string $inhalt): array
    {
        $nachrichten = [];
        $absender = [];
        $systemzeilen = 0;
        $medienhinweise = 0;
        $unlesbar = 0;
        $zeilenNr = 0;

        foreach (preg_split("/\r\n|\n|\r/", $inhalt) ?: [] as $rohzeile) {
            $zeilenNr++;
            $zeile = $this->saubereZeile($rohzeile);

            if ($zeile === '') {
                continue;
            }

            $kopf = $this->kopfZerlegen($zeile);

            // KEIN Kopf = Fortsetzung der vorherigen Nachricht. Sie zu
            // einer eigenen zu machen zerlegt jeden mehrzeiligen Text in
            // Bruchstuecke ohne Absender.
            if (! $kopf) {
                if ($nachrichten !== []) {
                    $letzte = count($nachrichten) - 1;
                    $nachrichten[$letzte]['body'] .= "\n".$zeile;

                    continue;
                }

                $unlesbar++;

                continue;
            }

            [$zeitpunkt, $rest] = $kopf;

            // Ein Absender endet am ERSTEN Doppelpunkt. Fehlt er, ist es
            // eine Systemzeile - dort steht nur ein Satz.
            $trenner = mb_strpos($rest, ': ');
            if ($trenner === false) {
                $systemzeilen++;

                continue;
            }

            $name = trim(mb_substr($rest, 0, $trenner));
            $text = trim(mb_substr($rest, $trenner + 2));

            if ($name === '' || $this->istSystemtext($text)) {
                $systemzeilen++;

                continue;
            }

            $medien = $this->istMedienhinweis($text);
            if ($medien) {
                $medienhinweise++;
            }

            $absender[$name] = ($absender[$name] ?? 0) + 1;

            $nachrichten[] = [
                'row' => $zeilenNr,
                'sent_at' => $zeitpunkt?->toDateTimeString(),
                'sender' => $name,
                'body' => $text,
                'has_media_note' => $medien,
            ];
        }

        $zeiten = array_values(array_filter(array_column($nachrichten, 'sent_at')));

        // Nach HAEUFIGKEIT sortiert: in der Vorschau steht der Absender
        // mit den meisten Nachrichten oben, und das ist fast immer einer
        // der beiden Gespraechspartner - Gruppen-Mitleser stehen unten.
        arsort($absender);

        return [
            'messages' => $nachrichten,
            'senders' => $absender,
            'system_lines' => $systemzeilen,
            'media_notes' => $medienhinweise,
            'unparsed_lines' => $unlesbar,
            'first_at' => $zeiten[0] ?? null,
            'last_at' => $zeiten === [] ? null : end($zeiten),
        ];
    }

    /**
     * Unsichtbare Zeichen entfernen.
     *
     * OHNE DIESEN SCHRITT FINDET KEIN AUSDRUCK ETWAS. iOS setzt vor jede
     * Zeile ein U+200E; die Datei sieht im Editor vollkommen normal aus
     * und liefert trotzdem null Nachrichten. Das schmale geschuetzte
     * Leerzeichen (U+202F) vor AM/PM ist derselbe Fall.
     */
    private function saubereZeile(string $zeile): string
    {
        return trim(str_replace(self::UNSICHTBAR, ['', '', '', ' ', ' '], $zeile));
    }

    /**
     * Den Kopf einer Zeile lesen: Zeitpunkt + der Rest.
     *
     * @return array{0:?Carbon,1:string}|null null = die Zeile hat keinen Kopf
     */
    private function kopfZerlegen(string $zeile): ?array
    {
        // iOS: [17.09.26, 14:03:22] Name: Text
        if (preg_match('/^\[([^\]]{6,40})\]\s*(.*)$/u', $zeile, $m)) {
            return [$this->zeitpunkt($m[1]), trim($m[2])];
        }

        // Android: 17.09.26, 14:03 - Name: Text
        // Der Bindestrich-Trenner ist erst NACH einer Uhrzeit gueltig -
        // sonst wuerde jede Zeile mit einem Gedankenstrich zum Kopf.
        if (preg_match('/^(\d{1,4}[.\/-]\d{1,2}[.\/-]\d{2,4},?\s+\d{1,2}:\d{2}(?::\d{2})?(?:\s*[APap]\.?[Mm]\.?)?)\s+-\s+(.*)$/u', $zeile, $m)) {
            return [$this->zeitpunkt($m[1]), trim($m[2])];
        }

        return null;
    }

    /**
     * Datum und Uhrzeit deuten.
     *
     * TAG VOR MONAT: die deutsche und die britische Schreibweise
     * beginnen mit dem Tag, die amerikanische mit dem Monat - aus
     * "03.09.26" allein ist nicht zu entscheiden, welche gilt. Der
     * Betrieb sitzt in Deutschland, deshalb gilt hier Tag zuerst. Wo die
     * Deutung scheitert, wird NICHTS geraten: die Nachricht behaelt
     * ihren Text und verliert nur den Zeitstempel (dann steht sie in der
     * Reihenfolge der Datei).
     */
    private function zeitpunkt(string $roh): ?Carbon
    {
        $roh = trim(str_replace(['[', ']'], '', $roh));

        foreach (['d.m.y, H:i:s', 'd.m.Y, H:i:s', 'd.m.y, H:i', 'd.m.Y, H:i',
            'd/m/y, H:i:s', 'd/m/Y, H:i:s', 'd/m/y, H:i', 'd/m/Y, H:i',
            'd.m.y, h:i:s A', 'd.m.Y, h:i:s A', 'd.m.y, h:i A', 'd.m.Y, h:i A',
            'd/m/y, h:i A', 'd/m/Y, h:i A', 'Y-m-d, H:i:s', 'Y-m-d, H:i'] as $format) {
            try {
                $wert = Carbon::createFromFormat($format, $roh);
            } catch (\Throwable) {
                continue;
            }

            // Ein Zeitpunkt in der ZUKUNFT kann kein Verlauf sein - dann
            // wurde das Format falsch gedeutet (Monat als Tag gelesen).
            if ($wert && ! $wert->isFuture()) {
                return $wert;
            }
        }

        return null;
    }

    private function istSystemtext(string $text): bool
    {
        $klein = mb_strtolower($text);

        foreach (self::SYSTEMTEXTE as $baustein) {
            if (str_contains($klein, $baustein)) {
                return true;
            }
        }

        return false;
    }

    private function istMedienhinweis(string $text): bool
    {
        $klein = mb_strtolower($text);

        foreach (self::MEDIENHINWEISE as $baustein) {
            if (str_contains($klein, $baustein)) {
                return true;
            }
        }

        return false;
    }
}
