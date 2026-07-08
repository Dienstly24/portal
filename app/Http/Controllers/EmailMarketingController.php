<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use App\Models\Contract;
use App\Models\EmailCampaign;
use App\Mail\CampaignMail;
use App\Mail\ContractExpiryMail;
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

    public function index() {
        $ids = $this->visibleCustomerIds();
        $campaigns = EmailCampaign::with('createdBy')->when($ids !== null, fn($q) => $q->where('created_by', auth()->id()))->latest()->get();
        $totalCustomers = $ids === null ? Customer::count() : count($ids);
        $expiringSoon = Contract::whereNotNull('end_date')
            ->whereDate('end_date', '<=', now()->addDays(30))
            ->whereDate('end_date', '>=', now())
            ->where('status', 'active')
            ->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))
            ->count();
        return view('admin.email_marketing', compact('campaigns', 'totalCustomers', 'expiringSoon'));
    }

    public function send(Request $request) {
        $request->validate(['subject' => 'required', 'body' => 'required', 'target' => 'required']);
        $customers = $this->getTargetCustomers($request->target);
        $sent = 0;
        foreach ($customers as $customer) {
            if (!$customer->user?->email || str_contains($customer->user->email, '@dienstly24.internal')) continue;
            try {
                Mail::to($customer->user->email)
                    ->send(new CampaignMail($request->subject, $request->body, $customer->user->name));
                $sent++;
            } catch (\Exception $e) { continue; }
        }
        EmailCampaign::create([
            'created_by' => auth()->id(),
            'subject' => $request->subject,
            'body' => $request->body,
            'target' => $request->target,
            'status' => 'sent',
            'sent_count' => $sent,
            'sent_at' => now(),
        ]);
        return back()->with('success', "$sent E-Mails erfolgreich gesendet.");
    }

    public function sendContractReminders() {
        $sent = 0;
        foreach ([30, 14, 7] as $days) {
            $ids = $this->visibleCustomerIds();
            $contracts = Contract::with('customer.user')
                ->whereNotNull('end_date')
                ->whereDate('end_date', now()->addDays($days))
                ->where('status', 'active')
                ->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids))
                ->get();
            foreach ($contracts as $contract) {
                if (!$contract->customer?->user?->email) continue;
                if (str_contains($contract->customer->user->email, '@dienstly24.internal')) continue;
                try {
                    Mail::to($contract->customer->user->email)
                        ->send(new ContractExpiryMail($contract, $days));
                    $sent++;
                } catch (\Exception $e) { continue; }
            }
        }
        return back()->with('success', "$sent Erinnerungs-E-Mails gesendet.");
    }

    private function getTargetCustomers($target) {
        $ids = $this->visibleCustomerIds();
        $base = Customer::with('user')->when($ids !== null, fn($q) => $q->whereIn('customers.id', $ids));
        if ($target === 'all') return $base->get();
        if (in_array($target, ['de', 'ar'])) return $base->where('preferred_lang', $target)->get();
        return $base->whereHas('contracts', fn($q) => $q->where('type', $target)->where('status', 'active'))->get();
    }
}
