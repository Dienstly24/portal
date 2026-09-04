<?php

namespace App\Http\Controllers;

use App\Mail\DirectEmailMail;
use App\Models\ChangeNotification;
use App\Models\CustomerChangeRequest;
use App\Models\CustomerTimeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * "Mitteilungen an Gesellschaften": nach der Freigabe einer sensiblen
 * Kundenaenderung (Bank, Adresse, Name) liegt je Gesellschaft des Kunden
 * ein fertiger Text bereit. Der Mitarbeiter prueft ihn, traegt die
 * Empfaengeradresse ein und sendet mit einem Klick - der Nachweis des
 * Kunden geht auf Wunsch als Anhang mit.
 *
 * Nach aussen geht NIE eine automatische E-Mail: der Versand ist immer
 * eine bewusste Mitarbeiter-Aktion (und erfordert die Composer-
 * Berechtigung wie jeder andere E-Mail-Versand).
 */
class ChangeNotificationController extends Controller
{
    public function index($changeRequestId)
    {
        $changeRequest = CustomerChangeRequest::with(['customer.user', 'documents', 'notifications.sender'])
            ->findOrFail($changeRequestId);
        $this->authorize('review', $changeRequest);

        return view('admin.change_notifications', [
            'changeRequest' => $changeRequest,
            'notifications' => $changeRequest->notifications()->with('sender')->orderBy('status')->orderBy('insurer')->get(),
        ]);
    }

    /** Text/Empfaenger anpassen, ohne zu senden (Zwischenstand speichern). */
    public function update(Request $request, $id)
    {
        $notification = $this->findAccessible($id);

        $data = $request->validate([
            'recipient' => 'nullable|email|max:190',
            'subject' => 'required|string|max:190',
            'body' => 'required|string|max:10000',
        ]);

        abort_if($notification->status === 'sent', 422, 'Gesendete Mitteilungen sind unveränderlich.');
        $notification->update($data);

        return back()->with('success', 'Entwurf gespeichert.');
    }

    public function send(Request $request, $id)
    {
        $notification = $this->findAccessible($id);
        $this->authorizeSending();

        if ($notification->status === 'sent') {
            return back()->with('error', 'Diese Mitteilung wurde bereits gesendet.');
        }

        $data = $request->validate([
            'recipient' => 'required|email|max:190',
            'subject' => 'required|string|max:190',
            'body' => 'required|string|max:10000',
            'attach_proof' => 'nullable|boolean',
        ]);

        $attachments = [];
        if ($request->boolean('attach_proof')) {
            foreach ($notification->changeRequest->documents as $document) {
                $disk = Storage::disk($document->disk ?: 'local');
                if (! $disk->exists($document->file_path)) {
                    continue;
                }
                $attachments[] = [
                    'data' => $disk->get($document->file_path),
                    'name' => $document->file_name,
                    'mime' => $document->mimeType(),
                ];
            }
        }

        try {
            Mail::to($data['recipient'])->send(new DirectEmailMail(
                mailSubject: $data['subject'],
                mailBody: $data['body'],
                customer: $notification->customer,
                fileAttachments: $attachments,
                senderName: (string) auth()->user()->name,
            ));
        } catch (\Throwable $e) {
            \Log::warning('Mitteilung an Gesellschaft fehlgeschlagen: '.$e->getMessage());
            return back()->with('error', 'E-Mail konnte nicht gesendet werden: '.$e->getMessage());
        }

        $notification->update([
            'recipient' => $data['recipient'],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'status' => 'sent',
            'channel' => 'email',
            'sent_at' => now(),
            'sent_by' => auth()->id(),
        ]);

        // Nachvollziehbarkeit: der Versand steht in der Kundenakte.
        CustomerTimeline::create([
            'customer_id' => $notification->customer_id,
            'user_id' => auth()->id(),
            'type' => 'email',
            'title' => 'Gesellschaft informiert: '.$notification->insurer,
            'description' => $data['subject'].' · An '.$data['recipient']
                .($attachments !== [] ? ' · '.count($attachments).' Nachweis/Nachweise angehängt' : ''),
        ]);

        return back()->with('success', 'Mitteilung an '.$notification->insurer.' gesendet.');
    }

    /**
     * Erledigt ohne E-Mail (per Post, ueber das Portal der Gesellschaft
     * oder gar nicht noetig) - der Vorgang bleibt trotzdem dokumentiert.
     */
    public function skip(Request $request, $id)
    {
        $notification = $this->findAccessible($id);

        if ($notification->status === 'sent') {
            return back()->with('error', 'Diese Mitteilung wurde bereits gesendet.');
        }

        $data = $request->validate([
            'channel' => 'nullable|in:post,portal',
            'note' => 'nullable|string|max:500',
        ]);

        $notification->update([
            'status' => 'skipped',
            'channel' => $data['channel'] ?? null,
            'note' => $data['note'] ?? null,
            'sent_at' => now(),
            'sent_by' => auth()->id(),
        ]);

        if (($data['channel'] ?? null) !== null) {
            CustomerTimeline::create([
                'customer_id' => $notification->customer_id,
                'user_id' => auth()->id(),
                'type' => 'note',
                'title' => 'Gesellschaft informiert: '.$notification->insurer,
                'description' => 'Weg: '.(ChangeNotification::CHANNEL_LABELS[$data['channel']] ?? $data['channel'])
                    .($data['note'] ?? '' ? ' · '.$data['note'] : ''),
            ]);
        }

        return back()->with('success', 'Mitteilung als erledigt markiert.');
    }

    private function findAccessible($id): ChangeNotification
    {
        $notification = ChangeNotification::with(['changeRequest.documents', 'customer.user'])->findOrFail($id);
        $this->authorize('review', $notification->changeRequest);
        return $notification;
    }

    /** E-Mail-Versand nach aussen: gleiche Berechtigung wie im Composer. */
    private function authorizeSending(): void
    {
        $user = auth()->user();
        abort_unless(
            in_array($user->role, ['admin', 'manager', 'support'], true) || $user->can_send_emails,
            403,
            'Keine Berechtigung zum E-Mail-Versand.'
        );
    }
}
