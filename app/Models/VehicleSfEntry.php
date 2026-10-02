<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * SF-Verlauf eines KFZ-Vertrags (Tabelle vehicle_sf_history). Je Sparte
 * (Haftpflicht/Vollkasko) wird jede Einstufung mit gueltig-ab/-bis
 * fortgeschrieben statt ueberschrieben - so bleibt die Entwicklung
 * (SF1 -> SF2 -> SF3 ...) nachvollziehbar (KFZ-Redesign 17.07.2026).
 */
class VehicleSfEntry extends Model
{
    protected $table = 'vehicle_sf_history';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['contract_vehicle_detail_id', 'branch', 'sf_class', 'special_reason', 'reference_label', 'reference_contract_id', 'valid_from', 'valid_until'];
    protected $casts = ['valid_from' => 'date', 'valid_until' => 'date'];

    public const BRANCHES = ['haftpflicht' => 'Haftpflicht', 'vollkasko' => 'Vollkasko'];

    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    /** @return BelongsTo<ContractVehicleDetail, $this> */
    public function vehicleDetail(): BelongsTo { return $this->belongsTo(ContractVehicleDetail::class, 'contract_vehicle_detail_id'); }

    /** @return BelongsTo<Contract, $this> */
    public function referenceContract(): BelongsTo { return $this->belongsTo(Contract::class, 'reference_contract_id'); }

    /** "Zweitwagenregelung · zu ADAC AD-5406305005" fuer die Spalte Grund / Bezug. */
    public function reasonText(): ?string {
        $reason = $this->special_reason ? (ContractVehicleDetail::SF_SPECIAL_REASONS[$this->special_reason] ?? $this->special_reason) : null;
        $parts = array_filter([$reason, $this->reference_label ? 'zu '.$this->reference_label : null]);
        return $parts ? implode(' · ', $parts) : null;
    }

    public function branchLabel(): string { return self::BRANCHES[$this->branch] ?? $this->branch; }
}
