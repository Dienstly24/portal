<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Wissensdatenbank-Eintrag der Workflow-Engine: eine versionierte
 * Dienstleistung (Service) innerhalb einer Sparte (Branch). Traegt die
 * generische Schritt-Liste, benoetigte Dokumente, Extraktionsfelder und
 * Intent-Beispiele. Eine neue Dienstleistung = ein neuer Datensatz, KEIN
 * neuer Kern-Code (Blueprint Saeule 1 + 9).
 *
 * Reine Definitions-Arrays (kein PII) -> `json`-Spalten + `array`-Cast.
 */
class WorkflowDefinition extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'branch', 'service_key', 'version', 'active', 'title', 'description',
        'steps', 'required_documents', 'extraction_fields', 'intent_examples',
        'confidence_threshold',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'version' => 'integer',
            'confidence_threshold' => 'integer',
            'steps' => 'array',
            'required_documents' => 'array',
            'extraction_fields' => 'array',
            'intent_examples' => 'array',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function prompts()
    {
        return $this->hasMany(WorkflowPrompt::class);
    }

    /**
     * Prompt-Vorlage eines Typs (system|intent|extraction|reply|validation)
     * oder ein Standard-Fallback. So laeuft ein Workflow auch, bevor der
     * Betreiber die Vorlagen im Admin gepflegt hat (Blueprint Saeule 7).
     */
    public function promptTemplate(string $type, ?string $default = null): ?string
    {
        return $this->prompts()->where('type', $type)->value('template') ?: $default;
    }

    public function runs()
    {
        return $this->hasMany(WorkflowRun::class);
    }

    public function scopeActive($q)
    {
        return $q->where('active', true);
    }

    /**
     * Geltende (aktive) Definition zu einem service_key oder null.
     * Bei mehreren aktiven Versionen gewinnt die hoechste (Schutz-Netz).
     */
    public static function activeFor(string $serviceKey): ?self
    {
        return static::where('service_key', $serviceKey)
            ->where('active', true)
            ->orderByDesc('version')
            ->first();
    }
}
