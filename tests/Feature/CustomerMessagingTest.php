<?php

namespace Tests\Feature;

use App\Mail\CustomerMessageMail;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\InternalNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Direktnachrichten Berater <-> Kunde (Portal-Chat): Versand, E-Mail-Modi,
 * Anhaenge (private Disk), Lese-Status, Zugriffsschutz.
 */
class CustomerMessagingTest extends TestCase
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

    private function makeAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_staff_sendet_nachricht_mit_hinweis_mail_und_glocke(): void
    {
        Mail::fake();
        $admin = $this->makeAdmin();
        $customer = $this->makeCustomer();

        $this->actingAs($admin)->post(route('admin.customer.messages.store', $customer->id), [
            'body' => 'Bitte rufen Sie uns zurueck.',
            'email_mode' => 'hint',
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_messages', [
            'customer_id' => $customer->id,
            'sender_id' => $admin->id,
            'from_staff' => true,
            'email_mode' => 'hint',
        ]);
        $this->assertDatabaseHas('internal_notifications', [
            'user_id' => $customer->user_id,
            'title' => '💬 Neue Nachricht',
        ]);
        Mail::assertSent(CustomerMessageMail::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->user->email) && $mail->mode === 'hint';
        });
    }

    public function test_email_modus_none_sendet_keine_mail(): void
    {
        Mail::fake();
        $admin = $this->makeAdmin();
        $customer = $this->makeCustomer();

        $this->actingAs($admin)->post(route('admin.customer.messages.store', $customer->id), [
            'body' => 'Nur im Portal.',
            'email_mode' => 'none',
        ])->assertRedirect();

        Mail::assertNothingSent();
        $this->assertDatabaseHas('internal_notifications', ['user_id' => $customer->user_id]);
    }

    public function test_platzhalter_email_erhaelt_keine_mail(): void
    {
        Mail::fake();
        $admin = $this->makeAdmin();
        $customer = $this->makeCustomer('import-123@dienstly24.internal');

        $this->actingAs($admin)->post(route('admin.customer.messages.store', $customer->id), [
            'body' => 'Test', 'email_mode' => 'full',
        ])->assertRedirect();

        Mail::assertNothingSent();
    }

    public function test_hint_mail_enthaelt_keinen_text_full_mail_schon(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeCustomer();
        $this->actingAs($admin);
        $message = CustomerMessage::create([
            'customer_id' => $customer->id, 'sender_id' => $admin->id,
            'body' => 'GEHEIMER-INHALT-XYZ', 'from_staff' => true,
        ]);

        $hint = (new CustomerMessageMail($message, 'hint'))->render();
        $full = (new CustomerMessageMail($message, 'full'))->render();

        $this->assertStringNotContainsString('GEHEIMER-INHALT-XYZ', $hint);
        $this->assertStringContainsString('GEHEIMER-INHALT-XYZ', $full);
        // Kundenlinks zeigen auf die Portal-Domain, nie auf admin.*
        $this->assertStringContainsString('portal.dienstly24.de', $full);
    }

    public function test_anhaenge_landen_auf_privater_disk(): void
    {
        Mail::fake();
        Storage::fake('local');
        $admin = $this->makeAdmin();
        $customer = $this->makeCustomer();

        $this->actingAs($admin)->post(route('admin.customer.messages.store', $customer->id), [
            'body' => 'Mit Anhang',
            'email_mode' => 'none',
            'attachments' => [UploadedFile::fake()->create('police.pdf', 100, 'application/pdf')],
        ])->assertRedirect();

        $attachment = \App\Models\CustomerMessageAttachment::firstOrFail();
        $this->assertSame('police.pdf', $attachment->file_name);
        $this->assertSame('local', $attachment->disk);
        $this->assertStringStartsWith('customers/' . $customer->id . '/messages/', $attachment->file_path);
        Storage::disk('local')->assertExists($attachment->file_path);
    }

    public function test_mitarbeiter_ohne_kundenzugriff_darf_nicht_senden(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $customer = $this->makeCustomer();

        $this->actingAs($employee)->post(route('admin.customer.messages.store', $customer->id), [
            'body' => 'Hallo', 'email_mode' => 'none',
        ])->assertForbidden();
    }

    public function test_zugewiesener_mitarbeiter_darf_senden(): void
    {
        Mail::fake();
        $employee = User::factory()->create(['role' => 'employee']);
        $customer = $this->makeCustomer();
        $customer->betreuer()->attach($employee->id);

        $this->actingAs($employee)->post(route('admin.customer.messages.store', $customer->id), [
            'body' => 'Hallo', 'email_mode' => 'none',
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_messages', ['customer_id' => $customer->id, 'sender_id' => $employee->id]);
    }

    public function test_kunde_sieht_nachrichten_und_lesestatus_wird_gesetzt(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeCustomer();
        $message = CustomerMessage::create([
            'customer_id' => $customer->id, 'sender_id' => $admin->id,
            'body' => 'Willkommen im Portal-Chat', 'from_staff' => true,
        ]);

        $this->actingAs($customer->user)->get(route('portal.messages'))
            ->assertOk()
            ->assertSee('Willkommen im Portal-Chat');

        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_kundenantwort_benachrichtigt_team(): void
    {
        $admin = $this->makeAdmin();
        $betreuer = User::factory()->create(['role' => 'employee']);
        $customer = $this->makeCustomer();
        $customer->betreuer()->attach($betreuer->id);

        $this->actingAs($customer->user)->post(route('portal.messages.store'), [
            'body' => 'Ich habe eine Frage zu meinem Vertrag.',
        ])->assertRedirect(route('portal.messages'));

        $this->assertDatabaseHas('customer_messages', [
            'customer_id' => $customer->id, 'from_staff' => false,
        ]);
        foreach ([$admin->id, $betreuer->id] as $userId) {
            $this->assertDatabaseHas('internal_notifications', [
                'user_id' => $userId, 'title' => '💬 Neue Kundennachricht',
            ]);
        }
    }

    public function test_kunde_kann_fremde_anhaenge_nicht_laden(): void
    {
        Storage::fake('local');
        $admin = $this->makeAdmin();
        $customerA = $this->makeCustomer('a@example.de');
        $customerB = $this->makeCustomer('b@example.de');
        $message = CustomerMessage::create([
            'customer_id' => $customerA->id, 'sender_id' => $admin->id,
            'body' => 'Anhang fuer A', 'from_staff' => true,
        ]);
        $attachment = \App\Models\CustomerMessageAttachment::create([
            'message_id' => $message->id, 'uploaded_by' => $admin->id,
            'file_name' => 'geheim.pdf', 'file_path' => 'customers/' . $customerA->id . '/messages/geheim.pdf',
            'disk' => 'local',
        ]);

        $this->actingAs($customerB->user)
            ->get(route('portal.messages.attachment', $attachment->id))
            ->assertNotFound();
    }

    public function test_staff_download_prueft_kundenzugriff(): void
    {
        $admin = $this->makeAdmin();
        $fremder = User::factory()->create(['role' => 'employee']);
        $customer = $this->makeCustomer();
        $message = CustomerMessage::create([
            'customer_id' => $customer->id, 'sender_id' => $admin->id,
            'body' => 'x', 'from_staff' => true,
        ]);
        $attachment = \App\Models\CustomerMessageAttachment::create([
            'message_id' => $message->id, 'uploaded_by' => $admin->id,
            'file_name' => 'x.pdf', 'file_path' => 'customers/' . $customer->id . '/messages/x.pdf',
            'disk' => 'local',
        ]);

        $this->actingAs($fremder)->get(route('admin.messages.attachment', $attachment->id))
            ->assertForbidden();
    }

    public function test_kundenakte_markiert_kundenantworten_als_gelesen(): void
    {
        $admin = $this->makeAdmin();
        $customer = $this->makeCustomer();
        $reply = CustomerMessage::create([
            'customer_id' => $customer->id, 'sender_id' => $customer->user_id,
            'body' => 'Kundenfrage', 'from_staff' => false,
        ]);

        $this->actingAs($admin)->get(route('admin.customer', $customer->id))->assertOk();

        $this->assertNotNull($reply->fresh()->read_at);
    }
}
