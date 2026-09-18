<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine erkannte Zeile eines hochgeladenen Verlaufs - NOCH KEINE
 * Nachricht des Kundenverlaufs.
 *
 * Erst die Bestaetigung erzeugt daraus `customer_messages` mit
 * `source = historical`, also stumm: keine KI-Antwort, kein
 * Ungelesen-Stand, kein Versand. Diese Eigenschaft ist bereits gebaut
 * und durch 14 Tests abgesichert - Phase 3 fuegt nur den Weg hinein
 * hinzu.
 */
class TrainingImportMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'training_import_id', 'row_number', 'sent_at',
        'sender_label', 'body', 'has_media_note',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'has_media_note' => 'boolean',
    ];

    /** @return BelongsTo<TrainingImport, $this> */
    public function import(): BelongsTo { return $this->belongsTo(TrainingImport::class, 'training_import_id'); }
}
