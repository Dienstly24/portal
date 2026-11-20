<?php

namespace App\Listeners\Messaging;

use App\Events\Messaging\InboundMessageReceived;
use App\Models\ContractSwitchReminder;
use App\Models\ConversationNote;
use App\Support\ErinnerungsSparte;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Log;

/**
 * Nennt ein Kunde in einer eingehenden Nachricht die Kurzreferenz aus einer
 * Vertrags-Erinnerung ("Referenz: R-7K3Q9X" im vorbefuellten WhatsApp-Text),
 * bekommt die Unterhaltung eine INTERNE Notiz mit Kunde und Vertrag
 * (Betreiber-Entscheidung 20.11.2026).
 *
 * Bewusst KEINE automatische Kundenzuordnung: eine weitergeleitete Mail
 * oder ein abgeschriebener Code belegt nicht, wer schreibt - und mit einer
 * Kundenakte wuerde der KI-Assistent mit Kundendaten antworten. Die Notiz
 * ist fuer das Team, das die Identitaet prueft. Jede Referenz wird je
 * Unterhaltung nur EINMAL notiert. Ein Fehler hier stoppt nie den Eingang.
 */
class KurzreferenzNotieren
{
    private const MUSTER = '/\bR-[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{6}\b/u';

    public function handle(InboundMessageReceived $event): void
    {
        try {
            $text = mb_strtoupper((string) $event->message->body);
            if (! preg_match_all(self::MUSTER, $text, $treffer)) {
                return;
            }

            foreach (array_unique($treffer[0]) as $referenz) {
                $this->notieren($event->conversation->id, $referenz);
            }
        } catch (\Throwable $e) {
            Log::warning('Kurzreferenz konnte nicht notiert werden: '.$e->getMessage());
        }
    }

    private function notieren(string $conversationId, string $referenz): void
    {
        $bereits = ConversationNote::where('conversation_id', $conversationId)
            ->where('body', 'like', '%'.$referenz.'%')
            ->exists();
        if ($bereits) {
            return;
        }

        $erinnerung = ContractSwitchReminder::with('contract.customer')
            ->where('kurzreferenz', $referenz)
            ->first();
        $vertrag = $erinnerung?->contract;

        if ($vertrag === null) {
            $body = 'Kurzreferenz '.$referenz.' genannt - zu dieser Referenz gibt es keine Erinnerung (Tippfehler?).';
        } else {
            $sparte = ErinnerungsSparte::fuer($vertrag->type)['name_de'] ?? $vertrag->type;
            $body = implode("\n", array_filter([
                'Kurzreferenz '.$referenz.' aus einer Vertrags-Erinnerung'
                    .' (versandt am '.(LocalTime::for($erinnerung->sent_at)?->format('d.m.Y') ?? '-').'):',
                'Kunde '.($vertrag->customer?->customer_number ?: '(ohne Kundennummer)')
                    .' - '.$sparte
                    .($vertrag->insurer ? ' bei '.$vertrag->insurer : '')
                    .($vertrag->contract_number ? ', Vertrag '.$vertrag->contract_number : ''),
                'NICHT automatisch zugeordnet - die Referenz belegt nicht, wer schreibt. '
                    .'Bitte Identitaet pruefen, bevor Vertragsdaten genannt werden.',
            ]));
        }

        ConversationNote::create([
            'conversation_id' => $conversationId,
            'user_id' => null,
            'body' => $body,
            'visibility' => ConversationNote::VISIBILITY_INTERNAL,
        ]);
    }
}
