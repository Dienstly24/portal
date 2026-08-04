<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Social-Media-Post zu einem Banner (Phase 1 "Social-Publishing"):
 * gemeinsamer Beitragstext (DE/AR) + oeffentliches Klick-Ziel; je Plattform
 * ein BannerSocialChannel mit Tracking-Kurzlink. Veroeffentlicht wird in
 * Phase 1 manuell auf der Plattform - das System liefert fertige
 * Bildformate, Texte und die messbaren Links (Phase 2: Meta-API).
 */
class BannerSocialPost extends Model
{
    /**
     * Zeitzone des Betreibers fuer Zeiteingaben/-anzeigen (app.timezone
     * ist UTC): scheduled_for wird als deutsche Zeit erfasst, in UTC
     * gespeichert und zur Anzeige zurueckgerechnet.
     */
    public const OPERATOR_TZ = 'Europe/Berlin';

    /** Unterstuetzte Plattformen (eine Quelle fuer Formulare/Anzeige/Codes). */
    public const PLATFORMS = [
        'facebook'  => ['label' => 'Facebook',  'icon' => '📘', 'prefix' => 'fb'],
        'instagram' => ['label' => 'Instagram', 'icon' => '📸', 'prefix' => 'ig'],
        'tiktok'    => ['label' => 'TikTok',    'icon' => '🎵', 'prefix' => 'tt'],
    ];

    protected $fillable = [
        'banner_id', 'caption_de', 'caption_ar', 'target_url',
        'scheduled_for', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
    ];

    public function banner()
    {
        return $this->belongsTo(Banner::class);
    }

    public function channels()
    {
        return $this->hasMany(BannerSocialChannel::class);
    }

    public function channelFor(string $platform): ?BannerSocialChannel
    {
        return $this->channels->firstWhere('platform', $platform);
    }

    /** Summe aller Social-Klicks ueber alle Plattformen. */
    public function totalClicks(): int
    {
        return (int) $this->channels->sum('clicks');
    }
}
