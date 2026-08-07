<?php

namespace Tests\Feature;

use App\Mail\WebsiteInquiryConfirmationMail;
use App\Models\ServicePage;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Leistungs-Anfrageformular (/leistungen/{slug}/anfrage): das Ticket muss -
 * wie beim Kontaktformular - source=website tragen, den DSGVO-Einwilligungs-
 * nachweis speichern UND dem Interessenten eine Eingangsbestaetigung schicken
 * (Audit-Nachweis 07.08.2026, FLOW-1/FLOW-2).
 */
class ServiceInquiryFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedPages(): void
    {
        // Eigene Leistungsseite OHNE Pflicht-Zusatzfelder, damit der Test
        // deterministisch die Kernlogik (Ticket + Einwilligung + Mail) prueft
        // und nicht an einem Pflichtfeld der Seed-Daten haengt.
        ServicePage::create([
            'slug' => 'audit-test-leistung',
            'category' => 'versicherung',
            'title_de' => 'Audit Test Leistung',
            'title_ar' => 'خدمة اختبار',
            'is_active' => true,
            'fields' => [],
        ]);
    }

    public function test_service_inquiry_creates_ticket_with_consent_and_confirmation_mail(): void
    {
        Mail::fake();
        $this->seedPages();

        $this->post('https://www.dienstly24.de/leistungen/audit-test-leistung/anfrage', [
            'name' => 'Testkunde Leistung',
            'email' => 'lead@example-testkunde.de',
            'message' => 'Bitte Angebot Kfz.',
            'consent' => '1',
        ])->assertRedirect();

        $ticket = Ticket::where('source', 'website')->first();
        $this->assertNotNull($ticket);
        $this->assertSame('offer', $ticket->type);
        $this->assertSame('lead@example-testkunde.de', $ticket->guest_email);

        // DSGVO-Nachweis muss gespeichert sein (war vorher NULL).
        $this->assertNotNull($ticket->consent_given_at);
        $this->assertNotNull($ticket->consent_ip);
        $this->assertStringContainsString('Ich stimme der Verarbeitung', $ticket->consent_text);

        // Interessent bekommt eine Eingangsbestaetigung (war vorher gar keine).
        Mail::assertQueued(WebsiteInquiryConfirmationMail::class, fn ($m) => $m->lang === 'de');
    }

    public function test_service_inquiry_without_email_still_creates_ticket_but_no_confirmation(): void
    {
        Mail::fake();
        $this->seedPages();

        $this->post('https://www.dienstly24.de/leistungen/audit-test-leistung/anfrage', [
            'name' => 'Nur Telefon',
            'phone' => '+49 170 1234567',
            'message' => 'Bitte anrufen.',
            'consent' => '1',
        ])->assertRedirect();

        $ticket = Ticket::where('source', 'website')->first();
        $this->assertNotNull($ticket);
        $this->assertNotNull($ticket->consent_given_at);
        Mail::assertNotQueued(WebsiteInquiryConfirmationMail::class);
    }

    public function test_service_inquiry_requires_consent(): void
    {
        $this->seedPages();

        $this->post('https://www.dienstly24.de/leistungen/audit-test-leistung/anfrage', [
            'name' => 'Ohne Einwilligung',
            'email' => 'noconsent@example-testkunde.de',
            'message' => 'Test',
        ])->assertSessionHasErrors('consent');

        $this->assertSame(0, Ticket::count());
    }
}
