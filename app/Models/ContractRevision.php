<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ein einzelner Feld-Aenderungseintrag im Verlauf eines Vertrags (Audit Log /
 * Version History). Zeigt fuer genau ein Feld den alten und den neuen Wert,
 * wann und durch wen die Aenderung erfolgte. Mehrere Aenderungen aus einem
 * Vorgang (z.B. ein importiertes Dokument) teilen sich eine batch_id.
 */
class ContractRevision extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'contract_id', 'batch_id', 'field', 'label',
        'old_value', 'new_value', 'source', 'source_document_id', 'changed_by',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /** Mitarbeiter, der die Aenderung ausgeloest hat (null = System). */
    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** Deutsches Label der Quelle fuer die Anzeige ("Dokument", "Manuell" ...). */
    public function sourceLabel(): string
    {
        return match ($this->source) {
            'document' => 'Dokument-Import',
            'import' => 'Import',
            'manual' => 'Manuell',
            default => 'System',
        };
    }

    /** Anzeigename des Bearbeiters ("System", wenn automatisch). */
    public function actorName(): string
    {
        return $this->changedBy?->name ?? 'System';
    }
}
