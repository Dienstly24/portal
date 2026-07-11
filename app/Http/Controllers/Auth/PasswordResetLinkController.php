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

        // Interne Import-Adressen können keine Mails empfangen.
        if (str_contains($email, '@dienstly24.internal')) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Für dieses Konto ist kein E-Mail-Versand möglich. Bitte wenden Sie sich an uns, damit wir Ihre Zugangsdaten einrichten.',
            ]);
        }

        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (\Throwable $e) {
            \Log::error('Passwort-Reset-Versand fehlgeschlagen für ' . $email . ': ' . $e->getMessage());
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Die E-Mail konnte gerade nicht versendet werden. Bitte versuchen Sie es in einigen Minuten erneut oder kontaktieren Sie uns.',
            ]);
        }

        return $status == Password::RESET_LINK_SENT
            ? back()->with('status', 'Wir haben Ihnen einen Link zum Zurücksetzen des Passworts per E-Mail gesendet. Bitte prüfen Sie auch Ihren Spam-Ordner.')
            : back()->withInput($request->only('email'))->withErrors([
                'email' => match ($status) {
                    Password::INVALID_USER => 'Zu dieser E-Mail-Adresse haben wir kein Konto gefunden.',
                    Password::RESET_THROTTLED => 'Sie haben bereits kürzlich einen Link angefordert. Bitte warten Sie einen Moment.',
                    default => 'Der Link konnte nicht versendet werden. Bitte versuchen Sie es erneut.',
                },
            ]);
    }
}
