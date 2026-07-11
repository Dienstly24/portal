<?php

namespace Tests\Feature;

use App\Mail\DocumentRequestMail;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\InternalNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $customerUser;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->customerUser = User::factory()->create(['role' => 'customer']);
        $this->customer = Customer::create([
            'user_id' => $this->customerUser->id,
            'customer_number' => 'K-1001',
            'salutation' => 'herr',
        ]);
    }

    private function request(array $overrides = []): DocumentRequest
    {
        return DocumentRequest::create(array_merge([
            'customer_id' => $this->customer->id,
            'title' => 'Kopie des Personalausweises',
            'status' => 'open',
            'requested_by' => $this->admin->id,
        ], $overrides));
    }

    public function test_staff_creates_request_and_customer_is_notified(): void
    {
        $response = $this->actingAs($this->admin)->post(
            route('admin.document_requests.store', $this->customer->id),
            ['title' => 'Meldebescheinigung', 'description' => 'Bitte aktuell', 'deadline' => now()->addWeek()->format('Y-m-d')]
        );

        $response->assertRedirect();
        $this->assertDatabaseHas('document_requests', [
            'customer_id' => $this->customer->id,
            'title' => 'Meldebescheinigung',
            'status' => 'open',
        ]);
        Mail::assertSent(DocumentRequestMail::class, fn ($mail) => $mail->hasTo($this->customerUser->email));
    }

    public function test_contract_of_other_customer_cannot_be_referenced(): void
    {
        $otherUser = User::factory()->create(['role' => 'customer']);
        $other = Customer::create(['user_id' => $otherUser->id, 'customer_number' => 'K-1002']);
        $foreignContract = \App\Models\Contract::create([
            'customer_id' => $other->id, 'contract_number' => 'C-1', 'type' => 'andere', 'insurer' => 'X', 'status' => 'active',
        ]);

        $this->actingAs($this->admin)->post(
            route('admin.document_requests.store', $this->customer->id),
            ['title' => 'Test', 'contract_id' => (string) $foreignContract->id]
        )->assertStatus(422);
    }

    public function test_customer_sees_open_request_in_portal(): void
    {
        $this->request(['title' => 'Kopie des Personalausweises']);

        $this->actingAs($this->customerUser)->get(route('portal.documents'))
            ->assertOk()
            ->assertSee('Kopie des Personalausweises')
            ->assertSee('Offen – bitte hochladen');
    }

    public function test_customer_upload_marks_request_for_review_and_notifies_staff(): void
    {
        $documentRequest = $this->request();

        $response = $this->actingAs($this->customerUser)->post(
            route('portal.document_requests.upload', $documentRequest->id),
            ['document' => UploadedFile::fake()->create('ausweis.pdf', 200, 'application/pdf')]
        );

        $response->assertRedirect();
        $documentRequest->refresh();
        $this->assertSame('uploaded', $documentRequest->status);
        $this->assertNotNull($documentRequest->document_id);
        $this->assertNotNull($documentRequest->uploaded_at);

        $document = Document::find($documentRequest->document_id);
        $this->assertSame('local', $document->disk);
        Storage::disk('local')->assertExists($document->file_path);

        // Ohne Betreuer-Zuweisung werden admins/manager benachrichtigt.
        $this->assertTrue(InternalNotification::where('user_id', $this->admin->id)
            ->where('title', 'like', 'Dokument hochgeladen%')->exists());
    }

    public function test_customer_cannot_upload_to_foreign_request(): void
    {
        $otherUser = User::factory()->create(['role' => 'customer']);
        $other = Customer::create(['user_id' => $otherUser->id, 'customer_number' => 'K-1003']);
        $foreign = DocumentRequest::create([
            'customer_id' => $other->id, 'title' => 'Fremd', 'status' => 'open',
        ]);

        $this->actingAs($this->customerUser)->post(
            route('portal.document_requests.upload', $foreign->id),
            ['document' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]
        )->assertNotFound();
    }

    public function test_approve_closes_request(): void
    {
        $documentRequest = $this->request(['status' => 'uploaded', 'uploaded_at' => now()]);

        $this->actingAs($this->admin)->post(route('admin.document_requests.approve', $documentRequest->id));

        $documentRequest->refresh();
        $this->assertSame('approved', $documentRequest->status);
        $this->assertSame($this->admin->id, $documentRequest->reviewed_by);
    }

    public function test_reject_returns_request_to_customer_with_note_and_mail(): void
    {
        $documentRequest = $this->request(['status' => 'uploaded', 'uploaded_at' => now()]);

        $this->actingAs($this->admin)->post(
            route('admin.document_requests.reject', $documentRequest->id),
            ['rejection_note' => 'Bitte beide Seiten des Ausweises.']
        );

        $documentRequest->refresh();
        $this->assertSame('rejected', $documentRequest->status);
        $this->assertSame('Bitte beide Seiten des Ausweises.', $documentRequest->rejection_note);
        $this->assertTrue($documentRequest->acceptsUpload()); // Kunde darf erneut hochladen
        Mail::assertSent(DocumentRequestMail::class, fn ($mail) => $mail->hasTo($this->customerUser->email));
    }

    public function test_restricted_employee_cannot_create_request_for_unassigned_customer(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);

        $this->actingAs($employee)->post(
            route('admin.document_requests.store', $this->customer->id),
            ['title' => 'Test']
        )->assertForbidden();
    }
}
