<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CustomerMessageController;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerChannelIdentity;
use App\Models\CustomerMessage;
use App\Models\CustomerMessageAttachment;
use App\Models\Document;
use App\Models\User;
use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\CustomerCreation\DuplicateCustomerException;
use App\Services\Messaging\AssignmentService;
use App\Services\Messaging\AttachmentFilingService;
use App\Services\Messaging\Inbox\ConversationInbox;
use App\Services\Messaging\Inbox\InboxFilters;
use App\Support\UploadRules;
use Illuminate\Http\Request;

/**
 * DAS vereinheitlichte Postfach (Auftrag Teil A).
 *
 * Vorher war "Postfach" eine Navigationsgruppe mit fuenf getrennten
 * Seiten. Es gab keine gemeinsame Liste - also auch kein "Alle", kein
 * "Ungelesen" und keinen Kanal-Filter. Ein neuer Kanal haette eine
 * sechste Seite bedeutet.
 *
 * Diese Seite liest ausschliesslich `conversations` ueber
 * `ConversationInbox`. Sie enthaelt KEINEN Kanalnamen: welche Kanaele es
 * gibt, steht in der Datenbank; was ein Kanal kann, in seinen
 * Faehigkeiten. Ein neuer Kanal erscheint hier von selbst.
 *
 * Tickets, Anfragen und E-Mail bleiben unangetastet auf ihren Seiten -
 * sie sind keine Kanal-Unterhaltungen, und sie umzubauen war
 * ausdruecklich nicht der Auftrag.
 */
class PostfachController extends Controller
{
    public function __construct(
        private readonly ConversationInbox $inbox,
        private readonly AssignmentService $assignments,
    ) {}

    public function index(Request $request)
    {
        $user = auth()->user();
        $kanaele = Channel::active()->orderBy('sort')->get();
        $filters = InboxFilters::fromRequest($request, $kanaele->pluck('key')->all());

        $conversations = $this->inbox->query($user, $filters)->paginate(30)->withQueryString();
        $counts = $this->inbox->counts($user, $filters);

        // Die offene Unterhaltung. Bewusst ueber DIESELBE Sichtbarkeits-
        // grenze wie die Liste: eine Kennung aus der Adresse darf nie
        // mehr zeigen, als die Liste hergeben wuerde.
        $active = null;
        $messages = collect();
        $aktenUnterlagen = collect();
        if ($request->query('unterhaltung')) {
            $active = $this->inbox->scope($user)
                ->whereKey($request->query('unterhaltung'))
                ->firstOrFail();

            $messages = $active->messages()->with(['sender', 'attachments'])
                ->orderBy('created_at')->get();

            // Sichtbar heisst gelesen - dieselbe Regel wie im Kundenchat.
            CustomerMessage::where('conversation_id', $active->id)
                ->where('direction', CustomerMessage::DIRECTION_INCOMING)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            // Unterlagen zum Mitschicken. GEDECKELT (dieselbe Lehre wie
            // bei den grossen Listen): eine Akte mit 300 Unterlagen darf
            // den Chat nicht laden - die juengsten sind die gefragten.
            if ($active->customer_id) {
                $aktenUnterlagen = Document::where('customer_id', $active->customer_id)
                    ->orderByDesc('created_at')->limit(50)
                    ->get(['id', 'file_name', 'created_at']);
            }
        }

        return view('admin.postfach.index', [
            'conversations' => $conversations,
            'counts' => $counts,
            'filters' => $filters,
            'aktenUnterlagen' => $aktenUnterlagen,
            'kanaele' => $kanaele,
            'active' => $active,
            'messages' => $messages,
            'mitarbeiter' => User::whereIn('role', ['admin', 'manager', 'support', 'employee'])
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Antworten. Der Kanal wird NICHT gewaehlt - er steht an der
     * Unterhaltung. Der Versand selbst haengt am Modell-Hook; hier wird
     * nur die Nachricht geschrieben.
     */
    public function reply(Request $request, string $id)
    {
        $user = auth()->user();
        $unterhaltung = $this->inbox->scope($user)->whereKey($id)->firstOrFail();

        $data = $request->validate([
            'body' => 'required|string|max:5000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => UploadRules::each(UploadRules::ATTACHMENT_MIMES),
            // Unterlagen AUS DER AKTE mitschicken - der haeufigste Fall
            // ("schicken Sie mir bitte meine Police") braucht sonst einen
            // Umweg ueber Herunterladen und wieder Hochladen.
            'dokumente' => 'nullable|array|max:5',
            'dokumente.*' => 'string',
        ]);

        $ausAkte = collect();
        if (! empty($data['dokumente'])) {
            // Nur Unterlagen DIESES Kunden - die Kennung kommt aus dem
            // Browser und wird nie geglaubt. Ohne diese Bedingung liesse
            // sich mit einer fremden Kennung eine fremde Akte abfliessen.
            if (! $unterhaltung->customer_id) {
                return back()->with('error', 'Diese Unterhaltung ist keinem Kunden zugeordnet.');
            }
            $ausAkte = Document::where('customer_id', $unterhaltung->customer_id)
                ->whereIn('id', $data['dokumente'])->get();
        }

        // FAEHIGKEITEN statt Kanalnamen (Auftrag 8): kann der Kanal keine
        // Dateien, wird der Anhang abgelehnt - und zwar mit einer
        // Begruendung, statt ihn still zu verschlucken.
        if (($request->hasFile('attachments') || $ausAkte->isNotEmpty())
            && ! $unterhaltung->channel?->supports('supportsMedia')) {
            return back()->with('error', 'Dieser Kanal kann keine Dateien senden.');
        }

        // Erst die Nachricht, dann die Anhaenge, dann der Versand - sonst
        // ginge der Text ohne die Dateien raus.
        CustomerMessage::mitAnhaengen(
            fn () => CustomerMessage::create([
                'conversation_id' => $unterhaltung->id,
                'customer_id' => $unterhaltung->customer_id,
                'sender_id' => $user->id,
                'body' => $data['body'],
                'from_staff' => true,
                'email_mode' => 'none',
            ]),
            function (CustomerMessage $nachricht) use ($request, $ausAkte, $user) {
                CustomerMessageController::storeAttachments($request, $nachricht);

                foreach ($ausAkte as $dokument) {
                    // KEINE zweite Kopie: der Anhang zeigt auf dieselbe
                    // Datei und traegt die Unterlagen-Kennung mit. Damit
                    // ist im Verlauf belegt, WELCHE Unterlage der Kunde
                    // bekommen hat - nicht nur, dass er etwas bekam.
                    CustomerMessageAttachment::create([
                        'message_id' => $nachricht->id,
                        'uploaded_by' => $user->id,
                        'file_name' => $dokument->file_name,
                        'file_path' => $dokument->file_path,
                        'disk' => $dokument->disk ?: 'local',
                        'file_size' => $dokument->file_size,
                        'document_id' => $dokument->id,
                    ]);
                }
            }
        );

        return back()->with('success', 'Nachricht gesendet.');
    }

    /**
     * Einen Chat-Anhang als Unterlage in die Kundenakte uebernehmen.
     *
     * Bewusst eine ausdrueckliche Aktion: nicht jedes Bild in einer
     * Unterhaltung ist ein Nachweis (siehe AttachmentFilingService).
     */
    public function fileAttachment(Request $request, string $id, AttachmentFilingService $ablage)
    {
        $user = auth()->user();
        $anhang = CustomerMessageAttachment::with('message.conversation')->findOrFail($id);

        // Zugriff ueber die UNTERHALTUNG pruefen, nicht ueber den Anhang:
        // dieselbe Sicht wie im Postfach, dieselbe Portfolio-Regel.
        $unterhaltung = $anhang->message?->conversation;
        abort_unless($unterhaltung && $this->inbox->scope($user)->whereKey($unterhaltung->id)->exists(), 403);

        try {
            $dokument = $ablage->uebernehmen($anhang, $user);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // EHRLICH benennen, was passiert ist: bei inhaltsgleicher
        // Unterlage entsteht keine zweite Datei, und der Mitarbeiter
        // soll nicht glauben, er habe gerade etwas angelegt.
        return back()->with('success', $dokument->wasRecentlyCreated
            ? 'In die Kundenakte übernommen: '.$dokument->file_name
            : 'Liegt bereits als Unterlage in der Kundenakte: '.$dokument->file_name);
    }

    /** Uebernehmen - aendert die Zustaendigkeit, NIE den Betreuer. */
    public function takeOver(Request $request, string $id)
    {
        $user = auth()->user();
        $unterhaltung = $this->inbox->scope($user)->whereKey($id)->firstOrFail();
        $this->assignments->takeOver($unterhaltung, $user);

        return back()->with('success', 'Vorgang übernommen. Der Betreuer des Kunden bleibt unverändert.');
    }

    /** Weitergeben an einen anderen Mitarbeiter. */
    public function reassign(Request $request, string $id)
    {
        $user = auth()->user();
        $unterhaltung = $this->inbox->scope($user)->whereKey($id)->firstOrFail();
        $data = $request->validate(['employee_id' => 'required|exists:users,id']);
        $ziel = User::findOrFail($data['employee_id']);
        $this->assignments->reassign($unterhaltung, $ziel, $user);

        return back()->with('success', 'Zuständigkeit geändert. Der Betreuer des Kunden bleibt unverändert.');
    }

    /** Zustand der Unterhaltung: schliessen, wieder oeffnen, archivieren. */
    public function status(Request $request, string $id)
    {
        $user = auth()->user();
        $unterhaltung = $this->inbox->scope($user)->whereKey($id)->firstOrFail();
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', array_keys(Conversation::STATUSES)),
        ]);

        $unterhaltung->forceFill([
            'status' => $data['status'],
            'closed_at' => $data['status'] === Conversation::STATUS_CLOSED ? now() : null,
            'archived_at' => $data['status'] === Conversation::STATUS_ARCHIVED ? now() : null,
            'reopened_at' => $data['status'] === Conversation::STATUS_OPEN ? now() : $unterhaltung->reopened_at,
        ])->save();

        return back()->with('success', 'Zustand geändert: '.Conversation::STATUSES[$data['status']]);
    }

    /**
     * UNBEKANNTER KONTAKT -> bestehende Kundenakte (Auftrag Prioritaet 3).
     *
     * Die Unterhaltung bleibt dieselbe: Verlauf, Zuweisung und Zustand
     * haengen an ihr. Eine neue Unterhaltung anzulegen hiesse, den
     * bisherigen Schriftwechsel zu verlieren - und genau der ist der
     * Grund, warum die Nachricht ueberhaupt aufgehoben wurde.
     */
    public function linkCustomer(Request $request, string $id)
    {
        $user = auth()->user();
        $unterhaltung = $this->inbox->scope($user)->whereKey($id)->firstOrFail();
        $data = $request->validate(['customer_id' => 'required|exists:customers,id']);
        abort_unless($user->canAccessCustomer($data['customer_id']), 403);

        // Eine bereits zugeordnete Unterhaltung wird nicht umgehaengt -
        // das waere eine stille Umbuchung fremder Nachrichten.
        abort_if($unterhaltung->customer_id !== null, 422, 'Diese Unterhaltung hat bereits eine Kundenakte.');

        $this->assign($unterhaltung, Customer::findOrFail($data['customer_id']));

        return back()->with('success', 'Unterhaltung mit der Kundenakte verknüpft.');
    }

    /** UNBEKANNTER KONTAKT -> neue Kundenakte. */
    public function createCustomer(Request $request, string $id)
    {
        $user = auth()->user();
        $unterhaltung = $this->inbox->scope($user)->whereKey($id)->firstOrFail();
        abort_if($unterhaltung->customer_id !== null, 422, 'Diese Unterhaltung hat bereits eine Kundenakte.');

        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
        ]);

        // Die Kennung der Plattform ist bei einem Telefon-Kanal die
        // Rufnummer - oft der einzige belegte Kontakt, den wir haben.
        $felder = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $unterhaltung->external_user_id
                ? '+'.ltrim((string) $unterhaltung->external_user_id, '+')
                : null,
        ];

        try {
            $kunde = app(CustomerAutoCreationService::class)
                ->createFromUnmatched($felder, 'manual', $user->id);
        } catch (DuplicateCustomerException) {
            // Der Duplikatsschutz des Bestands gilt auch hier. Lieber
            // keine zweite Akte als eine zweite Akte - der Mitarbeiter
            // verknuepft dann von Hand mit der vorhandenen.
            return back()->with('error',
                'Es gibt bereits eine passende Kundenakte. Bitte die Unterhaltung mit ihr verknüpfen.');
        }

        $this->assign($unterhaltung, $kunde);

        return back()->with('success', 'Kundenakte angelegt und verknüpft.');
    }

    /**
     * Kunde an Unterhaltung UND an die Kanal-Identitaet haengen.
     *
     * Die Identitaet ist der eigentliche Gewinn: ab jetzt findet JEDE
     * weitere Nachricht dieser Kennung den Kunden von selbst, ohne dass
     * jemand wieder zuordnen muss.
     */
    private function assign(Conversation $unterhaltung, Customer $kunde): void
    {
        $unterhaltung->forceFill(['customer_id' => $kunde->id])->save();

        CustomerMessage::where('conversation_id', $unterhaltung->id)
            ->whereNull('customer_id')
            ->update(['customer_id' => $kunde->id]);

        if ($unterhaltung->external_user_id) {
            CustomerChannelIdentity::firstOrCreate([
                'channel_account_id' => $unterhaltung->channel_account_id,
                'external_user_id' => $unterhaltung->external_user_id,
            ], [
                'customer_id' => $kunde->id,
                'channel_id' => $unterhaltung->channel_id,
            ]);
        }

        // Erst jetzt gibt es einen Betreuer, den man zuweisen koennte.
        $this->assignments->autoAssign($unterhaltung->fresh());
    }
}
