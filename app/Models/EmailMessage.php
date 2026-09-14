<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Rohgespeicherte eingehende E-Mail (Architekturplan Abschnitt 3/4).
 * Kein Ersatz für Ticket/InternalMessage - dies ist die Quelle, aus der
 * Tickets/Tasks/Dokumente erst per Kategorisierung+Matching abgeleitet
 * werden (siehe MailboxSyncService, EmailClassificationService).
 */
class EmailMessage extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'email_account_id', 'message_uid', 'from_address', 'from_name', 'to_address',
        'subject', 'body_text', 'body_html', 'received_at', 'category',
        'match_status', 'customer_id', 'match_score', 'processed_at', 'raw_headers',
    ];

    public const CATEGORIES = [
        'versicherung' => 'Versicherung',
        'fonds_finanz' => 'Fonds Finanz',
        'energie' => 'Energie',
        'dokumente' => 'Dokumente',
        'provisionen' => 'Provisionen',
        'kundenanfrage' => 'Kundenanfrage',
        'sonstige' => 'Sonstige',
    ];

    public const MATCH_STATUSES = ['unmatched', 'suggested', 'confirmed'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'raw_headers' => 'array',
            'attachments_meta' => 'array',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    /** @return BelongsTo<EmailAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<AiDecision, $this> */
    public function aiDecisions(): HasMany
    {
        return $this->hasMany(AiDecision::class);
    }

    public function scopeUnprocessed($q)
    {
        return $q->whereNull('processed_at');
    }

    public function scopeNeedsReview($q)
    {
        return $q->where('match_status', 'suggested');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ($this->category ?? '—');
    }
}
