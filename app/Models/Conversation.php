<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
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
        'customer_id', 'channel_id', 'last_channel_id', 'last_inbound_channel_id',
        'channel_account_id',
        'external_conversation_id', 'external_user_id',
        'assigned_employee_id', 'status', 'ai_mode', 'subject', 'last_message_at',
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

        /*
         * JEDE Unterhaltung traegt vom ersten Moment an ihren Kanal
         * (Phase 2).
         *
         * Hier im Modell und nicht im Conversation Engine - dieselbe
         * Begruendung wie beim Versand-Anstoss: es gilt fuer JEDEN
         * Schreibweg. Eine Unterhaltung ohne Eintrag waere im
         * Kanal-Filter des Postfachs unsichtbar, und zwar ohne
         * Fehlermeldung: die Liste zeigt einfach eine Zeile weniger.
         * Genau diese Art Ausfall bemerkt man erst, wenn ein Kunde
         * nachfragt.
         *
         * Der Eintrag ist idempotent, der Engine darf ihn also
         * zusaetzlich mit der Gegenstelle ergaenzen.
         */
        static::created(function (self $m) {
            if (! $m->channel_id) {
                return;
            }

            try {
                ConversationChannel::firstOrCreate(
                    ['link_key' => ConversationChannel::linkKey($m->id, $m->channel_id, $m->channel_account_id)],
                    [
                        'conversation_id' => $m->id,
                        'channel_id' => $m->channel_id,
                        'channel_account_id' => $m->channel_account_id,
                        'external_user_id' => $m->external_user_id,
                        'external_conversation_id' => $m->external_conversation_id,
                        'join_method' => ConversationChannel::JOIN_INITIAL,
                        'joined_at' => now(),
                    ]
                );
            } catch (\Throwable) {
                // Wie beim Versand: das Anlegen der Unterhaltung darf
                // daran nie scheitern. Fehlt der Eintrag, traegt ihn der
                // Nachtrag nach - die Nachricht ist dann sichtbar, nur
                // der Kanal-Reiter waere unvollstaendig.
            }
        });
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

    /**
     * ALLE Kanaele dieser Unterhaltung (Phase 2).
     *
     * `channel` (Einzahl) ist ab jetzt der ERSTE Kanal, `lastChannel`
     * der zuletzt benutzte. Beide bleiben als Spalte bestehen, weil die
     * Inbox danach sortiert und filtert - eine Beziehung statt einer
     * Spalte haette dort je Zeile eine weitere Abfrage bedeutet.
     *
     * @return HasMany<ConversationChannel, $this>
     */
    public function channels(): HasMany { return $this->hasMany(ConversationChannel::class); }

    /** @return BelongsTo<Channel, $this> */
    public function lastChannel(): BelongsTo { return $this->belongsTo(Channel::class, 'last_channel_id'); }

    /**
     * Die Zugehoerigkeit zu EINEM Kanal - der Weg zur Gegenstelle.
     *
     * Ohne sie waere nach einer Zusammenfuehrung nicht mehr bestimmbar,
     * an WELCHE Adresse eine Antwort auf diesem Kanal geht.
     */
    public function channelLink(int $channelId): ?ConversationChannel
    {
        return $this->channels->firstWhere('channel_id', $channelId)
            ?: $this->channels()->where('channel_id', $channelId)->first();
    }

    /** Traegt diese Unterhaltung mehr als einen Kanal? */
    public function isMultiChannel(): bool
    {
        return $this->channels()->count() > 1;
    }

    /**
     * Interne Notizen zu diesem Vorgang.
     *
     * Sie sind bewusst KEINE Nachrichten: `messages()` liefert, was
     * zwischen uns und dem Kunden gelaufen ist, und genau darauf
     * verlassen sich Portal, Verlauf, Suche und die KI. Eine Notiz
     * gehoert in keine dieser Antworten.
     *
     * @return HasMany<ConversationNote, $this>
     */
    public function notes(): HasMany { return $this->hasMany(ConversationNote::class); }

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

    /**
     * Wann hat der Kunde zuletzt GESCHRIEBEN?
     *
     * Manche Plattformen erlauben freie Nachrichten nur innerhalb eines
     * Zeitfensters nach der letzten Kundennachricht (WhatsApp: 24 h,
     * danach nur genehmigte Vorlagen). Der Kern kennt diese Regel nicht -
     * er liefert nur die TATSACHE, der Adapter zieht daraus seinen
     * Schluss. So bleibt die Frist Plattformwissen und wandert nicht in
     * die Unterhaltung.
     */
    public function lastInboundAt(): ?Carbon
    {
        $zeit = $this->messages()
            ->where('direction', CustomerMessage::DIRECTION_INCOMING)
            ->max('created_at');

        return $zeit ? Carbon::parse($zeit) : null;
    }

    /** Markiert diese Unterhaltung als "wird gerade bearbeitet". */
    public function touchLock(int $userId): void
    {
        $this->forceFill(['locked_by' => $userId, 'locked_at' => now()])->saveQuietly();
    }
}
