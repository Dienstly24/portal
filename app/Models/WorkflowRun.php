<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Laufende Instanz eines Workflows fuer ein Ticket/einen Kunden. Haelt den
 * Status, das KI-Gedaechtnis (`memory`, verschluesselt) und die festgehaltene
 * Definitions-Version (reproduzierbar, Blueprint Saeule 6 + 9).
 *
 * `memory` traegt Kunden-PII -> `encrypted:array` auf einer `text`-Spalte
 * (NIE `json`, sonst SQLSTATE[22032]).
 */
class WorkflowRun extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING_CUSTOMER = 'waiting_customer';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Zustaende, in denen die Engine nicht weiterlaeuft. */
    public const TERMINAL = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    protected $fillable = [
        'workflow_definition_id', 'definition_key', 'version', 'ticket_id',
        'customer_id', 'status', 'current_step_key', 'confidence', 'memory',
        'started_by', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'confidence' => 'integer',
            // KI-Gedaechtnis mit Kunden-PII: verschluesselt at rest.
            'memory' => 'encrypted:array',
            'completed_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function definition()
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }

    public function stepRuns()
    {
        return $this->hasMany(WorkflowStepRun::class);
    }

    public function actionLogs()
    {
        return $this->hasMany(AiActionLog::class);
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function starter()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }
}
