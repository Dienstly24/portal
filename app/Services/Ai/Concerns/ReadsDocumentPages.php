<?php
namespace App\Services\Ai\Concerns;

/**
 * Seitenweiser Zugriff auf die Textebene eines PDF - pdftotext trennt die
 * Seiten mit einem Form-Feed ("\f").
 *
 * Hintergrund (Betreiber-Vorgabe 30.07.2026): Ein AUFTRAG/ANTRAG traegt seine
 * Daten vollstaendig auf der ERSTEN Seite; danach folgen nur noch AGB,
 * Widerrufsbelehrung und Datenschutzhinweise. Dieser Rechtstext enthaelt
 * regelmaessig Woerter, die anderswo als Erkennungs- oder Ausschlussmerkmal
 * dienen - der 13-seitige LichtBlick-Auftrag nennt im Datenschutzhinweis
 * (Seite 12) das Wort "Beratungsprotokolle" und liess damit den Parser des
 * Auftrags verstummen. Wer das ganze Dokument durchsucht, liest Rauschen
 * statt Daten.
 *
 * ACHTUNG - die Regel gilt nur fuer FORMULARE (Auftrag/Antrag). Eine
 * Vertragsbestaetigung oder Police verteilt ihre Angaben bewusst ueber
 * mehrere Seiten (EWE-Bestaetigung: Preise und Zaehlerdaten stehen erst auf
 * den Folgeseiten) - solche Dokumente werden weiterhin komplett gelesen.
 */
trait ReadsDocumentPages
{
    /**
     * Eine erste Seite mit weniger Zeichen ist ein Deckblatt/Trennblatt und
     * kein Formular - dann lieber den vollen Text lesen als gar nichts.
     */
    private const FIRST_PAGE_MIN_CHARS = 200;

    /**
     * Erste Seite des Dokuments. Ohne Seitentrennung (Bild/OCR-Text) oder bei
     * einer praktisch leeren ersten Seite bleibt es beim vollen Text.
     */
    protected function firstPage(string $text): string
    {
        $pages = explode("\f", $text);
        if (count($pages) < 2) {
            return $text;
        }
        $first = rtrim($pages[0]);

        return mb_strlen(trim($first)) < self::FIRST_PAGE_MIN_CHARS ? $text : $first;
    }

    /**
     * Ist das Dokument ein FREMDES Vergleichs-/Beratungsprotokoll (CHECK24)?
     * Solche Angebote nennen zwar Versicherer, Tarife und Fahrzeugdaten, sind
     * aber kein Vertrag - die Vertrags-Parser muessen sie in Ruhe lassen.
     *
     * Zwei Merkmale mit unterschiedlicher Reichweite:
     * - "CHECK24" ist eine Marke und taucht in fremdem Rechtstext nicht auf -
     *   sie zaehlt an jeder Stelle des Dokuments.
     * - "Beratungsprotokoll" ist ein gewoehnliches Fachwort und steht auch in
     *   den Datenschutzhinweisen fremder AGB. Es zaehlt daher nur auf der
     *   ERSTEN Seite: dort nennt sich ein Protokoll selbst.
     */
    protected function looksLikeComparisonProtocol(string $text): bool
    {
        if (str_contains(mb_strtoupper($text), 'CHECK24')) {
            return true;
        }

        return str_contains(mb_strtoupper($this->firstPage($text)), 'BERATUNGSPROTOKOLL');
    }
}
