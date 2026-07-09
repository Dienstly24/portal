<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class CustomerFamily extends Model {
    protected $table = 'customer_family';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','name','relation','birth_date','krankenversicherung_nr','steuer_nr',
        'health_insurance_status','health_insurance_company','health_insurance_number','health_insurance_start'];

    public const HEALTH_STATUS = ['mitglied' => 'Mitglied', 'familienversichert' => 'Familienversichert'];

    /** KV-Nummer verschlüsselt (sensibles Datum). */
    protected function casts(): array {
        return ['health_insurance_number' => 'encrypted'];
    }

    public function customer() { return $this->belongsTo(Customer::class, 'customer_id'); }
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
}
