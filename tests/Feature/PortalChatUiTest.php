<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chat-UI im Kundenportal: JSON-Feed, AJAX-Versand, Messenger-Ansicht,
 * schwebendes Chat-Widget und Kontakt-Hero auf dem Dashboard.
 */
class PortalChatUiTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $email = 'kunde@example.de'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => $email, 'name' => 'Max Meyer']);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26' . str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ]);
    }

    private function staffMessage(Customer $customer, string $body = 'Hallo aus dem Team'): CustomerMessage
    {
        $admin = User::factory()->create(['role' => 'admin']);
        return CustomerMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => $admin->id,
            'body' => $body,
            'from_staff' => true,
        ]);
    }

    public function test_feed_liefert_verlauf_und_ungelesen_zaehler(): void
    {
        $customer = $this->makeCustomer();
        $this->staffMessage($customer);

        $this->actingAs($customer->user)->getJson(route('portal.messages.feed'))
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('messages.0.body', 'Hallo aus dem Team')
            ->assertJsonPath('messages.0.from_staff', true)
            ->assertJsonPath('messages.0.day', 'Heute')
            ->assertJsonPath('messages.0.read', false);
    }

    public function test_feed_ohne_mark_read_laesst_lesestatus_unveraendert(): void
    {
        $customer = $this->makeCustomer();
        $message = $this->staffMessage($customer);

        $this->actingAs($customer->user)->getJson(route('portal.messages.feed'));

        $this->assertNull($message->fresh()->read_at);
    }

    public function test_feed_mit_mark_read_markiert_beraternachrichten_gelesen(): void
    {
        $customer = $this->makeCustomer();
        $message = $this->staffMessage($customer);

        $this->actingAs($customer->user)
            ->getJson(route('portal.messages.feed') . '?mark_read=1')
            ->assertOk()
            ->assertJsonPath('unread', 0);

        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_feed_ist_auf_den_eigenen_kunden_gescoped(): void
    {
        $customerA = $this->makeCustomer('a@example.de');
        $customerB = $this->makeCustomer('b@example.de');
        $this->staffMessage($customerA, 'Nur fuer A');

        $this->actingAs($customerB->user)->getJson(route('portal.messages.feed'))
            ->assertOk()
            ->assertJsonPath('unread', 0)
            ->assertJsonCount(0, 'messages');
    }

    public function test_ajax_versand_liefert_nachricht_als_json(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)
            ->postJson(route('portal.messages.store'), ['body' => 'Frage zum Vertrag'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message.body', 'Frage zum Vertrag')
            ->assertJsonPath('message.from_staff', false)
            ->assertJsonPath('message.read', false);

        $this->assertDatabaseHas('customer_messages', [
            'customer_id' => $customer->id,
            'from_staff' => false,
            'body' => 'Frage zum Vertrag',
        ]);
    }

    public function test_klassischer_versand_leitet_weiter_wie_bisher(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)
            ->post(route('portal.messages.store'), ['body' => 'Ohne JS gesendet'])
            ->assertRedirect(route('portal.messages'));
    }

    public function test_nachrichten_seite_rendert_chat_ansicht(): void
    {
        $customer = $this->makeCustomer();
        $message = $this->staffMessage($customer, 'Willkommen im Chat');

        $response = $this->actingAs($customer->user)->get(route('portal.messages'));

        $response->assertOk()
            ->assertSee('chatpage', false)
            ->assertSee('data-mid="' . $message->id . '"', false)
            ->assertSee('Willkommen im Chat')
            ->assertSee(__('Ihr Dienstly24 Team'))
            ->assertSee(__('Anfrage stellen'));
    }

    public function test_anhaenge_haben_vorschau_attribute_und_partial_ist_eingebunden(): void
    {
        $customer = $this->makeCustomer();
        $message = $this->staffMessage($customer, 'Mit Anhang');
        $attachment = \App\Models\CustomerMessageAttachment::create([
            'message_id' => $message->id,
            'uploaded_by' => $message->sender_id,
            'file_name' => 'police.pdf',
            'file_path' => 'customers/' . $customer->id . '/messages/police.pdf',
            'disk' => 'local',
        ]);

        $this->actingAs($customer->user)->get(route('portal.messages'))
            ->assertOk()
            ->assertSee('docpv-quicklook', false)
            ->assertSee('data-preview-url="' . route('portal.messages.attachment.view', $attachment->id) . '"', false)
            ->assertSee('data-preview-kind="pdf"', false);
    }

    public function test_chat_widget_erscheint_im_portal_aber_nicht_im_chat(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('id="cw-fab"', false);

        $this->actingAs($customer->user)->get(route('portal.messages'))
            ->assertOk()
            ->assertDontSee('id="cw-fab"', false);
    }

    public function test_dashboard_zeigt_kontakt_hero_mit_ungelesen_badge(): void
    {
        $customer = $this->makeCustomer();
        $this->staffMessage($customer);

        $this->actingAs($customer->user)->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee(__('Wie können wir Ihnen helfen?'))
            ->assertSee(__('Chat starten'))
            ->assertSee('hero-badge', false);
    }
}
