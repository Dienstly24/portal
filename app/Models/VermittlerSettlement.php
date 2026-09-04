<?php

namespace App\Models;

use App\Services\Vermittler\VermittlerStatusMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ein Datensatz aus der Abrechnung des Vermittlers (eine CSV-Zeile).
 *
 * Natuerlicher Schluessel ist die `Id` des Vermittlers (unique) - ein erneuter
 * Import derselben Datei aktualisiert die Zeile, er legt sie NIE doppelt an.
 * Die Zeile bleibt erhalten, auch wenn der zugeordnete Vertrag spaeter
 * geloescht wird (contract_id wird null, contract_label/customer_label
 * bewahren die Zuordnung lesbar).
 */
class VermittlerSettlement extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'import_id', 'vermittler_id', 'produkt', 'statement_date', 'status_code',
        'provision', 'tracking_id', 'storno_reason', 'reference_number',
        'reference_key', 'contract_id', 'customer_id', 'contract_label',
        'customer_label', 'match_result', 'import_result', 'match_note', 'row_hash',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'provision' => 'decimal:2',
    ];

    /**
     * Ergebnis der Zuordnung je Zeile. Bewusst getrennt vom
     * Abrechnungsstatus des Vertrags: hier steht, ob wir den Vertrag
     * GEFUNDEN haben - dort, was der Vermittler mit ihm gemacht hat.
     */
    public const RESULTS = [
        'matched' => ['label' => 'Zugeordnet', 'icon' => '✓', 'badge' => 'active'],
        'linked' => ['label' => 'Neu zugeordnet', 'icon' => '🔗', 'badge' => 'open'],
        'unmatched' => ['label' => 'ID nicht gefunden', 'icon' => '⚠', 'badge' => 'pending'],
        'review' => ['label' => 'Prüfung erforderlich', 'icon' => '⚠', 'badge' => 'danger'],
        'unchanged' => ['label' => 'Bereits importiert', 'icon' => '·', 'badge' => 'closed'],
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function contract() { return $this->belongsTo(Contract::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function import() { return $this->belongsTo(VermittlerImport::class, 'import_id'); }

    /** Ergebnis des letzten Imports (ersatzweise der dauerhafte Zustand). */
    public function importResult(): string
    {
        return $this->import_result ?: $this->match_result;
    }

    public function resultLabel(): string
    {
        return self::RESULTS[$this->importResult()]['label'] ?? $this->importResult();
    }

    public function resultIcon(): string
    {
        return self::RESULTS[$this->importResult()]['icon'] ?? '·';
    }

    public function resultBadge(): string
    {
        return self::RESULTS[$this->importResult()]['badge'] ?? 'closed';
    }

    /** Deutsches Label des Vermittler-Status (aus der Status-Zuordnung). */
    public function statusLabel(): string
    {
        return Contract::VERMITTLER_STATUSES[$this->contractStatus()]['label'] ?? '–';
    }

    /** Abrechnungsstatus, den dieser Datensatz fuer den Vertrag bedeutet. */
    public function contractStatus(): string
    {
        return VermittlerStatusMap::forCode($this->status_code);
    }

    /** Ist dieser Datensatz storniert? */
    public function isStorno(): bool
    {
        return $this->contractStatus() === Contract::VERMITTLER_STORNIERT;
    }

    /**
     * Braucht dieser Datensatz eine Mitarbeiter-Entscheidung? Bewusst NICHT
     * "needsReview" - dieser Name gehoert dem Query-Scope, ein gleichnamiger
     * Instanz-Aufruf wuerde ihn verschatten.
     */
    public function requiresDecision(): bool
    {
        return in_array($this->match_result, ['unmatched', 'review'], true);
    }

    public function scopeNeedsReview($q)
    {
        return $q->whereIn('match_result', ['unmatched', 'review']);
    }
}
