<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Ein als Duplikat entferntes Dokument, wiederherstellbar bis
 * `endgueltig_ab` (KI-128). Einzige Schreibstelle:
 * App\Services\Dokumente\DokumentDublettenService.
 *
 * @property array<string, mixed> $daten
 * @property \Illuminate\Support\Carbon $entfernt_at
 * @property \Illuminate\Support\Carbon $endgueltig_ab
 */
class DokumentPapierkorb extends Model
{
    public const AUFBEWAHRUNG_TAGE = 30;

    protected $table = 'dokument_papierkorb';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['customer_id', 'document_id', 'behalten_document_id', 'file_name', 'disk', 'file_path',
        'daten', 'grund', 'entfernt_von', 'entfernt_at', 'endgueltig_ab'];

    protected function casts(): array
    {
        return [
            'daten' => 'encrypted:array',
            'entfernt_at' => 'datetime',
            'endgueltig_ab' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->id = $m->id ?: (string) Str::uuid();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function entferntVon(): BelongsTo { return $this->belongsTo(User::class, 'entfernt_von'); }
}
