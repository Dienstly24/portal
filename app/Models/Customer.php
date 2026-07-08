<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class Customer extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'user_id','customer_number','birth_date','address','address2',
        'iban','iban2','marital_status','phone','mobile','preferred_lang',
        'company_name','company_type','customer_type','email2',
        'nationality','occupation','last_contact'
    ];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function user() { return $this->belongsTo(User::class); }
    public function betreuer() { return $this->belongsToMany(User::class, 'employee_customers', 'customer_id', 'user_id'); }
    public function contracts() { return $this->hasMany(Contract::class); }
    public function tickets() { return $this->hasMany(Ticket::class); }
    public function approvalRequests() { return $this->hasMany(ApprovalRequest::class); }
    public function documents() { return $this->hasMany(Document::class); }
    public function familyMembers() { return $this->hasMany(FamilyMember::class); }
    public function family() { return $this->hasMany(CustomerFamily::class); }
    public function vehicles() { return $this->hasMany(CustomerVehicle::class); }
    public function notes() { return $this->hasMany(CustomerNote::class)->latest(); }
    public function timeline() { return $this->hasMany(CustomerTimeline::class)->latest(); }
    public function appointments() { return $this->hasMany(Appointment::class); }
}
