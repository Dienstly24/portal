<?php
namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ticket-Workflow der Beraterwelt: Liste mit Filtern/Kennzahlen, Detailseite
 * mit Statuswechsel, Zuweisung, Prioritaet/Typ, internen Notizen, Verlauf
 * und Kundenantwort (inkl. E-Mail an Gaeste ohne Portalzugang).
 */
class TicketController extends Controller
{
    /** null = alle sichtbar; sonst Array der erlaubten Kunden-IDs */
    private function visibleCustomerIds(): ?array {
        $user = auth()->user();
        if (!$user || $user->canSeeAllCustomers()) return null;
        return $user->visibleCustomerIdsWithSubstitution();
    }

    /** 403, wenn das Ticket zu einem nicht sichtbaren Kunden gehoert. (Audit M1) */
    private function authorizeTicketAccess(Ticket $ticket): void {
        $ids = $this->visibleCustomerIds();
        if ($ticket->customer_id !== null && $ids !== null
            && !in_array((string) $ticket->customer_id, array_map('strval', $ids), true)) {
            abort(403, 'Kein Zugriff auf diesen Kunden.');
        }
    }

    /**
     * Schreibende Ticket-Aktionen: admin/manager/support immer; Mitarbeiter
     * nur mit der Berechtigung "Tickets bearbeiten" (can_manage_tickets) -
     * die Checkbox in der Mitarbeiterverwaltung greift damit wirklich.
     */
    private function authorizeTicketManage(): void {
        $user = auth()->user();
        if ($user && $user->role === 'employee' && !$user->can_manage_tickets) {
            abort(403, 'Keine Berechtigung, Tickets zu bearbeiten.');
        }
    }

    public function index(Request $request) {
        $ids = $this->visibleCustomerIds();
        // Alle Anfragen MIT Kundenakte - unabhaengig von der Quelle (Portal,
        // Hilfe-Formular, ...), damit keine Anfrage unsichtbar bleibt.
        $base = Ticket::whereNotNull('customer_id')
            ->when($ids !== null, fn($q) => $q->whereIn('customer_id', $ids));

        // Kennzahlen fuer Karten + Status-Tabs (vor den Filtern gezaehlt)
        $stats = [
            'statuses' => (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'overdue' => (clone $base)->whereNotIn('status', ['resolved', 'closed'])
                ->whereNull('first_response_at')->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'unassigned' => (clone $base)->whereNotIn('status', ['resolved', 'closed'])->whereNull('assigned_to')->count(),
        ];

        $query = (clone $base)->with(['customer.user', 'assignedTo']);
        if ($request->filled('status') && $request->status !== 'alle') {
            $query->where('status', $request->status);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('assigned')) {
            match ($request->assigned) {
                'none' => $query->whereNull('assigned_to'),
                'me' => $query->where('assigned_to', auth()->id()),
                default => $query->where('assigned_to', $request->assigned),
            };
        }
        if ($q = trim((string) $request->q)) {
            $query->where(function ($w) use ($q) {
                $w->where('subject', 'like', "%{$q}%")
                  ->orWhere('ticket_number', 'like', "%{$q}%")
                  ->orWhereHas('customer.user', fn($u) => $u->where('name', 'like', "%{$q}%"))
                  ->orWhereHas('customer', fn($c) => $c->where('customer_number', 'like', "%{$q}%"));
            });
        }

        $tickets = $query->orderByDesc('updated_at')->paginate(25)->withQueryString();
        $staff = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])->orderBy('name')->get(['id', 'name']);
        return view('admin.tickets', compact('tickets', 'stats', 'staff'));
    }

    public function show($id) {
        $ticket = Ticket::with(['customer.user', 'assignedTo', 'closedBy', 'messages.sender', 'events.user', 'attachments'])->findOrFail($id);
        $this->authorizeTicketAccess($ticket);
        $staff = User::whereIn('role', ['admin', 'manager', 'support', 'employee'])->orderBy('name')->get(['id', 'name']);
        return view('admin.ticket_show', compact('ticket', 'staff'));
    }

    /** Statuswechsel ueber die Schnellaktionen der Detailseite. */
    public function status(Request $request, $id) {
        $request->validate(['status' => 'required|in:' . implode(',', array_keys(Ticket::STATUSES))]);
        $ticket = Ticket::findOrFail($id);
        $this->authorizeTicketAccess($ticket);
        $this->authorizeTicketManage();

        // "In Bearbeitung uebernehmen": unzugewiesenes Ticket gehoert danach dem Bearbeiter
        if ($request->status === 'in_progress' && !$ticket->assigned_to) {
            $ticket->update(['assigned_to' => auth()->id()]);
            $ticket->logEvent('assigned', 'an ' . auth()->user()->name);
        }
        $ticket->transitionTo($request->status, auth()->id());
        TicketNotifier::notifyCustomerStatus($ticket);
        return back()->with('success', 'Status aktualisiert: ' . $ticket->statusLabel());
    }

    /** Zuweisung, Prioritaet und Typ aendern (Karte "Eigenschaften"). */
    public function updateMeta(Request $request, $id) {
        $request->validate([
            'assigned_to' => 'sometimes|nullable|exists:users,id',
            'priority' => 'sometimes|in:' . implode(',', array_keys(Ticket::PRIORITIES)),
            'type' => 'sometimes|in:' . implode(',', array_keys(Ticket::TYPES)),
        ]);
        $ticket = Ticket::findOrFail($id);
        $this->authorizeTicketAccess($ticket);
        $this->authorizeTicketManage();

        if ($request->has('assigned_to')) {
            $newId = $request->assigned_to ?: null;
            if ($newId && !User::find($newId)->isStaff()) {
                return back()->with('error', 'Tickets koennen nur Mitarbeitern zugewiesen werden.');
            }
            if ((string) $newId !== (string) $ticket->assigned_to) {
                $ticket->update(['assigned_to' => $newId]);
                if ($newId) {
                    $assignee = User::find($newId);
                    $ticket->logEvent('assigned', 'an ' . $assignee->name);
                    TicketNotifier::notifyAssigned($ticket, $assignee);
                } else {
                    $ticket->logEvent('unassigned');
                }
            }
        }
        if ($request->filled('priority') && $request->priority !== $ticket->priority) {
            $old = $ticket->priorityLabel();
            $ticket->update([
                'priority' => $request->priority,
                // SLA-Faelligkeit haengt an der Prioritaet -> neu berechnen
                'due_at' => $ticket->created_at->copy()->addHours(Ticket::slaHours($request->priority)),
            ]);
            $ticket->logEvent('priority_changed', $old . ' → ' . $ticket->priorityLabel());
        }
        if ($request->filled('type') && $request->type !== $ticket->type) {
            $old = $ticket->typeLabel();
            $ticket->update(['type' => $request->type]);
            $ticket->logEvent('type_changed', $old . ' → ' . $ticket->typeLabel());
        }
        return back()->with('success', 'Ticket aktualisiert.');
    }

    /** Interne Notiz: nur fuer Staff sichtbar, nie im Kundenportal. */
    public function note(Request $request, $id) {
        $request->validate(['body' => 'required|string|max:5000']);
        $ticket = Ticket::findOrFail($id);
        $this->authorizeTicketAccess($ticket);
        $this->authorizeTicketManage();
        TicketMessage::create([
            'id' => Str::uuid(),
            'ticket_id' => $ticket->id,
            'sender_id' => auth()->id(),
            'body' => $request->body,
            'is_internal' => true,
        ]);
        $ticket->logEvent('note_added');
        $ticket->touch();
        return back()->with('success', 'Interne Notiz gespeichert.');
    }

    public function reply(Request $request, $id) {
        $request->validate([
            'body' => 'required',
            'status' => 'required|in:' . implode(',', array_keys(Ticket::STATUSES)),
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);
        $ticket = Ticket::findOrFail($id);
        $this->authorizeTicketAccess($ticket);
        $this->authorizeTicketManage();
        TicketMessage::create([
            'id' => Str::uuid(),
            'ticket_id' => $ticket->id,
            'sender_id' => auth()->id(),
            'body' => $request->body,
            // Ticketantworten sind IMMER Kundenkommunikation. Interne
            // Absprachen laufen ueber interne Notizen bzw. den internen
            // Chat - dadurch ist es strukturell unmoeglich, versehentlich
            // Internes an Kunden zu senden. (Spec Teil 8)
            'is_internal' => false,
        ]);
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                // Punkt 5: sicher auf privater Disk speichern
                $path = $file->store('tickets/' . $ticket->id, 'local');
                TicketAttachment::create([
                    'id' => Str::uuid(),
                    'ticket_id' => $ticket->id,
                    'uploaded_by' => auth()->id(),
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'disk' => 'local',
                ]);
            }
        }

        // SLA: Zeitpunkt der ersten Team-Antwort festhalten
        if (!$ticket->first_response_at) {
            $ticket->update(['first_response_at' => now()]);
        }
        $ticket->logEvent('staff_reply');
        $ticket->transitionTo($request->status, auth()->id());
        $ticket->touch();

        $ticket->load('customer.user');
        if ($ticket->customer) {
            // Portal-Glocke: "Neue Nachricht" fuer den Kunden (Review Punkt 10)
            if ($ticket->customer->user_id) {
                \App\Models\InternalNotification::create([
                    'user_id' => $ticket->customer->user_id,
                    'title' => 'Neue Nachricht',
                    'body' => 'Unser Team hat auf Ihre Anfrage „' . Str::limit($ticket->subject, 60) . '" geantwortet.',
                    'link' => route('portal.tickets.show', $ticket->id),
                ]);
            }
            $email = $ticket->customer->user?->email;
            if ($email && !str_contains($email, '@dienstly24.internal')) {
                try {
                    // Mail enthaelt bewusst KEINE Nachrichtendetails (Punkt 10)
                    \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\TicketReplyMail($ticket, $request->body));
                } catch (\Throwable $e) { \Log::warning('Ticket reply mail failed: ' . $e->getMessage()); }
            }
        } elseif ($ticket->guest_email) {
            // Gaeste haben keinen Portalzugang -> Antwort direkt per E-Mail
            try {
                \Illuminate\Support\Facades\Mail::to($ticket->guest_email)->send(new \App\Mail\GuestTicketReplyMail($ticket, $request->body));
            } catch (\Throwable $e) { \Log::warning('Guest ticket reply mail failed: ' . $e->getMessage()); }
        }
        return back()->with('success', 'Antwort gesendet.');
    }
}
