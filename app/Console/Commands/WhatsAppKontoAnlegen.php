<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Services\Messaging\Channels\Onboarding\MetaOnboardingClient;
use App\Services\Messaging\Channels\Onboarding\OnboardingFailed;
use App\Services\Messaging\Channels\WhatsAppAdapter;
use App\Support\ChannelConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * WhatsApp-Nummer OHNE Embedded Signup anbinden (Betreiber-Auftrag
 * 19.11.2026: Testnummer von Meta fuer das Video zum App Review).
 *
 * Derselbe Endzustand wie `EmbeddedSignupService::complete()` - ein
 * aktives Konto mit Token, Rufnummern-Kennung und App-Secret, und das
 * Abonnement auf dem WABA. Nur der Weg zum Token ist ein anderer: der
 * Betreiber fuegt es selbst ein.
 *
 * DAS TOKEN wird NUR unsichtbar abgefragt - nie als Option (stuende in
 * der Shell-Historie und in `ps`), nie in einer Ausgabe, nie im
 * ActivityLog. Gespeichert wird es verschluesselt in den Zugangsdaten
 * des Kontos, wie bei jeder anderen Anbindung.
 *
 * ERST PRUEFEN, DANN SPEICHERN: traegt das Token die Nummer nicht, wird
 * nichts angelegt - ein "aktives" Konto, das nichts kann, ist schlimmer
 * als keins.
 */
class WhatsAppKontoAnlegen extends Command
{
    protected $signature = 'whatsapp:add-account
        {--phone-number-id= : Phone Number ID aus dem Meta-App-Dashboard}
        {--waba-id= : WhatsApp Business Account ID}
        {--name= : Anzeigename im Portal (Standard: die Rufnummer laut Meta)}
        {--token-tage= : Gueltigkeit des Tokens in Tagen, falls bekannt (temporaeres Token = 1)}';

    protected $description = 'WhatsApp-Nummer manuell anbinden (ohne Embedded Signup): Konto anlegen und WABA abonnieren';

    public function handle(MetaOnboardingClient $meta): int
    {
        $phoneId = trim((string) $this->option('phone-number-id'));
        $wabaId = trim((string) $this->option('waba-id'));

        if (! ctype_digit($phoneId) || ! ctype_digit($wabaId)) {
            $this->error('--phone-number-id und --waba-id sind Pflicht und bestehen nur aus Ziffern.');

            return self::FAILURE;
        }

        $appSecret = (string) config('services.meta.app_secret');
        if ($appSecret === '') {
            $this->error('META_APP_SECRET fehlt in der .env - ohne ihn wird jede Zustellung von Meta abgewiesen.');

            return self::FAILURE;
        }

        $kanal = Channel::where('key', WhatsAppAdapter::KEY)->first();
        if (! $kanal) {
            $this->error('Der Kanal "whatsapp" existiert nicht - zuerst die Migrationen ausfuehren.');

            return self::FAILURE;
        }

        $token = trim((string) $this->secret('Access Token einfuegen (Eingabe bleibt unsichtbar)'));
        if ($token === '') {
            $this->error('Kein Token eingegeben - es wurde nichts geaendert.');

            return self::FAILURE;
        }

        // 1. Traegt das Token die Nummer?
        try {
            $nummer = $meta->phoneNumber($phoneId, $token);
        } catch (OnboardingFailed $e) {
            $this->error($e->getMessage().' Token, Phone Number ID und Berechtigungen pruefen. Es wurde nichts angelegt.');

            return self::FAILURE;
        }

        $anzeige = (string) ($nummer['display_phone_number'] ?? '');
        $this->info('Nummer bei Meta gefunden: '.($anzeige ?: $phoneId)
            .(isset($nummer['verified_name']) ? ' ('.$nummer['verified_name'].')' : ''));

        // 2. Konto anlegen bzw. ergaenzen - derselbe Schluessel wie beim
        //    Embedded Signup, damit es nie zwei Konten fuer eine Nummer gibt.
        $konto = ChannelAccount::firstOrNew([
            'channel_id' => $kanal->id,
            'external_account_id' => $phoneId,
        ]);
        $neu = ! $konto->exists;

        $zugang = $konto->credentials ?? [];
        $zugang['access_token'] = $token;
        $zugang['phone_number_id'] = $phoneId;
        $zugang['app_secret'] = $appSecret;
        // Ein vorhandenes Bestaetigungs-Token bleibt; sonst das der
        // Ersteinrichtung, damit auch der GET-Aufruf von Meta passt.
        $zugang['verify_token'] = $zugang['verify_token'] ?? ((string) config('services.meta.webhook_verify_token') ?: null);

        $tage = (int) $this->option('token-tage');

        $konto->fill([
            'name' => (string) ($this->option('name') ?: ($anzeige ?: 'WhatsApp '.$phoneId)),
            'credentials' => $zugang,
            'token_expires_at' => $tage > 0 ? now()->addDays($tage) : null,
            'waba_id' => $wabaId,
            'connection_type' => ChannelConnection::TYPE_CLOUD_API,
            'is_active' => true,
        ])->save();

        // 3. Der Kanal selbst muss aktiv sein - sonst ist er kein
        //    Versandweg (ChannelRoutingService::usable) und jede Antwort
        //    aus dem Postfach bliebe liegen.
        if (! $kanal->is_active) {
            $kanal->forceFill(['is_active' => true])->save();
            $this->warn('Der WhatsApp-Kanal war deaktiviert und wurde eingeschaltet.');
        }
        Cache::forget('channel_id:'.WhatsAppAdapter::KEY);

        $this->info(($neu ? 'Konto angelegt' : 'Konto aktualisiert').' (ID '.$konto->id.', aktiv).');

        // 4. Webhook-Abonnement auf dem WABA.
        $abo = $meta->subscribeWabaAntwort($wabaId, $token);
        $this->line('POST /'.$wabaId.'/subscribed_apps -> HTTP '.$abo['status'].' '
            .json_encode($abo['antwort'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($abo['ok']) {
            $konto->markConnection(ChannelConnection::CONNECTED);
            $this->info('WABA abonniert - Webhooks fuer diese Nummer kommen jetzt im Portal an.');
        } else {
            $konto->markConnection(
                ChannelConnection::WEBHOOK_ERROR,
                'Das Webhook-Abonnement wurde von Meta nicht bestaetigt.'
            );
            $this->error('Abonnement fehlgeschlagen - das Konto ist angelegt, aber Meta meldet keine Ereignisse. '
                .'Den Befehl nach Pruefung der Berechtigungen (whatsapp_business_management) erneut ausfuehren.');
        }

        // Protokoll OHNE Token und ohne Secret.
        ActivityLog::record('whatsapp_account_manual', 'channel_account', $konto->id, [
            'phone_number_id' => $phoneId,
            'waba_id' => $wabaId,
            'neu' => $neu,
            'abonniert' => $abo['ok'],
        ]);

        return $abo['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
