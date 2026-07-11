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

    /**
     * Regressionsschutz: Das Lösch-Formular darf NICHT im Zuweisungs-Formular
     * verschachtelt sein. Verschachtelte <form>-Tags verwirft der Browser, dann
     * reagiert der "Ausgewählte löschen"-Button nicht (Bug aus Produktion).
     * HTTP-Tests treffen die Route direkt und übersehen das – daher hier die
     * Struktur des gerenderten HTML prüfen.
     */
    public function test_bulk_delete_form_is_not_nested_in_assign_form(): void
    {
        $this->customerWithRelations('nest@k.de');

        $html = $this->actingAs($this->admin)->get(route('admin.customers'))->getContent();

        $posAssignOpen = strpos($html, 'id="bulkForm"');
        $posDeleteOpen = strpos($html, 'id="bulkDeleteForm"');
        $this->assertNotFalse($posAssignOpen, 'bulkForm fehlt im HTML.');
        $this->assertNotFalse($posDeleteOpen, 'bulkDeleteForm fehlt im HTML.');

        // Das Zuweisungs-Formular muss geschlossen sein, BEVOR das Lösch-Formular
        // beginnt – sonst liegt eine (ungültige) Verschachtelung vor.
        $posAssignClose = strpos($html, '</form>', $posAssignOpen);
        $this->assertNotFalse($posAssignClose);
        $this->assertLessThan(
            $posDeleteOpen,
            $posAssignClose,
            'bulkForm umschließt bulkDeleteForm – verschachtelte Formulare machen den Löschen-Button funktionslos.'
        );

        // Der Löschen-Button muss explizit auf das Lösch-Formular verweisen.
        $this->assertStringContainsString('form="bulkDeleteForm"', $html);
    }

    public function test_customer_list_shows_address_active_contracts_and_actions_menu(): void
    {
        $c = $this->customerWithRelations('menu@k.de');
        $c->update([
            'address_street' => 'Rissener Dorfstr.',
            'address_house_number' => '51',
            'address_zip' => '22559',
            'address_city' => 'Hamburg',
        ]);

        $html = $this->actingAs($this->admin)->get(route('admin.customers'))->getContent();

        // Neue Spalten sind vorhanden
        $this->assertStringContainsString('Adresse', $html);
        $this->assertStringContainsString('Aktive Verträge', $html);
        $this->assertStringContainsString('Aktionen', $html);

        // Adresse wird formatiert angezeigt
        $this->assertStringContainsString('Rissener Dorfstr. 51, 22559 Hamburg', $html);

        // 3-Punkte-Menü: Merge-Link + Einzel-Löschung (nur admin)
        $this->assertStringContainsString('Dublette bereinigen', $html);
        $this->assertStringContainsString(route('admin.customer.merge', $c->id), $html);
        $this->assertStringContainsString(route('admin.customers.delete', $c->id), $html);
    }

    public function test_employee_does_not_see_single_delete_in_actions_menu(): void
    {
        $c = $this->customerWithRelations('empmenu@k.de');
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => true]);

        $html = $this->actingAs($employee)->get(route('admin.customers'))->getContent();

        // Merge (Dublette) darf der Mitarbeiter sehen, die Einzel-Löschung NICHT.
        // (Der Lösch-Button ist eindeutig an "🗑 Löschen" erkennbar; die Route-URL
        //  taugt nicht als Marker, da sie Präfix der Merge-URL ist.)
        $this->assertStringContainsString('Dublette bereinigen', $html);
        $this->assertStringNotContainsString('🗑 Löschen', $html);

        // Gegenprobe: der Admin sieht die Einzel-Löschung im Menü
        $adminHtml = $this->actingAs($this->admin)->get(route('admin.customers'))->getContent();
        $this->assertStringContainsString('🗑 Löschen', $adminHtml);
    }

    public function test_list_hides_kundennr_and_email_and_shows_birthdate_under_name(): void
    {
        $c = $this->customerWithRelations('birth@k.de');
        $c->update(['birth_date' => '1971-02-15']);

        $html = $this->actingAs($this->admin)->get(route('admin.customers'))->getContent();

        // Aufgeräumte Liste: Kunden-E-Mail und Kundennr. sind keine Spalten mehr
        $this->assertStringNotContainsString('birth@k.de', $html);
        $this->assertStringNotContainsString($c->customer_number, $html);

        // Geburtsdatum steht unter dem Namen (TT.MM.JJJJ)
        $this->assertStringContainsString('15.02.1971', $html);
    }

    public function test_bulk_delete_accepts_comma_separated_ids(): void
    {
        $c1 = $this->customerWithRelations('csv1@k.de');
        $c2 = $this->customerWithRelations('csv2@k.de');
        $keep = $this->customerWithRelations('csvkeep@k.de');

        // So sendet das Formular jetzt: ein einziges kommagetrenntes Feld
        $this->actingAs($this->admin)->post(route('admin.customers.bulk-delete'), [
            'customer_ids' => $c1->id . ',' . $c2->id,
        ])->assertRedirect(route('admin.customers'))
          ->assertSessionHas('success', '2 Kunde(n) endgültig gelöscht.');

        $this->assertDatabaseMissing('customers', ['id' => $c1->id]);
        $this->assertDatabaseMissing('customers', ['id' => $c2->id]);
        $this->assertDatabaseHas('customers', ['id' => $keep->id]);
    }

    public function test_full_address_formats_and_falls_back_to_legacy(): void
    {
        $c = $this->customerWithRelations('addr@k.de');
        $c->update([
            'address_street' => 'Hauptstr.', 'address_house_number' => '7',
            'address_house_suffix' => 'a', 'address_zip' => '10115', 'address_city' => 'Berlin',
        ]);
        $this->assertSame('Hauptstr. 7 a, 10115 Berlin', $c->fresh()->fullAddress());

        // Fallback auf Alt-Feld, wenn strukturierte Felder leer sind
        $c2 = $this->customerWithRelations('legacy@k.de');
        $c2->update(['address' => 'Altfeldweg 3, 50667 Köln']);
        $this->assertSame('Altfeldweg 3, 50667 Köln', $c2->fresh()->fullAddress());

        // Gleiche Anschrift -> gleicher Haushalts-Schlüssel
        $c3 = $this->customerWithRelations('same@k.de');
        $c3->update([
            'address_street' => 'Hauptstr.', 'address_house_number' => '7',
            'address_house_suffix' => 'a', 'address_zip' => '10115', 'address_city' => 'Berlin',
        ]);
        $this->assertSame($c->fresh()->householdKey(), $c3->fresh()->householdKey());
    }
}
