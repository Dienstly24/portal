<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Support\AiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smart-E-Mail-Composer: Kundensuche mit Portfolio-Scope und Favoriten,
 * Kundenkarte mit Verlauf, KI-Entwurf (nur auf Klick, hart abgesichert).
 */
class SmartComposeTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $name, string $email, string $number, ?string $company = null): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);
        return Customer::create([
            'user_id' => $user->id, 'customer_number' => $number,
            'company_name' => $company, 'gender' => 'male', 'preferred_lang' => 'de',
        ]);
    }

    public function test_kundensuche_findet_nach_name_email_nummer_und_firma(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makeCustomer('Max Müller', 'max.mueller@firma.de', '2600100', 'Autohaus Berlin');
        $this->makeCustomer('Maria Schmidt', 'maria@example.de', '2600101');

        foreach (['Max', 'max.mueller', '2600100', 'Autohaus'] as $q) {
            $this->actingAs($admin)->get(route('admin.email.customer_search', ['q' => $q]))
                ->assertOk()
                ->assertJsonFragment(['name' => 'Max Müller'])
                ->assertJsonFragment(['email' => 'max.mueller@firma.de'])
                ->assertJsonFragment(['number' => '2600100']);
        }

        // Platzhalter-Adressen werden nie als E-Mail angeboten
        $this->makeCustomer('Import Kunde', 'import-1@dienstly24.internal', '2600102');
        $this->actingAs($admin)->get(route('admin.email.customer_search', ['q' => 'Import']))
            ->assertOk()->assertJsonFragment(['email' => null]);
    }

    public function test_kundensuche_respektiert_portfolio_scope(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_send_emails' => true]);
        $mine = $this->makeCustomer('Zugewiesener Kunde', 'a@example.de', '2600110');
        $this->makeCustomer('Fremder Kunde', 'b@example.de', '2600111');
        $mine->betreuer()->attach($employee->id);

        $response = $this->actingAs($employee)->get(route('admin.email.customer_search', ['q' => 'Kunde']))->assertOk();
        $response->assertJsonFragment(['name' => 'Zugewiesener Kunde']);
        $response->assertJsonMissing(['name' => 'Fremder Kunde']);
    }

    public function test_favoriten_stehen_ohne_suchbegriff_zuerst(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $fav = $this->makeCustomer('Stern Kunde', 'stern@example.de', '2600120');
        $this->makeCustomer('Neuer Kunde', 'neu@example.de', '2600121');

        $this->actingAs($admin)->post(route('admin.email.favorite', $fav->id))
            ->assertOk()->assertJson(['favorite' => true]);

        $names = collect($this->actingAs($admin)->get(route('admin.email.customer_search'))
            ->assertOk()->json('customers'))->pluck('name');
        $this->assertSame('Stern Kunde', $names->first());

        // Zweiter Klick entfernt den Favoriten wieder
        $this->actingAs($admin)->post(route('admin.email.favorite', $fav->id))
            ->assertOk()->assertJson(['favorite' => false]);
        $this->assertDatabaseMissing('favorite_customers', ['customer_id' => $fav->id]);
    }

    public function test_kundenkontext_liefert_anreden_und_verlauf(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer('Max Müller', 'max@example.de', '2600130');
        CustomerMessage::create([
            'customer_id' => $customer->id, 'sender_id' => $admin->id,
            'body' => 'Ihre Unterlagen sind angekommen.', 'from_staff' => true,
        ]);
        Ticket::create([
            'customer_id' => $customer->id, 'type' => 'other', 'status' => 'open',
            'subject' => 'KFZ Angebot', 'description' => 'x',
        ]);

        $json = $this->actingAs($admin)->get(route('admin.email.customer_context', $customer->id))
            ->assertOk()->json();

        $this->assertSame('Sehr geehrter Herr Müller,', $json['salutations']['formell']);
        $this->assertSame('Hallo Max,', $json['salutations']['locker']);
        $this->assertFalse($json['favorite']);
        $texts = collect($json['history'])->pluck('text')->implode(' | ');
        $this->assertStringContainsString('Ihre Unterlagen sind angekommen.', $texts);
        $this->assertStringContainsString('KFZ Angebot', $texts);
    }

    public function test_kontext_und_suche_pruefen_berechtigungen(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_send_emails' => true]);
        $ohneRecht = User::factory()->create(['role' => 'employee', 'can_send_emails' => false]);
        $customer = $this->makeCustomer('Fremd', 'f@example.de', '2600140');

        // Kein Kundenzugriff -> 403; ohne Composer-Recht -> 403 schon an der Tuer
        $this->actingAs($employee)->get(route('admin.email.customer_context', $customer->id))->assertForbidden();
        $this->actingAs($employee)->post(route('admin.email.favorite', $customer->id))->assertForbidden();
        $this->actingAs($ohneRecht)->get(route('admin.email.customer_search', ['q' => 'x']))->assertForbidden();
    }

    public function test_ki_entwurf_mit_gemocktem_anbieter(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Anna Admin']);
        $customer = $this->makeCustomer('Max Müller', 'max@example.de', '2600150');

        $this->mock(AiProviderInterface::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('complete')->once()->andReturn(new AiResponse(
                text: '{"subject": "Ihr Angebot von Dienstly24", "body": "Sehr geehrter Herr Müller,\n\nanbei unser Angebot."}'
            ));
        });

        $this->actingAs($admin)->postJson(route('admin.email.ai_draft'), [
            'goal' => 'Angebot KFZ nachfassen',
            'customer_id' => (string) $customer->id,
        ])->assertOk()
            ->assertJson([
                'subject' => 'Ihr Angebot von Dienstly24',
                'body' => "Sehr geehrter Herr Müller,\n\nanbei unser Angebot.",
            ]);
    }

    public function test_ki_entwurf_ohne_anbieter_liefert_hinweis(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->mock(AiProviderInterface::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(false);
        });

        $this->actingAs($admin)->postJson(route('admin.email.ai_draft'), ['goal' => 'Test'])
            ->assertStatus(422);
    }

    public function test_ki_entwurf_prueft_kundenzugriff(): void
    {
        $employee = User::factory()->create(['role' => 'employee', 'can_send_emails' => true]);
        $customer = $this->makeCustomer('Fremd', 'f2@example.de', '2600160');
        $this->mock(AiProviderInterface::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
        });

        $this->actingAs($employee)->postJson(route('admin.email.ai_draft'), [
            'goal' => 'x', 'customer_id' => (string) $customer->id,
        ])->assertForbidden();
    }

    public function test_composer_seite_rendert_mit_smart_elementen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        \App\Models\MessageTemplate::create(['name' => 'Angebot nachfassen', 'category' => 'kunde', 'body' => 'X']);

        $this->actingAs($admin)->get(route('admin.email.compose'))
            ->assertOk()
            ->assertSee('Kunde')
            ->assertSee('Vorlagen')
            ->assertSee('Vorschau');
    }

    public function test_versand_aktualisiert_letzten_kontakt(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer('Max Müller', 'max@example.de', '2600170');

        $this->actingAs($admin)->post(route('admin.email.compose.send'), [
            'to' => 'max@example.de', 'subject' => 'Info', 'body' => 'Text',
            'customer_id' => (string) $customer->id,
        ])->assertRedirect();

        $this->assertSame(now()->toDateString(), (string) $customer->fresh()->last_contact);
    }
}
