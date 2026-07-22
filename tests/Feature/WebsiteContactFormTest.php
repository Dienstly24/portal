<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kontaktformular der statischen Website -> /api/website-contact.
 *
 * Deckt alle Schutzschichten ab: Formular-Token (fehlend, manipuliert,
 * zu schnell, abgelaufen, wiederverwendet), Honeypot, Inhalts-Spamfilter
 * sowie den Erfolgsfall inkl. Kundenzuordnung.
 */
class WebsiteContactFormTest extends TestCase
{
    use RefreshDatabase;

    /** Baut ein gueltiges Formular-Token mit vorgegebenem Alter. */
    private function token(int $ageSeconds = 60): string
    {
        return Crypt::encryptString(json_encode([
            'iat' => now()->timestamp - $ageSeconds,
            'n' => Str::random(16),
        ]));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Max Mustermann',
            'kontakt' => 'max@example.com',
            'leistung' => 'Kfz-Versicherung',
            'nachricht' => 'Bitte um ein Angebot fuer meinen PKW.',
            'token' => $this->token(),
        ], $overrides);
    }

    public function test_token_endpoint_returns_decryptable_token(): void
    {
        $response = $this->getJson('/api/website-contact/token')
            ->assertOk()
            ->assertJsonStructure(['token']);

        $meta = json_decode(Crypt::decryptString($response->json('token')), true);
        $this->assertArrayHasKey('iat', $meta);
        $this->assertArrayHasKey('n', $meta);
    }

    public function test_valid_submission_creates_ticket(): void
    {
        $this->postJson('/api/website-contact', $this->payload())
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('tickets', [
            'source' => 'website',
            'type' => 'offer',
            'subject' => 'Website-Anfrage: Kfz-Versicherung',
            'description' => 'Bitte um ein Angebot fuer meinen PKW.',
            'guest_name' => 'Max Mustermann',
            'guest_email' => 'max@example.com',
            'customer_id' => null,
        ]);
    }

    public function test_phone_contact_is_stored_as_phone(): void
    {
        $this->postJson('/api/website-contact', $this->payload(['kontakt' => '+49 179 1234567']))
            ->assertOk();

        $this->assertDatabaseHas('tickets', [
            'guest_phone' => '+49 179 1234567',
            'guest_email' => null,
        ]);
    }

    public function test_empty_message_gets_fallback_description(): void
    {
        $this->postJson('/api/website-contact', $this->payload(['nachricht' => null]))
            ->assertOk();

        $this->assertDatabaseHas('tickets', [
            'description' => 'Keine Nachricht angegeben - Kontaktwunsch zu: Kfz-Versicherung',
        ]);
    }

    public function test_existing_customer_is_linked_by_email(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'kunde@example.com']);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => '2600077',
            'first_name' => 'Karla',
            'last_name' => 'Kundin',
        ]);

        $this->postJson('/api/website-contact', $this->payload(['kontakt' => 'kunde@example.com']))
            ->assertOk();

        $this->assertDatabaseHas('tickets', ['customer_id' => $customer->id]);
    }

    public function test_missing_or_garbage_token_is_rejected(): void
    {
        $this->postJson('/api/website-contact', $this->payload(['token' => null]))
            ->assertStatus(422)->assertJson(['error' => 'token']);
        $this->postJson('/api/website-contact', $this->payload(['token' => 'kaputt']))
            ->assertStatus(422)->assertJson(['error' => 'token']);
        $this->assertSame(0, Ticket::count());
    }

    public function test_too_fast_submission_is_rejected(): void
    {
        // Schneller als MIN_AGE_SECONDS nach Token-Ausgabe = Bot-Tempo.
        $this->postJson('/api/website-contact', $this->payload(['token' => $this->token(2)]))
            ->assertStatus(422)->assertJson(['error' => 'token']);
        $this->assertSame(0, Ticket::count());
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->postJson('/api/website-contact', $this->payload(['token' => $this->token(8000)]))
            ->assertStatus(422)->assertJson(['error' => 'token']);
        $this->assertSame(0, Ticket::count());
    }

    public function test_token_cannot_be_reused(): void
    {
        $token = $this->token();

        $this->postJson('/api/website-contact', $this->payload(['token' => $token]))->assertOk();
        $this->postJson('/api/website-contact', $this->payload(['token' => $token]))
            ->assertStatus(422)->assertJson(['error' => 'token']);

        $this->assertSame(1, Ticket::count());
    }

    public function test_honeypot_returns_fake_success_without_ticket(): void
    {
        $this->postJson('/api/website-contact', $this->payload(['website' => 'http://spam.example']))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(0, Ticket::count());
    }

    public function test_spam_content_is_silently_dropped(): void
    {
        $this->postJson('/api/website-contact', $this->payload([
            'nachricht' => 'True Fortune casino stands out as a trusted online gambling destination. '
                . 'Big-money jackpots! https://spam.example https://spam2.example',
        ]))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(0, Ticket::count());
    }

    public function test_unknown_leistung_is_rejected(): void
    {
        $this->postJson('/api/website-contact', $this->payload(['leistung' => 'Casino-Bonus']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['leistung']);
        $this->assertSame(0, Ticket::count());
    }
}
