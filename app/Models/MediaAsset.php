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

    protected static function booted(): void
    {
        // Slot-Aufloesung ist auf jeder Website-Seite noetig -> gecacht;
        // jede Aenderung an Bildern raeumt den Cache vollstaendig auf.
        $flush = function (self $asset) {
            foreach (array_keys((array) config('website.slots')) as $slot) {
                Cache::forget('website-media-slot:' . $slot);
            }
        };
        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Aktives Bild eines Slots (gecacht, null wenn keins zugewiesen). */
    public static function forSlot(string $slot): ?self
    {
        return Cache::remember(
            'website-media-slot:' . $slot,
            now()->addMinutes(30),
            fn () => self::where('slot', $slot)
                ->where('processing_status', 'ready')
                ->latest('updated_at')
                ->first()
        );
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
        return '/storage/' . ltrim($path, '/');
    }

    /** Groesste JPG-Variante als <img>-Fallback (SVG: Originaldatei). */
    public function fallbackUrl(): ?string
    {
        if ($this->isSvg()) {
            $first = (array) ($this->variants[0] ?? null);
            return $first ? self::publicUrl($first['path']) : null;
        }
        $jpgs = $this->variantsOf('jpg');
        $last = end($jpgs);
        return $last ? self::publicUrl($last['path']) : null;
    }

    /** srcset-Attribut eines Formats ("url 480w, url 960w ..."). */
    public function srcset(string $format): string
    {
        return implode(', ', array_map(
            fn ($v) => self::publicUrl($v['path']) . ' ' . $v['width'] . 'w',
            $this->variantsOf($format)
        ));
    }

    /** Abmessungen der groessten Variante [width, height] fuer CLS-freie <img>. */
    public function displaySize(): array
    {
        $jpgs = $this->variantsOf('jpg');
        $last = end($jpgs);
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
