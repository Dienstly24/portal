<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Die Unterhaltung als DATENSATZ (Omnichannel Phase B).
 *
 * Bis hierher war "die Unterhaltung" eine Abfrage ueber
 * `customer_messages` - deshalb konnte ihr nichts anhaften: kein
 * Zustand, kein Zustaendiger, kein Abschluss. Jetzt ist sie ein Objekt,
 * und alles andere (Inbox, Zuweisung, Historie, Archiv) haengt daran.
 *
 * WICHTIG: dieses Modell kennt KEINEN einzigen Kanal beim Namen. Wer
 * hier `whatsapp` schreibt, hat die Abstraktion durchbrochen -
 * `MessagingArchitectureTest` prueft das.
 */
class Conversation extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_OPEN = 'open';
    public const STATUS_PENDING = 'pending';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    /** Bewusst eine Liste und kein ENUM - neue Zustaende ohne Migration. */
    public const STATUSES = [
        self::STATUS_OPEN => 'Offen',
        self::STATUS_PENDING => 'Wartet',
        self::STATUS_CLOSED => 'Geschlossen',
        self::STATUS_ARCHIVED => 'Archiviert',
    ];

    /** Wie lange eine Bearbeitungs-Markierung gilt (Minuten). */
    public const LOCK_MINUTES = 3;

    protected $fillable = [
        'customer_id', 'channel_id', 'channel_account_id',
        'external_conversation_id', 'external_user_id',
        'assigned_employee_id', 'status', 'subject', 'last_message_at',
        'closed_at', 'archived_at', 'reopened_at', 'locked_by', 'locked_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
        'archived_at' => 'datetime',
        'reopened_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo { return $this->belongsTo(Channel::class); }
    /** @return BelongsTo<ChannelAccount, $this> */
    public function channelAccount(): BelongsTo { return $this->belongsTo(ChannelAccount::class); }
    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_employee_id'); }
    /** @return BelongsTo<User, $this> */
    public function lockedBy(): BelongsTo { return $this->belongsTo(User::class, 'locked_by'); }
    /** @return HasMany<CustomerMessage, $this> */
    public function messages(): HasMany { return $this->hasMany(CustomerMessage::class, 'conversation_id'); }
    /** @return HasMany<ConversationAssignment, $this> */
    public function assignments(): HasMany { return $this->hasMany(ConversationAssignment::class); }

    /** @return HasOne<CustomerMessage, $this> */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(CustomerMessage::class, 'conversation_id')->latestOfMany('created_at');
    }

    public function scopeOpen($q) { return $q->whereIn('status', [self::STATUS_OPEN, self::STATUS_PENDING]); }
    public function scopeUnarchived($q) { return $q->whereNull('archived_at'); }
    public function scopeAssignedTo($q, int $userId) { return $q->where('assigned_employee_id', $userId); }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_PENDING], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Eine Markierung gilt nur kurz. Ein abgestuerzter Browser darf eine
     * Unterhaltung nicht dauerhaft blockieren - lieber zwei Mitarbeiter,
     * die einander sehen, als eine Unterhaltung, die niemand mehr oeffnen
     * kann.
     */
    public function lockedByOther(int $userId): ?User
    {
        if (! $this->locked_by || $this->locked_by === $userId) {
            return null;
        }
        if (! $this->locked_at || $this->locked_at->lt(now()->subMinutes(self::LOCK_MINUTES))) {
            return null;
        }

        return $this->lockedBy;
    }

    /** Markiert diese Unterhaltung als "wird gerade bearbeitet". */
    public function touchLock(int $userId): void
    {
        $this->forceFill(['locked_by' => $userId, 'locked_at' => now()])->saveQuietly();
    }
}
