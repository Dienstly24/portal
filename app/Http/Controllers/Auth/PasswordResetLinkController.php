<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Reset-Link anfordern. Härtungen:
     * - Import-Platzhalter-Adressen (@dienstly24.internal) sind nicht
     *   erreichbar -> klare Meldung statt Mailer-Exception.
     * - Versand-/Serverfehler werden abgefangen und als verständliche
     *   deutsche Meldung angezeigt (vorher: HTTP 500 beim Kunden).
     * - Deutsche Statusmeldungen statt englischer Framework-Texte.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['email' => ['required', 'email']],
            ['email.required' => 'Bitte geben Sie Ihre E-Mail-Adresse ein.',
             'email.email' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.']
        );

        $email = mb_strtolower(trim($request->input('email')));

        // Anti-Enumeration (Audit AUTH-2): ein existierendes, ein unbekanntes und
        // ein Import-Platzhalter-Konto muessen die GLEICHE Antwort erhalten -
        // sonst ist der Reset-Dialog ein Orakel "ist X Kunde bei uns?".
        $genericSent = 'Falls ein Konto mit dieser E-Mail-Adresse existiert, haben wir Ihnen einen Link zum Zurücksetzen des Passworts gesendet. Bitte prüfen Sie auch Ihren Spam-Ordner.';

        // Interne Import-Adressen können keine Mails empfangen - NICHT senden,
        // aber die gleiche neutrale Meldung zeigen (kein Konto-Orakel).
        if (str_contains($email, '@dienstly24.internal')) {
            return back()->with('status', $genericSent);
        }

        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (\Throwable $e) {
            \Log::error('Passwort-Reset-Versand fehlgeschlagen für ' . $email . ': ' . $e->getMessage());
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Die E-Mail konnte gerade nicht versendet werden. Bitte versuchen Sie es in einigen Minuten erneut oder kontaktieren Sie uns.',
            ]);
        }

        // Erfolg UND "kein Konto" liefern die identische Meldung. Nur echtes
        // Throttling wird neutral genannt (verraet kein Konto - es begrenzt die
        // Anfrage-Rate der IP/Adresse unabhaengig von der Existenz).
        if ($status === Password::RESET_LINK_SENT || $status === Password::INVALID_USER) {
            return back()->with('status', $genericSent);
        }

        return back()->withInput($request->only('email'))->withErrors([
            'email' => $status === Password::RESET_THROTTLED
                ? 'Zu viele Anfragen. Bitte warten Sie einen Moment und versuchen Sie es erneut.'
                : 'Der Link konnte nicht versendet werden. Bitte versuchen Sie es erneut.',
        ]);
    }
}
