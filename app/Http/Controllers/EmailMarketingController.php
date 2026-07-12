<?php
namespace App\Http\Controllers;
use App\Jobs\SendCampaignJob;
use App\Mail\CampaignMail;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmailCampaign;
use App\Services\ContractSwitchReminderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailMarketingController extends Controller
{
    /** null = alle sichtbar; sonst Array der erlaubten Kunden-IDs */
    private function visibleCustomerIds(): ?array {
        $user = auth()->user();
        if (!$user || $user->canSeeAllCustomers()) return null;
        return $user->assignedCustomers()->pluck('customers.id')->toArray();
    }

    public function index(ContractSwitchReminderService $reminders) {
        $ids = $this->visibleCustomerIds();
        $campaigns = EmailCampaign::with('createdBy')->withCount([
                'logs as failed_count' => fn($q) => $q->where('status', 'failed'),
            ])
            ->when($ids !== null, fn($q) => $q->where('created_by', auth()->id()))
            ->latest()->get();
        $totalCustomers = $ids === null ? Customer::count() : count($ids);
        $reachableCustomers = Customer::marketingReachable()
            ->when($ids !== null, fn($q) => $q->whereIn('customers.id', $ids))->count();
        $dueReminders = count($reminders->due($ids));
        // ?draft={id}: Entwurf/geplante Kampagne ins Formular laden
        $draft = request('draft')
            ? $campaigns->first(fn($c) => $c->id === request('draft') && in_array($c->status, ['draft', 'scheduled'], true))
            : null;
        return view('admin.email_marketing', compact('campaigns', 'totalCustomers', 'reachableCustomers', 'dueReminders', 'draft'));
    }

    /**
     * Kampagne anlegen: sofort senden (Queue), als Entwurf speichern oder
     * für später planen - gesteuert über action. Mit draft_id wird ein
     * bestehender Entwurf aktualisiert statt neu angelegt.
     */
    public function send(Request $request) {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
            'target' => 'required|in:' . implode(',', EmailCampaign::TARGETS),
            'action' => 'nullable|in:send,draft,schedule',
            'scheduled_for' => 'required_if:action,schedule|nullable|date|after:now',
            'draft_id' => 'nullable|uuid',
        ]);
        $action = $data['action'] ?? 'send';

        $campaign = null;
        if (!empty($data['draft_id'])) {
            $campaign = EmailCampaign::where('id', $data['draft_id'])
                ->whereIn('status', ['draft', 'scheduled'])
                ->when($this->visibleCustomerIds() !== null, fn($q) => $q->where('created_by', auth()->id()))
                ->first();
        }

        $attributes = [
            'created_by' => $campaign->created_by ?? auth()->id(),
            'subject' => $data['subject'],
            'body' => $data['body'],
            'target' => $data['target'],
            'status' => match ($action) { 'draft' => 'draft', 'schedule' => 'scheduled', default => 'sending' },
            'scheduled_for' => $action === 'schedule' ? $data['scheduled_for'] : null,
        ];
        if ($campaign) {
            $campaign->update($attributes);
        } else {
            $campaign = EmailCampaign::create($attributes);
        }

        if ($action === 'send') {
            SendCampaignJob::dispatch($campaign->id);
            return back()->with('success', 'Kampagne wird im Hintergrund versendet.');
        }
        return back()->with('success', $action === 'draft'
            ? 'Entwurf gespeichert.'
            : 'Kampagne geplant für ' . $campaign->scheduled_for->format('d.m.Y H:i') . '.');
    }

    /** Entwurf / geplante Kampagne sofort versenden. */
    public function dispatchCampaign(string $id) {
        $campaign = $this->ownCampaign($id, ['draft', 'scheduled']);
        $campaign->update(['status' => 'sending', 'scheduled_for' => null]);
        SendCampaignJob::dispatch($campaign->id);
        return back()->with('success', 'Kampagne wird im Hintergrund versendet.');
    }

    /** Entwurf / geplante Kampagne löschen (Gesendetes bleibt als Historie). */
    public function destroyCampaign(string $id) {
        $this->ownCampaign($id, ['draft', 'scheduled'])->delete();
        return back()->with('success', 'Kampagne gelöscht.');
    }

    /** Serverseitig gerenderte Vorschau der Kampagnen-Mail (Paket B3). */
    public function preview(Request $request) {
        $data = $request->validate(['subject' => 'required|string|max:255', 'body' => 'required|string']);
        return response((new CampaignMail(
            $data['subject'], $data['body'], auth()->user()->name, '#',
        ))->render());
    }

    /** Testversand an die eigene Adresse - ohne Kampagne, ohne Protokoll. */
    public function testSend(Request $request) {
        $data = $request->validate(['subject' => 'required|string|max:255', 'body' => 'required|string']);
        Mail::to(auth()->user()->email)->send(new CampaignMail(
            '[TEST] ' . $data['subject'], $data['body'], auth()->user()->name, '#',
        ));
        return back()->with('success', 'Test-E-Mail an ' . auth()->user()->email . ' gesendet.');
    }

    /**
     * Wechsel-Erinnerungen manuell anstoßen. Gleiche Engine wie der
     * tägliche Cron; der Unique-Index verhindert Doppelversand.
     */
    public function sendContractReminders(ContractSwitchReminderService $reminders) {
        $sent = $reminders->run($this->visibleCustomerIds());
        return back()->with('success', "$sent Wechsel-Erinnerungen gesendet.");
    }

    /** Kunde hat auf eine Wechsel-Erinnerung reagiert -> Follow-up stoppen. */
    public function markSwitchResponded(string $contractId, ContractSwitchReminderService $reminders) {
        $ids = $this->visibleCustomerIds();
        $contract = Contract::when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))->findOrFail($contractId);
        $reminders->markResponded($contract);
        return back()->with('success', 'Als „Kunde hat reagiert" markiert – keine weitere Erinnerung für diese Periode.');
    }

    private function ownCampaign(string $id, array $statuses): EmailCampaign {
        return EmailCampaign::where('id', $id)->whereIn('status', $statuses)
            ->when($this->visibleCustomerIds() !== null, fn($q) => $q->where('created_by', auth()->id()))
            ->firstOrFail();
    }
}
