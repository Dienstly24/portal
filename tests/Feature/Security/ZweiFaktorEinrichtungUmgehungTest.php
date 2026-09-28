<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * KI-042 (Nachpruefung vor dem Merge von PR #358): die Einrichtungsseite
 * der Zwei-Faktor-Anmeldung war ein zweiter Pruefweg.
 *
 * Die Einrichtungs-Routen sind in JEDEM Zustand erreichbar (sonst saesse
 * ein neuer Mitarbeiter in einer Sackgasse). `setupStore` pruefte den Code
 * aber auch dann, wenn der zweite Faktor laengst bestaetigt war - gegen
 * dasselbe Geheimnis wie die Abfrage, nur OHNE deren Sperre (5 Versuche,
 * 300 s) und OHNE Protokoll. Wer nur das Passwort hatte, riet dort
 * unsichtbar und zehnmal schneller; ein Treffer liess ihn herein und
 * ersetzte die Ersatzcodes des Mitarbeiters.
 *
 * Jeder Fall unten scheitert ohne die Aenderung.
 */
class ZweiFaktorEinrichtungUmgehungTest extends TestCase
{
    use RefreshDatabase;

    private const ERSATZCODE = 'AAAAA-BBBBB';

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::updateOrCreate(['key' => 'two_factor_required'], ['value' => '1']);
    }

    private function mitarbeiter(): User
    {
        return User::factory()->create(['role' => 'employee', 'email' => uniqid().'@dienstly24.de']);
    }

    /** Konto mit bestaetigtem zweiten Faktor, wie es der Mitarbeiter eingerichtet hat. */
    private function eingerichtet(User $user): string
    {
        $secret = Totp::generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now()->subMonth(),
            'two_factor_recovery_codes' => [Hash::make(self::ERSATZCODE)],
        ])->save();

        return $secret;
    }

    /**
     * Der Angreifer kennt das Passwort, nicht den zweiten Faktor. Selbst
     * mit dem RICHTIGEN Code fuehrt die Einrichtung nicht an der Abfrage
     * vorbei - es wird dort gar nichts mehr geprueft.
     */
    public function test_eingerichtetes_konto_kann_die_einrichtung_nicht_als_abfrage_benutzen(): void
    {
        $user = $this->mitarbeiter();
        $secret = $this->eingerichtet($user);
        $bestaetigtAm = $user->fresh()->two_factor_confirmed_at;

        $this->actingAs($user)->post(route('two_factor.setup.store'), ['code' => Totp::code($secret)])
            ->assertRedirect(route('two_factor.challenge'));

        // Zustand: der zweite Faktor gilt in dieser Sitzung NICHT als erbracht.
        $this->actingAs($user)->get(route('admin.dashboard'))->assertRedirect(route('two_factor.challenge'));
        $this->assertFalse(session()->has(TwoFactorService::sessionKey($user)));
        $this->assertEquals($bestaetigtAm, $user->fresh()->two_factor_confirmed_at);
        $this->assertSame(0, ActivityLog::where('action', 'two_factor_enabled')->count());
    }

    /**
     * Ist die Abfrage nach fuenf Fehlversuchen gesperrt, darf kein anderer
     * Weg den Faktor trotzdem annehmen.
     */
    public function test_nach_der_sperre_der_abfrage_hilft_die_einrichtung_nicht(): void
    {
        $user = $this->mitarbeiter();
        $secret = $this->eingerichtet($user);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('two_factor.challenge.store'), ['code' => '000000']);
        }
        $this->actingAs($user)->post(route('two_factor.challenge.store'), ['code' => Totp::code($secret)])
            ->assertSessionHasErrors('code');

        $this->actingAs($user)->post(route('two_factor.setup.store'), ['code' => Totp::code($secret)]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertRedirect(route('two_factor.challenge'));
    }

    /**
     * Die Ersatzcodes gehoeren dem Mitarbeiter. Ein Aufruf der Einrichtung
     * - auch mit gueltigem Code - darf sie nicht austauschen; der alte
     * Ersatzcode muss danach an der Abfrage weiter funktionieren.
     */
    public function test_ersatzcodes_bleiben_bei_unbefugter_einrichtung_erhalten(): void
    {
        $user = $this->mitarbeiter();
        $secret = $this->eingerichtet($user);
        $vorher = $user->fresh()->two_factor_recovery_codes;

        $this->actingAs($user)->post(route('two_factor.setup.store'), ['code' => Totp::code($secret)]);

        $this->assertSame($vorher, $user->fresh()->two_factor_recovery_codes);
        $this->actingAs($user)->post(route('two_factor.challenge.store'), ['code' => self::ERSATZCODE])
            ->assertRedirect(route('admin.dashboard'));
    }

    /**
     * Auch die ERSTE Einrichtung gibt keinen Weg zum schnelleren Raten:
     * sie zaehlt Fehlversuche im selben Topf wie die Abfrage, sperrt nach
     * fuenf und protokolliert jeden Fehlversuch.
     */
    public function test_erste_einrichtung_zaehlt_fehlversuche_wie_die_abfrage(): void
    {
        $user = $this->mitarbeiter();
        $this->actingAs($user)->get(route('two_factor.setup'))->assertOk();
        $secret = $user->fresh()->two_factor_secret;

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('two_factor.setup.store'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $this->actingAs($user)->post(route('two_factor.setup.store'), ['code' => Totp::code($secret)])
            ->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->two_factor_confirmed_at, 'Die Sperre wurde umgangen.');

        $fehlversuche = ActivityLog::where('action', 'two_factor_failed')->where('user_id', $user->id)->get();
        $this->assertCount(5, $fehlversuche);
        $this->assertSame('einrichtung', $fehlversuche->first()->metaArray()['weg'] ?? null);
    }

    /** Der normale Weg eines neuen Mitarbeiters bleibt unveraendert. */
    public function test_erstmalige_einrichtung_funktioniert_weiter(): void
    {
        $user = $this->mitarbeiter();
        $this->actingAs($user)->get(route('two_factor.setup'))->assertOk();
        $secret = $user->fresh()->two_factor_secret;

        $this->actingAs($user)->post(route('two_factor.setup.store'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->actingAs($user)->post(route('two_factor.setup.store'), ['code' => Totp::code($secret)])
            ->assertRedirect(route('two_factor.recovery_codes'))
            ->assertSessionHas('recovery_codes');

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $user->fresh()->two_factor_recovery_codes);
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
    }

    /** Dieselbe Regel eine Schicht tiefer - fuer jeden kuenftigen Aufrufer. */
    public function test_dienst_bestaetigt_einen_bestaetigten_faktor_nie_erneut(): void
    {
        $user = $this->mitarbeiter();
        $secret = $this->eingerichtet($user);
        $vorher = $user->fresh()->two_factor_recovery_codes;

        $this->assertNull(app(TwoFactorService::class)->confirmSetup($user->fresh(), Totp::code($secret)));
        $this->assertSame($vorher, $user->fresh()->two_factor_recovery_codes);
    }
}
