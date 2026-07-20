<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Models\Customer;
use App\Models\ActivityLog;
use App\Mail\EmployeeWelcomeMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmployeeController extends Controller
{
    public function index() {
        $roles = auth()->user()->role === 'manager'
            ? ['manager', 'support', 'employee']
            : ['admin', 'manager', 'support', 'employee'];
        $employees = User::whereIn('role', $roles)->orderBy('name')->get();
        return view('admin.employees', compact('employees'));
    }

    public function create() {
        return view('admin.employee_create');
    }

    public function store(Request $request) {
        $request->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
        ]);

        $plainPassword = $request->password;

        $employee = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($plainPassword),
            'role' => 'employee',
            'access_level' => $request->access_level ?? 'full',
            'can_see_all_customers' => $request->has('can_see_all_customers'),
            'can_manage_contracts' => $request->has('can_manage_contracts'),
            'can_manage_tickets' => $request->has('can_manage_tickets'),
            'can_approve_changes' => $request->has('can_approve_changes'),
            'can_send_emails' => $request->has('can_send_emails'),
            'can_import_export' => $request->has('can_import_export'),
        ]);

        // بناء قائمة الصلاحيات للإيميل
        $permLabels = [];
        if($request->has('can_manage_contracts')) $permLabels[] = '📄 Verträge verwalten';
        if($request->has('can_manage_tickets')) $permLabels[] = '💬 Tickets bearbeiten';
        if($request->has('can_approve_changes')) $permLabels[] = '✅ Änderungen genehmigen';
        if($request->has('can_send_emails')) $permLabels[] = '📧 E-Mails senden';
        if($request->has('can_import_export')) $permLabels[] = '📤 Import / Export';
        if($request->has('can_see_all_customers')) $permLabels[] = '👥 Zugriff auf alle Kunden';

        // إرسال إيميل الترحيب
        try {
            Mail::to($employee->email)->send(new EmployeeWelcomeMail(
                $employee->name,
                $employee->email,
                $plainPassword,
                $permLabels
            ));
        } catch(\Throwable $e) { \Log::warning("Welcome-Mail fehlgeschlagen: ".$e->getMessage()); }

        // تسجيل النشاط
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'employee_created',
            'entity_type' => 'user',
            'entity_id' => $employee->id,
            'meta' => json_encode(['name' => $employee->name, 'email' => $employee->email]),
        ]);

        return redirect()->route('admin.employees')->with('success', 'Mitarbeiter erstellt und Zugangsdaten per E-Mail gesendet.');
    }

    public function edit($id) {
        $employee = User::findOrFail($id);
        if (auth()->user()->role === 'manager' && $employee->role === 'admin') {
            abort(403, 'Kein Zugriff auf Administrator-Konten.');
        }
        $assignedIds = $employee->assignedCustomers()->pluck('customers.id')->toArray();
        return view('admin.employee_edit', compact('employee','assignedIds'));
    }

    /**
     * Mitarbeiter-Detailseite: Profil + die zugewiesenen Kunden als
     * durchsuchbare, paginierte Liste (gleiche UX wie der Kundenbereich).
     * Ueber die Detailseite laeuft die smarte Mehrfach-Zuweisung und das
     * Entfernen einzelner Kunden.
     */
    public function show(Request $request, $id) {
        $employee = User::findOrFail($id);
        if (auth()->user()->role === 'manager' && $employee->role === 'admin') {
            abort(403, 'Kein Zugriff auf Administrator-Konten.');
        }
        // Zugewiesene Kunden dieses Mitarbeiters (aktive Vertraege fuer die
        // Icons mitladen). Die Freitext-Suche nutzt denselben Scope wie der
        // Kundenbereich (alle Felder) - hier nur auf das Portfolio begrenzt.
        $query = $employee->assignedCustomers()
            ->with(['user', 'contracts' => fn($q) => $q->where('status', 'active')->select('id', 'customer_id', 'type', 'status')]);
        if ($request->filled('q')) {
            $query->search((string) $request->q);
        }
        $assignedCount = $employee->assignedCustomers()->count();
        $customers = $query->orderByDesc('employee_customers.created_at')->paginate(25)->withQueryString();
        // IDs aller zugewiesenen Kunden - damit die smarte Suche bereits
        // zugewiesene Treffer markiert (kein doppeltes Zuweisen).
        $assignedIds = $employee->assignedCustomers()->pluck('customers.id')->map(fn($v) => (string) $v)->values();

        return view('admin.employee_show', compact('employee', 'customers', 'assignedCount', 'assignedIds'));
    }

    /**
     * Smarte Mehrfach-Zuweisung: mehrere gesuchte Kunden auf einmal diesem
     * Mitarbeiter zuweisen (ohne bestehende Zuweisungen zu loesen).
     */
    public function assignCustomers(Request $request, $id) {
        $employee = User::findOrFail($id);
        if (auth()->user()->role === 'manager' && $employee->role === 'admin') {
            abort(403, 'Kein Zugriff auf Administrator-Konten.');
        }
        $data = $request->validate([
            'customer_ids' => 'required|array|min:1',
            'customer_ids.*' => 'string',
        ]);
        // Nur echte, existierende Kunden zuweisen (keine ungueltigen IDs).
        $ids = Customer::whereIn('id', $data['customer_ids'])->pluck('id')->map(fn($v) => (string) $v)->all();
        if ($ids === []) {
            return back()->with('error', 'Keine gueltigen Kunden ausgewaehlt.');
        }
        $employee->assignedCustomers()->syncWithoutDetaching($ids);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'customer_reassigned',
            'entity_type' => 'user',
            'entity_id' => $employee->id,
            'meta' => json_encode([
                'to' => $employee->name,
                'count' => count($ids),
                'mode' => 'hinzugefuegt',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', count($ids) . ' Kunde(n) ' . $employee->name . ' zugewiesen.');
    }

    /** Einen einzelnen Kunden aus dem Portfolio des Mitarbeiters entfernen. */
    public function unassignCustomer($id, $customerId) {
        $employee = User::findOrFail($id);
        if (auth()->user()->role === 'manager' && $employee->role === 'admin') {
            abort(403, 'Kein Zugriff auf Administrator-Konten.');
        }
        $customer = Customer::find($customerId);
        $employee->assignedCustomers()->detach($customerId);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'customer_unassigned',
            'entity_type' => 'user',
            'entity_id' => $employee->id,
            'meta' => json_encode([
                'from' => $employee->name,
                'customer' => $customer?->user?->name,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Kunde aus dem Portfolio entfernt.');
    }

    public function update(Request $request, $id) {
        $employee = User::findOrFail($id);
        if (auth()->user()->role === 'manager' && $employee->role === 'admin') {
            abort(403, 'Kein Zugriff auf Administrator-Konten.');
        }
        $request->validate([
            'name' => 'required|string|max:255',
            'role' => 'nullable|in:employee,manager',
            'access_level' => 'nullable|in:full,limited',
        ]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $employee) {
            $employee->update([
                'name' => $request->name,
                'role' => in_array($request->role, ['employee', 'manager']) ? $request->role : $employee->role,
                'access_level' => $request->access_level ?? 'full',
                'can_see_all_customers' => $request->has('can_see_all_customers'),
                'can_manage_contracts' => $request->has('can_manage_contracts'),
                'can_manage_tickets' => $request->has('can_manage_tickets'),
                'can_approve_changes' => $request->has('can_approve_changes'),
                'can_send_emails' => $request->has('can_send_emails'),
                'can_import_export' => $request->has('can_import_export'),
            ]);

            // Zuweisungen NUR ändern, wenn das Formular sie explizit mitschickt.
            // Kein detach() mehr — Zuweisungen bleiben immer erhalten.
            if ($request->has('assigned_customers_present')) {
                $customerIds = array_filter($request->input('assigned_customers', []));
                $employee->assignedCustomers()->sync($customerIds);
            }
        });

        // Rollen-/Rechteaenderung mit protokollieren (Audit INT-8).
        ActivityLog::record('employee_updated', 'user', $employee->id, [
            'name' => $employee->name,
            'role' => $employee->role,
            'access_level' => $employee->access_level,
            'can_see_all_customers' => $employee->can_see_all_customers,
            'can_import_export' => $employee->can_import_export,
        ]);

        return redirect()->route('admin.employees')->with('success', 'Mitarbeiter aktualisiert.');
    }

    public function destroy($id) {
        $employee = User::findOrFail($id);
        if ($employee->id === auth()->id()) abort(403, 'Eigenes Konto kann nicht geloescht werden.');
        if ($employee->role === 'admin' && auth()->user()->role !== 'admin') abort(403);
        $name = $employee->name;
        // Referenz-Nullung + Loeschung atomar (Audit-Re-Audit): sonst koennte
        // ein Abbruch dazwischen genullte Referenzen bei noch existierendem
        // User hinterlassen.
        \Illuminate\Support\Facades\DB::transaction(function () use ($employee) {
            // Ticket-Antworten des Mitarbeiters bleiben in den Kundengespraechen
            // erhalten (sender wird geleert, Views zeigen "Dienstly24 Team") -
            // vorher loeschte der DB-Cascade die komplette Historie mit.
            \App\Models\TicketMessage::where('sender_id', $employee->id)->update(['sender_id' => null]);

            // Betriebs-/Audit-Historie erhalten (Audit DB-4): Autor-/Zustaendig-
            // Referenzen leeren, bevor der User geloescht wird. Wirkt auf jedem
            // Treiber (auch SQLite, wo die FK weiterhin CASCADE ist).
            foreach ([
                [\App\Models\Task::class, ['assigned_to', 'created_by']],
                [\App\Models\Appointment::class, ['assigned_to']],
                [\App\Models\Announcement::class, ['created_by']],
                [\App\Models\CustomerNote::class, ['created_by']],
                [\App\Models\EmailCampaign::class, ['created_by']],
                [\App\Models\EmailLog::class, ['user_id']],
            ] as [$model, $cols]) {
                foreach ($cols as $col) {
                    $model::where($col, $employee->id)->update([$col => null]);
                }
            }

            $employee->delete();
        });

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'employee_deleted',
            'entity_type' => 'user',
            'entity_id' => $id,
            'meta' => json_encode(['name' => $name]),
        ]);

        return redirect()->route('admin.employees')->with('success', 'Mitarbeiter gelöscht.');
    }

    public function customerSearch(Request $request) {
        $q = trim($request->get('q', ''));
        if (strlen($q) < 2) return response()->json([]);
        // Suche ueber ALLE Kundenfelder (Name, E-Mail, Telefon, Kundennummer,
        // Vertragsnummer, Anschrift, PLZ/Ort, Kennzeichen, FIN, Zaehlernummer
        // ...), damit der Kunde mit jeder vorliegenden Information gefunden und
        // dem Mitarbeiter zugewiesen werden kann.
        $customers = Customer::with('user')
            ->search($q)
            ->limit(15)->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->user?->name,
                'number' => $c->customer_number,
                'email' => $c->user?->email,
                'address' => $c->fullAddress(),
            ]);
        return response()->json($customers);
    }

    public function toggleActive($id) {
        $employee = User::findOrFail($id);
        if ($employee->id === auth()->id()) abort(403, 'Eigenes Konto kann nicht deaktiviert werden.');
        if ($employee->role === 'admin' && auth()->user()->role !== 'admin') abort(403);
        $employee->update(['is_active' => !$employee->is_active]);
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $employee->is_active ? 'employee_activated' : 'employee_deactivated',
            'entity_type' => 'user',
            'entity_id' => $employee->id,
            'meta' => json_encode(['name' => $employee->name]),
        ]);
        return back()->with('success', 'Mitarbeiter ' . ($employee->is_active ? 'aktiviert' : 'deaktiviert') . '.');
    }

    public function transferPortfolio(Request $request) {
        $request->validate([
            'from_employee' => 'required|exists:users,id',
            'to_employee' => 'required|exists:users,id|different:from_employee',
            'reason' => 'required|string|max:500',
        ]);
        $from = User::findOrFail($request->from_employee);
        $to = User::findOrFail($request->to_employee);
        $customerIds = $from->assignedCustomers()->pluck('customers.id')->toArray();
        if (empty($customerIds)) {
            return back()->with('success', 'Keine Kunden zum Uebertragen vorhanden.');
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($from, $to, $customerIds, $request) {
            foreach ($customerIds as $cid) {
                $to->assignedCustomers()->syncWithoutDetaching([$cid]);
            }
            $from->assignedCustomers()->detach($customerIds);
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'portfolio_transferred',
                'entity_type' => 'user',
                'entity_id' => $from->id,
                'meta' => json_encode([
                    'from' => $from->name,
                    'to' => $to->name,
                    'count' => count($customerIds),
                    'reason' => $request->reason,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        });
        return back()->with('success', count($customerIds) . ' Kunden von ' . $from->name . ' an ' . $to->name . ' uebertragen.');
    }

    public function storeSubstitution(Request $request) {
        $request->validate([
            'absent_user_id' => 'required|exists:users,id',
            'substitute_user_id' => 'required|exists:users,id|different:absent_user_id',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'reason' => 'nullable|string|max:255',
        ]);
        $sub = \App\Models\Substitution::create([
            'absent_user_id' => $request->absent_user_id,
            'substitute_user_id' => $request->substitute_user_id,
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
            'reason' => $request->reason,
            'created_by' => auth()->id(),
        ]);
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'substitution_created',
            'entity_type' => 'substitution',
            'entity_id' => $sub->id,
            'meta' => json_encode([
                'absent' => User::find($request->absent_user_id)?->name,
                'substitute' => User::find($request->substitute_user_id)?->name,
                'from' => $request->from_date, 'to' => $request->to_date,
                'reason' => $request->reason,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        return back()->with('success', 'Vertretung eingerichtet.');
    }

    public function destroySubstitution($id) {
        $sub = \App\Models\Substitution::findOrFail($id);
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'substitution_ended',
            'entity_type' => 'substitution',
            'entity_id' => $sub->id,
            'meta' => json_encode(['absent' => $sub->absentUser?->name, 'substitute' => $sub->substituteUser?->name], JSON_UNESCAPED_UNICODE),
        ]);
        $sub->delete();
        return back()->with('success', 'Vertretung beendet.');
    }

    public function teamPage() {
        $employees = User::whereIn('role', ['manager', 'support', 'employee'])
            ->withCount('assignedCustomers')->orderBy('name')->get();
        $substitutions = \App\Models\Substitution::with(['absentUser', 'substituteUser'])
            ->whereDate('to_date', '>=', now()->subDays(7))->latest()->get();
        return view('admin.team_verwaltung', compact('employees', 'substitutions'));
    }

    public function activityLog() {
        // Seitenaufrufe (seite_geoeffnet) wuerden das Audit-Protokoll
        // fluten - der vollstaendige Verlauf inkl. Seitenaufrufen ist je
        // Mitarbeiter unter "Aktivitaet & Zeiten" einsehbar.
        $logs = ActivityLog::with('user')
            ->where('action', '!=', 'seite_geoeffnet')
            ->latest()
            ->paginate(50);
        return view('admin.activity_log', compact('logs'));
    }
}
