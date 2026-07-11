<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Commission;
use App\Services\LexofficeService;
use Illuminate\Http\Request;

/**
 * Provisions-Freigabe (Architekturplan Abschnitte 10/13, Priorität 6).
 * Die Buchung nach Lexoffice ist die Mitarbeiter-Bestätigungsstufe:
 * automatisch erfasste Gutschriften (CommissionWorkflowService) werden
 * hier geprüft und erst dann als Beleg erzeugt.
 */
class CommissionController extends Controller
{
    public function index()
    {
        $pending = Commission::with('partner')->pendingReview()->orderBy('created_at')->get();
        $recent = Commission::with(['partner', 'reviewer'])
            ->where('status', '!=', 'pending_review')
            ->latest('reviewed_at')->limit(50)->get();

        return view('admin.commissions', compact('pending', 'recent'));
    }

    public function book(Request $request, $id, LexofficeService $lexoffice)
    {
        $commission = Commission::with('partner')->findOrFail($id);
        if ($commission->status !== 'pending_review') {
            return back()->with('error', 'Diese Gutschrift wurde bereits bearbeitet.');
        }

        // Mitarbeiter kann Betrag/Nummer/Datum beim Buchen korrigieren -
        // die automatische Erfassung ist ein Vorschlag, keine Wahrheit.
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:1000000',
            'credit_note_number' => 'nullable|string|max:100',
            'statement_date' => 'required|date',
        ]);

        $voucher = $lexoffice->createVoucher([
            'type' => 'salesinvoice',
            'voucherNumber' => $data['credit_note_number'] ?: null,
            'voucherDate' => $data['statement_date'],
            'totalGrossAmount' => (float) $data['amount'],
            'totalTaxAmount' => 0,
            'taxType' => 'gross',
            'useCollectiveContact' => true,
            'remark' => 'Provisionsgutschrift ' . $commission->partner->name,
            'voucherItems' => [[
                'amount' => (float) $data['amount'],
                'taxAmount' => 0,
                'taxRatePercent' => 0,
                'categoryId' => '8f8664a8-fd86-11e1-a21f-0800200c9a66', // lexoffice Standard "Einnahmen"
            ]],
        ]);

        if ($voucher === null) {
            return back()->with('error', 'Lexoffice-Beleg konnte nicht erstellt werden (API-Key/Verbindung prüfen). Die Gutschrift bleibt zur Prüfung offen.');
        }

        $commission->update([
            'amount' => $data['amount'],
            'credit_note_number' => $data['credit_note_number'] ?: $commission->credit_note_number,
            'statement_date' => $data['statement_date'],
            'status' => 'booked',
            'lexoffice_voucher_id' => $voucher['id'] ?? null,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'commission_booked',
            'entity_type' => 'commission',
            'entity_id' => $commission->id,
            'meta' => json_encode([
                'partner_id' => (string) $commission->partner_id,
                'amount' => $data['amount'],
                'lexoffice_voucher_id' => $voucher['id'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Gutschrift gebucht und Lexoffice-Beleg erstellt.');
    }

    public function reject(Request $request, $id)
    {
        $commission = Commission::findOrFail($id);
        if ($commission->status !== 'pending_review') {
            return back()->with('error', 'Diese Gutschrift wurde bereits bearbeitet.');
        }

        $commission->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'commission_rejected',
            'entity_type' => 'commission',
            'entity_id' => $commission->id,
            'meta' => json_encode(['partner_id' => (string) $commission->partner_id], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Gutschrift abgelehnt.');
    }
}
