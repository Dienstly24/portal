<?php
namespace App\Http\Controllers;

use App\Events\InternalMessageCreated;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\InternalMessage;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Interner Mitarbeiter-Chat & interne Notizen pro Kunde.
 *
 * Sicherheit: Alle Routen liegen ausschließlich in der Admin-Gruppe
 * (role:admin,manager,employee). Zusätzlich prüft jede Aktion die
 * InternalMessagePolicy (Kundensichtbarkeit inkl. Vertretungen).
 * Es existiert bewusst KEINE Portal-Route, die dieses Model berührt.
 */
class InternalMessageController extends Controller
{
    public function store(Request $request, $customerId)
    {
        $customer = Customer::findOrFail($customerId);
        $this->authorize('createForCustomer', [InternalMessage::class, (string) $customer->id]);

        $data = $request->validate([
            'message' => 'required|string|max:5000',
            'type' => 'required|in:chat,note',
        ]);

        $mentioned = $this->resolveMentions($data['message']);

        $message = InternalMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => auth()->id(),
            'message' => $data['message'],
            'type' => $data['type'],
            'mentioned_users' => $mentioned->pluck('id')->values()->all(),
        ]);

        $this->notifyMentioned($message, $mentioned);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'internal_message_created',
            'entity_type' => 'internal_message',
            'entity_id' => $message->id,
            'meta' => json_encode([
                'customer' => $customer->user?->name,
                'customer_id' => (string) $customer->id,
                'type' => $message->type,
                'mentioned' => $mentioned->pluck('name')->values()->all(),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        InternalMessageCreated::dispatch($message);

        return back()->with('success', $data['type'] === 'note' ? 'Interne Notiz gespeichert.' : 'Nachricht gesendet.')
            ->withFragment($data['type'] === 'note' ? 'tab-notizen' : 'tab-intern');
    }

    public function destroy($id)
    {
        $message = InternalMessage::findOrFail($id);
        $this->authorize('delete', $message);

        // Audit-Log VOR dem Soft-Delete (wer hat wann was gelöscht)
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'internal_message_deleted',
            'entity_type' => 'internal_message',
            'entity_id' => $message->id,
            'meta' => json_encode([
                'customer_id' => (string) $message->customer_id,
                'author' => $message->sender?->name,
                'type' => $message->type,
                'preview' => mb_substr($message->message, 0, 120),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $message->deleted_by = auth()->id();
        $message->save();
        $message->delete(); // Soft delete - bleibt für das Audit erhalten

        return back()->with('success', 'Nachricht gelöscht.');
    }

    /**
     * @Mentions auflösen:
     *  - @Admin / @Manager / @Support / @Team -> alle aktiven Nutzer der Rolle(n)
     *  - @Vorname bzw. @Vorname.Nachname      -> Namens-Match (Staff, aktiv)
     * Der Absender selbst und alle Nicht-Staff-Nutzer (Kunden!) werden
     * grundsätzlich ausgeschlossen.
     */
    private function resolveMentions(string $text)
    {
        preg_match_all('/@([\p{L}\p{N}._-]+)/u', $text, $m);
        $tokens = collect($m[1] ?? [])->unique();
        if ($tokens->isEmpty()) return collect();

        $staffRoles = ['admin', 'manager', 'support', 'employee'];
        $users = collect();

        foreach ($tokens as $token) {
            $lower = mb_strtolower($token);
            if (in_array($lower, ['admin', 'manager', 'support'], true)) {
                $users = $users->merge(
                    User::where('role', $lower)->where('is_active', true)->get()
                );
            } elseif ($lower === 'team' || $lower === 'alle') {
                $users = $users->merge(
                    User::whereIn('role', $staffRoles)->where('is_active', true)->get()
                );
            } else {
                $name = str_replace(['.', '_'], ' ', $token);
                $users = $users->merge(
                    User::whereIn('role', $staffRoles)
                        ->where('is_active', true)
                        ->where('name', 'like', $name . '%')
                        ->get()
                );
            }
        }

        return $users->unique('id')
            ->reject(fn($u) => $u->id === auth()->id() || !$u->isStaff())
            ->values();
    }

    private function notifyMentioned(InternalMessage $message, $users): void
    {
        foreach ($users as $user) {
            \App\Support\Facades\Notify::push($user->id, [
                'type' => \App\Services\Notifications\NotificationService::TYPE_MENTION,
                'message_id' => $message->id,
                'dedup_key' => 'mention-' . $message->id,
            ]);

            // Optionale E-Mail-Benachrichtigung (Einstellung, Standard: aus).
            // Nur an Staff - Kunden sind durch resolveMentions() bereits
            // ausgeschlossen; der Check hier ist eine zweite Verteidigungslinie.
            if (SystemSetting::get('mention_email_enabled', '0') === '1'
                && $user->isStaff()
                && $user->email
                && !str_contains($user->email, '@dienstly24.internal')) {
                try {
                    Mail::to($user->email)->send(new \App\Mail\InternalMentionMail($message, $user));
                } catch (\Throwable $e) {
                    \Log::warning('Mention mail failed: ' . $e->getMessage());
                }
            }
        }
    }
}
