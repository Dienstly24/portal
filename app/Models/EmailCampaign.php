<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EmailCampaign extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['created_by', 'subject', 'body', 'target', 'status', 'sent_count', 'sent_at', 'scheduled_for'];
    protected $casts = ['sent_at' => 'datetime', 'scheduled_for' => 'datetime'];

    /** Zulässige Zielgruppen (Empfänger-Dropdown + Validierung). */
    public const TARGETS = ['all', 'de', 'ar', 'kfz', 'krankenversicherung', 'internet', 'strom', 'gas', 'strom_gas'];

    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = Str::uuid());
    }
    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    /** @return HasMany<EmailLog, $this> */
    public function logs(): HasMany { return $this->hasMany(EmailLog::class, 'campaign_id'); }
}
