<?php

namespace App\Models;

use App\Mail\PasswordResetMail;
use App\Services\Matching\DuplicateDetectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class User extends Authenticatable
{
    use HasFactory, Notifiable;
    protected $fillable = ['name', 'email', 'password', 'role', 'access_level', 'can_see_all_customers', 'can_manage_contracts', 'can_manage_tickets', 'can_approve_changes', 'can_send_emails', 'can_import_export', 'can_manage_commissions', 'provision_fixed', 'provision_percent'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'invitation_sent_at' => 'datetime',
        'first_login_at' => 'datetime',
        'portal_password_set_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'must_change_password' => 'boolean',
        // Das 2FA-Geheimnis ist gleichwertig zum Passwort: wer es hat,
        // erzeugt gueltige Codes. Deshalb verschluesselt at rest.
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
        'password' => 'hashed',
        'can_see_all_customers' => 'boolean',
        'can_manage_contracts' => 'boolean',
        'can_manage_tickets' => 'boolean',
        'can_approve_changes' => 'boolean',
        'can_send_emails' => 'boolean',
        'can_manage_commissions' => 'boolean',
        'can_import_export' => 'boolean',
        'provision_fixed' => 'decimal:2',
        'provision_percent' => 'decimal:2',
    ];
    protected static function booted(): void
    {
        // Name und E-Mail sind starke Dubletten-Signale, liegen aber am User.
        // Aendert sich eines fuer einen KUNDEN-Account, muss der Dubletten-
        // Hinweis-Badge neu berechnet werden (Anlage/Loeschung laufen bereits
        // ueber das Customer-Modell). Nur Kundenkonten, nur bei echten
        // Aenderungen an Name/E-Mail - Login-/Rechte-Updates loesen nichts aus.
        static::updated(function (self $user) {
            if ($user->role !== 'customer') {
                return;
            }
            if (array_intersect(array_keys($user->getChanges()), ['name', 'email']) !== []) {
                app(DuplicateDetectionService::class)->forgetCount();
            }
        });
    }

    /** @return HasOne<Customer, $this> */
    public function customer(): HasOne { return $this->hasOne(Customer::class); }

    /** Echte, erreichbare E-Mail (Import-Platzhalter zählen nicht). */
    public function hasRealEmail(): bool {
        return $this->email && ! str_contains($this->email, '@dienstly24.internal');
    }

    /**
     * Deutsche Passwort-Reset-Mail statt der englischen Framework-
     * Notification. Der Versand läuft über den Password-Broker; Fehler
     * werden im Controller abgefangen (kein 500 mehr beim Kunden).
     */
    public function sendPasswordResetNotification($token): void {
        Mail::to($this->email)
            ->send(new PasswordResetMail($this, $token));
    }
    /** @return BelongsToMany<Customer, $this> */
    public function assignedCustomers(): BelongsToMany { return $this->belongsToMany(Customer::class, 'employee_customers'); }

    /** Kunden, die dieser Mitarbeiter geworben hat (Neukunden-Bericht/Provision). */
    /** @return HasMany<Customer, $this> */
    public function acquiredCustomers(): HasMany { return $this->hasMany(Customer::class, 'acquired_by'); }

    /** Sparten-Provisionssaetze dieses Mitarbeiters (Provisions-Management). */
    /** @return HasMany<ProvisionRate, $this> */
    public function provisionRates(): HasMany { return $this->hasMany(ProvisionRate::class); }

    /** Favoriten-Kunden dieses Mitarbeiters (Stern im E-Mail-Composer). */
    /** @return BelongsToMany<Customer, $this> */
    public function favoriteCustomers(): BelongsToMany { return $this->belongsToMany(Customer::class, 'favorite_customers')->withTimestamps(); }

    public function canSeeAllCustomers(): bool {
        return in_array($this->role, ['admin', 'manager']) || (bool) $this->can_see_all_customers;
    }

    /**
     * Die Mitarbeiter, deren Portfolio dieser Nutzer gerade sieht:
     * er selbst plus jeder Kollege, den er aktuell VERTRITT.
     *
     * EINE Abfrage (Audit 15.09.2026). Vorher lief hier ein User::find()
     * JE abwesendem Kollegen und danach je Kollege eine weitere Abfrage
     * auf dessen Kunden - ein N+1 in einem Pfad, den JEDE Seite der
     * Beraterwelt durchlaeuft.
     *
     * @return array<int, int>
     */
    public function visibleOwnerIds(): array {
        $ids = Substitution::active()
            ->where('substitute_user_id', $this->id)
            ->pluck('absent_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $ids[] = (int) $this->id;

        return array_values(array_unique($ids));
    }

    /**
     * Eigene Kunden + Kunden von Kollegen, die man aktuell vertritt.
     *
     * BEWUSST OHNE ZWISCHENSPEICHER (Audit 15.09.2026): ein Cache je
     * Modell-Instanz sah zunaechst verlockend aus, weil die Methode je
     * Anfrage mehrfach aufgerufen wird. Er ist aber genau dann falsch,
     * wenn sich das Portfolio INNERHALB einer Anfrage aendert - und die
     * Testsuite hat das sofort gezeigt (Kunde zugewiesen, danach
     * Suche: der neue Kunde fehlte).
     *
     * Eine Sichtbarkeitsregel, die manchmal veraltete Daten liefert, ist
     * ein Sicherheitsrisiko, kein Tuning. Die teuren Teile sind statt
     * dessen dort beseitigt, wo sie wirklich weh taten: das N+1 oben,
     * die EXISTS-Bedingung in Customer::scopeVisibleTo() und die
     * EXISTS-Pruefung in canAccessCustomer() - keine dieser Stellen
     * materialisiert noch die vollstaendige Liste.
     *
     * @return array<int, string>
     */
    public function visibleCustomerIdsWithSubstitution(): array {
        return DB::table('employee_customers')
            ->whereIn('user_id', $this->visibleOwnerIds())
            ->distinct()
            ->pluck('customer_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** Interne Rollen - Kunden sind ausdrücklich KEIN Staff. */
    public function isStaff(): bool {
        return in_array($this->role, ['admin', 'manager', 'support', 'employee'], true);
    }

    /**
     * Einheitliche Sichtbarkeitsprüfung für einen Kunden:
     * admin/manager/can_see_all_customers sehen alles, sonst zählt die
     * Zuweisung inkl. aktiver Vertretungen. (Basis für Policies)
     */
    public function canAccessCustomer($customerId): bool {
        if (! $this->isStaff()) return false;
        if ($this->canSeeAllCustomers()) return true;
        if ($customerId === null || $customerId === '') return false;

        // EXISTS statt "ganze Liste holen und darin suchen" (Audit
        // 15.09.2026): die Pruefung laeuft auf JEDER Kundenseite und
        // musste bisher erst das komplette Portfolio laden. Jetzt trifft
        // sie den Index employee_customers(user_id, customer_id) und
        // liest genau eine Zeile - unabhaengig von der Portfoliogroesse.
        return DB::table('employee_customers')
            ->whereIn('user_id', $this->visibleOwnerIds())
            ->where('customer_id', (string) $customerId)
            ->exists();
    }

    /**
     * Muss dieser Nutzer beim naechsten Aufruf ein eigenes Passwort
     * setzen? Zwei Faelle, bewusst zusammengefasst (Betreiber-Vorgabe
     * 18.08.2026):
     *  a) must_change_password ist gesetzt (System hat das Passwort
     *     vergeben - Geburtsdatum, Admin-Reset, CLI).
     *  b) Kundenkonto mit nutzbarem Passwort, das noch NIE selbst
     *     geaendert wurde (Altbestand vor dieser Regel).
     * Konten ohne nutzbares Passwort (reiner Magic-Login) sind bewusst
     * NICHT betroffen - die fuehrt der Portal-Flow ohnehin zum Setzen.
     */
    public function needsPasswordChange(): bool
    {
        return (bool) ($this->must_change_password ?? false);
    }

    /**
     * Passwort setzen und alle Nebenbuchungen an EINER Stelle erledigen:
     * Zwangswechsel aufheben, Zeitstempel fuehren, Portal-Status
     * markieren. Vorher lag das in vier Controllern verstreut und war
     * jedes Mal etwas anders (mal ohne portal_password_set_at, mal ohne
     * Zeitstempel) - genau so entstehen "Passwort gesetzt, trotzdem
     * wieder gefragt"-Meldungen.
     */
    public function setPassword(string $plain): void
    {
        $this->forceFill([
            'password' => bcrypt($plain),
            'portal_password_set_at' => now(),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();
    }

    /** Ist die Zwei-Faktor-Anmeldung fertig eingerichtet und bestaetigt? */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Braucht dieses Konto zwingend eine zweite Schicht? Alle internen
     * Rollen - sie sehen fremde personenbezogene Daten. Kundenkonten
     * bewusst NICHT: dort waere die Huerde groesser als der Gewinn, und
     * ein Kunde sieht ausschliesslich seine eigenen Daten.
     */
    public function requiresTwoFactor(): bool
    {
        return $this->isStaff() || $this->role === 'partner';
    }

    public function isAdmin() { return $this->role === 'admin'; }
    public function isEmployee() { return $this->role === 'employee'; }
    public function isCustomer() { return $this->role === 'customer'; }

    /**
     * Die Kunden, die dieser Nutzer sehen darf - als Query.
     *
     * EINE QUELLE (Audit 15.09.2026). Hier standen frueher DREI Regeln
     * nebeneinander, und sie waren nicht deckungsgleich:
     *   - canSeeAllCustomers()   : admin ODER manager ODER Flag
     *   - getAccessibleCustomers(): admin ODER Flag  -- manager FEHLTE
     *   - canSeeCustomer()        : admin ODER Flag, ohne Vertretung
     *
     * Folge, am echten System nachgemessen: ein MANAGER mit allen
     * Rechten sah unter /admin/kundenchat NULL Unterhaltungen, waehrend
     * ihm die Kundenliste alle 3.000 Kunden zeigte. Kein Fehler, keine
     * Meldung - die Seite war einfach leer, und niemand konnte wissen,
     * dass dort Kundennachrichten unbeantwortet lagen. Ein vertretender
     * Kollege sah aus demselben Grund die Unterhaltungen des Abwesenden
     * nicht, obwohl er dessen Kunden bearbeiten durfte.
     *
     * canSeeCustomer() war toter Code und ist entfallen.
     *
     * @return Builder<Customer>
     */
    public function getAccessibleCustomers() {
        return Customer::query()->with('user')->visibleTo($this);
    }
}
