<?php

namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Jobs\AnswerCustomerMessageJob;
use App\Models\AiAssistantLog;
use App\Models\CustomerMessage;
use App\Services\Ai\Assistant\AssistantSettings;
use Illuminate\Console\Command;

/**
 * Sicherheitsnetz des KI-Kundenassistenten (gleiche Lehre wie
 * documents:analyze-pending): lief der Queue-Worker beim Eingang der
 * Kundennachricht nicht, ginge die Antwort sonst still verloren - der
 * Kunde wartet dann auf niemanden.
 *
 * Aufgegriffen wird nur, was NACHWEISLICH nie bearbeitet wurde: zu jeder
 * bearbeiteten Nachricht existiert ein Protokoll (auch bei "keine Antwort").
 * Zusammen mit der Idempotenz-Sperre in CustomerAssistantService kann
 * dadurch keine zweite Antwort entstehen, selbst wenn der urspruengliche
 * Job noch in der Queue liegt.
 *
 * Bewusst NUR die letzten 6 Stunden: eine Tage alte Frage automatisch zu
 * beantworten waere fuer den Kunden befremdlich - die liegt beim Team.
 */
class AnswerPendingCustomerMessages extends Command
{
    use ProcessesRecordsSafely;

    protected $signature = 'ai:answer-pending';
    protected $description = 'Unbearbeitete Kundennachrichten erneut an den KI-Assistenten geben (Queue-Ausfall)';

    public function handle(AssistantSettings $settings): int
    {
        if (! $settings->enabled() || ! $settings->autoReply()) {
            $this->info('KI-Assistent ist abgeschaltet - nichts zu tun.');

            return self::SUCCESS;
        }

        // Kundennachrichten der letzten 6 Stunden, mindestens 10 Minuten alt
        // (juenger laeuft der regulaere Job noch), ohne Protokolleintrag.
        $candidates = CustomerMessage::fromCustomer()
            ->whereBetween('created_at', [now()->subHours(6), now()->subMinutes(10)])
            ->whereNotIn('id', AiAssistantLog::whereNotNull('customer_message_id')->pluck('customer_message_id'))
            ->orderBy('created_at')
            ->limit(25)
            ->get();

        // Je Nachricht abgesichert: eine kaputte Nachricht darf nicht dazu
        // fuehren, dass ALLE anderen Kunden weiter auf niemanden warten -
        // dieser Befehl ist das letzte Netz vor genau diesem Zustand.
        $dispatched = $this->verarbeiteEinzeln($candidates, function (CustomerMessage $message) {
            // Nur die JUENGSTE Nachricht einer Unterhaltung beantworten -
            // auf einen ganzen Rueckstau nachtraeglich einzeln zu antworten
            // waere fuer den Kunden verwirrend.
            $newer = CustomerMessage::where('customer_id', $message->customer_id)
                ->where('created_at', '>', $message->created_at)
                ->exists();
            if ($newer) {
                return;
            }

            AnswerCustomerMessageJob::dispatch($message->id);
        }, 'Kundennachricht');

        $this->info($dispatched.' Kundennachricht(en) geprueft und ggf. erneut an den KI-Assistenten gegeben.');

        return $this->ergebnisMitUebersprungenen(self::SUCCESS);
    }
}
