<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Zustellprotokoll pro Empfänger (Tabelle existiert seit 2026_07_06,
 * wurde aber nie beschrieben - Verbesserungsplan Paket A3).
 * type: campaign | contract_switch | ...
 */
class EmailLog extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['campaign_id', 'user_id', 'email', 'subject', 'type', 'status'];
    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = (string) Str::uuid());
    }
    /** @return BelongsTo<EmailCampaign, $this> */
    public function campaign(): BelongsTo { return $this->belongsTo(EmailCampaign::class, 'campaign_id'); }
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
