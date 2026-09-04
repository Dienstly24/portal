<?php

namespace App\Support;

/**
 * Erkennungssicherheit JE FELD einer Dokumentanalyse (Betreiber-Vorgabe
 * 28.08.2026).
 *
 * WARUM: Bisher gab es nur EINE Konfidenz fuer das ganze Dokument und den
 * Hinweis "Einige Angaben konnten nicht sicher gelesen werden" mit einer
 * festen Liste von vier Standardfeldern. Der Mitarbeiter musste daraufhin
 * ALLES kontrollieren - also genau das, was die Automatik ersparen sollte.
 * Ein Auftrag mit 20 gelesenen Feldern, von denen eines wackelt, sieht damit
 * aus wie ein Auftrag, bei dem nichts stimmt.
 *
 * Deshalb traegt das Ergebnis jetzt je Feld einen von vier Zustaenden. Sie
 * sind bewusst grob - eine Prozentzahl je Feld waere eine Genauigkeit, die
 * eine Regel-Erkennung gar nicht hat:
 *
 *   sicher       Der Wert stand beschriftet da und hat jede Pruefung bestanden
 *                (z.B. IBAN mit gueltiger Pruefziffer).
 *   pruefen      Der Wert wurde uebernommen, aber es musste etwas repariert,
 *                abgeleitet oder geraten-nahe entschieden werden.
 *   fehlt        Im Dokument nicht gefunden. KEIN Wert - es wird nichts geraten.
 *   widerspruch  Zwei Angaben im selben Dokument sagen Verschiedenes. Dann
 *                wird NICHTS uebernommen: eine falsche Bankverbindung ist
 *                teurer als eine fehlende.
 *
 * Die Schluessel sind "gruppe.feld" (z.B. "person.email", "bank.iban") und
 * spiegeln damit exakt den Aufbau von `data` - die Oberflaeche kann jeden
 * Status ohne Uebersetzungstabelle an sein Feld haengen.
 */
class FieldRecognition
{
    public const SICHER = 'sicher';
    public const PRUEFEN = 'pruefen';
    public const FEHLT = 'fehlt';
    public const WIDERSPRUCH = 'widerspruch';

    /** Schluessel im Analyse-Ergebnis (`data`), unter dem die Status stehen. */
    public const KEY = 'feldstatus';

    /** Klartext je Zustand - eine Quelle fuer Oberflaeche und Zusammenfassung. */
    public const LABELS = [
        self::SICHER => 'sicher erkannt',
        self::PRUEFEN => 'erkannt - bitte pruefen',
        self::FEHLT => 'nicht erkannt',
        self::WIDERSPRUCH => 'widerspruechliche Angaben',
    ];

    /**
     * Von "harmlos" nach "handlungsbeduerftig". Stehen an einem Anzeige-Block
     * mehrere Felder, gewinnt der schlechteste Zustand - sonst verdeckt ein
     * sicher gelesener Ort die unlesbare Hausnummer daneben.
     *
     * @var array<string,int>
     */
    private const RANG = [
        self::SICHER => 0,
        self::FEHLT => 1,
        self::PRUEFEN => 2,
        self::WIDERSPRUCH => 3,
    ];

    /** @var array<string,array{status:string,hinweis:?string}> */
    private array $felder = [];

    public function set(string $feld, string $status, ?string $hinweis = null): void
    {
        if (! isset(self::RANG[$status])) {
            return;
        }
        // Ein schlechterer Zustand ueberschreibt einen besseren, nie umgekehrt:
        // wer einmal "bitte pruefen" gemeldet hat, darf nicht durch einen
        // spaeteren Pauschal-Durchlauf auf "sicher" zurueckgesetzt werden.
        $bisher = $this->felder[$feld]['status'] ?? null;
        if ($bisher !== null && self::RANG[$bisher] >= self::RANG[$status]) {
            return;
        }
        $this->felder[$feld] = ['status' => $status, 'hinweis' => $hinweis];
    }

    public function sicher(string $feld): void
    {
        $this->set($feld, self::SICHER);
    }

    public function pruefen(string $feld, string $hinweis): void
    {
        $this->set($feld, self::PRUEFEN, $hinweis);
    }

    public function fehlt(string $feld, ?string $hinweis = null): void
    {
        $this->set($feld, self::FEHLT, $hinweis);
    }

    public function widerspruch(string $feld, string $hinweis): void
    {
        $this->set($feld, self::WIDERSPRUCH, $hinweis);
    }

    /**
     * Bilanz einer ganzen Gruppe: was da ist, ist "sicher erkannt", was in
     * `$erwartet` fehlt, ist "nicht erkannt". Bereits gesetzte (schlechtere)
     * Zustaende bleiben unangetastet - deshalb darf dieser Aufruf am Ende
     * stehen, ohne die Feinarbeit davor zu ueberschreiben.
     *
     * @param array<string,mixed> $werte
     * @param list<string>        $erwartet
     */
    public function gruppe(string $gruppe, array $werte, array $erwartet): void
    {
        foreach ($werte as $feld => $wert) {
            if ($wert !== null && $wert !== '' && $wert !== []) {
                $this->sicher($gruppe.'.'.$feld);
            }
        }
        foreach ($erwartet as $feld) {
            if (($werte[$feld] ?? null) === null || $werte[$feld] === '') {
                $this->fehlt($gruppe.'.'.$feld);
            }
        }
    }

    /** @return array<string,array{status:string,hinweis:?string}> */
    public function toArray(): array
    {
        return $this->felder;
    }

    /** Felder, die der Mitarbeiter anschauen muss (nicht "sicher"). */
    public function toCheck(): array
    {
        return array_filter($this->felder, fn (array $f) => $f['status'] !== self::SICHER);
    }

    /**
     * Schlechtester Zustand einer Feldmenge - fuer einen Anzeige-Block, der
     * mehrere Felder buendelt ("Adresse" = Strasse + Nr. + PLZ + Ort).
     *
     * @param array<string,array{status:string,hinweis:?string}> $felder
     * @param list<string>                                       $schluessel
     */
    public static function schlechtester(array $felder, array $schluessel): ?string
    {
        $out = null;
        foreach ($schluessel as $key) {
            $status = $felder[$key]['status'] ?? null;
            if ($status === null || ! isset(self::RANG[$status])) {
                continue;
            }
            if ($out === null || self::RANG[$status] > self::RANG[$out]) {
                $out = $status;
            }
        }
        return $out;
    }
}
