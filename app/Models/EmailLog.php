<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Zustellprotokoll pro Empfänger (Tabelle existiert seit 2026_07_06,
 * wurde aber nie beschrieben - Verbesserungsplan Paket A3).
 * type: campaign | contract_switch | ...
 */
class EmailLog extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['campaign_id','user_id','email','subject','type','status'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }
    public function campaign() { return $this->belongsTo(EmailCampaign::class, 'campaign_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
