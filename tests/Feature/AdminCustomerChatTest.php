<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zentraler Kunden-Chat der Beraterwelt: Unterhaltungsliste, Feed,
 * JSON-Versand, Lesestatus und Portfolio-Scoping.
 */
class AdminCustomerChatTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $email = 'kunde@example.de', string $name = 'Max Meyer'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => $email, 'name' => $name]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26' . str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ]);
    }

    private function customerReply(Customer $customer, string $body = 'Ich habe eine Frage.'): CustomerMessage
    {
        return CustomerMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => $customer->user_id,
            'body' => $body,
            'from_staff' => false,
        ]);
    }

    public function test_admin_sieht_unterhaltung_mit_ungelesen_badge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $this->customerReply($customer, 'Frage zur KFZ-Police');

        $this->actingAs($admin)->get(route('admin.customer_chat'))
            ->assertOk()
            ->assertSee('Kunden-Chat')
            ->assertSee('Max Meyer')
            ->assertSee('Frage zur KFZ-Police')
            ->assertSee('kchat-unread', false);
    }

    public function test_liste_zeigt_die_neueste_nachricht_als_snippet(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $alt = $this->customerReply($customer, 'Alte Nachricht');
        $alt->created_at = now()->subHours(3);
        $alt->save();
        $this->customerReply($customer, 'Neueste Nachricht');

        $this->actingAs($admin)->get(route('admin.customer_chat'))
            ->assertOk()
            ->assertSee('Neueste Nachricht')
            ->assertDontSee('Alte Nachricht');
    }

    public function test_employee_sieht_nur_zugewiesene_unterhaltungen(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $mine = $this->makeCustomer('mein@example.de', 'Mein Kunde');
        $foreign = $this->makeCustomer('fremd@example.de', 'Fremder Kunde');
        $mine->betreuer()->attach($employee->id);
        $this->customerReply($mine);
        $this->customerReply($foreign);

        $this->actingAs($employee)->get(route('admin.customer_chat'))
            ->assertOk()
            ->assertSee('Mein Kunde')
            ->assertDontSee('Fremder Kunde');
    }

    public function test_employee_ohne_zugriff_bekommt_403_fuer_thread_und_feed(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $foreign = $this->makeCustomer();
        $this->customerReply($foreign);

        $this->actingAs($employee)
            ->get(route('admin.customer_chat', ['kunde' => (string) $foreign->id]))
            ->assertForbidden();
        $this->actingAs($employee)
            ->getJson(route('admin.customer_chat.feed', $foreign->id))
            ->assertForbidden();
    }

    public function test_thread_oeffnen_markiert_kundenantworten_gelesen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $reply = $this->customerReply($customer);

        $this->actingAs($admin)
            ->get(route('admin.customer_chat', ['kunde' => (string) $customer->id]))
            ->assertOk()
            ->assertSee('data-mid="' . $reply->id . '"', false);

        $this->assertNotNull($reply->fresh()->read_at);
    }

    public function test_anhaenge_im_thread_haben_vorschau_attribute(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $reply = $this->customerReply($customer, 'Mit Foto');
        $attachment = \App\Models\CustomerMessageAttachment::create([
            'message_id' => $reply->id,
            'uploaded_by' => $customer->user_id,
            'file_name' => 'schaden.jpg',
            'file_path' => 'customers/' . $customer->id . '/messages/schaden.jpg',
            'disk' => 'local',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customer_chat', ['kunde' => (string) $customer->id]))
            ->assertOk()
            ->assertSee('docpv-quicklook', false)
            ->assertSee('data-preview-url="' . route('admin.messages.attachment.view', $attachment->id) . '"', false)
            ->assertSee('data-preview-kind="image"', false);
    }

    public function test_feed_liefert_staff_perspektive(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $this->customerReply($customer, 'Kundenfrage');
        CustomerMessage::create([
            'customer_id' => $customer->id, 'sender_id' => $admin->id,
            'body' => 'Antwort vom Team', 'from_staff' => true,
        ]);

        $this->actingAs($admin)->getJson(route('admin.customer_chat.feed', $customer->id))
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('messages.0.own', false)
            ->assertJsonPath('messages.0.sender', 'Max Meyer')
            ->assertJsonPath('messages.1.own', true)
            ->assertJsonPath('messages.1.show_sender', true);
    }

    public function test_feed_mark_read_setzt_lesestatus(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $reply = $this->customerReply($customer);

        $this->actingAs($admin)
            ->getJson(route('admin.customer_chat.feed', $customer->id) . '?mark_read=1')
            ->assertOk()
            ->assertJsonPath('unread', 0);

        $this->assertNotNull($reply->fresh()->read_at);
    }

    public function test_staff_json_versand_liefert_nachricht_und_benachrichtigt_kunden(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        $this->actingAs($admin)
            ->postJson(route('admin.customer.messages.store', $customer->id), [
                'body' => 'Hallo aus dem Kunden-Chat',
                'email_mode' => 'none',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message.own', true)
            ->assertJsonPath('message.body', 'Hallo aus dem Kunden-Chat');

        $this->assertDatabaseHas('customer_messages', [
            'customer_id' => $customer->id, 'from_staff' => true,
        ]);
        $this->assertDatabaseHas('internal_notifications', [
            'user_id' => $customer->user_id, 'title' => '💬 Neue Nachricht',
        ]);
    }

    public function test_suche_findet_kunden_ohne_nachrichten(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeCustomer('neu@example.de', 'Nadia Neu');

        $this->actingAs($admin)->get(route('admin.customer_chat', ['q' => 'Nadia']))
            ->assertOk()
            ->assertSee('Nadia Neu')
            ->assertSee('Unterhaltung starten');
    }

    public function test_sidebar_zeigt_kunden_chat_mit_badge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();
        $this->customerReply($customer);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Kunden-Chat')
            ->assertSee(route('admin.customer_chat'), false);
    }
}
