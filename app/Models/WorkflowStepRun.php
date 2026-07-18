<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ein einzelner Schritt einer Workflow-Instanz. `output` traegt das
 * (ggf. PII-haltige) Ergebnis -> `encrypted:array` auf `text`. `config` ist
 * der unverschluesselte Definitions-Snapshot des Schritts (kein PII).
 *
 * `decided_by`/`decided_at` dokumentieren den Human Override (Blueprint
 * Saeule 5): editieren, neu ausfuehren, ueberspringen, manuell erledigen.
 */
class WorkflowStepRun extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_WAITING_CUSTOMER = 'waiting_customer';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    /** Zustaende, die die Engine anhalten (Mensch/Kunde muss handeln). */
    public const HALTING = [self::STATUS_NEEDS_REVIEW, self::STATUS_WAITING_CUSTOMER, self::STATUS_FAILED];

    /** Zustaende, ueber die die Engine hinweggeht (Schritt ist erledigt). */
    public const DONE = [self::STATUS_COMPLETED, self::STATUS_SKIPPED];

    protected $fillable = [
        'workflow_run_id', 'step_key', 'type', 'status', 'confidence',
        'config', 'output', 'error', 'decided_by', 'decided_at', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'sort_order' => 'integer',
            'config' => 'array',
            // Ergebnis kann Kundendaten enthalten: verschluesselt at rest.
            'output' => 'encrypted:array',
            'decided_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function run()
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isHalting(): bool
    {
        return in_array($this->status, self::HALTING, true);
    }

    public function isDone(): bool
    {
        return in_array($this->status, self::DONE, true);
    }
}
