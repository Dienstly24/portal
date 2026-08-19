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
     * WICHTIG: Ein bereits nutzbares Passwort (portal_password_set_at
     * gesetzt) wird NIE ueberschrieben. Der 7-Tage-Erinnerungs-Batch
     * und "Einladung erneut senden" haben sonst Kunden, die ihr
     * Passwort schon gesetzt (aber sich noch nicht eingeloggt) hatten,
     * still das Passwort zurueckgesetzt -> "mein Passwort geht nicht".
     * Bewusstes Zuruecksetzen laeuft ueber resetPortal(), das den
     * Timestamp vorher leert.
     *
     * @return string Versandmodus: 'birthdate' (Startpasswort = Geburtsdatum)
     *         oder 'setlink' (KEIN Geburtsdatum hinterlegt - nur zeitlich
     *         begrenzter Passwort-Setzen-Link). Der Aufrufer soll bei
     *         'setlink' warnen: ohne Geburtsdatum gilt die Startpasswort-
     *         Regel nicht, und der Link laeuft ab (Betreiber-Meldung
     *         07.08.2026: Einladung sah erfolgreich aus, der Kunde konnte
     *         den Zugang aber nie aktivieren).
     * @throws \RuntimeException wenn keine echte E-Mail vorliegt
     */
    public function sendInvitation(Customer $customer, ?int $actorId = null): string
    {
        $user = $customer->user;
        if ($user === null || !$user->hasRealEmail()) {
            throw new \RuntimeException('Kunde hat keine echte E-Mail-Adresse – bitte zuerst eine Login-E-Mail hinterlegen.');
        }

        $initialPassword = $this->initialPasswordFor($customer);
        $setPasswordUrl = null;
        $hasUsablePassword = $user->portal_password_set_at !== null;

        if ($initialPassword !== null) {
            if (!$hasUsablePassword) {
                $user->forceFill([
                    'password' => bcrypt($initialPassword),
                    'portal_password_set_at' => now(),
                    // Das Geburtsdatum steht auf jedem Ausweis, in jedem
                    // Versicherungsschein und in jeder Meldebestaetigung -
                    // als Dauer-Passwort ist es faktisch oeffentlich.
                    // Deshalb beim ersten Login Pflicht-Wechsel (Betreiber-
                    // Vorgabe 18.08.2026). Der Kunde meldet sich weiterhin
                    // mit dem Geburtsdatum an; er kommt nur nicht mehr
                    // dauerhaft damit durch.
                    'must_change_password' => true,
                ])->save();
            }
            $mode = 'birthdate';
        } else {
            // Kein Geburtsdatum: zufälliges (unbekanntes) Passwort + Link
            // zum Selbst-Setzen.
            if (!$hasUsablePassword) {
                $user->forceFill(['password' => bcrypt(Str::random(40))])->save();
            }
            // SIGNIERTER Link statt Reset-Broker-Token: der Broker ist seit
            // der Haertung auf 60 Minuten gestellt (Self-Service-Reset), was
            // fuer eine Einladung viel zu kurz waere - Kunden lesen die Mail
            // oft erst Tage spaeter. Der signierte Link haelt 14 Tage,
            // haengt am APP_KEY und laesst das Passwort unangetastet, bis
            // der Kunde selbst eines setzt.
            $setPasswordUrl = \App\Http\Controllers\Auth\PasswordSetupController::invitationUrl($user);
            $mode = 'setlink';
        }

        Mail::to($user->email)->send(new CustomerWelcomeMail($customer, $mode, null, $setPasswordUrl));

        $user->forceFill([
            'invitation_sent_at' => now(),
            'invitation_count' => (int) ($user->invitation_count ?? 0) + 1,
        ])->save();

        ActivityLog::create([
            'user_id' => $actorId,
            'action' => 'portal_invitation_sent',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode(['mode' => $mode, 'email' => $user->email], JSON_UNESCAPED_UNICODE),
        ]);

        return $mode;
    }

    /**
     * Automatische Portal-Einladung nach einem Ereignis (z.B. Vertrag aus
     * einem hochgeladenen Dokument angelegt/verknuepft). Anders als
     * sendInvitation() wirft diese Methode NICHT, sondern ueberspringt still,
     * wenn eine Einladung nicht sinnvoll ist - der ausloesende Vorgang
     * (Dokument zuordnen, Kunde anlegen) darf daran nie scheitern.
     *
     * Es wird NUR eingeladen, wenn:
     * - eine echte, erreichbare E-Mail vorliegt,
     * - das Portal nicht deaktiviert ist,
     * - der Zugang noch NICHT laeuft (kein Login, kein gesetztes Passwort) und
     * - noch KEINE Einladung raus ist (Reputationsschutz: nie doppelt).
     *
     * @return bool true, wenn eine Einladung versendet wurde
     */
    public function autoInvite(Customer $customer, ?int $actorId = null): bool
    {
        $user = $customer->user;
        if ($user === null || !$user->hasRealEmail()) {
            return false; // keine erreichbare Adresse -> stiller Ueberspring
        }
        if (isset($user->is_active) && !$user->is_active) {
            return false; // Portal bewusst deaktiviert
        }
        // Zugang laeuft bereits (Login/Passwort) oder Einladung schon versandt
        // -> nicht wiederholen (kein Spam bei jedem weiteren Vertrag/Dokument).
        if ($user->first_login_at !== null
            || $user->portal_password_set_at !== null
            || $user->invitation_sent_at !== null) {
            return false;
        }

        try {
            $this->sendInvitation($customer, $actorId);
            return true;
        } catch (\Throwable $e) {
            \Log::warning('Automatische Portal-Einladung fehlgeschlagen (' . $user->email . '): ' . $e->getMessage());
            return false;
        }
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
     *
     * @return string Versandmodus wie sendInvitation() ('birthdate'/'setlink').
     */
    public function resetPortal(Customer $customer, ?int $actorId = null): string
    {
        $user = $customer->user;
        if ($user === null || !$user->hasRealEmail()) {
            throw new \RuntimeException('Kunde hat keine echte E-Mail-Adresse – Zurücksetzen nicht möglich.');
        }

        // must_change_password mit zuruecksetzen: nach einem Admin-Reset ist
        // das Passwort wieder ein vom SYSTEM vergebenes und muss beim
        // naechsten Login gegen ein eigenes getauscht werden. Ohne diese
        // Zeile behielte ein Kunde, der frueher schon einmal gewechselt hat,
        // dauerhaft sein Geburtsdatum als Passwort.
        $user->forceFill([
            'portal_password_set_at' => null,
            'must_change_password' => false,
        ])->save();
        $mode = $this->sendInvitation($customer, $actorId);

        ActivityLog::create([
            'user_id' => $actorId,
            'action' => 'portal_reset',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode(['email' => $user->email], JSON_UNESCAPED_UNICODE),
        ]);

        return $mode;
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
