<?php

namespace Tests\Feature;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteInquiryTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'name' => 'Max Mustermann',
        'email' => 'max@example.com',
        'message' => 'Bitte um Rückruf.',
    ];

    public function test_rejected_when_no_token_is_configured(): void
    {
        // Regression for audit C5: with INQUIRY_TOKEN unset, null !== null
        // used to pass and anyone could create tickets.
        config(['services.inquiry.token' => null]);

        $this->postJson('/api/website-inquiry', $this->payload)->assertStatus(401);
        $this->postJson('/api/website-inquiry', $this->payload, ['X-Inquiry-Token' => ''])->assertStatus(401);
        $this->assertSame(0, Ticket::count());
    }

    public function test_rejected_with_wrong_token(): void
    {
        config(['services.inquiry.token' => 'secret-token']);

        $this->postJson('/api/website-inquiry', $this->payload, ['X-Inquiry-Token' => 'wrong'])
            ->assertStatus(401);
        $this->assertSame(0, Ticket::count());
    }

    public function test_accepted_with_correct_token(): void
    {
        config(['services.inquiry.token' => 'secret-token']);

        $this->postJson('/api/website-inquiry', $this->payload, ['X-Inquiry-Token' => 'secret-token'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tickets', [
            'source' => 'website',
            'guest_email' => 'max@example.com',
            'customer_id' => null,
        ]);
    }
}
