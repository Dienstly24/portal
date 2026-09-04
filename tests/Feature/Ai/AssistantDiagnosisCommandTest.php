<?php

namespace Tests\Feature\Ai;

use App\Models\AiAssistantLog;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Selbstdiagnose `ki:pruefen` (Betreiber-Auftrag 18.08.2026).
 *
 * Der Befehl existiert, weil ein ausgefallener Assistent IMMER gleich
 * aussieht (es kommt keine Antwort), die Ursachen aber verschiedene
 * Loesungen haben. Diese Tests sichern die zwei Aussagen, auf die sich
 * der Betreiber verlassen koennen muss: er nennt das gerissene Glied
 * beim Namen, und er meldet Erfolg nur, wenn die Kette wirklich steht.
 */
class AssistantDiagnosisCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Werkszustand: der Hauptschalter ist AUS - genau das muss dastehen. */
    public function test_meldet_den_ausgeschalteten_hauptschalter_als_blocker(): void
    {
        config(['services.anthropic.key' => 'sk-ant-test']);

        $this->artisan('ki:pruefen')
            ->expectsOutputToContain('Hauptschalter ist AUS')
            ->expectsOutputToContain('/admin/settings')
            ->assertExitCode(1);
    }

    /** Fehlender Schluessel ist ein eigener Blocker mit eigener Loesung. */
    public function test_meldet_fehlenden_schluessel_als_blocker(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        config(['services.anthropic.key' => '']);

        $this->artisan('ki:pruefen')
            ->expectsOutputToContain('Kein Zugangsschluessel gesetzt')
            ->expectsOutputToContain('ANTHROPIC_API_KEY')
            ->assertExitCode(1);
    }

    /** Ein abgeschalteter Anbieter darf nicht als "laeuft" durchgehen. */
    public function test_meldet_abgeschalteten_anbieter(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        config(['services.ai_assistant_provider' => 'none']);

        $this->artisan('ki:pruefen')
            ->expectsOutputToContain('bewusst abgeschaltet')
            ->assertExitCode(1);
    }

    /** Steht die Kette, meldet der Befehl Erfolg (Exitcode 0). */
    public function test_meldet_erfolg_wenn_die_kette_steht(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        config(['services.anthropic.key' => 'sk-ant-test']);

        $this->artisan('ki:pruefen')
            ->expectsOutputToContain('Kette vollstaendig')
            ->assertExitCode(0);
    }

    /**
     * Der Live-Test ist die einzige Pruefung, die Schluessel, Endpunkt UND
     * Modellfreigabe wirklich beweist - ein Fehler muss deshalb im Klartext
     * dastehen und den Befehl scheitern lassen.
     */
    public function test_live_aufruf_meldet_den_fehler_des_dienstes(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        config(['services.anthropic.key' => 'sk-ant-test']);

        Http::fake([
            '*' => Http::response(['error' => ['message' => 'model not found']], 404),
        ]);

        // Bewusst ueber Artisan::call: der ganze Fehlertext steht in EINER
        // Zeile, und expectsOutputToContain verbraucht je Erwartung eine
        // eigene Zeile.
        $code = Artisan::call('ki:pruefen', ['--live' => true]);
        $ausgabe = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Aufruf fehlgeschlagen', $ausgabe);
        $this->assertStringContainsString('HTTP 404', $ausgabe);
    }

    /** Erfolgreicher Live-Aufruf: der Dienst hat geantwortet. */
    public function test_live_aufruf_meldet_erreichbaren_dienst(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        config(['services.anthropic.key' => 'sk-ant-test']);

        Http::fake([
            '*' => Http::response([
                'model' => 'claude-opus-5',
                'content' => [['type' => 'text', 'text' => 'OK']],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 2],
            ], 200),
        ]);

        $this->artisan('ki:pruefen', ['--live' => true])
            ->expectsOutputToContain('Der KI-Dienst hat geantwortet')
            ->assertExitCode(0);
    }

    /** Der Schluessel darf in KEINER Ausgabe auftauchen. */
    public function test_gibt_den_schluessel_niemals_aus(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        config(['services.anthropic.key' => 'sk-ant-streng-geheim-123456']);

        $this->artisan('ki:pruefen')->assertExitCode(0);

        $ausgabe = Artisan::output();
        $this->assertStringNotContainsString('streng-geheim', $ausgabe);
        $this->assertStringNotContainsString('123456', $ausgabe);
    }

    /** Fallback-Runden im Protokoll sind der Hinweis auf einen Dienstfehler. */
    public function test_weist_auf_fallback_runden_im_protokoll_hin(): void
    {
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        config(['services.anthropic.key' => 'sk-ant-test']);

        AiAssistantLog::create([
            'outcome' => AiAssistantLog::OUTCOME_FALLBACK,
            'in_scope' => true,
            'handover' => true,
        ]);

        $this->artisan('ki:pruefen')
            ->expectsOutputToContain('endeten im Fallback')
            ->assertExitCode(0);
    }
}
