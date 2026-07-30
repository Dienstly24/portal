<?php

namespace App\Support;

use App\Models\MediaAsset;

/**
 * Marken-Bilder (Logo, Symbol, Favicon) an EINER Stelle (Auftrag P1-1e).
 *
 * Der Betreiber laedt sein finales Logo unter /admin/medien hoch und
 * weist es dem passenden Slot zu - danach zeigt die GESAMTE Anwendung
 * (Website, Kundenportal, Beraterwelt, Login, Fehlerseiten) das neue
 * Logo, ohne dass eine Datei per FTP getauscht oder Code angefasst wird.
 *
 * Ist kein Bild zugewiesen, greift der bisherige Bestand aus
 * public/images (aus logo.png per GD erzeugt) - die Anwendung sieht
 * also nie "leer" aus. URLs sind IMMER relativ (P0-6: nie APP_URL-
 * oder IP-abhaengig).
 */
class BrandAssets
{
    /** Slot -> mitgelieferte Datei, die er ueberschreibt. */
    private const FALLBACKS = [
        'logo-hell' => '/images/logo-white.png',
        'logo-dunkel' => '/images/logo-transparent.png',
        'logo-symbol-hell' => '/images/logo-icon-white.png',
        'favicon' => '/images/favicon.png',
    ];

    /** Weisse Wortmarke fuer dunkle Flaechen (Website-Kopf, Login, Portal). */
    public static function logoLight(?int $width = null): string
    {
        return self::url('logo-hell', $width);
    }

    /** Farbige Wortmarke fuer helle Flaechen (Beraterwelt-Kopfzeile). */
    public static function logoDark(?int $width = null): string
    {
        return self::url('logo-dunkel', $width);
    }

    /** Weisses D-Symbol fuer dunkle Seitenleisten. */
    public static function logoSymbolLight(?int $width = null): string
    {
        return self::url('logo-symbol-hell', $width);
    }

    /**
     * Favicon in der gewuenschten Kantenlaenge (32 = Browser-Tab,
     * 180 = Apple-Touch-Icon, 512 = Startbildschirm/PWA). Ohne
     * zugewiesenen Slot greifen die bestehenden Dateien.
     */
    public static function favicon(int $size = 32): string
    {
        if ($asset = MediaAsset::forSlot('favicon')) {
            if ($url = $asset->variantUrl($size)) {
                return $url;
            }
        }

        return match (true) {
            $size >= 512 => '/images/logo-icon.png',
            $size >= 180 => '/images/apple-touch-icon.png',
            default => '/images/favicon.png',
        };
    }

    /** Aufloesung eines Marken-Slots mit Rueckfall auf die Bestandsdatei. */
    private static function url(string $slot, ?int $width): string
    {
        if ($asset = MediaAsset::forSlot($slot)) {
            $url = $width ? $asset->variantUrl($width) : $asset->fallbackUrl();
            if ($url) {
                return $url;
            }
        }

        return self::FALLBACKS[$slot];
    }
}
