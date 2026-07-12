<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmailCampaign extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['created_by','subject','body','target','status','sent_count','sent_at','scheduled_for'];
    protected $casts = ['sent_at' => 'datetime', 'scheduled_for' => 'datetime'];

    /** Zulässige Zielgruppen (Empfänger-Dropdown + Validierung). */
    public const TARGETS = ['all', 'de', 'ar', 'kfz', 'krankenversicherung', 'internet', 'strom_gas'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function logs() { return $this->hasMany(EmailLog::class, 'campaign_id'); }
}
