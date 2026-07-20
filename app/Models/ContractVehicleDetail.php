<?php
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * KFZ-Detaildaten eines Vertrags (1:1). Seit dem KFZ-Redesign (17.07.2026)
 * liegen hier alle Kataloge, aus denen die Button-Oberflaeche gebaut wird:
 * Fahrzeugtyp, Deckung inkl. Selbstbeteiligung, Zusatzleistungen, Fahrerkreis,
 * Halter/Eigentum, Fahrleistung sowie die SF-Logik (Art der Einstufung,
 * tatsaechliche vs. aktuelle Klasse, Uebertragbarkeit).
 * Neue Option = hier eine Zeile ergaenzen, keine Migration noetig.
 */
class ContractVehicleDetail extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'contract_id', 'license_plate', 'manufacturer', 'model', 'vehicle_type', 'vin', 'hsn', 'tsn',
        'first_registration', 'acquisition_date', 'vehicle_condition', 'power_kw', 'fuel_type', 'transmission', 'color',
        'has_teilkasko', 'teilkasko_deductible', 'has_vollkasko', 'vollkasko_deductible',
        'extras', 'driver_groups', 'additional_drivers', 'holder_type', 'holder_name', 'ownership_type',
        'initial_mileage', 'annual_mileage',
        'previous_insurer', 'previous_insurance_since', 'previous_insurance_terminated_by_insurer',
        'sf_liability_class', 'sf_liability_valid_from', 'sf_liability_type', 'sf_liability_special_reason', 'sf_liability_real_class',
        'sf_comprehensive_class', 'sf_comprehensive_valid_from', 'sf_comprehensive_type', 'sf_comprehensive_special_reason', 'sf_comprehensive_real_class',
    ];
    protected $casts = [
        'first_registration' => 'date',
        'acquisition_date' => 'date',
        'sf_liability_valid_from' => 'date',
        'sf_comprehensive_valid_from' => 'date',
        'has_teilkasko' => 'boolean',
        'has_vollkasko' => 'boolean',
        'previous_insurance_terminated_by_insurer' => 'boolean',
        'extras' => 'array',
        'driver_groups' => 'array',
        'additional_drivers' => 'array',
    ];

    /** Fahrzeugtypen (Ein-Klick-Buttons im Formular). */
    public const VEHICLE_TYPES = [
        'pkw'         => ['label' => 'PKW',         'icon' => '🚗'],
        'wohnmobil'   => ['label' => 'Wohnmobil',   'icon' => '🚐'],
        'transporter' => ['label' => 'Transporter', 'icon' => '🚚'],
        'lkw'         => ['label' => 'LKW',         'icon' => '🚛'],
        'anhaenger'   => ['label' => 'Anhänger',    'icon' => '🛞'],
        'wohnwagen'   => ['label' => 'Wohnwagen',   'icon' => '🏕️'],
        'taxi'        => ['label' => 'Taxi',        'icon' => '🚕'],
        'mietwagen'   => ['label' => 'Mietwagen',   'icon' => '🔑'],
        'escooter'    => ['label' => 'E-Scooter',   'icon' => '🛴'],
        'sonstige'    => ['label' => 'Sonstige',    'icon' => '📋'],
    ];

    public const CONDITIONS = ['neuwagen' => 'Neuwagen', 'gebrauchtwagen' => 'Gebrauchtwagen'];

    public const FUEL_TYPES = [
        'benzin' => 'Benzin', 'diesel' => 'Diesel', 'elektro' => 'Elektro', 'hybrid' => 'Hybrid',
        'plugin_hybrid' => 'Plug-in-Hybrid', 'autogas' => 'Autogas (LPG)', 'erdgas' => 'Erdgas (CNG)',
        'wasserstoff' => 'Wasserstoff', 'sonstige' => 'Sonstige',
    ];

    public const TRANSMISSIONS = ['schaltgetriebe' => 'Schaltgetriebe', 'automatik' => 'Automatik'];

    /** Selbstbeteiligungen (EUR). 0 = ohne SB. */
    public const TK_DEDUCTIBLES = [0, 150, 300];
    public const VK_DEDUCTIBLES = [300, 500, 1000];

    /**
     * Zusatzleistungen / Zusatzbausteine. Nach dem Speichern sieht jeder
     * Mitarbeiter auf einen Blick, ob der Kunde z.B. einen Schutzbrief hat
     * (Anruf "Panne auf der Autobahn").
     */
    public const EXTRAS = [
        'schutzbrief'             => 'Schutzbrief',
        'fahrerschutz'            => 'Fahrerschutz',
        'rabattschutz'            => 'Rabattschutz',
        'verkehrsrechtsschutz'    => 'Verkehrs-Rechtsschutz',
        'insassenunfall'          => 'Insassenunfallversicherung',
        'auslandsschadenschutz'   => 'Auslandsschadenschutz',
        'gap_deckung'             => 'GAP-Deckung',
        'werkstattbindung'        => 'Werkstattbindung',
        'neupreisentschaedigung'  => 'Neupreisentschädigung',
        'kaufpreisentschaedigung' => 'Kaufpreisentschädigung',
        'grobe_fahrlaessigkeit'   => 'Schutz bei grober Fahrlässigkeit',
        'tierbiss_folgeschaeden'  => 'Tierbiss inkl. Folgeschäden',
        'marderbiss'              => 'Marderbiss',
        'glasversicherung'        => 'Glasversicherung',
        'elementarschaeden'       => 'Elementarschäden',
        'erweiterte_wildschaeden' => 'Erweiterte Wildschäden',
        'freie_werkstattwahl'     => 'Freie Werkstattwahl',
        'schluesselverlust'       => 'Schutz bei Schlüsselverlust',
        'mobilitaetsgarantie'     => 'Mobilitätsgarantie',
        'sonderausstattung'       => 'Schutz für Sonderausstattung',
        'eauto_akku'              => 'E-Auto Akkuversicherung',
        'wallbox'                 => 'Wallbox Versicherung',
        'ladekabel'               => 'Ladekabel Versicherung',
        'leasingbaustein'         => 'Leasingbaustein',
        'premiumschutz'           => 'Premiumschutz',
    ];

    /** Fahrerkreis. "weitere_fahrer" oeffnet die strukturierte Fahrerliste. */
    public const DRIVER_GROUPS = [
        'versicherungsnehmer' => 'Versicherungsnehmer',
        'fahrzeughalter'      => 'Fahrzeughalter',
        'ehepartner'          => 'Ehepartner',
        'lebenspartner'       => 'Lebenspartner',
        'kinder'              => 'Kinder',
        'zweitfahrer'         => 'Zweitfahrer',
        'weitere_fahrer'      => 'Weitere Fahrer',
    ];

    public const HOLDER_TYPES = [
        'versicherungsnehmer' => 'Versicherungsnehmer',
        'abweichender_halter' => 'Abweichender Halter',
    ];

    public const OWNERSHIP_TYPES = [
        'versicherungsnehmer' => 'Versicherungsnehmer',
        'fahrzeughalter'      => 'Fahrzeughalter',
        'leasing'             => 'Leasing',
        'finanzierung'        => 'Finanzierung',
    ];

    /** Vereinbarte Jahresfahrleistung (km) - Ein-Klick-Buttons. */
    public const ANNUAL_MILEAGE_OPTIONS = [6000, 9000, 12000, 15000, 20000, 25000, 30000, 40000, 50000];

    /** Art der SF-Einstufung. Sondereinstufungen sind NICHT uebertragbar. */
    public const SF_TYPES = [
        'tatsaechlich'      => 'Tatsächliche SF-Klasse',
        'sondereinstufung'  => 'Sondereinstufung',
    ];

    public const SF_SPECIAL_REASONS = [
        'zweitwagen'        => 'Zweitwagenregelung',
        'drittwagen'        => 'Drittwagenregelung',
        'fuehrerschein_3'   => 'Führerschein länger als 3 Jahre',
        'fuehrerschein_5'   => 'Führerschein länger als 5 Jahre',
        'sonderaktion'      => 'Sonderaktion des Versicherers',
        'familie'           => 'Übernahme innerhalb der Familie',
        'firmenfahrzeug'    => 'Firmenfahrzeug',
        'sonstige'          => 'Sonstige',
    ];

    /** Waehlbare SF-Klassen: M, S, 0, 1/2 und SF 1-50. */
    public static function sfClassKeys(): array {
        return array_merge(['M', 'S', '0', '1/2'], array_map('strval', range(1, 50)));
    }

    /** Anzeige einer SF-Klasse ("4" -> "SF 4", "M" -> "Klasse M"). */
    public static function sfLabel(?string $class): ?string {
        if ($class === null || $class === '') return null;
        return in_array($class, ['M', 'S'], true) ? 'Klasse ' . $class : 'SF ' . $class;
    }

    /** Anzeige einer Selbstbeteiligung (0 -> "ohne SB"). */
    public static function deductibleLabel(?int $value): string {
        if ($value === null) return '—';
        return $value === 0 ? 'ohne SB' : number_format($value, 0, ',', '.') . ' € SB';
    }

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function contract() { return $this->belongsTo(Contract::class); }
    public function claims() { return $this->hasMany(VehicleClaim::class)->orderByDesc('claim_date'); }
    public function mileageReadings() { return $this->hasMany(VehicleMileageReading::class)->orderByDesc('reading_date')->orderByDesc('created_at'); }
    public function sfHistory() { return $this->hasMany(VehicleSfEntry::class)->orderBy('branch')->orderByRaw('valid_from is null')->orderBy('valid_from'); }

    // ---- Anzeige-Helper ----------------------------------------------------

    public function vehicleTypeLabel(): ?string {
        if (!$this->vehicle_type) return null;
        return self::VEHICLE_TYPES[$this->vehicle_type]['label'] ?? $this->vehicle_type;
    }

    public function vehicleTypeIcon(): string {
        return self::VEHICLE_TYPES[$this->vehicle_type]['icon'] ?? '🚗';
    }

    public function fuelLabel(): ?string { return self::FUEL_TYPES[$this->fuel_type] ?? $this->fuel_type; }
    public function transmissionLabel(): ?string { return self::TRANSMISSIONS[$this->transmission] ?? $this->transmission; }
    public function conditionLabel(): ?string { return self::CONDITIONS[$this->vehicle_condition] ?? $this->vehicle_condition; }
    public function holderLabel(): ?string { return self::HOLDER_TYPES[$this->holder_type] ?? $this->holder_type; }
    public function ownershipLabel(): ?string { return self::OWNERSHIP_TYPES[$this->ownership_type] ?? $this->ownership_type; }

    /** Gewaehlte Zusatzleistungen als Labels (nur gueltige Schluessel). */
    public function extrasLabels(): array {
        return array_values(array_intersect_key(self::EXTRAS, array_flip($this->extras ?? [])));
    }

    public function hasExtra(string $key): bool {
        return in_array($key, $this->extras ?? [], true);
    }

    /** Fahrerkreis als Labels (ohne "Weitere Fahrer" - die stehen namentlich dabei). */
    public function driverGroupLabels(): array {
        return array_values(array_intersect_key(self::DRIVER_GROUPS, array_flip($this->driver_groups ?? [])));
    }

    /** Deckungs-Kurztext, z.B. "Haftpflicht · Teilkasko (150 € SB) · Vollkasko (300 € SB)". */
    public function coverageLabel(): string {
        $parts = ['Haftpflicht'];
        if ($this->has_teilkasko) {
            $parts[] = 'Teilkasko (' . self::deductibleLabel($this->teilkasko_deductible !== null ? (int) $this->teilkasko_deductible : null) . ')';
        }
        if ($this->has_vollkasko) {
            $parts[] = 'Vollkasko (' . self::deductibleLabel($this->vollkasko_deductible !== null ? (int) $this->vollkasko_deductible : null) . ')';
        }
        return implode(' · ', $parts);
    }

    /**
     * Ist die SF-Einstufung der Sparte zu einem anderen Versicherer
     * uebertragbar? Sondereinstufungen (Zweit-/Drittwagen usw.) sind es nicht -
     * dort zaehlt nur die tatsaechliche Klasse.
     */
    public function sfTransferable(string $branch): ?bool {
        $type = $branch === 'vollkasko' ? $this->sf_comprehensive_type : $this->sf_liability_type;
        $class = $branch === 'vollkasko' ? $this->sf_comprehensive_class : $this->sf_liability_class;
        if (!$class) return null;
        return $type !== 'sondereinstufung';
    }

    /** Juengster gemeldeter Kilometerstand (oder null). */
    public function latestMileageReading(): ?VehicleMileageReading {
        return $this->mileageReadings->first();
    }

    /**
     * Vergleich gefahrene km vs. vereinbarte Jahresfahrleistung.
     * Basis: Kilometerstand bei Vertragsbeginn + Vertragsbeginn-Datum
     * (ersatzweise aelteste Ablesung). Liefert null, wenn keine belastbare
     * Aussage moeglich ist, sonst u.a. 'exceeded' fuer den Mitarbeiter-Hinweis.
     */
    public function mileageStatus(): ?array {
        if (!$this->annual_mileage) return null;
        $readings = $this->mileageReadings->sortBy('reading_date')->values();
        $latest = $readings->last();
        if (!$latest) return null;

        $baseMileage = $this->initial_mileage;
        $baseDate = $this->contract?->start_date ? Carbon::parse($this->contract->start_date) : null;
        if ($baseMileage === null || !$baseDate) {
            if ($readings->count() < 2) return null;
            $first = $readings->first();
            $baseMileage = $first->mileage;
            $baseDate = Carbon::parse($first->reading_date);
        }

        $days = $baseDate->diffInDays(Carbon::parse($latest->reading_date), false);
        $driven = $latest->mileage - $baseMileage;
        if ($days < 14 || $driven < 0) return null; // zu kurzer Zeitraum / Tacho-Wechsel

        $projected = (int) round($driven / $days * 365);
        return [
            'driven'    => $driven,
            'days'      => $days,
            'projected' => $projected,
            'allowed'   => (int) $this->annual_mileage,
            'exceeded'  => $projected > (int) $this->annual_mileage,
            'latest'    => $latest,
        ];
    }
}
