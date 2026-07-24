<?php

namespace Tests\Feature;

use App\Models\CustomerChangeRequest;
use App\Models\Customer;
use App\Models\Document;
use App\Models\InternalNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalReviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);
        return Customer::create(['user_id' => $user->id, 'customer_number' => 'C-' . strtoupper(substr(md5((string)$user->id),0,6))]);
    }

    // Punkt 2: Familienmitglied mit Geschlecht + Detailfeldern
    public function test_family_member_request_carries_gender_and_details(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.family.store'), [
            'name' => 'Kind Eins', 'relation' => 'kind', 'gender' => 'female',
            'birth_place' => 'Hamburg', 'health_insurance_number' => 'A123',
            'pension_insurance_number' => 'R456', 'tax_id' => '12345678901',
        ])->assertSessionHas('success');

        $cr = CustomerChangeRequest::where('type', 'family')->first();
        $this->assertSame('female', $cr->new_data['gender']);
        $this->assertSame('Hamburg', $cr->new_data['birth_place']);

        // Nach Genehmigung landen die Felder am Familienmitglied
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $cr->id), ['action' => 'approve']);
        $this->assertDatabaseHas('customer_family', ['name' => 'Kind Eins', 'gender' => 'female', 'birth_place' => 'Hamburg']);
    }

    // Punkt 5/6: strukturierte Adresse + neue Felder als Profil-Request
    public function test_structured_address_and_new_fields_create_profile_request(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.profile.update'), [
            'address_street' => 'Musterstraße', 'address_house_number' => '20', 'address_house_suffix' => 'a',
            'address_zip' => '20095', 'address_city' => 'Hamburg', 'birth_place' => 'Bremen', 'tax_id' => '99887766554',
        ])->assertSessionHas('success');

        $cr = CustomerChangeRequest::where('type', 'profile')->first();
        $this->assertSame('Musterstraße', $cr->new_data['address_street']);
        $this->assertSame('20', $cr->new_data['address_house_number']);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $cr->id), ['action' => 'approve']);
        $customer->refresh();
        $this->assertSame('Musterstraße', $customer->address_street);
        $this->assertSame('20', $customer->address_house_number);
        $this->assertSame('Bremen', $customer->birth_place);
    }

    // Pflicht-Stammdaten: Name, E-Mail und Geburtsdatum aus dem Portal
    // laufen ueber den Aenderungsantrag und landen nach Freigabe am User bzw.
    // am Kunden.
    public function test_name_email_and_birthdate_from_portal_update_user_after_approval(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Alt Name', 'email' => 'alt@example.com']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'C-NAME01']);

        $this->actingAs($user)->post(route('portal.profile.update'), [
            'first_name' => 'Neu', 'last_name' => 'Name', 'email' => 'neu@example.com',
            'birth_date' => '1990-05-17', 'birth_place' => 'Aleppo', 'nationality' => 'Syrisch',
        ])->assertSessionHas('success');

        $cr = CustomerChangeRequest::where('type', 'profile')->firstOrFail();
        $this->assertSame('Neu', $cr->new_data['first_name']);
        $this->assertSame('neu@example.com', $cr->new_data['email']);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $cr->id), ['action' => 'approve']);

        $user->refresh();
        $customer->refresh();
        $this->assertSame('Neu Name', $user->name);
        $this->assertSame('neu@example.com', $user->email);
        $this->assertSame('1990-05-17', (string) $customer->birth_date);
        $this->assertSame('Aleppo', $customer->birth_place);
        $this->assertSame('Syrisch', $customer->nationality);
    }

    // Pflichtfeld darf nicht LEER eingereicht werden (serverseitige Absicherung
    // zusaetzlich zum HTML-required), Teil-Updates ohne das Feld bleiben moeglich.
    public function test_empty_required_profile_field_is_rejected(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.profile.update'), [
            'birth_place' => '', 'nationality' => 'Deutsch',
        ])->assertSessionHasErrors('birth_place');

        $this->assertSame(0, CustomerChangeRequest::where('type', 'profile')->count());
    }

    // Punkt 9: mehrere unabhängige Change Requests gleichzeitig
    public function test_customer_can_create_multiple_independent_requests(): void
    {
        $customer = $this->makeCustomer();
        // Bank + Profil in einem Submit -> zwei getrennte Requests
        $this->actingAs($customer->user)->post(route('portal.profile.update'), [
            'iban' => 'DE89370400440532013000', 'address_city' => 'Köln',
        ]);
        // Weitere Änderung, obwohl die erste noch pending ist -> nicht blockiert
        $this->actingAs($customer->user)->post(route('portal.profile.update'), [
            'iban' => 'DE12500105170648489890',
        ])->assertSessionHas('success');

        $this->assertSame(2, CustomerChangeRequest::where('type', 'bank')->count());
        $this->assertSame(1, CustomerChangeRequest::where('type', 'profile')->count());
        $this->assertSame(3, CustomerChangeRequest::where('status', 'pending')->count());
    }

    // Punkt 7: Kunde lädt Dokument hoch -> gehört ihm, Admin wird benachrichtigt
    public function test_customer_can_upload_document_and_staff_is_notified(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.documents.upload'), [
            'document' => UploadedFile::fake()->create('police.pdf', 200, 'application/pdf'),
            'category' => 'police',
        ])->assertSessionHas('success');

        $doc = Document::first();
        $this->assertSame((string)$customer->id, (string)$doc->customer_id);
        $this->assertSame('local', $doc->disk);
        $this->assertSame('customer', $doc->visibility);
        Storage::disk('local')->assertExists($doc->file_path);
        Storage::disk('public')->assertMissing($doc->file_path);
        $this->assertDatabaseHas('internal_notifications', ['user_id' => $admin->id, 'title' => 'Neues Kundendokument']);
    }

    // Punkt 7: hochgeladenes Dokument ist im Portal des Kunden sichtbar
    public function test_uploaded_document_appears_in_customer_portal(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.documents.upload'), [
            'document' => UploadedFile::fake()->create('meins.pdf', 50, 'application/pdf'),
            'category' => 'other',
        ]);

        $this->actingAs($customer->user)->get(route('portal.documents'))
            ->assertOk()->assertSee('meins.pdf');
    }

    // Punkt 3: Dashboard-Kacheln sind echte Links
    public function test_dashboard_tiles_link_to_sections(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee(route('portal.contracts'))
            ->assertSee(route('portal.documents'))
            ->assertSee(route('portal.profile'));
    }

    // Punkt 8: Kunde erhält Statusmeldung bei Entscheidung
    public function test_customer_is_notified_when_request_is_decided(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.profile.update'), ['iban' => 'DE89370400440532013000']);
        $cr = CustomerChangeRequest::first();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $cr->id), ['action' => 'approve']);

        $this->assertDatabaseHas('internal_notifications', [
            'user_id' => $customer->user_id,
            'title' => 'Änderungsanfrage genehmigt',
        ]);

        // Kunde sieht die Meldung in seiner Portal-Glocke
        $this->actingAs($customer->user)->get(route('portal.notifications'))
            ->assertOk()->assertJsonFragment(['title' => 'Änderungsanfrage genehmigt']);
    }

    // Punkt 10: Admin-Antwort erzeugt Portal-Notification, Mail ohne Details
    public function test_admin_ticket_reply_notifies_customer_without_details(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $customer = $this->makeCustomer();
        $ticket = \App\Models\Ticket::create([
            'customer_id' => $customer->id, 'type' => 'other', 'status' => 'open',
            'subject' => 'Meine Frage', 'description' => 'Text',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.ticket.reply', $ticket->id), [
            'body' => 'GEHEIME-ANTWORT-DETAILS', 'status' => 'open',
        ]);

        $this->assertDatabaseHas('internal_notifications', [
            'user_id' => $customer->user_id, 'title' => 'Neue Nachricht',
        ]);

        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\TicketReplyMail::class, function ($mail) {
            $html = $mail->render();
            return str_contains($html, 'Sie haben eine neue Nachricht im Kundenportal')
                && !str_contains($html, 'GEHEIME-ANTWORT-DETAILS');
        });
    }

    // Punkt 11/12: Vertrag als Karte -> Detailseite mit erweiterten Feldern
    public function test_contract_detail_page_shows_extended_fields(): void
    {
        $customer = $this->makeCustomer();
        $contract = \App\Models\Contract::create([
            'customer_id' => $customer->id, 'type' => 'strom_gas', 'insurer' => 'Stadtwerke',
            'status' => 'active', 'contract_number' => 'S-1', 'end_date' => now()->addYear(),
            'cancellation_date' => now()->addMonths(9),
        ]);
        \App\Models\ContractEnergyDetail::create([
            'contract_id' => $contract->id, 'meter_number' => 'Z-999', 'malo_id' => '12345678901',
            'payment_amount' => 89.50, 'payment_interval' => 'monatlich',
        ]);

        // Übersicht zeigt Karte mit Link
        $this->actingAs($customer->user)->get(route('portal.contracts'))
            ->assertOk()->assertSee(route('portal.contracts.show', $contract->id));

        // Detailseite zeigt Kündigungsdatum + Abschlag + Intervall
        $this->actingAs($customer->user)->get(route('portal.contracts.show', $contract->id))
            ->assertOk()
            ->assertSee('Stadtwerke')
            ->assertSee('Z-999')
            ->assertSee('89,50')
            ->assertSee('Monatlich');
    }

    public function test_customer_cannot_view_foreign_contract_detail(): void
    {
        $owner = $this->makeCustomer();
        $contract = \App\Models\Contract::create([
            'customer_id' => $owner->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active', 'contract_number' => 'K-9',
        ]);
        $attacker = $this->makeCustomer();
        $this->actingAs($attacker->user)->get(route('portal.contracts.show', $contract->id))->assertNotFound();
    }
}