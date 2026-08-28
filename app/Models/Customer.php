<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
class Customer extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    public const SOURCES = ['manual', 'website', 'email_import', 'fonds_finanz', 'import', 'lexoffice'];

    protected $fillable = [
        'user_id','partner_id','customer_number','source','commission_import_id','created_by','acquired_by','acquired_by_partner_id',
        'birth_date','address','address2',
        'iban','iban2','marital_status','phone','mobile','preferred_lang',
        'company_name','company_type','customer_type','email2',
        'nationality','occupation','employer_name','employer_address','last_contact','gender','account_holder','bic',
        'marketing_consent','unsubscribed_at','unsubscribe_token',
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
            'marketing_consent' => 'boolean',
            'unsubscribed_at' => 'datetime',
            // SafeEncrypted: verschluesselt at rest (DSGVO), aber robust gegen
            // Alt-Klartext-Bestaende (sonst HTTP 500 beim Oeffnen/Speichern).
            'health_insurance_number' => \App\Casts\SafeEncrypted::class,
            'pension_insurance_number' => \App\Casts\SafeEncrypted::class,
            'tax_id' => \App\Casts\SafeEncrypted::class,
            // Bankdaten verschlüsselt at rest (DSGVO). Anzeige bleibt maskiert.
            'iban' => \App\Casts\SafeEncrypted::class,
            'iban2' => \App\Casts\SafeEncrypted::class,
            'bic' => \App\Casts\SafeEncrypted::class,
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
            ['ok' => $this->contracts()->whereIn('type', \App\Models\Contract::ENERGY_TYPES)->exists(), 'label' => 'Energievertrag fehlt', 'route' => 'portal.contracts'],
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
            // Bewusst NICHT "Aktiviert": das Startpasswort richtet das SYSTEM
            // beim Einladungsversand ein - aktiv wird der Kunde erst mit dem
            // ersten Login (Betreiber-Missverstaendnis 07.08.2026).
            return ['key' => 'aktiviert', 'label' => 'Zugang eingerichtet – noch kein Login', 'color' => '#185FA5', 'bg' => '#E6F1FB'];
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

    /**
     * Darf dieser Kunde Marketing-Mails (Kampagnen, Wechsel-Erinnerungen)
     * erhalten? Transaktionale Mails sind davon NICHT betroffen.
     */
    public function isMarketingReachable(): bool {
        return $this->user?->hasRealEmail()
            && $this->marketing_consent
            && $this->unsubscribed_at === null;
    }

    /** Query-Scope-Variante von isMarketingReachable() für Massenversand. */
    public function scopeMarketingReachable($query) {
        return $query->where('marketing_consent', true)->whereNull('unsubscribed_at');
    }

    /**
     * Volltext-Suche ueber ALLE relevanten Kundenfelder und verknuepften
     * Datensaetze (Vertraege, Fahrzeuge, Energie). EIN Sucheingabefeld findet
     * damit den Kunden unabhaengig davon, welche Information dem Mitarbeiter
     * vorliegt: Name, E-Mail, Telefon, Kundennummer, Vertragsnummer, Anschrift,
     * PLZ/Ort, Kennzeichen, FIN, Zaehlernummer usw.
     *
     * Der Suchbegriff wird in einzelne Woerter zerlegt: jedes Wort MUSS
     * irgendwo passen (UND zwischen den Woertern, ODER ueber die Felder). So
     * grenzt "Ahmad Berlin" auf Ahmad in Berlin ein, waehrend eine einzelne
     * Nummer (PLZ, Kennzeichen, Zaehler, Vertragsnummer ...) jedes Feld trifft,
     * in dem sie vorkommt.
     *
     * Verschluesselte Felder (IBAN, KV-/RV-Nummer, Steuer-ID) sind BEWUSST
     * nicht durchsuchbar - sie liegen als Chiffrat in der Datenbank und lassen
     * sich per LIKE nicht vergleichen (Datenschutz + technisch nicht moeglich).
     */
    public function scopeSearch($query, ?string $term) {
        $term = trim((string) ($term ?? ''));
        if ($term === '') {
            return $query;
        }
        // In einzelne Suchwoerter zerlegen (mehrere Leerzeichen zusammenfassen).
        $tokens = preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [$term];
        foreach ($tokens as $token) {
            // %/_ maskieren, damit Nutzereingaben keine LIKE-Platzhalter werden.
            $like = '%' . addcslashes($token, '%_\\') . '%';
            // Ein Suchwort, das ein DATUM ist (12.03.2012 / 2012-03-12), trifft
            // zusaetzlich das Geburtsdatum - die Familienzuordnung sucht
            // bestehende Kunden ausdruecklich auch darueber.
            $birthDate = static::parseDateToken($token);
            $query->where(function ($w) use ($like, $birthDate) {
                // Direkte Kundenfelder (inkl. strukturierte Anschrift + PLZ/Ort).
                $w->where('customer_number', 'like', $like)
                  ->orWhere('phone', 'like', $like)
                  ->orWhere('mobile', 'like', $like)
                  ->orWhere('email2', 'like', $like)
                  ->orWhere('company_name', 'like', $like)
                  ->orWhere('address', 'like', $like)
                  ->orWhere('address2', 'like', $like)
                  ->orWhere('address_street', 'like', $like)
                  ->orWhere('address_house_number', 'like', $like)
                  ->orWhere('address_zip', 'like', $like)
                  ->orWhere('address_city', 'like', $like)
                  // Name + Login-E-Mail liegen am User.
                  ->orWhereHas('user', fn($u) => $u->where('name', 'like', $like)
                      ->orWhere('email', 'like', $like))
                  // Vertragsnummer, Versicherer/Anbieter, freie Sparte.
                  ->orWhereHas('contracts', fn($c) => $c->where('contract_number', 'like', $like)
                      ->orWhere('insurer', 'like', $like)
                      ->orWhere('type_other', 'like', $like))
                  // Fahrzeug am Vertrag: Kennzeichen, FIN, HSN/TSN, Marke/Modell.
                  ->orWhereHas('contracts.vehicleDetail', fn($v) => $v->where('license_plate', 'like', $like)
                      ->orWhere('vin', 'like', $like)
                      ->orWhere('hsn', 'like', $like)
                      ->orWhere('tsn', 'like', $like)
                      ->orWhere('manufacturer', 'like', $like)
                      ->orWhere('model', 'like', $like))
                  // Energie am Vertrag: Zaehlernummer (Strom/Gas), MaLo-ID,
                  // Kundennummer beim Energieanbieter.
                  ->orWhereHas('contracts.energyDetail', fn($e) => $e->where('meter_number', 'like', $like)
                      ->orWhere('malo_id', 'like', $like)
                      ->orWhere('customer_number', 'like', $like))
                  // Separat gepflegte Kunden-Stammfahrzeuge.
                  ->orWhereHas('vehicles', fn($cv) => $cv->where('license_plate', 'like', $like)
                      ->orWhere('vin', 'like', $like)
                      ->orWhere('brand', 'like', $like)
                      ->orWhere('model', 'like', $like));
                // Bewusst ganz am Ende der ODER-Kette: als erste Bedingung
                // wuerde das folgende where() zu einem UND und die uebrigen
                // Felder waeren nicht mehr durchsuchbar.
                if ($birthDate !== null) {
                    $w->orWhereDate('birth_date', $birthDate);
                }
            });
        }
        return $query;
    }

    /**
     * Ein Suchwort als Datum lesen - oder null, wenn es keines ist. Bewusst
     * nur die beiden eindeutigen Schreibweisen (deutsch und ISO): eine
     * grosszuegigere Erkennung wuerde reine Zahlen (PLZ, Kundennummer) zu
     * Datumsangaben machen und die Suche verwaessern.
     */
    public static function parseDateToken(string $token): ?string
    {
        $token = trim($token);
        foreach (['d.m.Y', 'Y-m-d'] as $format) {
            $date = \DateTime::createFromFormat('!' . $format, $token);
            if ($date !== false && $date->format($format) === $token) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /** Token für den öffentlichen Abmelde-Link (lazy erzeugt). */
    public function unsubscribeToken(): string {
        if (empty($this->unsubscribe_token)) {
            $this->forceFill(['unsubscribe_token' => Str::random(48)])->save();
        }
        return $this->unsubscribe_token;
    }

    public function addresses() { return $this->hasMany(CustomerAddress::class, 'customer_id'); }
    public function contacts() { return $this->hasMany(CustomerContact::class, 'customer_id'); }
    public function changeRequests() { return $this->hasMany(CustomerChangeRequest::class, 'customer_id'); }
    /**
     * Kundenfelder, die in den Dubletten-Abgleich einfliessen. Aendert sich
     * eines davon, kann sich die Zahl der Verdachtsfaelle veraendern - der
     * Hinweis-Badge muss dann neu berechnet werden. (Name/E-Mail liegen am
     * User-Modell und werden dort invalidiert.)
     */
    private const DUPLICATE_SIGNAL_FIELDS = [
        'phone', 'mobile', 'email2', 'iban', 'iban2', 'birth_date',
        'address', 'address2', 'address_street', 'address_house_number',
        'address_house_suffix', 'address_zip', 'address_city',
    ];

    protected static function boot() {
        parent::boot();
        static::creating(function ($m) {
            $m->id = Str::uuid();
            // Neukunden-Bericht: den Anleger automatisch festhalten, egal ueber
            // welchen Weg der Kunde entsteht (Formular, Import, Dokument-Upload).
            // Nur Mitarbeiter-Konten - Selbstregistrierung/CLI bleibt null (=System).
            if ($m->created_by === null && auth()->check() && auth()->user()->isStaff()) {
                $m->created_by = auth()->id();
            }
        });

        // Dubletten-Hinweis-Badge (DuplicateDetectionService::countCached, 5-Min-
        // TTL) zentral invalidieren, sobald sich der Kundenbestand aendert.
        // Bisher wurde der Cache NUR beim Zusammenfuehren/Verwerfen aktualisiert;
        // loeschte ein Mitarbeiter dagegen einen der beiden Duplikat-Datensaetze
        // direkt (oder legte einen neuen Kunden an), blieb die angezeigte Zahl
        // bis zu 5 Minuten stehen - der Verdachtsfall war "erledigt", der Badge
        // aber noch da. Jetzt greift die Invalidierung an EINER Stelle fuer ALLE
        // Pfade (UI, Import-Job, CLI-Purge, Merge).
        static::created(fn() => static::forgetDuplicateBadge());
        static::deleted(fn() => static::forgetDuplicateBadge());
        static::updated(function ($customer) {
            if (array_intersect(array_keys($customer->getChanges()), self::DUPLICATE_SIGNAL_FIELDS) !== []) {
                static::forgetDuplicateBadge();
            }
        });
    }

    /**
     * Faengt die seltene Kollision zweier zeitgleich vergebener Kundennummern
     * ab (Unique-Index auf customer_number): die Nummer wird von den Aufrufern
     * vorab per CustomerNumberGenerator gezogen (check-then-act), sodass zwei
     * gleichzeitige Anlagen dieselbe Nummer erhalten koennen. Statt eines 500ers
     * wird die Nummer EINMAL neu gezogen und erneut gespeichert - Muster wie
     * Ticket::save (Audit CONC-2). Die DB-UNIQUE bleibt die eigentliche
     * Absicherung gegen echte Duplikate.
     */
    public function save(array $options = [])
    {
        try {
            return parent::save($options);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            if (!$this->exists && $this->customer_number
                && str_contains($e->getMessage(), 'customer_number')) {
                $this->customer_number = app(\App\Services\CustomerNumberGenerator::class)->generate();
                return parent::save($options);
            }
            throw $e;
        }
    }

    /**
     * Zaehler-Cache der Dubletten-Verdachtsfaelle verwerfen. Ueber den Service,
     * damit der Cache-Schluessel (Versionsnummer) nur an EINER Stelle definiert
     * ist.
     */
    private static function forgetDuplicateBadge(): void
    {
        app(\App\Services\Matching\DuplicateDetectionService::class)->forgetCount();
    }
    public function user() { return $this->belongsTo(User::class); }
    public function betreuer() { return $this->belongsToMany(User::class, 'employee_customers', 'customer_id', 'user_id'); }
    public function contracts() { return $this->hasMany(Contract::class); }
    public function contractHistories() { return $this->hasMany(ContractHistory::class); }
    public function tickets() { return $this->hasMany(Ticket::class); }
    public function documents() { return $this->hasMany(Document::class); }
    public function consents() { return $this->hasMany(CustomerConsent::class); }
    public function family() { return $this->hasMany(CustomerFamily::class); }

    /**
     * Familienbeziehungen zu ANDEREN Kundenakten, ausgehend von diesem Kunden:
     * "X ist <Rolle> von mir". Die Gegenrichtung steht in familyRelationsOf().
     */
    public function familyRelations() { return $this->hasMany(CustomerFamilyRelation::class, 'customer_id'); }

    /** Beziehungen, in denen dieser Kunde das verknuepfte Mitglied ist ("ich bin <Rolle> von X"). */
    public function familyRelationsOf() { return $this->hasMany(CustomerFamilyRelation::class, 'related_customer_id'); }
    public function vehicles() { return $this->hasMany(CustomerVehicle::class); }
    public function messages() { return $this->hasMany(CustomerMessage::class); }
    public function notes() { return $this->hasMany(CustomerNote::class)->latest(); }
    public function timeline() { return $this->hasMany(CustomerTimeline::class)->latest(); }
    public function appointments() { return $this->hasMany(Appointment::class); }
    public function externalReferences() { return $this->morphMany(ExternalReference::class, 'referenceable'); }
    public function partner() { return $this->belongsTo(Partner::class); }
    /** Mitarbeiter, der den Datensatz angelegt hat (null = System/Import/Alt-Bestand). */
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    /** Werber (Mitarbeiter), der den Kunden geworben hat. */
    public function acquirer() { return $this->belongsTo(User::class, 'acquired_by'); }
    /** Werber (Vertriebspartner), der den Kunden geworben hat. */
    public function acquirerPartner() { return $this->belongsTo(Partner::class, 'acquired_by_partner_id'); }

    /**
     * Werber-Schluessel fuer Filter/Formulare: 'u:{id}' (Mitarbeiter),
     * 'p:{uuid}' (Partner) oder null. Mitarbeiter hat Vorrang - die
     * Setter-Logik haelt beide Felder ohnehin exklusiv.
     */
    public function acquirerKey(): ?string
    {
        if ($this->acquired_by) return 'u:' . $this->acquired_by;
        if ($this->acquired_by_partner_id) return 'p:' . $this->acquired_by_partner_id;
        return null;
    }

    /** Anzeigename des Werbers oder null, wenn keiner hinterlegt ist. */
    public function acquirerLabel(): ?string
    {
        if ($this->acquired_by) return $this->acquirer?->name;
        if ($this->acquired_by_partner_id) return $this->acquirerPartner?->name;
        return null;
    }

    /**
     * Werber-Schluessel aus Formularen ('u:{id}' Mitarbeiter, 'p:{uuid}'
     * Partner) validiert aufloesen. Liefert immer beide Spalten (exklusiv,
     * unbekannte Schluessel -> beide null). Rollen-Gate (nur Verwaltung darf
     * Werber setzen) bleibt Sache des Aufrufers.
     */
    public static function resolveWerberKey(?string $key): array
    {
        $values = ['acquired_by' => null, 'acquired_by_partner_id' => null];
        $key = (string) $key;
        if (str_starts_with($key, 'u:')) {
            $values['acquired_by'] = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])
                ->whereKey((int) substr($key, 2))->value('id');
        } elseif (str_starts_with($key, 'p:')) {
            $values['acquired_by_partner_id'] = Partner::whereKey(substr($key, 2))->value('id');
        }
        return $values;
    }

    /**
     * Ab diesem Alter gilt ein Familienmitglied nicht mehr als abhaengig,
     * sondern als eigenstaendiger Kunde (Betreiber-Vorgabe 28.08.2026).
     */
    public const DEPENDENT_AGE = 15;

    /** Stammdaten, die ein abhaengiges Kind von der Bezugsperson erben kann. */
    public const INHERITABLE_FIELDS = ['address', 'email', 'phone', 'mobile'];

    /** Alter in Jahren oder null, wenn kein Geburtsdatum erfasst ist (nie schaetzen). */
    public function age(): ?int
    {
        if (empty($this->birth_date)) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($this->birth_date)->age;
    }

    /**
     * Beziehungen, in denen dieser Kunde als ABHAENGIGES Familienmitglied an
     * einer Bezugsperson haengt. Grundlage fuer Status und Datenvererbung.
     */
    public function dependencyRelations()
    {
        return $this->familyRelationsOf()->where('is_dependent', true);
    }

    /**
     * Bezugspersonen (Eltern/Hauptkunden), von denen dieser Kunde heute
     * abhaengig ist. Reihenfolge = Anlage-Reihenfolge; die erste gilt als
     * Haupt-Bezugsperson.
     *
     * @return \Illuminate\Support\Collection<int, Customer>
     */
    public function familyGuardians(): \Illuminate\Support\Collection
    {
        return $this->dependencyRelations()
            ->with(['customer.user'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (CustomerFamilyRelation $r) => $r->dependentNow() && $r->customer !== null)
            ->map(fn (CustomerFamilyRelation $r) => $r->customer)
            ->values();
    }

    /** Haupt-Bezugsperson eines abhaengigen Familienmitglieds (oder null). */
    public function familyGuardian(): ?Customer
    {
        return $this->familyGuardians()->first();
    }

    /**
     * Ist dieser Kunde heute ein abhaengiges Familienmitglied (Kind < 15 mit
     * hinterlegter Bezugsperson)? Wird IMMER aus Alter + Beziehung abgeleitet,
     * nie aus einer eigenen Statusspalte - eine solche koennte aus dem Takt
     * laufen, und der Uebergang mit 15 wuerde davon abhaengen, dass der
     * Tageslauf gelaufen ist.
     */
    public function isFamilyDependent(): bool
    {
        return $this->familyGuardian() !== null;
    }

    /**
     * KUNDENSTATUS (bewusst getrennt von der Familienrolle):
     * 'familienmitglied' (abhaengig), 'hauptkunde' (fuehrt eine Familie) oder
     * 'eigenstaendig'. Abgeleitet, damit Anzeige und Wirklichkeit nie
     * auseinanderlaufen.
     */
    public function familyStatus(): array
    {
        if ($guardian = $this->familyGuardian()) {
            return [
                'key' => 'familienmitglied',
                'label' => 'Familienmitglied / Kind – abhängig von ' . ($guardian->user?->name ?: 'Hauptkunde'),
                'short' => 'Familienmitglied',
                'color' => '#92400E', 'bg' => '#FEF3C7',
                'guardian' => $guardian,
            ];
        }

        if ($this->familyRelations()->where('is_dependent', true)->exists()) {
            return [
                'key' => 'hauptkunde', 'label' => 'Hauptkunde der Familie', 'short' => 'Hauptkunde',
                'color' => '#128a4b', 'bg' => '#D9F4E6', 'guardian' => null,
            ];
        }

        return [
            'key' => 'eigenstaendig', 'label' => 'Eigenständiger Kunde', 'short' => 'Eigenständig',
            'color' => '#185FA5', 'bg' => '#E6F1FB', 'guardian' => null,
        ];
    }

    /** Rohwert eines vererbbaren Stammdatums an DIESEM Kunden (ohne Vererbung). */
    private function ownContactValue(string $field): string
    {
        return trim((string) match ($field) {
            'address' => $this->fullAddress(),
            'email'   => $this->user?->hasRealEmail() ? $this->user->email : ($this->email2 ?? ''),
            'phone'   => $this->phone ?? '',
            'mobile'  => $this->mobile ?? '',
            default   => '',
        });
    }

    /**
     * Wirksames Stammdatum eines Kunden - eigener Wert schlaegt IMMER den
     * geerbten (Betreiber-Vorgabe 28.08.2026).
     *
     * Bewusst KEINE physische Kopie in die Kindakte: die Kontaktdaten der
     * Familie stehen genau einmal (bei der Bezugsperson) und werden hier
     * gelesen. Eine Kopie waere ab dem ersten Umzug falsch, ohne dass es
     * jemandem auffaellt.
     *
     * @return array{value:string, inherited:bool, source:?Customer}
     */
    public function effectiveContact(string $field): array
    {
        $own = $this->ownContactValue($field);
        if ($own !== '') {
            return ['value' => $own, 'inherited' => false, 'source' => null];
        }

        // Geerbt wird nur, solange das Familienmitglied abhaengig ist.
        foreach ($this->familyGuardians() as $guardian) {
            $value = $guardian->ownContactValue($field);
            if ($value !== '') {
                return ['value' => $value, 'inherited' => true, 'source' => $guardian];
            }
        }

        return ['value' => '', 'inherited' => false, 'source' => null];
    }

    /** Aktive E-Mail-Verarbeitungs-Einwilligung (oder null). */
    public function activeEmailConsent(): ?CustomerConsent
    {
        return $this->consents()
            ->emailProcessing()->active()
            ->latest('granted_at')->first();
    }

    public function hasActiveEmailConsent(): bool
    {
        return $this->activeEmailConsent() !== null;
    }
}
