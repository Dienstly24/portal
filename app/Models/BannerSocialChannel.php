<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ein Plattform-Kanal eines Banner-Social-Posts: traegt den eindeutigen
 * Tracking-Kurzlink (/s/{code}) fuer Klick-Zaehlung je Plattform und das
 * Veroeffentlichungs-Protokoll (wer hat wann tatsaechlich gepostet).
 */
class BannerSocialChannel extends Model
{
    protected $fillable = [
        'banner_social_post_id', 'platform', 'short_code',
        'clicks', 'last_click_at', 'published_at', 'published_by',
    ];

    protected $casts = [
        'last_click_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function post()
    {
        return $this->belongsTo(BannerSocialPost::class, 'banner_social_post_id');
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** Absoluter Kurzlink fuer den Beitrag dieser Plattform. */
    public function shortUrl(): string
    {
        return url('/s/' . $this->short_code);
    }

    /** Klick von der Plattform zaehlen (oeffentlicher Redirect). */
    public function recordClick(): void
    {
        $this->increment('clicks');
        $this->forceFill(['last_click_at' => now()])->saveQuietly();
    }

    /** Eindeutigen, plattform-erkennbaren Code erzeugen (z. B. fb-x7k2p9). */
    public static function generateCode(string $platform): string
    {
        $prefix = BannerSocialPost::PLATFORMS[$platform]['prefix'] ?? 'sm';
        do {
            $code = $prefix . '-' . Str::lower(Str::random(6));
        } while (self::where('short_code', $code)->exists());

        return $code;
    }

    public function platformInfo(): array
    {
        return BannerSocialPost::PLATFORMS[$this->platform]
            ?? ['label' => ucfirst($this->platform), 'icon' => '📣', 'prefix' => 'sm'];
    }
}
