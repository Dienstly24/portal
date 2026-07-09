<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContractVehicleDetail extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'contract_id','license_plate','manufacturer','model','vehicle_type','vin','first_registration',
        'has_claims','claims','sf_liability_class','sf_liability_year','sf_comprehensive_class','sf_comprehensive_year',
    ];
    protected $casts = ['claims' => 'array', 'has_claims' => 'boolean', 'first_registration' => 'date'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
    public function contract() { return $this->belongsTo(Contract::class); }
}
