<?php

namespace Tests\Feature;

use App\Mail\DirectEmailMail;
use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Vorlagen (CRUD + Platzhalter-Rendering) und E-Mail-Composer
 * (Berechtigungen, Versand, Protokollierung in der Kundenakte).
 */
class MessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'kunde@example.de', 'name' => 'Max Meyer']);
        return Customer::create([
            'user_id' => $user->id, 'customer_number' => '2600007',
            'gender' => 'male', 'birth_date' => '1990-05-01',
        ]);
    }

    public function test_admin_legt_vorlage_an_und_bearbeitet_sie(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.templates.store'), [
            'name' => 'Test-Vorlage', 'category' => 'kunde',
            'subject' => 'Betreff', 'body' => 'Hallo {{name}}',
        ])->assertRedirect(route('admin.templates'));

        $template = MessageTemplate::firstOrFail();
        $this->assertSame($admin->id, $template->created_by);

        $this->actingAs($admin)->put(route('admin.templates.update', $template->id), [
            'name' => 'Umbenannt', 'category' => 'gesellschaft', 'body' => 'Neu',
        ])->assertRedirect(route('admin.templates'));
        $this->assertSame('Umbenannt', $template->fresh()->name);

        $this->actingAs($admin)->delete(route('admin.templates.destroy', $template->id))->assertRedirect();
        $this->assertDatabaseMissing('message_templates', ['id' => $template->id]);
    }

    public function test_mitarbeiter_darf_vorlagen_nicht_pflegen_aber_nutzen(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $template = MessageTemplate::create(['name' => 'T', 'category' => 'kunde', 'body' => 'Hallo {{berater}}']);

        // Pflege-Routen sind admin/manager - Rollen-Middleware leitet um
        $this->actingAs($employee)->post(route('admin.templates.store'), [
            'name' => 'X', 'category' => 'kunde', 'body' => 'X',
        ])->assertRedirect(route('admin.dashboard'));

        // Nutzung (Liste + Rendern) steht allen Staff-Rollen offen
        $this->actingAs($employee)->get(route('admin.templates.list'))
            ->assertOk()->assertJsonFragment(['name' => 'T']);
        $this->actingAs($employee)->get(route('admin.templates.render', $template->id))
            ->assertOk()->assertJsonFragment(['body' => 'Hallo ' . $employee->name]);
    }

    public function test_rendering_ersetzt_kundenplatzhalter(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Anna Admin']);
        $customer = $this->makeCustomer();
        $template = MessageTemplate::create([
            'name' => 'Voll', 'category' => 'kunde',
            'subject' => 'Kunde {{kundennummer}}',
            'body' => "{{anrede}},\nName: {{name}} ({{vorname}} {{nachname}})\nGeboren: {{geburtsdatum}}\nIhr Berater: {{berater}}",
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.templates.render', $template->id) . '?customer_id=' . $customer->id)
            ->assertOk()->json();

        $this->assertSame('Kunde 2600007', $response['subject']);
        $this->assertStringContainsString('Sehr geehrter Herr Meyer', $response['body']);
        $this->assertStringContainsString('Name: Max Meyer (Max Meyer)', $response['body']);
        $this->assertStringContainsString('Geboren: 01.05.1990', $response['body']);
        $this->assertStringContainsString('Ihr Berater: Anna Admin', $response['body']);
    }

    public function test_rendering_prueft_kundenzugriff(): void
    {
        $employee = User::factory()->create(['role' => 'employee']);
        $customer = $this->makeCustomer();
        $template = MessageTemplate::create(['name' => 'T', 'category' => 'kunde', 'body' => '{{name}}']);

        $this->actingAs($employee)
            ->get(route('admin.templates.render', $template->id) . '?customer_id=' . $customer->id)
            ->assertForbidden();
    }

    public function test_vorlagen_seite_rendert_mit_vorhandenen_vorlagen(): void
    {
        // Regression: die Detail-Zeile (Bearbeiten-Button mit JSON-Daten)
        // wird nur mit vorhandener Vorlage ausgefuehrt - genau dort sass
        // der Kompilierfehler, der in Produktion einen 500 ausloeste.
        $admin = User::factory()->create(['role' => 'admin']);
        MessageTemplate::create([
            'name' => 'Render-Check', 'category' => 'kunde',
            'subject' => 'Betreff mit "Anfuehrungszeichen"', 'body' => "Zeile 1\n{{anrede}}, 'Apostroph'",
        ]);

        $this->actingAs($admin)->get(route('admin.templates'))
            ->assertOk()
            ->assertSee('Render-Check');

        // Mitarbeiter sehen die Seite lesend, ohne Pflege-Buttons
        $employee = User::factory()->create(['role' => 'employee']);
        $this->actingAs($employee)->get(route('admin.templates'))
            ->assertOk()
            ->assertDontSee('tpl-edit');
    }

    public function test_standard_vorlagen_sind_idempotent(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.templates.seed'))->assertRedirect();
        $count = MessageTemplate::count();
        $this->assertGreaterThan(0, $count);

        $this->actingAs($admin)->post(route('admin.templates.seed'))->assertRedirect();
        $this->assertSame($count, MessageTemplate::count());
    }

    public function test_composer_sendet_email_und_protokolliert_in_kundenakte(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->makeCustomer();

        $this->actingAs($admin)->post(route('admin.email.compose.send'), [
            'to' => 'service@gesellschaft.de',
            'subject' => 'Kuendigung Vertrag 123',
            'body' => 'Sehr geehrte Damen und Herren, ...',
            'customer_id' => (string) $customer->id,
            'attachments' => [UploadedFile::fake()->create('vollmacht.pdf', 50, 'application/pdf')],
        ])->assertRedirect();

        Mail::assertQueued(DirectEmailMail::class, function ($mail) {
            return $mail->hasTo('service@gesellschaft.de')
                && $mail->mailSubject === 'Kuendigung Vertrag 123'
                && count($mail->fileAttachments) === 1;
        });
        $this->assertDatabaseHas('customer_timeline', [
            'customer_id' => (string) $customer->id, 'type' => 'email',
            'title' => 'E-Mail gesendet: Kuendigung Vertrag 123',
        ]);
    }

    public function test_composer_berechtigungen(): void
    {
        $ohneRecht = User::factory()->create(['role' => 'employee', 'can_send_emails' => false]);
        $mitRecht = User::factory()->create(['role' => 'employee', 'can_send_emails' => true]);
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($ohneRecht)->get(route('admin.email.compose'))->assertForbidden();
        $this->actingAs($mitRecht)->get(route('admin.email.compose'))->assertOk();
        $this->actingAs($support)->get(route('admin.email.compose'))->assertOk();
    }

    public function test_composer_prueft_kundenzugriff_beim_versand(): void
    {
        Mail::fake();
        $employee = User::factory()->create(['role' => 'employee', 'can_send_emails' => true]);
        $customer = $this->makeCustomer();

        $this->actingAs($employee)->post(route('admin.email.compose.send'), [
            'to' => 'x@example.de', 'subject' => 'S', 'body' => 'B',
            'customer_id' => (string) $customer->id,
        ])->assertForbidden();

        Mail::assertNothingSent();
    }
}
