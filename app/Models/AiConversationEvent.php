<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ereignisprotokoll eines KI-Gespraechs (Spezifikation Abschnitt 23).
 *
 * BEWUSST GETRENNT vom Chattext: hier steht, WAS passiert ist
 * (Zustandswechsel, erfasste Feldnamen, Angebotsauswahl, Pruefung,
 * Uebernahme, Fehler) - nicht, was geschrieben wurde. Damit bleibt der
 * Ablauf nachvollziehbar, ohne die Kundenkommunikation zu duplizieren
 * (Datenminimierung, dieselbe Linie wie ai_assistant_logs).
 *
 * `detail` enthaelt nie den Rohwert einer sensiblen Angabe - nur den
 * Feldnamen und das Ergebnis. Verschluesselt ist es trotzdem.
 */
class AiConversationEvent extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const ACTOR_AI = 'ai';
    public const ACTOR_STAFF = 'staff';
    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_SYSTEM = 'system';

    public const EVENT_STARTED = 'conversation_started';
    public const EVENT_INTENT = 'intent_detected';
    public const EVENT_STATE = 'state_changed';
    public const EVENT_COLLECTED = 'information_collected';
    public const EVENT_OFFER_ADDED = 'offer_added';
    public const EVENT_OFFER_PRESENTED = 'offer_presented';
    public const EVENT_OFFER_SELECTED = 'offer_selected';
    public const EVENT_VERIFICATION = 'verification_run';
    public const EVENT_HANDOVER = 'handover';
    public const EVENT_TAKEOVER = 'staff_takeover';
    public const EVENT_RESUMED = 'ai_resumed';
    public const EVENT_ERROR = 'error';
    public const EVENT_LEAD = 'lead_created';

    public const EVENT_LABELS = [
        self::EVENT_STARTED => 'Gespräch begonnen',
        self::EVENT_INTENT => 'Anliegen erkannt',
        self::EVENT_STATE => 'Zustand gewechselt',
        self::EVENT_COLLECTED => 'Angaben erfasst',
        self::EVENT_OFFER_ADDED => 'Angebot hinterlegt',
        self::EVENT_OFFER_PRESENTED => 'Angebot vorgestellt',
        self::EVENT_OFFER_SELECTED => 'Angebot ausgewählt',
        self::EVENT_VERIFICATION => 'Prüfung durchgeführt',
        self::EVENT_HANDOVER => 'An das Team übergeben',
        self::EVENT_TAKEOVER => 'Mitarbeiter hat übernommen',
        self::EVENT_RESUMED => 'KI hat wieder übernommen',
        self::EVENT_ERROR => 'Störung',
        self::EVENT_LEAD => 'Interessent angelegt',
    ];

    protected $fillable = [
        'conversation_id', 'lead_id', 'event', 'actor', 'user_id',
        'from_state', 'to_state', 'detail',
    ];

    protected function casts(): array
    {
        return ['detail' => 'encrypted:array'];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function conversation() { return $this->belongsTo(AiConversation::class, 'conversation_id'); }
    public function user() { return $this->belongsTo(User::class); }

    public function label(): string
    {
        return self::EVENT_LABELS[$this->event] ?? $this->event;
    }
}
