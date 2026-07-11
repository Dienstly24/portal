<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Document;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Mailbox\EmailAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin-Kundenverwaltung: Einzel- und Massen-Löschung (nur admin),
 * vollständige Bereinigung aller Beziehungen inkl. physischer Dateien.
 */
class CustomerBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /** Kunde mit ALLEN relevanten Beziehungen (Tickets, Mails, Anhänge, Notizen, Aufgaben, Verträge, Dokumente). */
    private function customerWithRelations(string $email): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => $email]);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'K-' . uniqid()]);

        Contract::create(['customer_id' => $customer->id, 'contract_number' => 'V-' . uniqid(), 'type' => 'kfz', 'insurer' => 'A', 'status' => 'active']);
        Ticket::forceCreate(['id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'type' => 'other', 'status' => 'open', 'priority' => 'mittel', 'subject' => 'T', 'description' => 'x']);
        CustomerNote::create(['customer_id' => $customer->id, 'created_by' => $this->admin->id, 'note' => 'N']);
        Task::forceCreate(['id' => (string) Str::uuid(), 'assigned_to' => $this->admin->id, 'created_by' => $this->admin->id, 'customer_id' => $customer->id, 'title' => 'A', 'type' => 'email', 'status' => 'open', 'priority' => 'medium']);

        Storage::disk('local')->put("customers/{$customer->id}/d.pdf", 'x');
        Document::create(['customer_id' => $customer->id, 'category' => 'other', 'file_name' => 'd.pdf', 'file_path' => "customers/{$customer->id}/d.pdf", 'disk' => 'local', 'visibility' => 'customer']);

        $account = EmailAccount::firstOrCreate(
            ['email_address' => 'info@dienstly24.de'],
            ['name' => 'I', 'provider' => 'imap', 'folders' => ['INBOX'], 'is_active' => true]
        );
        $mail = EmailMessage::create(['email_account_id' => $account->id, 'message_uid' => 'INBOX:' . uniqid(), 'from_address' => $email, 'subject' => 'M', 'match_status' => 'confirmed', 'customer_id' => $customer->id, 'processed_at' => now()]);
        app(EmailAttachmentService::class)->storeFiles($mail, [['filename' => 'a.pdf', 'mime' => 'application/pdf', 'content' => 'x']]);

        return $customer;
    }

    public function test_admin_can_bulk_delete_customers_with_all_relations(): void
    {
        $c1 = $this->customerWithRelations('c1@k.de');
        $c2 = $this->customerWithRelations('c2@k.de');
        $keep = $this->customerWithRelations('keep@k.de');
        $mailPath1 = EmailMessage::where('customer_id', $c1->id)->first()->attachments_meta[0]['path'];

        $response = $this->actingAs($this->admin)->post(route('admin.customers.bulk-delete'), [
            'customer_ids' => [(string) $c1->id, (string) $c2->id],
        ]);

        $response->assertRedirect(route('admin.customers'));
        $response->assertSessionHas('success', '2 Kunde(n) endgültig gelöscht.');

        foreach ([$c1, $c2] as $c) {
            $this->assertDatabaseMissing('customers', ['id' => $c->id]);
            $this->assertDatabaseMissing('users', ['id' => $c->user_id]);
            $this->assertDatabaseMissing('contracts', ['customer_id' => $c->id]);
            $this->assertDatabaseMissing('tickets', ['customer_id' => $c->id]);
            $this->assertDatabaseMissing('customer_notes', ['customer_id' => $c->id]);
            $this->assertDatabaseMissing('documents', ['customer_id' => $c->id]);
            $this->assertDatabaseMissing('email_messages', ['customer_id' => $c->id]);
            Storage::disk('local')->assertMissing("customers/{$c->id}/d.pdf");
        }
        Storage::disk('local')->assertMissing($mailPath1);

        // Nicht ausgewählter Kunde bleibt vollständig erhalten
        $this->assertDatabaseHas('customers', ['id' => $keep->id]);
        $this->assertDatabaseHas('contracts', ['customer_id' => $keep->id]);

        // Löschung ist im Aktivitätslog nachvollziehbar
        $this->assertSame(2, \App\Models\ActivityLog::where('action', 'customer_deleted')->count());
    }

    public function test_single_delete_still_works_for_admin(): void
    {
        $c = $this->customerWithRelations('single@k.de');

        $this->actingAs($this->admin)->delete(route('admin.customers.delete', $c->id))
            ->assertRedirect(route('admin.customers'));

        $this->assertDatabaseMissing('customers', ['id' => $c->id]);
        $this->assertDatabaseMissing('users', ['email' => 'single@k.de']);
    }

    public function test_employee_cannot_delete_single_or_bulk(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => true]);
        $manager = User::factory()->create(['role' => 'manager']);
        $c = $this->customerWithRelations('emp@k.de');

        // Einzel-Löschung: employee + manager geblockt (nur admin)
        $this->actingAs($employee)->delete(route('admin.customers.delete', $c->id))
            ->assertRedirect(route('admin.dashboard'));
        $this->actingAs($manager)->delete(route('admin.customers.delete', $c->id))
            ->assertRedirect(route('admin.dashboard'));

        // Massen-Löschung: ebenfalls geblockt
        $this->actingAs($employee)->post(route('admin.customers.bulk-delete'), [
            'customer_ids' => [(string) $c->id],
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertDatabaseHas('customers', ['id' => $c->id]);
    }

    public function test_customer_cannot_reach_delete_routes(): void
    {
        $c = $this->customerWithRelations('cust@k.de');

        $this->actingAs($c->user)->post(route('admin.customers.bulk-delete'), [
            'customer_ids' => [(string) $c->id],
        ])->assertRedirect(route('portal.dashboard'));

        $this->actingAs($c->user)->delete(route('admin.customers.delete', $c->id))
            ->assertRedirect(route('portal.dashboard'));

        $this->assertDatabaseHas('customers', ['id' => $c->id]);
    }

    public function test_bulk_delete_validates_input(): void
    {
        $this->actingAs($this->admin)->post(route('admin.customers.bulk-delete'), [
            'customer_ids' => [],
        ])->assertSessionHasErrors('customer_ids');

        $this->actingAs($this->admin)->post(route('admin.customers.bulk-delete'), [
            'customer_ids' => ['kein-uuid'],
        ])->assertSessionHasErrors('customer_ids.0');
    }

    public function test_customer_list_shows_portal_columns_and_admin_delete_button(): void
    {
        $this->customerWithRelations('list@k.de');

        $response = $this->actingAs($this->admin)->get(route('admin.customers'));
        $response->assertOk()
            ->assertSee('Portal')
            ->assertSee('1. Login')
            ->assertSee('Letzter Login')
            ->assertSee('Ausgewählte löschen');

        // Employee sieht den Lösch-Button NICHT
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => true]);
        $this->actingAs($employee)->get(route('admin.customers'))
            ->assertOk()
            ->assertDontSee('Ausgewählte löschen');
    }
}
