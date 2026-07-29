<?php
namespace App\Jobs;

use App\Models\CustomerChangeRequest;
use App\Models\User;
use App\Services\ChangeRequest\ChangeProofPolicy;
use App\Services\ChangeRequest\ChangeProofVerifier;
use App\Services\ChangeRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Prueft die Nachweise einer Kundenaenderung automatisch (Textebene/OCR)
 * und entscheidet, wie es weitergeht:
 *
 *  - Nachweis bestaetigt UND automatische Freigabe erlaubt (Einstellung)
 *    -> Aenderung wird uebernommen, die Verwaltung bekommt die Meldung
 *       ueber die Glocke und die vorbereiteten Mitteilungen an die
 *       Gesellschaften.
 *  - sonst -> der Antrag bleibt offen, die Verwaltung sieht das
 *    Pruefergebnis (gruen/gelb/rot) direkt in der Review-Liste.
 *
 * Die Pruefung laeuft im Hintergrund, weil OCR eines Ausweisfotos einige
 * Sekunden dauern kann - der Kunde soll im Portal nicht warten.
 */
class VerifyChangeRequestProofJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 2;
    public int $backoff = 30;

    public function __construct(public string $changeRequestId)
    {
    }

    public function handle(
        ChangeProofVerifier $verifier,
        ChangeProofPolicy $policy,
        ChangeRequestService $service,
    ): void {
        $request = CustomerChangeRequest::with(['customer.user', 'documents'])->find($this->changeRequestId);
        if (!$request || $request->status !== 'pending') {
            return;
        }

        try {
            $result = $verifier->verify($request);
        } catch (\Throwable $e) {
            Log::warning('Nachweispruefung fehlgeschlagen: ' . $e->getMessage());
            $request->forceFill(['proof_status' => 'unreadable', 'proof_checked_at' => now()])->save();
            return;
        }

        $request->refresh();

        if ($policy->autoApproveAllowed($request)) {
            $approved = $service->approve($request, null, 'Automatisch freigegeben: Nachweis bestätigt.', auto: true);
            if ($approved['ok']) {
                return; // Die Verwaltung wird bereits ueber die Freigabe informiert.
            }
        }

        $this->notifyStaff($request, $result['status'] ?? $request->proof_status);
    }

    /** Ergebnis der Pruefung an die Verwaltung melden (Glocke). */
    private function notifyStaff(CustomerChangeRequest $request, string $status): void
    {
        $recipients = User::whereIn('role', ['admin', 'manager', 'support'])
            ->where('is_active', true)->pluck('id');
        if ($recipients->isEmpty()) {
            return;
        }

        $state = CustomerChangeRequest::PROOF_STATES[$status] ?? CustomerChangeRequest::PROOF_STATES['none'];
        $name = $request->customer?->user?->name ?: 'Kunde';

        \App\Support\Facades\Notify::pushMany($recipients, [
            'type' => \App\Services\Notifications\NotificationService::TYPE_CHANGE_REQUEST,
            'title' => $state['icon'] . ' Nachweis geprüft: ' . $request->typeLabel(),
            'body' => $name . ' – ' . $state['label'] . '. Bitte freigeben oder ablehnen.',
            'link' => route('admin.change_requests'),
            'change_request_id' => $request->id,
            'dedup_key' => 'change-request-proof-' . $request->id,
        ]);
    }
}
