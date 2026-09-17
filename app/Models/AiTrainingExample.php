<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein GESCHWAERZTES Frage-Antwort-Paar aus einem echten Gespraech
 * (Auftrag Abschnitte 18-21).
 *
 * HIER STEHT NIE EIN ROHTEXT - nur die geschwaerzte Fassung. Ein
 * Datensatz, der beides enthielte, waere ein zweiter Kundendaten-
 * bestand mit eigener Loeschpflicht, und die Schwaerzung waere nur noch
 * eine Anzeige.
 *
 * NICHTS WIRD AUTOMATISCH FREIGEGEBEN (Abschnitt 19): nicht jede
 * historische Antwort war richtig, aktuell oder vollstaendig. Erst die
 * ausdrueckliche Freigabe eines Menschen macht aus einem Beispiel eine
 * Auskunft des Assistenten.
 */
class AiTrainingExample extends Model
{
    public const STATUS_OFFEN = 'offen';
    public const STATUS_FREIGEGEBEN = 'freigegeben';
    public const STATUS_ABGELEHNT = 'abgelehnt';

    public const STATUSES = [
        self::STATUS_OFFEN => 'Wartet auf Prüfung',
        self::STATUS_FREIGEGEBEN => 'Freigegeben',
        self::STATUS_ABGELEHNT => 'Abgelehnt',
    ];

    protected $fillable = [
        'training_import_id', 'conversation_id', 'question', 'answer',
        'language', 'status', 'is_holdout', 'redaction_report',
        'reviewed_by', 'reviewed_at', 'knowledge_entry_id',
    ];

    protected $casts = [
        'redaction_report' => 'array',
        'is_holdout' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<TrainingImport, $this> */
    public function import(): BelongsTo { return $this->belongsTo(TrainingImport::class, 'training_import_id'); }
    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Hat die Schwaerzung etwas gefunden, das sie NICHT zuordnen konnte?
     *
     * @return array<int,string>
     */
    public function warnings(): array
    {
        return $this->redaction_report['warnings'] ?? [];
    }

    /** @return array<string,int> */
    public function redactionCounts(): array
    {
        return $this->redaction_report['counts'] ?? [];
    }
}
