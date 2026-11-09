<?php

namespace Tests\Feature;

use App\Models\ServicePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rolle Tippgeber (Betreiber-Vorgabe 09.11.2026): Dienstly24 haelt keine
 * Erlaubnis/Registrierung nach Paragraph 34d GewO und ist deshalb auch KEIN
 * (vertraglich gebundener) Untervermittler. Beratung und Vermittlung erfolgen
 * ausschliesslich durch den Partnermakler NESA. Keine Rechtsseite darf
 * Dienstly24 als Vermittler darstellen.
 */
class TippgeberRolleTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://www.dienstly24.de';

    public function test_rechtsseiten_nennen_dienstly24_nicht_als_vermittler(): void
    {
        foreach (['/impressum', '/erstinformation', '/agb'] as $pfad) {
            $html = $this->get(self::HOST.$pfad)->assertOk()->getContent();

            $this->assertStringNotContainsString('vertraglich gebundener Vermittler', $html, $pfad);
            $this->assertStringNotContainsString('Untervermittler)', $html, $pfad);
            $this->assertStringContainsString('NESA Versicherung und Finanzen', $html, $pfad);
            if ($pfad !== '/agb') {
                $this->assertStringContainsString('D-N5J2-9T4LJ-96', $html, $pfad);
            }
        }
    }

    public function test_erstinformation_stellt_die_rollen_klar(): void
    {
        $this->get(self::HOST.'/erstinformation')
            ->assertOk()
            ->assertSee('Beratung, Vermittlung und Betreuung Ihrer Versicherungsverträge erfolgen ausschließlich durch diesen Partner.')
            ->assertSee('vermittelt keine Versicherungsverträge')
            ->assertSee('Vergütung für die Weiterleitung von Kontaktanfragen');
    }

    public function test_impressum_traegt_den_hinweis_nach_34d(): void
    {
        $this->get(self::HOST.'/impressum')
            ->assertOk()
            ->assertSee('ist nicht als Versicherungsvermittler tätig');
    }

    public function test_whatsapp_text_fragt_keine_beratung_bei_dienstly24_an(): void
    {
        $html = $this->get(self::HOST.'/impressum')->getContent();

        $this->assertStringNotContainsString(rawurlencode('ich interessiere mich für eine Beratung'), $html);
        $this->assertStringContainsString(rawurlencode('ich wünsche mir einen Rückruf von einem Experten'), $html);
    }

    /** Die Migration tauscht auf der LIVE-Seite nur die woertlichen Altsaetze. */
    public function test_migration_ersetzt_den_altsatz_auf_der_bestehenden_seite(): void
    {
        $seite = ServicePage::create([
            'slug' => 'versicherungsmakler',
            'title_de' => 'Versicherungsmakler',
            'title_ar' => 'وسيط تأمين',
            'body_de' => 'Vorher. Dienstly24 tritt dabei als vertraglich gebundener Vermittler unter dessen Haftung auf. Nachher.',
            'body_ar' => 'وDienstly24 بتشتغل كوسيط مرتبط تعاقدياً وتحت مسؤوليته.',
        ]);

        $migration = require database_path('migrations/2026_11_09_100000_tippgeber_text_versicherungsmakler_seite.php');
        $migration->up();

        $neu = DB::table('service_pages')->find($seite->id);
        $this->assertStringNotContainsString('vertraglich gebundener Vermittler', $neu->body_de);
        $this->assertStringContainsString('in eigener Verantwortung', $neu->body_de);
        $this->assertStringContainsString('Vorher.', $neu->body_de);
        $this->assertStringNotContainsString('كوسيط مرتبط تعاقدياً', $neu->body_ar);
    }
}
