<?php

namespace Tests\Feature;

use App\Support\EnvFileWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Einrichtungs-Assistent php artisan meta:einrichten: Token abfragen,
 * Seite/Instagram automatisch finden, .env schreiben, Fehler erklaeren.
 */
class MetaSetupCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $envDatei;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envDatei = tempnam(sys_get_temp_dir(), 'envtest');
        file_put_contents($this->envDatei, "APP_NAME=Test\nMETA_PAGE_ID=alt\n");
        $this->app->bind(EnvFileWriter::class, fn () => new EnvFileWriter($this->envDatei));
    }

    protected function tearDown(): void
    {
        @unlink($this->envDatei);
        parent::tearDown();
    }

    public function test_assistent_findet_seite_und_instagram_und_schreibt_env(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/me/accounts')) {
                return Http::response(['data' => [[
                    'id' => '111222333',
                    'name' => 'Dienstly24',
                    'instagram_business_account' => ['id' => '444555666', 'username' => 'dienstly24'],
                ]]]);
            }
            return Http::response(['error' => ['message' => 'unerwartet']], 400);
        });

        $this->artisan('meta:einrichten')
            ->expectsQuestion('System-User-Token einfuegen (Eingabe bleibt unsichtbar)', 'TOK-GEHEIM')
            ->expectsOutputToContain('Dienstly24')
            ->expectsOutputToContain('@dienstly24')
            ->assertSuccessful();

        $env = file_get_contents($this->envDatei);
        // Vorhandener Schluessel ersetzt (nicht doppelt), Rest angehaengt.
        $this->assertStringContainsString("META_PAGE_ID=111222333\n", $env);
        $this->assertStringNotContainsString('META_PAGE_ID=alt', $env);
        $this->assertStringContainsString("META_IG_USER_ID=444555666\n", $env);
        $this->assertStringContainsString("META_ACCESS_TOKEN=TOK-GEHEIM\n", $env);
        $this->assertStringContainsString("APP_NAME=Test\n", $env);
        $this->assertSame(1, substr_count($env, 'META_PAGE_ID='));
    }

    public function test_mehrere_seiten_fuehren_zur_auswahl(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            ['id' => '1', 'name' => 'Seite A'],
            ['id' => '2', 'name' => 'Dienstly24'],
        ]])]);

        $this->artisan('meta:einrichten')
            ->expectsQuestion('System-User-Token einfuegen (Eingabe bleibt unsichtbar)', 'TOK')
            ->expectsChoice('Mehrere Seiten gefunden - welche soll das System nutzen?', 'Dienstly24', ['Seite A', 'Dienstly24'])
            ->assertSuccessful();

        $this->assertStringContainsString('META_PAGE_ID=2', file_get_contents($this->envDatei));
    }

    public function test_ohne_instagram_wird_nur_facebook_eingerichtet(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            ['id' => '111', 'name' => 'Dienstly24'],
        ]])]);

        $this->artisan('meta:einrichten')
            ->expectsQuestion('System-User-Token einfuegen (Eingabe bleibt unsichtbar)', 'TOK')
            ->expectsOutputToContain('KEIN Business-Konto')
            ->assertSuccessful();

        $this->assertStringContainsString("META_IG_USER_ID=\n", file_get_contents($this->envDatei));
    }

    public function test_ungueltiges_token_wird_erklaert_und_nichts_geschrieben(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Invalid OAuth access token.', 'code' => 190],
        ], 400)]);

        $this->artisan('meta:einrichten')
            ->expectsQuestion('System-User-Token einfuegen (Eingabe bleibt unsichtbar)', 'KAPUTT')
            ->expectsOutputToContain('ungueltig oder abgelaufen')
            ->assertFailed();

        $this->assertStringNotContainsString('KAPUTT', file_get_contents($this->envDatei));
        $this->assertStringContainsString('META_PAGE_ID=alt', file_get_contents($this->envDatei));
    }

    public function test_token_ohne_zugewiesene_seite_gibt_handlungshinweis(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []])]);

        $this->artisan('meta:einrichten')
            ->expectsQuestion('System-User-Token einfuegen (Eingabe bleibt unsichtbar)', 'TOK')
            ->expectsOutputToContain('Assets zuweisen')
            ->assertFailed();
    }

    public function test_pruefen_meldet_fehlende_konfiguration(): void
    {
        config(['services.meta' => ['page_id' => null, 'ig_user_id' => null, 'token' => null, 'graph_version' => 'v23.0']]);

        $this->artisan('meta:einrichten --pruefen')
            ->expectsOutputToContain('nicht konfiguriert')
            ->assertFailed();
    }

    public function test_pruefen_testet_bestehende_verbindung(): void
    {
        config(['services.meta' => ['page_id' => 'P1', 'ig_user_id' => 'I1', 'token' => 'TOK', 'graph_version' => 'v23.0']]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/P1')) {
                return Http::response(['name' => 'Dienstly24', 'id' => 'P1']);
            }
            return Http::response(['username' => 'dienstly24', 'id' => 'I1']);
        });

        $this->artisan('meta:einrichten --pruefen')
            ->expectsOutputToContain('Dienstly24')
            ->expectsOutputToContain('Verbindung OK')
            ->assertSuccessful();
    }
}
