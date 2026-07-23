<?php
namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerNote;
use App\Models\Document;
use App\Models\EmailMessage;
use App\Models\InternalMessage;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use Illuminate\Support\Collection;

/**
 * EINE Unterhaltung pro Kunde (Omnichannel, Phase A):
 * fuehrt die bestehenden Kanaele chronologisch zusammen, OHNE die
 * Datenhaltung zu veraendern - Chat (CustomerMessage), Tickets
 * (Nachrichten + Ereignisse), eingehende E-Mails, vom Kunden
 * hochgeladene Dokumente und interne Notizen. Jedes Element verlinkt
 * auf sein Ursprungsmodul; geschrieben wird weiterhin dort.
 *
 * Item-Struktur (fuer die Kundenkommunikation-Ansicht):
 *  kind     chat|ticket_msg|event|email|document|note (CSS/Filter)
 *  style    bubble|card|internal  (Darstellung)
 *  own      true = vom Team (nur bubble)
 *  at       Zeitpunkt, day/time vorformatiert
 *  icon,tag Kanal-Kennzeichnung (z.B. 🎫 Ticket #123)
 *  title    Kartentitel (nur card/internal)
 *  body     Text/Snippet
 *  sender   Absendername (nur bubble)
 *  url      Sprung ins Ursprungsmodul (optional)
 *  read     Lesehaken fuer eigene Chat-Blasen (nur chat)
 *  message  Original-CustomerMessage (nur chat - fuer Anhaenge-Markup)
 */
class CustomerConversationService
{
    /**
     * @param bool $includeEmails E-Mail-Posteingang ist auf
     *        admin/manager/support beschraenkt - fuer andere Rollen werden
     *        E-Mail-Elemente ausgelassen (Links waeren fuer sie 403).
     * @return Collection<int,array<string,mixed>> chronologisch aufsteigend
     */
    public function timeline(Customer $customer, bool $includeEmails = true): Collection
    {
        $items = collect();

        // 1) Portal-Chat: bleibt das Herz der Unterhaltung (Blasen).
        CustomerMessage::where('customer_id', $customer->id)
            ->with(['sender', 'attachments'])->get()
            ->each(function ($m) use (&$items) {
                $items->push(array_merge($this->base('chat', 'bubble', $m->created_at), [
                    'own' => $m->from_staff,
                    'sender' => $m->from_staff ? ($m->sender?->name ?? 'Dienstly24 Team') : null,
                    'body' => $m->body,
                    'read' => $m->read_at !== null,
                    'icon' => '💬',
                    'tag' => null,
                    'message' => $m,
                ]));
            });

        // 2) Tickets: Kundenschreiben/Antworten als Blasen mit Ticket-Tag,
        //    Workflow-Ereignisse (Status etc.) als kompakte Karten.
        $tickets = Ticket::where('customer_id', $customer->id)
            ->with(['messages.sender', 'events.user'])->get();
        foreach ($tickets as $ticket) {
            $tag = '🎫 Ticket #' . $ticket->ticket_number;
            $url = route('admin.ticket', $ticket->id);
            $items->push(array_merge($this->base('event', 'card', $ticket->created_at), [
                'icon' => '🆕',
                'tag' => $tag,
                'title' => 'Ticket erstellt: ' . $ticket->subject,
                'body' => trim(($ticket->typeLabel() ?? '') . ' · Quelle ' . ($ticket->source ?? 'portal')),
                'url' => $url,
            ]));
            foreach ($ticket->messages as $tm) {
                $fromStaff = $tm->sender_id && $tm->sender_id !== $customer->user_id;
                if ($tm->is_internal) {
                    $items->push(array_merge($this->base('note', 'internal', $tm->created_at), [
                        'icon' => '🔒',
                        'tag' => $tag,
                        'title' => 'Interne Ticket-Notiz von ' . ($tm->sender?->name ?? 'Team'),
                        'body' => $tm->body,
                        'url' => $url,
                    ]));
                    continue;
                }
                $items->push(array_merge($this->base('ticket_msg', 'bubble', $tm->created_at), [
                    'own' => $fromStaff,
                    'sender' => $fromStaff ? ($tm->sender?->name ?? 'Dienstly24 Team') : null,
                    'body' => $tm->body,
                    'icon' => '🎫',
                    'tag' => $tag,
                    'url' => $url,
                ]));
            }
            foreach ($ticket->events as $ev) {
                if ($ev->event === 'created') {
                    continue; // eigene Erstellt-Karte oben
                }
                [$icon, $label] = TicketEvent::LABELS[$ev->event] ?? ['🔁', $ev->event];
                $items->push(array_merge($this->base('event', 'card', $ev->created_at), [
                    'icon' => $icon,
                    'tag' => $tag,
                    'title' => $label . ($ev->user ? ' · ' . $ev->user->name : ''),
                    'body' => $ev->details,
                    'url' => $url,
                ]));
            }
        }

        // 3) Eingehende E-Mails, die dieser Kundenakte zugeordnet sind.
        if ($includeEmails) {
            EmailMessage::where('customer_id', $customer->id)->get()
                ->each(function ($mail) use (&$items) {
                    $items->push(array_merge($this->base('email', 'card', $mail->received_at ?? $mail->created_at), [
                        'icon' => '✉️',
                        'tag' => 'E-Mail',
                        'title' => $mail->subject ?: '(ohne Betreff)',
                        'body' => 'von ' . ($mail->from_name ?: $mail->from_address),
                        'url' => route('admin.email_inbox.show', $mail->id),
                    ]));
                });
        }

        // 4) Vom Kunden selbst hochgeladene Dokumente (Portal-Upload).
        if ($customer->user_id) {
            Document::where('customer_id', $customer->id)
                ->where('uploaded_by', $customer->user_id)->get()
                ->each(function ($doc) use (&$items) {
                    $items->push(array_merge($this->base('document', 'card', $doc->created_at), [
                        'icon' => '📄',
                        'tag' => 'Dokument',
                        'title' => 'Dokument hochgeladen: ' . $doc->file_name,
                        'body' => $doc->category,
                        'url' => route('admin.customer', $doc->customer_id) . '#tab-dokumente',
                    ]));
                });
        }

        // 5) Interne Notizen (Kundenakte) - nur fuer das Team sichtbar.
        CustomerNote::where('customer_id', $customer->id)->with('createdBy')->get()
            ->each(function ($note) use (&$items) {
                $items->push(array_merge($this->base('note', 'internal', $note->created_at), [
                    'icon' => '🔒',
                    'tag' => 'Notiz',
                    'title' => 'Interne Notiz von ' . ($note->createdBy?->name ?? 'Team'),
                    'body' => $note->note,
                    'url' => route('admin.customer', $note->customer_id) . '#tab-intern',
                ]));
            });
        InternalMessage::where('customer_id', $customer->id)->note()->with('sender')->get()
            ->each(function ($msg) use (&$items) {
                $items->push(array_merge($this->base('note', 'internal', $msg->created_at), [
                    'icon' => '🔒',
                    'tag' => 'Notiz',
                    'title' => 'Interne Notiz von ' . ($msg->sender?->name ?? 'Team'),
                    'body' => $msg->message,
                    'url' => route('admin.customer', $msg->customer_id) . '#tab-intern',
                ]));
            });

        return $items->sortBy('at')->values();
    }

    /**
     * Leichter Fingerabdruck der NICHT-Chat-Elemente (Tickets, E-Mails,
     * Dokumente, Notizen). Der Chat aktualisiert sich live; aendert sich
     * diese Version, zeigt die Unterhaltung einen Aktualisieren-Hinweis.
     */
    public function version(Customer $customer, bool $includeEmails = true): string
    {
        $ticketIds = Ticket::where('customer_id', $customer->id)->pluck('id');
        $parts = [
            Ticket::where('customer_id', $customer->id)->count(),
            Ticket::where('customer_id', $customer->id)->max('updated_at'),
            TicketMessage::whereIn('ticket_id', $ticketIds)->count(),
            TicketEvent::whereIn('ticket_id', $ticketIds)->count(),
            $includeEmails ? EmailMessage::where('customer_id', $customer->id)->count() : 0,
            Document::where('customer_id', $customer->id)->count(),
            CustomerNote::where('customer_id', $customer->id)->count(),
            InternalMessage::where('customer_id', $customer->id)->note()->count(),
        ];
        return md5(implode('|', array_map(strval(...), $parts)));
    }

    /** @return array<string,mixed> */
    private function base(string $kind, string $style, $at): array
    {
        $at = $at ?: now();
        return [
            'kind' => $kind,
            'style' => $style,
            'at' => $at,
            'day' => $at->isToday() ? __('Heute') : ($at->isYesterday() ? __('Gestern') : $at->format('d.m.Y')),
            'time' => $at->format('H:i'),
            'own' => false,
            'sender' => null,
            'title' => null,
            'body' => null,
            'url' => null,
            'icon' => null,
            'tag' => null,
            'read' => false,
            'message' => null,
        ];
    }
}
