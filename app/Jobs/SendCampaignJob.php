<?php
namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Kampagnenversand über die Queue (Paket A2): Der HTTP-Request legt nur
 * noch den Kampagnen-Datensatz an; der eigentliche Versand läuft hier,
 * gechunkt und mit Zustellprotokoll pro Empfänger (email_logs, Paket A3).
 * Abgemeldete Kunden werden ausgefiltert (Paket A1).
 */
class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public string $campaignId) {}

    public function handle(): void
    {
        $campaign = EmailCampaign::find($this->campaignId);
        if (!$campaign || !in_array($campaign->status, ['sending', 'scheduled'], true)) return;

        $campaign->update(['status' => 'sending']);
        $sent = 0;

        $this->recipients($campaign)->orderBy('customers.id')->chunk(200, function ($customers) use ($campaign, &$sent) {
            foreach ($customers as $customer) {
                if (!$customer->isMarketingReachable()) continue;
                $email = $customer->user->email;
                try {
                    Mail::to($email)->send(new CampaignMail(
                        $campaign->subject,
                        $campaign->body,
                        $customer->user->name,
                        route('unsubscribe', $customer->unsubscribeToken()),
                        $customer->preferred_lang ?? 'de',
                    ));
                    $status = 'sent';
                    $sent++;
                } catch (\Throwable $e) {
                    $status = 'failed';
                    Log::warning("Kampagne {$campaign->id}: Versand an {$email} fehlgeschlagen: " . $e->getMessage());
                }
                EmailLog::create([
                    'campaign_id' => $campaign->id,
                    'user_id' => $customer->user_id,
                    'email' => $email,
                    'subject' => $campaign->subject,
                    'type' => 'campaign',
                    'status' => $status,
                ]);
                $campaign->update(['sent_count' => $sent]);
            }
        });

        $campaign->update(['status' => 'sent', 'sent_count' => $sent, 'sent_at' => now()]);
    }

    /**
     * Empfänger-Query nach Zielgruppe der Kampagne. Sichtbarkeit richtet
     * sich nach dem Ersteller (der Job läuft ohne auth()-Kontext).
     */
    private function recipients(EmailCampaign $campaign)
    {
        $creator = $campaign->createdBy;
        if (!$creator) {
            // Kein (mehr) aufloesbarer Ersteller (Konto geloescht -> created_by
            // genullt): das Portfolio ist unbekannt. Dann NICHT an alle Kunden
            // senden (Portfolio-Scope-Schutz, Audit MKT-1) - leere Liste.
            $ids = [];
        } elseif (!$creator->canSeeAllCustomers()) {
            $ids = $creator->assignedCustomers()->pluck('customers.id')->toArray();
        } else {
            $ids = null; // see-all -> keine Einschraenkung
        }

        $base = Customer::with('user')
            ->marketingReachable()
            ->when($ids !== null, fn($q) => $q->whereIn('customers.id', $ids));

        return match (true) {
            $campaign->target === 'all' => $base,
            in_array($campaign->target, ['de', 'ar'], true) => $base->where('preferred_lang', $campaign->target),
            // Sparten-Kampagne: nur Kunden mit einem AKTIVEN Vertrag dieser
            // Sparte (Contract::currentlyActive) - wer gekuendigt hat, ist kein
            // Bestandskunde dieser Sparte mehr.
            default => $base->whereHas('contracts', fn($q) => $q->where('type', $campaign->target)->currentlyActive()),
        };
    }

    /**
     * Fällige geplante Kampagnen anstoßen (Paket B1). Wird vom Scheduler
     * alle 5 Minuten aufgerufen; als eigene Methode testbar.
     */
    public static function dispatchDueScheduled(): int
    {
        $due = EmailCampaign::where('status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->get();
        foreach ($due as $campaign) {
            $campaign->update(['status' => 'sending']);
            self::dispatch($campaign->id);
        }
        return $due->count();
    }
}
