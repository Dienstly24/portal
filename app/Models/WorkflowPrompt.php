<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Editierbare Prompt-Vorlage je Definition + Typ (system, intent,
 * extraction, reply, validation). Aus dem Admin pflegbar, kein Deploy noetig
 * (Blueprint Saeule 7). Der Text ist keine Kunden-PII, daher unverschluesselt.
 */
class WorkflowPrompt extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPES = ['system', 'intent', 'extraction', 'reply', 'validation'];

    protected $fillable = [
        'workflow_definition_id', 'type', 'template',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function definition()
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
