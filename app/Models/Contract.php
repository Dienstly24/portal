<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Contract extends Model {
    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * Transientes Flag (nicht persistiert): true, wenn der Statuswechsel auf
     * cancelled ein NATUERLICHES Vertragsende ist (Wechsel-Kette bzw.
     * Tages-Job contracts:apply-endings). Dann KEIN Provisions-Storno - die
     * Vermittler-Provision gibt es einmalig je Verkauf und sie bleibt bei
     * regulaerem Vertragsende verdient (Betreiber-Klarstellung 26.07.2026).
     * Storno weiterhin bei Loeschung und manueller Stornierung im Formular.
     */
    public bool $endsWithoutStorno = false;
    protected $fillable = ['customer_id','contract_number','type','type_other','subtype','insurer','status','stage','start_date','end_date','pdf_path','notes','cancellation_date','premium_amount','premium_interval'];

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
        // Schutzbrief / Mobilclub-Mitgliedschaft (Betreiber-Vorgabe 28.07.2026):
        // eigene Sparte statt "Sonstige", weil viele Kunden eine ADAC-
        // Mitgliedschaft haben. Die Stufe (Basis/Plus/Premium) ist ein subtype.
        'schutzbrief'         => ['label' => 'Schutzbrief / Mobilclub', 'icon' => '🆘', 'color' => '#92400E', 'bg' => '#FEF3C7'],
        'krankenversicherung' => ['label' => 'Krankenversicherung', 'icon' => '🏥', 'color' => '#3B7A57', 'bg' => '#E4F0E7'],
        'krankenzusatz'       => ['label' => 'Krankenzusatz',       'icon' => '🩺', 'color' => '#2F8F6B', 'bg' => '#DEF1E8'],
        'leben'               => ['label' => 'Leben',               'icon' => '❤️', 'color' => '#993556', 'bg' => '#FBEAF0'],
        'haftpflicht'         => ['label' => 'Haftpflicht',         'icon' => '🛡️', 'color' => '#6D28D9', 'bg' => '#F0E6FB'],
        // GEWERBLICHE Sparten (Betreiber-Vorgabe 30.07.2026): eigene Zeilen
        // statt der privaten Sammel-Sparte "Haftpflicht". Betriebs- und
        // Frachtfuehrerhaftpflicht versichern den BETRIEB, nicht die Person -
        // andere Gesellschaften, andere Beitraege, andere Beratung. Das Flag
        // 'gewerblich' gruppiert sie in den Formularen.
        'betriebshaftpflicht' => ['label' => 'Betriebshaftpflicht', 'icon' => '🏭', 'color' => '#5B21B6', 'bg' => '#EDE9FE', 'gewerblich' => true],
        'frachtfuehrerhaftpflicht' => ['label' => 'Frachtführerhaftpflicht', 'icon' => '🚚', 'color' => '#1F4E79', 'bg' => '#E4EDF6', 'gewerblich' => true],
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

    /** Gewerbliche Sparten (versichern den Betrieb, nicht die Privatperson). */
    public static function commercialTypeKeys(): array {
        return array_keys(array_filter(self::TYPES, fn ($cfg) => !empty($cfg['gewerblich'])));
    }

    /** Ist dies ein gewerblicher Vertrag? */
    public function isCommercial(): bool {
        return !empty(self::TYPES[$this->type]['gewerblich']);
    }

    /**
     * Untergruppen (subtype-Spalte) je Sparte. Bei der Krankenversicherung
     * steuert GKV/PKV die Wechsel-Erinnerungen (§175 SGB V); die Krankenzusatz-
     * Arten sind rein beschreibend. Beim Schutzbrief/Mobilclub ist die
     * Untergruppe die Mitgliedschafts-Stufe (Namen wie beim ADAC, passen aber
     * auch fuer andere Clubs). Neue Untergruppe = hier eine Zeile ergaenzen.
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
        'schutzbrief' => [
            'basis'   => 'Basis-Mitgliedschaft',
            'plus'    => 'Plus-Mitgliedschaft',
            'premium' => 'Premium-Mitgliedschaft',
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

    /**
     * Vertrags-STUFE (stage) - Betreiber-Vorgabe 29.07.2026. Ein Vertrag
     * entsteht in zwei Schritten: zuerst der AUFTRAG/ANTRAG (viele Daten, aber
     * noch keine Bestaetigung), spaeter die VERTRAGSBESTAETIGUNG/POLICE mit
     * Vertragsnummer, Kundennummer, endgueltigem Beginn und Abschlag.
     *
     * Nur ein Vertrag der Stufe 'antrag' darf von einem spaeter hochgeladenen
     * Bestaetigungs-Dokument automatisch ERGAENZT werden (statt ein Duplikat
     * anzulegen). null = Altbestand/manuell angelegt -> die Automatik fasst
     * ihn nie an.
     */
    public const STAGE_ANTRAG = 'antrag';
    public const STAGE_VERTRAG = 'vertrag';

    public const STAGE_LABELS = [
        self::STAGE_ANTRAG  => 'Antrag – wartet auf Vertragsbestätigung',
        self::STAGE_VERTRAG => 'Vertrag bestätigt',
    ];

    /** Wartet dieser Vertrag noch auf seine Vertragsbestaetigung/Police? */
    public function isApplication(): bool {
        return $this->stage === self::STAGE_ANTRAG;
    }

    /** Anzeige-Label der Vertragsstufe (oder null bei Altbestand). */
    public function stageLabel(): ?string {
        return self::STAGE_LABELS[$this->stage] ?? null;
    }

    /** Roh-Status -> deutsches Label (eine Quelle fuer alle Listen). */
    public const STATUS_LABELS = [
        'active'    => 'Aktiv',
        'pending'   => 'In Bearbeitung',
        'cancelled' => 'Gekündigt',
        'expired'   => 'Abgelaufen',
    ];

    /**
     * Wirksames Vertragsende bei erfasster Kuendigung (Betreiber-Feedback
     * 26.07.2026): cancellation_date ist das EINREICHUNGS-Datum der
     * Kuendigung (meist "heute"), der Vertrag endet zum Ablauf (end_date).
     * Ohne Ablauf gilt das erfasste Datum selbst als Ende (Altdaten/
     * Sonderkuendigung); nie frueher als die Einreichung. Die deutsche
     * Kuendigungsfrist (KFZ: EIN MONAT zum Ablauf) prueft das Formular als
     * LIVE-HINWEIS beim Erfassen - gespeicherte Daten werden bewusst nicht
     * still "korrigiert": der Betreiber erfasst Fakten (inkl.
     * Sonderkuendigung und Wechsel-Kette), keine erfundenen Daten.
     */
    public function effectiveCancellationDate(): ?Carbon {
        if (empty($this->cancellation_date)) {
            return null;
        }
        $submitted = Carbon::parse($this->cancellation_date)->startOfDay();
        $end = $this->end_date ? Carbon::parse($this->end_date)->startOfDay() : null;
        if (!$end) {
            return $submitted;
        }
        return $end->greaterThanOrEqualTo($submitted) ? $end : $submitted;
    }

    /**
     * Zwei Versicherer-Angaben grob vergleichen ("ADAC Autoversicherung AG"
     * = "ADAC"): Kleinschreibung, Umlaute gefaltet, Rechtsform- und
     * Branchenwoerter entfernt, dann Gleichheit oder Enthaltensein. Fehlt
     * eine Angabe (oder bleibt nichts uebrig), gilt sie als passend.
     * Genutzt vom Dokumenten-Eingang (Duplikat vs. Wechsel) und der
     * Wechsel-Automatik im Admin-Formular.
     */
    public static function insurersLookAlike(?string $a, ?string $b): bool {
        $na = self::normalizeInsurerName($a);
        $nb = self::normalizeInsurerName($b);
        if ($na === '' || $nb === '') {
            return true;
        }
        if ($na === $nb) {
            return true;
        }
        // Enthaltensein (z.B. "ADAC" in "ADAC Autoversicherung AG") NUR bei
        // tragfaehiger Laenge. Ein auf 1-2 Zeichen geschrumpfter Kern - etwa
        // "R+V" -> "r", weil das Branchenwort 'v' entfaellt - ist sonst
        // Teilstring fast jedes Namens ("r" in "geneRali") und wuerde FREMDE
        // Versicherer faelschlich als gleich behandeln. Dann wuerde eine
        // Wechsel-Police den Bestandsvertrag ueberschreiben statt einen
        // eigenen Vertrag anzulegen (Audit INTAKE-1). Bei kurzem Kern zaehlt
        // nur die exakte Gleichheit oben.
        if (min(mb_strlen($na), mb_strlen($nb)) < 3) {
            return false;
        }
        return str_contains($na, $nb) || str_contains($nb, $na);
    }

    /** Versicherer-Namen auf seinen unterscheidenden Kern reduzieren. */
    private static function normalizeInsurerName(?string $name): string {
        $n = mb_strtolower(trim((string) $name));
        if ($n === '') {
            return '';
        }
        $n = strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $n = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $n);
        $stop = ['ag', 'se', 'gmbh', 'kg', 'vvag', 'a', 'g', 'e', 'v', 'co',
            'versicherung', 'versicherungs', 'versicherungen', 'versicherungsverein',
            'autoversicherung', 'krankenversicherung', 'lebensversicherung',
            'sachversicherung', 'direktversicherung', 'allgemeine', 'deutschland'];
        $words = array_filter(explode(' ', $n), fn ($w) => $w !== '' && !in_array($w, $stop, true));
        return implode(' ', $words);
    }

    /**
     * Ende des Versicherungsschutzes fuer die Doppelversicherungs-Pruefung
     * (null = offen/unbefristet):
     *  - E-Scooter enden fix zum Saisonende (bedarf keiner Kuendigung).
     *  - Erfasste Kuendigung -> wirksames Ende (effectiveCancellationDate).
     *  - Status cancelled/expired ohne Kuendigungsdatum -> Ablauf; ganz ohne
     *    Datum als beendet behandeln (blockiert nichts).
     *  - Laufender Vertrag ohne Kuendigung: verlaengert sich stillschweigend,
     *    ein blosses Ablaufdatum ist deshalb KEIN Ende -> offen.
     */
    public function coverageEndsAt(): ?Carbon {
        if ($this->type === 'escooter' && $this->end_date) {
            return Carbon::parse($this->end_date)->startOfDay();
        }
        if ($effective = $this->effectiveCancellationDate()) {
            return $effective;
        }
        if (in_array($this->status, ['cancelled', 'expired'], true)) {
            if ($this->end_date) {
                return Carbon::parse($this->end_date)->startOfDay();
            }
            return $this->start_date ? Carbon::parse($this->start_date)->startOfDay() : Carbon::today();
        }
        return null;
    }

    /**
     * Schlauer Anzeige-Status (Betreiber-Feedback 25./26.07.2026): das rohe
     * status-Feld allein greift zu kurz. Eine erfasste Kuendigung
     * (cancellation_date) erscheint als "Gekuendigt zum <wirksames Ende>"
     * (Ablauf-Logik siehe effectiveCancellationDate), ein abgeschlossener
     * Vertrag mit Beginn in der Zukunft als "Aktiv ab <Datum>". Der
     * GESPEICHERTE Status bleibt unveraendert - Statistiken, Filter und der
     * Provisions-Storno haengen daran; hier geht es nur um die Anzeige in
     * Listen und Detailseiten.
     *
     * Rueckgabe fuer die Views:
     *   key       maschinenlesbarer Zustand (active, active_upcoming,
     *             cancelled_upcoming, cancelled, pending, expired)
     *   badge     Badge-Ton der Layouts (active|open|pending|rejected|closed)
     *   label     fertiges deutsches Label ("Gekündigt zum 03.09.2026")
     *   label_key Uebersetzungs-Key fuer __() im Kundenportal (:date-Platzhalter)
     *   params    Parameter fuer __() ([] oder ['date' => '03.09.2026'])
     */
    public function displayStatus(): array {
        $today = Carbon::today();
        $start = $this->start_date ? Carbon::parse($this->start_date)->startOfDay() : null;

        // Kuendigung erfasst: zaehlt fuer laufende UND bereits auf
        // cancelled gestellte Vertraege. Angezeigt wird das WIRKSAME Ende
        // (Ablauf), nicht das Einreichungsdatum. Zukuenftiges Ende =
        // Vertrag laeuft noch bis dahin (orange), erreicht = beendet (rot).
        if (in_array($this->status, ['active', 'cancelled'], true)
            && ($ende = $this->effectiveCancellationDate())) {
            $date = $ende->format('d.m.Y');
            $upcoming = $ende->greaterThan($today);
            return [
                'key'       => $upcoming ? 'cancelled_upcoming' : 'cancelled',
                'badge'     => $upcoming ? 'pending' : 'rejected',
                'label'     => 'Gekündigt zum ' . $date,
                'label_key' => 'Gekündigt zum :date',
                'params'    => ['date' => $date],
            ];
        }

        // Abgeschlossen, aber der Beginn liegt in der Zukunft: "Aktiv ab".
        if ($this->status === 'active' && $start && $start->greaterThan($today)) {
            $date = $start->format('d.m.Y');
            return [
                'key'       => 'active_upcoming',
                'badge'     => 'open',
                'label'     => 'Aktiv ab ' . $date,
                'label_key' => 'Aktiv ab :date',
                'params'    => ['date' => $date],
            ];
        }

        $label = self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
        $badge = ['active' => 'active', 'pending' => 'pending', 'cancelled' => 'rejected', 'expired' => 'closed'][$this->status] ?? 'pending';
        return ['key' => (string) $this->status, 'badge' => $badge, 'label' => $label, 'label_key' => $label, 'params' => []];
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

        // Provisions-Management (25.07.2026): Vermittler-Provision automatisch
        // buchen - zentral am Modell, damit ALLE Anlagewege greifen (Formular,
        // Dokumenten-Eingang, Imports, CLI). Kuendigung/Loeschung erzeugt eine
        // negative Gegenbuchung statt einer Loeschung (Finanzhistorie).
        static::created(fn ($m) => app(\App\Services\Provision\ContractProvisionService::class)
            ->createForContract($m));
        static::updated(function ($m) {
            // endsWithoutStorno: natuerliches Vertragsende (Wechsel/Tages-Job)
            // laesst die einmalige Verkaufs-Provision unangetastet.
            if ($m->wasChanged('status') && $m->status === 'cancelled' && !$m->endsWithoutStorno) {
                app(\App\Services\Provision\ContractProvisionService::class)
                    ->createStornoForContract($m, 'Vertrag gekuendigt/storniert');
            }
        });
        // deleting (nicht deleted): die Provisionen referenzieren den Vertrag
        // hier noch - nach dem Loeschen setzt die DB contract_id auf null.
        static::deleting(fn ($m) => app(\App\Services\Provision\ContractProvisionService::class)
            ->createStornoForContract($m, 'Vertrag geloescht'));
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
    /** Vermittler-Provisionen dieses Vertrags (inkl. Storno-Gegenbuchungen). */
    public function provisions() { return $this->hasMany(Provision::class); }
}
