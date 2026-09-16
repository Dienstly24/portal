<?php

namespace Tests\Feature;

use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Ampel fuer die externe Ueberwachung (Audit 15.09.2026).
 *
 * BEFUND: `/admin/systemzustand.json` war ausdruecklich fuer eine
 * externe Ueberwachung gebaut ("HTTP 503, wenn etwas
 * handlungsbeduerftig ist"), lag aber hinter `auth`, `role`,
 * Passwortzwang und Zweitem Faktor. Ein anonymer Aufruf bekam eine 302
 * auf die Anmeldeseite - kein Ueberwachungsdienst kann damit etwas
 * anfangen. Die Ampel sah also nur, wer zufaellig hinschaute.
 *
 * Diese Tests halten beides fest: der Endpunkt ist von aussen nutzbar,
 * UND er oeffnet nichts ausser der Ampel.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-health-token-0123456789abcdef';

    public function test_ohne_gesetztes_token_gibt_es_den_endpunkt_nicht(): void
    {
        config(['security.health_token' => '']);

        $this->getJson('/gesundheit')->assertNotFound();
    }

    public function test_falsches_token_wird_abgewiesen(): void
    {
        config(['security.health_token' => self::TOKEN]);

        $this->getJson('/gesundheit')->assertNotFound();
        $this->getJson('/gesundheit?token=falsch')->assertNotFound();
        $this->withHeader('X-Health-Token', 'falsch')->getJson('/gesundheit')->assertNotFound();
    }

    /**
     * Die Drossel muss auch bei FALSCHEM Token greifen (Sicherheits-
     * Nachpruefung 15.09.2026). Sonst waere das Erraten des Tokens
     * unbegrenzt moeglich, obwohl an der Route eine Drossel steht.
     *
     * Beim Nachpruefen gemessen: Laravel fuehrt `throttle` ueber die
     * Middleware-Prioritaet ohnehin vor der Token-Pruefung aus, in
     * welcher Reihenfolge sie auch an der Route stehen. Dieser Test
     * sichert deshalb das VERHALTEN ab - er wuerde auch anschlagen,
     * wenn jemand die Drossel entfernt oder gegen eine eigene Pruefung
     * ohne Zaehler tauscht.
     */
    public function test_falsche_versuche_werden_gedrosselt(): void
    {
        config(['security.health_token' => self::TOKEN]);
        RateLimiter::clear(sha1('127.0.0.1'));

        $letzte = null;
        for ($i = 0; $i < 61; $i++) {
            $letzte = $this->getJson('/gesundheit?token=falsch');
        }

        $this->assertSame(429, $letzte->getStatusCode(),
            'Nach 60 Fehlversuchen muss die Drossel greifen - sonst steht sie hinter der Token-Pruefung.');
    }

    /**
     * 404 statt 403: ein 403 bestaetigt, dass es den Endpunkt gibt.
     * Wer ohne Token anklopft, soll nicht einmal das erfahren.
     */
    public function test_abweisung_ist_404_und_nicht_403(): void
    {
        config(['security.health_token' => self::TOKEN]);

        $this->getJson('/gesundheit?token=falsch')->assertStatus(404);
    }

    public function test_richtiges_token_im_header_wird_akzeptiert(): void
    {
        config(['security.health_token' => self::TOKEN]);

        $antwort = $this->withHeader('X-Health-Token', self::TOKEN)->getJson('/gesundheit');

        $this->assertContains($antwort->status(), [200, 503]);
        $antwort->assertJsonStructure(['status', 'checked_at', 'checks']);
    }

    public function test_richtiges_token_als_query_wird_akzeptiert(): void
    {
        config(['security.health_token' => self::TOKEN]);

        $antwort = $this->getJson('/gesundheit?token='.self::TOKEN);

        $this->assertContains($antwort->status(), [200, 503]);
    }

    public function test_bearer_token_wird_akzeptiert(): void
    {
        config(['security.health_token' => self::TOKEN]);

        $antwort = $this->withHeader('Authorization', 'Bearer '.self::TOKEN)->getJson('/gesundheit');

        $this->assertContains($antwort->status(), [200, 503]);
    }

    /** OHNE Anmeldung nutzbar - das ist der ganze Zweck. */
    public function test_endpunkt_funktioniert_ohne_anmeldung(): void
    {
        config(['security.health_token' => self::TOKEN]);

        $antwort = $this->getJson('/gesundheit?token='.self::TOKEN);

        $antwort->assertHeaderMissing('Location');
        $this->assertNotSame(302, $antwort->status(),
            'Eine Weiterleitung auf die Anmeldeseite war genau der Fehler.');
    }

    /**
     * Die Antwort darf NUR die Ampel enthalten - keine Zahlen, keine
     * Dienstnamen, keine Umgebungsangaben und unter keinen Umstaenden
     * einen Schluessel.
     */
    public function test_antwort_verraet_keine_betriebsdetails(): void
    {
        config([
            'security.health_token' => self::TOKEN,
            'services.anthropic.key' => 'sk-ant-streng-geheim-abcdef123456',
        ]);

        $roh = $this->getJson('/gesundheit?token='.self::TOKEN)->getContent();
        $daten = json_decode($roh, true);

        $this->assertSame(['status', 'checked_at', 'checks'], array_keys($daten),
            'Die Antwort darf nur Ampel, Zeitpunkt und Abschnittszustaende tragen.');

        foreach ($daten['checks'] as $zustand) {
            $this->assertContains($zustand, [
                SystemHealthService::OK,
                SystemHealthService::WARN,
                SystemHealthService::FAIL,
                SystemHealthService::INFO,
            ], 'Je Abschnitt darf NUR der Zustand stehen, keine Zusammenfassung.');
        }

        $this->assertStringNotContainsString('sk-ant-', $roh);
        $this->assertStringNotContainsString('geheim', $roh);
        $this->assertStringNotContainsString(self::TOKEN, $roh);
        // Keine Zusammenfassungstexte, keine Umgebungsangabe.
        $this->assertStringNotContainsString('summary', $roh);
        $this->assertStringNotContainsString('environment', $roh);
    }

    /** Handlungsbedarf muss als 503 sichtbar sein - sonst alarmiert niemand. */
    public function test_status_fail_ergibt_http_503(): void
    {
        config(['security.health_token' => self::TOKEN]);

        $this->mock(SystemHealthService::class, function ($mock) {
            $mock->shouldReceive('overview')->andReturn([
                'generated_at' => now(),
                'environment' => 'testing',
                'sections' => ['queue' => ['title' => 'Q', 'status' => SystemHealthService::FAIL, 'summary' => 'x']],
                'status' => SystemHealthService::FAIL,
            ]);
        });

        $this->getJson('/gesundheit?token='.self::TOKEN)->assertStatus(503);
    }

    public function test_status_ok_ergibt_http_200(): void
    {
        config(['security.health_token' => self::TOKEN]);

        $this->mock(SystemHealthService::class, function ($mock) {
            $mock->shouldReceive('overview')->andReturn([
                'generated_at' => now(),
                'environment' => 'testing',
                'sections' => ['queue' => ['title' => 'Q', 'status' => SystemHealthService::OK, 'summary' => 'x']],
                'status' => SystemHealthService::OK,
            ]);
        });

        $this->getJson('/gesundheit?token='.self::TOKEN)->assertStatus(200);
    }

    /**
     * Stuerzt die Pruefung selbst ab, darf der Endpunkt nicht mit einem
     * 500er antworten - von aussen sieht das aus wie ein Totalausfall.
     * Er meldet ehrlich 503, ohne Einzelheiten.
     */
    public function test_absturz_der_pruefung_ergibt_503_statt_500(): void
    {
        config(['security.health_token' => self::TOKEN]);

        $this->mock(SystemHealthService::class, function ($mock) {
            $mock->shouldReceive('overview')->andThrow(new \RuntimeException('Datenbank weg'));
        });

        $antwort = $this->getJson('/gesundheit?token='.self::TOKEN);

        $antwort->assertStatus(503);
        $this->assertStringNotContainsString('Datenbank weg', $antwort->getContent());
    }

    /** Die alte, angemeldete Ansicht bleibt unveraendert geschuetzt. */
    public function test_admin_json_bleibt_hinter_der_anmeldung(): void
    {
        config(['security.health_token' => self::TOKEN]);

        $this->get('/admin/systemzustand.json')->assertRedirect();
        $this->get('/admin/systemzustand.json?token='.self::TOKEN)->assertRedirect();
    }
}
