<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesCustomerAccess;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /** Die drei Reiter. Ein unbekannter Wert (?tab=alle) fiel frueher durch
     *  jede Bedingung und zeigte ALLE Aufgaben - auch fremde. */
    private const TABS = ['mine', 'customer', 'done'];

    private function tab(Request $request): string {
        $tab = (string) $request->get('tab', 'mine');
        return in_array($tab, self::TABS, true) ? $tab : 'mine';
    }

    public function index(Request $request) {
        $user = auth()->user();
        $tab = $this->tab($request);
        $vids = $this->visibleCustomerIds();

        $query = $this->filterQuery($request, $user)
            ->with(['assignedTo', 'customer.user', 'createdBy', 'emailMessage']);

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
     * Die Filter der Liste (Tab, Status, Typ, Faelligkeit, Suche, Kunde) als
     * EINE Abfrage - die Liste UND die Sammelaktion "alle Treffer des
     * Filters" benutzen dieselbe. Zwei Fassungen koennten auseinanderlaufen,
     * und dann trifft "alle 896 ueberfaelligen erledigen" andere Aufgaben,
     * als die Liste gezeigt hat.
     *
     * @return Builder<Task>
     */
    private function filterQuery(Request $request, User $user) {
        $tab = $this->tab($request);
        $status = $request->get('status', '');
        $type = $request->get('type', '');
        $due = $request->get('due', '');
        $q = trim((string) $request->get('q', ''));
        $vids = $this->visibleCustomerIds();
        $seesAll = in_array($user->role, ['admin', 'manager'], true);

        $query = Task::query();

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

        return $query;
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

    /** Obergrenze je Sammelaktion - schuetzt vor einem versehentlichen Lauf ueber den Gesamtbestand. */
    public const SAMMEL_MAX = 5000;

    /** Ab dieser Anzahl verlangt die Oberflaeche eine ausdrueckliche Bestaetigung. */
    public const SAMMEL_BESTAETIGEN_AB = 50;

    /** Wie lange "Rueckgaengig" moeglich ist. */
    public const RUECKGAENGIG_MINUTEN = 15;

    /**
     * Sammelaktion ueber die AUSGEWAEHLTEN Aufgaben oder ALLE Treffer des
     * aktuellen Filters (Betreiber-Auftrag 09.10.2026: 929 Aufgaben, davon
     * 896 ueberfaellig, liessen sich nur einzeln bearbeiten).
     *
     * EIN Request, Aenderungen in wenigen Abfragen. Berechtigung wie beim
     * Einzelzugriff (authorizeTask): Verwaltung alles, sonst nur eigene
     * Aufgaben bzw. Aufgaben zu Kunden im eigenen Portfolio - als
     * Bedingung IN der Abfrage, nicht als Nachpruefung je Zeile. Eine
     * fremde ID im Formular wird damit nicht abgelehnt, sondern schlicht
     * nicht getroffen.
     */
    public function bulk(Request $request) {
        $user = auth()->user();
        $data = $request->validate([
            'aktion' => 'required|in:erledigt,status,verschieben,zuweisen,loeschen',
            'auswahl' => 'required|in:ids,filter',
            'ids' => 'required_if:auswahl,ids|array|max:'.self::SAMMEL_MAX,
            'ids.*' => 'uuid',
            // "neuer_status", nicht "status": status ist ein FILTER der Liste
            // und reist bei "alle Treffer" im selben Formular mit.
            'neuer_status' => 'required_if:aktion,status|nullable|in:'.implode(',', array_keys(Task::STATUSES)),
            'tage' => 'nullable|integer|in:1,3,7,14,30',
            'datum' => 'nullable|date|after_or_equal:today',
            'assigned_to' => ['required_if:aktion,zuweisen', 'nullable', Rule::exists('users', 'id')
                ->where(fn ($q) => $q->whereIn('role', ['admin', 'manager', 'support', 'employee'])->where('is_active', true))],
            'bestaetigt' => 'nullable|boolean',
        ], [
            'ids.required_if' => 'Bitte mindestens eine Aufgabe auswählen.',
            'neuer_status.required_if' => 'Bitte den neuen Status wählen.',
            'assigned_to.required_if' => 'Bitte einen Mitarbeiter für die Zuweisung wählen.',
            'assigned_to.exists' => 'Dieser Mitarbeiter ist nicht (mehr) aktiv.',
            'datum.after_or_equal' => 'Das neue Fälligkeitsdatum darf nicht in der Vergangenheit liegen.',
        ]);
        if ($data['aktion'] === 'verschieben' && empty($data['tage']) && empty($data['datum'])) {
            throw ValidationException::withMessages(['tage' => 'Bitte angeben, um wie viele Tage oder auf welches Datum verschoben wird.']);
        }

        $query = $data['auswahl'] === 'filter'
            ? $this->filterQuery($request, $user)
            : Task::whereIn('id', $data['ids'] ?? []);
        $this->berechtigt($query, $user);

        $ids = $query->limit(self::SAMMEL_MAX + 1)->pluck('id')->all();
        if ($ids === []) {
            return back()->with('error', 'Keine Aufgabe getroffen – die Auswahl ist leer oder Sie dürfen diese Aufgaben nicht bearbeiten.');
        }
        if (count($ids) > self::SAMMEL_MAX) {
            return back()->with('error', 'Zu viele Aufgaben auf einmal (mehr als '.self::SAMMEL_MAX.'). Bitte den Filter enger fassen.');
        }
        $braucht = $data['aktion'] === 'loeschen' || count($ids) >= self::SAMMEL_BESTAETIGEN_AB;
        if ($braucht && ! $request->boolean('bestaetigt')) {
            return back()->with('error', 'Bitte die Sammelaktion über '.count($ids).' Aufgabe(n) ausdrücklich bestätigen.');
        }

        $spalten = ['id', 'status', 'completed_at', 'due_date', 'assigned_to', 'auto_email_status', 'auto_email_error'];
        $vorher = [];
        foreach (array_chunk($ids, 500) as $teil) {
            $zeilen = DB::table('tasks')->whereIn('id', $teil)
                ->get($data['aktion'] === 'loeschen' ? ['*'] : $spalten);
            foreach ($zeilen as $z) $vorher[] = (array) $z;
        }

        $anzahl = count($ids);
        DB::transaction(function () use ($data, $ids) {
            foreach (array_chunk($ids, 500) as $teil) {
                $this->sammelAusfuehren($data, $teil);
            }
        });

        ActivityLog::record('tasks_bulk', 'task', null, [
            'aktion' => $data['aktion'],
            'anzahl' => $anzahl,
            'auswahl' => $data['auswahl'],
            'status' => $data['neuer_status'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
        ]);

        $token = (string) Str::uuid();
        Cache::put('aufgaben-rueckgaengig:'.$token, [
            'user_id' => $user->id,
            'aktion' => $data['aktion'],
            'zeilen' => $vorher,
        ], now()->addMinutes(self::RUECKGAENGIG_MINUTEN));

        $text = match ($data['aktion']) {
            'erledigt' => $anzahl.' Aufgabe(n) als erledigt markiert.',
            'status' => $anzahl.' Aufgabe(n) auf „'.Task::STATUSES[$data['neuer_status']].'“ gesetzt.',
            'verschieben' => $anzahl.' Aufgabe(n) verschoben'.(! empty($data['datum'])
                ? ' auf den '.Carbon::parse($data['datum'])->format('d.m.Y').'.' : ' um '.$data['tage'].' Tag(e).'),
            'zuweisen' => $anzahl.' Aufgabe(n) an '.(User::find($data['assigned_to'])?->name ?? 'Mitarbeiter').' zugewiesen.',
            'loeschen' => $anzahl.' Aufgabe(n) gelöscht.',
        };

        return back()->with('success', $text)->with('aufgaben_rueckgaengig', [
            'token' => $token,
            'minuten' => self::RUECKGAENGIG_MINUTEN,
        ]);
    }

    /**
     * Die eigentliche Aenderung je Block. Laeuft an Eloquent vorbei (eine
     * Abfrage statt 900) und bildet deshalb den saving-Hook von Task
     * AUSDRUECKLICH nach: erledigt => completed_at setzen und eine geplante
     * Auto-E-Mail ueberspringen; nicht erledigt => completed_at leeren.
     *
     * @param  array<string,mixed>  $data
     * @param  list<string>  $ids
     */
    private function sammelAusfuehren(array $data, array $ids): void {
        $tabelle = fn () => DB::table('tasks')->whereIn('id', $ids);
        $jetzt = now();
        $status = $data['aktion'] === 'erledigt' ? 'done' : ($data['neuer_status'] ?? null);

        switch ($data['aktion']) {
            case 'erledigt':
            case 'status':
                if ($status === 'done') {
                    $tabelle()->whereNull('completed_at')->update(['completed_at' => $jetzt]);
                    $tabelle()->where('auto_email_status', 'pending')->update([
                        'auto_email_status' => 'skipped',
                        'auto_email_error' => 'Aufgabe erledigt - geplanter Versand uebersprungen.',
                    ]);
                    $tabelle()->update(['status' => 'done', 'updated_at' => $jetzt]);
                } else {
                    $tabelle()->update(['status' => $status, 'completed_at' => null, 'updated_at' => $jetzt]);
                }
                return;
            case 'verschieben':
                if (! empty($data['datum'])) {
                    $tabelle()->update(['due_date' => Carbon::parse($data['datum'])->toDateString(), 'updated_at' => $jetzt]);
                    return;
                }
                // Wie das Einzel-Verschieben: Basis ist die spaetere von
                // Faelligkeit und heute. Ueberfaellige (der Normalfall bei
                // 896 Stueck) laufen in EINER Abfrage, nur Aufgaben mit
                // Faelligkeit in der Zukunft brauchen ihren eigenen Wert.
                // REIHENFOLGE: die kuenftigen ZUERST lesen - nach dem ersten
                // Update laegen die eben verschobenen ueberfaelligen selbst in
                // der Zukunft und wuerden ein zweites Mal verschoben.
                $tage = (int) $data['tage'];
                $heute = today();
                $kuenftig = $tabelle()->whereDate('due_date', '>', $heute)->get(['id', 'due_date']);
                $tabelle()->where(fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '<=', $heute))
                    ->update(['due_date' => $heute->copy()->addDays($tage)->toDateString(), 'updated_at' => $jetzt]);
                $kuenftig
                    ->groupBy(fn ($z) => substr((string) $z->due_date, 0, 10))
                    ->each(function ($gruppe, $faellig) use ($tage, $jetzt) {
                        DB::table('tasks')->whereIn('id', $gruppe->pluck('id'))->update([
                            'due_date' => Carbon::parse($faellig)->addDays($tage)->toDateString(),
                            'updated_at' => $jetzt,
                        ]);
                    });
                return;
            case 'zuweisen':
                $tabelle()->update(['assigned_to' => (int) $data['assigned_to'], 'updated_at' => $jetzt]);
                return;
            case 'loeschen':
                $tabelle()->delete();
                return;
        }
    }

    /**
     * Sammelaktion zuruecknehmen (15 Minuten, nur wer sie ausgeloest hat).
     * Zurueck geht nur, was es noch gibt bzw. was fehlt: eine geloeschte
     * Aufgabe wird mit ihrer alten ID wieder angelegt, eine geaenderte
     * bekommt ihre vorherigen Werte. Danach ist der Schluessel verbraucht.
     */
    public function bulkUndo(Request $request) {
        $request->validate(['token' => 'required|uuid']);
        $schluessel = 'aufgaben-rueckgaengig:'.$request->input('token');
        $stand = Cache::get($schluessel);
        if (! is_array($stand) || (int) $stand['user_id'] !== (int) auth()->id()) {
            return back()->with('error', 'Rückgängig ist nicht mehr möglich (abgelaufen oder bereits ausgeführt).');
        }
        Cache::forget($schluessel);

        $zeilen = $stand['zeilen'] ?? [];
        DB::transaction(function () use ($stand, $zeilen) {
            if ($stand['aktion'] === 'loeschen') {
                $vorhanden = [];
                foreach (array_chunk(array_column($zeilen, 'id'), 500) as $teil) {
                    $vorhanden = array_merge($vorhanden, DB::table('tasks')->whereIn('id', $teil)->pluck('id')->all());
                }
                $fehlend = array_values(array_filter($zeilen, fn ($z) => ! in_array($z['id'], $vorhanden, true)));
                foreach (array_chunk($fehlend, 200) as $teil) {
                    DB::table('tasks')->insert($teil);
                }
                return;
            }
            foreach ($zeilen as $z) {
                $id = $z['id'];
                unset($z['id']);
                DB::table('tasks')->where('id', $id)->update($z);
            }
        });

        ActivityLog::record('tasks_bulk_undo', 'task', null, ['aktion' => $stand['aktion'], 'anzahl' => count($zeilen)]);

        return back()->with('success', count($zeilen).' Aufgabe(n) wiederhergestellt.');
    }

    /** Dieselbe Grenze wie authorizeTask(), als Bedingung in der Abfrage. */
    private function berechtigt($query, User $user): void {
        if (in_array($user->role, ['admin', 'manager'], true)) {
            return;
        }
        $vids = $this->visibleCustomerIds();
        $query->where(function ($w) use ($user, $vids) {
            $w->where('assigned_to', $user->id)->orWhere('created_by', $user->id);
            if ($vids === null) {
                $w->orWhereNotNull('customer_id');
            } elseif ($vids !== []) {
                $w->orWhereIn('customer_id', $vids);
            }
        });
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
