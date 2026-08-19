<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zwingt zum Setzen eines EIGENEN Passworts, bevor irgendetwas anderes
 * benutzbar ist (Betreiber-Vorgabe 18.08.2026).
 *
 * Hintergrund: Das Start-Passwort im Kundenportal ist das GEBURTSDATUM.
 * Das steht auf jedem Ausweis, in jedem Versicherungsschein und in jeder
 * Meldebestaetigung - es ist faktisch oeffentlich. Bisher konnte ein
 * Kunde damit dauerhaft weiterarbeiten; ein Wechsel wurde nur
 * empfohlen. Dasselbe galt fuer vom Admin vergebene Mitarbeiter-
 * Passwoerter.
 *
 * Ab jetzt: Konto mit system-vergebenem Passwort -> jede Seite fuehrt
 * zum Passwort-Bildschirm. Bewusst NICHT gesperrt sind Abmelden, der
 * Passwort-Bildschirm selbst, die Rechtsseiten und der Sprachwechsel -
 * sonst sitzt jemand in einer Sackgasse, die er nicht einmal verlassen
 * kann.
 *
 * Nicht-HTML-Anfragen (JSON/Feeds im Portal) bekommen 409 statt einer
 * Weiterleitung: ein Redirect auf HTML wuerde dort still als kaputte
 * Antwort ankommen.
 */
class EnsurePasswordChanged
{
    /** Routen, die trotz faelligem Wechsel erreichbar bleiben muessen. */
    private const ALLOWED_ROUTES = [
        // Der Bildschirm selbst - sonst Endlos-Weiterleitung.
        'password.forced',
        'password.forced.store',
        // Sackgasse vermeiden: abmelden, Sprache wechseln, Rechtstexte lesen
        // muss immer moeglich bleiben.
        'logout',
        'locale.switch',
        'legal',
        'password.confirm',
        // Die regulaeren Passwort-Formulare erfuellen die Forderung ebenso -
        // beide laufen ueber User::setPassword() und heben den Zwang auf.
        // Wer den Wechsel dort erledigt, soll nicht abgewiesen werden.
        'portal.profile.password',
        'password.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->needsPasswordChange()) {
            return $next($request);
        }

        $route = $request->route()?->getName();
        if ($route !== null && in_array($route, self::ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        // Nie den Bildschirm selbst blockieren, auch ohne Routennamen.
        if ($request->is('passwort-festlegen', 'passwort-festlegen/*')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => __('Bitte legen Sie zuerst ein eigenes Passwort fest.'),
            ], 409);
        }

        return redirect()->route('password.forced');
    }
}
