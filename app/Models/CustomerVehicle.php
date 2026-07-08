<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class CustomerVehicle extends Model {
    protected $table = 'customer_vehicles';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','brand','model','license_plate','year','vin'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function customer() { return $this->belongsTo(Customer::class); }
}
