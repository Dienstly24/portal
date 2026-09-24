<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Eine hochgeladene Rechnung/Gutschrift des Vermittlers (TARIFCHECK24) - der
 * BELEG, dass eine Provision gezahlt wurde (Betreiber-Auftrag 23.09.2026).
 *
 * `status`: entwurf (gelesen, noch nichts geschrieben) -> bestaetigt (die
 * Ergebnisse stehen an den Abrechnungsdatensaetzen und Vertraegen).
 * `result` traegt je gefundener Position das Ergebnis des Abgleichs, damit
 * die Vorschau genau das zeigt, was die Bestaetigung spaeter schreibt.
 */
class VermittlerInvoice extends Model
{
    public const STATUS_ENTWURF = 'entwurf';
    public const STATUS_BESTAETIGT = 'bestaetigt';

    /** Ergebnis je Position. */
    public const OUTCOMES = [
        'bestaetigt' => ['label' => 'Betrag stimmt – Zahlung belegt', 'icon' => '✅', 'badge' => 'active'],
        'abweichung' => ['label' => 'Betrag weicht ab – Prüfung', 'icon' => '⚠', 'badge' => 'danger'],
        'storniert' => ['label' => 'In der Rechnung, aber storniert – Prüfung', 'icon' => '⛔', 'badge' => 'danger'],
        'ohne_betrag' => ['label' => 'Gefunden, Betrag nicht eindeutig lesbar', 'icon' => '❓', 'badge' => 'pending'],
        'ohne_vergleich' => ['label' => 'Gefunden, aber in der CSV steht keine Provision zum Vergleich', 'icon' => '❓', 'badge' => 'pending'],
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'filename', 'file_path', 'file_hash', 'invoice_number', 'invoice_date', 'total_amount',
        'status', 'source', 'rows_found', 'rows_confirmed', 'rows_deviation', 'rows_open',
        'result', 'uploaded_by', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'total_amount' => 'decimal:2',
        'result' => 'array',
        'confirmed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by'); }
    /** @return HasMany<VermittlerSettlement, $this> */
    public function settlements(): HasMany { return $this->hasMany(VermittlerSettlement::class, 'invoice_id'); }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_ENTWURF;
    }

    public static function outcomeLabel(string $outcome): string
    {
        return self::OUTCOMES[$outcome]['label'] ?? $outcome;
    }

    public static function outcomeIcon(string $outcome): string
    {
        return self::OUTCOMES[$outcome]['icon'] ?? '·';
    }

    public static function outcomeBadge(string $outcome): string
    {
        return self::OUTCOMES[$outcome]['badge'] ?? 'closed';
    }
}
