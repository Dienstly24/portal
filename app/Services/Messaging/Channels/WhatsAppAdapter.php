<?php

namespace App\Services\Messaging\Channels;

use App\Models\ChannelAccount;
use App\Services\Messaging\Dto\ConnectionTest;
use App\Services\Messaging\Dto\InboundAttachment;
use App\Services\Messaging\Dto\InboundMessage;
use App\Services\Messaging\Dto\OutboundMessage;
use App\Services\Messaging\Dto\SendResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API - offizieller Weg ueber Meta, ohne Zwischenanbieter
 * (Auftrag Abschnitt 30).
 *
 * HIER und NUR hier steht WhatsApp-Wissen: Nutzlast-Formate, Signatur,
 * Medien-Abruf, Fehlercodes. Der Conversation Engine sieht davon nichts
 * (Abschnitte 79/100), und `MessagingArchitectureTest` haelt das fest.
 *
 * BEWUSST NICHT `MetaGraphClient` wiederverwendet, obwohl es denselben
 * Graph-Host anspricht: jener holt sein Token aus der Konfiguration. Die
 * Zugangsdaten gehoeren hier aber zum KANALKONTO (Abschnitte 68/84) -
 * sonst waeren zwei WhatsApp-Nummern mit getrennten Zugaengen unmoeglich,
 * und genau das ist der Zweck der Kontoebene.
 *
 * ZUGANGSDATEN je Konto: `access_token`, `phone_number_id`, `app_secret`
 * (Signatur), `verify_token` (Ersteinrichtung). Sie liegen verschluesselt
 * in `channel_accounts` und werden nie ausgegeben.
 */
class WhatsAppAdapter extends AbstractChannelAdapter
{
    public const KEY = 'whatsapp';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * Signaturpruefung (Abschnitt 21): X-Hub-Signature-256 ist ein
     * HMAC-SHA256 des ROHEN Koerpers mit dem App-Secret.
     *
     * Verglichen wird mit `hash_equals` - ein normaler Vergleich bricht
     * beim ersten falschen Zeichen ab und verraet ueber die Laufzeit,
     * wie viele Zeichen stimmten.
     *
     * OHNE hinterlegtes App-Secret wird ABGELEHNT, nie durchgewunken.
     * Ein Schutz, der bei fehlender Einrichtung durchlaesst, ist genau
     * dann aus, wenn er gebraucht wird (dieselbe Haltung wie bei
     * Turnstile, SEC-1).
     */
    public function verifyWebhook(string $rawBody, array $headers, ?ChannelAccount $account): bool
    {
        $secret = $account?->credential('app_secret');
        if (! $secret) {
            return false;
        }

        $signature = $this->header($headers, 'x-hub-signature-256');
        if (! $signature || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $erwartet = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($erwartet, $signature);
    }

    /**
     * Bestaetigungs-Token der Ersteinrichtung (GET-Aufruf von Meta).
     * Auch hier `hash_equals`.
     */
    public function verifySubscription(?ChannelAccount $account, string $token): bool
    {
        $erwartet = $account?->credential('verify_token');

        return $erwartet !== null && hash_equals($erwartet, $token);
    }

    /**
     * Aus welchem Konto stammt diese Zustellung? Meta liefert die
     * `phone_number_id` in der Nutzlast - so findet auch bei MEHREREN
     * Nummern jede Nachricht ihr Konto (Abschnitt 73).
     */
    public static function phoneNumberIdFrom(array $payload): ?string
    {
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $id = $change['value']['metadata']['phone_number_id'] ?? null;
                if ($id) {
                    return (string) $id;
                }
            }
        }

        return null;
    }

    /** @return array<int,InboundMessage> */
    public function parseInbound(array $payload, ?ChannelAccount $account): array
    {
        $nachrichten = [];

        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                // Absendernamen stehen getrennt von den Nachrichten.
                $namen = [];
                foreach (($value['contacts'] ?? []) as $kontakt) {
                    $namen[(string) ($kontakt['wa_id'] ?? '')] = $kontakt['profile']['name'] ?? null;
                }

                foreach (($value['messages'] ?? []) as $msg) {
                    $von = (string) ($msg['from'] ?? '');
                    if ($von === '') {
                        continue;
                    }

                    [$typ, $text, $anhaenge] = $this->readContent($msg);

                    $nachrichten[] = new InboundMessage(
                        externalUserId: $von,
                        externalMessageId: (string) ($msg['id'] ?? ''),
                        // WhatsApp kennt keine Unterhaltungs-Kennung: die
                        // Unterhaltung ist durch Konto + Gegenstelle
                        // bestimmt. Der Engine kommt damit zurecht.
                        externalConversationId: null,
                        text: $text,
                        type: $typ,
                        attachments: $anhaenge,
                        senderName: $namen[$von] ?? null,
                        // Die wa_id IST die Telefonnummer in
                        // internationaler Schreibweise - genau das, was
                        // der CustomerResolver zur Zuordnung braucht.
                        senderPhone: $von,
                        sentAt: isset($msg['timestamp'])
                            ? Carbon::createFromTimestamp((int) $msg['timestamp'])
                            : null,
                    );
                }
            }
        }

        return $nachrichten;
    }

    /**
     * Inhalt einer Nachricht lesen.
     *
     * @return array{0:string,1:?string,2:array<int,InboundAttachment>}
     */
    private function readContent(array $msg): array
    {
        $typ = (string) ($msg['type'] ?? 'unsupported');

        return match ($typ) {
            'text' => ['text', $msg['text']['body'] ?? null, []],
            'image', 'video', 'audio', 'document', 'sticker' => [
                $typ,
                // Bildunterschrift ist der Text der Nachricht.
                $msg[$typ]['caption'] ?? null,
                [new InboundAttachment(
                    type: $typ,
                    externalMediaId: isset($msg[$typ]['id']) ? (string) $msg[$typ]['id'] : null,
                    fileName: $msg[$typ]['filename'] ?? null,
                    mimeType: $msg[$typ]['mime_type'] ?? null,
                )],
            ],
            'location' => [
                'location',
                trim(($msg['location']['name'] ?? '').' '
                    .($msg['location']['latitude'] ?? '').','.($msg['location']['longitude'] ?? '')),
                [],
            ],
            'contacts' => ['contact', null, []],
            // Ein unbekannter Typ wird NICHT verworfen: der Mitarbeiter
            // soll sehen, dass etwas kam, das wir nicht darstellen koennen.
            default => ['unsupported', '[Nicht unterstuetzte Nachricht: '.$typ.']', []],
        };
    }

    /** @return array<int,array{external_message_id:string,status:string,reason?:string}> */
    public function parseStatusUpdates(array $payload, ?ChannelAccount $account): array
    {
        $meldungen = [];

        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                foreach (($change['value']['statuses'] ?? []) as $status) {
                    $id = (string) ($status['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }

                    $eintrag = [
                        'external_message_id' => $id,
                        'status' => match ((string) ($status['status'] ?? '')) {
                            'sent' => 'sent',
                            'delivered' => 'delivered',
                            'read' => 'read',
                            'failed' => 'failed',
                            default => 'sent',
                        },
                    ];

                    if (isset($status['errors'][0]['title'])) {
                        $eintrag['reason'] = (string) $status['errors'][0]['title'];
                    }

                    $meldungen[] = $eintrag;
                }
            }
        }

        return $meldungen;
    }

    public function send(OutboundMessage $message, ?ChannelAccount $account): SendResult
    {
        $token = $account?->credential('access_token');
        $phoneId = $account?->credential('phone_number_id');

        if (! $token || ! $phoneId) {
            return SendResult::failed('WhatsApp-Konto ist nicht vollstaendig eingerichtet.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $message->recipientId,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => (string) $message->text],
        ];

        try {
            // Token IMMER als Header - nie in Query oder Body: sonst steht
            // es in Fehlermeldungen und Verbindungs-Ausnahmen (dieselbe
            // Regel wie beim Social-Publishing).
            $antwort = Http::withToken($token)
                ->timeout(15)->connectTimeout(5)
                ->post($this->url($phoneId.'/messages'), $payload);
        } catch (\Throwable $e) {
            report($e);

            return SendResult::failed('WhatsApp nicht erreichbar.');
        }

        if ($antwort->successful()) {
            return SendResult::ok(
                $antwort->json('messages.0.id'),
                ['wa_id' => $antwort->json('contacts.0.wa_id')]
            );
        }

        // Die Fremdmeldung wird bewusst NICHT durchgereicht - sie kann
        // Kennungen enthalten. Der Betreiber bekommt eine einordnende
        // Meldung, der Rest steht im Log.
        return SendResult::failed($this->classify($antwort->status()));
    }

    /**
     * Mediendatei holen - ZWEI Schritte: erst die (kurzlebige) URL zur
     * Medien-Kennung, dann der eigentliche Download, der ebenfalls
     * authentifiziert werden muss. Ein direkter Download der Kennung
     * gibt es bei WhatsApp nicht.
     *
     * @return array{contents:string,mime_type:?string,file_name:?string}|null
     */
    public function fetchMedia(string $externalMediaId, ?ChannelAccount $account): ?array
    {
        $token = $account?->credential('access_token');
        if (! $token) {
            return null;
        }

        try {
            $meta = Http::withToken($token)->timeout(15)->get($this->url($externalMediaId));
            if (! $meta->successful() || ! $meta->json('url')) {
                return null;
            }

            $datei = Http::withToken($token)->timeout(30)->get((string) $meta->json('url'));
            if (! $datei->successful()) {
                return null;
            }

            return [
                'contents' => $datei->body(),
                'mime_type' => $meta->json('mime_type'),
                'file_name' => $meta->json('file_name'),
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function markAsRead(string $externalMessageId, ?ChannelAccount $account): bool
    {
        $token = $account?->credential('access_token');
        $phoneId = $account?->credential('phone_number_id');

        if (! $token || ! $phoneId) {
            return false;
        }

        try {
            return Http::withToken($token)->timeout(10)
                ->post($this->url($phoneId.'/messages'), [
                    'messaging_product' => 'whatsapp',
                    'status' => 'read',
                    'message_id' => $externalMessageId,
                ])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function testConnection(?ChannelAccount $account): ConnectionTest
    {
        $token = $account?->credential('access_token');
        $phoneId = $account?->credential('phone_number_id');

        if (! $token || ! $phoneId) {
            return ConnectionTest::make(ConnectionTest::NOT_CONFIGURED,
                'Zugangs-Token oder Rufnummern-Kennung fehlt.');
        }

        try {
            $antwort = Http::withToken($token)->timeout(15)->get($this->url($phoneId));
        } catch (\Throwable $e) {
            report($e);

            return ConnectionTest::make(ConnectionTest::UNAVAILABLE);
        }

        if ($antwort->successful()) {
            return ConnectionTest::connected(
                'Verbunden mit '.($antwort->json('display_phone_number') ?: 'der hinterlegten Nummer').'.'
            );
        }

        return ConnectionTest::make(match ($antwort->status()) {
            401, 403 => ConnectionTest::INVALID_CREDENTIALS,
            404 => ConnectionTest::NOT_CONFIGURED,
            429 => ConnectionTest::RATE_LIMITED,
            default => ConnectionTest::UNAVAILABLE,
        });
    }

    /** Fremde Fehlercodes in eine einordnende Meldung uebersetzen. */
    private function classify(int $status): string
    {
        return match ($status) {
            401, 403 => 'Zugang abgelehnt - Token pruefen.',
            404 => 'Empfaenger oder Rufnummern-Kennung nicht gefunden.',
            429 => 'Zu viele Anfragen - spaeter erneut versuchen.',
            default => 'WhatsApp antwortet mit Fehler '.$status.'.',
        };
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'
            .config('services.meta.graph_version', 'v23.0').'/'.ltrim($path, '/');
    }

    /** @param array<string,mixed> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $schluessel => $wert) {
            if (strtolower((string) $schluessel) === $name) {
                return is_array($wert) ? (string) ($wert[0] ?? '') : (string) $wert;
            }
        }

        return null;
    }
}
