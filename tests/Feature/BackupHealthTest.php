<?php

namespace Tests\Feature;

use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Sichtbarkeit der Sicherung (Audit 15.09.2026).
 *
 * BEFUND: `scripts/backup.sh` lief per Cron in eine Logdatei, die im
 * Alltag niemand oeffnet. Eine Sicherung, die seit Wochen scheitert,
 * meldet sich nicht von selbst - gemerkt haette man es erst beim
 * Wiederherstellen, also im schlechtesten denkbaren Moment. Dieselbe
 * Lehre wie bei den 500ern, die vor dem ErrorRecorder nur im Log
 * standen.
 *
 * Jetzt schreibt jeder Lauf eine Statusdatei, und die Systemzustand-
 * Seite UND der externe Endpunkt lesen sie.
 */
class BackupHealthTest extends TestCase
{
    use RefreshDatabase;

    private function statusSchreiben(array $daten): void
    {
        File::ensureDirectoryExists(storage_path('app/private'));
        File::put(storage_path('app/private/backup-status.json'), json_encode($daten));
    }

    protected function tearDown(): void
    {
        File::delete(storage_path('app/private/backup-status.json'));
        parent::tearDown();
    }

    public function test_ohne_statusdatei_meldet_der_abschnitt_nur_hinweis(): void
    {
        File::delete(storage_path('app/private/backup-status.json'));

        $abschnitt = app(SystemHealthService::class)->backup();

        $this->assertSame(SystemHealthService::INFO, $abschnitt['status'],
            'Ohne eingerichtetes Backup darf die Seite nicht rot werden - sie sagt nur, dass nichts erfasst ist.');
    }

    public function test_erfolgreicher_lauf_ist_gruen(): void
    {
        $this->statusSchreiben([
            'status' => 'ok',
            'zeitpunkt' => now()->subHours(3)->toIso8601String(),
            'meldung' => 'Sicherung erstellt, geprueft und extern abgelegt',
            'verschluesselt' => true,
            'extern' => true,
        ]);

        $abschnitt = app(SystemHealthService::class)->backup();

        $this->assertSame(SystemHealthService::OK, $abschnitt['status']);
    }

    public function test_fehlgeschlagener_lauf_ist_rot(): void
    {
        $this->statusSchreiben([
            'status' => 'fehler',
            'zeitpunkt' => now()->subHour()->toIso8601String(),
            'meldung' => 'DB-Dump ist nur 12 Byte gross',
            'verschluesselt' => true,
            'extern' => true,
        ]);

        $abschnitt = app(SystemHealthService::class)->backup();

        $this->assertSame(SystemHealthService::FAIL, $abschnitt['status']);
    }

    /**
     * Ein ALTER Erfolg ist kein Erfolg: laeuft der Cron nicht mehr,
     * bliebe die letzte Meldung sonst fuer immer auf "ok" stehen.
     */
    public function test_veralteter_erfolg_wird_rot(): void
    {
        $this->statusSchreiben([
            'status' => 'ok',
            'zeitpunkt' => now()->subDays(5)->toIso8601String(),
            'meldung' => 'Sicherung erstellt',
            'verschluesselt' => true,
            'extern' => true,
        ]);

        $abschnitt = app(SystemHealthService::class)->backup();

        $this->assertSame(SystemHealthService::FAIL, $abschnitt['status'],
            'Eine Sicherung, die seit Tagen nicht mehr lief, muss auffallen.');
    }

    public function test_unverschluesselte_sicherung_ist_rot(): void
    {
        $this->statusSchreiben([
            'status' => 'ok',
            'zeitpunkt' => now()->subHour()->toIso8601String(),
            'meldung' => 'Sicherung erstellt',
            'verschluesselt' => false,
            'extern' => false,
        ]);

        $abschnitt = app(SystemHealthService::class)->backup();

        $this->assertSame(SystemHealthService::FAIL, $abschnitt['status'],
            'Ein Archiv mit IBAN-, Ausweis- und Gesundheitsdaten darf nicht unverschluesselt liegen.');
    }

    public function test_fehlender_zweiter_speicherort_ist_eine_warnung(): void
    {
        $this->statusSchreiben([
            'status' => 'ok',
            'zeitpunkt' => now()->subHour()->toIso8601String(),
            'meldung' => 'Sicherung erstellt und geprueft (nur lokal)',
            'verschluesselt' => true,
            'extern' => false,
        ]);

        $abschnitt = app(SystemHealthService::class)->backup();

        $this->assertSame(SystemHealthService::WARN, $abschnitt['status']);
    }

    public function test_unlesbare_statusdatei_wird_gemeldet_statt_zu_stuerzen(): void
    {
        File::ensureDirectoryExists(storage_path('app/private'));
        File::put(storage_path('app/private/backup-status.json'), 'kein json {{{');

        $abschnitt = app(SystemHealthService::class)->backup();

        $this->assertSame(SystemHealthService::WARN, $abschnitt['status']);
    }

    /** Der Abschnitt taucht in der Gesamtuebersicht auf. */
    public function test_abschnitt_ist_teil_der_uebersicht(): void
    {
        $uebersicht = app(SystemHealthService::class)->overview();

        $this->assertArrayHasKey('backup', $uebersicht['sections']);
    }

    /** Und damit auch im externen Endpunkt. */
    public function test_externer_endpunkt_meldet_den_backup_zustand(): void
    {
        config(['security.health_token' => 'test-health-token-0123456789abcdef']);
        $this->statusSchreiben([
            'status' => 'fehler',
            'zeitpunkt' => now()->toIso8601String(),
            'meldung' => 'kaputt',
            'verschluesselt' => true,
            'extern' => true,
        ]);

        $antwort = $this->getJson('/gesundheit?token=test-health-token-0123456789abcdef');

        $antwort->assertStatus(503);
        $this->assertSame(SystemHealthService::FAIL, $antwort->json('checks.backup'));
    }

    /**
     * Die Statusdatei darf NIE Zugangsdaten enthalten - sie wird von der
     * Anwendung gelesen und landet in einer Anzeige.
     */
    public function test_statusdatei_traegt_keine_geheimnisse(): void
    {
        $this->statusSchreiben([
            'status' => 'ok',
            'zeitpunkt' => now()->toIso8601String(),
            'meldung' => 'Sicherung erstellt, geprueft und extern abgelegt',
            'verschluesselt' => true,
            'extern' => true,
        ]);

        $abschnitt = app(SystemHealthService::class)->backup();
        $ausgabe = json_encode($abschnitt, JSON_UNESCAPED_UNICODE);

        // Nur ja/nein - nie das Passwort und nie die Zieladresse.
        $this->assertStringNotContainsString('PASSPHRASE=', $ausgabe);
        $this->assertStringNotContainsString('BACKUP_REMOTE=', $ausgabe);
        $this->assertStringNotContainsString('passphrase', strtolower($ausgabe));

        // Und das Skript baut den Status aus festen Zeichenketten.
        $skript = File::get(base_path('scripts/backup.sh'));
        $this->assertStringContainsString('"verschluesselt":%s', $skript,
            'Der Status wird aus ja/nein gebaut - nicht aus dem Wert selbst.');
    }

    /** Ohne Passwort wird nichts an einen fremden Speicherort gegeben. */
    public function test_skript_verweigert_externe_ablage_ohne_verschluesselung(): void
    {
        $skript = File::get(base_path('scripts/backup.sh'));

        $this->assertStringContainsString(
            'BACKUP_REMOTE gesetzt, aber BACKUP_PASSPHRASE fehlt',
            $skript,
            'Unverschluesselte Kundendaten duerfen den Server nie verlassen.'
        );
    }

    /** Die Wiederherstellung schuetzt die produktive Datenbank. */
    public function test_restore_skript_schuetzt_die_produktion(): void
    {
        $this->assertFileExists(base_path('scripts/restore.sh'));

        $skript = File::get(base_path('scripts/restore.sh'));
        $this->assertStringContainsString('ist die PRODUKTIVE Datenbank', $skript,
            'Ein Restore darf nicht versehentlich die Produktion ueberschreiben.');
        $this->assertStringContainsString('--pruefen', $skript,
            'Es muss eine gefahrlose Probe geben - sonst uebt sie niemand.');
    }
}
