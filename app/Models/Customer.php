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
        'nationality','occupation','last_contact','gender','account_holder',
        'salutation','health_insurance_number','health_insurance_company','health_insurance_type',
        'pension_insurance_number','tax_id'
    ];

    public const SALUTATIONS = ['herr' => 'Herr', 'frau' => 'Frau', 'divers' => 'Divers', 'firma' => 'Firma'];

    /**
     * Sensible Daten (KV-Nummer, RV-Nummer, Steuer-ID) werden
     * verschlüsselt gespeichert (AES via APP_KEY). Zugriff nur über
     * autorisierte Controller; Änderungen laufen durchs Audit-Log.
     */
    protected function casts(): array {
        return [
            'health_insurance_number' => 'encrypted',
            'pension_insurance_number' => 'encrypted',
            'tax_id' => 'encrypted',
        ];
    }

    /**
     * Vollständigkeit der Kundenakte (Final Polish Punkt 5).
     * Liefert Prozent + Liste offener Punkte mit direktem Portal-Link.
     * Steuer-ID zählt als optional (nicht prozentmindernd).
     */
    public function completeness(): array
    {
        $checks = [
            ['ok' => !empty($this->health_insurance_company), 'label' => 'Krankenkasse fehlt', 'route' => 'portal.profile'],
            ['ok' => $this->family()->exists(), 'label' => 'Familienmitglieder fehlen', 'route' => 'portal.family'],
            ['ok' => !empty($this->iban), 'label' => 'Bankverbindung fehlt', 'route' => 'portal.bank'],
            ['ok' => $this->contracts()->whereHas('vehicleDetail')->exists(), 'label' => 'Fahrzeugdaten fehlen', 'route' => 'portal.contracts'],
            ['ok' => $this->contracts()->where('type', 'strom_gas')->exists(), 'label' => 'Energievertrag fehlt', 'route' => 'portal.contracts'],
            ['ok' => $this->contracts()->where('type', 'internet')->exists(), 'label' => 'Internetvertrag fehlt', 'route' => 'portal.contracts'],
        ];

        $done = count(array_filter($checks, fn($c) => $c['ok']));
        $percent = (int) round($done / count($checks) * 100);

        $missing = array_values(array_filter($checks, fn($c) => !$c['ok']));
        // Steuer-ID als optionaler Hinweis (zählt nicht in die Prozent)
        if (empty($this->tax_id)) {
            $missing[] = ['ok' => false, 'label' => 'Steuer-ID optional', 'route' => 'portal.profile', 'optional' => true];
        }

        return ['percent' => $percent, 'missing' => $missing];
    }

    /** Korrekte Briefanrede für E-Mails und Vorlagen. */
    public function salutationLine(?string $fallbackName = null): string {
        $name = $this->user?->name ?: ($fallbackName ?? '');
        return match ($this->salutation) {
            'herr' => 'Sehr geehrter Herr ' . $this->lastNameOr($name),
            'frau' => 'Sehr geehrte Frau ' . $this->lastNameOr($name),
            'firma' => 'Sehr geehrte Damen und Herren',
            default => trim($name) !== '' ? 'Guten Tag ' . $name : 'Sehr geehrte Damen und Herren',
        };
    }

    private function lastNameOr(string $fullName): string {
        $parts = preg_split('/\s+/', trim($fullName));
        return $parts ? end($parts) : $fullName;
    }

    public const GENDERS = ['male' => 'Männlich', 'female' => 'Weiblich', 'diverse' => 'Divers'];

    public function addresses() { return $this->hasMany(CustomerAddress::class, 'customer_id'); }
    public function contacts() { return $this->hasMany(CustomerContact::class, 'customer_id'); }
    public function changeRequests() { return $this->hasMany(CustomerChangeRequest::class, 'customer_id'); }
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = Str::uuid());
    }
    public function user() { return $this->belongsTo(User::class); }
    public function betreuer() { return $this->belongsToMany(User::class, 'employee_customers', 'customer_id', 'user_id'); }
    public function contracts() { return $this->hasMany(Contract::class); }
    public function tickets() { return $this->hasMany(Ticket::class); }
    public function documents() { return $this->hasMany(Document::class); }
    public function family() { return $this->hasMany(CustomerFamily::class); }
    public function vehicles() { return $this->hasMany(CustomerVehicle::class); }
    public function notes() { return $this->hasMany(CustomerNote::class)->latest(); }
    public function timeline() { return $this->hasMany(CustomerTimeline::class)->latest(); }
    public function appointments() { return $this->hasMany(Appointment::class); }
}
