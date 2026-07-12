<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class Customer extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    public const SOURCES = ['manual', 'website', 'email_import', 'fonds_finanz', 'import', 'lexoffice'];

    protected $fillable = [
        'user_id','partner_id','customer_number','source','birth_date','address','address2',
        'iban','iban2','marital_status','phone','mobile','preferred_lang',
        'company_name','company_type','customer_type','email2',
        'nationality','occupation','last_contact','gender','account_holder',
        'health_insurance_number','health_insurance_company','health_insurance_type',
        'pension_insurance_number','tax_id','birth_place',
        'address_street','address_house_number','address_house_suffix','address_zip','address_city'
    ];

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
            // Bankdaten verschlüsselt at rest (DSGVO). Anzeige bleibt maskiert.
            'iban' => 'encrypted',
            'iban2' => 'encrypted',
        ];
    }

    /**
     * Formatierte Anschrift für Listen/Übersichten. Nutzt die strukturierten
     * Adressfelder (deutscher Standard) und fällt auf das Alt-Feld `address`
     * zurück, solange ein Datensatz noch nicht migriert ist. Leerstring, wenn
     * gar keine Adresse hinterlegt ist.
     */
    public function fullAddress(): string
    {
        $street = trim(
            ($this->address_street ?? '') . ' ' . ($this->address_house_number ?? '')
            . ($this->address_house_suffix ? ' ' . $this->address_house_suffix : '')
        );
        $city = trim(($this->address_zip ?? '') . ' ' . ($this->address_city ?? ''));
        $parts = array_values(array_filter([$street, $city], fn($p) => $p !== ''));

        return $parts !== [] ? implode(', ', $parts) : trim($this->address ?? '');
    }

    /**
     * Normalisierter Adress-Schlüssel für die „Haushalt"-Zuordnung: Kunden mit
     * identischem Schlüssel gelten als derselbe Haushalt (gleiche Anschrift).
     * Leerstring, wenn keine Adresse vorhanden ist (dann kein Haushalt).
     */
    public function householdKey(): string
    {
        return \Illuminate\Support\Str::of($this->fullAddress())
            ->lower()->replaceMatches('/[^a-z0-9]+/', '')->value();
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

    /**
     * Echter Portal-Status (Admin-Kundenakte + Kundenliste).
     * Ableitung aus den Tracking-Feldern am User - keine eigene
     * Statusspalte, die aus dem Takt laufen könnte.
     */
    public function portalStatus(): array
    {
        $user = $this->user;

        if ($user === null || !$user->hasRealEmail()) {
            return ['key' => 'kein_account', 'label' => 'Kein Portal-Account', 'color' => '#A32D2D', 'bg' => '#F9E3E3'];
        }
        if (isset($user->is_active) && !$user->is_active) {
            return ['key' => 'deaktiviert', 'label' => 'Portal deaktiviert', 'color' => '#5F5E5A', 'bg' => '#EDEBE6'];
        }
        if ($user->first_login_at !== null) {
            return ['key' => 'erster_login', 'label' => 'Aktiv – Login erfolgt', 'color' => '#3B7A57', 'bg' => '#E4F0E7'];
        }
        if ($user->portal_password_set_at !== null) {
            return ['key' => 'aktiviert', 'label' => 'Aktiviert – noch kein Login', 'color' => '#185FA5', 'bg' => '#E6F1FB'];
        }
        if ($user->invitation_sent_at !== null) {
            return ['key' => 'einladung_gesendet', 'label' => 'Einladung gesendet', 'color' => '#92400E', 'bg' => '#FEF3C7'];
        }
        return ['key' => 'passwort_nicht_gesetzt', 'label' => 'Passwort nicht gesetzt', 'color' => '#92400E', 'bg' => '#FEF3C7'];
    }

    /**
     * Korrekte Briefanrede für E-Mails und Vorlagen - abgeleitet aus dem
     * Geschlecht (einzige Datenquelle, Review Punkt 1). Firmenkunden
     * (company_name gesetzt) erhalten die neutrale Form.
     */
    public function salutationLine(?string $fallbackName = null): string {
        $name = $this->user?->name ?: ($fallbackName ?? '');
        if (!empty($this->company_name)) {
            return 'Sehr geehrte Damen und Herren';
        }
        return match ($this->gender) {
            'male' => 'Sehr geehrter Herr ' . $this->lastNameOr($name),
            'female' => 'Sehr geehrte Frau ' . $this->lastNameOr($name),
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
    public function externalReferences() { return $this->morphMany(ExternalReference::class, 'referenceable'); }
    public function partner() { return $this->belongsTo(Partner::class); }
}
