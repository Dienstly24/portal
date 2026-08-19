<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * "Passwort vergessen" - bewusst auf Kunden zugeschnitten
 * (Betreiber-Meldung 18.08.2026: "Kunden wissen nicht, wie sie ihr
 * Passwort zuruecksetzen").
 *
 * Drei konkrete Aenderungen gegenueber vorher:
 *
 * 1. KUNDENNUMMER ist als Kennung erlaubt. Viele Kunden wissen nicht
 *    (mehr), mit welcher E-Mail-Adresse ihr Zugang angelegt wurde -
 *    die Kundennummer steht dagegen auf JEDEM Schreiben, das sie von
 *    uns bekommen. Wir schicken die Mail dann an die hinterlegte
 *    Adresse; genannt wird sie nie (siehe 2.).
 *
 * 2. IMMER dieselbe Antwort, egal ob das Konto existiert. Vorher
 *    verriet "Zu dieser E-Mail-Adresse haben wir kein Konto gefunden"
 *    jedem Fremden, welche Adressen Kunde bei uns sind (Enumeration,
 *    DSGVO Art. 32). Die Ergebnisseite erklaert dafuer ausfuehrlich,
 *    was jetzt passiert und was zu tun ist, wenn nichts ankommt - das
 *    hilft dem echten Kunden mehr als eine Fehlermeldung.
 *
 * 3. Ergebnis ist eine EIGENE SEITE statt eines gruenen Streifens ueber
 *    dem Formular. Der Streifen wurde uebersehen; Kunden tippten die
 *    Adresse noch dreimal ein und riefen dann an.
 *
 * Einzige Ausnahme von (2): die internen Import-Platzhalter
 * (@dienstly24.internal). Die kann niemand von aussen erraten und sie
 * koennen technisch keine Mail empfangen - hier ist der klare Hinweis
 * hilfreicher als eine Schweige-Antwort.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /** Ergebnisseite - erklaert die naechsten Schritte. */
    public function sent(): View
    {
        return view('auth.forgot-password-sent', [
            'validMinutes' => (int) config('auth.passwords.users.expire', 60),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // 'email' bleibt als Feldname erlaubt: aeltere Lesezeichen, die
        // Passwort-Verwaltung des Browsers und bestehende Verlinkungen
        // schicken weiterhin dieses Feld. Wer es abwuerfe, produziert eine
        // stumme Fehlermeldung genau in dem Formular, das gerade
        // verstaendlicher werden soll.
        $request->merge([
            'identifier' => $request->input('identifier') ?? $request->input('email'),
        ]);

        $request->validate(
            ['identifier' => ['required', 'string', 'max:190']],
            ['identifier.required' => 'Bitte geben Sie Ihre E-Mail-Adresse oder Ihre Kundennummer ein.'],
        );

        $identifier = trim((string) $request->input('identifier'));

        // Interne Import-Adressen koennen keine Mails empfangen.
        if (str_contains(mb_strtolower($identifier), '@dienstly24.internal')) {
            return back()->withInput($request->only('identifier'))->withErrors([
                'identifier' => 'Für dieses Konto ist kein E-Mail-Versand möglich. '
                    . 'Bitte wenden Sie sich an uns, damit wir Ihre Zugangsdaten einrichten.',
            ]);
        }

        $email = $this->resolveEmail($identifier);

        // Kein Treffer: bewusst dieselbe Antwort wie im Erfolgsfall.
        if ($email !== null) {
            try {
                $status = Password::sendResetLink(['email' => $email]);
                if ($status !== Password::RESET_LINK_SENT && $status !== Password::RESET_THROTTLED) {
                    Log::info('Passwort-Reset ohne Versand, Status: ' . $status);
                }
            } catch (\Throwable $e) {
                // Versandfehler wird protokolliert, aber nicht nach aussen
                // unterschieden - sonst waere er wieder ein Enumerations-
                // Signal. Der Kunde findet auf der Ergebnisseite den Weg
                // ueber die Hilfe.
                Log::error('Passwort-Reset-Versand fehlgeschlagen: ' . $e->getMessage());
            }
        }

        return redirect()->route('password.request.sent');
    }

    /**
     * Kennung -> Login-E-Mail. Erlaubt sind die E-Mail-Adresse selbst
     * (Login-Adresse oder die hinterlegte Zweitadresse) und die
     * Kundennummer. Rueckgabe null = kein Konto (Aufrufer schweigt).
     */
    private function resolveEmail(string $identifier): ?string
    {
        // a) sieht aus wie eine E-Mail -> direkt am Konto suchen
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $email = mb_strtolower($identifier);

            if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
                return $email;
            }

            // Zweitadresse (email2) der Kundenakte: Kunden nennen oft die
            // Adresse, unter der wir sie anschreiben - nicht die, mit der
            // der Zugang angelegt wurde.
            $customer = Customer::whereRaw('LOWER(email2) = ?', [$email])->first();

            return $customer?->user?->hasRealEmail() ? $customer->user->email : null;
        }

        // b) Kundennummer (auch alte "C-..."-Nummern, Gross-/Kleinschreibung egal)
        $number = mb_strtoupper(preg_replace('/\s+/', '', $identifier) ?? '');
        if ($number === '') {
            return null;
        }

        $customer = Customer::whereRaw('UPPER(customer_number) = ?', [$number])->first();

        return $customer?->user?->hasRealEmail() ? $customer->user->email : null;
    }
}
