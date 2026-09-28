<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Darf eine gespeicherte Datei IM BROWSER geoeffnet werden - oder nur
 * heruntergeladen? (System-Audit 28.09.2026, KI-025)
 *
 * WARUM DAS EINE EIGENE STELLE IST: Dateien in der Kundenakte kommen
 * nicht nur von Mitarbeitern. E-Mail-Anhaenge BELIEBIGER Absender und
 * Dokumente aus WhatsApp werden ebenfalls zu Dokumenten. Wurden sie
 * "angezeigt", entschied der Typ, den das Dateisystem aus dem Inhalt
 * erraet - bei einer SVG also `image/svg+xml`. Die Inhaltsrichtlinie
 * (CSP) setzt die Anwendung aber nur auf HTML-Antworten: eine SVG mit
 * `<script>` lief damit OHNE jede Richtlinie im Ursprung der Beraterwelt,
 * mit der Sitzung des Mitarbeiters, der auf "Anzeigen" geklickt hat.
 *
 * DIE REGEL: inline nur, was ein Browser nachweislich nicht ausfuehrt -
 * PDF und die ueblichen Rasterbilder. Entschieden wird am INHALT, nie am
 * Dateinamen und nie an einer Typangabe von aussen (eine SVG, die sich
 * "foto.png" nennt, bleibt eine SVG; die Typangabe einer Plattform ist
 * die Behauptung des Absenders). Alles andere wird heruntergeladen - die
 * Datei ist nicht verloren, sie wird nur nicht im Ursprung der Anwendung
 * GEOEFFNET.
 */
final class InlineDatei
{
    /** Typen, die ein Browser anzeigt, ohne darin Code auszufuehren. */
    public const SICHERE_TYPEN = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** Inhaltstyp am INHALT bestimmt; null, wenn er nicht feststellbar ist. */
    public static function inhaltstyp(FilesystemAdapter $disk, string $pfad): ?string
    {
        try {
            $typ = $disk->mimeType($pfad);
        } catch (\Throwable) {
            return null;
        }

        return is_string($typ) && $typ !== '' ? strtolower(trim(explode(';', $typ)[0])) : null;
    }

    public static function darfInline(FilesystemAdapter $disk, string $pfad): bool
    {
        return in_array(self::inhaltstyp($disk, $pfad), self::SICHERE_TYPEN, true);
    }

    /**
     * Die Antwort fuer "Anzeigen": inline mit dem am Inhalt erkannten Typ,
     * sonst ein gewoehnlicher Download als `application/octet-stream`.
     */
    public static function antwort(FilesystemAdapter $disk, string $pfad, string $name): StreamedResponse
    {
        $typ = self::inhaltstyp($disk, $pfad);

        if (! in_array($typ, self::SICHERE_TYPEN, true)) {
            return $disk->download($pfad, $name, [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $disk->response($pfad, $name, [
            'Content-Type' => $typ,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
