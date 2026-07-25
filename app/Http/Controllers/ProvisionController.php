<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Partner;
use App\Models\Provision;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Vermittler-Provisionen (AUSGANG): Verguetungen an Mitarbeiter und
 * Vertriebspartner fuer geworbene Neukunden. Erfasst aus dem Neukunden-
 * Bericht (Vorschlag aus den Provisions-Saetzen) oder manuell; Auszahlung
 * wird per Ein-Klick bestaetigt (HITL wie bei den Gutschriften).
 */
class ProvisionController extends Controller
{
    public function index(Request $request) {
        $query = Provision::with(['user', 'partner', 'customer.user', 'creator', 'payer'])
            ->orderByDesc('created_at');

        if ($request->filled('status') && isset(Provision::STATUSES[$request->status])) {
            $query->where('status', $request->status);
        }
        if ($request->filled('empfaenger')) {
            $e = $request->empfaenger;
            if (str_starts_with($e, 'u:')) {
                $query->where('user_id', (int) substr($e, 2));
            } elseif (str_starts_with($e, 'p:')) {
                $query->where('partner_id', substr($e, 2));
            }
        }

        $provisions = $query->paginate(50)->withQueryString();

        $totals = [
            'offen' => (float) Provision::where('status', 'offen')->sum('amount'),
            'ausgezahlt' => (float) Provision::where('status', 'ausgezahlt')->sum('amount'),
        ];

        $employees = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])->orderBy('name')->get();
        $partners = Partner::orderBy('name')->get();

        return view('admin.provisions', compact('provisions', 'totals', 'employees', 'partners'));
    }

    /** Provision anlegen (aus dem Neukunden-Bericht oder manuell). */
    public function store(Request $request) {
        $request->validate([
            'empfaenger' => 'required|string|max:60',
            'amount' => 'required|numeric|min:0.01|max:1000000',
            'note' => 'nullable|string|max:500',
            'period_from' => 'nullable|date',
            'period_to' => 'nullable|date|after_or_equal:period_from',
        ]);

        $data = [
            'amount' => round((float) $request->amount, 2),
            'note' => $request->note,
            'period_from' => $request->period_from,
            'period_to' => $request->period_to,
            'status' => 'offen',
            'created_by' => auth()->id(),
        ];

        $e = $request->empfaenger;
        if (str_starts_with($e, 'u:')) {
            $recipient = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])
                ->findOrFail((int) substr($e, 2));
            $data['user_id'] = $recipient->id;
        } elseif (str_starts_with($e, 'p:')) {
            $recipient = Partner::findOrFail(substr($e, 2));
            $data['partner_id'] = $recipient->id;
        } else {
            return back()->with('error', 'Ungueltiger Empfaenger.');
        }

        $provision = Provision::create($data);
        ActivityLog::record('provision_created', 'provision', $provision->id, [
            'empfaenger' => $recipient->name,
            'betrag' => $data['amount'],
        ]);

        return back()->with('success',
            'Provision ueber ' . number_format($data['amount'], 2, ',', '.') . ' EUR fuer ' . $recipient->name . ' erfasst.');
    }

    /** Status aendern: auszahlen, stornieren oder wieder oeffnen. */
    public function updateStatus(Request $request, $id) {
        $provision = Provision::findOrFail($id);
        $request->validate(['status' => 'required|in:offen,ausgezahlt,storniert']);

        $update = ['status' => $request->status];
        if ($request->status === 'ausgezahlt') {
            $update['paid_by'] = auth()->id();
            $update['paid_at'] = now();
        } else {
            $update['paid_by'] = null;
            $update['paid_at'] = null;
        }
        $provision->update($update);

        ActivityLog::record('provision_status_changed', 'provision', $provision->id, [
            'empfaenger' => $provision->recipientName(),
            'status' => $request->status,
        ]);

        return back()->with('success', 'Provision: ' . $provision->statusLabel() . '.');
    }
}
