<?php

namespace App\Support;

/**
 * Zeitbasierte Einmalkennwoerter (TOTP, RFC 6238 auf Basis von HOTP,
 * RFC 4226) - die zweite Schicht der Anmeldung in der Beraterwelt.
 *
 * Bewusst ohne Fremd-Bibliothek: Das Verfahren ist ein HMAC und rund
 * hundert Zeilen; eine weitere Abhaengigkeit in einem System mit Kunden-,
 * Gesundheits- und Bankdaten waere der groessere Preis. Die Umsetzung ist
 * gegen die Testvektoren aus RFC 6238 geprueft (tests/Unit/TotpTest.php).
 *
 * Kompatibel mit allen gaengigen Apps (Google Authenticator, Microsoft
 * Authenticator, Aegis, 1Password, ...): SHA1, 6 Stellen, 30 Sekunden -
 * das ist die Voreinstellung, die jede App ohne Nachfragen versteht.
 */
class Totp
{
    /** Laenge des Codes. */
    public const DIGITS = 6;

    /** Zeitfenster in Sekunden. */
    public const PERIOD = 30;

    /**
     * Wie viele Fenster vor/nach dem aktuellen noch gelten. 1 = plus/minus
     * 30 Sekunden. Deckt die uebliche Uhren-Abweichung eines Telefons ab,
     * ohne das Zeitfenster fuer einen Angreifer nennenswert zu vergroessern.
     */
    public const WINDOW = 1;

    /** Neues Geheimnis (160 Bit, Base32 - so erwarten es die Apps). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Code fuer einen Zeitpunkt berechnen.
     *
     * @param  int|null  $timestamp  Unix-Zeit; null = jetzt
     */
    public static function code(string $secret, ?int $timestamp = null, int $digits = self::DIGITS, int $period = self::PERIOD): string
    {
        $counter = intdiv($timestamp ?? time(), $period);

        return self::hotp($secret, $counter, $digits);
    }

    /**
     * Code pruefen - inklusive Toleranz fuer leicht abweichende Uhren.
     *
     * Der Vergleich laeuft ueber hash_equals: ein einfaches === braucht je
     * nach Uebereinstimmung unterschiedlich lange und verraet damit ueber
     * viele Versuche hinweg den richtigen Code (Timing-Angriff).
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = self::WINDOW): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $now = $timestamp ?? time();
        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidate = self::code($secret, $now + $offset * self::PERIOD);
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adresse fuer den QR-Code bzw. die manuelle Eingabe.
     * Bewusst nur die Standardwerte - jede zusaetzliche Angabe ist eine
     * weitere Stelle, an der eine App anders entscheiden kann.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Geheimnis in Vierergruppen - so laesst es sich abtippen. */
    public static function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    /** HOTP nach RFC 4226. */
    private static function hotp(string $secret, int $counter, int $digits): string
    {
        $key = self::base32Decode($secret);
        $binCounter = pack('N*', 0, $counter); // 64 Bit, hoechstwertig zuerst
        $hash = hash_hmac('sha1', $binCounter, $key, true);

        // Dynamische Kuerzung: die letzten 4 Bit zeigen auf den Startpunkt
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');

        $bits = '';
        for ($i = 0; $i < strlen($secret); $i++) {
            $index = strpos(self::ALPHABET, $secret[$i]);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
