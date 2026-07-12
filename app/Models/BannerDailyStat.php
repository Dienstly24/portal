<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tageswerte je Banner (Impressions/Klicks) für Zeitraum-Statistiken. */
class BannerDailyStat extends Model
{
    public $timestamps = false;

    // 'date' bewusst als reiner Y-m-d-String (kein Cast): firstOrCreate
    // muss den Tageswert exakt wiederfinden (Unique banner_id+date).
    protected $fillable = ['banner_id', 'date', 'impressions', 'clicks'];

    public function banner()
    {
        return $this->belongsTo(Banner::class);
    }
}
