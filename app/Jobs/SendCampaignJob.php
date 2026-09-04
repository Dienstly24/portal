<?php

namespace App\Jobs;

use App\Mail\CampaignMail;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Services\Notifications\NotificationService;
use App\Support\Facades\Notify;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Kampagnenversand über die Queue (Paket A2): Der HTTP-Request legt nur
 * noch den Kampagnen-Datensatz an; der eigentliche Versand läuft hier,
 * gechunkt und mit Zustellprotokoll pro Empfänger (email_logs, Paket A3).
 * Abgemeldete Kunden werden ausgefiltert (Paket A1).
 *
 * DOPPELVERSAND-SCHUTZ (Lehre 18.08.2026, Audit-Befund):
 * Das Job-Timeout war mit 600s GROESSER als das `retry_after` der
 * Datenbank-Queue (360s). Ein zweiter Worker holt einen reservierten Job
 * nach `retry_after` erneut aus der Tabelle - der erste lief da noch. Ein
 * Kunde bekam die Werbe-Mail damit doppelt (UWG-Risiko, Reputation der
 * Absender-Domain). Drei Schichten beheben das:
 *
 *  1. TIMING: $timeout liegt jetzt UNTER retry_after (300 < 360), und der
 *     Job hoert schon nach SOFT_BUDGET_SECONDS von selbst auf und setzt
 *     sich fuer den Rest neu in die Queue. Eine grosse Kampagne laeuft
 *     dadurch in mehreren kurzen Laeufen, statt in ein Timeout zu rennen.
 *  2. IDEMPOTENZ (die eigentliche Sicherheit): Empfaenger, fuer die es
 *     bereits einen Protokolleintrag dieser Kampagne gibt, sind aus der
 *     Empfaenger-Query ausgeschlossen (whereNotExists auf email_logs).
 *     Selbst wenn zwei Worker denselben Job gleichzeitig ausfuehren oder
 *     ein Retry laeuft, wird niemand ein zweites Mal angeschrieben.
 *  3. SICHTBARER FEHLER: failed() setzt die Kampagne auf 'failed' und
 *     meldet es dem Ersteller per Glocke - vorher blieb sie fuer immer
 *     auf "Wird gesendet…" stehen, ohne dass jemand davon erfuhr.
 */
class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * MUSS kleiner bleiben als `retry_after` der Queue-Verbindung
     * (config/queue.php, Default 360s) - sonst liefert die Queue den Job
     * an einen zweiten Worker aus, waehrend der erste noch sendet.
     */
    public int $timeout = 300;

    /**
     * Ein Retry ist dank der Idempotenz-Sperre gefahrlos: bereits
     * protokollierte Empfaenger werden nicht erneut angeschrieben.
     */
    public int $tries = 3;

    public int $backoff = 60;

    /** Empfaenger je Datenbank-Runde. */
    private const BATCH_SIZE = 200;

    /**
     * Nach dieser Laufzeit beendet sich der Job freiwillig und setzt sich
     * fuer die restlichen Empfaenger neu in die Queue. Deutlich unter
     * $timeout, damit der laufende Stapel sicher fertig wird.
     */
    private const SOFT_BUDGET_SECONDS = 200;

    public function __construct(public string $campaignId) {}

    public function handle(): void
    {
        $campaign = EmailCampaign::find($this->campaignId);
        if (! $campaign || ! in_array($campaign->status, ['sending', 'scheduled'], true)) return;

        $campaign->update(['status' => 'sending']);

        $startedAt = microtime(true);
        // Empfaenger, die in DIESEM Lauf uebersprungen wurden (kein echtes
        // Postfach). Sie erzeugen keinen Protokolleintrag und wuerden sonst
        // in der naechsten Runde erneut auftauchen -> Endlosschleife.
        $skipped = [];

        while (true) {
            $batch = $this->recipients($campaign)
                ->when($skipped !== [], fn ($q) => $q->whereNotIn('customers.id', $skipped))
                ->orderBy('customers.id')
                ->limit(self::BATCH_SIZE)
                ->get();

            if ($batch->isEmpty()) break;

            foreach ($batch as $customer) {
                if (! $customer->isMarketingReachable()) {
                    // Platzhalter-Adresse (import-...@dienstly24.internal)
                    // oder zwischenzeitlich abgemeldet.
                    $skipped[] = $customer->id;
                    continue;
                }
                $email = $customer->user->email;

                // Idempotenz-Sperre: der Protokolleintrag entsteht VOR dem
                // Versand. Traegt ein zweiter Worker denselben Empfaenger
                // gleichzeitig ein, scheitert einer von beiden am
                // Unique-Index (campaign_id + user_id) und ueberspringt ihn -
                // die Mail geht genau einmal raus.
                // BEWUSSTE ENTSCHEIDUNG: Stirbt der Worker zwischen Eintrag
                // und Versand, gilt der Empfaenger als angeschrieben, obwohl
                // die Mail eventuell nicht rausging. Bei WERBUNG ist eine
                // fehlende Mail harmlos, eine doppelte dagegen ein
                // UWG-Aergernis und schadet der Domain-Reputation
                // (Spam-Beschwerden) - deshalb hoechstens einmal.
                try {
                    $log = EmailLog::create([
                        'campaign_id' => $campaign->id,
                        'user_id' => $customer->user_id,
                        'email' => $email,
                        'subject' => $campaign->subject,
                        'type' => 'campaign',
                        'status' => 'sent',
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    $skipped[] = $customer->id;
                    continue;
                }

                try {
                    Mail::to($email)->send(new CampaignMail(
                        $campaign->subject,
                        $campaign->body,
                        $customer->user->name,
                        route('unsubscribe', $customer->unsubscribeToken()),
                        $customer->preferred_lang ?? 'de',
                    ));
                } catch (\Throwable $e) {
                    $log->update(['status' => 'failed']);
                    Log::warning("Kampagne {$campaign->id}: Versand an {$email} fehlgeschlagen: ".$e->getMessage());
                }
            }

            // Zaehler aus dem Protokoll statt aus einer Lauf-Variablen:
            // ueberlebt Fortsetzung und Retry (frueher setzte ein zweiter
            // Lauf den Stand auf 0 zurueck) - und kostet EINE Abfrage je
            // Stapel statt eines UPDATEs je Empfaenger.
            $campaign->update(['sent_count' => $this->sentCount($campaign)]);

            if ((microtime(true) - $startedAt) >= self::SOFT_BUDGET_SECONDS) {
                // Rest in einem frischen Lauf weitersenden - nie ins
                // Timeout laufen. Die Kampagne bleibt auf 'sending'.
                self::dispatch($campaign->id);
                return;
            }
        }

        $campaign->update([
            'status' => 'sent',
            'sent_count' => $this->sentCount($campaign),
            'sent_at' => $campaign->sent_at ?? now(),
        ]);
    }

    /**
     * Endgueltig gescheiterter Versand (Timeout, Worker-Absturz, Fehler
     * nach allen Versuchen): Die Kampagne darf NICHT stumm auf
     * "Wird gesendet…" stehen bleiben - Zustand sichtbar machen und den
     * Ersteller benachrichtigen. Bereits versendete Empfaenger bleiben
     * protokolliert; ein erneuter Anstoss schreibt sie nicht erneut an.
     */
    public function failed(\Throwable $e): void
    {
        $campaign = EmailCampaign::find($this->campaignId);
        if (! $campaign) return;

        $sent = $this->sentCount($campaign);
        $campaign->update(['status' => 'failed', 'sent_count' => $sent]);

        Log::error("Kampagne {$campaign->id}: Versand abgebrochen nach {$sent} Empfaengern: ".$e->getMessage());

        if ($campaign->created_by) {
            Notify::push($campaign->created_by, [
                'type' => NotificationService::TYPE_SYSTEM,
                'title' => 'Kampagnen-Versand abgebrochen',
                'body' => 'Die Kampagne "'.$campaign->subject.'" konnte nicht vollstaendig versendet werden. '
                    .$sent.' Empfaenger wurden bereits angeschrieben. Ein erneuter Versand ueberspringt diese.',
                'link' => route('admin.email_marketing'),
                'dedup_key' => 'campaign-failed-'.$campaign->id,
            ]);
        }
    }

    /** Tatsaechlich zugestellte Empfaenger laut Protokoll. */
    private function sentCount(EmailCampaign $campaign): int
    {
        return EmailLog::where('campaign_id', $campaign->id)->where('status', 'sent')->count();
    }

    /**
     * Empfänger-Query nach Zielgruppe der Kampagne. Sichtbarkeit richtet
     * sich nach dem Ersteller (der Job läuft ohne auth()-Kontext).
     * Bereits angeschriebene Empfaenger (Protokolleintrag vorhanden, egal
     * ob zugestellt oder gescheitert) sind IMMER ausgeschlossen - das ist
     * der Doppelversand-Schutz und zugleich das Abbruchkriterium der
     * Sende-Schleife.
     */
    private function recipients(EmailCampaign $campaign)
    {
        $creator = $campaign->createdBy;
        if (! $creator) {
            // Kein (mehr) aufloesbarer Ersteller (Konto geloescht -> created_by
            // genullt): das Portfolio ist unbekannt. Dann NICHT an alle Kunden
            // senden (Portfolio-Scope-Schutz, Audit MKT-1) - leere Liste.
            $ids = [];
        } elseif (! $creator->canSeeAllCustomers()) {
            $ids = $creator->assignedCustomers()->pluck('customers.id')->toArray();
        } else {
            $ids = null; // see-all -> keine Einschraenkung
        }

        $base = Customer::with('user')
            ->marketingReachable()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('email_logs')
                ->whereColumn('email_logs.user_id', 'customers.user_id')
                ->where('email_logs.campaign_id', $campaign->id))
            ->when($ids !== null, fn ($q) => $q->whereIn('customers.id', $ids));

        return match (true) {
            $campaign->target === 'all' => $base,
            in_array($campaign->target, ['de', 'ar'], true) => $base->where('preferred_lang', $campaign->target),
            // Sparten-Kampagne: nur Kunden mit einem AKTIVEN Vertrag dieser
            // Sparte (Contract::currentlyActive) - wer gekuendigt hat, ist kein
            // Bestandskunde dieser Sparte mehr.
            default => $base->whereHas('contracts', fn ($q) => $q->where('type', $campaign->target)->currentlyActive()),
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
