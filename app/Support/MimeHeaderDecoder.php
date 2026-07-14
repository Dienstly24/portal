<?php
namespace App\Support;

/**
 * Dekodiert MIME-"encoded-word"-Header (RFC 2047), z. B.
 * "=?utf-8?Q?Bitte_best=C3=A4tigen_Sie_Ihre_Angaben?=" ->
 * "Bitte bestätigen Sie Ihre Angaben".
 *
 * Notwendig, weil manche IMAP-Server/Weiterleitungen den Betreff (und den
 * Absendernamen) roh kodiert ausliefern. Ohne Dekodierung landen kryptische
 * "=?utf-8?Q?..."-Betreffs in Aufgaben/Posteingang. Defensiv: bei
 * unbekannter Kodierung wird der Originalwert unveraendert zurueckgegeben,
 * nie eine Exception in die Mail-Pipeline geworfen.
 */
class MimeHeaderDecoder
{
    public static function decode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        // Kein encoded-word enthalten -> unveraendert lassen (haeufigster Fall).
        if (!str_contains($value, '=?')) {
            return $value;
        }

        // mb_decode_mimeheader ist am robustesten fuer gemischte Kodierungen;
        // iconv_mime_decode als Fallback, falls mbstring einen Teil nicht loest.
        $decoded = null;
        if (function_exists('mb_decode_mimeheader')) {
            $decoded = @mb_decode_mimeheader($value);
        }
        if (($decoded === null || $decoded === '' || str_contains((string) $decoded, '=?'))
            && function_exists('iconv_mime_decode')) {
            $iconv = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($iconv !== false && $iconv !== '') {
                $decoded = $iconv;
            }
        }

        $decoded = $decoded !== null && $decoded !== '' ? $decoded : $value;

        // Encoded-words trennen Woerter oft mit Unterstrichen ("Q"-Kodierung);
        // uebrig gebliebene, offensichtliche Trennungen normalisieren.
        return trim($decoded);
    }
}
