<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServicePage;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\ServicePageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Barrierefreiheit: Ueberschrift und Feldnamen (Audit 15.09.2026).
 *
 * BEFUND: KEINE der 114 Seiten der Beraterwelt hatte eine Ueberschrift
 * der ersten Ebene - die Seitentitel standen als <div class="page-title">
 * im Markup. Fuer das Auge derselbe Text, fuer eine Bildschirmlesehilfe
 * eine Seite voellig ohne Struktur. Dazu kamen rund 500 Formularfelder
 * ohne verknuepften Namen; das oeffentliche Anfrageformular der
 * Leistungsseiten war mit einer Lesehilfe praktisch nicht ausfuellbar.
 *
 * Dieser Test prueft das AUSGELIEFERTE HTML - nicht die Vorlage. Eine
 * Beschriftung, die nur im Quelltext danebensteht, ist nicht verknuepft.
 */
class BarrierefreiheitTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        SystemSetting::set('two_factor_required', '0');

        return User::factory()->create([
            'role' => 'admin',
            'must_change_password' => false,
        ]);
    }

    /**
     * Felder ohne zugaenglichen Namen finden.
     *
     * @return array<int, string>
     */
    private function felderOhneNamen(string $html): array
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);

        // Alle <label for="..."> einsammeln.
        $beschriftet = [];
        foreach ($xpath->query('//label[@for]') as $label) {
            $beschriftet[$label->getAttribute('for')] = true;
        }

        $ohne = [];
        foreach ($xpath->query('//input | //select | //textarea') as $feld) {
            $typ = strtolower($feld->getAttribute('type'));
            if (in_array($typ, ['hidden', 'submit', 'button', 'image', 'reset'], true)) {
                continue;
            }
            // Honeypot & Co: absichtlich vor Hilfsmitteln verborgen. Er
            // DARF keinen Namen haben - sonst fuellt ihn ein Nutzer mit
            // Lesehilfe aus und gilt als Bot.
            $verborgen = false;
            for ($n = $feld; $n instanceof \DOMElement; $n = $n->parentNode) {
                if ($n->getAttribute('aria-hidden') === 'true') {
                    $verborgen = true;
                    break;
                }
            }
            if ($verborgen) {
                continue;
            }
            if ($feld->getAttribute('aria-label') !== ''
                || $feld->getAttribute('aria-labelledby') !== ''
                || $feld->getAttribute('title') !== '') {
                continue;
            }
            $id = $feld->getAttribute('id');
            if ($id !== '' && isset($beschriftet[$id])) {
                continue;
            }
            // Ein umschliessendes <label> genuegt ebenfalls.
            $umschlossen = false;
            for ($n = $feld->parentNode; $n instanceof \DOMElement; $n = $n->parentNode) {
                if (strtolower($n->nodeName) === 'label') {
                    $umschlossen = true;
                    break;
                }
            }
            if (! $umschlossen) {
                $ohne[] = $feld->nodeName.'['.($feld->getAttribute('name') ?: $id ?: $typ).']';
            }
        }

        return $ohne;
    }

    private function ueberschriften(string $html): int
    {
        return preg_match_all('/<h1[\s>]/i', $html);
    }

    // ================================================================
    // Beraterwelt
    // ================================================================

    public static function adminSeiten(): array
    {
        return [
            ['/admin'], ['/admin/customers'], ['/admin/contracts'], ['/admin/tickets'],
            ['/admin/tasks'], ['/admin/reports'], ['/admin/settings'], ['/admin/systemzustand'],
            ['/admin/fehler'], ['/admin/signaturen'], ['/admin/dokumenten-eingang'],
            ['/admin/medien'], ['/admin/banners'], ['/admin/employees'], ['/admin/kundenchat'],
        ];
    }

    #[DataProvider('adminSeiten')]
    public function test_admin_seite_hat_genau_eine_hauptueberschrift(string $pfad): void
    {
        $html = $this->actingAs($this->admin())->get($pfad)->assertOk()->getContent();

        $this->assertSame(1, $this->ueberschriften($html),
            "{$pfad}: genau eine Ueberschrift der ersten Ebene erwartet.");
    }

    #[DataProvider('adminSeiten')]
    public function test_admin_seite_hat_keine_namenlosen_felder(string $pfad): void
    {
        $html = $this->actingAs($this->admin())->get($pfad)->assertOk()->getContent();
        $ohne = $this->felderOhneNamen($html);

        $this->assertSame([], $ohne,
            "{$pfad}: Formularfelder ohne zugaenglichen Namen: ".implode(', ', $ohne));
    }

    // ================================================================
    // Oeffentliche Seiten
    // ================================================================

    public static function oeffentlicheSeiten(): array
    {
        return [['/website'], ['/leistungen'], ['/login'], ['/register'], ['/hilfe'], ['/ar/']];
    }

    #[DataProvider('oeffentlicheSeiten')]
    public function test_oeffentliche_seite_hat_ueberschrift_und_benannte_felder(string $pfad): void
    {
        $this->seed(ServicePageSeeder::class);
        $html = $this->get($pfad)->assertOk()->getContent();

        $this->assertSame(1, $this->ueberschriften($html),
            "{$pfad}: genau eine Ueberschrift der ersten Ebene erwartet.");
        $this->assertSame([], $this->felderOhneNamen($html),
            "{$pfad}: Felder ohne Namen: ".implode(', ', $this->felderOhneNamen($html)));
    }

    /** Das oeffentliche Anfrageformular war der schlimmste Fall: 8 Felder ohne Namen. */
    public function test_anfrageformular_der_leistungsseite_ist_vollstaendig_beschriftet(): void
    {
        $this->seed(ServicePageSeeder::class);
        $seite = ServicePage::active()->firstOrFail();

        $html = $this->get('/leistungen/'.$seite->slug)->assertOk()->getContent();

        $this->assertSame([], $this->felderOhneNamen($html));
        $this->assertStringContainsString('for="anfrage-name"', $html,
            'Die uebersetzten Beschriftungen sollen ueber for/id verknuepft sein.');
    }

    // ================================================================
    // Kundenportal
    // ================================================================

    public function test_kundenportal_hat_ueberschriften_und_benannte_felder(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'must_change_password' => false]);
        Customer::create([
            'user_id' => $user->id,
            'customer_number' => '2600001',
            'preferred_lang' => 'de',
        ]);

        foreach (['/portal', '/portal/contracts', '/portal/documents', '/portal/profile',
            '/portal/bank', '/portal/addresses', '/portal/nachrichten'] as $pfad) {
            $html = $this->actingAs($user)->get($pfad)->assertOk()->getContent();

            $this->assertSame(1, $this->ueberschriften($html), "{$pfad}: keine/mehrere H1.");
            $this->assertSame([], $this->felderOhneNamen($html),
                "{$pfad}: Felder ohne Namen: ".implode(', ', $this->felderOhneNamen($html)));
        }
    }

    /**
     * Der Honeypot DARF keinen Namen haben - er ist vor Hilfsmitteln
     * verborgen, und ein Nutzer mit Lesehilfe wuerde ihn sonst ausfuellen
     * und als Bot gelten.
     */
    public function test_honeypot_bleibt_ohne_namen_und_verborgen(): void
    {
        $html = $this->get('/hilfe')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="website"[^>]*aria-hidden="true"/',
            $html,
            'Der Honeypot muss vor Hilfsmitteln verborgen bleiben.'
        );
    }
}
