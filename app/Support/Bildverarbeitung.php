<?php

namespace App\Support;

/**
 * Ist die PHP-Bildbibliothek (GD) da?
 *
 * WARUM DIESE KLASSE EXISTIERT (Betreiber-Meldung 10.09.2026, "HTTP 500
 * beim Unterschreiben"): auf dem Server fehlte `php8.3-gd`. Der
 * Unterschreiben-Pfad rief `imagecreatefromstring()` auf - eine Funktion,
 * die es ohne GD schlicht NICHT GIBT. PHP beendet den Aufruf dann mit
 * einem FATALEN Error, und zwar an einer Stelle, die kein `@` und kein
 * `try` abfaengt, weil ein fehlender FUNKTIONSNAME kein Laufzeitfehler des
 * Aufrufs ist.
 *
 * Der Unterzeichner hatte zu diesem Zeitpunkt bereits unterschrieben: im
 * Protokoll stand "Unterschrift begonnen", danach nichts. Die Unterschrift
 * war weg, und niemand konnte ihm sagen, warum.
 *
 * DIE LEHRE IST NICHT "GD INSTALLIEREN", sondern: eine Voraussetzung, die
 * das Programm braucht, muss FRUEH geprueft werden und einen Satz
 * hinterlassen, den ein Mensch lesen kann. Deshalb steht die Pruefung an
 * einer Stelle (hier), wird beim HOCHLADEN gefragt (nicht beim
 * Unterschreiben - dieselbe Regel wie beim kaputten PDF) und steht als
 * eigene Zeile auf der Systemzustand-Seite.
 *
 * `extension_loaded()` allein genuegt NICHT: es gibt Installationen, in
 * denen die Erweiterung geladen ist, einzelne Funktionen aber per
 * `disable_functions` gesperrt sind. Geprueft wird deshalb, was wirklich
 * aufgerufen wird.
 */
class Bildverarbeitung
{
    /**
     * Die Funktionen, ohne die der Signatur-Weg nicht arbeiten kann.
     *
     * `getimagesizefromstring` steht bewusst NICHT dabei: sie gehoert zum
     * PHP-Kern und ist auch ohne GD vorhanden. Genau daran liess sich der
     * Fehler festmachen - sie lief durch, die naechste Zeile nicht.
     *
     * @var array<int,string>
     */
    public const BENOETIGT = [
        'imagecreatefromstring',
        'imagepng',
        'imagesx',
        'imagesy',
        'imagecolorat',
        'imagealphablending',
        'imagesavealpha',
        'imagedestroy',
        'imagecreatetruecolor',
        'imagecopyresampled',
    ];

    public static function verfuegbar(): bool
    {
        return self::fehlendeFunktionen() === [];
    }

    /** @return array<int,string> Nicht aufrufbare Funktionen, leer = alles da. */
    public static function fehlendeFunktionen(): array
    {
        $fehlt = [];
        foreach (self::BENOETIGT as $funktion) {
            if (! function_exists($funktion)) {
                $fehlt[] = $funktion;
            }
        }

        return $fehlt;
    }

    /**
     * Der Satz fuer den MITARBEITER: was fehlt und wer es beheben kann.
     *
     * Bewusst ohne Funktionsnamen und ohne Dateipfad - der Mitarbeiter
     * kann damit nichts anfangen, und die Meldung erscheint im Browser.
     * Der Befehl fuer den Server steht in `hinweisFuerBetrieb()`.
     */
    public static function meldungFuerOberflaeche(): string
    {
        return 'Die Bildverarbeitung (PHP-Erweiterung GD) ist auf dem Server nicht '
            .'verfügbar. Unterschriften können deshalb nicht verarbeitet werden. '
            .'Bitte die Systemverwaltung informieren - bis dahin bitte keine '
            .'Signaturanfragen versenden.';
    }

    /** Der Satz fuer die Systemzustand-Seite: konkret und ausfuehrbar. */
    public static function hinweisFuerBetrieb(): string
    {
        return 'Fehlende PHP-Erweiterung GD. Auf dem Server installieren mit: '
            .'apt install php8.3-gd && systemctl restart php8.3-fpm '
            .'(Versionsnummer aus "php -v"). Betroffen sind Signaturen, '
            .'Medienbibliothek, Social-Formate und die Logo-Erzeugung.';
    }
}
