<?php

namespace Tests\Feature;

use App\Mail\SupportInquiryMail;
use App\Mail\WebsiteInquiryConfirmationMail;
use App\Models\ServicePage;
use App\Models\Ticket;
use App\Services\UmlautRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Website-Merge (Arbeitsauftrag 30.07.2026): Marketing-Website laeuft
 * serverseitig im Portal auf www.dienstly24.de - echte DE/AR-URLs,
 * serverseitiges Kontaktformular mit Einwilligungs-Protokoll,
 * Domain-Redirects, dynamische robots.txt/sitemap.xml, lokale Schriften.
 */
class WebsiteMergeTest extends TestCase
{
    use RefreshDatabase;

    private const HOME = 'https://www.dienstly24.de/';

    // ---------- Startseite ----------

    public function test_homepage_renders_on_website_host(): void
    {
        $this->get(self::HOME)
            ->assertOk()
            ->assertSee('Alle Versicherungen.')
            ->assertSee('Ein Ansprechpartner.')
            ->assertSee('wa.me/491799673909', false)
            ->assertSee('action="/kontakt"', false)
            ->assertSee('rel="canonical" href="https://www.dienstly24.de/"', false)
            ->assertSee('hreflang="ar" href="https://www.dienstly24.de/ar"', false)
            ->assertDontSee('fonts.googleapis.com')
            ->assertDontSee('fonts.gstatic.com')
            ->assertDontSee('mailto:info@dienstly24.de" method', false);
    }

    public function test_homepage_on_portal_host_keeps_login_redirect(): void
    {
        $this->get('/')->assertRedirect(route('portal.dashboard'));
    }

    public function test_arabic_homepage_is_server_rendered_rtl(): void
    {
        $this->get('https://www.dienstly24.de/ar')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('جميع أنواع التأمين.')
            ->assertSee('جهة اتصال واحدة.')
            ->assertSee('rel="canonical" href="https://www.dienstly24.de/ar"', false)
            ->assertSee('hreflang="de" href="https://www.dienstly24.de/"', false);
    }

    public function test_alternate_hosts_redirect_301_to_canonical(): void
    {
        $this->get('https://dienstly24.com/leistungen')
            ->assertStatus(301)
            ->assertRedirect('https://www.dienstly24.de/leistungen');

        $this->get('https://dienstly24.de/impressum')
            ->assertStatus(301)
            ->assertRedirect('https://www.dienstly24.de/impressum');
    }

    /**
     * P1-4: Nach dem DNS-Umzug wandern die alten Marketing-URLs des
     * Portal-Hosts per 301 auf den kanonischen Host - aber NICHT vorher
     * (dann liegt dort noch die statische Site ohne /leistungen) und nie
     * der Login-/Portalbereich.
     */
    public function test_marketing_paths_move_to_canonical_host_only_after_cutover(): void
    {
        $this->seed(\Database\Seeders\ServicePageSeeder::class);

        // Vor dem Umzug (Standard): Portal-Host liefert weiter aus.
        $this->get('https://portal.dienstly24.de/leistungen/kfz-versicherung')->assertOk();

        config(['website.marketing_redirect' => true]);

        $this->get('https://portal.dienstly24.de/leistungen/kfz-versicherung')
            ->assertStatus(301)
            ->assertRedirect('https://www.dienstly24.de/leistungen/kfz-versicherung');
        $this->get('https://portal.dienstly24.de/leistungen')
            ->assertStatus(301)
            ->assertRedirect('https://www.dienstly24.de/leistungen');
        $this->get('https://portal.dienstly24.de/ar')
            ->assertStatus(301)
            ->assertRedirect('https://www.dienstly24.de/ar');

        // Der Anwendungsbereich bleibt unberuehrt - sonst kaeme kein Kunde
        // mehr ins Portal.
        $this->get('https://portal.dienstly24.de/login')->assertOk();
        $this->get('https://portal.dienstly24.de/hilfe')->assertOk();
        // Und auf dem Website-Host wird natuerlich nichts umgeleitet.
        $this->get('https://www.dienstly24.de/leistungen/kfz-versicherung')->assertOk();
    }

    /**
     * Zwei Dateien werden von AUSSEN referenziert und muessen den Umzug
     * ueberleben, sonst brechen sie still: das BIMI-Logo (steht im
     * DNS-TXT-Eintrag default._bimi und zeigt das Markenlogo im
     * Mail-Programm) und die Google-Search-Console-Bestaetigung.
     */
    public function test_externally_referenced_files_exist_in_public(): void
    {
        $this->assertFileExists(public_path('dienstly-bimi-logo.svg'),
            'BIMI-Logo fehlt - der DNS-Eintrag default._bimi zeigt darauf.');
        $this->assertFileExists(public_path('googled3a1b012f4607d0c.html'),
            'Search-Console-Bestaetigung fehlt - Property-Verifizierung ginge verloren.');

        // Keine Route darf die Dateien verschatten (Blade-Fallback statt Datei).
        $this->assertStringNotContainsString('<html', file_get_contents(public_path('googled3a1b012f4607d0c.html')));
    }

    public function test_old_static_html_urls_redirect_to_clean_routes(): void
    {
        $this->get('https://www.dienstly24.de/impressum.html')
            ->assertStatus(301)
            ->assertRedirect('/impressum');
        $this->get('https://www.dienstly24.de/index.html')
            ->assertStatus(301)
            ->assertRedirect('/');
    }

    // ---------- Rechtsseiten ----------

    public function test_legal_pages_render_locally_on_website_host(): void
    {
        $this->get('https://www.dienstly24.de/impressum')
            ->assertOk()
            ->assertSee('vermittlerregister.info', false)
            ->assertDontSee('fonts.googleapis.com');

        $this->get('https://www.dienstly24.de/erstinformation')->assertOk();
        $this->get('https://www.dienstly24.de/widerruf')->assertOk();
        $this->get('https://www.dienstly24.de/bildnachweise')->assertOk();
        $this->get('https://www.dienstly24.de/cookie-richtlinie')
            ->assertOk()
            ->assertSee('ohne Tracking');
    }

    public function test_legal_kontakt_on_website_host_goes_to_contact_section(): void
    {
        $this->get('https://www.dienstly24.de/kontakt')->assertRedirect('/#kontakt');
    }

    // ---------- Kontaktformular (P0-1) ----------

    private function contactPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Max Mustermann',
            'kontakt' => 'max@example.com',
            'leistung' => 'Kfz-Versicherung',
            'nachricht' => 'Bitte um ein Angebot fuer meinen PKW.',
            'consent' => '1',
            'lang' => 'de',
        ], $overrides);
    }

    public function test_contact_form_creates_ticket_with_consent_log_and_mails(): void
    {
        Mail::fake();

        $this->post('https://www.dienstly24.de/kontakt', $this->contactPayload())
            ->assertRedirect(route('website.thanks'));

        $ticket = Ticket::where('source', 'website')->first();
        $this->assertNotNull($ticket);
        $this->assertSame('Website-Anfrage: Kfz-Versicherung', $ticket->subject);
        $this->assertSame('max@example.com', $ticket->guest_email);
        // DSGVO-Einwilligungs-Protokoll: Zeitpunkt, IP, exakter Text.
        $this->assertNotNull($ticket->consent_given_at);
        $this->assertNotNull($ticket->consent_ip);
        $this->assertStringContainsString('Ich stimme der Verarbeitung', $ticket->consent_text);

        Mail::assertQueued(SupportInquiryMail::class);
        Mail::assertQueued(WebsiteInquiryConfirmationMail::class, fn ($mail) => $mail->lang === 'de');
    }

    public function test_contact_form_arabic_redirects_to_arabic_thanks(): void
    {
        Mail::fake();

        $this->post('https://www.dienstly24.de/kontakt', $this->contactPayload(['lang' => 'ar']))
            ->assertRedirect(route('ar.website.thanks'));

        $ticket = Ticket::where('source', 'website')->first();
        $this->assertStringContainsString('أوافق', $ticket->consent_text);
        Mail::assertQueued(WebsiteInquiryConfirmationMail::class, fn ($mail) => $mail->lang === 'ar');
    }

    public function test_contact_form_requires_consent(): void
    {
        $this->from('https://www.dienstly24.de/')
            ->post('https://www.dienstly24.de/kontakt', $this->contactPayload(['consent' => null]))
            ->assertSessionHasErrors('consent');
        $this->assertSame(0, Ticket::count());
    }

    public function test_contact_form_honeypot_discards_silently(): void
    {
        Mail::fake();

        $this->post('https://www.dienstly24.de/kontakt', $this->contactPayload(['website' => 'spam-bot']))
            ->assertRedirect(route('website.thanks'));

        $this->assertSame(0, Ticket::count());
        Mail::assertNothingQueued();
    }

    public function test_thanks_page_renders(): void
    {
        $this->get('https://www.dienstly24.de/kontakt/danke')
            ->assertOk()
            ->assertSee('Ihre Anfrage ist bei uns angekommen');
        $this->get('https://www.dienstly24.de/ar/kontakt/danke')
            ->assertOk()
            ->assertSee('وصلنا طلبكم');
    }

    public function test_purge_command_deletes_only_old_unconverted_website_leads(): void
    {
        $old = Ticket::forceCreate([
            'id' => \Illuminate\Support\Str::uuid(), 'source' => 'website', 'type' => 'offer',
            'priority' => 'mittel', 'status' => 'open', 'subject' => 'Alt',
            'description' => 'x', 'guest_name' => 'Alt',
        ]);
        Ticket::where('id', $old->id)->update(['created_at' => now()->subMonths(7)]);

        $fresh = Ticket::forceCreate([
            'id' => \Illuminate\Support\Str::uuid(), 'source' => 'website', 'type' => 'offer',
            'priority' => 'mittel', 'status' => 'open', 'subject' => 'Neu',
            'description' => 'x', 'guest_name' => 'Neu',
        ]);

        $this->artisan('tickets:purge-website-leads')->assertSuccessful();

        $this->assertNull(Ticket::find($old->id));
        $this->assertNotNull(Ticket::find($fresh->id));
    }

    public function test_purge_hard_deletes_lead_leaving_no_soft_deleted_pii(): void
    {
        $old = Ticket::forceCreate([
            'id' => \Illuminate\Support\Str::uuid(), 'source' => 'website', 'type' => 'offer',
            'priority' => 'mittel', 'status' => 'open', 'subject' => 'Alt',
            'description' => 'Vertrauliche Nachricht', 'guest_name' => 'Alt Gast',
            'guest_email' => 'alt@example.com', 'consent_ip' => '203.0.113.9',
        ]);
        Ticket::where('id', $old->id)->update(['created_at' => now()->subMonths(7)]);

        $this->artisan('tickets:purge-website-leads')->assertSuccessful();

        // Echte Loeschung: der Datensatz darf auch als Soft-Delete NICHT
        // uebrig bleiben (DSGVO - sonst bleibt Gast-PII fuer immer in der DB).
        $this->assertNull(Ticket::withTrashed()->find($old->id));
        $this->assertDatabaseMissing('tickets', ['id' => $old->id]);
    }

    // ---------- SEO ----------

    public function test_robots_txt_depends_on_host(): void
    {
        $this->get('https://www.dienstly24.de/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: https://www.dienstly24.de/sitemap.xml')
            ->assertSee('Disallow: /admin');

        // Portal-/Beraterwelt-Hosts: komplett gesperrt (Marketing lebt nur
        // noch auf www). Host explizit angeben - relative Test-URLs erben
        // sonst den Host des vorherigen Requests.
        $this->get('https://portal.dienstly24.de/robots.txt')
            ->assertOk()
            ->assertSee("Disallow: /\n", false)
            ->assertDontSee('Sitemap:');
    }

    public function test_sitemap_lists_pages_with_hreflang(): void
    {
        $this->seed(\Database\Seeders\ServicePageSeeder::class);

        $this->get('https://www.dienstly24.de/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>https://www.dienstly24.de/</loc>', false)
            ->assertSee('<loc>https://www.dienstly24.de/ar</loc>', false)
            ->assertSee('<loc>https://www.dienstly24.de/leistungen/kfz-versicherung</loc>', false)
            ->assertSee('hreflang="ar" href="https://www.dienstly24.de/ar/leistungen/kfz-versicherung"', false)
            ->assertSee('<loc>https://www.dienstly24.de/impressum</loc>', false);
    }

    public function test_admin_and_login_send_noindex_header(): void
    {
        $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_service_page_has_hreflang_and_whatsapp(): void
    {
        $this->seed(\Database\Seeders\ServicePageSeeder::class);

        $this->get('https://www.dienstly24.de/leistungen/kfz-versicherung')
            ->assertOk()
            ->assertSee('hreflang="ar" href="https://www.dienstly24.de/ar/leistungen/kfz-versicherung"', false)
            ->assertSee('rel="canonical" href="https://www.dienstly24.de/leistungen/kfz-versicherung"', false)
            ->assertSee('wa.me/491799673909', false);

        $this->get('https://www.dienstly24.de/ar/leistungen/kfz-versicherung')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('rel="canonical" href="https://www.dienstly24.de/ar/leistungen/kfz-versicherung"', false);
    }

    // ---------- P0-7 Umlaute / P0-6 Storage-URLs ----------

    public function test_umlaut_repair_fixes_known_words_conservatively(): void
    {
        $this->assertSame(
            'Haftpflicht verständlich erklärt – für Sie kostenlos.',
            UmlautRepair::fix('Haftpflicht verstaendlich erklaert – fuer Sie kostenlos.')
        );
        // Woerter mit legitimen ae/ue-Folgen bleiben unangetastet.
        $this->assertSame('aktuell, zuerst, Dauer, Steuern', UmlautRepair::fix('aktuell, zuerst, Dauer, Steuern'));
    }

    public function test_fix_umlauts_command_repairs_service_pages(): void
    {
        $page = ServicePage::create([
            'slug' => 'test', 'title_de' => 'Test', 'title_ar' => 'x',
            'subtitle_de' => 'Alles verstaendlich erklaert', 'is_active' => true,
        ]);

        $this->artisan('service-pages:fix-umlauts', ['--write' => true])->assertSuccessful();

        $this->assertSame('Alles verständlich erklärt', $page->fresh()->subtitle_de);
    }

    public function test_seeded_service_pages_have_proper_umlauts(): void
    {
        $this->seed(\Database\Seeders\ServicePageSeeder::class);

        $kfz = ServicePage::where('slug', 'kfz-versicherung')->first();
        $this->assertStringContainsString('verständlich erklärt', $kfz->subtitle_de);
        $this->assertStringNotContainsString('verstaendlich', $kfz->subtitle_de);
    }

    public function test_service_page_image_url_is_always_relative(): void
    {
        $page = new ServicePage(['image_path' => 'service-pages/bild.png']);
        $this->assertSame('/storage/service-pages/bild.png', $page->imageUrl());

        // Historisch absolut gespeicherte IP-URLs (P0-6) werden repariert.
        $page = new ServicePage(['image_path' => 'http://187.127.70.161/storage/service-pages/bild.png']);
        $this->assertSame('/storage/service-pages/bild.png', $page->imageUrl());
    }

    public function test_fix_storage_urls_command_normalizes_db_values(): void
    {
        ServicePage::create([
            'slug' => 'test', 'title_de' => 'Test', 'title_ar' => 'x', 'is_active' => true,
            'image_path' => 'http://187.127.70.161/storage/service-pages/bild.png',
        ]);

        $this->artisan('website:fix-storage-urls', ['--write' => true])->assertSuccessful();

        $this->assertSame('service-pages/bild.png', ServicePage::first()->image_path);
    }
}
