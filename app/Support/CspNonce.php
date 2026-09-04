<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Ein Zufallswert je Anfrage fuer die Content-Security-Policy
 * (Audit SEC-4).
 *
 * Warum ueberhaupt: bis SEC-4 stand in der Richtlinie
 * `script-src 'self' 'unsafe-inline' 'unsafe-eval'`. `'unsafe-inline'`
 * erlaubt JEDES eingebettete Skript - also genau das, was ein
 * XSS-Angriff einschleust. Eine CSP mit `'unsafe-inline'` im script-src
 * schuetzt vor XSS praktisch nicht mehr; sie sieht nur so aus.
 *
 * Der Ausweg ist ein NONCE: die Antwort nennt einen frischen Zufallswert,
 * und nur Skripte, die ihn tragen, laufen. Eingeschleustes Markup kennt
 * ihn nicht - der Angreifer sieht die Antwort ja nicht, in der er steht.
 *
 * Der Wert wird EINMAL je Anfrage erzeugt und dann festgehalten: ein
 * zweiter Aufruf muss denselben Wert liefern, sonst passt der Nonce im
 * Header nicht zu dem im HTML und die Seite bleibt stumm.
 */
class CspNonce
{
    private static ?string $nonce = null;

    public static function get(): string
    {
        return self::$nonce ??= Str::random(24);
    }

    /** Fertiges Attribut fuer Blade: <script @cspNonce> */
    public static function attribute(): string
    {
        return 'nonce="'.e(self::get()).'"';
    }

    /**
     * Zuruecksetzen zwischen zwei Anfragen. In Tests laufen mehrere
     * Anfragen im selben Prozess - ohne das truege die zweite Antwort
     * den Nonce der ersten.
     */
    public static function reset(): void
    {
        self::$nonce = null;
    }
}
