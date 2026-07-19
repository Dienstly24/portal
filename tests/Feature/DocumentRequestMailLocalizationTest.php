<?php

namespace Tests\Feature;

use App\Mail\DocumentRequestMail;
use App\Models\Customer;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit I18N-3: Die Dokumentanfrage-Mail muss - wie die Willkommens-Mail -
 * zweisprachig (DE/AR) mit RTL ausgeliefert werden, je nach
 * preferred_lang des Kunden.
 */
class DocumentRequestMailLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function documentRequest(string $lang): DocumentRequest
    {
        $user = User::factory()->create([
            'role' => 'customer', 'name' => 'Ahmad Albhre', 'email' => 'ahmad@kunde.de',
        ]);
        $customer = Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'K-' . uniqid(),
            'preferred_lang' => $lang,
        ]);

        return DocumentRequest::create([
            'customer_id' => $customer->id,
            'title' => 'Personalausweis',
            'status' => 'open',
        ]);
    }

    public function test_german_customer_gets_german_mail(): void
    {
        $mail = new DocumentRequestMail($this->documentRequest('de'));
        $html = $mail->render();

        $this->assertStringContainsString('Wir benötigen ein Dokument von Ihnen', $html);
        $this->assertStringContainsString('Dokument jetzt hochladen', $html);
        $this->assertStringContainsString('dir="ltr"', $html);
        $this->assertStringContainsString('Dokument benötigt: Personalausweis', $mail->envelope()->subject);
    }

    public function test_arabic_customer_gets_arabic_rtl_mail(): void
    {
        $mail = new DocumentRequestMail($this->documentRequest('ar'));
        $html = $mail->render();

        $this->assertStringContainsString('نحتاج مستنداً منك', $html);
        $this->assertStringContainsString('ارفع المستند الآن', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="ar"', $html);
        $this->assertStringContainsString('مستند مطلوب: Personalausweis', $mail->envelope()->subject);
    }
}
