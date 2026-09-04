<?php

namespace App\Jobs;

use App\Models\CustomerMessage;
use App\Services\Ai\Assistant\CustomerAssistantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Antwort des KI-Kundenassistenten auf eine Kundennachricht.
 *
 * Warum asynchron: der Kunde soll seine Nachricht sofort abgeschickt sehen,
 * ohne auf den KI-Dienst zu warten. Der Portal-Chat pollt seinen Feed
 * (portal.messages.feed) - die Antwort erscheint dadurch von selbst, ohne
 * Aenderung am Frontend-Transport.
 *
 * KEINE Wiederholung (tries = 1): ein zweiter Versuch wuerde dem Kunden
 * eine zweite Antwort schreiben und ggf. einen zweiten Vorgang anlegen.
 * Scheitert der Versuch, hat der Dienst selbst schon den Fallback samt
 * Uebergabe an das Team erzeugt (Spezifikation Abschnitt 31).
 */
class AnswerCustomerMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(public string $messageId)
    {
    }

    public function handle(CustomerAssistantService $assistant): void
    {
        $message = CustomerMessage::with('customer')->find($this->messageId);
        if (! $message) {
            return;
        }

        // Hat inzwischen ein Mensch geantwortet, ist die KI zu spaet - dann
        // schweigt sie (der Mitarbeiter fuehrt das Gespraech, Abschnitt 15).
        $answeredByStaff = CustomerMessage::where('customer_id', $message->customer_id)
            ->where('created_at', '>', $message->created_at)
            ->fromStaff()
            ->where('ai_generated', false)
            ->exists();
        if ($answeredByStaff) {
            return;
        }

        try {
            $assistant->handleCustomerMessage($message);
        } catch (\Throwable $e) {
            // Der Dienst faengt seine Fehler selbst ab und erzeugt den
            // Fallback; hier bleibt nur das Protokoll fuer den Notfall.
            Log::error('KI-Assistent: Antwort fehlgeschlagen', [
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
