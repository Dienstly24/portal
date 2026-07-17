<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Kilometerstand-Ablesung eines KFZ-Vertrags. Jede Meldung (Mitarbeiter oder
 * Kunde ueber das Portal) wird als eigene Zeile gespeichert - der Verlauf
 * bleibt vollstaendig erhalten (KFZ-Redesign 17.07.2026).
 */
class VehicleMileageReading extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['contract_vehicle_detail_id', 'mileage', 'reading_date', 'source', 'created_by'];
    protected $casts = ['reading_date' => 'date', 'mileage' => 'integer'];

    public const SOURCES = ['staff' => 'Beraterwelt', 'customer' => 'Kundenportal'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function vehicleDetail() { return $this->belongsTo(ContractVehicleDetail::class, 'contract_vehicle_detail_id'); }

    public function sourceLabel(): string { return self::SOURCES[$this->source] ?? ($this->source ?: '—'); }
}
