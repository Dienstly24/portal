<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Contract extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','contract_number','type','insurer','status','start_date','end_date','pdf_path','notes'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function vehicleDetail() { return $this->hasOne(ContractVehicleDetail::class); }
    public function energyDetail() { return $this->hasOne(ContractEnergyDetail::class); }
    public function internetDetail() { return $this->hasOne(ContractInternetDetail::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function externalReferences() { return $this->morphMany(ExternalReference::class, 'referenceable'); }
    public function documents() { return $this->hasMany(Document::class); }
}
