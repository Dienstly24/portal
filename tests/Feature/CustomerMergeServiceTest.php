<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerMessage;
use App\Models\Document;
use App\Models\ExternalReference;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Matching\CustomerMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $name, string $email, array $attrs = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);
        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($email . microtime()), 0, 8)),
        ], $attrs));
    }

    public function test_merge_moves_all_dependent_records_and_deletes_nothing(): void
    {
        $primary = $this->makeCustomer('Ahmad Albhre', 'ahmad@example.com');
        $duplicate = $this->makeCustomer('Ahmad Albhre', 'ahmad2@example.com', ['phone' => '030999']);

        // Duplikat bekommt Daten in verschiedenen Tabellen (mit CASCADE-FK!).
        $contract = Contract::create(['customer_id' => $duplicate->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);
        $document = Document::create(['customer_id' => $duplicate->id, 'category' => 'other', 'file_name' => 'd.pdf', 'file_path' => 'x/d.pdf', 'disk' => 'local', 'visibility' => 'customer']);
        $ticket = Ticket::create(['ticket_number' => 'T-1', 'customer_id' => $duplicate->id, 'type' => 'general', 'status' => 'open', 'subject' => 'Frage', 'description' => 'Text']);
        $consent = CustomerConsent::create(['customer_id' => $duplicate->id, 'type' => CustomerConsent::TYPE_EMAIL_PROCESSING, 'granted_at' => now(), 'consent_text_version' => CustomerConsent::EMAIL_TEXT_VERSION]);
        $message = CustomerMessage::create(['customer_id' => $duplicate->id, 'sender_id' => $primary->user_id, 'body' => 'Hallo', 'from_staff' => true]);
        $ref = ExternalReference::create(['referenceable_type' => Customer::class, 'referenceable_id' => $duplicate->id, 'type' => 'lexoffice', 'value' => 'LX-123', 'source' => 'lexoffice']);

        $dupUserId = $duplicate->user_id;

        $moved = app(CustomerMergeService::class)->merge($primary, $duplicate);

        // Alles am Hauptkunden, nichts verloren.
        $this->assertEquals($primary->id, $contract->fresh()->customer_id);
        $this->assertEquals($primary->id, $document->fresh()->customer_id);
        $this->assertEquals($primary->id, $ticket->fresh()->customer_id);
        $this->assertEquals($primary->id, $consent->fresh()->customer_id, 'DSGVO-Einwilligung darf beim Merge nicht verloren gehen');
        $this->assertEquals($primary->id, $message->fresh()->customer_id, 'Portal-Nachricht darf beim Merge nicht verloren gehen');
        $this->assertEquals($primary->id, $ref->fresh()->referenceable_id);

        // Duplikat-Huelle + verwaister User weg.
        $this->assertNull(Customer::find($duplicate->id));
        $this->assertNull(User::find($dupUserId));

        // Fehlendes Feld ergaenzt.
        $this->assertEquals('030999', $primary->fresh()->phone);

        // Zusammenfassung meldet uebertragene Datensaetze.
        $this->assertArrayHasKey('contracts', $moved);
        $this->assertArrayHasKey('customer_consents', $moved);
    }

    public function test_merge_deduplicates_shared_betreuer_assignment(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $primary = $this->makeCustomer('Klaus Weber', 'klaus1@example.com');
        $duplicate = $this->makeCustomer('Klaus Weber', 'klaus2@example.com');
        $primary->betreuer()->attach($employee->id);
        $duplicate->betreuer()->attach($employee->id);

        app(CustomerMergeService::class)->merge($primary, $duplicate);

        // Keine doppelte Betreuer-Zeile.
        $this->assertEquals(1, DB::table('employee_customers')
            ->where('customer_id', $primary->id)->where('user_id', $employee->id)->count());
    }

    public function test_merge_handles_unique_customer_id_tables_without_crashing(): void
    {
        // Reproduziert den HTTP-500-Fehler beim Zusammenfuehren: Sobald ein
        // Mitarbeiter BEIDE Kundenakten geoeffnet (customer_views) oder als
        // Favorit markiert (favorite_customers) hat, existieren fuer denselben
        // user_id zwei Zeilen. Beide Tabellen haben ein unique(user_id,
        // customer_id) - das blinde Umhaengen der customer_id verletzt dieses
        // UNIQUE und wirft eine QueryException (-> 500). Das ist der
        // Normalfall: man oeffnet erst beide Akten, um sie dann zu mergen.
        $staff = User::factory()->create(['role' => 'employee']);
        $primary = $this->makeCustomer('Sara Klein', 'sara1@example.com');
        $duplicate = $this->makeCustomer('Sara Klein', 'sara2@example.com');

        foreach (['customer_views', 'favorite_customers'] as $table) {
            DB::table($table)->insert(['user_id' => $staff->id, 'customer_id' => $primary->id]);
            DB::table($table)->insert(['user_id' => $staff->id, 'customer_id' => $duplicate->id]);
        }

        // Darf NICHT werfen (vorher: UNIQUE-Verletzung -> 500).
        app(CustomerMergeService::class)->merge($primary, $duplicate);

        // Kollidierende Duplikat-Zeile verworfen, genau eine Zeile bleibt.
        foreach (['customer_views', 'favorite_customers'] as $table) {
            $this->assertEquals(1, DB::table($table)
                ->where('user_id', $staff->id)->where('customer_id', $primary->id)->count(),
                "Genau eine {$table}-Zeile fuer den Hauptkunden erwartet");
            $this->assertEquals(0, DB::table($table)
                ->where('customer_id', $duplicate->id)->count(),
                "Keine {$table}-Zeile darf am geloeschten Duplikat haengen bleiben");
        }
    }

    public function test_merge_moves_non_colliding_unique_rows(): void
    {
        // Gegenprobe: Haben ZWEI verschiedene Mitarbeiter je nur EINE der
        // Akten geoeffnet, gibt es keine Kollision - beide Ansichten muessen
        // erhalten bleiben und auf den Hauptkunden zeigen.
        $staffA = User::factory()->create(['role' => 'employee']);
        $staffB = User::factory()->create(['role' => 'employee']);
        $primary = $this->makeCustomer('Tim Gross', 'tim1@example.com');
        $duplicate = $this->makeCustomer('Tim Gross', 'tim2@example.com');

        DB::table('customer_views')->insert(['user_id' => $staffA->id, 'customer_id' => $primary->id]);
        DB::table('customer_views')->insert(['user_id' => $staffB->id, 'customer_id' => $duplicate->id]);

        app(CustomerMergeService::class)->merge($primary, $duplicate);

        $this->assertEquals(2, DB::table('customer_views')
            ->where('customer_id', $primary->id)->count(),
            'Beide (nicht kollidierenden) Ansichten muessen auf den Hauptkunden zeigen');
    }

    public function test_merge_refuses_self_and_non_customer(): void
    {
        $primary = $this->makeCustomer('Solo Person', 'solo@example.com');
        $this->expectException(\InvalidArgumentException::class);
        app(CustomerMergeService::class)->merge($primary, $primary);
    }
}
