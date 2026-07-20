<?php
namespace App\Http\Controllers;
use App\Http\Controllers\Concerns\ScopesCustomerAccess;

use App\Mail\DirectEmailMail;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerTimeline;
use App\Models\MessageTemplate;
use App\Models\Ticket;
use App\Services\EmailDraftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Smart-E-Mail-Composer der Beraterwelt: Kundensuche mit Sofort-Vorschlaegen
 * und Favoriten, Kundenkarte mit Interaktionsverlauf, Vorlagen mit Suche,
 * optionaler KI-Entwurf - Versand an Kunden oder Gesellschaften.
 * Versand mit Kundenbezug wird in der Kunden-Historie protokolliert.
 *
 * Berechtigung: admin/manager/support immer; employee nur mit dem
 * bestehenden Rechte-Flag can_send_emails. Kundendaten immer zusaetzlich
 * durch den Portfolio-Check (canAccessCustomer) geschuetzt.
 */
class ComposeEmailController extends Controller
{
    use ScopesCustomerAccess;

    private function authorizeCompose(): void
    {
        $user = auth()->user();
        abort_unless(
            in_array($user->role, ['admin', 'manager', 'support'], true) || $user->can_send_emails,
            403,
            'Keine Berechtigung zum E-Mail-Versand.'
        );
    }

    public function create(Request $request, EmailDraftService $draftService)
    {
        $this->authorizeCompose();

        $customer = null;
        if ($request->filled('customer_id')) {
            abort_unless(auth()->user()->canAccessCustomer($request->customer_id), 403);
            $customer = Customer::with('user')->findOrFail($request->customer_id);
        }

        return view('admin.compose_email', [
            'customer' => $customer,
            'templates' => MessageTemplate::orderBy('category')->orderBy('sort')->orderBy('name')
                ->get(['id', 'name', 'category', 'subject']),
            'placeholders' => MessageTemplate::PLACEHOLDERS,
            'aiAvailable' => $draftService->isAvailable(),
        ]);
    }

    /**
     * Sofort-Suche fuer die Kundenauswahl: Name, E-Mail, Kundennummer oder
     * Firma - immer im eigenen Portfolio-Scope. Ohne Suchbegriff kommen
     * Favoriten zuerst, danach die zuletzt angelegten Kunden.
     */
    public function customerSearch(Request $request)
    {
        $this->authorizeCompose();
        $q = trim((string) $request->query('q', ''));
        $ids = $this->visibleCustomerIds();
        $favoriteIds = auth()->user()->favoriteCustomers()->pluck('customers.id')->map(fn($id) => (string) $id);

        $base = Customer::with(['user', 'betreuer'])
            ->when($ids !== null, fn($query) => $query->whereIn('customers.id', $ids));

        if ($q === '') {
            $favorites = (clone $base)->whereIn('customers.id', $favoriteIds)->get();
            $recent = (clone $base)->whereNotIn('customers.id', $favoriteIds)->latest()->take(5)->get();
            $customers = $favorites->concat($recent);
        } else {
            // Suche ueber ALLE Kundenfelder (Name, E-Mail, Nummer, Anschrift,
            // Kennzeichen, Zaehlernummer ...) statt nur Name/E-Mail/Nummer.
            $customers = $base->search($q)->take(8)->get();
        }

        return response()->json([
            'customers' => $customers->map(fn(Customer $c) => [
                'id' => (string) $c->id,
                'name' => $c->user?->name ?? '—',
                'email' => $c->user?->hasRealEmail() ? $c->user->email : null,
                'company' => $c->company_name,
                'number' => $c->customer_number,
                'lang' => strtoupper((string) $c->preferred_lang ?: 'DE'),
                'last_contact' => $c->last_contact
                    ? \Carbon\Carbon::parse($c->last_contact)->format('d.m.Y') : null,
                'betreuer' => $c->betreuer->pluck('name')->implode(', '),
                'favorite' => $favoriteIds->contains((string) $c->id),
            ])->values(),
        ]);
    }

    /** Stern setzen/entfernen - Favoriten stehen in der Suche ganz oben. */
    public function toggleFavorite($customerId)
    {
        $this->authorizeCompose();
        abort_unless(auth()->user()->canAccessCustomer($customerId), 403);
        Customer::findOrFail($customerId);

        $favorites = auth()->user()->favoriteCustomers();
        if ($favorites->where('customers.id', $customerId)->exists()) {
            $favorites->detach($customerId);
            return response()->json(['favorite' => false]);
        }
        $favorites->attach($customerId);
        return response()->json(['favorite' => true]);
    }

    /**
     * Kundenkarte + Kontext nach der Auswahl: Anreden (formell/locker),
     * Stammdaten und die letzten Interaktionen (Nachrichten, Anfragen,
     * Historie) - damit der Mitarbeiter den Zusammenhang sofort sieht.
     */
    public function customerContext($customerId)
    {
        $this->authorizeCompose();
        abort_unless(auth()->user()->canAccessCustomer($customerId), 403);
        $customer = Customer::with(['user', 'betreuer'])->findOrFail($customerId);

        $name = trim((string) ($customer->user?->name ?? ''));
        $vorname = $name !== '' ? preg_split('/\s+/', $name)[0] : '';

        $history = collect();
        foreach (CustomerMessage::where('customer_id', $customerId)->latest()->take(5)->get() as $m) {
            $history->push([
                'icon' => $m->from_staff ? '📨' : '💬',
                'text' => ($m->from_staff ? 'Nachricht gesendet: ' : 'Kunde schrieb: ')
                    . \Illuminate\Support\Str::limit(trim($m->body), 55),
                'at' => $m->created_at,
            ]);
        }
        foreach (Ticket::where('customer_id', $customerId)->latest()->take(4)->get() as $t) {
            $history->push([
                'icon' => '🎫',
                'text' => $t->subject . ' (' . ($t->status === 'closed' ? 'geschlossen' : 'offen') . ')',
                'at' => $t->created_at,
            ]);
        }
        foreach (CustomerTimeline::where('customer_id', $customerId)->latest()->take(5)->get() as $e) {
            $history->push(['icon' => '✓', 'text' => $e->title, 'at' => $e->created_at]);
        }

        return response()->json([
            'id' => (string) $customer->id,
            'name' => $name !== '' ? $name : '—',
            'email' => $customer->user?->hasRealEmail() ? $customer->user->email : null,
            'company' => $customer->company_name,
            'number' => $customer->customer_number,
            'lang' => strtoupper((string) $customer->preferred_lang ?: 'DE'),
            'phone' => $customer->phone ?: $customer->mobile,
            'betreuer' => $customer->betreuer->pluck('name')->implode(', '),
            'last_contact' => $customer->last_contact
                ? \Carbon\Carbon::parse($customer->last_contact)->format('d.m.Y') : null,
            'favorite' => auth()->user()->favoriteCustomers()->where('customers.id', $customerId)->exists(),
            'salutations' => [
                'formell' => $customer->salutationLine() . ',',
                'locker' => $vorname !== '' ? 'Hallo ' . $vorname . ',' : 'Hallo,',
            ],
            'history' => $history->sortByDesc('at')->take(6)->values()
                ->map(fn($h) => ['icon' => $h['icon'], 'text' => $h['text'], 'date' => $h['at']->format('d.m.Y')]),
        ]);
    }

    /**
     * "✨ KI-Entwurf": erzeugt auf expliziten Klick einen kompletten
     * E-Mail-Vorschlag aus Kundenkontext + Anliegen. Der Mitarbeiter
     * prueft und sendet selbst - nie automatisch.
     */
    public function aiDraft(Request $request, EmailDraftService $draftService)
    {
        $this->authorizeCompose();
        $request->validate([
            'goal' => 'required|string|max:1000',
            'customer_id' => 'nullable|string',
            'category' => 'nullable|in:' . implode(',', array_keys(MessageTemplate::CATEGORIES)),
        ]);
        if (!$draftService->isAvailable()) {
            return response()->json(['message' => 'Kein KI-Anbieter konfiguriert (ANTHROPIC_API_KEY fehlt).'], 422);
        }

        $customer = null;
        $history = [];
        if ($request->filled('customer_id')) {
            abort_unless(auth()->user()->canAccessCustomer($request->customer_id), 403);
            $customer = Customer::with('user')->findOrFail($request->customer_id);
            $history = CustomerMessage::where('customer_id', $customer->id)->latest()->take(4)->get()
                ->map(fn($m) => $m->created_at->format('d.m.Y') . ' '
                    . ($m->from_staff ? 'Wir' : 'Kunde') . ': ' . \Illuminate\Support\Str::limit(trim($m->body), 90))
                ->all();
        }

        try {
            $draft = $draftService->draft(
                $customer,
                auth()->user(),
                $request->goal,
                $request->input('category', 'kunde'),
                $history,
            );
        } catch (\Throwable $e) {
            \Log::warning('KI-Entwurf fehlgeschlagen: ' . $e->getMessage());
            return response()->json(['message' => 'KI-Entwurf derzeit nicht verfügbar. Bitte später erneut versuchen.'], 502);
        }

        return response()->json($draft);
    }

    public function send(Request $request)
    {
        $this->authorizeCompose();
        $request->validate([
            'to' => 'required|email|max:190',
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:10000',
            'customer_id' => 'nullable|string',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp,doc,docx|max:10240',
        ]);

        $customer = null;
        if ($request->filled('customer_id')) {
            abort_unless(auth()->user()->canAccessCustomer($request->customer_id), 403);
            $customer = Customer::with('user')->findOrFail($request->customer_id);
        }

        $files = [];
        foreach ($request->file('attachments', []) as $file) {
            $files[] = [
                'data' => $file->get(),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType() ?: 'application/octet-stream',
            ];
        }

        try {
            Mail::to($request->to)->send(new DirectEmailMail(
                mailSubject: $request->subject,
                mailBody: $request->body,
                customer: $customer,
                fileAttachments: $files,
                senderName: (string) auth()->user()->name,
            ));
        } catch (\Throwable $e) {
            \Log::warning('Direkt-E-Mail fehlgeschlagen: ' . $e->getMessage());
            return back()->withInput()->with('error', 'E-Mail konnte nicht gesendet werden: ' . $e->getMessage());
        }

        // Nachvollziehbarkeit: Versand in der Kundenakte protokollieren.
        if ($customer) {
            CustomerTimeline::create([
                'customer_id' => $customer->id,
                'user_id' => auth()->id(),
                'type' => 'email',
                'title' => 'E-Mail gesendet: ' . $request->subject,
                'description' => 'An ' . $request->to
                    . ($files !== [] ? ' · ' . count($files) . ' Anhang/Anhänge' : ''),
            ]);
            $customer->update(['last_contact' => now()->toDateString()]);
        }

        return redirect(route('admin.email.compose') . ($customer ? '?customer_id=' . $customer->id : ''))
            ->with('success', 'E-Mail an ' . $request->to . ' gesendet.');
    }
}
