<?php
namespace Tests\Feature;

use App\Mail\SupportInquiryMail;
use App\Mail\TicketReplyMail;
use App\Models\Banner;
use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImprovementRoundTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $email = null): Customer
    {
        $user = User::factory()->create(['role' => 'customer'] + ($email ? ['email' => $email] : []));
        return Customer::create(['user_id' => $user->id, 'customer_number' => 'C-' . strtoupper(substr(md5((string)$user->id),0,6))]);
    }

    // Punkt 1: Mitarbeiter sieht in "Zuletzt geöffnete Kunden" nur eigene
    public function test_employee_dashboard_shows_only_assigned_recent_customers(): void
    {
        $mine = $this->makeCustomer();
        $foreign = $this->makeCustomer();
        $employee = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $mine->betreuer()->attach($employee->id);

        $res = $this->actingAs($employee)->get(route('admin.dashboard'))->assertOk();
        $res->assertSee($mine->user->name);
        $res->assertDontSee($foreign->user->name);
    }

    public function test_admin_dashboard_shows_all_recent_customers(): void
    {
        $a = $this->makeCustomer(); $b = $this->makeCustomer();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()->assertSee($a->user->name)->assertSee($b->user->name);
    }

    // Punkt 2: Dashboard-Karten verlinken auf die Übersichten
    public function test_admin_dashboard_cards_are_links(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee(route('admin.customers'))
            ->assertSee(route('admin.contracts'))
            ->assertSee(route('admin.tickets'))
            ->assertSee(route('admin.change_requests'));
    }

    // Punkt 3: nur aktive Banner im Zeitfenster erscheinen im Portal
    public function test_only_current_banners_show_in_portal(): void
    {
        Banner::create(['title' => 'Aktiv Jetzt', 'media_path' => 'banners/a.jpg']);
        Banner::create(['title' => 'Abgelaufen', 'media_path' => 'banners/b.jpg', 'end_date' => now()->subDay()]);
        Banner::create(['title' => 'Deaktiviert', 'media_path' => 'banners/c.jpg', 'is_active' => false]);

        $customer = $this->makeCustomer();
        $res = $this->actingAs($customer->user)->get(route('portal.dashboard'))->assertOk();
        $res->assertSee('Aktiv Jetzt');
        $res->assertDontSee('Abgelaufen');
        $res->assertDontSee('Deaktiviert');
    }

    // Punkt 3: Admin-Bannerverwaltung (erstellen + deaktivieren)
    public function test_admin_can_create_and_toggle_banner(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.banners.store'), [
            'title' => 'Stromwechsel Juli 2026',
            'media' => UploadedFile::fake()->image('promo.jpg', 800, 300),
            'sort_order' => 1,
        ])->assertSessionHas('success');

        $banner = Banner::first();
        $this->assertSame('image', $banner->media_type);
        Storage::disk('public')->assertExists($banner->media_path);

        $this->actingAs($admin)->post(route('admin.banners.toggle', $banner->id));
        $this->assertFalse($banner->fresh()->is_active);
    }

    // Punkt 4: Banner-Klick erstellt referenzierte Supportanfrage, kein Duplikat
    public function test_banner_click_creates_referenced_ticket_once(): void
    {
        $banner = Banner::create(['title' => 'Stromwechsel Juli 2026', 'media_path' => 'banners/x.jpg']);
        $customer = $this->makeCustomer();

        $this->actingAs($customer->user)->get(route('portal.banner.interest', $banner->id))
            ->assertRedirect();
        $ticket = Ticket::first();
        $this->assertSame('Interesse: Stromwechsel Juli 2026', $ticket->subject);
        $this->assertStringContainsString('interessiert sich für das Angebot', $ticket->description);
        $this->assertStringContainsString('Banner #' . $banner->id, $ticket->description);

        // zweiter Klick -> gleiche offene Anfrage, kein Duplikat
        $this->actingAs($customer->user)->get(route('portal.banner.interest', $banner->id));
        $this->assertSame(1, Ticket::count());
    }

    // Punkt 5: Kunde hängt Datei an, sicher gespeichert, Support kann laden
    public function test_customer_reply_attachment_is_private_and_downloadable_by_support(): void
    {
        Storage::fake('local');
        $customer = $this->makeCustomer();
        $ticket = Ticket::create(['customer_id' => $customer->id, 'type' => 'other', 'status' => 'open', 'subject' => 's', 'description' => 'd']);

        $this->actingAs($customer->user)->post(route('portal.tickets.reply', $ticket->id), [
            'body' => 'Anbei das Dokument.',
            'attachments' => [UploadedFile::fake()->create('nachweis.webp', 80, 'image/webp')],
        ])->assertSessionHas('success');

        $att = TicketAttachment::first();
        $this->assertSame('local', $att->disk);
        Storage::disk('local')->assertExists($att->file_path);

        // Kunde selbst kann laden
        $this->actingAs($customer->user)->get(route('portal.attachment.download', $att->id))->assertOk();
        // Support kann laden
        $support = User::factory()->create(['role' => 'support', 'can_see_all_customers' => true]);
        $this->actingAs($support)->get(route('admin.attachment.download', $att->id))->assertOk();
        // Fremder Kunde nicht
        $other = $this->makeCustomer();
        $this->actingAs($other->user)->get(route('portal.attachment.download', $att->id))->assertNotFound();
    }

    // Punkt 6: Arabisch + Umlaute überleben die Mail-Pipeline (UTF-8)
    public function test_mail_encoding_preserves_arabic_and_umlauts(): void
    {
        $user = User::factory()->create(['name' => 'Jürgen Müllerß', 'role' => 'customer']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'C-ENC', 'gender' => 'male', 'preferred_lang' => 'ar']);
        $ticket = Ticket::create(['customer_id' => $customer->id, 'type' => 'other', 'status' => 'open', 'subject' => 'Prüfung Änderung ÄÖÜ', 'description' => 'د']);

        $html = (new TicketReplyMail($ticket, 'x'))->render();
        $this->assertStringContainsString('لديك رسالة جديدة في بوابة العملاء', $html);
        $this->assertStringContainsString('charset=UTF-8', $html);
        $this->assertStringContainsString('http-equiv', $html);
    }

    // Punkt 7: Support-Mail enthält Name, Kundennummer, E-Mail, Betreff, Zeit
    public function test_website_inquiry_sends_detailed_support_mail(): void
    {
        Mail::fake();
        config(['services.inquiry.token' => 'secret-token', 'services.inquiry.support_email' => 'support@dienstly24.de']);
        $customer = $this->makeCustomer('bestand@kunde.de');

        $this->postJson('/api/website-inquiry', [
            'name' => 'Erika Müller', 'email' => 'bestand@kunde.de',
            'subject' => 'Stromtarif Frage', 'message' => 'Bitte um Rückruf.',
        ], ['X-Inquiry-Token' => 'secret-token'])->assertOk();

        Mail::assertSent(SupportInquiryMail::class, function ($mail) use ($customer) {
            $env = $mail->envelope();
            $html = $mail->render();
            return str_contains($env->subject, 'Erika Müller')
                && str_contains($env->subject, $customer->customer_number)
                && str_contains($html, 'Erika Müller')
                && str_contains($html, $customer->customer_number)
                && str_contains($html, 'bestand@kunde.de')
                && str_contains($html, 'Stromtarif Frage')
                && str_contains($html, now()->format('d.m.Y'));
        });

        // Ticket wurde dem Bestandskunden zugeordnet
        $this->assertSame((string)$customer->id, (string)Ticket::first()->customer_id);
    }
}
