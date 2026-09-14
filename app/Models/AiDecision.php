<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Protokollierte KI-Entscheidung (Architekturplan Abschnitte 12/13/19).
 * Status-Workflow: suggested -> accepted | rejected (Mitarbeiter).
 * input_hash statt Klartext-Prompt (Datenminimierung); das validierte
 * Ergebnis steht in output.
 */
class AiDecision extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'email_message_id', 'document_id', 'skill', 'model', 'input_hash', 'output',
        'confidence', 'status', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            // Verschluesselt at rest: enthaelt bei Dokument-Analysen Name/
            // Kundennummer eines Matches sowie die KI-Zusammenfassung.
            'output' => 'encrypted:array',
            'decided_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    /** @return BelongsTo<EmailMessage, $this> */
    public function emailMessage(): BelongsTo { return $this->belongsTo(EmailMessage::class); }
    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }

    public function scopeSuggested($q) { return $q->where('status', 'suggested'); }
}
