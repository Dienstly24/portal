<?php
namespace App\Services\Portal;

use App\Mail\CustomerWelcomeMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Zentrale Logik für den Kundenportal-Zugang:
 * - Startpasswort = Geburtsdatum im Format TT.MM.JJJJ (Business-Vorgabe)
 * - Einladung (Erst- und Wiederversand) mit klarer Login-Anleitung
 * - Passwort-Reset-Link, Portal-Zurücksetzen, Aktivieren/Deaktivieren
 *
 * Grundregel: Es geht NIE eine Login-Mail an Kunden ohne echte E-Mail-
 * Adresse oder ohne nutzbares Passwort/Set-Link.
 */
class PortalAccessService
{
    /** Startpasswort aus dem Geburtsdatum (TT.MM.JJJJ) oder null. */
    public function initialPasswordFor(Customer $customer): ?string
    {
        return $customer->birth_date
            ? \Carbon\Carbon::parse($customer->birth_date)->format('d.m.Y')
            : null;
    }

    /**
     * Einladung senden (auch erneut). Setzt bei vorhandenem Geburtsdatum
     * das Startpasswort; ohne Geburtsdatum enthält die Mail einen
     * Passwort-Setzen-Link (Reset-Broker-Token).
     *
     * @throws \RuntimeException wenn keine echte E-Mail vorliegt
     */
    public function sendInvitation(Customer $customer, ?int $actorId = null): void
    {
        $user = $customer->user;
        if ($user === null || !$user->hasRealEmail()) {
            throw new \RuntimeException('Kunde hat keine echte E-Mail-Adresse – bitte zuerst eine Login-E-Mail hinterlegen.');
        }

        $initialPassword = $this->initialPasswordFor($customer);
        $setPasswordUrl = null;

        if ($initialPassword !== null) {
            $user->forceFill([
                'password' => bcrypt($initialPassword),
                'portal_password_set_at' => now(),
            ])->save();
            $mode = 'birthdate';
        } else {
            // Kein Geburtsdatum: zufälliges (unbekanntes) Passwort + Link
            // zum Selbst-Setzen über den regulären Reset-Broker.
            $user->forceFill(['password' => bcrypt(Str::random(40))])->save();
            $token = Password::broker()->createToken($user);
            $setPasswordUrl = route('password.reset', ['token' => $token, 'email' => $user->email]);
            $mode = 'setlink';
        }

        Mail::to($user->email)->send(new CustomerWelcomeMail($customer, $mode, null, $setPasswordUrl));

        $user->forceFill(['invitation_sent_at' => now()])->save();

        ActivityLog::create([
            'user_id' => $actorId,
            'action' => 'portal_invitation_sent',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode(['mode' => $mode, 'email' => $user->email], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** Passwort-Reset-Link senden (Admin-Aktion, gleiche Mail wie Self-Service). */
    public function sendResetLink(Customer $customer, ?int $actorId = null): void
    {
        $user = $customer->user;
        if ($user === null || !$user->hasRealEmail()) {
            throw new \RuntimeException('Kunde hat keine echte E-Mail-Adresse – Reset-Link nicht möglich.');
        }

        Password::broker()->sendResetLink(['email' => $user->email]);

        ActivityLog::create([
            'user_id' => $actorId,
            'action' => 'portal_reset_link_sent',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode(['email' => $user->email], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Portal zurücksetzen: neues Startpasswort (Geburtsdatum) bzw.
     * Set-Link, Login-Historie des Portals bleibt erhalten; der Kunde
     * bekommt die Einladung erneut.
     */
    public function resetPortal(Customer $customer, ?int $actorId = null): void
    {
        $user = $customer->user;
        if ($user === null || !$user->hasRealEmail()) {
            throw new \RuntimeException('Kunde hat keine echte E-Mail-Adresse – Zurücksetzen nicht möglich.');
        }

        $user->forceFill(['portal_password_set_at' => null])->save();
        $this->sendInvitation($customer, $actorId);

        ActivityLog::create([
            'user_id' => $actorId,
            'action' => 'portal_reset',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode(['email' => $user->email], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** Portal-Login aktivieren/deaktivieren (users.is_active). */
    public function setActive(Customer $customer, bool $active, ?int $actorId = null): void
    {
        $user = $customer->user;
        if ($user === null) {
            throw new \RuntimeException('Kunde hat keinen Benutzer-Datensatz.');
        }

        $user->forceFill(['is_active' => $active])->save();

        ActivityLog::create([
            'user_id' => $actorId,
            'action' => $active ? 'portal_activated' : 'portal_deactivated',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode(['email' => $user->email], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
