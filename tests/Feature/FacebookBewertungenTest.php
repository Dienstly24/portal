<?php

namespace Tests\Feature;

use App\Models\FacebookBewertung;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Social\FacebookBewertungAbruf;
use App\Support\FacebookEmpfehlung;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Facebook-Empfehlungen auf der Startseite (Betreiber-Wunsch 21.11.2026):
 * nur freigegeben, nie umformuliert, keine Versicherungs-Beratung, kein
 * voller Name, und der Browser des Besuchers spricht nie mit Facebook.
 */
class FacebookBewertungenTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://www.dienstly24.de';

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('two_factor_required', '0');
        config([
            'services.meta.page_id' => '123456',
            'services.meta.page_token' => 'seiten-token',
            'services.meta.graph_version' => 'v23.0',
        ]);
    }

    private function fakeFacebook(array $daten, array $paging = []): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => $daten, 'paging' => $paging])]);
    }

    private function eintrag(string $id, string $text, string $typ = 'positive', string $name = 'Ahmad Albhre'): array
    {
        return [
            'created_time' => '2026-10-05T10:00:00+0000',
            'recommendation_type' => $typ,
            'review_text' => $text,
            'reviewer' => ['name' => $name, 'id' => '999'],
            'open_graph_story' => ['id' => $id],
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_abruf_legt_neue_empfehlungen_an_und_speichert_nur_den_kurznamen(): void
    {
        $this->fakeFacebook([
            $this->eintrag('s1', 'Super schneller Stromwechsel!'),
            $this->eintrag('s2', 'Leider nicht erreichbar.', 'negative', 'Max Mustermann'),
        ]);

        $this->artisan('facebook:bewertungen-holen')->assertSuccessful();

        $this->assertSame(2, FacebookBewertung::count());
        $s1 = FacebookBewertung::where('fb_kennung', 's1')->firstOrFail();
        $this->assertSame('Ahmad A.', $s1->name_kurz);
        // unbedenklich -> automatisch freigegeben (Voreinstellung AN)
        $this->assertSame('freigegeben', $s1->status);
        $this->assertDatabaseMissing('facebook_bewertungen', ['name_kurz' => 'Ahmad Albhre']);
        $this->assertSame(['positiv' => 1, 'gesamt' => 2], array_intersect_key(FacebookEmpfehlung::stand(), ['positiv' => 1, 'gesamt' => 1]));

        // Das Token geht im Header, nie in der URL
        Http::assertSent(fn ($r) => str_contains($r->url(), '/123456/ratings')
            && ! str_contains($r->url(), 'seiten-token')
            && $r->hasHeader('Authorization', 'Bearer seiten-token'));
    }

    public function test_nichts_erscheint_ohne_freigabe(): void
    {
        SystemSetting::set(FacebookBewertung::SETTING_AUTOMATISCH, '0');
        $this->fakeFacebook([$this->eintrag('s1', 'Super schneller Stromwechsel, danke!')]);
        $this->artisan('facebook:bewertungen-holen')->assertSuccessful();

        $this->get(self::HOST.'/')->assertOk()->assertDontSee('Super schneller Stromwechsel');

        FacebookBewertung::first()->update(['status' => 'freigegeben']);
        $this->get(self::HOST.'/')->assertOk()
            ->assertSee('Super schneller Stromwechsel, danke!')
            ->assertSee('Ahmad A.')
            ->assertSee('Empfehlung auf Facebook')
            ->assertSee('Ob die Verfasser Kunden von uns sind, können wir nicht prüfen')
            ->assertDontSee('Albhre');
        $this->get(self::HOST.'/ar')->assertOk()->assertSee('توصية على فيسبوك', false);
    }

    public function test_die_startseite_laedt_nichts_von_facebook(): void
    {
        Http::fake();
        FacebookBewertung::create(['fb_kennung' => 'x', 'text' => 'Toll', 'empfehlung' => 'positive', 'status' => 'freigegeben']);

        $html = $this->get(self::HOST.'/')->assertOk()->getContent();

        Http::assertNothingSent();
        $this->assertDoesNotMatchRegularExpression('#<(script|img|iframe)[^>]+(facebook\.com|fbcdn\.net)#i', $html);
    }

    public function test_versicherungsberatung_ist_gesperrt_energieberatung_nicht(): void
    {
        $admin = $this->admin();
        $versicherung = FacebookBewertung::create(['fb_kennung' => 'v', 'text' => 'Tolle Beratung zur Kfz-Versicherung!', 'empfehlung' => 'positive']);
        $strom = FacebookBewertung::create(['fb_kennung' => 's', 'text' => 'Tolle Beratung beim Stromvertrag!', 'empfehlung' => 'positive']);

        $this->actingAs($admin)->post(route('admin.facebook_bewertungen.status', $versicherung->id), ['status' => 'freigegeben'])
            ->assertSessionHasErrors('status');
        $this->assertSame('neu', $versicherung->fresh()->status);

        $this->actingAs($admin)->post(route('admin.facebook_bewertungen.status', $strom->id), ['status' => 'freigegeben'])
            ->assertSessionHasNoErrors();
        $this->assertSame('freigegeben', $strom->fresh()->status);
        $this->assertSame($admin->id, $strom->fresh()->geprueft_von);

        // Beratung + Strom ist unbedenklich, Beratung ohne Bezug nicht
        $this->assertSame([], $strom->hinweise());
        $this->assertSame('pruefen', (new FacebookBewertung(['text' => 'Gute Beratung', 'empfehlung' => 'positive']))->hinweise()[0]['art']);
    }

    public function test_negative_oder_leere_empfehlung_kann_nicht_freigegeben_werden(): void
    {
        $admin = $this->admin();
        $neg = FacebookBewertung::create(['fb_kennung' => 'n', 'text' => 'Schlecht', 'empfehlung' => 'negative']);
        $leer = FacebookBewertung::create(['fb_kennung' => 'l', 'text' => null, 'empfehlung' => 'positive']);

        foreach ([$neg, $leer] as $b) {
            $this->actingAs($admin)->post(route('admin.facebook_bewertungen.status', $b->id), ['status' => 'freigegeben'])
                ->assertSessionHasErrors('status');
            $this->assertSame('neu', $b->fresh()->status);
        }
    }

    public function test_geaenderter_text_braucht_neue_freigabe_und_geloeschte_verschwindet(): void
    {
        SystemSetting::set(FacebookBewertung::SETTING_AUTOMATISCH, '0');
        Http::fakeSequence('graph.facebook.com/*')
            ->push(['data' => [$this->eintrag('s1', 'Alter Text'), $this->eintrag('s2', 'Bleibt')]])
            ->push(['data' => [$this->eintrag('s1', 'Neuer Text')]]);
        $this->artisan('facebook:bewertungen-holen')->assertSuccessful();
        FacebookBewertung::query()->update(['status' => 'freigegeben']);

        $this->artisan('facebook:bewertungen-holen')->assertSuccessful();

        $s1 = FacebookBewertung::where('fb_kennung', 's1')->firstOrFail();
        $this->assertSame('Neuer Text', $s1->text);
        $this->assertSame('neu', $s1->status);

        $s2 = FacebookBewertung::where('fb_kennung', 's2')->firstOrFail();
        $this->assertNotNull($s2->entfernt_at);
        $this->assertSame(0, FacebookBewertung::sichtbar()->count());
    }

    public function test_fehlende_berechtigung_wird_verstaendlich_gemeldet_und_loescht_nichts(): void
    {
        FacebookBewertung::create(['fb_kennung' => 'alt', 'text' => 'Gut', 'empfehlung' => 'positive', 'status' => 'freigegeben']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
            'message' => '(#200) Requires pages_read_user_content permission', 'code' => 200,
        ]], 403)]);

        $this->artisan('facebook:bewertungen-holen')
            ->expectsOutputToContain('pages_read_user_content')
            ->assertFailed();

        $this->assertNull(FacebookBewertung::where('fb_kennung', 'alt')->value('entfernt_at'));
    }

    public function test_ohne_einrichtung_endet_der_lauf_still(): void
    {
        config(['services.meta.page_id' => null]);
        Http::fake();

        $this->artisan('facebook:bewertungen-holen')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_blaettert_ueber_mehrere_seiten(): void
    {
        Http::fakeSequence('graph.facebook.com/*')
            ->push(['data' => [$this->eintrag('a', 'Eins')], 'paging' => ['cursors' => ['after' => 'c1'], 'next' => 'https://graph.facebook.com/x']])
            ->push(['data' => [$this->eintrag('b', 'Zwei')], 'paging' => ['cursors' => ['after' => 'c2']]]);

        $this->artisan('facebook:bewertungen-holen')->assertSuccessful();
        $this->assertSame(2, FacebookBewertung::count());
    }

    public function test_nur_verwaltung_sieht_die_pruefseite(): void
    {
        $b = FacebookBewertung::create(['fb_kennung' => 'x', 'text' => 'Gut', 'empfehlung' => 'positive']);
        $mitarbeiter = User::factory()->create(['role' => 'employee']);

        // Die Rollen-Middleware leitet zur Startseite der Beraterwelt um.
        $this->actingAs($mitarbeiter)->get(route('admin.facebook_bewertungen'))->assertRedirect('/admin');
        $this->actingAs($mitarbeiter)->post(route('admin.facebook_bewertungen.status', $b->id), ['status' => 'freigegeben'])->assertRedirect('/admin');
        $this->assertSame('neu', $b->fresh()->status);

        $this->actingAs($this->admin())->get(route('admin.facebook_bewertungen'))->assertOk()->assertSee('Gut');
    }

    /** Betreiber-Wunsch 21.11.2026: "keine Zeit, selbst auszuwaehlen". */
    public function test_automatik_gibt_nur_unbedenkliches_frei(): void
    {
        $this->fakeFacebook([
            $this->eintrag('ok', 'Super schneller Service, sehr freundlich!'),
            $this->eintrag('energie', 'Tolle Beratung beim Stromvertrag, danke!'),
            $this->eintrag('versicherung', 'Gute Beratung zur Kfz-Versicherung'),
            $this->eintrag('unklar', 'Sehr gute Beratung!'),
            $this->eintrag('makler', 'Bester Makler in Hamburg'),
            $this->eintrag('versicherung2', 'Haben mir mit der Haftpflicht geholfen'),
            $this->eintrag('negativ', 'Nicht erreichbar', 'negative'),
            $this->eintrag('leer', ''),
        ]);

        $this->artisan('facebook:bewertungen-holen')
            ->expectsOutputToContain('automatisch freigegeben: 2')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['ok', 'energie'], FacebookBewertung::sichtbar()->pluck('fb_kennung')->all());
        $this->assertTrue(FacebookBewertung::where('fb_kennung', 'ok')->value('auto_freigegeben'));
        $this->assertSame('neu', FacebookBewertung::where('fb_kennung', 'versicherung')->value('status'));
    }

    public function test_automatik_laesst_abgelehnte_in_ruhe_und_ist_abschaltbar(): void
    {
        FacebookBewertung::create(['fb_kennung' => 'weg', 'text' => 'Toll', 'empfehlung' => 'positive', 'status' => 'abgelehnt']);
        $this->fakeFacebook([$this->eintrag('weg', 'Toll')]);
        $this->artisan('facebook:bewertungen-holen')->assertSuccessful();
        $this->assertSame('abgelehnt', FacebookBewertung::where('fb_kennung', 'weg')->value('status'));

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.facebook_bewertungen.automatik'), ['an' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(FacebookBewertung::automatikAn());

        $neu = FacebookBewertung::create(['fb_kennung' => 'n', 'text' => 'Klasse', 'empfehlung' => 'positive']);
        $this->assertSame(0, app(FacebookBewertungAbruf::class)->automatischFreigeben());
        $this->assertSame('neu', $neu->fresh()->status);

        // Einschalten gibt sofort frei
        $this->actingAs($admin)->post(route('admin.facebook_bewertungen.automatik'), ['an' => '1']);
        $this->assertSame('freigegeben', $neu->fresh()->status);
    }

    public function test_kurzname(): void
    {
        $this->assertSame('Ahmad A.', FacebookBewertung::kurzname('Ahmad Al Bhre'));
        $this->assertSame('Laila', FacebookBewertung::kurzname('Laila'));
        $this->assertNull(FacebookBewertung::kurzname('  '));
        $this->assertSame('أحمد ا.', FacebookBewertung::kurzname('أحمد البحري'));
    }
}
