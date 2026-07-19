<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class CustomerFamily extends Model {
    protected $table = 'customer_family';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','name','relation','birth_date',
        'health_insurance_status','health_insurance_company','health_insurance_number','health_insurance_start',
        'gender','pension_insurance_number','tax_id','birth_place'];

    public const HEALTH_STATUS = ['mitglied' => 'Mitglied', 'familienversichert' => 'Familienversichert'];
    public const GENDERS = ['male' => 'Männlich', 'female' => 'Weiblich'];

    /** KV-Nummer verschlüsselt (sensibles Datum). */
    protected function casts(): array {
        return ['health_insurance_number' => 'encrypted', 'pension_insurance_number' => 'encrypted', 'tax_id' => 'encrypted'];
    }

    public function customer() { return $this->belongsTo(Customer::class, 'customer_id'); }
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
}
