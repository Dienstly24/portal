<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Contract extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['customer_id','contract_number','type','type_other','subtype','insurer','status','start_date','end_date','pdf_path','notes','cancellation_date','premium_amount','premium_interval'];

    protected $casts = [
        'premium_amount' => 'decimal:2',
    ];

    /**
     * Zahlweisen des Beitrags mit deutschem Label und der Anzahl Zahlungen pro
     * Jahr (Basis fuer die auf den Monat normierte Statistik). Neue Stufe = hier
     * eine Zeile ergaenzen (premium_interval ist ein String, keine Migration).
     */
    public const PREMIUM_INTERVALS = [
        'monthly'    => ['label' => 'Monatlich',       'per_year' => 12],
        'quarterly'  => ['label' => 'Vierteljährlich', 'per_year' => 4],
        'semiannual' => ['label' => 'Halbjährlich',    'per_year' => 2],
        'yearly'     => ['label' => 'Jährlich',        'per_year' => 1],
        // Einmalzahlung (z.B. E-Scooter-Saisonbeitrag): faellt nur EINMAL an,
        // daher per_year = 0 -> geht nicht in die auf den Monat/das Jahr
        // normierte Beitrags-Statistik ein (kein laufender Beitrag).
        'einmalig'   => ['label' => 'Einmalig',        'per_year' => 0],
    ];

    /** Gueltige Zahlweise-Schluessel (Validierungs-Whitelist). */
    public static function premiumIntervalKeys(): array {
        return array_keys(self::PREMIUM_INTERVALS);
    }

    /** Deutsches Label der Zahlweise (z.B. "Vierteljährlich"). */
    public function premiumIntervalLabel(): string {
        return self::PREMIUM_INTERVALS[$this->premium_interval]['label']
            ?? self::PREMIUM_INTERVALS['monthly']['label'];
    }

    /** Ist ein Beitrag hinterlegt? (Betrag > 0) */
    public function hasPremium(): bool {
        return (float) $this->premium_amount > 0;
    }

    /** Einmalzahlung (z.B. E-Scooter-Saisonbeitrag) - kein laufender Beitrag. */
    public function isOneTime(): bool {
        return $this->premium_interval === 'einmalig';
    }

    /** Ist dies ein E-Scooter-Vertrag (feste Saison, Einmalbeitrag)? */
    public function isEscooter(): bool {
        return $this->type === 'escooter';
    }

    /** Auf den Monat normierter Beitrag - Basis fuer Summen/Statistik. */
    public function monthlyPremium(): float {
        if (!$this->hasPremium()) return 0.0;
        $perYear = self::PREMIUM_INTERVALS[$this->premium_interval]['per_year'] ?? 12;
        return round((float) $this->premium_amount * $perYear / 12, 2);
    }

    /** Auf das Jahr hochgerechneter Beitrag. */
    public function yearlyPremium(): float {
        if (!$this->hasPremium()) return 0.0;
        $perYear = self::PREMIUM_INTERVALS[$this->premium_interval]['per_year'] ?? 12;
        return round((float) $this->premium_amount * $perYear, 2);
    }

    /**
     * Zentrale Sparten-Definition (eine Quelle fuer alle Formulare, Listen und
     * das Kundenportal). Frueher lag die Liste an vier verschiedenen Stellen
     * verstreut und wich vom DB-Enum ab -> Anlegen scheiterte. Neue Sparte =
     * hier eine Zeile ergaenzen, keine Migration noetig (type ist string).
     */
    public const TYPES = [
        'kfz'                 => ['label' => 'KFZ',                  'icon' => '🚗', 'color' => '#185FA5', 'bg' => '#E6F1FB'],
        'krankenversicherung' => ['label' => 'Krankenversicherung', 'icon' => '🏥', 'color' => '#3B7A57', 'bg' => '#E4F0E7'],
        'krankenzusatz'       => ['label' => 'Krankenzusatz',       'icon' => '🩺', 'color' => '#2F8F6B', 'bg' => '#DEF1E8'],
        'leben'               => ['label' => 'Leben',               'icon' => '❤️', 'color' => '#993556', 'bg' => '#FBEAF0'],
        'haftpflicht'         => ['label' => 'Haftpflicht',         'icon' => '🛡️', 'color' => '#6D28D9', 'bg' => '#F0E6FB'],
        'hausrat'             => ['label' => 'Hausrat',             'icon' => '🏠', 'color' => '#3B7A57', 'bg' => '#E4F0E7'],
        'rechtsschutz'        => ['label' => 'Rechtsschutz',        'icon' => '⚖️', 'color' => '#92400E', 'bg' => '#FEF3C7'],
        'unfall'              => ['label' => 'Unfall',              'icon' => '🚑', 'color' => '#A32D2D', 'bg' => '#F9E3E3'],
        'sach'                => ['label' => 'Sach',                'icon' => '📦', 'color' => '#5F5E5A', 'bg' => '#EEF0F3'],
        'escooter'            => ['label' => 'E-Scooter',           'icon' => '🛴', 'color' => '#185FA5', 'bg' => '#E6F1FB'],
        'internet'            => ['label' => 'Internet & Mobilfunk','icon' => '📶', 'color' => '#6D28D9', 'bg' => '#EDE9FE'],
        // Strom und Gas sind getrennte Sparten (Betreiber-Vorgabe 14.07.2026):
        // je eigene Zeile, beide nutzen die Energie-Detailtabelle.
        'strom'               => ['label' => 'Strom',               'icon' => '⚡', 'color' => '#92400E', 'bg' => '#FEF3C7'],
        'gas'                 => ['label' => 'Gas',                 'icon' => '🔥', 'color' => '#B45309', 'bg' => '#FEF0E7'],
        'andere'              => ['label' => 'Sonstige',            'icon' => '📋', 'color' => '#5F5E5A', 'bg' => '#EEF0F3'],
    ];

    /**
     * Alt-Sparten, die nicht mehr auswaehlbar sind, aber in Bestandsdaten
     * vorkommen koennen (Migration teilt "strom_gas" in strom/gas auf; ein
     * Rest-Datensatz soll trotzdem sauber rendern statt auf "Sonstige" zu
     * fallen).
     */
    public const LEGACY_TYPES = [
        'strom_gas' => ['label' => 'Strom & Gas', 'icon' => '⚡', 'color' => '#92400E', 'bg' => '#FEF3C7'],
    ];

    /** Energie-Sparten (Strom, Gas + Alt-Sammelsparte) - nutzen energyDetail. */
    public const ENERGY_TYPES = ['strom', 'gas', 'strom_gas'];

    /** Gueltige Sparten-Schluessel (Validierungs-Whitelist). */
    public static function typeKeys(): array {
        return array_keys(self::TYPES);
    }

    /** Ist dies ein Energievertrag (Strom oder Gas)? */
    public function isEnergy(): bool {
        return in_array($this->type, self::ENERGY_TYPES, true);
    }

    /**
     * Untergruppen (subtype-Spalte) je Sparte. Bei der Krankenversicherung
     * steuert GKV/PKV die Wechsel-Erinnerungen (§175 SGB V); die Krankenzusatz-
     * Arten sind rein beschreibend. Neue Untergruppe = hier eine Zeile ergaenzen.
     */
    public const SUBTYPES = [
        'krankenversicherung' => [
            'gkv' => 'Gesetzlich (GKV)',
            'pkv' => 'Privat (PKV)',
        ],
        'krankenzusatz' => [
            'ambulant'        => 'Ambulante Zusatzversicherung',
            'zahnzusatz'      => 'Zahnzusatzversicherung',
            'auslandskranken' => 'Auslandskrankenversicherung',
        ],
    ];

    /** Sparten, die eine Untergruppe (subtype) fuehren. */
    public static function typesWithSubtype(): array {
        return array_keys(self::SUBTYPES);
    }

    /** Alle gueltigen subtype-Schluessel ueber alle Sparten (Validierung). */
    public static function subtypeKeys(): array {
        return array_merge(...array_map('array_keys', array_values(self::SUBTYPES)));
    }

    /**
     * Liefert den subtype-Wert nur zurueck, wenn er zur Sparte passt - sonst
     * null. So kann kein "gkv" an einem Krankenzusatz-Vertrag haengen bleiben.
     */
    public static function normalizeSubtype(?string $type, ?string $subtype): ?string {
        return isset(self::SUBTYPES[$type][$subtype]) ? $subtype : null;
    }

    /** Anzeige-Label der Untergruppe (z.B. "Zahnzusatzversicherung"), sonst null. */
    public function subtypeLabel(): ?string {
        return self::SUBTYPES[$this->type][$this->subtype] ?? null;
    }

    /** Anzeige-Konfiguration (Icon/Farbe/Label) einer Sparte inkl. Fallback. */
    public function typeConfig(): array {
        return self::TYPES[$this->type] ?? self::LEGACY_TYPES[$this->type] ?? self::TYPES['andere'];
    }

    /**
     * Anzeigename der Sparte. Bei "Sonstige" wird der Freitext (type_other)
     * bevorzugt, damit z.B. "ADAC Schutzbrief" statt nur "Sonstige" erscheint.
     */
    public function typeLabel(): string {
        if ($this->type === 'andere' && !empty($this->type_other)) {
            return $this->type_other;
        }
        return self::TYPES[$this->type]['label']
            ?? self::LEGACY_TYPES[$this->type]['label']
            ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    public function typeIcon(): string {
        return self::TYPES[$this->type]['icon'] ?? self::LEGACY_TYPES[$this->type]['icon'] ?? '📋';
    }

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());

        // E-Scooter: feste Fachregeln zentral erzwingen - egal woher der Vertrag
        // kommt (Formular, Dokumenten-Eingang, Import). Der Vertrag endet immer
        // am Ende der Saison (Ende Februar, "bedarf keiner Kuendigung") und der
        // Beitrag ist eine Einmalzahlung. So bleibt der Ablauf bei jedem Wechsel
        // des Beginns korrekt und muss nirgends von Hand nachgezogen werden.
        static::saving(function ($m) {
            if ($m->type === 'escooter') {
                if (!empty($m->start_date)) {
                    $m->end_date = \App\Support\EscooterInsurance::seasonEndDate($m->start_date);
                }
                if (empty($m->premium_interval)) {
                    $m->premium_interval = 'einmalig';
                }
            }
        });
    }
    public function vehicleDetail() { return $this->hasOne(ContractVehicleDetail::class); }
    public function energyDetail() { return $this->hasOne(ContractEnergyDetail::class); }
    public function internetDetail() { return $this->hasOne(ContractInternetDetail::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function externalReferences() { return $this->morphMany(ExternalReference::class, 'referenceable'); }
    public function documents() { return $this->hasMany(Document::class); }
    public function switchReminders() { return $this->hasMany(ContractSwitchReminder::class); }
    /** Feld-genaue Aenderungshistorie (Audit Log), neueste zuerst. */
    public function revisions() { return $this->hasMany(ContractRevision::class)->orderByDesc('created_at'); }
}
