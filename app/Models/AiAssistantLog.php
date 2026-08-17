<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Audit-Spur jeder Runde des KI-Kundenassistenten (Spezifikation
 * Abschnitt 22): wer, wann, welche Absicht, welche Tools, welche
 * Aktionen, welches Ergebnis, Uebergabe ja/nein.
 *
 * Bewusst OHNE Roh-Prompt und ohne Nachrichtentext (Datenminimierung -
 * dieselbe Regel wie bei AiDecision: nur das Ergebnis, nicht die
 * Eingabe). `detail` kann Fragmente enthalten -> verschluesselt.
 */
class AiAssistantLog extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const OUTCOME_ANSWERED = 'answered';
    public const OUTCOME_OUT_OF_SCOPE = 'refused_out_of_scope';
    public const OUTCOME_ESCALATED = 'escalated';
    public const OUTCOME_FALLBACK = 'fallback';
    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_LABELS = [
        self::OUTCOME_ANSWERED => 'Automatisch beantwortet',
        self::OUTCOME_OUT_OF_SCOPE => 'Außerhalb des Bereichs abgelehnt',
        self::OUTCOME_ESCALATED => 'An das Team übergeben',
        self::OUTCOME_FALLBACK => 'Fallback (KI-Dienst gestört)',
        self::OUTCOME_SKIPPED => 'Keine Antwort (KI inaktiv/Grenze)',
    ];

    protected $fillable = [
        'conversation_id', 'customer_id', 'customer_message_id', 'reply_message_id',
        'intent', 'in_scope', 'outcome', 'handover', 'employee_id',
        'tools', 'actions', 'detail', 'provider', 'model',
        'input_tokens', 'output_tokens', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'in_scope' => 'boolean',
            'handover' => 'boolean',
            'tools' => 'array',
            'actions' => 'array',
            'detail' => 'encrypted:array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function conversation() { return $this->belongsTo(AiConversation::class, 'conversation_id'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function employee() { return $this->belongsTo(User::class, 'employee_id'); }

    public function outcomeLabel(): string
    {
        return self::OUTCOME_LABELS[$this->outcome] ?? $this->outcome;
    }
}
