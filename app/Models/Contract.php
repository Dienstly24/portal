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
    protected $fillable = ['customer_id','contract_number','internal_contract_number','commission_import_id','reference_number','vermittler_id','vermittler_status','vermittler_matched_at','vermittler_last_import_id','vermittler_last_imported_at','type','type_other','subtype','insurer','status','stage','start_date','end_date','pdf_path','notes','cancellation_date','premium_amount','premium_interval'];

    protected $casts = [
        'premium_amount' => 'decimal:2',
        'vermittler_matched_at' => 'datetime',
        'vermittler_last_imported_at' => 'datetime',
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

    /**
     * Gespeicherte Status-Werte. Vier Zustaende, fachlich in DREI Gruppen
     * (siehe GROUP_*): aktiver Bestand, Anbahnung, Historie.
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    /** Roh-Status -> deutsches Label (eine Quelle fuer alle Listen). */
    public const STATUS_LABELS = [
        self::STATUS_ACTIVE    => 'Aktiv',
        self::STATUS_PENDING   => 'In Bearbeitung',
        self::STATUS_CANCELLED => 'Gekündigt',
        self::STATUS_EXPIRED   => 'Abgelaufen',
    ];

    /**
     * BESTANDSGRUPPEN (Betreiber-Vorgabe 17.08.2026, die eine Quelle fuer
     * "aktiv" im ganzen System). Der rohe status-Wert allein genuegt nicht:
     * ein Vertrag mit status=active, dessen wirksames Ende erreicht ist
     * (Kuendigung zum X, E-Scooter-Saisonende), ist FAKTISCH beendet, auch
     * wenn der Tages-Job contracts:apply-endings den Status erst nachts
     * nachzieht. Deshalb entscheidet immer statusGroup()/isCurrentlyActive()
     * - nie ein direkter Vergleich status === 'active'.
     *
     *   GROUP_ACTIVE   aktueller Bestand: laeuft heute oder ist ab einem
     *                  Zukunftstag abgeschlossen ("Aktiv ab ..."), auch mit
     *                  Kuendigung zu einem noch nicht erreichten Ende.
     *   GROUP_PENDING  Anbahnung ("In Bearbeitung") - noch KEIN Bestand,
     *                  aber auch keine Historie.
     *   GROUP_HISTORY  Historie: gekuendigt, abgelaufen, beendet. Zaehlt
     *                  NIE zur Vertragsstruktur und nie zu den aktiven
     *                  Vertraegen.
     */
    public const GROUP_ACTIVE = 'aktiv';
    public const GROUP_PENDING = 'anbahnung';
    public const GROUP_HISTORY = 'historie';

    /** Status, die (bei laufender Deckung) zum aktiven Bestand zaehlen. */
    public const ACTIVE_STATUSES = [self::STATUS_ACTIVE];

    /** Status, die immer Historie sind - unabhaengig von jedem Datum. */
    public const HISTORIC_STATUSES = [self::STATUS_CANCELLED, self::STATUS_EXPIRED];

    /** Anzeige-Namen der Bestandsgruppen (Filter, Tabs, Ueberschriften). */
    public const GROUP_LABELS = [
        self::GROUP_ACTIVE  => 'Aktiver Bestand',
        self::GROUP_PENDING => 'In Bearbeitung',
        self::GROUP_HISTORY => 'Beendet / Historie',
    ];

    /**
     * Status-Auswahl im Vertragsformular: sprechendes Label + Erklaerung, was
     * der Status fachlich bedeutet. Bewusst getrennt von STATUS_LABELS - in
     * Listen soll das Badge kurz bleiben ("Gekündigt"), in der AUSWAHL muss
     * dagegen unmissverstaendlich sein, was der Status ausloest (Vertrag
     * verlaesst den aktiven Bestand bzw. die Vertragsstruktur).
     */
    public const STATUS_OPTIONS = [
        self::STATUS_ACTIVE => [
            'label' => 'Aktiv – laufender Vertrag',
            'group' => self::GROUP_ACTIVE,
            'hint'  => 'Zaehlt zum aktiven Bestand und erscheint in der Vertragsstruktur.',
        ],
        self::STATUS_PENDING => [
            'label' => 'In Bearbeitung – noch nicht aktiv',
            'group' => self::GROUP_PENDING,
            'hint'  => 'Angebot/Antrag in Arbeit: zaehlt NICHT zum aktiven Bestand.',
        ],
        self::STATUS_CANCELLED => [
            'label' => 'Inaktiv / Gekündigt',
            'group' => self::GROUP_HISTORY,
            'hint'  => 'Beendet durch Kuendigung: nur noch in der Historie sichtbar.',
        ],
        self::STATUS_EXPIRED => [
            'label' => 'Beendet / Abgelaufen',
            'group' => self::GROUP_HISTORY,
            'hint'  => 'Laufzeit beendet: nur noch in der Historie sichtbar.',
        ],
    ];

    /** Gueltige Status-Schluessel (Validierungs-Whitelist). */
    public static function statusKeys(): array {
        return array_keys(self::STATUS_OPTIONS);
    }

    /**
     * Ist die Deckung an diesem Tag (Standard: heute) beendet? Die EINE
     * Datums-Regel hinter isCurrentlyActive(), scopeHistoric() und dem
     * Tages-Job contracts:apply-endings:
     *  - Status cancelled/expired -> immer beendet.
     *  - Erfasste Kuendigung -> beendet, sobald das WIRKSAME Ende erreicht
     *    ist (effectiveCancellationDate, Tag des Endes zaehlt als beendet -
     *    wie displayStatus() und der Tages-Job).
     *  - E-Scooter -> beendet NACH dem Saisonende (Ablauftag selbst laeuft
     *    noch, identisch zur Regel in contracts:apply-endings).
     *  - Sonst offen: Versicherungen verlaengern sich stillschweigend, ein
     *    blosses Ablaufdatum ist KEIN Ende (Betreiber-Vorgabe 26.07.2026).
     */
    public function hasCoverageEnded(?Carbon $on = null): bool {
        $on = ($on ? $on->copy() : Carbon::today())->startOfDay();
        if (in_array($this->status, self::HISTORIC_STATUSES, true)) {
            return true;
        }
        if ($ende = $this->effectiveCancellationDate()) {
            return $ende->lessThanOrEqualTo($on);
        }
        if ($this->isEscooter() && $this->end_date) {
            return Carbon::parse($this->end_date)->startOfDay()->lessThan($on);
        }
        return false;
    }

    /**
     * DIE Definition von "aktiv" (Vertragsstruktur, Zaehler, Kennzahlen):
     * Status aktiv UND Deckung noch nicht beendet. Ein Vertrag mit Beginn in
     * der Zukunft ("Aktiv ab ...") gehoert dazu - er ist abgeschlossen und
     * die aktuelle Kundenbeziehung dieser Sparte.
     */
    public function isCurrentlyActive(): bool {
        return in_array($this->status, self::ACTIVE_STATUSES, true) && !$this->hasCoverageEnded();
    }

    /** Historie: gekuendigt/abgelaufen/beendet - nie Teil der Struktur. */
    public function isHistoric(): bool {
        return $this->statusGroup() === self::GROUP_HISTORY;
    }

    /** In Anbahnung ("In Bearbeitung") - noch kein Bestand. */
    public function isPendingStatus(): bool {
        return $this->status === self::STATUS_PENDING;
    }

    /** Bestandsgruppe dieses Vertrags (GROUP_ACTIVE|GROUP_PENDING|GROUP_HISTORY). */
    public function statusGroup(): string {
        if ($this->isPendingStatus()) {
            return self::GROUP_PENDING;
        }
        return $this->isCurrentlyActive() ? self::GROUP_ACTIVE : self::GROUP_HISTORY;
    }

    /** Anzeige-Name der Bestandsgruppe ("Aktiver Bestand", ...). */
    public function statusGroupLabel(): string {
        return self::GROUP_LABELS[$this->statusGroup()];
    }

    /**
     * Query-Fassung von isCurrentlyActive() - Wort fuer Wort dieselbe Regel
     * (Datenbank statt PHP), damit Listen, Filter und Kennzahlen nie von der
     * Anzeige abweichen. Nutzung: Contract::currentlyActive(),
     * $customer->contracts()->currentlyActive(), whereHas('contracts', fn($q)
     * => $q->currentlyActive()).
     */
    public function scopeCurrentlyActive($query) {
        $today = Carbon::today()->toDateString();
        return $query->whereIn('status', self::ACTIVE_STATUSES)
            // Keine Kuendigung ODER wirksames Ende noch nicht erreicht.
            // Wirksames Ende = max(end_date, cancellation_date), also noch
            // offen, sobald EINES der beiden Daten in der Zukunft liegt.
            ->where(function ($w) use ($today) {
                $w->whereNull('cancellation_date')
                    ->orWhereDate('cancellation_date', '>', $today)
                    ->orWhere(fn ($x) => $x->whereNotNull('end_date')->whereDate('end_date', '>', $today));
            })
            // E-Scooter: nach dem Saisonende nicht mehr aktiv.
            ->where(function ($w) use ($today) {
                $w->where('type', '!=', 'escooter')
                    ->orWhereNull('end_date')
                    ->orWhereDate('end_date', '>=', $today);
            });
    }

    /**
     * Query-Fassung von isHistoric(): gekuendigt/abgelaufen ODER Status noch
     * aktiv, aber das wirksame Ende ist erreicht (Tages-Job zieht den Status
     * erst nachts nach). "In Bearbeitung" ist bewusst NICHT enthalten.
     */
    public function scopeHistoric($query) {
        $today = Carbon::today()->toDateString();
        return $query->where(function ($q) use ($today) {
            $q->whereIn('status', self::HISTORIC_STATUSES)
                // Gekuendigt, wirksames Ende (max aus beiden Daten) erreicht.
                ->orWhere(fn ($w) => $w->whereIn('status', self::ACTIVE_STATUSES)
                    ->whereNotNull('cancellation_date')
                    ->whereDate('cancellation_date', '<=', $today)
                    ->where(fn ($e) => $e->whereNull('end_date')->orWhereDate('end_date', '<=', $today)))
                // E-Scooter nach Saisonende.
                ->orWhere(fn ($w) => $w->whereIn('status', self::ACTIVE_STATUSES)
                    ->where('type', 'escooter')
                    ->whereNotNull('end_date')
                    ->whereDate('end_date', '<', $today));
        });
    }

    /** Query-Fassung von isPendingStatus() ("In Bearbeitung"). */
    public function scopeInProgress($query) {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Freitextsuche ueber die Vertragsliste - DIESELBEN Felder, die die
     * Liste anzeigt: Gesellschaft, Vertragsnummer, Kundenname, Kundennummer.
     *
     * Frueher lief diese Suche im Browser ueber die bereits geladenen
     * Zeilen. Das setzte voraus, dass ALLE Vertraege im HTML stehen - genau
     * die Annahme, die mit wachsendem Bestand die Seite umbringt. Deshalb
     * sucht jetzt die Datenbank.
     *
     * Mehrere Woerter werden UND-verknuepft (jedes Wort muss irgendwo
     * treffen); %/_ werden maskiert, damit eine Nutzereingabe keine
     * LIKE-Platzhalter erzeugt.
     */
    public function scopeSearch($query, ?string $term) {
        $term = trim((string) ($term ?? ''));
        if ($term === '') {
            return $query;
        }

        foreach (preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [$term] as $token) {
            $like = '%' . addcslashes($token, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('insurer', 'like', $like)
                    ->orWhere('contract_number', 'like', $like)
                    // Vermittler-Kennungen sind fuer den Betrieb echte
                    // Suchbegriffe: die Referenz-Nr. steht auf der
                    // Antragsbestaetigung, die Vermittler-ID in der
                    // Abrechnung - beide fuehren zum selben Vertrag.
                    ->orWhere('reference_number', 'like', $like)
                    ->orWhere('vermittler_id', 'like', $like)
                    // Die INTERNE Vertragsnummer des Fremdsystems steht auf
                    // jeder Abrechnung und auf jeder spaeteren Rechnung -
                    // ohne sie in der Suche waere die Bruecke nur halb da.
                    ->orWhere('internal_contract_number', 'like', $like)
                    ->orWhereHas('customer', function ($c) use ($like) {
                        $c->where('customer_number', 'like', $like)
                            ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like));
                    });
            });
        }

        return $query;
    }

    /** Eine Bestandsgruppe als Query-Filter (GROUP_*); alles andere = kein Filter. */
    public function scopeStatusGroup($query, ?string $group) {
        return match ($group) {
            self::GROUP_ACTIVE  => $query->currentlyActive(),
            self::GROUP_PENDING => $query->inProgress(),
            self::GROUP_HISTORY => $query->historic(),
            default             => $query,
        };
    }

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
        if ($this->isEscooter() && $this->end_date) {
            return Carbon::parse($this->end_date)->startOfDay();
        }
        if ($effective = $this->effectiveCancellationDate()) {
            return $effective;
        }
        if (in_array($this->status, self::HISTORIC_STATUSES, true)) {
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
     *   group     Bestandsgruppe (GROUP_ACTIVE|GROUP_PENDING|GROUP_HISTORY) -
     *             identisch zu statusGroup(), damit Badge und Vertragsstruktur
     *             NIE gegensaetzliche Aussagen treffen koennen
     *   historic  true = Historie (beendet), zaehlt nicht zum aktiven Bestand
     */
    public function displayStatus(): array {
        $today = Carbon::today();
        $start = $this->start_date ? Carbon::parse($this->start_date)->startOfDay() : null;
        // Die Gruppe kommt IMMER aus derselben Quelle wie die Vertragsstruktur.
        $group = $this->statusGroup();
        $with = fn (array $st) => $st + ['group' => $group, 'historic' => $group === self::GROUP_HISTORY];

        // Kuendigung erfasst: zaehlt fuer laufende UND bereits auf
        // cancelled gestellte Vertraege. Angezeigt wird das WIRKSAME Ende
        // (Ablauf), nicht das Einreichungsdatum. Zukuenftiges Ende =
        // Vertrag laeuft noch bis dahin (orange), erreicht = beendet (rot).
        if (in_array($this->status, ['active', 'cancelled'], true)
            && ($ende = $this->effectiveCancellationDate())) {
            $date = $ende->format('d.m.Y');
            $upcoming = $ende->greaterThan($today);
            return $with([
                'key'       => $upcoming ? 'cancelled_upcoming' : 'cancelled',
                'badge'     => $upcoming ? 'pending' : 'rejected',
                'label'     => 'Gekündigt zum ' . $date,
                'label_key' => 'Gekündigt zum :date',
                'params'    => ['date' => $date],
            ]);
        }

        // Erreichtes Ende trotz Status "aktiv" (E-Scooter nach Saisonende;
        // der Tages-Job contracts:apply-endings zieht den Status erst nachts
        // nach): NIE als "Aktiv" anzeigen - sonst behauptet das Badge einen
        // aktiven Vertrag, den die Vertragsstruktur zu Recht nicht mehr
        // fuehrt (Inkonsistenz Anzeige vs. Datenlogik).
        if ($this->status === self::STATUS_ACTIVE && $this->hasCoverageEnded($today)) {
            $ende = $this->coverageEndsAt();
            $date = $ende?->format('d.m.Y');
            return $with([
                'key'       => 'expired',
                'badge'     => 'closed',
                'label'     => $date ? 'Abgelaufen am ' . $date : 'Abgelaufen',
                'label_key' => $date ? 'Abgelaufen am :date' : 'Abgelaufen',
                'params'    => $date ? ['date' => $date] : [],
            ]);
        }

        // Abgeschlossen, aber der Beginn liegt in der Zukunft: "Aktiv ab".
        if ($this->status === 'active' && $start && $start->greaterThan($today)) {
            $date = $start->format('d.m.Y');
            return $with([
                'key'       => 'active_upcoming',
                'badge'     => 'open',
                'label'     => 'Aktiv ab ' . $date,
                'label_key' => 'Aktiv ab :date',
                'params'    => ['date' => $date],
            ]);
        }

        $label = self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
        $badge = ['active' => 'active', 'pending' => 'pending', 'cancelled' => 'rejected', 'expired' => 'closed'][$this->status] ?? 'pending';
        return $with(['key' => (string) $this->status, 'badge' => $badge, 'label' => $label, 'label_key' => $label, 'params' => []]);
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

    // ---------------------------------------------------------------
    // Vermittler-Abrechnung (Betreiber-Auftrag 20.08.2026)
    //
    // Zusatzschicht ueber dem Vertrag: sie beantwortet die Frage "hat der
    // Vermittler diesen Vertrag bestaetigt und abgerechnet?" - und aendert
    // dabei NIE den fachlichen Vertragsstatus (status/stage). Beides sind
    // getrennte Wahrheiten und duerfen sich nie gegenseitig ueberschreiben.
    // ---------------------------------------------------------------

    public const VERMITTLER_NEU = 'neu';
    public const VERMITTLER_REFERENZ = 'referenz_hinterlegt';
    public const VERMITTLER_ID_ZUGEORDNET = 'id_zugeordnet';
    public const VERMITTLER_IN_ABRECHNUNG = 'in_abrechnung';
    public const VERMITTLER_ABGERECHNET = 'abgerechnet';
    public const VERMITTLER_STORNIERT = 'storniert';
    public const VERMITTLER_NICHT_GEFUNDEN = 'nicht_gefunden';
    public const VERMITTLER_PRUEFUNG = 'pruefung';

    /** Abrechnungsstatus mit deutschem Label und Anzeige-Merkmalen. */
    public const VERMITTLER_STATUSES = [
        self::VERMITTLER_NEU            => ['label' => 'Neu',                        'icon' => '·',  'badge' => 'closed'],
        self::VERMITTLER_REFERENZ       => ['label' => 'Referenz hinterlegt',        'icon' => '📌', 'badge' => 'open'],
        self::VERMITTLER_ID_ZUGEORDNET  => ['label' => 'ID zugeordnet',              'icon' => '🔗', 'badge' => 'open'],
        self::VERMITTLER_IN_ABRECHNUNG  => ['label' => 'In Abrechnung gefunden',     'icon' => '✓',  'badge' => 'active'],
        self::VERMITTLER_ABGERECHNET    => ['label' => 'Bestätigt / Abgerechnet',    'icon' => '✅', 'badge' => 'active'],
        self::VERMITTLER_STORNIERT      => ['label' => 'Storniert',                  'icon' => '⛔', 'badge' => 'danger'],
        self::VERMITTLER_NICHT_GEFUNDEN => ['label' => 'Nicht in Abrechnung gefunden','icon' => '❓', 'badge' => 'pending'],
        self::VERMITTLER_PRUEFUNG       => ['label' => 'Prüfung erforderlich',       'icon' => '⚠',  'badge' => 'danger'],
    ];

    /**
     * Zustaende VOR dem ersten Treffer in einer Abrechnung. Nur solche
     * Vertraege duerfen ein "Nicht in Abrechnung gefunden" bekommen - ein
     * bereits abgerechneter oder stornierter Vertrag wird von einem spaeteren
     * Lauf nie mehr zurueckgestuft.
     */
    public const VERMITTLER_PRE_MATCH = [
        self::VERMITTLER_NEU,
        self::VERMITTLER_REFERENZ,
        self::VERMITTLER_NICHT_GEFUNDEN,
    ];

    public static function vermittlerStatusKeys(): array
    {
        return array_keys(self::VERMITTLER_STATUSES);
    }

    /**
     * Aktueller Abrechnungsstatus - nie null. Ohne gespeicherten Wert wird er
     * aus dem Bestand abgeleitet: mit hinterlegter Referenz-Nr. gilt der
     * Vertrag als "Referenz hinterlegt", sonst als "Neu". So stimmt die
     * Anzeige auch fuer Altvertraege, ohne dass eine Migration Daten erfindet.
     */
    public function vermittlerStatus(): string
    {
        if ($this->vermittler_status && isset(self::VERMITTLER_STATUSES[$this->vermittler_status])) {
            return $this->vermittler_status;
        }
        if (filled($this->vermittler_id)) {
            return self::VERMITTLER_ID_ZUGEORDNET;
        }
        return filled($this->reference_number) ? self::VERMITTLER_REFERENZ : self::VERMITTLER_NEU;
    }

    public function vermittlerStatusLabel(): string
    {
        return self::VERMITTLER_STATUSES[$this->vermittlerStatus()]['label'];
    }

    public function vermittlerStatusBadge(): string
    {
        return self::VERMITTLER_STATUSES[$this->vermittlerStatus()]['badge'];
    }

    public function vermittlerStatusIcon(): string
    {
        return self::VERMITTLER_STATUSES[$this->vermittlerStatus()]['icon'];
    }

    /** Abrechnungs-Datensaetze des Vermittlers zu diesem Vertrag. */
    /**
     * Interne Provisionen zu diesem Vertrag (Betreiber-Auftrag 26.08.2026).
     *
     * VERTRAULICH: Diese Beziehung darf nur in der Beraterwelt geladen
     * werden. Sie ist bewusst NICHT Teil eines `$with` und hat kein
     * Gegenstueck am Kunden-Modell - so kann sie im Portal nicht
     * versehentlich mitgeladen und ausgegeben werden.
     */
    public function commissions()
    {
        return $this->hasMany(ContractCommission::class)->orderByDesc('commission_date');
    }

    public function vermittlerSettlements()
    {
        return $this->hasMany(VermittlerSettlement::class, 'contract_id')
            ->orderByDesc('statement_date')->orderByDesc('created_at');
    }

    /** Historie der Zuordnung (aelteste zuerst - sie erzaehlt den Verlauf). */
    public function vermittlerEvents()
    {
        return $this->hasMany(VermittlerMatchEvent::class, 'contract_id')->orderBy('created_at');
    }

    /** Vergleichsschluessel der Referenz-Nr. (nur fuer die Zuordnung). */
    public function referenceKey(): ?string
    {
        return \App\Services\Vermittler\VermittlerReference::key($this->reference_number);
    }
}
