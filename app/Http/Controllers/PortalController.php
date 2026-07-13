<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Contract;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Str;

class PortalController extends Controller
{
    private function getCustomer() {
        return Customer::firstOrCreate(
            ['user_id' => auth()->id()],
            ['customer_number' => 'C-' . strtoupper(Str::random(8))]
        );
    }

    public function dashboard() {
        $customer = $this->getCustomer();
        return view('portal.dashboard', [
            'customer' => $customer,
            'contractsCount' => Contract::where('customer_id', $customer->id)->where('status','active')->count(),
            'openTickets' => Ticket::where('customer_id', $customer->id)->whereIn('status',['open','in_progress'])->count(),
            'pendingApprovals' => \App\Models\CustomerChangeRequest::where('customer_id', $customer->id)->where('status','pending')->count(),
            'contracts' => Contract::where('customer_id', $customer->id)->latest()->take(3)->get(),
            'tickets' => Ticket::where('customer_id', $customer->id)->latest()->take(3)->get(),
            'completeness' => $customer->completeness(),
            'banners' => $this->bannersFor(auth()->id()),
        ]);
    }

    /**
     * Ausspielbare Banner für diesen Kunden: weggeklickte Banner bleiben
     * bis zum Ablauf der Schließen-Frist ausgeblendet; jede Ausspielung
     * wird für die Statistik gezählt (Impressions + eindeutige Betrachter).
     */
    private function bannersFor(string $userId)
    {
        $dismissed = \App\Models\BannerUserView::where('user_id', $userId)
            ->where('dismissed_until', '>', now())
            ->pluck('banner_id');

        $banners = \App\Models\Banner::current()
            ->whereNotIn('id', $dismissed)
            ->get();

        foreach ($banners as $banner) {
            $banner->recordImpression($userId);
        }

        return $banners;
    }

    /** Banner-Klick mit hinterlegtem Ziel: zählen, dann weiterleiten. */
    public function bannerClick($id) {
        $this->getCustomer();
        $banner = \App\Models\Banner::current()->findOrFail($id);
        $banner->recordClick(auth()->id());

        $url = $banner->link_url;
        // Interner Pfad oder externe URL – alles andere fällt aufs Dashboard zurück.
        if (!$url) {
            return redirect()->route('portal.dashboard');
        }
        return redirect()->away(str_starts_with($url, 'http') ? $url : url($url));
    }

    /** Banner schließen: für die konfigurierte Dauer nicht mehr anzeigen. */
    public function bannerDismiss($id) {
        $this->getCustomer();
        $banner = \App\Models\Banner::findOrFail($id);
        $days = max(1, (int) ($banner->dismiss_days ?? 7));

        \App\Models\BannerUserView::updateOrCreate(
            ['banner_id' => $banner->id, 'user_id' => auth()->id()],
            ['dismissed_until' => now()->addDays($days)]
        );

        return response()->json(['ok' => true]);
    }

    public function contracts() {
        $customer = $this->getCustomer();
        return view('portal.contracts', [
            'contracts' => Contract::where('customer_id', $customer->id)
                ->with(['vehicleDetail', 'energyDetail', 'internetDetail'])
                ->latest()->get()
        ]);
    }

    /** Detailseite eines eigenen Vertrags (Review Punkt 12). */
    public function contractShow($id) {
        $customer = $this->getCustomer();
        $contract = Contract::where('customer_id', $customer->id)
            ->with(['vehicleDetail', 'energyDetail', 'internetDetail'])
            ->where('id', $id)->firstOrFail();
        return view('portal.contract_show', ['contract' => $contract]);
    }

    public function tickets() {
        $customer = $this->getCustomer();
        return view('portal.tickets', [
            'tickets' => Ticket::where('customer_id', $customer->id)->latest()->get()
        ]);
    }

    public function ticketsCreate() {
        return view('portal.tickets_create');
    }

    public function ticketsStore(Request $request) {
        $request->validate([
            // in:-Regel gegen das MySQL-Enum - ohne sie wirft ein
            // manipulierter POST einen 500er statt Validierungsfehler
            'type' => 'required|in:' . implode(',', array_keys(Ticket::TYPES)),
            'subject' => 'required|max:255',
            'description' => 'required',
            'priority' => 'required|in:niedrig,mittel,hoch',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);
        $customer = $this->getCustomer();
        $ticket = Ticket::create([
            'id' => Str::uuid(),
            'customer_id' => $customer->id,
            'type' => $request->type,
            'priority' => $request->priority ?? 'mittel',
            'subject' => $request->subject,
            'description' => $request->description,
            'status' => 'open',
        ]);
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                // Punkt 5: sicher auf privater Disk speichern - Kunden-Uploads
                // (Versichertenkarten, Unfallfotos) duerfen NIE oeffentlich
                // unter /storage/... erreichbar sein.
                $path = $file->store('tickets/' . $ticket->id, 'local');
                \App\Models\TicketAttachment::create([
                    'id' => Str::uuid(),
                    'ticket_id' => $ticket->id,
                    'uploaded_by' => auth()->id(),
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'disk' => 'local',
                ]);
            }
        }
        // Team ueber die neue Anfrage informieren (Glocke).
        \App\Services\TicketNotifier::notifyNewTicket($ticket);
        return redirect()->route('portal.tickets')->with('success', __('Anfrage erfolgreich eingereicht.') . ' (' . $ticket->ticket_number . ')');
    }

    public function ticketsShow($id) {
        $customer = $this->getCustomer();
        $ticket = Ticket::where('id', $id)->where('customer_id', $customer->id)->firstOrFail();
        $messages = TicketMessage::where('ticket_id', $id)->where('is_internal', false)->with('sender')->get();
        return view('portal.tickets_show', compact('ticket', 'messages'));
    }

    public function downloadAttachment($id) {
        $customer = $this->getCustomer();
        $a = \App\Models\TicketAttachment::findOrFail($id);
        $ticket = Ticket::where('id', $a->ticket_id)->where('customer_id', $customer->id)->firstOrFail();
        if (($a->disk ?? 'public') === 'local') {
            return \Illuminate\Support\Facades\Storage::disk('local')->download($a->file_path, $a->file_name);
        }
        // 404 statt 500, wenn die Datei fehlt (z. B. manuell geloescht)
        abort_unless(is_file(storage_path('app/public/' . $a->file_path)), 404);
        return response()->download(storage_path('app/public/' . $a->file_path), $a->file_name);
    }

    public function ticketsReply(Request $request, $id) {
        // Punkt 5: Dateianhänge in der Unterhaltung (PDF/JPG/JPEG/PNG/WEBP)
        $request->validate([
            'body' => 'required',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);
        $customer = $this->getCustomer();
        $ticket = Ticket::where('id', $id)->where('customer_id', $customer->id)->firstOrFail();
        // Geschlossene Anfragen sind schreibgeschuetzt (bitte neue Anfrage stellen)
        if ($ticket->status === 'closed') {
            return back()->with('error', __('Diese Anfrage ist geschlossen. Bitte stellen Sie eine neue Anfrage.'));
        }
        TicketMessage::create([
            'id' => Str::uuid(),
            'ticket_id' => $ticket->id,
            'sender_id' => auth()->id(),
            'body' => $request->body,
            'is_internal' => false,
        ]);
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                \App\Models\TicketAttachment::create([
                    'id' => (string) Str::uuid(),
                    'ticket_id' => $ticket->id,
                    'uploaded_by' => auth()->id(),
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $file->store('tickets/' . $ticket->id, 'local'),
                    'disk' => 'local',
                ]);
            }
        }
        $ticket->logEvent('customer_reply');
        // Kundenantwort auf "Wartet auf Kunde" oder "Geloest" -> Ticket ist
        // wieder beim Team (auch Wiedereroeffnung nach Loesung).
        if (in_array($ticket->status, ['waiting', 'resolved'], true)) {
            $ticket->transitionTo('open', auth()->id());
        }
        $ticket->touch();
        \App\Services\TicketNotifier::notifyCustomerReply($ticket);
        return back()->with('success', 'Nachricht gesendet.');
    }

    /** Kunde bestaetigt: Anliegen erledigt -> Anfrage schliessen. */
    public function ticketsClose($id) {
        $customer = $this->getCustomer();
        $ticket = Ticket::where('id', $id)->where('customer_id', $customer->id)->firstOrFail();
        if ($ticket->status !== 'closed') {
            $ticket->transitionTo('closed', auth()->id(), 'closed_by_customer');
            \App\Services\TicketNotifier::notifyTeam($ticket, '✅ Anfrage vom Kunden geschlossen',
                ($ticket->ticket_number ? $ticket->ticket_number . ' – ' : '') . \Illuminate\Support\Str::limit($ticket->subject, 70));
        }
        return back()->with('success', __('Vielen Dank! Die Anfrage wurde geschlossen.'));
    }

    /** Zufriedenheits-Bewertung (1-5) nach Loesung/Abschluss, einmalig. */
    public function ticketsRate(Request $request, $id) {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'rating_comment' => 'nullable|string|max:1000',
        ]);
        $customer = $this->getCustomer();
        $ticket = Ticket::where('id', $id)->where('customer_id', $customer->id)->firstOrFail();
        if (!$ticket->isFinished() || $ticket->rating !== null) {
            return back();
        }
        $ticket->update(['rating' => (int) $request->rating, 'rating_comment' => $request->rating_comment]);
        $ticket->logEvent('rated', $request->rating . '/5' . ($request->rating_comment ? ' – ' . \Illuminate\Support\Str::limit($request->rating_comment, 120) : ''));
        \App\Services\TicketNotifier::notifyTeam($ticket, '⭐ Neue Ticket-Bewertung',
            ($ticket->ticket_number ? $ticket->ticket_number . ' – ' : '') . $request->rating . '/5 Sternen');
        return back()->with('success', __('Vielen Dank für Ihre Bewertung!'));
    }

    /**
     * Sicherer Dokument-Download: nur authentifizierte Kunden, nur
     * Dokumente des eigenen Kundendatensatzes (sonst 404). Funktioniert
     * für private ('local') und Bestandsdokumente ('public').
     */
    public function documentDownload($id) {
        $customer = $this->getCustomer();
        $doc = \App\Models\Document::where('customer_id', $customer->id)
            ->customerVisible() // interne Dokumente sind für Kunden unsichtbar (404)
            ->where('id', $id)->firstOrFail();
        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_viewed',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);
        $disk = $doc->disk ?: 'public';
        abort_unless(\Illuminate\Support\Facades\Storage::disk($disk)->exists($doc->file_path), 404);
        return \Illuminate\Support\Facades\Storage::disk($disk)->download($doc->file_path, $doc->file_name);
    }

    /**
     * Portal-Glocke (Review Punkt 8/10): Statusmeldungen und
     * 'Neue Nachricht'-Hinweise für den Kunden. Hart gescoped auf den
     * eigenen User und auf title-basierte Einträge - interne Mention-/
     * Change-Request-Zeilen sind hier strukturell unerreichbar.
     */
    public function notifications() {
        $items = \App\Models\InternalNotification::where('user_id', auth()->id())
            ->whereNotNull('title')
            ->latest()->take(15)->get()
            ->map(fn($n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'time' => $n->created_at->format('d.m.Y H:i'),
                'read' => $n->read_at !== null,
                'url' => $n->link ?: '#',
            ]);

        return response()->json([
            'unread' => \App\Models\InternalNotification::where('user_id', auth()->id())->whereNotNull('title')->whereNull('read_at')->count(),
            'items' => $items,
        ]);
    }

    public function notificationRead($id) {
        $n = \App\Models\InternalNotification::where('user_id', auth()->id())->whereNotNull('title')->findOrFail($id);
        $n->update(['read_at' => $n->read_at ?? now()]);
        return response()->json(['ok' => true]);
    }

    /**
     * Banner-Klick (Punkt 4): öffnet die Nachrichten-Seite und erstellt
     * automatisch eine Supportanfrage, die den Banner eindeutig
     * referenziert. Doppelklicks erzeugen kein Duplikat, solange die
     * Anfrage offen ist.
     */
    public function bannerInterest($id) {
        $customer = $this->getCustomer();
        $banner = \App\Models\Banner::current()->findOrFail($id);
        $banner->recordClick(auth()->id());

        $subject = 'Interesse: ' . $banner->title;
        $ticket = Ticket::where('customer_id', $customer->id)
            ->where('subject', $subject)
            ->whereIn('status', ['open', 'in_progress', 'waiting'])
            ->first();

        if (!$ticket) {
            $ticket = Ticket::create([
                'customer_id' => $customer->id,
                'type' => 'other',
                'status' => 'open',
                'subject' => $subject,
                'description' => 'Der Kunde interessiert sich für das Angebot „' . $banner->title . '" (Banner #' . $banner->id . ').',
            ]);
        }

        return redirect()->route('portal.tickets.show', $ticket->id)
            ->with('success', 'Ihre Anfrage zum Angebot „' . $banner->title . '" wurde erstellt. Unser Team meldet sich bei Ihnen.');
    }

    public function documents() {
        $customer = $this->getCustomer();
        return view('portal.documents', [
            'documents' => \App\Models\Document::where('customer_id', $customer->id)->customerVisible()->latest()->get(),
            // Angeforderte Dokumente: offene/abgelehnte zuerst, dann in Prüfung, dann erledigt.
            'documentRequests' => \App\Models\DocumentRequest::with('contract')
                ->where('customer_id', $customer->id)
                ->orderByRaw("case status when 'rejected' then 0 when 'open' then 1 when 'uploaded' then 2 else 3 end")
                ->latest()->get(),
        ]);
    }

    /**
     * Upload zu einer konkreten Dokumentenanfrage (Architekturplan
     * Abschnitt 14): Datei landet als normales Document (privater
     * Storage), die Anfrage wechselt auf 'uploaded' und die zuständigen
     * Mitarbeiter werden über die interne Glocke benachrichtigt.
     */
    public function documentRequestUpload(Request $request, $id) {
        $customer = $this->getCustomer();
        $documentRequest = \App\Models\DocumentRequest::where('customer_id', $customer->id)->findOrFail($id);
        abort_unless($documentRequest->acceptsUpload(), 422);

        $request->validate([
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        $file = $request->file('document');
        $path = $file->store("customers/{$customer->id}/documents", 'local');

        $doc = \App\Models\Document::create([
            'customer_id' => $customer->id,
            'category' => 'other',
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'disk' => 'local',
            'visibility' => 'customer',
            'uploaded_by' => auth()->id(),
            'file_size' => $file->getSize(),
        ]);

        $documentRequest->update([
            'document_id' => $doc->id,
            'status' => 'uploaded',
            'uploaded_at' => now(),
        ]);

        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_request_uploaded',
            'entity_type' => 'document_request',
            'entity_id' => $documentRequest->id,
            'meta' => json_encode(['file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);

        // Zuständige Betreuer benachrichtigen; ohne Zuweisung admins/manager.
        $recipients = $customer->betreuer()->get();
        if ($recipients->isEmpty()) {
            $recipients = \App\Models\User::whereIn('role', ['admin', 'manager'])->where('is_active', true)->get();
        }
        foreach ($recipients as $recipient) {
            \App\Models\InternalNotification::create([
                'user_id' => $recipient->id,
                'title' => 'Dokument hochgeladen: ' . $documentRequest->title,
                'body' => ($customer->user?->name ?? 'Kunde') . ' hat ein angefordertes Dokument hochgeladen.',
                'link' => route('admin.document_requests'),
            ]);
        }

        return back()->with('success', 'Vielen Dank! Ihr Dokument wurde übermittelt und wird nun geprüft.');
    }

    /**
     * Kunde lädt selbst ein Dokument hoch (Review Punkt 7). Speicherung
     * privat; das Dokument gehört dem Kunden und ist für ihn sichtbar.
     * Admin/Manager/Support werden über das Notification Center informiert.
     */
    public function documentUpload(Request $request) {
        $customer = $this->getCustomer();

        $data = $request->validate([
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx|max:10240',
            'category' => 'required|in:contract,police,invoice,identity,claim,other',
        ]);

        $file = $request->file('document');
        $path = $file->store('customers/' . $customer->id . '/documents', 'local');

        $doc = \App\Models\Document::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'customer_id' => $customer->id,
            'category' => $data['category'],
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'disk' => 'local',
            'visibility' => 'customer',
            'uploaded_by' => auth()->id(),
            'file_size' => $file->getSize(),
        ]);

        // Benachrichtigung an Staff (Notification Center)
        foreach (\App\Models\User::whereIn('role', ['admin','manager','support'])->where('is_active', true)->get() as $recipient) {
            \App\Models\InternalNotification::create([
                'user_id' => $recipient->id,
                'title' => 'Neues Kundendokument',
                'body' => ($customer->user?->name ?? 'Ein Kunde') . ' hat „' . $doc->file_name . '" hochgeladen.',
                'link' => route('admin.customer', $customer->id) . '#tab-uebersicht',
            ]);
        }

        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'document_uploaded_by_customer',
            'entity_type' => 'document',
            'entity_id' => $doc->id,
            'meta' => json_encode(['customer_id' => (string) $customer->id, 'file' => $doc->file_name], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Ihr Dokument wurde hochgeladen.');
    }

    public function profile() {
        $customer = $this->getCustomer();
        return view('portal.profile', [
            'customer' => $customer,
            'pending' => \App\Models\CustomerChangeRequest::where('customer_id', $customer->id)->where('status','pending')->count(),
        ]);
    }

    /** Transparente Datenschutzinfo für Kunden (DSGVO Art. 13/14). */
    public function datenschutz() {
        return view('portal.datenschutz');
    }

    /**
     * E-Mail-Verbindung (einwilligungsbasiert, Variante A). Zeigt Status,
     * getrennte Einwilligung und - bei aktiver Zustimmung - die persoenliche
     * Import-Adresse samt Weiterleitungs-Anleitung.
     */
    public function emailConnection(\App\Services\Mailbox\CustomerMailboxImportService $import) {
        $customer = $this->getCustomer();
        $consent = $customer->activeEmailConsent();

        return view('portal.email_connection', [
            'customer' => $customer,
            'consent' => $consent,
            'importAddress' => $consent ? $import->importAddressFor($consent) : null,
        ]);
    }

    /** Einwilligung erteilen (getrennte, nicht vorausgewaehlte Checkbox). */
    public function emailConnectionGrant(Request $request) {
        $request->validate([
            'consent' => 'accepted', // Checkbox muss aktiv gesetzt sein (Art. 7: keine Vorauswahl)
        ], [
            'consent.accepted' => __('Bitte bestaetigen Sie die Einwilligung, um Ihre E-Mail-Verbindung zu aktivieren.'),
        ]);

        $customer = $this->getCustomer();

        if ($customer->hasActiveEmailConsent()) {
            return redirect()->route('portal.email_connection')
                ->with('success', __('Ihre E-Mail-Verbindung ist bereits aktiv.'));
        }

        \App\Models\CustomerConsent::create([
            'customer_id' => $customer->id,
            'type' => \App\Models\CustomerConsent::TYPE_EMAIL_PROCESSING,
            'granted_at' => now(),
            'consent_text_version' => \App\Models\CustomerConsent::EMAIL_TEXT_VERSION,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'source' => 'portal_settings',
            'import_token' => \App\Models\CustomerConsent::newImportToken(),
        ]);

        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'email_consent_granted',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode(['version' => \App\Models\CustomerConsent::EMAIL_TEXT_VERSION], JSON_UNESCAPED_UNICODE),
        ]);

        return redirect()->route('portal.email_connection')
            ->with('success', __('E-Mail-Verbindung aktiviert. Bitte richten Sie die Weiterleitung wie unten beschrieben ein.'));
    }

    /** Einwilligung widerrufen (Art. 7 Abs. 3 - so einfach wie die Erteilung). */
    public function emailConnectionRevoke(Request $request) {
        $customer = $this->getCustomer();

        $consent = $customer->activeEmailConsent();
        if ($consent) {
            $consent->forceFill(['revoked_at' => now()])->save();

            \App\Models\ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'email_consent_revoked',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
                'meta' => json_encode(['consent_id' => $consent->id], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return redirect()->route('portal.email_connection')
            ->with('success', __('E-Mail-Verbindung getrennt. Es werden keine weiteren E-Mails verarbeitet.'));
    }

    public function profileUpdate(Request $request) {
        $customer = $this->getCustomer();

        $data = $request->validate([
            'gender' => 'nullable|in:male,female,diverse',
            'birth_place' => 'nullable|string|max:255',
            'marital_status' => 'nullable|in:ledig,verheiratet,geschieden,verwitwet',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\/\s()-]{6,}$/'],
            // Strukturierte Adresse nach deutschem Standard (Review Punkt 5)
            'address_street' => 'nullable|string|max:255',
            'address_house_number' => 'nullable|string|max:10',
            'address_house_suffix' => 'nullable|string|max:10',
            'address_zip' => 'nullable|string|max:10',
            'address_city' => 'nullable|string|max:100',
            // Sensible Kundendaten (Review Punkt 6)
            'health_insurance_number' => 'nullable|string|max:50',
            'pension_insurance_number' => 'nullable|string|max:50',
            'tax_id' => 'nullable|string|max:20',
            // Bankverbindung (Review Punkt 4 - alles auf einer Seite)
            'iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/'],
            'account_holder' => 'nullable|string|max:255',
        ]);

        $service = app(\App\Services\ChangeRequestService::class);
        $created = 0;

        // Persönliche Daten + strukturierte Adresse + Kundendaten:
        // EIN gebündelter Profil-Antrag für alle geänderten Felder.
        $profileFields = [
            'gender', 'birth_place', 'marital_status', 'phone',
            'address_street', 'address_house_number', 'address_house_suffix', 'address_zip', 'address_city',
            'health_insurance_number', 'pension_insurance_number', 'tax_id',
        ];
        $profileOld = $profileNew = [];
        foreach ($profileFields as $field) {
            if ($request->filled($field) && (string) $data[$field] !== (string) $customer->$field) {
                $profileOld[$field] = $customer->$field;
                $profileNew[$field] = $data[$field];
            }
        }
        if ($profileNew) {
            $service->submit($customer, 'profile', $profileOld, $profileNew, 'Profiländerung beantragt: ' . implode(', ', array_keys($profileNew)));
            $created++;
        }

        // Bankverbindung als EIGENER, unabhängiger Change Request (Review Punkt 9)
        if ($request->filled('iban') && $data['iban'] !== $customer->iban) {
            $service->submit(
                $customer,
                'bank',
                ['iban' => $customer->iban ? '••••' . substr($customer->iban, -4) : null, 'account_holder' => $customer->account_holder],
                ['iban' => $data['iban'], 'account_holder' => ($data['account_holder'] ?? null) ?: ($customer->account_holder ?? auth()->user()->name)],
                'Neue Bankverbindung beantragt'
            );
            $created++;
        }

        return back()->with('success', $created > 0
            ? 'Ihre Änderung' . ($created > 1 ? 'en wurden' : ' wurde') . ' zur Prüfung eingereicht. Jede Änderung wird einzeln bearbeitet.'
            : 'Keine Änderungen erkannt.');
    }

    /**
     * Eigenes Passwort ändern (Portal, "Meine Daten"). Wirkt sofort -
     * das Login-Passwort ist keine Stammdatenänderung und braucht
     * keine Mitarbeiterfreigabe. Erfordert das aktuelle Passwort.
     */
    public function passwordUpdate(Request $request) {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'min:8'],
        ], [
            'current_password.current_password' => 'Das aktuelle Passwort ist nicht korrekt.',
            'password.confirmed' => 'Die Passwort-Bestätigung stimmt nicht überein.',
            'password.min' => 'Das neue Passwort muss mindestens 8 Zeichen lang sein.',
        ]);

        auth()->user()->forceFill([
            'password' => bcrypt($request->password),
            'portal_password_set_at' => now(),
        ])->save();

        \App\Models\ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'portal_password_changed',
            'entity_type' => 'user',
            'entity_id' => (string) auth()->id(),
            'meta' => json_encode([], JSON_UNESCAPED_UNICODE),
        ]);

        return back()->with('success', 'Ihr Passwort wurde geändert.');
    }
}
