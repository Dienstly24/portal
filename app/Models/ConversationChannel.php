<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WELCHE Kanaele traegt diese Unterhaltung? (Auftrag Abschnitte 2/7)
 *
 * Der Kern von Phase 2: aus "eine Unterhaltung = ein Kanal" wird "eine
 * Unterhaltung = ein Vorgang, der ueber mehrere Wege gelaufen ist".
 * Schreibt derselbe Kunde heute ueber WhatsApp und morgen im Portal,
 * ist das EINE Sache - der Mitarbeiter soll die Vorgeschichte sehen,
 * ohne zwischen zwei Listen zu springen.
 *
 * DIE GEGENSTELLE STEHT HIER, nicht an der Unterhaltung: dieselbe
 * Person ist bei WhatsApp eine Rufnummer und im Portal eine
 * Benutzer-Kennung. Eine einzige Spalte an der Unterhaltung koennte nur
 * eine von beiden tragen - und die Antwort ginge an die falsche
 * Adresse.
 *
 * KEIN KANALNAME in dieser Klasse. Was ein Kanal ist, steht in
 * `channels`; was er kann, in seinen Faehigkeiten.
 */
class ConversationChannel extends Model
{
    /** Die Unterhaltung hat auf diesem Kanal BEGONNEN. */
    public const JOIN_INITIAL = 'initial';

    /** Ein Mitarbeiter hat den Kanal ausdruecklich verbunden. */
    public const JOIN_MANUAL = 'manual';

    /** Die Automatik hat ihn anhand der Kundenakte verbunden. */
    public const JOIN_AUTO = 'auto';

    public const JOIN_METHODS = [
        self::JOIN_INITIAL => 'Hier begonnen',
        self::JOIN_MANUAL => 'Von einem Mitarbeiter verbunden',
        self::JOIN_AUTO => 'Automatisch verbunden',
    ];

    protected $fillable = [
        'conversation_id', 'channel_id', 'channel_account_id',
        'external_user_id', 'external_conversation_id',
        'join_method', 'joined_by', 'joined_at',
        'first_message_at', 'last_message_at', 'link_key',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'first_message_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo { return $this->belongsTo(Channel::class); }
    /** @return BelongsTo<ChannelAccount, $this> */
    public function channelAccount(): BelongsTo { return $this->belongsTo(ChannelAccount::class); }
    /** @return BelongsTo<User, $this> */
    public function joinedBy(): BelongsTo { return $this->belongsTo(User::class, 'joined_by'); }

    /**
     * Der Schluessel, der eine Doppelung unmoeglich macht.
     *
     * Ein fehlendes Konto wird zu `0` - NICHT zu NULL: in einem UNIQUE
     * gilt NULL in beiden Datenbanken als "immer verschieden", und ein
     * Kanal ohne Konto (Portal, interner Chat) waere beliebig oft
     * eintragbar gewesen.
     */
    public static function linkKey(string $conversationId, int $channelId, ?int $accountId): string
    {
        return $conversationId.':'.$channelId.':'.($accountId ?: 0);
    }

    public function joinMethodLabel(): string
    {
        return self::JOIN_METHODS[$this->join_method] ?? $this->join_method;
    }

    /** Wurde dieser Kanal nachtraeglich dazugeholt? */
    public function isJoined(): bool
    {
        return $this->join_method !== self::JOIN_INITIAL;
    }
}
