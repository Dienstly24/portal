<?php

namespace Tests\Feature\Messaging;

use App\Models\Channel;
use App\Services\Messaging\Channels\ChannelAdapterInterface;
use App\Services\Messaging\Channels\ChannelManager;
use App\Services\Messaging\Exceptions\ChannelConfigurationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Die Architekturregel, gemessen statt behauptet.
 *
 * WARUM ALS TEST: "der Kern kennt keine Kanaele" ist eine Absprache,
 * und Absprachen halten in einem wachsenden Projekt genau so lange,
 * bis es einmal eilig ist. Die erste `if ($channel === 'whatsapp')` im
 * Kern kostet nichts und faellt niemandem auf; die zwanzigste macht
 * einen neuen Kanal unbezahlbar. Deshalb faellt dieser Test, sobald
 * ein Kanalname in den Kern wandert - und nicht erst in einem Review
 * in sechs Monaten.
 */
class MessagingArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /** Kanalnamen, die im Kern nichts zu suchen haben. */
    private const PLATTFORMEN = [
        'whatsapp', 'instagram', 'facebook', 'messenger',
        'tiktok', 'telegram', 'wa_id', 'wamid', 'graph.facebook',
    ];

    /**
     * Der CODE einer Datei, ohne Kommentare.
     *
     * Kommentare duerfen Plattformen sehr wohl nennen - "hier steht
     * bewusst kein WhatsApp-Sonderfall" ist genau die Erklaerung, die
     * ein spaeterer Leser braucht. Verboten ist die BEDINGUNG, nicht
     * die Erlaeuterung. Ohne diese Trennung wuerde der Test die
     * Dokumentation der eigenen Regel bestrafen.
     */
    private function codeOhneKommentare(string $datei): string
    {
        $code = '';
        foreach (token_get_all(file_get_contents($datei)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return strtolower($code);
    }

    /** @return array<int,string> */
    private function kernDateien(): array
    {
        $wurzel = base_path('app/Services/Messaging');
        $dateien = [];
        $lauf = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel));

        foreach ($lauf as $datei) {
            if (! $datei->isFile() || $datei->getExtension() !== 'php') {
                continue;
            }
            // Die Adapter sind der ORT fuer Plattformwissen - sie sind
            // ausdruecklich ausgenommen. Alles andere ist Kern.
            if (str_contains($datei->getPathname(), DIRECTORY_SEPARATOR.'Channels'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $dateien[] = $datei->getPathname();
        }

        return $dateien;
    }

    /** Fall 1: Kein Kanalname im Conversation Engine und seinen Nachbarn. */
    public function test_der_kern_nennt_keinen_kanal_beim_namen(): void
    {
        $treffer = [];

        foreach ($this->kernDateien() as $datei) {
            $inhalt = $this->codeOhneKommentare($datei);
            foreach (self::PLATTFORMEN as $plattform) {
                if (str_contains($inhalt, $plattform)) {
                    $treffer[] = basename($datei).' -> '.$plattform;
                }
            }
        }

        $this->assertSame([], $treffer,
            'Plattformwissen gehoert in den Adapter, nicht in den Kern: '.implode(', ', $treffer));
    }

    /** Fall 2: Auch die Unterhaltung selbst bleibt kanalfrei. */
    public function test_die_unterhaltung_nennt_keinen_kanal_beim_namen(): void
    {
        $inhalt = $this->codeOhneKommentare(app_path('Models/Conversation.php'));

        foreach (self::PLATTFORMEN as $plattform) {
            $this->assertStringNotContainsString($plattform, $inhalt,
                "Conversation darf '{$plattform}' nicht kennen.");
        }
    }

    /** Fall 3: Die registrierten Kanaele erfuellen alle denselben Vertrag. */
    public function test_jeder_registrierte_kanal_erfuellt_den_vertrag(): void
    {
        $manager = app(ChannelManager::class);

        $this->assertNotEmpty($manager->keys());

        foreach ($manager->keys() as $schluessel) {
            $adapter = $manager->driver($schluessel);
            $this->assertInstanceOf(ChannelAdapterInterface::class, $adapter);
            $this->assertSame($schluessel, $adapter->key());
        }
    }

    /** Fall 4: Jeder registrierte Adapter hat einen Kanal in der Datenbank. */
    public function test_jeder_adapter_hat_seinen_kanal_datensatz(): void
    {
        foreach (app(ChannelManager::class)->keys() as $schluessel) {
            $this->assertNotNull(Channel::where('key', $schluessel)->first(),
                "Zum Adapter '{$schluessel}' fehlt der Eintrag in `channels`.");
        }
    }

    /**
     * Fall 5: Ein unbekannter Kanal fuehrt zu einer klaren Meldung,
     * nicht zu einem stillen null - "es passiert einfach nichts" ist
     * der teuerste Fehlerzustand, den dieses Projekt kennt.
     */
    public function test_unbekannter_kanal_meldet_sich_deutlich(): void
    {
        $this->expectException(ChannelConfigurationException::class);
        app(ChannelManager::class)->driver('gibtesnicht');
    }

    /**
     * Fall 6a: KEINE KI IM KANAL (Auftrag Abschnitte 79/100).
     *
     * Der Adapter spricht mit der Plattform - mehr nicht. Wandert die
     * KI dort hinein, muss sie in jedem weiteren Kanal noch einmal
     * stehen; beim vierten vergisst sie jemand, und der Ausfall sieht
     * aus wie gar nichts. Sie haengt deshalb am Ereignis des Kerns
     * (`TriggerAiAssistant`), und dieser Test haelt das fest.
     */
    public function test_kein_kanal_adapter_ruft_die_ki_auf(): void
    {
        $verbotene = ['assistant', 'openai', 'anthropic', 'claude', 'prompt', 'aiconversation'];
        $treffer = [];

        foreach (glob(base_path('app/Services/Messaging/Channels/*.php')) as $datei) {
            $code = $this->codeOhneKommentare($datei);
            foreach ($verbotene as $wort) {
                if (str_contains($code, $wort)) {
                    $treffer[] = basename($datei).' -> '.$wort;
                }
            }
        }

        $this->assertSame([], $treffer,
            'KI gehoert ueber den Conversation Engine, nicht in den Kanal: '.implode(', ', $treffer));
    }

    /**
     * Fall 6: Ein Kanal OHNE eigene Webhook-Pruefung nimmt nichts
     * entgegen. Durchwinken waere die gefaehrlichere Vorgabe: eine
     * gefaelschte Nachricht landete sonst in einer Kundenakte.
     */
    public function test_ohne_eigene_pruefung_wird_kein_webhook_angenommen(): void
    {
        $manager = app(ChannelManager::class);

        foreach ([Channel::PORTAL, Channel::INTERNAL] as $schluessel) {
            $this->assertFalse(
                $manager->driver($schluessel)->verifyWebhook('', [], null),
                "Kanal '{$schluessel}' darf ohne eigene Pruefung keine Webhooks annehmen."
            );
        }
    }
}
