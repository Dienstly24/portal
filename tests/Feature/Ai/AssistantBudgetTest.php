<?php

namespace Tests\Feature\Ai;

use App\Models\SystemSetting;
use App\Services\Ai\Assistant\AssistantBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Kostenbremse des WEBSITE-Assistenten (Audit 15.09.2026).
 *
 * BEFUND: `POST /api/website-assistent` ist oeffentlich, verlangt keine
 * Anmeldung und ruft je Nachricht das Modell. Geschuetzt war er nur
 * durch eine Drossel JE IP - 20 Anfragen je Minute sind rechnerisch
 * 28.800 Modellaufrufe am Tag aus einer Quelle, mit wechselnden
 * Adressen beliebig viele. Der Portal-Assistent hatte schon Grenzen,
 * ausgerechnet der oeffentliche Weg nicht.
 *
 * Diese Tests halten fest, dass eine erreichte Grenze den Modellaufruf
 * VERHINDERT (nicht nur die Antwort verwirft) und dass der Besucher
 * trotzdem eine Antwort und eine Uebergabe bekommt.
 */
class AssistantBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        SystemSetting::set('ai_assistant_auto_handover', '1');

        config([
            'services.openai.key' => 'sk-test-nur-fuer-tests',
            'services.openai.model' => 'gpt-5',
            'services.ai_assistant_provider' => 'openai',
        ]);

        Cache::flush();
    }

    private function fakeModell(string $text = 'Gern, dazu beraten wir Sie.'): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-5',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => $text]],
                ]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 30],
            ]),
        ]);
    }

    /** Eine fachliche Frage, die den kostenlosen Vorfilter passiert. */
    private function frage(): string
    {
        return 'Ich brauche ein Angebot für eine Kfz-Versicherung.';
    }

    private function senden(?string $text = null)
    {
        return $this->postJson(route('api.assistant.send'), [
            'nachricht' => $text ?? $this->frage(),
        ]);
    }

    // ================================================================
    // Die Grenzen selbst
    // ================================================================

    public function test_budget_meldet_erst_ab_der_grenze(): void
    {
        config(['services.ai_assistant.website_rate_per_hour' => 2]);
        $budget = app(AssistantBudget::class);

        $this->assertNull($budget->ueberschritten(AssistantBudget::BEREICH_WEBSITE, 'sitzung-a'));
        $budget->verbrauchen(AssistantBudget::BEREICH_WEBSITE, 'sitzung-a');
        $this->assertNull($budget->ueberschritten(AssistantBudget::BEREICH_WEBSITE, 'sitzung-a'));
        $budget->verbrauchen(AssistantBudget::BEREICH_WEBSITE, 'sitzung-a');

        $this->assertNotNull($budget->ueberschritten(AssistantBudget::BEREICH_WEBSITE, 'sitzung-a'),
            'Nach zwei Aufrufen muss die Stundengrenze greifen.');
    }

    public function test_grenze_gilt_je_sitzung_nicht_global(): void
    {
        config([
            'services.ai_assistant.website_rate_per_hour' => 1,
            'services.ai_assistant.website_daily_limit' => 0,
        ]);
        $budget = app(AssistantBudget::class);

        $budget->verbrauchen(AssistantBudget::BEREICH_WEBSITE, 'sitzung-a');

        $this->assertNotNull($budget->ueberschritten(AssistantBudget::BEREICH_WEBSITE, 'sitzung-a'));
        $this->assertNull($budget->ueberschritten(AssistantBudget::BEREICH_WEBSITE, 'sitzung-b'),
            'Eine fremde Sitzung darf nicht mitgesperrt werden.');
    }

    /**
     * DIE EIGENTLICHE ZUSICHERUNG: das Tagesbudget gilt ueber ALLE
     * Sitzungen - ein Wechsel von Sitzung oder IP umgeht es nicht.
     */
    public function test_tagesbudget_greift_auch_bei_wechselnder_sitzung(): void
    {
        config([
            'services.ai_assistant.website_rate_per_hour' => 0,
            'services.ai_assistant.website_session_daily_limit' => 0,
            'services.ai_assistant.website_daily_limit' => 3,
        ]);
        $budget = app(AssistantBudget::class);

        foreach (['a', 'b', 'c'] as $sitzung) {
            $this->assertNull($budget->ueberschritten(AssistantBudget::BEREICH_WEBSITE, $sitzung));
            $budget->verbrauchen(AssistantBudget::BEREICH_WEBSITE, $sitzung);
        }

        $this->assertNotNull(
            $budget->ueberschritten(AssistantBudget::BEREICH_WEBSITE, 'voellig-neue-sitzung'),
            'Das Tagesbudget muss unabhaengig von Sitzung und IP greifen.'
        );
        $this->assertSame(3, $budget->bereichsVerbrauch(AssistantBudget::BEREICH_WEBSITE));
    }

    public function test_portal_und_website_haben_getrennte_budgets(): void
    {
        config([
            'services.ai_assistant.website_daily_limit' => 1,
            'services.ai_assistant.daily_reply_limit' => 50,
        ]);
        $budget = app(AssistantBudget::class);

        $budget->verbrauchen(AssistantBudget::BEREICH_WEBSITE, 'x');

        $this->assertNotNull($budget->ueberschritten(AssistantBudget::BEREICH_WEBSITE, 'y'));
        $this->assertNull($budget->ueberschritten(AssistantBudget::BEREICH_PORTAL, 'kunde-1'),
            'Die Website darf das Budget des Portals nicht aufbrauchen.');
    }

    public function test_kennung_steht_nie_im_klartext_im_cache(): void
    {
        config(['services.ai_assistant.website_session_daily_limit' => 5]);
        $kennung = 'geheime-sitzungs-kennung-123';

        app(AssistantBudget::class)->verbrauchen(AssistantBudget::BEREICH_WEBSITE, $kennung);

        $this->assertGreaterThan(0, app(AssistantBudget::class)
            ->bereichsVerbrauch(AssistantBudget::BEREICH_WEBSITE));
        $this->assertNull(Cache::get('ai-budget:d:website:'.$kennung.':'.now()->format('Y-m-d')),
            'Die Kennung darf nicht im Klartext als Cache-Schluessel stehen.');
    }

    // ================================================================
    // Wirkung am echten Endpunkt
    // ================================================================

    public function test_unterhalb_der_grenze_wird_das_modell_gerufen(): void
    {
        $this->fakeModell('Gern - wir melden uns.');

        $this->senden()->assertOk()->assertJson(['ok' => true]);

        Http::assertSentCount(1);
    }

    /** Ist das Budget erschoepft, darf KEIN Modellaufruf mehr rausgehen. */
    public function test_bei_erreichtem_budget_geht_kein_modellaufruf_raus(): void
    {
        config(['services.ai_assistant.website_daily_limit' => 1]);
        $this->fakeModell();

        $this->senden()->assertOk();
        Http::assertSentCount(1);

        // Zweite Sitzung, frischer Besucher - trotzdem gesperrt.
        $this->flushSession();
        $antwort = $this->senden();

        $antwort->assertOk()->assertJson(['ok' => true, 'uebergeben' => true]);
        Http::assertSentCount(1);
    }

    /** Der Besucher bekommt trotzdem eine Antwort - keine Sackgasse. */
    public function test_bei_erreichtem_budget_bekommt_der_besucher_eine_antwort(): void
    {
        config(['services.ai_assistant.website_daily_limit' => 0]);
        config(['services.ai_assistant.website_rate_per_hour' => 1]);
        $this->fakeModell();

        $this->senden()->assertOk();
        $zweite = $this->senden()->assertOk();

        $this->assertTrue($zweite->json('ok'));
        $this->assertNotEmpty($zweite->json('antwort'),
            'Auch bei erreichter Grenze muss der Besucher eine verstaendliche Antwort bekommen.');
        $this->assertTrue($zweite->json('uebergeben'),
            'Der Kontakt muss an das Team uebergeben werden, statt verloren zu gehen.');
    }

    /** Der kostenlose Vorfilter verbraucht kein Budget. */
    public function test_abgewiesene_frage_verbraucht_kein_budget(): void
    {
        config(['services.ai_assistant.website_daily_limit' => 5]);
        $this->fakeModell();

        $this->senden('Wie wird das Wetter morgen in Hamburg?')->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, app(AssistantBudget::class)
            ->bereichsVerbrauch(AssistantBudget::BEREICH_WEBSITE),
            'Eine Frage, die den kostenlosen Vorfilter nicht passiert, darf nichts verbrauchen.');
    }

    public function test_zu_lange_nachricht_wird_abgelehnt(): void
    {
        $this->fakeModell();

        $this->postJson(route('api.assistant.send'), [
            'nachricht' => str_repeat('a', 2001),
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_antwortlaenge_ist_gedeckelt(): void
    {
        config(['services.ai_assistant.website_max_output_tokens' => 321]);
        $this->fakeModell();

        $this->senden()->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();
            $gefunden = $body['max_output_tokens'] ?? $body['max_tokens'] ?? null;

            return $gefunden === 321;
        });
    }

    public function test_route_drossel_greift_je_ip(): void
    {
        RateLimiter::clear('');
        $this->fakeModell();
        config(['services.ai_assistant.website_daily_limit' => 0, 'services.ai_assistant.website_rate_per_hour' => 0]);

        $gesperrt = false;
        for ($i = 0; $i < 12; $i++) {
            if ($this->senden()->status() === 429) {
                $gesperrt = true;
                break;
            }
        }

        $this->assertTrue($gesperrt, 'Die Route-Drossel muss vor dem zwoelften Versuch greifen.');
    }
}
