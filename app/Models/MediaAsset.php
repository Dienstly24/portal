<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * Ein Bild der Website-Medienverwaltung (/admin/medien).
 *
 * Jedes Bild kann genau einem Slot (festem Platz auf der Website)
 * zugewiesen sein; je Slot ist genau EIN Bild aktiv. Beim Zuweisen wird
 * das bisherige Slot-Bild automatisch ins Archiv gestellt (slot = null),
 * nie geloescht. Geloeschte Bilder liegen 30 Tage im Papierkorb
 * (SoftDeletes) und sind wiederherstellbar.
 */
class MediaAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slot', 'title', 'original_name', 'mime', 'width', 'height', 'size_bytes',
        'alt_de', 'alt_ar', 'credit', 'original_path', 'variants',
        'processing_status', 'processing_error', 'uploaded_by',
    ];

    protected $casts = [
        'variants' => 'array',
        'width' => 'integer',
        'height' => 'integer',
        'size_bytes' => 'integer',
    ];

    /** Cache-Schluessel der Slot-Belegung (ein Eintrag fuer alle Slots). */
    public const SLOT_CACHE_KEY = 'website-media-slots';

    protected static function booted(): void
    {
        // Slot-Aufloesung ist auf jeder Seite noetig -> gecacht; jede
        // Aenderung an Bildern raeumt den Cache auf.
        $flush = fn () => self::flushSlotCache();
        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }

    public static function flushSlotCache(): void
    {
        Cache::forget(self::SLOT_CACHE_KEY);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Aktives Bild eines Slots (null, wenn keins zugewiesen ist).
     *
     * Gecacht wird bewusst EIN Eintrag mit den ROHEN Spaltenwerten aller
     * Slots - nicht die Eloquent-Objekte selbst: Serialisierte Modelle
     * kommen aus einem echten Cache-Store (database/file/redis) als
     * __PHP_Incomplete_Class zurueck und legen damit jede Seite lahm.
     * Ausserdem braucht eine Seite mit zwoelf Slots so nur EINEN
     * Cache-Zugriff statt zwoelf.
     */
    public static function forSlot(string $slot): ?self
    {
        $all = Cache::remember(self::SLOT_CACHE_KEY, now()->addMinutes(30), function () {
            return self::whereNotNull('slot')
                ->where('processing_status', 'ready')
                ->orderBy('updated_at')
                ->get()
                // Bei (theoretisch unmoeglicher) Doppelbelegung gewinnt das
                // zuletzt aktualisierte Bild.
                ->keyBy('slot')
                ->map(fn (self $asset) => $asset->getAttributes())
                ->all();
        });

        $attributes = $all[$slot] ?? null;

        return $attributes ? (new self)->newFromBuilder($attributes) : null;
    }

    /** Alt-Text in der aktiven Sprache (ar mit Fallback de). */
    public function alt(): string
    {
        if (app()->getLocale() === 'ar') {
            return $this->alt_ar ?: $this->alt_de;
        }
        return $this->alt_de;
    }

    public function isSvg(): bool
    {
        return $this->mime === 'image/svg+xml';
    }

    /** Varianten eines Formats ('avif'|'webp'|'jpg'), aufsteigend nach Breite. */
    public function variantsOf(string $format): array
    {
        $list = array_values(array_filter((array) $this->variants, fn ($v) => ($v['format'] ?? '') === $format));
        usort($list, fn ($a, $b) => ($a['width'] ?? 0) <=> ($b['width'] ?? 0));
        return $list;
    }

    /**
     * Relative URL einer Variante (immer '/storage/...', NIE absolute Basis -
     * absolute Basen haben auf dem Server schon einmal auf eine rohe
     * IP-Adresse gezeigt, siehe Arbeitsauftrag P0-6).
     */
    public static function publicUrl(string $path): string
    {
        return '/storage/'.ltrim($path, '/');
    }

    /**
     * Format der universellen Fallback-Variante: PNG, sobald das Original
     * Transparenz hat (Logos, freigestellte Motive), sonst JPG.
     */
    public function fallbackFormat(): string
    {
        return $this->variantsOf('png') !== [] ? 'png' : 'jpg';
    }

    /** Groesste Fallback-Variante als <img>-Quelle (SVG: Originaldatei). */
    public function fallbackUrl(): ?string
    {
        if ($this->isSvg()) {
            $first = (array) ($this->variants[0] ?? null);
            return $first ? self::publicUrl($first['path']) : null;
        }
        $list = $this->variantsOf($this->fallbackFormat());
        $last = end($list);
        return $last ? self::publicUrl($last['path']) : null;
    }

    /**
     * URL der kleinsten Variante, die mindestens $width breit ist (sonst
     * der groessten). Fuer Stellen mit fester Anzeigegroesse - z. B. das
     * 32px-Favicon oder das Logo in der Kopfzeile.
     */
    public function variantUrl(int $width, ?string $format = null): ?string
    {
        if ($this->isSvg()) {
            return $this->fallbackUrl();
        }
        $list = $this->variantsOf($format ?? $this->fallbackFormat());
        foreach ($list as $variant) {
            if ((int) $variant['width'] >= $width) {
                return self::publicUrl($variant['path']);
            }
        }
        $last = end($list);
        return $last ? self::publicUrl($last['path']) : null;
    }

    /** srcset-Attribut eines Formats ("url 480w, url 960w ..."). */
    public function srcset(string $format): string
    {
        return implode(', ', array_map(
            fn ($v) => self::publicUrl($v['path']).' '.$v['width'].'w',
            $this->variantsOf($format)
        ));
    }

    /** Abmessungen der groessten Variante [width, height] fuer CLS-freie <img>. */
    public function displaySize(): array
    {
        $list = $this->variantsOf($this->fallbackFormat());
        $last = end($list);
        if ($last) {
            return [(int) $last['width'], (int) $last['height']];
        }
        return [(int) $this->width ?: 1200, (int) $this->height ?: 900];
    }

    /** Summe aller gespeicherten Bytes (Original + Varianten). */
    public function totalBytes(): int
    {
        return (int) $this->size_bytes
            + array_sum(array_map(fn ($v) => (int) ($v['bytes'] ?? 0), (array) $this->variants));
    }
}
