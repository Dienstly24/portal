<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests fuer `netz:client-ip-pruefen` (Audit SEC-2, Netzwerkteil).
 *
 * Der Befehl beantwortet die Frage, die im Repository nicht steht: sieht
 * die Anwendung die echte Client-IP oder die Adresse des Vorschalt-
 * Dienstes? Er darf dabei NIE raten - ohne Daten sagt er "unklar".
 */
class ClientIpChainCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,int> */
    private array $nutzer = [];

    private function nutzer(int $nummer): int
    {
        return $this->nutzer[$nummer] ??= User::factory()->create()->id;
    }

    private function log(string $ip, ?int $nummer, int $anzahl = 1): void
    {
        $userId = $nummer === null ? null : $this->nutzer($nummer);

        for ($i = 0; $i < $anzahl; $i++) {
            DB::table('activity_logs')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'action' => 'seite',
                'ip' => $ip,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);
        }
    }

    public function test_ohne_daten_wird_nichts_behauptet(): void
    {
        $this->artisan('netz:client-ip-pruefen')
            ->expectsOutputToContain('Unklar')
            ->assertExitCode(0);
    }

    public function test_verteilte_adressen_gelten_als_korrekte_kette(): void
    {
        $this->log('203.0.113.10', 1, 5);
        $this->log('198.51.100.7', 2, 4);
        $this->log('192.0.2.33', 3, 3);

        $this->artisan('netz:client-ip-pruefen')
            ->expectsOutputToContain('sieht die echte Client-IP')
            ->assertExitCode(0);
    }

    public function test_eine_adresse_fuer_viele_nutzer_wird_als_vorschalt_dienst_gemeldet(): void
    {
        // Der gemeldete Zustand: das CDN reicht von EINER externen
        // Adresse weiter, die nicht in der Vertrauensliste steht.
        $this->log('203.0.113.200', 1, 20);
        $this->log('203.0.113.200', 2, 20);
        $this->log('203.0.113.200', 3, 20);
        $this->log('198.51.100.1', 4, 1);

        $this->artisan('netz:client-ip-pruefen')
            ->expectsOutputToContain('sieht NICHT die echte Client-IP')
            ->expectsOutputToContain('TRUSTED_PROXIES')
            ->assertExitCode(1);
    }

    public function test_loopback_ohne_weitergereichte_ip_ist_ebenfalls_ein_befund(): void
    {
        // Loopback steht in der Vertrauensliste - kommt es trotzdem als
        // Client-IP an, setzt der lokale Webserver keinen
        // X-Forwarded-For-Header. Auch das ist ein Fehler, nur ein
        // anderer.
        $this->log('127.0.0.1', 1, 10);
        $this->log('127.0.0.1', 2, 10);
        $this->log('127.0.0.1', 3, 10);

        $this->artisan('netz:client-ip-pruefen')
            ->expectsOutputToContain('vhost-Konfiguration')
            ->assertExitCode(1);
    }

    public function test_der_befehl_aendert_nichts(): void
    {
        $this->log('203.0.113.10', 1, 3);
        $vorher = DB::table('activity_logs')->count();

        $this->artisan('netz:client-ip-pruefen');

        $this->assertSame($vorher, DB::table('activity_logs')->count());
    }
}
