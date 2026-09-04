<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Aufgaben & Wiedervorlagen der Beraterwelt.
 *
 * Ausbau 26.07.2026 (Betreiber-Vorgabe "Aufgaben professionell machen"):
 *  - Kundenauswahl ueber Sofort-Suche im eigenen Portfolio-Scope statt
 *    einer Dropdown-Liste mit ALLEN Kunden.
 *  - Wiedervorlage-Praesets ("in 10/20 Tagen nachfassen") + Verschieben
 *    (+1 Tag ... +1 Monat) direkt aus der Liste.
 *  - Optional je Aufgabe eine AUTOMATISCHE Kunden-E-Mail zum Stichtag
 *    (Vorlagen + Platzhalter, Versand via tasks:send-auto-emails).
 *  - Voll-Bearbeitung bestehender Aufgaben, Filter (ueberfaellig, Suche),
 *    Zaehler je Tab, taegliche Glocken-Erinnerung (tasks:remind).
 */
class TaskController extends Controller
{
    use ScopesCustomerAccess;

    public function index(Request $request) {
        $user = auth()->user();
        $tab = $request->get('tab', 'mine');
        $status = $request->get('status', '');
        $type = $request->get('type', '');
        $due = $request->get('due', '');
        $q = trim((string) $request->get('q', ''));
        $vids = $this->visibleCustomerIds();
        $seesAll = in_array($user->role, ['admin', 'manager'], true);

        $query = Task::with(['assignedTo', 'customer.user', 'createdBy', 'emailMessage']);

        // Tabs: Meine + Kunden zeigen OFFENE Vorgaenge, Erledigtes hat den
        // eigenen Tab. Kunden-Aufgaben nur im eigenen Portfolio-Scope
        // (Mitarbeiter sehen keine fremden Kundennamen), Erledigt fuer
        // Nicht-Verwaltung nur eigene (zugewiesen oder selbst erstellt).
        if ($tab === 'mine') {
            $query->where('assigned_to', $user->id)->open();
        } elseif ($tab === 'customer') {
            $query->whereNotNull('customer_id')->open()
                ->when($vids !== null, fn ($qq) => $qq->whereIn('customer_id', $vids));
        } elseif ($tab === 'done') {
            $query->where('status', 'done')
                ->when(! $seesAll, fn ($qq) => $qq->where(fn ($w) => $w
                    ->where('assigned_to', $user->id)->orWhere('created_by', $user->id)));
        }

        if ($status) $query->where('status', $status);
        if ($type) $query->where('type', $type);
        if ($due === 'today') $query->whereDate('due_date', today());
        elseif ($due === 'overdue') $query->whereDate('due_date', '<', today())->open();
        elseif (in_array($due, ['7', '14', '30'], true)) $query->whereDate('due_date', '<=', today()->addDays((int) $due));
        if ($request->filled('customer')) $query->where('customer_id', $request->get('customer'));

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(function ($w) use ($like) {
                $w->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_number', 'like', $like))
                    ->orWhereHas('customer.user', fn ($u) => $u->where('name', 'like', $like));
            });
        }

        // CASE statt MySQL-spezifischem FIELD(), damit die Seite auch auf
        // SQLite/Postgres funktioniert. (Audit M5) Ohne Faelligkeit ans Ende.
        if ($tab === 'done') {
            $tasks = $query->orderByDesc('completed_at')->paginate(30)->withQueryString();
        } else {
            $tasks = $query->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_date')
                ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->paginate(30)->withQueryString();
        }

        $counts = [
            'mine' => Task::where('assigned_to', $user->id)->open()->count(),
            'customer' => Task::whereNotNull('customer_id')->open()
                ->when($vids !== null, fn ($qq) => $qq->whereIn('customer_id', $vids))->count(),
            'overdue' => Task::where('assigned_to', $user->id)->open()
                ->whereDate('due_date', '<', today())->count(),
        ];

        // Vorbelegter Kunde (z. B. Button "Aufgabe" in der Kundenakte).
        $preselected = null;
        if ($request->filled('customer_id') && $user->canAccessCustomer($request->get('customer_id'))) {
            $c = Customer::with('user')->find($request->get('customer_id'));
            if ($c) $preselected = $this->customerPayload($c);
        }

        // Kunden-Chip fuer aktiven customer-Filter (Deep-Link aus Kundenakte).
        // Nur im eigenen Portfolio-Scope, sonst leakt ?customer=<uuid> den
        // Namen eines fremden Kunden (Audit SEC-P2).
        $filterCustomer = ($request->filled('customer') && $user->canAccessCustomer($request->get('customer')))
            ? Customer::with('user')->find($request->get('customer')) : null;

        return view('admin.tasks', [
            'tasks' => $tasks,
            'tab' => $tab,
            'counts' => $counts,
            'staff' => User::whereIn('role', ['admin', 'manager', 'support', 'employee'])
                ->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'templates' => MessageTemplate::where('category', 'kunde')
                ->orderBy('sort')->orderBy('name')->get(['id', 'name', 'subject', 'body']),
            'placeholders' => MessageTemplate::PLACEHOLDERS,
            'preselected' => $preselected,
            'filterCustomer' => $filterCustomer,
            'canAutoEmail' => $this->mayScheduleEmails($user),
            'openModal' => $request->boolean('neu') || $request->filled('customer_id'),
        ]);
    }

    /**
     * Sofort-Suche fuer die Kundenauswahl im Aufgaben-Formular - immer im
     * eigenen Portfolio-Scope. Ohne Suchbegriff die zuletzt angelegten Kunden.
     */
    public function customerSearch(Request $request) {
        $q = trim((string) $request->query('q', ''));
        $ids = $this->visibleCustomerIds();

        $base = Customer::with(['user', 'betreuer'])
            ->when($ids !== null, fn ($query) => $query->whereIn('customers.id', $ids));
        $customers = $q === ''
            ? $base->latest()->take(8)->get()
            : $base->search($q)->take(8)->get();

        return response()->json([
            'customers' => $customers->map(fn (Customer $c) => $this->customerPayload($c))->values(),
        ]);
    }

    public function store(Request $request) {
        $data = $this->validateTask($request);
        $auto = $this->autoEmailPayload($request);

        Task::create([
            'assigned_to' => $data['assigned_to'],
            'created_by' => auth()->id(),
            'customer_id' => $data['customer_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'other',
            'status' => 'open',
            'priority' => $data['priority'] ?? 'medium',
            'due_date' => $data['due_date'] ?? null,
        ] + $auto);

        $msg = 'Aufgabe erstellt.';
        if (($auto['auto_email_status'] ?? null) === 'pending') {
            $msg .= ' Die E-Mail an den Kunden wird am '
                .Carbon::parse($auto['auto_email_send_on'])->format('d.m.Y')
                .' automatisch gesendet.';
        }
        return back()->with('success', $msg);
    }

    /**
     * Darf der angemeldete Nutzer diese Aufgabe bearbeiten/loeschen?
     * Verwaltung (admin/manager) immer; sonst nur eigene Aufgaben
     * (zugewiesen oder selbst erstellt) bzw. Aufgaben zu einem Kunden im
     * eigenen Portfolio - deckungsgleich mit der Sichtbarkeit in index().
     */
    private function authorizeTask(Task $task): void {
        $user = auth()->user();
        if (in_array($user->role, ['admin', 'manager'], true)) return;
        $own = $task->assigned_to === $user->id || $task->created_by === $user->id;
        $portfolio = $task->customer_id && $user->canAccessCustomer($task->customer_id);
        abort_unless($own || $portfolio, 403);
    }

    public function update(Request $request, $id) {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);

        // 1) Schnell-Verschieben aus der Liste (+1 Tag ... +1 Monat).
        if ($request->filled('postpone_days')) {
            $request->validate(['postpone_days' => 'required|integer|in:1,3,7,14,30']);
            $base = $task->due_date && $task->due_date->gt(today()) ? $task->due_date : today();
            $task->due_date = $base->copy()->addDays((int) $request->postpone_days);
            $task->save();
            return back()->with('success', 'Aufgabe verschoben auf '.$task->due_date->format('d.m.Y').'.');
        }

        // 2) Schnell-Statuswechsel (Dropdown in der Liste) - unveraendertes Verhalten.
        if (! $request->boolean('edit')) {
            $request->validate(['status' => 'required|in:open,in_progress,done']);
            $task->update(['status' => $request->status]);
            return back()->with('success', 'Status aktualisiert.');
        }

        // 3) Voll-Bearbeitung ueber das Modal.
        $data = $this->validateTask($request);
        $auto = $this->autoEmailPayload($request, $task);

        $task->fill([
            'assigned_to' => $data['assigned_to'],
            'customer_id' => $data['customer_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? $task->type,
            'priority' => $data['priority'] ?? $task->priority,
            'due_date' => $data['due_date'] ?? null,
        ] + $auto)->save();

        return back()->with('success', 'Aufgabe aktualisiert.');
    }

    public function destroy($id) {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $task->delete();
        return back()->with('success', 'Aufgabe gelöscht.');
    }

    /** Gemeinsame Validierung fuer Anlegen + Voll-Bearbeitung. */
    private function validateTask(Request $request): array {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'type' => ['nullable', Rule::in(array_keys(Task::TYPES))],
            'priority' => ['nullable', Rule::in(array_keys(Task::PRIORITIES))],
            'due_date' => 'nullable|date',
            'assigned_to' => ['required', Rule::exists('users', 'id')
                ->where(fn ($q) => $q->whereIn('role', ['admin', 'manager', 'support', 'employee']))],
            'customer_id' => 'nullable|uuid|exists:customers,id',
        ], [], ['title' => 'Titel', 'assigned_to' => 'Zuweisung', 'due_date' => 'Fälligkeitsdatum']);

        if (! empty($data['customer_id'])) {
            abort_unless(auth()->user()->canAccessCustomer($data['customer_id']), 403);
        }
        return $data;
    }

    /** Gleiche Berechtigung wie der E-Mail-Composer (Rechte-Flag fuer Mitarbeiter). */
    private function mayScheduleEmails(User $user): bool {
        return in_array($user->role, ['admin', 'manager', 'support'], true) || $user->can_send_emails;
    }

    /**
     * Geplante Auto-E-Mail aus dem Formular uebernehmen. Regeln:
     *  - Aktivieren erfordert die Composer-Berechtigung, einen Kunden mit
     *    ECHTER E-Mail-Adresse sowie Betreff/Text/Stichtag (nicht in der
     *    Vergangenheit).
     *  - Bereits GESENDETE Mails bleiben unveraendert stehen (Historie);
     *    Abschalten setzt nur einen noch offenen Versand zurueck.
     */
    private function autoEmailPayload(Request $request, ?Task $existing = null): array {
        if ($existing && $existing->auto_email_status === 'sent') {
            return [];
        }

        if (! $request->boolean('auto_email')) {
            return [
                'auto_email_status' => null, 'auto_email_subject' => null,
                'auto_email_body' => null, 'auto_email_send_on' => null,
                'auto_email_error' => null,
            ];
        }

        abort_unless($this->mayScheduleEmails(auth()->user()), 403, 'Keine Berechtigung zum E-Mail-Versand.');

        $data = $request->validate([
            'customer_id' => 'required|uuid|exists:customers,id',
            'auto_email_subject' => 'required|string|max:200',
            'auto_email_body' => 'required|string|max:10000',
            'auto_email_send_on' => 'required|date|after_or_equal:today',
        ], [
            'customer_id.required' => 'Für die automatische E-Mail muss ein Kunde ausgewählt sein.',
            'auto_email_send_on.after_or_equal' => 'Der Sendetermin darf nicht in der Vergangenheit liegen.',
        ], [
            'auto_email_subject' => 'Betreff', 'auto_email_body' => 'E-Mail-Text',
            'auto_email_send_on' => 'Sendetermin',
        ]);

        $customer = Customer::with('user')->findOrFail($data['customer_id']);
        if (! $customer->user?->hasRealEmail()) {
            throw ValidationException::withMessages([
                'auto_email' => 'Der Kunde hat keine echte E-Mail-Adresse - automatischer Versand nicht möglich.',
            ]);
        }

        return [
            'auto_email_status' => 'pending',
            'auto_email_subject' => $data['auto_email_subject'],
            'auto_email_body' => $data['auto_email_body'],
            'auto_email_send_on' => $data['auto_email_send_on'],
            'auto_email_error' => null,
        ];
    }

    /** Einheitliches Kunden-JSON fuer Suche + Vorauswahl. */
    private function customerPayload(Customer $c): array {
        return [
            'id' => (string) $c->id,
            'name' => $c->user?->name ?? '—',
            'number' => $c->customer_number,
            'company' => $c->company_name,
            'email' => $c->user?->hasRealEmail() ? $c->user->email : null,
            'betreuer' => $c->relationLoaded('betreuer') ? $c->betreuer->pluck('name')->implode(', ') : '',
            'last_contact' => $c->last_contact
                ? Carbon::parse($c->last_contact)->format('d.m.Y') : null,
        ];
    }
}
