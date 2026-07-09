<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\InternalConversation;
use App\Models\InternalConversationMessage;
use App\Models\InternalConversationParticipant;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Eigenständiger interner Mitarbeiter-Chat (Spec Teil 8).
 * Vollständig getrennt von Kundentickets und Kunden. Alle Routen liegen
 * in der Admin-Gruppe (nur Staff); zusätzlich prüft die
 * InternalConversationPolicy die Teilnehmerschaft.
 */
class InternalChatController extends Controller
{
    private const STAFF_ROLES = ['admin', 'manager', 'support', 'employee'];

    public function index()
    {
        $conversations = InternalConversation::whereHas('participants', fn($q) => $q->where('user_id', auth()->id()))
            ->with(['creator', 'participants.user'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();

        return view('admin.internal_chat.index', [
            'conversations' => $conversations,
            'staff' => User::whereIn('role', self::STAFF_ROLES)->where('is_active', true)->where('id', '!=', auth()->id())->orderBy('name')->get(),
        ]);
    }

    public function show($id)
    {
        $conversation = InternalConversation::with(['participants.user', 'messages.sender'])->findOrFail($id);
        $this->authorize('view', $conversation);

        // Als gelesen markieren
        InternalConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', auth()->id())
            ->update(['last_read_at' => now()]);

        return view('admin.internal_chat.show', [
            'conversation' => $conversation,
            'conversations' => InternalConversation::whereHas('participants', fn($q) => $q->where('user_id', auth()->id()))
                ->orderByDesc('last_message_at')->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'participants' => 'required|array|min:1',
            'participants.*' => 'integer|exists:users,id',
            'team' => 'nullable|in:support,manager,admin,all',
            'body' => 'required|string|max:5000',
        ]);

        // Teilnehmer bestimmen: explizit gewählte + optional ganzes Team.
        // HART auf Staff gefiltert - Kunden können nie Teilnehmer werden.
        $participantIds = collect($data['participants']);
        if (!empty($data['team'])) {
            $teamQuery = User::where('is_active', true);
            $data['team'] === 'all'
                ? $teamQuery->whereIn('role', self::STAFF_ROLES)
                : $teamQuery->where('role', $data['team']);
            $participantIds = $participantIds->merge($teamQuery->pluck('id'));
        }

        $participantIds = User::whereIn('id', $participantIds->unique())
            ->whereIn('role', self::STAFF_ROLES)
            ->where('is_active', true)
            ->pluck('id')
            ->push(auth()->id())
            ->unique();

        $conversation = InternalConversation::create([
            'subject' => $data['subject'],
            'created_by' => auth()->id(),
            'last_message_at' => now(),
        ]);

        foreach ($participantIds as $uid) {
            InternalConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $uid,
                'last_read_at' => $uid === auth()->id() ? now() : null,
            ]);
        }

        InternalConversationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => auth()->id(),
            'body' => $data['body'],
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'internal_conversation_created',
            'entity_type' => 'internal_conversation',
            'entity_id' => $conversation->id,
            'meta' => json_encode(['subject' => $data['subject'], 'participants' => $participantIds->values()->all()], JSON_UNESCAPED_UNICODE),
        ]);

        return redirect()->route('admin.chat.show', $conversation->id)->with('success', 'Unterhaltung erstellt.');
    }

    public function reply(Request $request, $id)
    {
        $conversation = InternalConversation::findOrFail($id);
        $this->authorize('reply', $conversation);

        $data = $request->validate(['body' => 'required|string|max:5000']);

        InternalConversationMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => auth()->id(),
            'body' => $data['body'],
        ]);
        $conversation->update(['last_message_at' => now()]);

        // Absender hat gelesen; für die übrigen bleibt es ungelesen
        InternalConversationParticipant::where('conversation_id', $conversation->id)
            ->where('user_id', auth()->id())->update(['last_read_at' => now()]);

        return back()->with('success', 'Nachricht gesendet.');
    }
}
