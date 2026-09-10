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

        if (! is_array($payload)) {
            return response('', 200);
        }

        // Das Konto steht IN der Nutzlast (Rufnummern-Kennung) - so findet
        // auch bei mehreren Nummern jede Zustellung ihr Konto.
        $phoneId = WhatsAppAdapter::phoneNumberIdFrom($payload);
        $account = $phoneId
            ? $this->accounts()->firstWhere(fn (ChannelAccount $a) => $a->credential('phone_number_id') === $phoneId)
            : null;

        // ERST PRUEFEN, DANN SPEICHERN: eine gefaelschte Nachricht darf
        // nie in einer Kundenakte landen. Ohne passendes Konto gibt es
        // kein App-Secret - also keine Pruefung und damit keine Annahme.
        if (! $account || ! $adapter->verifyWebhook($roh, $request->headers->all(), $account)) {
            return response('', 403);
        }

        // Die eigentliche Arbeit laeuft asynchron.
        ProcessWhatsAppWebhookJob::dispatch($account->id, $payload);

        return response('', 200);
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
