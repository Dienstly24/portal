<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\Messaging\ProcessWhatsAppWebhookJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Services\Messaging\Channels\WhatsAppAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Webhook-Endpunkt fuer WhatsApp (Auftrag Abschnitt 21).
 *
 * BEWUSST DUENN: pruefen, beanspruchen, Job werfen, 200 antworten. Alles
 * Weitere gehoert in den Adapter und den Conversation Engine.
 *
 * WARUM SO SCHNELL 200: Meta wiederholt jede Zustellung, die nicht
 * zuegig quittiert wird - und bei anhaltenden Zeitueberschreitungen
 * schaltet Meta den Webhook ab. Wer hier die vollstaendige Verarbeitung
 * (Kundensuche, KI, Medien-Download) abwartet, riskiert genau das.
 *
 * UND: 200 auch bei einer Nutzlast, mit der wir nichts anfangen koennen.
 * Ein Fehlercode wuerde Meta zu endlosen Wiederholungen derselben
 * unbrauchbaren Zustellung veranlassen.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * Ersteinrichtung: Meta ruft einmal per GET und erwartet die
     * `hub.challenge` im Klartext zurueck.
     *
     * Das Bestaetigungs-Token wird gegen JEDES aktive Konto geprueft -
     * beim GET-Aufruf liegt noch keine Rufnummern-Kennung vor, an der man
     * das Konto erkennen koennte.
     *
     * UND gegen einen Wert aus der Server-Konfiguration. Der ist bei der
     * ERSTEINRICHTUNG der einzige Weg: Meta prueft den Endpunkt, bevor
     * eine Nummer angebunden ist - dann gibt es noch kein Konto, gegen
     * das sich etwas pruefen liesse. Ohne diesen Ausweg waere die
     * Reihenfolge unaufloesbar (kein Webhook ohne Konto, kein Konto
     * ohne Webhook).
     *
     * Das betrifft AUSSCHLIESSLICH die Bestaetigung des Endpunkts. Jede
     * echte Zustellung wird weiterhin ueber die SIGNATUR mit dem
     * App-Secret des jeweiligen Kontos geprueft - hier wird nichts
     * aufgeweicht.
     */
    public function verify(Request $request, WhatsAppAdapter $adapter): Response
    {
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        if ($request->query('hub_mode') !== 'subscribe' || $token === '') {
            return response('', 403);
        }

        foreach ($this->accounts() as $account) {
            if ($adapter->verifySubscription($account, $token)) {
                return response($challenge, 200)->header('Content-Type', 'text/plain');
            }
        }

        // Ersteinrichtung. `hash_equals` auch hier: ein gewoehnlicher
        // Vergleich verraet ueber die Laufzeit, wie viele Zeichen
        // gestimmt haben.
        $einrichtung = (string) config('services.meta.webhook_verify_token');
        if ($einrichtung !== '' && hash_equals($einrichtung, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('', 403);
    }

    public function handle(Request $request, WhatsAppAdapter $adapter): Response
    {
        $roh = $request->getContent();
        $payload = json_decode($roh, true);
        $hatSignatur = $request->headers->has('X-Hub-Signature-256');

        // JEDE Zustellung wird protokolliert (19.11.2026, KI-109) - bevor
        // irgendetwas geprueft wird. Vorher hinterliess ein abgewiesener
        // Aufruf KEINE Spur: Meta meldete "fehlgeschlagen", im Portal stand
        // nichts, und der Fehlercode einer Zustellmeldung war nirgends
        // nachzulesen. Eigener Kanal mit kurzer Aufbewahrung (Inhalte sind
        // personenbezogen).
        $this->protokoll('info', 'Zustellung eingegangen', [
            'ip' => $request->ip(),
            'signatur' => $hatSignatur ? 'vorhanden' : 'fehlt',
            'laenge' => strlen($roh),
            'payload' => is_array($payload) ? $payload : mb_substr($roh, 0, 2000),
        ]);

        if (! is_array($payload)) {
            return response('', 200);
        }

        // Fehlgeschlagene Zustellmeldungen als eigene, lesbare Zeile:
        // Fehlercode, Titel, Meldung, Details, Empfaenger.
        foreach (self::fehlgeschlageneStatus($payload) as $fehler) {
            $this->protokoll('warning', 'Zustellmeldung failed', $fehler);
        }

        $konten = $this->accounts();
        $teile = WhatsAppAdapter::splitByPhoneNumberId($payload);

        // Zustellungen OHNE Rufnummern-Kennung (z. B. account_update,
        // message_template_status_update) hatten bisher kein Konto und
        // endeten deshalb mit 403 - Meta wiederholt solche Zustellungen
        // und kann den Webhook bei anhaltenden Fehlern abschalten. Sie
        // werden nur quittiert, wenn die Signatur stimmt; verarbeitet wird
        // daraus nichts.
        if ($teile === []) {
            $quelle = $adapter->signaturPruefung($roh, $request->headers->all(), $konten->first());
            $this->protokoll($quelle ? 'info' : 'warning', 'Zustellung ohne Rufnummern-Kennung', [
                'signatur_gueltig' => $quelle !== null,
            ]);

            return response('', $quelle ? 200 : 403);
        }

        $angenommen = false;

        foreach ($teile as $phoneId => $teil) {
            $account = $konten->firstWhere(fn (ChannelAccount $a) => $a->credential('phone_number_id') === (string) $phoneId);

            // ERST PRUEFEN, DANN SPEICHERN: eine gefaelschte Nachricht darf
            // nie in einer Kundenakte landen. Ohne passendes Konto gibt es
            // keine Zuordnung - also keine Annahme.
            if (! $account) {
                // Echt von Meta (Server-App-Secret), aber im Portal ist diese
                // Nummer nicht angebunden: protokolliert ist die Nutzlast
                // bereits; quittieren, damit Meta nicht endlos wiederholt und
                // den Webhook nicht abschaltet. Verarbeitet wird NICHTS.
                $echt = $adapter->signaturPruefung($roh, $request->headers->all(), null) !== null;
                $this->protokoll('warning', 'Kein aktives Konto fuer diese Rufnummern-Kennung - nicht verarbeitet', [
                    'phone_number_id' => (string) $phoneId,
                    'signatur_gueltig' => $echt,
                    'bekannte_kennungen' => $konten->map(fn (ChannelAccount $a) => $a->credential('phone_number_id'))->values()->all(),
                ]);
                $angenommen = $angenommen || $echt;

                continue;
            }

            $quelle = $adapter->signaturPruefung($roh, $request->headers->all(), $account);
            if ($quelle === null) {
                $this->protokoll('warning', 'Abgewiesen: Signatur ungueltig', [
                    'phone_number_id' => (string) $phoneId,
                    'konto_id' => $account->id,
                    'signatur' => $hatSignatur ? 'vorhanden, passt weder zum Konto- noch zum Server-App-Secret' : 'fehlt',
                    'konto_hat_app_secret' => (bool) $account->credential('app_secret'),
                    'server_hat_app_secret' => (string) config('services.meta.app_secret') !== '',
                ]);

                continue;
            }

            if ($quelle === 'server') {
                // Nur ein Hinweis: das Konto traegt ein veraltetes Secret.
                $this->protokoll('warning', 'Signatur passt nur zum Server-App-Secret - App-Secret am Konto ist veraltet', [
                    'konto_id' => $account->id,
                ]);
            }

            // Die eigentliche Arbeit laeuft asynchron.
            ProcessWhatsAppWebhookJob::dispatch($account->id, $teil);
            $angenommen = true;
        }

        return response('', $angenommen ? 200 : 403);
    }

    /**
     * Alle Zustellmeldungen mit status "failed", reduziert auf das, was
     * zur Fehlersuche gebraucht wird.
     *
     * @return list<array<string,mixed>>
     */
    public static function fehlgeschlageneStatus(array $payload): array
    {
        $ergebnis = [];
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                foreach (($change['value']['statuses'] ?? []) as $status) {
                    if (($status['status'] ?? null) !== 'failed') {
                        continue;
                    }
                    $ergebnis[] = [
                        'message_id' => $status['id'] ?? null,
                        'recipient_id' => $status['recipient_id'] ?? null,
                        'timestamp' => $status['timestamp'] ?? null,
                        'errors' => array_map(fn ($e) => [
                            'code' => $e['code'] ?? null,
                            'title' => $e['title'] ?? null,
                            'message' => $e['message'] ?? null,
                            'details' => $e['error_data']['details'] ?? null,
                        ], is_array($status['errors'] ?? null) ? $status['errors'] : []),
                    ];
                }
            }
        }

        return $ergebnis;
    }

    /** Das Protokollieren darf die Annahme NIE scheitern lassen. */
    private function protokoll(string $stufe, string $text, array $kontext): void
    {
        try {
            Log::channel('whatsapp_webhook')->log($stufe, '[WhatsApp-Webhook] '.$text, $kontext);
        } catch (\Throwable) {
            // bewusst geschluckt
        }
    }

    /** @return Collection<int,ChannelAccount> */
    private function accounts()
    {
        $channelId = Channel::idFor(WhatsAppAdapter::KEY);

        if (! $channelId) {
            return collect();
        }

        return ChannelAccount::where('channel_id', $channelId)->active()->get();
    }
}
