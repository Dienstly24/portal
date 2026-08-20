<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use App\Models\User;
use App\Support\ErrorRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fehler, die echte Nutzer treffen, dürfen nicht in einer Logdatei
 * verschwinden, die im Alltag niemand oeffnet.
 *
 * Die Tests halten drei Entscheidungen fest: es wird ZUSAMMENGEFASST (eine
 * Zeile je Fingerabdruck), es werden NUR DEFEKTE gezaehlt (kein 404, keine
 * Validierung) und es landen KEINE personenbezogenen Inhalte in der
 * Tabelle.
 */
class ErrorVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // -------------------------------------------------- Was gezaehlt wird

    public function test_ein_echter_defekt_wird_festgehalten(): void
    {
        ErrorRecorder::record(new \RuntimeException('Datenbank nicht erreichbar'));

        $eintrag = ErrorEvent::firstOrFail();
        $this->assertSame(\RuntimeException::class, $eintrag->exception_class);
        $this->assertSame('Datenbank nicht erreichbar', $eintrag->message);
        $this->assertSame(1, $eintrag->occurrences);
        $this->assertNotNull($eintrag->first_seen_at);
        $this->assertNotNull($eintrag->last_seen_at);
    }

    /**
     * Normales Nutzerverhalten ist kein Defekt. Wuerde es mitgezaehlt, ginge
     * der eine echte Fehler zwischen tausend Rauscheintraegen unter.
     */
    public function test_normales_nutzerverhalten_wird_nicht_als_fehler_gezaehlt(): void
    {
        $harmlos = [
            new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('nicht da'),
            new \Illuminate\Auth\AuthenticationException(),
            new \Illuminate\Session\TokenMismatchException(),
            new \Illuminate\Database\Eloquent\ModelNotFoundException(),
            new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('verboten'),
        ];

        foreach ($harmlos as $e) {
            $this->assertFalse(ErrorRecorder::shouldRecord($e), $e::class . ' sollte kein Defekt sein.');
            ErrorRecorder::record($e);
        }

        $this->assertSame(0, ErrorEvent::count());
    }

    public function test_ein_serverfehler_zaehlt_auch_als_http_ausnahme(): void
    {
        // Generische HttpException statt einer Unterklasse: die Regel haengt
        // am STATUS (>= 500), nicht an einer bestimmten Klasse.
        $this->assertTrue(ErrorRecorder::shouldRecord(
            new \Symfony\Component\HttpKernel\Exception\HttpException(503, 'Wartung')
        ));
        $this->assertTrue(ErrorRecorder::shouldRecord(
            new \Symfony\Component\HttpKernel\Exception\HttpException(500, 'Serverfehler')
        ));
        // Und die Gegenprobe: alles unter 500 ist eine Antwort an den
        // Nutzer, kein Systemfehler.
        $this->assertFalse(ErrorRecorder::shouldRecord(
            new \Symfony\Component\HttpKernel\Exception\HttpException(429, 'Zu viele Anfragen')
        ));
    }

    // ------------------------------------------------------ Zusammenfassen

    public function test_derselbe_fehler_wird_gezaehlt_statt_dupliziert(): void
    {
        for ($i = 0; $i < 5; $i++) {
            ErrorRecorder::record($this->immerGleicherFehler());
        }

        // EIN Problem, nicht fuenf.
        $this->assertSame(1, ErrorEvent::count());
        $this->assertSame(5, ErrorEvent::first()->occurrences);
    }

    public function test_verschiedene_fehler_bleiben_getrennt(): void
    {
        ErrorRecorder::record(new \RuntimeException('A'));
        ErrorRecorder::record(new \LogicException('B'));

        $this->assertSame(2, ErrorEvent::count());
    }

    public function test_ein_erneutes_auftreten_oeffnet_einen_erledigten_fehler_wieder(): void
    {
        ErrorRecorder::record($this->immerGleicherFehler());
        $eintrag = ErrorEvent::firstOrFail();
        $eintrag->forceFill(['resolved_at' => now()->subHour(), 'resolved_by' => 1])->save();

        ErrorRecorder::record($this->immerGleicherFehler());

        // "Behoben" ist ein Fehler erst, wenn er ausbleibt.
        $this->assertNull($eintrag->fresh()->resolved_at);
        $this->assertSame(2, $eintrag->fresh()->occurrences);
    }

    // -------------------------------------------------------- Datenschutz

    public function test_es_landen_keine_formularinhalte_in_der_tabelle(): void
    {
        // Ein Request mit sensiblen Feldern - nichts davon darf gespeichert werden.
        $this->call('GET', '/hilfe', ['iban' => 'DE02120300000000202051', 'geheim' => 'passwort123']);

        ErrorRecorder::record(new \RuntimeException('Irgendetwas ging schief'));

        $roh = json_encode(ErrorEvent::firstOrFail()->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['DE02120300000000202051', 'passwort123', 'geheim'] as $wert) {
            $this->assertStringNotContainsString($wert, $roh);
        }
    }

    public function test_eine_lange_meldung_wird_gekuerzt(): void
    {
        ErrorRecorder::record(new \RuntimeException(str_repeat('x', 900)));

        $this->assertSame(500, mb_strlen(ErrorEvent::firstOrFail()->message));
    }

    // ------------------------------------------------------------ Ansicht

    public function test_die_fehlerliste_ist_admin_und_manager_vorbehalten(): void
    {
        foreach (['admin', 'manager'] as $rolle) {
            $this->actingAs(User::factory()->create(['role' => $rolle]))
                ->get(route('admin.errors'))->assertOk();
        }

        $this->actingAs(User::factory()->create(['role' => 'employee']))
            ->get(route('admin.errors'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_erledigt_markieren_und_wieder_oeffnen(): void
    {
        ErrorRecorder::record(new \RuntimeException('Kaputt'));
        $eintrag = ErrorEvent::firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.errors.resolve', $eintrag->id))->assertRedirect();
        $this->assertNotNull($eintrag->fresh()->resolved_at);
        $this->assertSame($admin->id, $eintrag->fresh()->resolved_by);

        $this->actingAs($admin)->post(route('admin.errors.reopen', $eintrag->id))->assertRedirect();
        $this->assertNull($eintrag->fresh()->resolved_at);
    }

    public function test_die_liste_trennt_offen_und_erledigt(): void
    {
        ErrorRecorder::record(new \RuntimeException('Offener Fehler'));
        ErrorRecorder::record(new \LogicException('Erledigter Fehler'));
        ErrorEvent::where('exception_class', \LogicException::class)
            ->update(['resolved_at' => now()]);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.errors'))
            ->assertSee('Offener Fehler')->assertDontSee('Erledigter Fehler');

        $this->actingAs($admin)->get(route('admin.errors', ['erledigt' => 1]))
            ->assertSee('Erledigter Fehler')->assertDontSee('Offener Fehler');
    }

    // ------------------------------------------------------ Systemzustand

    public function test_der_systemzustand_meldet_neue_fehler(): void
    {
        ErrorRecorder::record(new \RuntimeException('Frischer Defekt'));

        $abschnitt = app(\App\Services\SystemHealthService::class)->errors();

        $this->assertSame(\App\Services\SystemHealthService::FAIL, $abschnitt['status']);
        $eintrag = collect($abschnitt['items'])->firstWhere('label', 'Neue Fehler (24 h)');
        $this->assertSame('1', $eintrag['value']);
    }

    public function test_ohne_fehler_ist_der_abschnitt_gruen(): void
    {
        $abschnitt = app(\App\Services\SystemHealthService::class)->errors();

        $this->assertSame(\App\Services\SystemHealthService::OK, $abschnitt['status']);
    }

    // ------------------------------------------------------- Aufraeumen

    public function test_aufraeumen_loescht_nur_erledigte_alte_eintraege(): void
    {
        ErrorRecorder::record(new \RuntimeException('Alt und erledigt'));
        ErrorEvent::query()->update([
            'resolved_at' => now()->subDays(60),
            'last_seen_at' => now()->subDays(60),
        ]);

        ErrorRecorder::record(new \LogicException('Alt aber offen'));
        ErrorEvent::where('exception_class', \LogicException::class)
            ->update(['last_seen_at' => now()->subDays(60)]);

        $this->artisan('errors:prune')->assertSuccessful();

        // Ein offener Fehler bleibt stehen, egal wie alt: ein Problem
        // verschwindet nicht durch Ignorieren.
        $this->assertSame(0, ErrorEvent::where('exception_class', \RuntimeException::class)->count());
        $this->assertSame(1, ErrorEvent::where('exception_class', \LogicException::class)->count());
    }

    /** Immer dieselbe Zeile - damit der Fingerabdruck stabil bleibt. */
    private function immerGleicherFehler(): \RuntimeException
    {
        return new \RuntimeException('Immer derselbe Fehler');
    }
}
