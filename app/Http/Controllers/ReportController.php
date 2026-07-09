<?php
namespace App\Http\Controllers;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /** null = alle sichtbar; sonst Array der erlaubten Kunden-IDs */
    private function visibleCustomerIds(): ?array {
        $user = auth()->user();
        if (!$user || $user->canSeeAllCustomers()) return null;
        return $user->assignedCustomers()->pluck('customers.id')->toArray();
    }

    public function index(Request $request) {
        $from = $request->get('from') ? \Carbon\Carbon::parse($request->get('from')) : now()->subDays(30);
        $to = $request->get('to') ? \Carbon\Carbon::parse($request->get('to')) : now();
        $ids = $this->visibleCustomerIds();
        $cf = function ($q) use ($ids) { return $ids === null ? $q : $q->whereIn('customer_id', $ids); };

        $contracts = [
            'active' => $cf(Contract::where('status','active'))->whereBetween('created_at',[$from,$to])->count(),
            'pending' => $cf(Contract::where('status','pending'))->whereBetween('created_at',[$from,$to])->count(),
            'cancelled' => $cf(Contract::where('status','cancelled'))->whereBetween('created_at',[$from,$to])->count(),
            'expired' => $cf(Contract::where('status','expired'))->whereBetween('created_at',[$from,$to])->count(),
            'total' => $cf(Contract::whereBetween('created_at',[$from,$to]))->count(),
            'by_type' => $cf(Contract::whereBetween('created_at',[$from,$to]))->selectRaw('type, count(*) as count')->groupBy('type')->pluck('count','type'),
        ];

        $tickets = [
            'total' => $cf(Ticket::whereBetween('created_at',[$from,$to]))->count(),
            'open' => $cf(Ticket::where('status','open'))->whereBetween('created_at',[$from,$to])->count(),
            'closed' => $cf(Ticket::where('status','closed'))->whereBetween('created_at',[$from,$to])->count(),
            'in_progress' => $cf(Ticket::where('status','in_progress'))->whereBetween('created_at',[$from,$to])->count(),
            'by_type' => $cf(Ticket::whereBetween('created_at',[$from,$to]))->selectRaw('type, count(*) as count')->groupBy('type')->pluck('count','type'),
        ];

        $customers_stats = [
            'total' => $ids === null ? Customer::count() : count($ids),
            'new' => Customer::whereBetween('created_at',[$from,$to])->when($ids !== null, fn($q) => $q->whereIn('customers.id', $ids))->count(),
            'privat' => Customer::where('customer_type','privat')->when($ids !== null, fn($q) => $q->whereIn('customers.id', $ids))->count(),
            'firma' => Customer::where('customer_type','firma')->when($ids !== null, fn($q) => $q->whereIn('customers.id', $ids))->count(),
        ];

        $expiring = $cf(Contract::with('customer.user'))
            ->whereNotNull('end_date')
            ->whereDate('end_date','>=',now())
            ->whereDate('end_date','<=',now()->addDays(30))
            ->where('status','active')
            ->orderBy('end_date')
            ->get();

        $warnings = $cf(Contract::with('customer.user'))
            ->whereNotNull('end_date')
            ->whereDate('end_date','<',now())
            ->where('status','active')
            ->count();

        return view('admin.reports', compact('contracts','tickets','customers_stats','expiring','warnings','from','to'));
    }
}
