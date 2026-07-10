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
}
