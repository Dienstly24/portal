<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable {
    use HasFactory, Notifiable;
    protected $fillable = ['name','email','password','role','access_level','can_see_all_customers','can_manage_contracts','can_manage_tickets','can_approve_changes','can_send_emails','can_import_export','provision_fixed','provision_percent'];
    protected $hidden = ['password','remember_token'];
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'invitation_sent_at' => 'datetime',
        'first_login_at' => 'datetime',
        'portal_password_set_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'must_change_password' => 'boolean',
        'password' => 'hashed',
        'can_see_all_customers' => 'boolean',
        'can_manage_contracts' => 'boolean',
        'can_manage_tickets' => 'boolean',
        'can_approve_changes' => 'boolean',
        'can_send_emails' => 'boolean',
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
                app(\App\Services\Matching\DuplicateDetectionService::class)->forgetCount();
            }
        });
    }

    public function customer() { return $this->hasOne(Customer::class); }

    /** Echte, erreichbare E-Mail (Import-Platzhalter zählen nicht). */
    public function hasRealEmail(): bool {
        return $this->email && !str_contains($this->email, '@dienstly24.internal');
    }

    /**
     * Deutsche Passwort-Reset-Mail statt der englischen Framework-
     * Notification. Der Versand läuft über den Password-Broker; Fehler
     * werden im Controller abgefangen (kein 500 mehr beim Kunden).
     */
    public function sendPasswordResetNotification($token): void {
        \Illuminate\Support\Facades\Mail::to($this->email)
            ->send(new \App\Mail\PasswordResetMail($this, $token));
    }
    public function assignedCustomers() { return $this->belongsToMany(Customer::class, 'employee_customers'); }

    /** Kunden, die dieser Mitarbeiter geworben hat (Neukunden-Bericht/Provision). */
    public function acquiredCustomers() { return $this->hasMany(Customer::class, 'acquired_by'); }

    /** Sparten-Provisionssaetze dieses Mitarbeiters (Provisions-Management). */
    public function provisionRates() { return $this->hasMany(ProvisionRate::class); }

    /** Favoriten-Kunden dieses Mitarbeiters (Stern im E-Mail-Composer). */
    public function favoriteCustomers() { return $this->belongsToMany(Customer::class, 'favorite_customers')->withTimestamps(); }

    public function canSeeAllCustomers(): bool {
        return in_array($this->role, ['admin', 'manager']) || (bool) $this->can_see_all_customers;
    }

    /** Eigene Kunden + Kunden von Kollegen, die man aktuell vertritt */
    public function visibleCustomerIdsWithSubstitution(): array {
        $ids = $this->assignedCustomers()->pluck('customers.id')->toArray();
        $absentIds = \App\Models\Substitution::active()
            ->where('substitute_user_id', $this->id)
            ->pluck('absent_user_id');
        foreach ($absentIds as $absentId) {
            $absent = User::find($absentId);
            if ($absent) {
                $ids = array_merge($ids, $absent->assignedCustomers()->pluck('customers.id')->toArray());
            }
        }
        return array_values(array_unique($ids));
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
        if (!$this->isStaff()) return false;
        if ($this->canSeeAllCustomers()) return true;
        return in_array((string) $customerId, array_map('strval', $this->visibleCustomerIdsWithSubstitution()), true);
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

    public function isAdmin() { return $this->role === 'admin'; }
    public function isEmployee() { return $this->role === 'employee'; }
    public function isCustomer() { return $this->role === 'customer'; }
    public function canSeeCustomer($customerId) {
        if ($this->isAdmin()) return true;
        if ($this->can_see_all_customers) return true;
        return $this->assignedCustomers()->where('customers.id', $customerId)->exists();
    }
    public function getAccessibleCustomers() {
        if ($this->isAdmin() || $this->can_see_all_customers) {
            return Customer::with('user');
        }
        return $this->assignedCustomers()->with('user');
    }
}
