<?php

namespace App\Services\Messaging\Channels\Onboarding;

use Illuminate\Support\Facades\Http;

/**
 * Die drei Aufrufe bei Meta, die eine Anbindung ausmachen.
 *
 * Bewusst eine EIGENE Klasse und nicht `MetaGraphClient`: der holt sein
 * Token aus der Konfiguration und spricht fuer UNSER Konto. Hier geht es
 * um ein FREMDES Token, das gerade erst entsteht - beides in einer
 * Klasse zu fuehren hiesse, dem Aufrufer die Wahl zu lassen, welches
 * Token gerade gilt. Genau daraus entstehen Anbindungen, die im Test
 * gehen und in Produktion das falsche Konto ansprechen.
 *
 * Zeitgrenzen wie ueberall (Lehre 20.08.2026): kein Web-Request wartet
 * minutenlang auf einen fremden Dienst.
 */
class MetaOnboardingClient
{
    private function base(): string
    {
        $version = (string) config('services.meta.graph_version', 'v23.0');

        return 'https://graph.facebook.com/'.$version;
    }

    private function http()
    {
        return Http::timeout(15)->connectTimeout(5)->acceptJson();
    }

    /**
     * Kurzlebigen Code gegen ein Token tauschen.
     *
     * Das App-Secret geht hier raus - deshalb passiert dieser Aufruf
     * ausschliesslich auf dem Server. Der Code ist einmalig und nur
     * wenige Minuten gueltig; ein abgefangener Code ohne Secret ist
     * wertlos, ein Secret im Browser dagegen ein Dauerschluessel.
     *
     * @throws OnboardingFailed
     */
    public function exchangeCode(string $code): string
    {
        $antwort = $this->http()->get($this->base().'/oauth/access_token', [
            'client_id' => (string) config('services.meta.app_id'),
            'client_secret' => (string) config('services.meta.app_secret'),
            'code' => $code,
        ]);

        $token = (string) $antwort->json('access_token', '');

        if (! $antwort->successful() || $token === '') {
            // Der fremde Wortlaut wird NICHT durchgereicht: er ist auf
            // Englisch, nennt interne Codes und hilft niemandem hier.
            throw new OnboardingFailed(
                'Meta hat die Anmeldung nicht bestaetigt. Bitte den Vorgang erneut starten.'
            );
        }

        return $token;
    }

    /**
     * Die Nummer selbst - der Beweis, dass das Token traegt.
     *
     * @return array<string,mixed>
     *
     * @throws OnboardingFailed
     */
    public function phoneNumber(string $phoneNumberId, string $token): array
    {
        $antwort = $this->http()->withToken($token)->get($this->base().'/'.$phoneNumberId, [
            'fields' => 'display_phone_number,verified_name,quality_rating,platform_type',
        ]);

        if (! $antwort->successful()) {
            throw new OnboardingFailed(
                'Die Nummer konnte mit dem erhaltenen Zugang nicht gelesen werden.'
            );
        }

        return (array) $antwort->json();
    }

    /**
     * Unsere App auf die Ereignisse des WhatsApp Business Accounts
     * abonnieren. OHNE diesen Schritt kommt kein einziger Webhook an -
     * und die Anbindung sieht trotzdem fertig aus.
     *
     * @throws OnboardingFailed
     */
    public function subscribeWaba(string $wabaId, string $token): void
    {
        $antwort = $this->http()->withToken($token)
            ->post($this->base().'/'.$wabaId.'/subscribed_apps');

        if (! $antwort->successful() || $antwort->json('success') !== true) {
            throw new OnboardingFailed('Das Webhook-Abonnement wurde von Meta nicht bestaetigt.');
        }
    }
}
