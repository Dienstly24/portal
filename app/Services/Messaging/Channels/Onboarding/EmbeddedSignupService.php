<?php

namespace App\Services\Messaging\Channels\Onboarding;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Support\ChannelConnection;

/**
 * Der OFFIZIELLE Weg, eine WhatsApp-Nummer anzubinden (Auftrag 18/20).
 *
 * Meta nennt ihn Embedded Signup: der Betreiber meldet sich in einem
 * Meta-Fenster an, waehlt Unternehmen und Nummer, und wir bekommen einen
 * kurzlebigen CODE zurueck - kein Token. Den Code tauscht der SERVER
 * gegen ein Token. Genau diese Trennung ist der Grund fuer das
 * Verfahren: das App-Secret bleibt auf dem Server, und niemand muss
 * einen Token durch einen Chat schicken (dieselbe Regel wie bei
 * `meta:einrichten`).
 *
 * AUSDRUECKLICH NICHT: WhatsApp-Web-Automatisierung, QR-Umwege,
 * inoffizielle Schnittstellen, Sitzungs-Abgriff, Browser-Steuerung oder
 * ein Zwischenanbieter. Die Anbindung laeuft direkt gegen Meta.
 *
 * COEXISTENCE ist eine EIGENE Auswahl in diesem Fenster
 * (`featureType = whatsapp_business_app_onboarding`) - nicht ein
 * Nebeneffekt der Anbindung. Deshalb wird sie hier auch nur dann
 * vermerkt, wenn genau dieser Weg gegangen wurde.
 */
class EmbeddedSignupService
{
    public function __construct(private readonly MetaOnboardingClient $client) {}

    /** Ist der Weg ueberhaupt eingerichtet? */
    public function isConfigured(): bool
    {
        return $this->appId() !== '' && $this->configId() !== '' && $this->appSecret() !== '';
    }

    public function appId(): string
    {
        return (string) config('services.meta.app_id');
    }

    public function configId(): string
    {
        return (string) config('services.meta.es_config_id');
    }

    private function appSecret(): string
    {
        return (string) config('services.meta.app_secret');
    }

    /**
     * Was der Browser braucht - und NUR das.
     *
     * @return array<string,string>
     */
    public function browserConfig(): array
    {
        return [
            'appId' => $this->appId(),
            'configId' => $this->configId(),
            'graphVersion' => (string) config('services.meta.graph_version', 'v23.0'),
        ];
    }

    /**
     * Den Code einloesen und das Konto anlegen bzw. aktualisieren.
     *
     * @param  bool  $coexistence  ob im Fenster der Business-App-Weg gewaehlt wurde
     *
     * @throws OnboardingFailed
     */
    public function complete(
        string $code,
        string $wabaId,
        string $phoneNumberId,
        bool $coexistence,
        string $verifyToken,
    ): ChannelAccount {
        if (! $this->isConfigured()) {
            throw new OnboardingFailed('Die WhatsApp-Anbindung ist auf dem Server noch nicht eingerichtet.');
        }

        // 1. Code gegen Token - auf dem SERVER, mit dem App-Secret.
        //    Der ABLAUF kommt mit: die gaengige Vorlage bei Meta vergibt
        //    60 Tage. Ohne diesen Wert stuende das Konto bis zum Tag des
        //    Ausfalls auf "verbunden".
        $zugang = $this->client->exchangeCode($code);
        $token = $zugang['token'];

        // 2. Erst pruefen, dann speichern: ein Konto, dessen Zugang gar
        //    nicht traegt, waere ein "verbunden", das nichts kann.
        $nummer = $this->client->phoneNumber($phoneNumberId, $token);

        $kanal = Channel::where('key', 'whatsapp')->firstOrFail();

        $konto = ChannelAccount::firstOrNew([
            'channel_id' => $kanal->id,
            'external_account_id' => $phoneNumberId,
        ]);

        // Bestehende Zugangsdaten werden ERGAENZT, nicht ersetzt: ein
        // bereits gepflegtes App-Secret oder Bestaetigungs-Token darf
        // eine erneute Anbindung nicht loeschen.
        $zugangsdaten = $konto->credentials ?? [];
        $zugangsdaten['access_token'] = $token;
        $zugangsdaten['phone_number_id'] = $phoneNumberId;
        $zugangsdaten['app_secret'] = $this->appSecret();
        $zugangsdaten['verify_token'] = $verifyToken;

        $konto->fill([
            'name' => $nummer['display_phone_number'] ?? ('WhatsApp '.$phoneNumberId),
            'credentials' => $zugangsdaten,
            'token_expires_at' => $zugang['expires_at'],
            'waba_id' => $wabaId,
            'connection_type' => $coexistence
                ? ChannelConnection::TYPE_COEXISTENCE
                : ChannelConnection::TYPE_CLOUD_API,
            'is_active' => true,
        ])->save();

        // 3. Webhook-Abonnement auf dem WABA. Ohne diesen Schritt kommt
        //    kein einziges Ereignis an, und die Anbindung sieht trotzdem
        //    fertig aus - deshalb entscheidet er ueber den Zustand.
        try {
            $this->client->subscribeWaba($wabaId, $token);
            $konto->markConnection(ChannelConnection::CONNECTED);
        } catch (\Throwable $e) {
            $konto->markConnection(
                ChannelConnection::WEBHOOK_ERROR,
                'Die Anmeldung hat geklappt, das Webhook-Abonnement nicht. '
                .'Bitte in der Meta-App die Webhook-URL und die Felder pruefen.'
            );
        }

        return $konto->fresh();
    }
}
