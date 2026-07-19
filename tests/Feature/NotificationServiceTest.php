<?php

namespace Tests\Feature;

use App\Models\InternalNotification;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Facades\Notify;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kernabsicherung des zentralen NotificationService (Notification-System-
 * Audit, Juli 2026): sicheres Kuerzen, Duplikat-Vermeidung, Fan-out,
 * Kategorisierung. Diese Regeln gelten damit fuer ALLE Aufrufer einheitlich.
 */
class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    // ---------------------------------------------------------------
    // Sicheres Kuerzen: verhindert SQLSTATE[22001] "Data too long"
    // ---------------------------------------------------------------

    public function test_title_and_body_are_truncated_to_column_limits(): void
    {
        $user = $this->user();

        Notify::push($user->id, [
            'type' => NotificationService::TYPE_SYSTEM,
            'title' => str_repeat('T', 400),
            'body' => str_repeat('B', 900),
            'link' => 'https://example.test',
        ]);

        $note = InternalNotification::where('user_id', $user->id)->firstOrFail();
        $this->assertLessThanOrEqual(255, mb_strlen((string) $note->title));
        $this->assertLessThanOrEqual(500, mb_strlen((string) $note->body));
    }

    // ---------------------------------------------------------------
    // Duplikat-Vermeidung: gleicher dedup_key -> nur EIN ungelesener Eintrag
    // ---------------------------------------------------------------

    public function test_same_dedup_key_does_not_create_duplicates_while_unread(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 5; $i++) {
            Notify::push($user->id, [
                'type' => NotificationService::TYPE_TICKET,
                'title' => 'Neue Ticket-Antwort',
                'body' => "Antwort Nr. $i",
                'link' => '/admin/tickets/1',
                'dedup_key' => 'ticket-reply-1',
            ]);
        }

        $rows = InternalNotification::where('user_id', $user->id)->get();
        $this->assertCount(1, $rows, 'Gleicher dedup_key darf keine Duplikate erzeugen.');
        // Inhalt wurde aufgefrischt (letzte Nachricht gewinnt).
        $this->assertSame('Antwort Nr. 4', $rows->first()->body);
    }

    public function test_new_notification_after_read_is_shown_again(): void
    {
        $user = $this->user();

        $first = Notify::push($user->id, [
            'title' => 'Neue Ticket-Antwort',
            'body' => 'Erste',
            'dedup_key' => 'ticket-reply-9',
        ]);
        // Empfaenger liest den Eintrag.
        $first->update(['read_at' => now()]);

        // Neues Ereignis nach dem Lesen -> muss wieder sichtbar werden.
        Notify::push($user->id, [
            'title' => 'Neue Ticket-Antwort',
            'body' => 'Zweite',
            'dedup_key' => 'ticket-reply-9',
        ]);

        $this->assertSame(2, InternalNotification::where('user_id', $user->id)->count());
        $this->assertSame(1, InternalNotification::where('user_id', $user->id)->unread()->count());
    }

    public function test_without_dedup_key_every_push_creates_a_row(): void
    {
        $user = $this->user();
        Notify::push($user->id, ['title' => 'A']);
        Notify::push($user->id, ['title' => 'A']);
        $this->assertSame(2, InternalNotification::where('user_id', $user->id)->count());
    }

    // ---------------------------------------------------------------
    // Fan-out: doppelte Empfaenger-IDs -> genau eine Benachrichtigung
    // ---------------------------------------------------------------

    public function test_push_many_deduplicates_recipient_ids(): void
    {
        $a = $this->user();
        $b = $this->user();

        $delivered = Notify::pushMany([$a->id, $b->id, $a->id, 0, null], [
            'type' => NotificationService::TYPE_SYSTEM,
            'title' => 'Broadcast',
        ]);

        $this->assertSame(2, $delivered);
        $this->assertSame(1, InternalNotification::where('user_id', $a->id)->count());
        $this->assertSame(1, InternalNotification::where('user_id', $b->id)->count());
    }

    public function test_push_many_accepts_per_recipient_callback(): void
    {
        $a = $this->user();
        $b = $this->user();

        Notify::pushMany([$a->id, $b->id], fn ($id) => [
            'title' => 'Hallo ' . $id,
            'dedup_key' => 'greet-' . $id,
        ]);

        $this->assertDatabaseHas('internal_notifications', ['user_id' => $a->id, 'title' => 'Hallo ' . $a->id]);
        $this->assertDatabaseHas('internal_notifications', ['user_id' => $b->id, 'title' => 'Hallo ' . $b->id]);
    }

    public function test_unknown_attributes_are_ignored(): void
    {
        $user = $this->user();
        // Ein Tippfehler im Aufrufer darf keinen DB-Fehler ausloesen.
        $note = Notify::push($user->id, ['title' => 'Ok', 'bogus_field' => 'x']);
        $this->assertNotNull($note);
        $this->assertSame('Ok', $note->title);
    }
}
