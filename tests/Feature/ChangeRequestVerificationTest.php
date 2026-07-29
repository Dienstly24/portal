<?php

namespace Tests\Feature;

use App\Models\ChangeNotification;
use App\Models\ChangeRequestDocument;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\CustomerMessage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ChangeRequest\ChangeProofVerifier;
use App\Services\ChangeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Nachweispflicht, automatische Pruefung und Mitteilungen an die
 * Gesellschaften (Betreiber-Vorgabe 29.07.2026).
 */
class ChangeRequestVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeCustomer(array $attributes = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Mohammad Alshaikh']);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => '2600001',
            'iban' => 'DE00ALTALTALTALTALT00',
        ], $attributes));
    }

    private function proof(string $name = 'nachweis.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 60, 'application/pdf');
    }

    // ------------------------------------------------------------------
    // Nachweispflicht im Portal
    // ------------------------------------------------------------------

    public function test_bank_change_without_proof_is_rejected(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000',
            'account_holder' => 'Mohammad Alshaikh',
        ])->assertSessionHasErrors('bank_proof');

        $this->assertSame(0, CustomerChangeRequest::count());
    }

    public function test_address_change_without_proof_is_rejected(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.addresses.store'), [
            'type' => 'main', 'street' => 'Musterweg 5', 'zip' => '20095', 'city' => 'Hamburg',
        ])->assertSessionHasErrors('proof');

        $this->assertSame(0, CustomerChangeRequest::count());
    }

    public function test_bank_change_with_proof_is_stored_privately_with_effective_date(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000',
            'account_holder' => 'Mohammad Alshaikh',
            'effective_from' => '2026-08-01',
            'bank_proof' => $this->proof('bankkarte.pdf'),
            'id_front' => $this->proof('ausweis-vorne.pdf'),
        ])->assertSessionHas('success');

        $request = CustomerChangeRequest::firstOrFail();
        $this->assertSame('bank', $request->type);
        $this->assertSame('2026-08-01', $request->effective_from->toDateString());
        $this->assertSame(2, $request->documents()->count());

        // Nachweise liegen auf der privaten Disk im Kundenverzeichnis
        $document = $request->documents()->where('kind', 'bank_proof')->firstOrFail();
        $this->assertSame('local', $document->disk);
        $this->assertStringStartsWith('customers/' . $customer->id . '/nachweise', $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_unreadable_proof_never_approves_automatically(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000',
            'account_holder' => 'Mohammad Alshaikh',
            'bank_proof' => $this->proof(),
        ]);

        $request = CustomerChangeRequest::firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertNotSame('verified', $request->proof_status);
        $this->assertSame('DE00ALTALTALTALTALT00', $customer->fresh()->iban);
    }

    // ------------------------------------------------------------------
    // Automatischer Abgleich Dokument <-> beantragte Angaben
    // ------------------------------------------------------------------

    /** Der Verifier arbeitet auf dem Dokumenttext - hier direkt eingespeist. */
    private function verifyWithText(CustomerChangeRequest $request, string $text): array
    {
        $extractor = new class($text) implements \App\Services\Ocr\TextExtractorInterface {
            public function __construct(private string $text) {}
            public function isAvailable(): bool { return true; }
            public function extract(string $binary, string $mime): string { return $this->text; }
        };
        $pdfText = new class extends \App\Services\Ocr\PdfTextLayerExtractor {
            public function isAvailable(): bool { return false; }
        };

        $verifier = new ChangeProofVerifier($extractor, $pdfText, app(\App\Services\ChangeRequest\ChangeProofPolicy::class));
        return $verifier->verify($request->fresh());
    }

    private function bankRequest(Customer $customer, string $iban = 'DE89370400440532013000'): CustomerChangeRequest
    {
        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => $iban,
            'account_holder' => 'Mohammad Alshaikh',
            'bank_proof' => $this->proof('bankkarte.jpg'),
        ]);
        return CustomerChangeRequest::where('type', 'bank')->latest()->firstOrFail();
    }

    public function test_matching_iban_and_holder_are_recognised(): void
    {
        $customer = $this->makeCustomer();
        $request = $this->bankRequest($customer);

        $result = $this->verifyWithText($request, "Sparkasse Hamburg\nKontoinhaber: Mohammad Alshaikh\nIBAN DE89 3704 0044 0532 0130 00\n");

        $this->assertSame('verified', $result['status']);
        $checks = collect($result['checks'])->keyBy('key');
        $this->assertTrue($checks['iban']['passed']);
        $this->assertTrue($checks['account_holder']['passed']);
    }

    public function test_foreign_iban_in_document_is_reported_as_mismatch(): void
    {
        $customer = $this->makeCustomer();
        $request = $this->bankRequest($customer);

        $result = $this->verifyWithText($request, "Kontoinhaber: Mohammad Alshaikh\nIBAN DE12 5001 0517 0648 4898 90\n");

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame('mismatch', $request->fresh()->proof_status);
    }

    public function test_ocr_confusions_in_the_iban_still_match(): void
    {
        $customer = $this->makeCustomer();
        $request = $this->bankRequest($customer);

        // OCR liest 0 als O und 1 als I - dieselbe IBAN, andere Zeichen
        $result = $this->verifyWithText($request, "IBAN DE89 37O4 OO44 O532 OI3O OO\nMohammad Alshaikh\n");

        $this->assertSame('verified', $result['status']);
        $this->assertTrue(collect($result['checks'])->firstWhere('key', 'iban')['tolerant']);
    }

    public function test_address_proof_matches_street_zip_and_city(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.addresses.store'), [
            'type' => 'main', 'street' => 'Musterstraße 12', 'zip' => '20095', 'city' => 'Hamburg',
            'proof' => $this->proof('meldebescheinigung.jpg'), 'proof_kind' => 'meldebescheinigung',
        ]);
        $request = CustomerChangeRequest::where('type', 'address')->firstOrFail();

        $result = $this->verifyWithText(
            $request,
            "Meldebescheinigung\nMohammad Alshaikh\nMusterstr. 12\n20095 Hamburg\n"
        );

        $this->assertSame('verified', $result['status']);
    }

    public function test_address_proof_with_other_city_fails(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.addresses.store'), [
            'type' => 'main', 'street' => 'Musterstraße 12', 'zip' => '20095', 'city' => 'Hamburg',
            'proof' => $this->proof('meldebescheinigung.jpg'),
        ]);
        $request = CustomerChangeRequest::where('type', 'address')->firstOrFail();

        $result = $this->verifyWithText($request, "Meldebescheinigung\nMohammad Alshaikh\nAndere Gasse 3\n50667 Köln\n");

        $this->assertContains($result['status'], ['mismatch', 'partial']);
        $this->assertNotSame('approved', $request->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Automatische Freigabe (Einstellung)
    // ------------------------------------------------------------------

    public function test_verified_address_is_approved_automatically_but_bank_is_not(): void
    {
        $policy = app(\App\Services\ChangeRequest\ChangeProofPolicy::class);
        $this->assertSame('address', $policy->autoApproveMode(), 'Standard: Adresse automatisch, Bank manuell.');

        $customer = $this->makeCustomer();

        // Adresse: geprueft -> automatisch uebernommen
        $this->actingAs($customer->user)->post(route('portal.addresses.store'), [
            'type' => 'main', 'street' => 'Musterstraße 12', 'zip' => '20095', 'city' => 'Hamburg',
            'proof' => $this->proof('meldebescheinigung.jpg'),
        ]);
        $address = CustomerChangeRequest::where('type', 'address')->firstOrFail();
        $this->verifyWithText($address, "Mohammad Alshaikh\nMusterstr. 12\n20095 Hamburg\n");
        app(\App\Jobs\VerifyChangeRequestProofJob::class, ['changeRequestId' => (string) $address->id]);

        // Bank: geprueft, aber Geldfluss -> bleibt beim Vier-Augen-Prinzip
        $bank = $this->bankRequest($customer);
        $this->verifyWithText($bank, "Kontoinhaber Mohammad Alshaikh IBAN DE89370400440532013000");
        $this->assertFalse($policy->autoApproveAllowed($bank->fresh()));
        $this->assertTrue($policy->autoApproveAllowed($address->fresh()));
    }

    public function test_auto_approval_can_be_switched_off_completely(): void
    {
        SystemSetting::set('change_request_auto_approve', 'off');
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->post(route('portal.addresses.store'), [
            'type' => 'main', 'street' => 'Musterstraße 12', 'zip' => '20095', 'city' => 'Hamburg',
            'proof' => $this->proof('meldebescheinigung.jpg'),
        ]);
        $request = CustomerChangeRequest::where('type', 'address')->firstOrFail();
        $this->verifyWithText($request, "Mohammad Alshaikh\nMusterstr. 12\n20095 Hamburg\n");

        $this->assertFalse(app(\App\Services\ChangeRequest\ChangeProofPolicy::class)->autoApproveAllowed($request->fresh()));
    }

    // ------------------------------------------------------------------
    // Mitteilungen an die Gesellschaften
    // ------------------------------------------------------------------

    public function test_approval_prepares_one_notification_per_insurer(): void
    {
        $customer = $this->makeCustomer(['birth_date' => '1990-05-17']);
        Contract::create(['customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK-Coburg', 'status' => 'active', 'contract_number' => 'KFZ-1']);
        Contract::create(['customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK-Coburg', 'status' => 'active', 'contract_number' => 'KFZ-2']);
        Contract::create(['customer_id' => $customer->id, 'type' => 'krankenversicherung', 'insurer' => 'TK', 'status' => 'active', 'contract_number' => 'KV-1']);
        // Beendeter Vertrag wird NICHT informiert
        Contract::create(['customer_id' => $customer->id, 'type' => 'hausrat', 'insurer' => 'Alt AG', 'status' => 'cancelled', 'contract_number' => 'HR-1']);

        $request = $this->bankRequest($customer);
        $request->update(['effective_from' => '2026-08-01']);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $request->id), ['action' => 'approve'])
            ->assertRedirect(route('admin.change_requests.notifications', $request->id));

        $notifications = ChangeNotification::all();
        $this->assertSame(2, $notifications->count());
        $this->assertEqualsCanonicalizing(['HUK-Coburg', 'TK'], $notifications->pluck('insurer')->all());

        $huk = $notifications->firstWhere('insurer', 'HUK-Coburg');
        $this->assertStringContainsString('KFZ-1', $huk->contract_numbers);
        $this->assertStringContainsString('KFZ-2', $huk->contract_numbers);
        $this->assertStringContainsString('DE89 3704 0044 0532 0130 00', $huk->body);
        $this->assertStringContainsString('01.08.2026', $huk->body);
        $this->assertStringContainsString('Mohammad Alshaikh', $huk->body);
        $this->assertSame('pending', $huk->status);
    }

    public function test_notification_can_be_sent_with_proof_attached(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $customer = $this->makeCustomer();
        Contract::create(['customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active', 'contract_number' => 'KFZ-9']);

        $request = $this->bankRequest($customer);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $request->id), ['action' => 'approve']);

        $notification = ChangeNotification::firstOrFail();
        $this->actingAs($admin)->post(route('admin.change_notifications.send', $notification->id), [
            'recipient' => 'service@huk.de',
            'subject' => $notification->subject,
            'body' => $notification->body,
            'attach_proof' => 1,
        ])->assertSessionHas('success');

        $notification->refresh();
        $this->assertSame('sent', $notification->status);
        $this->assertSame('service@huk.de', $notification->recipient);
        $this->assertSame($admin->id, $notification->sent_by);
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\DirectEmailMail::class);

        // Versand steht in der Kundenakte
        $this->assertDatabaseHas('customer_timeline', [
            'customer_id' => (string) $customer->id,
            'title' => 'Gesellschaft informiert: HUK',
        ]);
    }

    public function test_sent_notification_cannot_be_sent_twice(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $customer = $this->makeCustomer();
        Contract::create(['customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active', 'contract_number' => 'KFZ-8']);
        $request = $this->bankRequest($customer);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $request->id), ['action' => 'approve']);
        $notification = ChangeNotification::firstOrFail();
        $payload = ['recipient' => 'a@b.de', 'subject' => 'X', 'body' => 'Y'];

        $this->actingAs($admin)->post(route('admin.change_notifications.send', $notification->id), $payload);
        $this->actingAs($admin)->post(route('admin.change_notifications.send', $notification->id), $payload)
            ->assertSessionHas('error');

        \Illuminate\Support\Facades\Mail::assertQueuedCount(1);
    }

    public function test_notification_can_be_marked_as_handled_by_post(): void
    {
        $customer = $this->makeCustomer();
        Contract::create(['customer_id' => $customer->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active', 'contract_number' => 'KFZ-7']);
        $request = $this->bankRequest($customer);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.action', $request->id), ['action' => 'approve']);
        $notification = ChangeNotification::firstOrFail();

        $this->actingAs($admin)->post(route('admin.change_notifications.skip', $notification->id), [
            'channel' => 'post', 'note' => 'Formular der HUK genutzt',
        ])->assertSessionHas('success');

        $this->assertSame('skipped', $notification->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Rueckfrage im Chat + Zugriffsschutz
    // ------------------------------------------------------------------

    public function test_staff_question_creates_chat_message_and_opens_conversation(): void
    {
        $customer = $this->makeCustomer();
        $request = $this->bankRequest($customer);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.change_requests.ask', $request->id), [
            'body' => 'Ab wann gilt Ihre neue Bankverbindung?',
            'email_mode' => 'none',
        ])->assertRedirect(route('admin.customer_chat', ['kunde' => (string) $customer->id]));

        $this->assertDatabaseHas('customer_messages', [
            'customer_id' => (string) $customer->id,
            'from_staff' => true,
        ]);
        $this->assertSame('Ab wann gilt Ihre neue Bankverbindung?', CustomerMessage::firstOrFail()->body);
    }

    public function test_foreign_staff_cannot_open_proof_or_ask(): void
    {
        $customer = $this->makeCustomer();
        $request = $this->bankRequest($customer);
        $document = $request->documents()->firstOrFail();

        $stranger = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $this->actingAs($stranger)->get(route('admin.change_requests.proof', $document->id))->assertForbidden();
        $this->actingAs($stranger)->post(route('admin.change_requests.ask', $request->id), ['body' => 'Hallo'])
            ->assertForbidden();
    }

    public function test_customer_cannot_open_proof_of_another_customer(): void
    {
        $victim = $this->makeCustomer();
        $request = $this->bankRequest($victim);
        $document = $request->documents()->firstOrFail();

        $attacker = $this->makeCustomer(['customer_number' => '2600002']);
        $this->actingAs($attacker->user)->get(route('admin.change_requests.proof', $document->id))
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_assigned_staff_can_open_proof(): void
    {
        $customer = $this->makeCustomer();
        $request = $this->bankRequest($customer);
        $document = $request->documents()->firstOrFail();

        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $employee->assignedCustomers()->attach((string) $customer->id);

        $this->actingAs($employee)->get(route('admin.change_requests.proof', ['id' => $document->id, 'download' => 1]))
            ->assertOk();
    }

    public function test_review_page_shows_proof_state_and_effective_date(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer->user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000',
            'account_holder' => 'Mohammad Alshaikh',
            'effective_from' => '2026-09-01',
            'bank_proof' => $this->proof('bankkarte.pdf'),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.change_requests'))
            ->assertOk()
            ->assertSee('Kontonachweis', false)
            ->assertSee('01.09.2026')
            ->assertSee('Rückfrage', false);
    }
}
