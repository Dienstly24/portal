<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Serverseitige Pruefung von Cloudflare Turnstile (Audit SEC-1).
 *
 * Der Punkt der Klasse ist das Wort SERVERSEITIG. Ein Bot, der
 * POST /register direkt anspricht, fuehrt kein Browser-JavaScript aus -
 * ein Widget im Formular sieht er gar nicht. Erst der Abgleich des
 * Tokens gegen Cloudflares siteverify-Endpunkt sagt etwas aus.
 *
 * FEHLERVERHALTEN: anders als beim HaveIBeenPwned-Abgleich (der bei
 * Netzproblemen auf "bestanden" faellt, weil er sonst legitime Nutzer
 * aussperrt) gilt hier "im Zweifel ABGELEHNT". Ein Passwort-Abgleich,
 * der ausfaellt, kostet eine Schutzschicht; ein Bot-Schutz, der bei
 * Netzproblemen durchwinkt, ist der Weg, ihn absichtlich auszuschalten
 * (der Angreifer muss nur den Ausfall provozieren).
 */
class TurnstileVerifier
{
    /** Ist Turnstile konfiguriert? */
    public function configured(): bool
    {
        return trim((string) config('services.turnstile.secret_key')) !== '';
    }

    /**
     * MUSS in dieser Umgebung ein gueltiges Token vorliegen?
     *
     * Produktion: ja - immer. Eine Registrierung ohne Bot-Schutz ist
     * genau der Zustand, den SEC-1 beseitigt; sie darf nicht durch eine
     * vergessene .env-Zeile zurueckkommen.
     * Lokal/Test: nur, wenn ein Secret gesetzt ist. Sonst waere die
     * Registrierung in der Entwicklung gar nicht mehr benutzbar.
     */
    public function required(): bool
    {
        if (app()->environment('production')) {
            return true;
        }

        return $this->configured();
    }

    /**
     * Prueft das Token des Formulars.
     *
     * @param string|null $token   Feld cf-turnstile-response
     * @param string|null $remoteIp Client-IP (aus der vertrauenswuerdigen Proxy-Kette)
     */
    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        if (! $this->required()) {
            // Entwicklung ohne Secret: der Honeypot bleibt als Schicht.
            return true;
        }

        if (! $this->configured()) {
            // Produktion ohne Secret: NICHT durchwinken. Lieber eine
            // sichtbar kaputte Registrierung als eine stillschweigend
            // ungeschuetzte - kaputt faellt auf, ungeschuetzt nicht.
            Log::error('Turnstile: TURNSTILE_SECRET_KEY fehlt - Registrierung wird abgelehnt.');

            return false;
        }

        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.turnstile.timeout', 5))
                ->connectTimeout(3)
                ->post((string) config('services.turnstile.verify_url'), array_filter([
                    'secret' => (string) config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ], fn ($v) => $v !== null && $v !== ''));
        } catch (\Throwable $e) {
            // Siehe Klassenkommentar: Ausfall = Ablehnung.
            Log::warning('Turnstile nicht erreichbar: ' . $e->getMessage());

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Turnstile antwortete mit HTTP ' . $response->status());

            return false;
        }

        $data = $response->json();
        if (! is_array($data) || ($data['success'] ?? false) !== true) {
            Log::info('Turnstile abgelehnt', [
                'codes' => $data['error-codes'] ?? null,
            ]);

            return false;
        }

        return true;
    }

    /** Der oeffentliche Schluessel fuers Widget (leer = kein Widget). */
    public function siteKey(): string
    {
        return (string) config('services.turnstile.site_key', '');
    }
}
