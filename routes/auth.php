<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\PasswordSetupController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store'])->middleware('throttle:8,1');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    // Zusaetzlicher per-IP-Limiter gegen Password-Spraying ueber viele Konten;
    // der feinere email+IP-Limiter steckt weiterhin in LoginRequest. (Audit SEC-5)
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:20,1');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    // Throttle gegen Adress-Probing / Mail-Bombing beim Reset-Versand. (Audit SEC-4)
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    // Ergebnisseite "Wir haben Ihnen eine E-Mail geschickt" - erklaert die
    // naechsten Schritte, statt sie in einen gruenen Streifen zu quetschen
    // (Betreiber-Meldung: Kunden fanden den Weg nicht).
    Route::get('forgot-password/gesendet', [PasswordResetLinkController::class, 'sent'])
        ->name('password.request.sent');
});

/*
| Passwort aus einer EINLADUNG setzen (Kunde oder neuer Mitarbeiter).
| Signierter Link statt Klartext-Passwort in der Mail. Bewusst OHNE
| 'guest': wer per Magic-Login schon angemeldet ist, soll den Link aus
| derselben Mail trotzdem benutzen koennen. Die Signatur schuetzt den
| Vorgang; ein manipulierter Link ist ungueltig.
*/
Route::get('zugang/passwort-festlegen/{user}', [PasswordSetupController::class, 'create'])
    ->middleware(['signed:relative', 'throttle:20,1'])
    ->name('password.setup');

Route::post('zugang/passwort-festlegen/{user}', [PasswordSetupController::class, 'store'])
    ->middleware(['signed:relative', 'throttle:10,1'])
    ->name('password.setup.store');

// BEWUSST ohne 'guest': Kunden aus der Willkommens-Mail sind oft schon
// per Magic-Login eingeloggt, wenn sie den Passwort-Setzen-Link klicken.
// Mit 'guest' wurden sie still zum Dashboard umgeleitet und konnten nie
// ein Passwort festlegen. Der Token selbst schuetzt den Vorgang.
Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
    ->name('password.reset');

Route::post('reset-password', [NewPasswordController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('password.store');

Route::middleware('auth')->group(function () {
    /*
    | Erzwungener Passwortwechsel: Konten mit einem vom SYSTEM vergebenen
    | Passwort (Startpasswort = Geburtsdatum, Admin-Reset, CLI) kommen
    | ueber EnsurePasswordChanged hierher und erst weiter, wenn ein
    | eigenes Passwort steht.
    */
    Route::get('passwort-festlegen', [PasswordSetupController::class, 'forced'])
        ->name('password.forced');
    Route::post('passwort-festlegen', [PasswordSetupController::class, 'forcedStore'])
        ->middleware('throttle:10,1')
        ->name('password.forced.store');

    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    // Throttle wie bei Login/Reset: sonst kann ein gekaperte Session (z.B. ueber
    // einen geleakten Magic-Login-Link) das echte Passwort unbegrenzt raten und
    // damit die Passwort-Bestaetigung aushebeln (Audit AUTH-3).
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:6,1');

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
