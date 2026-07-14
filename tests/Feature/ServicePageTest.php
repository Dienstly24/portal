<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServicePage;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ServicePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Vite-Assets werden in der Testumgebung nicht gebaut.
        $this->withoutVite();
    }

    private function makePage(array $o = []): ServicePage
    {
        return ServicePage::create(array_merge([
            'slug' => 'kfz-versicherung',
            'icon' => '🚗',
            'title_de' => 'Kfz-Versicherung',
            'title_ar' => 'تأمين السيارة',
            'subtitle_de' => 'Kurz erklaert',
            'intro_de' => 'Definition der Kfz-Versicherung.',
            'highlights_de' => "Punkt A\nPunkt B",
            'faq' => [['q_de' => 'Frage?', 'q_ar' => '', 'a_de' => 'Antwort.', 'a_ar' => '']],
            'is_active' => true,
        ], $o));
    }

    public function test_public_page_renders(): void
    {
        $this->makePage();
        $this->get('/leistungen/kfz-versicherung')
            ->assertOk()
            ->assertSee('Kfz-Versicherung')
            ->assertSee('Punkt A')
            ->assertSee('Frage?');
    }

    public function test_body_markup_renders_as_html(): void
    {
        $this->makePage([
            'body_de' => "## Darauf kommt es an\nEin Absatz.\n\n- Punkt eins\n- Punkt zwei",
            'meta_description_de' => 'Testbeschreibung fuer SEO.',
        ]);

        $this->get('/leistungen/kfz-versicherung')
            ->assertOk()
            ->assertSee('<h3>Darauf kommt es an</h3>', false)
            ->assertSee('<li>Punkt eins</li>', false)
            ->assertSee('<p>Ein Absatz.</p>', false)
            ->assertSee('Testbeschreibung fuer SEO.', false)   // meta description
            ->assertSee('application/ld+json', false);          // structured data
    }

    public function test_body_escapes_html(): void
    {
        $this->makePage(['body_de' => 'Text mit <script>alert(1)</script> drin']);
        $this->get('/leistungen/kfz-versicherung')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_inactive_or_unknown_is_404(): void
    {
        $this->makePage(['is_active' => false]);
        $this->get('/leistungen/kfz-versicherung')->assertNotFound();
        $this->get('/leistungen/gibt-es-nicht')->assertNotFound();
    }

    public function test_index_lists_only_active_pages(): void
    {
        $this->makePage();
        $this->makePage(['slug' => 'strom-gas', 'title_de' => 'Strom und Gas']);
        $this->makePage(['slug' => 'verborgen', 'title_de' => 'Geheim', 'is_active' => false]);

        $this->get('/leistungen')->assertOk()
            ->assertSee('Kfz-Versicherung')
            ->assertSee('Strom und Gas')
            ->assertDontSee('Geheim');
    }

    public function test_submit_creates_website_ticket(): void
    {
        Mail::fake();
        $this->makePage();

        $this->post('/leistungen/kfz-versicherung/anfrage', [
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'message' => 'Ich brauche ein Angebot.',
            'consent' => '1',
        ])->assertRedirect(route('services.show', 'kfz-versicherung'));

        $this->assertSame(1, Ticket::count());
        $ticket = Ticket::first();
        $this->assertSame('website', $ticket->source);
        $this->assertSame('Max Mustermann', $ticket->guest_name);
        $this->assertSame('max@example.com', $ticket->guest_email);
        $this->assertStringContainsString('Kfz-Versicherung', $ticket->subject);
    }

    public function test_custom_required_field_is_enforced(): void
    {
        $this->makePage(['fields' => [
            ['label_de' => 'Deckung', 'label_ar' => '', 'type' => 'select', 'options_de' => 'Haftpflicht, Vollkasko', 'options_ar' => '', 'required' => true],
        ]]);

        $this->from('/leistungen/kfz-versicherung')
            ->post('/leistungen/kfz-versicherung/anfrage', ['name' => 'A', 'email' => 'a@b.de', 'consent' => '1'])
            ->assertSessionHasErrors('custom.0');

        $this->assertSame(0, Ticket::count());
    }

    public function test_custom_field_answers_appended_to_ticket(): void
    {
        Mail::fake();
        $this->makePage(['fields' => [
            ['label_de' => 'Fahrzeug', 'label_ar' => '', 'type' => 'text', 'options_de' => '', 'options_ar' => '', 'required' => false],
            ['label_de' => 'Deckung', 'label_ar' => '', 'type' => 'select', 'options_de' => 'Haftpflicht, Vollkasko', 'options_ar' => '', 'required' => true],
        ]]);

        $this->post('/leistungen/kfz-versicherung/anfrage', [
            'name' => 'Max', 'email' => 'max@x.de', 'consent' => '1',
            'custom' => [0 => 'BMW 3er', 1 => 'Vollkasko'],
        ])->assertRedirect(route('services.show', 'kfz-versicherung'));

        $ticket = Ticket::first();
        $this->assertStringContainsString('Fahrzeug: BMW 3er', $ticket->description);
        $this->assertStringContainsString('Deckung: Vollkasko', $ticket->description);
    }

    public function test_admin_can_save_custom_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.service_pages.store'), [
            'slug' => 'kfz', 'title_de' => 'Kfz', 'is_active' => '1',
            'field_label_de' => ['Fahrzeug', ''], 'field_label_ar' => ['', ''],
            'field_type' => ['text', 'text'], 'field_options_de' => ['', ''],
            'field_options_ar' => ['', ''], 'field_required' => ['1', '0'],
        ])->assertRedirect(route('admin.service_pages'));

        $page = ServicePage::where('slug', 'kfz')->firstOrFail();
        // Leere Zeile (ohne Label) wird verworfen -> genau ein Feld.
        $this->assertCount(1, $page->fields);
        $this->assertSame('Fahrzeug', $page->fields[0]['label_de']);
        $this->assertTrue($page->fields[0]['required']);
    }

    public function test_submit_requires_consent_and_contact(): void
    {
        $this->makePage();

        $this->from('/leistungen/kfz-versicherung')
            ->post('/leistungen/kfz-versicherung/anfrage', ['name' => 'A', 'email' => 'a@b.de'])
            ->assertSessionHasErrors('consent');

        $this->from('/leistungen/kfz-versicherung')
            ->post('/leistungen/kfz-versicherung/anfrage', ['name' => 'A', 'consent' => '1'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Ticket::count());
    }

    public function test_honeypot_blocks_silently(): void
    {
        $this->makePage();

        $this->post('/leistungen/kfz-versicherung/anfrage', [
            'name' => 'Bot', 'email' => 'bot@x.de', 'consent' => '1', 'website' => 'spam',
        ])->assertRedirect(route('services.show', 'kfz-versicherung'));

        $this->assertSame(0, Ticket::count());
    }

    public function test_admin_can_manage_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.service_pages'))->assertOk();
        $this->actingAs($admin)->get(route('admin.service_pages.create'))->assertOk();

        $this->actingAs($admin)->post(route('admin.service_pages.store'), [
            'slug' => 'neu', 'title_de' => 'Neu', 'is_active' => '1', 'sort_order' => 5,
            'faq_q_de' => ['Frage?'], 'faq_a_de' => ['Antwort.'], 'faq_q_ar' => [''], 'faq_a_ar' => [''],
        ])->assertRedirect(route('admin.service_pages'));

        $page = ServicePage::where('slug', 'neu')->firstOrFail();
        $this->assertCount(1, $page->faq);
        $this->assertSame('Frage?', $page->faq[0]['q_de']);

        $this->actingAs($admin)->put(route('admin.service_pages.update', $page), [
            'slug' => 'neu', 'title_de' => 'Neu geaendert', 'is_active' => '1', 'sort_order' => 5,
        ])->assertRedirect(route('admin.service_pages'));
        $this->assertSame('Neu geaendert', $page->refresh()->title_de);

        $this->actingAs($admin)->post(route('admin.service_pages.toggle', $page));
        $this->assertFalse($page->refresh()->is_active);

        $this->actingAs($admin)->delete(route('admin.service_pages.delete', $page))
            ->assertRedirect(route('admin.service_pages'));
        $this->assertSame(0, ServicePage::count());
    }

    public function test_slug_must_be_unique(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makePage(['slug' => 'strom-gas', 'title_de' => 'Strom']);

        $this->actingAs($admin)->from(route('admin.service_pages.create'))
            ->post(route('admin.service_pages.store'), [
                'slug' => 'strom-gas', 'title_de' => 'Duplikat', 'is_active' => '1',
            ])->assertSessionHasErrors('slug');
    }

    public function test_non_admin_cannot_manage(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'c@x.de']);
        Customer::create(['user_id' => $user->id, 'customer_number' => 'C-TEST1']);

        // Gast (nicht eingeloggt) -> Login.
        $this->get(route('admin.service_pages'))->assertRedirect(route('login'));

        // Kunde -> auf sein eigenes Dashboard, kein Admin-Zugriff.
        $this->actingAs($user)->get(route('admin.service_pages'))
            ->assertRedirect(route('portal.dashboard'));
    }
}
