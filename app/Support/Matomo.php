<?php

namespace App\Support;

/**
 * Die EINE Stelle, die entscheidet, ob und wohin gemessen wird.
 *
 * Warum eine Klasse und nicht zwei config()-Aufrufe in der Vorlage: die
 * Adresse aus der .env fliesst an DREI Stellen in ausgelieferten Inhalt -
 * in die Inhaltsrichtlinie (script-src/connect-src), in die Skript-Adresse
 * und in die Melde-Adresse. Ein Wert, der an drei Stellen wirkt, wird
 * einmal geprueft, nicht dreimal unterschiedlich.
 *
 * GEPRUEFT WIRD STRENG, aus demselben Grund wie bei
 * `legal_external_base` (SEC-5): ein Tippfehler in der .env wuerde sonst
 * einen fremden Host in die Skript-Freigabe schreiben - und eine
 * Skript-Freigabe ist der Weg, ueber den eingeschleuster Code laeuft.
 * Erlaubt ist deshalb ausschliesslich https, ohne Zugangsdaten, ohne
 * Abfrage, ohne Fragment. Alles andere heisst: Messung bleibt AUS.
 * Lieber keine Zahlen als ein fremder Skript-Host.
 */
class Matomo
{
    /**
     * Die geprüfte Konfiguration oder null.
     *
     * @return array{url: string, site_id: string, origin: string}|null
     */
    public static function konfiguration(): ?array
    {
        $roh = trim((string) config('analytics.matomo.url'));
        $seite = trim((string) config('analytics.matomo.site_id'));

        // Beides oder nichts: eine Adresse ohne Seitennummer meldet an
        // keine Website, eine Nummer ohne Adresse an keinen Server.
        if ($roh === '' || $seite === '') {
            return null;
        }

        // Die Seitennummer ist eine Zahl. Alles andere waere ein
        // Fremdwert in einer JavaScript-Zeichenkette.
        if (! preg_match('/^[1-9][0-9]{0,6}$/', $seite)) {
            return null;
        }

        $teile = parse_url(rtrim($roh, '/'));
        if ($teile === false) {
            return null;
        }

        // Nur https. Ein Messskript ueber http waere auf einer
        // https-Seite ohnehin blockiert (Mixed Content) - und die
        // Freigabe stuende trotzdem in der Richtlinie.
        if (($teile['scheme'] ?? '') !== 'https') {
            return null;
        }

        $host = strtolower((string) ($teile['host'] ?? ''));
        if ($host === '' || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }

        // Zugangsdaten, Abfrage und Fragment haben in einer Basisadresse
        // nichts zu suchen; sie sind das Kennzeichen einer manipulierten
        // oder falsch abgetippten URL.
        foreach (['user', 'pass', 'query', 'fragment'] as $unerwuenscht) {
            if (isset($teile[$unerwuenscht])) {
                return null;
            }
        }

        $port = isset($teile['port']) ? ':'.(int) $teile['port'] : '';
        $pfad = rtrim((string) ($teile['path'] ?? ''), '/');

        // Ein Pfad ist erlaubt (Matomo liegt oft unter /matomo), aber nur
        // in harmloser Schreibweise.
        if ($pfad !== '' && ! preg_match('#^(/[A-Za-z0-9_-]+)+$#', $pfad)) {
            return null;
        }

        return [
            'url' => 'https://'.$host.$port.$pfad,
            'site_id' => $seite,
            // Fuer die Inhaltsrichtlinie zaehlt der URSPRUNG, nicht der
            // Pfad: eine Freigabe ist immer hostweit.
            'origin' => 'https://'.$host.$port,
        ];
    }

    /** Ist die Messung eingerichtet? */
    public static function aktiv(): bool
    {
        return self::konfiguration() !== null;
    }

    /**
     * Der Ursprung fuer script-src/connect-src - oder ein leerer String.
     * Leer heisst: keine zusaetzliche Freigabe in der Richtlinie.
     */
    public static function cspOrigin(): string
    {
        return self::konfiguration()['origin'] ?? '';
    }
}
