<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Contract extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','contract_number','type','type_other','subtype','insurer','status','start_date','end_date','pdf_path','notes','cancellation_date'];

    /**
     * Zentrale Sparten-Definition (eine Quelle fuer alle Formulare, Listen und
     * das Kundenportal). Frueher lag die Liste an vier verschiedenen Stellen
     * verstreut und wich vom DB-Enum ab -> Anlegen scheiterte. Neue Sparte =
     * hier eine Zeile ergaenzen, keine Migration noetig (type ist string).
     */
    public const TYPES = [
        'kfz'                 => ['label' => 'KFZ',                  'icon' => '🚗', 'color' => '#185FA5', 'bg' => '#E6F1FB'],
        'krankenversicherung' => ['label' => 'Krankenversicherung', 'icon' => '🏥', 'color' => '#3B7A57', 'bg' => '#E4F0E7'],
        'leben'               => ['label' => 'Leben',               'icon' => '❤️', 'color' => '#993556', 'bg' => '#FBEAF0'],
        'haftpflicht'         => ['label' => 'Haftpflicht',         'icon' => '🛡️', 'color' => '#6D28D9', 'bg' => '#F0E6FB'],
        'hausrat'             => ['label' => 'Hausrat',             'icon' => '🏠', 'color' => '#3B7A57', 'bg' => '#E4F0E7'],
        'rechtsschutz'        => ['label' => 'Rechtsschutz',        'icon' => '⚖️', 'color' => '#92400E', 'bg' => '#FEF3C7'],
        'unfall'              => ['label' => 'Unfall',              'icon' => '🚑', 'color' => '#A32D2D', 'bg' => '#F9E3E3'],
        'sach'                => ['label' => 'Sach',                'icon' => '📦', 'color' => '#5F5E5A', 'bg' => '#EEF0F3'],
        'escooter'            => ['label' => 'E-Scooter',           'icon' => '🛴', 'color' => '#185FA5', 'bg' => '#E6F1FB'],
        'internet'            => ['label' => 'Internet & Mobilfunk','icon' => '📶', 'color' => '#6D28D9', 'bg' => '#EDE9FE'],
        'strom_gas'           => ['label' => 'Strom & Gas',         'icon' => '⚡', 'color' => '#92400E', 'bg' => '#FEF3C7'],
        'andere'              => ['label' => 'Sonstige',            'icon' => '📋', 'color' => '#5F5E5A', 'bg' => '#EEF0F3'],
    ];

    /** Gueltige Sparten-Schluessel (Validierungs-Whitelist). */
    public static function typeKeys(): array {
        return array_keys(self::TYPES);
    }

    /** Anzeige-Konfiguration (Icon/Farbe/Label) einer Sparte inkl. Fallback. */
    public function typeConfig(): array {
        return self::TYPES[$this->type] ?? self::TYPES['andere'];
    }

    /**
     * Anzeigename der Sparte. Bei "Sonstige" wird der Freitext (type_other)
     * bevorzugt, damit z.B. "ADAC Schutzbrief" statt nur "Sonstige" erscheint.
     */
    public function typeLabel(): string {
        if ($this->type === 'andere' && !empty($this->type_other)) {
            return $this->type_other;
        }
        return self::TYPES[$this->type]['label'] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    public function typeIcon(): string {
        return self::TYPES[$this->type]['icon'] ?? '📋';
    }

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
    public function vehicleDetail() { return $this->hasOne(ContractVehicleDetail::class); }
    public function energyDetail() { return $this->hasOne(ContractEnergyDetail::class); }
    public function internetDetail() { return $this->hasOne(ContractInternetDetail::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function externalReferences() { return $this->morphMany(ExternalReference::class, 'referenceable'); }
    public function documents() { return $this->hasMany(Document::class); }
    public function switchReminders() { return $this->hasMany(ContractSwitchReminder::class); }
}
