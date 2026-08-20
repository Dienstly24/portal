<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ein Import-Lauf der Vermittler-Abrechnung (eine hochgeladene CSV-Datei).
 * Haelt nur die Zaehler des Laufs - die Datensaetze selbst stehen in
 * vermittler_settlements und bleiben ueber Laeufe hinweg bestehen.
 */
class VermittlerImport extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'filename', 'file_hash', 'rows_total', 'rows_matched', 'rows_new_link',
        'rows_unmatched', 'rows_review', 'rows_storno', 'rows_unchanged',
        'rows_invalid', 'contracts_not_found', 'imported_by',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function settlements() { return $this->hasMany(VermittlerSettlement::class, 'import_id'); }
    public function importer() { return $this->belongsTo(User::class, 'imported_by'); }

    /** Summe der Provisionen dieses Laufs (nur die Zeilen dieses Imports). */
    public function provisionSum(): float
    {
        return (float) $this->settlements()->sum('provision');
    }
}
