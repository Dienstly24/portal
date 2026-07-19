<?php

namespace Tests\Feature;

use App\Models\InternalConversation;
use App\Models\InternalConversationMessage;
use App\Models\InternalConversationParticipant;
use App\Models\InternalNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Notification Center (Glocke im CRM): korrekte Aggregation, Ungelesen-
 * Zaehler, "alles gelesen" und - als Regressionsschutz - dass fuer
 * ungelesene Chats nur die JUENGSTE Nachricht ausgeliefert wird (kein
 * Laden des kompletten Verlaufs, latestMessage-Relation).
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'can_see_all_customers' => true]);
    }

    public function test_index_returns_system_notifications_and_unread_count(): void
    {
        $user = $this->staff();
        InternalNotification::create(['user_id' => $user->id, 'type' => 'system', 'title' => 'Hinweis A', 'body' => 'x']);
        $read = InternalNotification::create(['user_id' => $user->id, 'type' => 'system', 'title' => 'Hinweis B', 'body' => 'y']);
        $read->update(['read_at' => now()]);

        $data = $this->actingAs($user)
            ->getJson(route('admin.notifications'))
            ->assertOk()
            ->json();

        $this->assertSame(1, $data['unread']);
        $this->assertCount(2, $data['items']);
    }

    public function test_mark_all_read_clears_unread_counter(): void
    {
        $user = $this->staff();
        InternalNotification::create(['user_id' => $user->id, 'title' => 'A']);
        InternalNotification::create(['user_id' => $user->id, 'title' => 'B']);

        $this->actingAs($user)->postJson(route('admin.notifications.read_all'))->assertOk();

        $this->assertSame(0, InternalNotification::where('user_id', $user->id)->unread()->count());
    }

    public function test_user_only_sees_own_notifications(): void
    {
        $me = $this->staff();
        $other = $this->staff();
        InternalNotification::create(['user_id' => $other->id, 'title' => 'Fremd']);

        $data = $this->actingAs($me)->getJson(route('admin.notifications'))->json();
        $this->assertSame(0, $data['unread']);
        $this->assertCount(0, $data['items']);
    }

    public function test_unread_conversation_shows_only_latest_message(): void
    {
        $user = $this->staff();
        $conversation = InternalConversation::create([
            'subject' => 'Abstimmung Tarif',
            'created_by' => $user->id,
            'last_message_at' => now(),
        ]);
        InternalConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'last_read_at' => null,
        ]);
        InternalConversationMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $user->id, 'body' => 'Erste Nachricht', 'created_at' => now()->subMinutes(5)]);
        InternalConversationMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $user->id, 'body' => 'Neueste Nachricht', 'created_at' => now()]);

        $data = $this->actingAs($user)->getJson(route('admin.notifications'))->json();

        $conv = collect($data['items'])->firstWhere('icon', '🗨️');
        $this->assertNotNull($conv, 'Ungelesene Unterhaltung muss im Center erscheinen.');
        $this->assertStringContainsString('Neueste Nachricht', $conv['preview']);
        $this->assertGreaterThanOrEqual(1, $data['unread']);
    }
}
