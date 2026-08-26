<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ein Import-Lauf (eine hochgeladene CSV-/Excel-Datei).
 *
 * ZWEI STUFEN: `entwurf` heisst gelesen und geprueft, aber NICHTS
 * geschrieben - erst `importiert` hat Provisionen angelegt. Der Lauf bleibt
 * in beiden Faellen stehen; auch ein verworfener Entwurf ist ein Nachweis
 * darueber, wer wann welche Datei hochgeladen hat.
 */
class CommissionImport extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const ENTWURF = 'entwurf';
    public const IMPORTIERT = 'importiert';
    public const VERWORFEN = 'verworfen';

    protected $fillable = [
        'filename', 'file_hash', 'format', 'delimiter', 'encoding', 'sheet_name',
        'sheet_names', 'header', 'column_map', 'status', 'rows_total', 'rows_new',
        'rows_updated', 'rows_duplicate', 'rows_unmatched', 'rows_invalid',
        'imported_by', 'confirmed_at',
    ];

    protected $casts = [
        'sheet_names' => 'array',
        'header' => 'array',
        'column_map' => 'array',
        'confirmed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function rows() { return $this->hasMany(CommissionImportRow::class, 'import_id'); }
    public function commissions() { return $this->hasMany(ContractCommission::class, 'import_id'); }
    public function importer() { return $this->belongsTo(User::class, 'imported_by'); }

    public function isDraft(): bool { return $this->status === self::ENTWURF; }

    /** Anzahl der Zeilen, die uebernommen wuerden (neu + aktualisiert). */
    public function applicableCount(): int
    {
        return $this->rows_new + $this->rows_updated;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::ENTWURF => 'Entwurf – noch nicht übernommen',
            self::IMPORTIERT => 'Importiert',
            self::VERWORFEN => 'Verworfen',
            default => $this->status,
        };
    }

    /** Trennzeichen lesbar benennen (ein Tabulator ist sonst unsichtbar). */
    public function delimiterLabel(): string
    {
        return match ($this->delimiter) {
            ';' => 'Semikolon ( ; )',
            ',' => 'Komma ( , )',
            "\t" => 'Tabulator',
            '|' => 'Pipe ( | )',
            null, '' => '—',
            default => $this->delimiter,
        };
    }
}
