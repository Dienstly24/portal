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
    ];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
    public function contract() { return $this->belongsTo(Contract::class); }
}
