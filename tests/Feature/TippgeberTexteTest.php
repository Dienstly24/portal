<?php

namespace Tests\Feature;

use App\Mail\CustomerMessageMail;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\ServicePage;
use App\Models\User;
use App\Support\Tippgeber\ServiceSeitenTexte;
use Database\Seeders\ServicePageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tippgeber-Rolle (Betreiber-Vorgabe 20.11.2026): Dienstly24 haelt keine
 * Erlaubnis nach Paragraph 34d GewO. Kein Kundentext darf den Eindruck
 * erwecken, Dienstly24 berate, vergleiche Versicherungstarife oder sei
 * Makler. Dieser Test ist der Waechter: jede oeffentliche Seite wird
 * gerendert und ihr SICHTBARER Text gegen die Wortliste geprueft.
 *
 * Ausgenommen sind die Rechtsseiten (Impressum, Erstinformation, AGB,
 * Datenschutz): dort steht ausdruecklich, dass Beratung und Vermittlung
 * AUSSCHLIESSLICH der zugelassene Partner uebernimmt - genau diese
 * Klarstellung ist der Schutz.
 */
class TippgeberTexteTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://www.dienstly24.de';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServicePageSeeder::class);
    }

    /** Sichtbarer Text: ohne Skripte, Stile und Tags. */
    private function sichtbar(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
        // Titel und Beschreibung zaehlen mit (stehen in Google)
        preg_match_all('#<(?:title)>(.*?)</title>|content="([^"]*)"#is', $html, $meta);

        return html_entity_decode(strip_tags($html).' '.implode(' ', $meta[1]).' '.implode(' ', $meta[2]), ENT_QUOTES);
    }

    /** @return list<string> */
    private function seiten(): array
    {
        $pfade = ['/', '/ar', '/dienstly24-hamburg', '/ar/dienstly24-hamburg', '/leistungen', '/ar/leistungen'];
        foreach (ServicePage::active()->pluck('slug') as $slug) {
            $pfade[] = '/leistungen/'.$slug;
            $pfade[] = '/ar/leistungen/'.$slug;
        }

        return $pfade;
    }

    public function test_keine_oeffentliche_seite_spricht_von_beratung_oder_vergleich(): void
    {
        $verstoesse = [];
        foreach ($this->seiten() as $pfad) {
            $html = $this->get(self::HOST.$pfad)->assertOk()->getContent();
            $funde = ServiceSeitenTexte::funde($this->sichtbar($html));
            if ($funde !== []) {
                $verstoesse[] = $pfad.': '.implode(', ', $funde);
            }
        }

        $this->assertSame([], $verstoesse, "Makler-/Beratungswortlaut auf oeffentlichen Seiten:\n".implode("\n", $verstoesse));
    }

    public function test_jede_seite_mit_versicherungsbezug_nennt_die_rolle(): void
    {
        foreach (['/', '/ar', '/dienstly24-hamburg', '/leistungen/kfz-versicherung', '/ar/leistungen/kfz-versicherung'] as $pfad) {
            $text = $this->sichtbar($this->get(self::HOST.$pfad)->assertOk()->getContent());
            $this->assertMatchesRegularExpression(
                '/kein Versicherungsvermittler|ليست وسيط تأمين|مو وسيط تأمين/u', $text, $pfad);
        }
    }

    public function test_alte_makler_adressen_leiten_dauerhaft_um(): void
    {
        $this->get('/versicherungsmakler-hamburg')->assertStatus(301)->assertRedirect('/dienstly24-hamburg');
        $this->get('/ar/versicherungsmakler-hamburg')->assertStatus(301)->assertRedirect('/ar/dienstly24-hamburg');
        $this->get('/leistungen/versicherungsmakler')->assertStatus(301)->assertRedirect('/leistungen/so-arbeiten-wir');
    }

    public function test_das_unternehmen_ist_im_schema_keine_versicherungsagentur(): void
    {
        foreach (['/', '/dienstly24-hamburg', '/leistungen/kfz-versicherung'] as $pfad) {
            $this->assertStringNotContainsString('InsuranceAgency', $this->get(self::HOST.$pfad)->getContent(), $pfad);
        }
    }

    public function test_seeder_texte_enthalten_nach_der_umstellung_keine_beratung(): void
    {
        $reste = [];
        foreach ((new ServicePageSeeder)->pages() as $seite) {
            $seite = ServicePageSeeder::neutral($seite);
            array_walk_recursive($seite, function ($wert) use (&$reste, $seite) {
                if (is_string($wert) && ($f = ServiceSeitenTexte::funde($wert)) !== []) {
                    $reste[] = $seite['slug'].': '.implode(', ', $f);
                }
            });
        }

        $this->assertSame([], $reste);
    }

    /**
     * Der Bestand in der Datenbank: die Migration ersetzt die bekannten
     * Saetze, benennt die Makler-Seite um, sichert vorher - und laesst
     * selbst geschriebenen Text stehen (er wird gemeldet).
     */
    public function test_umstellung_des_bestands_ersetzt_sichert_und_meldet(): void
    {
        Storage::fake('local');
        ServicePage::query()->delete();

        // Alter Startinhalt (vor 20.11.2026), wie er in der Datenbank steht.
        ServicePage::create([
            'slug' => 'versicherungsmakler', 'category' => 'versicherung', 'is_active' => true,
            'title_de' => 'Versicherungsmakler', 'title_ar' => 'وسيط تأمين',
            'intro_de' => 'Ein Versicherungsmakler vergleicht die Angebote mehrerer Anbieter.',
        ]);
        ServicePage::create([
            'slug' => 'kfz-versicherung', 'category' => 'versicherung', 'is_active' => true,
            'title_de' => 'Kfz-Versicherung', 'title_ar' => 'تأمين السيارة',
            'body_de' => "Intro.\n\n## So beraten wir Sie\nWir vergleichen anbieterunabhängig eine Vielzahl von Kfz-Tarifen und erklären Ihnen die Unterschiede verständlich – auf Deutsch und Arabisch. Sie entscheiden in Ruhe; wir begleiten Sie bis zum Abschluss und darüber hinaus.",
            'faq' => [['q_de' => 'Was kostet mich die Beratung?', 'q_ar' => 'قديش بتكلّفني الاستشارة؟',
                'a_de' => 'Die Beratung ist für Sie kostenlos und unverbindlich.', 'a_ar' => 'الاستشارة مجانية وبدون أي التزام.']],
        ]);
        ServicePage::create([
            'slug' => 'eigene-seite', 'category' => 'versicherung', 'is_active' => true,
            'title_de' => 'Eigene Seite', 'title_ar' => 'صفحة',
            'intro_de' => 'Unsere persönliche Beratung ist die beste.',
        ]);

        // Probelauf aendert nichts
        $this->artisan('website:tippgeber-texte')->assertSuccessful();
        $this->assertTrue(ServicePage::where('slug', 'versicherungsmakler')->exists());

        $this->artisan('website:tippgeber-texte', ['--schreiben' => true])
            ->expectsOutputToContain("Bitte im Admin pruefen: 'eigene-seite'")
            ->assertSuccessful();

        $this->assertFalse(ServicePage::where('slug', 'versicherungsmakler')->exists());
        $neu = ServicePage::where('slug', 'so-arbeiten-wir')->firstOrFail();
        $this->assertSame('So arbeiten wir', $neu->title_de);

        $kfz = ServicePage::where('slug', 'kfz-versicherung')->firstOrFail();
        $this->assertStringStartsWith("Intro.\n\n## So helfen wir Ihnen", $kfz->body_de);
        $this->assertSame([], ServiceSeitenTexte::funde($kfz->body_de.json_encode($kfz->faq, JSON_UNESCAPED_UNICODE)));

        // Selbst geschriebener Text bleibt - nie still ueberschrieben.
        $this->assertSame('Unsere persönliche Beratung ist die beste.', ServicePage::where('slug', 'eigene-seite')->value('intro_de'));

        // Sicherung der alten Texte
        $dateien = Storage::disk('local')->files('backups');
        $this->assertCount(1, $dateien);
        $this->assertStringContainsString('So beraten wir Sie', Storage::disk('local')->get($dateien[0]));

        // Zweiter Lauf: nichts mehr zu tun
        $this->artisan('website:tippgeber-texte', ['--schreiben' => true])
            ->expectsOutputToContain('Geaendert: 0 Seite(n)')->assertSuccessful();
    }

    /**
     * Die STATISCHE Uebergangs-Website (website/*.html, bis zum DNS-Umzug
     * live auf dienstly24.de) - sie wird von Hand hochgeladen und lief am
     * 20.11.2026 noch mit dem alten Wortlaut, waehrend die App schon
     * umgestellt war. Erstinformation/Impressum/Datenschutz sind die
     * Pflichtangaben des Partners und bleiben ausgenommen.
     */
    public function test_statische_website_spricht_nicht_von_beratung(): void
    {
        $verstoesse = [];
        foreach (glob(base_path('website/*.html')) as $datei) {
            if (in_array(basename($datei), ['erstinformation.html', 'impressum.html', 'datenschutz.html'], true)) {
                continue;
            }
            $funde = ServiceSeitenTexte::funde($this->sichtbar((string) file_get_contents($datei)));
            if ($funde !== []) {
                $verstoesse[] = basename($datei).': '.implode(', ', $funde);
            }
        }
        $index = (string) file_get_contents(base_path('website/index.html'));
        $this->assertStringNotContainsString('InsuranceAgency', $index);
        $this->assertStringNotContainsString('portal.dienstly24.de/login', $index);
        $this->assertStringContainsString('kein Versicherungsvermittler', $index);

        $this->assertSame([], $verstoesse, implode("\n", $verstoesse));
    }

    public function test_kunden_mails_nennen_keinen_berater(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Max Muster']);
        $kunde = Customer::create(['user_id' => $user->id, 'customer_number' => '2600778', 'preferred_lang' => 'de']);
        $nachricht = CustomerMessage::create(['customer_id' => $kunde->id, 'body' => 'Hallo', 'from_staff' => true]);

        foreach (['de', 'ar'] as $sprache) {
            $kunde->update(['preferred_lang' => $sprache]);
            $mail = new CustomerMessageMail($nachricht->fresh(), 'full');
            $html = $mail->render();
            $this->assertStringNotContainsString('Berater', $html.$mail->envelope()->subject);
            $this->assertStringNotContainsString('مستشار', $html.$mail->envelope()->subject);
        }
    }
}
