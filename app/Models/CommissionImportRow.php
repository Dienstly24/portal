<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Eine gelesene Zeile eines Import-Laufs. Sie traegt den ROHWERT der Zellen
 * UND unsere Deutung - der Admin soll in der Vorschau sehen, was in der Datei
 * STAND, nicht nur, was wir daraus gemacht haben. Genau daran erkennt man ein
 * falsch erkanntes Trennzeichen oder eine vertauschte Spalte.
 */
class CommissionImportRow extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const NEU = 'neu';
    public const AKTUALISIERT = 'aktualisiert';
    public const DUPLIKAT = 'duplikat';
    public const NICHT_ZUGEORDNET = 'nicht_zugeordnet';
    public const FEHLERHAFT = 'fehlerhaft';

    public const RESULTS = [
        self::NEU => ['label' => 'Neu', 'badge' => 'active', 'icon' => '＋'],
        self::AKTUALISIERT => ['label' => 'Aktualisiert', 'badge' => 'open', 'icon' => '↻'],
        self::DUPLIKAT => ['label' => 'Duplikat', 'badge' => 'closed', 'icon' => '·'],
        self::NICHT_ZUGEORDNET => ['label' => 'Nicht zugeordnet', 'badge' => 'pending', 'icon' => '⚠'],
        self::FEHLERHAFT => ['label' => 'Fehlerhaft', 'badge' => 'danger', 'icon' => '✕'],
    ];

    protected $fillable = [
        'import_id', 'row_number', 'raw', 'mapped', 'result', 'message',
        'contract_id', 'customer_id', 'created_contract', 'created_customer',
        'match_reason', 'dedupe_key',
    ];

    protected $casts = [
        'raw' => 'array',
        'mapped' => 'array',
        'created_contract' => 'boolean',
        'created_customer' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function import() { return $this->belongsTo(CommissionImport::class, 'import_id'); }
    public function contract() { return $this->belongsTo(Contract::class); }
    public function customer() { return $this->belongsTo(Customer::class); }

    /**
     * Kann aus dieser nicht zugeordneten Zeile ein Vertrag entstehen? Der
     * Merker wird beim Pruefen gesetzt, damit die Vorschau die Anzahl nennen
     * kann, ohne jede Zeile erneut zu deuten.
     */
    public function isBuildable(): bool
    {
        return $this->result === self::NICHT_ZUGEORDNET && $this->match_reason === 'anlegbar';
    }

    public function resultLabel(): string { return self::RESULTS[$this->result]['label'] ?? $this->result; }
    public function resultBadge(): string { return self::RESULTS[$this->result]['badge'] ?? 'closed'; }
    public function resultIcon(): string { return self::RESULTS[$this->result]['icon'] ?? '·'; }

    /**
     * Wird diese Zeile bei der Bestaetigung uebernommen? Duplikate,
     * fehlerhafte und nicht zugeordnete Zeilen werden bewusst NICHT
     * geschrieben - sie sind kein Fehler des Admins, sondern eine Aussage
     * ueber die Datei.
     */
    public function willApply(): bool
    {
        return in_array($this->result, [self::NEU, self::AKTUALISIERT], true);
    }
}
