<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Services\SpamFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpamFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_gambling_advert_is_flagged(): void
    {
        $this->assertTrue(SpamFilter::isSpam([
            '888starz apk',
            '888starz download 888starz download apk jetzt herunterladen',
        ]));
    }

    public function test_many_links_are_flagged(): void
    {
        $this->assertTrue(SpamFilter::isSpam([
            'Werbung',
            'http://a.example http://b.example http://c.example http://d.example',
        ]));
    }

    public function test_mojibake_message_is_flagged(): void
    {
        // Kaputt kodierter Text (UTF-8 als Latin-1), wie in echten Bot-Mails.
        $garbage = str_repeat('Ù�Ù�Ø«Ù� Ø§Ù�تØ·Ø¨Ù�Ù� ', 3);
        $this->assertTrue(SpamFilter::isSpam(['888starz apk_zfpn', $garbage]));
    }

    public function test_legitimate_german_request_is_not_flagged(): void
    {
        $this->assertFalse(SpamFilter::isSpam([
            'Max Mustermann',
            'Guten Tag, ich interessiere mich fuer eine Kfz-Versicherung und '
            . 'bitte um ein Angebot. Vielen Dank.',
        ]));
    }

    public function test_legitimate_arabic_request_is_not_flagged(): void
    {
        $this->assertFalse(SpamFilter::isSpam([
            'محمد',
            'مرحبا، بدي عرض لتأمين السيارة من فضلكم. شكرا جزيلا.',
        ]));
    }

    public function test_single_link_is_allowed(): void
    {
        $this->assertFalse(SpamFilter::isSpam([
            'Anna Beispiel',
            'Mein Profil finden Sie unter https://example.com – bitte um Rueckruf.',
        ]));
    }

    public function test_website_inquiry_api_drops_spam_silently(): void
    {
        config(['services.inquiry.token' => 'secret-token']);

        $this->postJson('/api/website-inquiry', [
            'name' => '888starz apk_zfpn',
            'email' => 'bot@spam.example',
            'message' => '888starz download 888starz download apk herunterladen jetzt',
        ], ['X-Inquiry-Token' => 'secret-token'])
            ->assertOk()
            ->assertJson(['success' => true]);

        // Kein Ticket trotz "success" (stille Verwerfung).
        $this->assertSame(0, Ticket::count());
    }
}
