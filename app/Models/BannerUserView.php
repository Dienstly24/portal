<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sichtbarkeit je Kunde: zählt eindeutige Betrachter und merkt sich,
 * bis wann ein Kunde den Banner weggeklickt hat (dismissed_until).
 */
class BannerUserView extends Model
{
    public $timestamps = false;

    protected $fillable = ['banner_id', 'user_id', 'views', 'last_seen_at', 'dismissed_until'];

    protected $casts = ['last_seen_at' => 'datetime', 'dismissed_until' => 'datetime'];

    public function banner()
    {
        return $this->belongsTo(Banner::class);
    }
}
