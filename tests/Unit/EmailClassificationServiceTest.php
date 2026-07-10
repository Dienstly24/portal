<?php

namespace Tests\Unit;

use App\Models\EmailMessage;
use App\Services\Workflow\EmailClassificationService;
use Tests\TestCase;

class EmailClassificationServiceTest extends TestCase
{
    private function message(string $from, ?string $subject, ?string $body = null): EmailMessage
    {
        $message = new EmailMessage();
        $message->from_address = $from;
        $message->subject = $subject;
        $message->body_text = $body;
        return $message;
    }

    public function test_detects_fonds_finanz_by_sender_domain(): void
    {
        $service = new EmailClassificationService();
        $category = $service->classify($this->message('info@fondsfinanz.de', 'Neue Unterlagen', null));
        $this->assertSame('fonds_finanz', $category);
    }

    public function test_detects_versicherung_by_keyword(): void
    {
        $service = new EmailClassificationService();
        $category = $service->classify($this->message('kontakt@allianz.de', 'Schadenmeldung zu Ihrer Police', null));
        $this->assertSame('versicherung', $category);
    }

    public function test_detects_energie_by_keyword(): void
    {
        $service = new EmailClassificationService();
        $category = $service->classify($this->message('info@stadtwerke.de', 'Ihr Stromvertrag läuft aus', null));
        $this->assertSame('energie', $category);
    }

    public function test_detects_provisionen_by_keyword(): void
    {
        $service = new EmailClassificationService();
        $category = $service->classify($this->message('partner@makler.de', 'Provisionsabrechnung Juli', null));
        $this->assertSame('provisionen', $category);
    }

    public function test_detects_kundenanfrage_by_keyword(): void
    {
        $service = new EmailClassificationService();
        $category = $service->classify($this->message('kunde@example.com', 'Ich habe eine Frage zu meinem Vertrag', null));
        $this->assertSame('kundenanfrage', $category);
    }

    public function test_falls_back_to_sonstige(): void
    {
        $service = new EmailClassificationService();
        $category = $service->classify($this->message('irgendwer@example.com', 'Hallo', 'Nur ein Gruß, keine Kategorie erkennbar hier.'));
        $this->assertSame('sonstige', $category);
    }
}
