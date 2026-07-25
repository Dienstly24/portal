<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContractEnergyDetail extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'contract_id','tariff','consumption_kwh','meter_number','malo_id',
        'meter_reading','grid_operator','metering_operator','payment_amount','payment_interval',
        // Kundennummer beim Energieanbieter (separat von der Vertragsnummer,
        // die am Vertrag selbst haengt) - Betreiber-Vorgabe fuer Energievertraege.
        'customer_number',
        // Vorversorger (bisheriger Lieferant beim Wechsel) + dessen Kundennummer.
        'previous_provider','previous_customer_number',
        // Tarifpreise: Arbeitspreis (ct/kWh) und Grundpreis (EUR/Monat).
        'working_price','base_price',
    ];
    protected $casts = [
        'working_price' => 'decimal:3',
        'base_price'    => 'decimal:2',
        'payment_amount'=> 'decimal:2',
    ];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
    public function contract() { return $this->belongsTo(Contract::class); }
}
