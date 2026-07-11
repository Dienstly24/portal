<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'remember_token' => Str::random(60),
                    // Portal-Status: ab jetzt existiert ein selbst
                    // gewähltes, nutzbares Passwort.
                    'portal_password_set_at' => now(),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // Deutsche Meldungen statt englischer Framework-Texte.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', 'Ihr Passwort wurde geändert. Sie können sich jetzt anmelden.')
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => match ($status) {
                            Password::INVALID_TOKEN => 'Dieser Link ist abgelaufen oder ungültig. Bitte fordern Sie einen neuen Link an.',
                            Password::INVALID_USER => 'Zu dieser E-Mail-Adresse haben wir kein Konto gefunden.',
                            default => 'Das Passwort konnte nicht geändert werden. Bitte fordern Sie einen neuen Link an.',
                        }]);
    }
}
