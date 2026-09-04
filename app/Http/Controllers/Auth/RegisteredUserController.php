<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\RegistrationVerificationMail;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Services\CustomerNumberGenerator;
use App\Services\Security\TurnstileVerifier;
use App\Support\PasswordPolicy;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Selbst-Registrierung für Neukunden ("Noch kein Konto? Konto erstellen").
 *
 * ZWEISTUFIG seit Audit SEC-1. Vorher legte ein einziger, oeffentlicher
 * POST sofort einen User, eine vollstaendige Kundenakte MIT laufender
 * Kundennummer und eine angemeldete Sitzung an - ohne jeden Beweis, dass
 * die E-Mail-Adresse dem Absender gehoert. Ein Bot konnte damit in Serie
 * echte Kundenakten erzeugen; die vergebenen Nummern des Jahreskreises
 * (JJ + 5-stellig) waren danach verbraucht.
 *
 * Schritt 1 (store): Bot-Schutz pruefen, Angaben in `pending_registrations`
 *   vormerken, Bestaetigungsmail verschicken. Es entsteht KEIN User,
 *   KEINE Kundenakte, KEINE Kundennummer und KEINE Sitzung.
 * Schritt 2 (verify): Erst der Klick auf den Link im Postfach legt
 *   User + Kundenakte + Kundennummer an und meldet an.
 *
 * Warum nicht einfach `User implements MustVerifyEmail`?
 *  - Dann existierte das Konto (und damit ein Login- und ein
 *    Enumerationsziel) bereits vor der Bestaetigung.
 *  - Die Kundennummer waere weiterhin sofort verbraucht.
 *  - Und alle ueber die Beraterwelt eingeladenen BESTANDSKUNDEN, die sich
 *    nie selbst registriert haben, waeren schlagartig "unbestaetigt"
 *    gewesen - ein Massenausfall im Kundenportal fuer ein Problem, das
 *    nur die Selbst-Registrierung hat.
 *
 * Schutzschichten in dieser Reihenfolge (guenstig vor teuer):
 *  1. Route-Throttle (IP + Adresse, siehe routes/auth.php)
 *  2. Honeypot-Feld "website"
 *  3. Cloudflare Turnstile, SERVERSEITIG geprueft
 *  4. Validierung
 *  5. Erst dann ein Schreibvorgang und ein Mailversand
 */
class RegisteredUserController extends Controller
{
    public function create(TurnstileVerifier $turnstile): View
    {
        return view('auth.register', [
            'turnstileSiteKey' => $turnstile->siteKey(),
        ]);
    }

    /**
     * Schritt 1: Angaben vormerken und Bestaetigungsmail verschicken.
     */
    public function store(Request $request, TurnstileVerifier $turnstile): RedirectResponse
    {
        // Honeypot: von Bots ausgefuellt, von Menschen unsichtbar/leer.
        if ($request->filled('website')) {
            abort(422);
        }

        // Bot-Schutz VOR der Validierung: eine abgelehnte Anfrage soll
        // moeglichst wenig kosten und ueber die Feldpruefung nichts
        // verraten (z. B. ob eine Adresse schon vergeben ist).
        if (! $turnstile->verify($request->input('cf-turnstile-response'), $request->ip())) {
            throw ValidationException::withMessages([
                'cf-turnstile-response' => __('Die Sicherheitsprüfung ist fehlgeschlagen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.'),
            ]);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            // Eindeutig gegen BEIDE Tabellen: ein bestehendes Konto und
            // eine laufende Vormerkung blockieren die Adresse gleichermassen.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255',
                'unique:'.User::class],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'password' => ['required', 'confirmed', PasswordPolicy::customer()],
            'agb' => ['accepted'],
            // Freiwillige, getrennte Einwilligung zur E-Mail-Archivierung
            // (Art. 7 DSGVO) - darf leer bleiben, keine Kopplung an die AGB.
            'email_consent' => ['sometimes', 'accepted'],
        ]);

        $email = strtolower(trim($data['email']));

        // Laeuft fuer diese Adresse bereits eine Vormerkung? Dann wird
        // KEINE zweite angelegt, sondern hoechstens erneut gesendet. Sonst
        // waere "immer wieder registrieren" der bequemste Weg, eine fremde
        // Adresse zuzuspammen.
        $pending = PendingRegistration::where('email', $email)->first();

        if ($pending && ! $pending->isExpired()) {
            $this->resend($pending);

            // Dieselbe Antwort wie beim Erstversuch: ob zu dieser Adresse
            // schon etwas laeuft, geht den Absender nichts an.
            return redirect()->route('register.pending')->with('registered_email', $email);
        }

        // Abgelaufene Vormerkung derselben Adresse ersetzen.
        $pending?->delete();

        $token = PendingRegistration::newToken();

        $pending = PendingRegistration::create([
            'email' => $email,
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'birth_date' => ($data['birth_date'] ?? '') ?: null,
            // Bereits gehasht: ein Klartext-Passwort liegt zu keinem
            // Zeitpunkt in der Datenbank.
            'password' => Hash::make($data['password']),
            'token_hash' => PendingRegistration::hashToken($token),
            'email_consent' => $request->boolean('email_consent'),
            'preferred_lang' => in_array(app()->getLocale(), ['de', 'ar'], true) ? app()->getLocale() : 'de',
            'register_ip' => $request->ip(),
            'send_count' => 1,
            'last_sent_at' => now(),
            'expires_at' => PendingRegistration::freshExpiry(),
        ]);

        $this->sendVerificationMail($pending, $token);

        return redirect()->route('register.pending')->with('registered_email', $email);
    }

    /** Zwischenseite "Bitte bestaetigen Sie Ihre E-Mail-Adresse". */
    public function pending(Request $request): View
    {
        return view('auth.register-pending', [
            'email' => (string) $request->session()->get('registered_email', ''),
        ]);
    }

    /**
     * Schritt 2: Der Klick aus dem Postfach. ERST HIER entstehen User,
     * Kundenakte und Kundennummer.
     */
    public function verify(Request $request, string $token): RedirectResponse
    {
        $pending = PendingRegistration::where(
            'token_hash',
            PendingRegistration::hashToken($token)
        )->first();

        if (! $pending) {
            return redirect()->route('register')
                ->withErrors(['email' => __('Dieser Bestätigungslink ist ungültig. Bitte registrieren Sie sich erneut.')]);
        }

        if ($pending->isExpired()) {
            $pending->delete();

            return redirect()->route('register')
                ->withErrors(['email' => __('Dieser Bestätigungslink ist abgelaufen. Bitte registrieren Sie sich erneut.')]);
        }

        // Zwischen Vormerkung und Klick kann dieselbe Adresse ueber einen
        // anderen Weg (Einladung durch einen Mitarbeiter, Import) ein
        // Konto bekommen haben. Dann wird KEIN zweites angelegt.
        if (User::where('email', $pending->email)->exists()) {
            $pending->delete();

            return redirect()->route('login')
                ->withErrors(['email' => __('Für diese E-Mail-Adresse besteht bereits ein Konto. Bitte melden Sie sich an.')]);
        }

        $user = DB::transaction(function () use ($pending, $request) {
            $user = User::create([
                'name' => $pending->fullName(),
                'email' => $pending->email,
                // Der bereits gehashte Wert wird durchgereicht, nicht
                // erneut gehasht.
                'password' => $pending->password,
                'role' => 'customer',
            ]);

            // Beide Zeitstempel stehen NICHT in User::$fillable und
            // wuerden ueber create() still verschluckt - genau das ist
            // dem alten Code mit portal_password_set_at passiert
            // (die Kundenakte zeigte "Passwort eingerichtet" nie an).
            // forceFill schreibt sie sichtbar.
            $user->forceFill([
                'email_verified_at' => now(),
                'portal_password_set_at' => now(),
            ])->save();

            $customer = Customer::create([
                'user_id' => $user->id,
                // ERST JETZT wird eine Kundennummer verbraucht.
                'customer_number' => app(CustomerNumberGenerator::class)->generate(),
                'source' => 'website',
                'birth_date' => $pending->birth_date,
                'preferred_lang' => $pending->preferred_lang,
            ]);

            if ($pending->email_consent) {
                // Die Einwilligung wird mit der IP des BESTAETIGENDEN
                // Aufrufs erfasst - das ist der Zeitpunkt, zu dem die
                // Person nachweislich Zugriff auf das Postfach hatte.
                CustomerConsent::create([
                    'customer_id' => $customer->id,
                    'type' => CustomerConsent::TYPE_EMAIL_PROCESSING,
                    'granted_at' => now(),
                    'consent_text_version' => CustomerConsent::EMAIL_TEXT_VERSION,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 512),
                    'source' => 'portal_registration',
                    'import_token' => CustomerConsent::newImportToken(),
                ]);
            }

            $pending->delete();

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);
        // Session-Fixation: nach jedem Anmelden eine neue Sitzungs-ID.
        $request->session()->regenerate();

        return redirect()->route('portal.dashboard')
            ->with('success', __('Willkommen! Ihre E-Mail-Adresse ist bestätigt.'));
    }

    /**
     * Bestaetigungsmail erneut anfordern.
     *
     * Bewusst IMMER dieselbe Antwort - ob zu einer Adresse eine
     * Vormerkung laeuft, ist eine Auskunft, die es nicht geben soll
     * (Enumeration, DSGVO Art. 32). Gedeckelt wird ueber den Zaehler in
     * der Vormerkung, nicht nur ueber einen Zeit-Throttle: ein
     * Zeit-Throttle gibt nach Ablauf wieder Luft, der Zaehler nicht.
     */
    public function resendRequest(Request $request): RedirectResponse
    {
        $email = strtolower(trim((string) $request->input('email')));

        if ($email !== '') {
            $pending = PendingRegistration::where('email', $email)->first();
            if ($pending && ! $pending->isExpired()) {
                $this->resend($pending);
            }
        }

        return back()->with('status', __('Falls für diese Adresse eine Anmeldung vorliegt, haben wir die Bestätigungsmail erneut verschickt.'));
    }

    /** Erneut senden, sofern Zaehler und Wartezeit es erlauben. */
    private function resend(PendingRegistration $pending): void
    {
        if (! $pending->mayResend()) {
            return;
        }

        // Neues Token: der alte Link wird damit ungueltig. Ein
        // weitergeleiteter oder abgefangener alter Link laeuft ins Leere.
        $token = PendingRegistration::newToken();

        $pending->forceFill([
            'token_hash' => PendingRegistration::hashToken($token),
            'send_count' => $pending->send_count + 1,
            'last_sent_at' => now(),
            'expires_at' => PendingRegistration::freshExpiry(),
        ])->save();

        $this->sendVerificationMail($pending, $token);
    }

    private function sendVerificationMail(PendingRegistration $pending, string $token): void
    {
        $url = route('register.verify', ['token' => $token]);

        try {
            Mail::to($pending->email)->send(new RegistrationVerificationMail(
                name: $pending->fullName(),
                verifyUrl: $url,
                validHours: PendingRegistration::LIFETIME_HOURS,
            ));
        } catch (\Throwable $e) {
            // Der Versand darf die Vormerkung nicht mitreissen: sonst
            // steht die Adresse blockiert da, ohne dass je eine Mail
            // ankam. Der Nutzer kann "erneut senden" ausloesen.
            Log::error('Bestaetigungsmail zur Registrierung fehlgeschlagen: '.$e->getMessage());
        }
    }
}
