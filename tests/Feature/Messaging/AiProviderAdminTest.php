<?php

namespace Tests\Feature\Messaging;

use App\Models\AiProviderAccount;
use App\Models\User;
use App\Services\Ai\Assistant\AiProviderSettings;
use App\Services\Ai\Assistant\ClaudeAssistantProvider;
use App\Services\Ai\Assistant\Contracts\AssistantProviderInterface;
use App\Services\Ai\Assistant\OpenAiAssistantProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * KI-Anbieter aus der Oberflaeche (Auftrag Abschnitte 81/95/96/101).
 *
 * Der Schwerpunkt liegt auf der RANGFOLGE: Schluessel und Anbieter
 * koennen jetzt aus zwei Quellen kommen, und zwei Quellen ohne erklaerte
 * Rangfolge sind eine Zufallsentscheidung. Diese Faelle halten sie fest.
 */
class AiProviderAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'email' => 'a'.uniqid().'@dienstly24.de']);
    }

    private function zugang(array $attrs = []): AiProviderAccount
    {
        return AiProviderAccount::create(array_merge([
            'provider' => 'claude',
            'name' => 'Anthropic produktiv',
            'credentials' => ['api_key' => 'SK-GEHEIM-1'],
            'is_active' => true,
        ], $attrs));
    }

    private function settings(): AiProviderSettings
    {
        // Bewusst frisch: der Dienst merkt sich den Zugang je Anfrage.
        return new AiProviderSettings;
    }

    /** Fall 1: Ohne gepflegten Zugang gilt unveraendert die .env. */
    public function test_ohne_zugang_gilt_die_env(): void
    {
        config(['services.ai_assistant_provider' => 'claude', 'services.anthropic.key' => 'ENV-KEY']);

        $s = $this->settings();
        $this->assertSame('claude', $s->provider());
        $this->assertSame('ENV-KEY', $s->apiKey('claude', 'ENV-KEY'));
        $this->assertStringContainsString('.env', $s->explain()['source']);
    }

    /** Fall 2: Ein aktiver Zugang mit Schluessel schlaegt die .env. */
    public function test_gepflegter_zugang_schlaegt_die_env(): void
    {
        config(['services.ai_assistant_provider' => 'openai', 'services.anthropic.key' => 'ENV-KEY']);
        $this->zugang();

        $s = $this->settings();
        $this->assertSame('claude', $s->provider());
        $this->assertSame('SK-GEHEIM-1', $s->apiKey('claude', 'ENV-KEY'));
        $this->assertStringContainsString('Oberflaeche', $s->explain()['source']);
    }

    /**
     * Fall 3: DIE NOTBREMSE schlaegt alles. Ein Notaus, den eine
     * Datenbankzeile aushebeln kann, ist keiner.
     */
    public function test_notbremse_in_der_env_schlaegt_den_zugang(): void
    {
        config(['services.ai_assistant_provider' => 'none']);
        $this->zugang();

        $this->assertSame('none', $this->settings()->provider());
    }

    /** Fall 4: Ein Zugang OHNE Schluessel zaehlt nicht - er ist nicht einsatzbereit. */
    public function test_zugang_ohne_schluessel_wird_nicht_verwendet(): void
    {
        config(['services.ai_assistant_provider' => 'claude', 'services.anthropic.key' => 'ENV-KEY']);
        $this->zugang(['credentials' => null]);

        $s = $this->settings();
        $this->assertSame('ENV-KEY', $s->apiKey('claude', 'ENV-KEY'));
        $this->assertStringContainsString('.env', $s->explain()['source']);
    }

    /** Fall 5: Ein INAKTIVER Zugang wirkt nicht. */
    public function test_inaktiver_zugang_wirkt_nicht(): void
    {
        config(['services.ai_assistant_provider' => 'claude', 'services.anthropic.key' => 'ENV-KEY']);
        $this->zugang(['is_active' => false]);

        $this->assertSame('ENV-KEY', $this->settings()->apiKey('claude', 'ENV-KEY'));
    }

    /** Fall 6: Das Modell folgt derselben Regel - leer heisst "wie bisher". */
    public function test_modell_kommt_aus_dem_zugang_sonst_aus_der_konfiguration(): void
    {
        $this->zugang(['model' => 'claude-sonnet-5']);
        $this->assertSame('claude-sonnet-5', $this->settings()->model('claude', 'standard'));

        AiProviderAccount::query()->update(['model' => null]);
        $this->assertSame('standard', $this->settings()->model('claude', 'standard'));
    }

    /** Fall 7: Der Anbieter-Adapter greift die Rangfolge tatsaechlich auf. */
    public function test_der_adapter_nutzt_den_gepflegten_zugang(): void
    {
        config(['services.anthropic.key' => '', 'services.ai_assistant_provider' => 'claude']);
        // Ohne Zugang und ohne .env-Schluessel ist der Adapter aus.
        $this->assertFalse(app(ClaudeAssistantProvider::class)->isEnabled());

        $this->zugang();
        $this->assertTrue(app(ClaudeAssistantProvider::class)->isEnabled());
    }

    /** Fall 8: Die Anbieterwahl der Anwendung folgt dem gepflegten Zugang. */
    public function test_anwendung_waehlt_den_anbieter_des_zugangs(): void
    {
        config(['services.ai_assistant_provider' => 'claude', 'services.openai.key' => '']);
        $this->zugang(['provider' => 'openai', 'credentials' => ['api_key' => 'SK-OPENAI']]);

        $this->assertInstanceOf(OpenAiAssistantProvider::class, app(AssistantProviderInterface::class));
    }

    /** Fall 9: Der Schluessel erscheint nie in der Oberflaeche. */
    public function test_schluessel_erscheint_nie_in_der_oberflaeche(): void
    {
        $this->zugang();

        $antwort = $this->actingAs($this->admin())->get('/admin/ki-anbieter');

        $antwort->assertOk();
        $antwort->assertDontSee('SK-GEHEIM-1');
        $antwort->assertSee('gesetzt');
    }

    /** Fall 10: Ein leeres Feld loescht den Schluessel nicht. */
    public function test_leeres_feld_loescht_den_schluessel_nicht(): void
    {
        $zugang = $this->zugang();

        $this->actingAs($this->admin())
            ->put('/admin/ki-anbieter/'.$zugang->id, [
                'provider' => 'claude',
                'name' => 'Anthropic produktiv',
                'model' => 'claude-opus-5',
                'api_key' => '',
                'is_active' => '1',
            ])->assertRedirect();

        $frisch = $zugang->fresh();
        $this->assertSame('SK-GEHEIM-1', $frisch->apiKey());
        $this->assertSame('claude-opus-5', $frisch->model);
    }

    /** Fall 11: Der Schluessel liegt verschluesselt in der Datenbank. */
    public function test_schluessel_liegt_verschluesselt_in_der_datenbank(): void
    {
        $zugang = $this->zugang();

        $roh = (string) DB::table('ai_provider_accounts')->where('id', $zugang->id)->value('credentials');
        $this->assertStringNotContainsString('SK-GEHEIM-1', $roh);
    }

    /** Fall 12: Immer hoechstens EIN aktiver Zugang. */
    public function test_nur_ein_zugang_ist_aktiv(): void
    {
        $erster = $this->zugang(['name' => 'Erster']);

        $this->actingAs($this->admin())->post('/admin/ki-anbieter', [
            'provider' => 'openai',
            'name' => 'Zweiter',
            'api_key' => 'SK-2',
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertFalse($erster->fresh()->is_active);
        $this->assertSame(1, AiProviderAccount::where('is_active', true)->count());
    }

    /** Fall 13: Im Protokoll steht die Handlung, nie der Schluessel. */
    public function test_protokoll_enthaelt_keinen_schluessel(): void
    {
        $this->actingAs($this->admin())->post('/admin/ki-anbieter', [
            'provider' => 'claude',
            'name' => 'Protokoll-Zugang',
            'api_key' => 'SK-NICHT-INS-LOG',
        ])->assertRedirect();

        $zeilen = DB::table('activity_logs')->where('action', 'ai_provider_created')->get();
        $this->assertCount(1, $zeilen);
        foreach ($zeilen as $zeile) {
            $this->assertStringNotContainsString('SK-NICHT-INS-LOG', json_encode($zeile));
        }
    }

    /** Fall 14: Nur admin. */
    public function test_nur_admin_kommt_an_die_ki_anbieter(): void
    {
        foreach (['manager', 'support', 'employee'] as $rolle) {
            $user = User::factory()->create(['role' => $rolle, 'email' => $rolle.uniqid().'@example.de']);
            $this->actingAs($user)->get('/admin/ki-anbieter')->assertRedirect();
        }

        $this->actingAs($this->admin())->get('/admin/ki-anbieter')->assertOk();
    }

    /** Fall 15: Entfernen fuehrt zurueck auf die .env, nicht in einen Ausfall. */
    public function test_entfernen_faellt_auf_die_env_zurueck(): void
    {
        config(['services.ai_assistant_provider' => 'claude', 'services.anthropic.key' => 'ENV-KEY']);
        $zugang = $this->zugang();

        $this->actingAs($this->admin())
            ->delete('/admin/ki-anbieter/'.$zugang->id)
            ->assertRedirect();

        $this->assertSame('ENV-KEY', $this->settings()->apiKey('claude', 'ENV-KEY'));
    }
}
