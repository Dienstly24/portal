<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Chronik JEDER KI-/System-/Mitarbeiter-Entscheidung eines Workflows
 * (Blueprint Saeule 10). Doppelte Spur zum bestehenden ActivityLog: hier
 * feingranular pro Run/Step mit Konfidenz, dort der globale Audit-Trail.
 *
 * `detail` kann PII-Fragmente enthalten -> `encrypted:array` auf `text`.
 */
class AiActionLog extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const ACTOR_AI = 'ai';
    public const ACTOR_STAFF = 'staff';
    public const ACTOR_SYSTEM = 'system';

    protected $fillable = [
        'workflow_run_id', 'workflow_step_run_id', 'ticket_id',
        'actor', 'actor_id', 'action', 'detail', 'confidence',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'encrypted:array',
            'confidence' => 'integer',
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

    public function step()
    {
        return $this->belongsTo(WorkflowStepRun::class, 'workflow_step_run_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Bequemer Chronik-Eintrag. Der Aufrufer uebergibt Run/Step-Kontext;
     * ticket_id wird aus dem Run uebernommen.
     *
     * @param array<string,mixed> $detail
     */
    public static function record(
        ?WorkflowRun $run,
        ?WorkflowStepRun $step,
        string $actor,
        string $action,
        array $detail = [],
        ?int $confidence = null,
        ?int $actorId = null,
    ): self {
        return static::create([
            'workflow_run_id' => $run?->id,
            'workflow_step_run_id' => $step?->id,
            'ticket_id' => $run?->ticket_id,
            'actor' => $actor,
            'actor_id' => $actorId,
            'action' => $action,
            'detail' => $detail,
            'confidence' => $confidence,
        ]);
    }
}
