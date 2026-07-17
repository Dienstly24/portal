<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Schadenfall eines KFZ-Vertrags (eigene Tabelle statt JSON, KFZ-Redesign
 * 17.07.2026): Datum, Art, Schadenhoehe, Status der Regulierung,
 * regulierender Versicherer und Notizen.
 */
class VehicleClaim extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'contract_vehicle_detail_id', 'claim_date', 'claim_type',
        'damage_amount', 'status', 'insurer', 'notes',
    ];
    protected $casts = ['claim_date' => 'date', 'damage_amount' => 'decimal:2'];

    public const TYPES = [
        'haftpflicht' => 'Haftpflicht',
        'teilkasko'   => 'Teilkasko',
        'vollkasko'   => 'Vollkasko',
        'sonstige'    => 'Sonstige',
    ];

    public const STATUSES = [
        'offen'          => 'Offen',
        'in_bearbeitung' => 'In Bearbeitung',
        'reguliert'      => 'Reguliert',
        'abgelehnt'      => 'Abgelehnt',
    ];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function vehicleDetail() { return $this->belongsTo(ContractVehicleDetail::class, 'contract_vehicle_detail_id'); }

    public function typeLabel(): string { return self::TYPES[$this->claim_type] ?? ($this->claim_type ?: '—'); }
    public function statusLabel(): string { return self::STATUSES[$this->status] ?? ($this->status ?: '—'); }
}
