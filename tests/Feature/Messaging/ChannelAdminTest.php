<?php

namespace Tests\Feature\Messaging;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Ai\Assistant\AiSettingsResolver;
use App\Support\AiMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kanal-Verwaltung im Admin (Auftrag Abschnitte 68-72, 86, 96-99).
 *
 * Der Schwerpunkt liegt auf den Geheimnis-Regeln: ein Zugangswert darf
 * den Server nie wieder verlassen, und er darf nicht versehentlich
 * verschwinden.
 */
class ChannelAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'email' => 'a'.uniqid().'@dienstly24.de']);
    }

    private function portal(): Channel
    {
        return Channel::where('key', Channel::PORTAL)->firstOrFail();
    }

    private function konto(array $attrs = []): ChannelAccount
    {
        return ChannelAccount::create(array_merge([
            'channel_id' => $this->portal()->id,
            'name' => 'Konto A',
            'credentials' => ['access_token' => 'GEHEIM-123'],
        ], $attrs));
    }

    /**
     * Fall 1: Die Seite ist ADMIN-Gebiet - hier liegen Zugangsdaten.
     *
     * Abgewiesen wird per UMLEITUNG in den eigenen Bereich, nicht per 403:
     * das ist die bewusste Linie dieses Projekts ("kein Menuepunkt fuehrt
     * in ein 403", AdminNavigationTest). Entscheidend ist, dass die Seite
     * NICHT ausgeliefert wird - und genau das prueft dieser Fall.
     */
    public function test_nur_admin_kommt_in_die_kanalverwaltung(): void
    {
        foreach (['manager', 'support', 'employee', 'customer', 'partner'] as $rolle) {
            $user = User::factory()->create(['role' => $rolle, 'email' => $rolle.uniqid().'@example.de']);
            $antwort = $this->actingAs($user)->get('/admin/kanaele');
            $antwort->assertRedirect();
            $this->assertNotSame(200, $antwort->getStatusCode());
        }

        $this->actingAs($this->admin())->get('/admin/kanaele')->assertOk();
    }

    /**
     * Fall 2: DIE WICHTIGSTE REGEL. Ein hinterlegtes Geheimnis erscheint
     * NIE wieder im HTML - nur "gesetzt" oder "fehlt".
     */
    public function test_zugangsdaten_erscheinen_nie_in_der_oberflaeche(): void
    {
        $this->konto();

        $antwort = $this->actingAs($this->admin())->get('/admin/kanaele');

        $antwort->assertOk();
        $antwort->assertDontSee('GEHEIM-123');
        $antwort->assertSee('gesetzt');
    }

    /**
     * Fall 3: Ein LEER abgeschicktes Feld loescht nichts.
     *
     * Sonst raeumt jedes Speichern der KI-Betriebsart nebenbei das Token
     * ab - und das faellt erst auf, wenn die naechste Nachricht nicht
     * mehr rausgeht.
     */
    public function test_leeres_feld_loescht_das_vorhandene_geheimnis_nicht(): void
    {
        $konto = $this->konto();

        $this->actingAs($this->admin())
            ->put('/admin/kanaele/konten/'.$konto->id, [
                'name' => 'Konto A',
                'ai_mode' => AiMode::OFF,
                'is_active' => '1',
                'credentials' => ['access_token' => ''],
            ])->assertRedirect();

        $frisch = $konto->fresh();
        $this->assertSame('GEHEIM-123', $frisch->credential('access_token'));
        $this->assertSame(AiMode::OFF, $frisch->ai_mode);
    }

    /** Fall 4: Ein ausgefuelltes Feld ersetzt den Wert - und bleibt verschluesselt. */
    public function test_neues_geheimnis_wird_verschluesselt_uebernommen(): void
    {
        $konto = $this->konto();

        $this->actingAs($this->admin())
            ->put('/admin/kanaele/konten/'.$konto->id, [
                'name' => 'Konto A',
                'credentials' => ['access_token' => 'NEU-456'],
            ])->assertRedirect();

        $this->assertSame('NEU-456', $konto->fresh()->credential('access_token'));

        $roh = (string) DB::table('channel_accounts')->where('id', $konto->id)->value('credentials');
        $this->assertStringNotContainsString('NEU-456', $roh);
    }

    /**
     * Fall 5: "Zugang trennen" loescht die Zugangsdaten - aber KEINE
     * Unterhaltung. Der Verlauf gehoert dem Kunden und dem Betrieb, nicht
     * der Anbindung.
     */
    public function test_trennen_loescht_zugangsdaten_aber_keine_unterhaltung(): void
    {
        $konto = $this->konto();
        Conversation::create([
            'channel_id' => $this->portal()->id,
            'channel_account_id' => $konto->id,
            'status' => Conversation::STATUS_OPEN,
        ]);

        $this->actingAs($this->admin())
            ->post('/admin/kanaele/konten/'.$konto->id.'/trennen')
            ->assertRedirect();

        $frisch = $konto->fresh();
        $this->assertNull($frisch->credentials);
        $this->assertFalse($frisch->is_active);
        $this->assertSame(1, Conversation::count());
    }

    /** Fall 6: Kanal an/aus und KI-Betriebsart je Kanal (Abschnitte 72/89). */
    public function test_admin_schaltet_kanal_und_ki_betriebsart(): void
    {
        $kanal = $this->portal();

        $this->actingAs($this->admin())
            ->put('/admin/kanaele/'.$kanal->id, ['ai_mode' => AiMode::AI_FIRST])
            ->assertRedirect();

        $frisch = $kanal->fresh();
        $this->assertFalse($frisch->is_active);
        $this->assertSame(AiMode::AI_FIRST, $frisch->ai_mode);
    }

    /**
     * Fall 7: Ein leeres Auswahlfeld heisst ERBEN, nicht "aus" - es muss
     * als NULL ankommen, sonst waere die Hierarchie kaputt.
     */
    public function test_leere_betriebsart_bedeutet_erben(): void
    {
        $kanal = $this->portal();
        $kanal->update(['ai_mode' => AiMode::OFF]);

        $this->actingAs($this->admin())
            ->put('/admin/kanaele/'.$kanal->id, ['is_active' => '1', 'ai_mode' => ''])
            ->assertRedirect();

        $this->assertNull($kanal->fresh()->ai_mode);
    }

    /** Fall 8: Eine unbekannte Betriebsart wird abgelehnt, nie gespeichert. */
    public function test_unbekannte_betriebsart_wird_abgelehnt(): void
    {
        $kanal = $this->portal();

        $this->actingAs($this->admin())
            ->put('/admin/kanaele/'.$kanal->id, ['ai_mode' => 'turbo'])
            ->assertSessionHasErrors('ai_mode');

        $this->assertNull($kanal->fresh()->ai_mode);
    }

    /** Fall 9: Globale Betriebsart setzen und wieder auf Ableitung stellen. */
    public function test_globale_betriebsart_ist_setzbar_und_ruecksetzbar(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put('/admin/kanaele/ki-betriebsart', ['ai_mode' => AiMode::AI_ASSIST])
            ->assertRedirect();
        $this->assertSame(AiMode::AI_ASSIST, app(AiSettingsResolver::class)->globalMode());

        // Leer = zurueck auf die Ableitung aus den bestehenden Schaltern.
        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        $this->actingAs($admin)
            ->put('/admin/kanaele/ki-betriebsart', ['ai_mode' => ''])
            ->assertRedirect();
        $this->assertSame(AiMode::AUTO_REPLY, app(AiSettingsResolver::class)->globalMode());
    }

    /** Fall 10: Neues Konto anlegen (Abschnitt 99). */
    public function test_admin_legt_ein_konto_an(): void
    {
        $kanal = $this->portal();

        $this->actingAs($this->admin())
            ->post('/admin/kanaele/'.$kanal->id.'/konten', [
                'name' => 'Zweites Konto',
                'external_account_id' => '123456',
                'credentials' => ['access_token' => 'TOKEN-A'],
                'is_active' => '1',
            ])->assertRedirect();

        $konto = ChannelAccount::where('name', 'Zweites Konto')->firstOrFail();
        $this->assertSame('123456', $konto->external_account_id);
        $this->assertSame('TOKEN-A', $konto->credential('access_token'));
        $this->assertTrue($konto->is_active);
    }

    /**
     * Fall 11: Der Verbindungstest nennt einen BENANNTEN Zustand. Kanaele
     * ohne externe Plattform sagen ehrlich, dass es nichts zu testen gibt -
     * ein stilles "verbunden" waere eine Behauptung.
     */
    public function test_verbindungstest_meldet_einen_benannten_zustand(): void
    {
        $konto = $this->konto();

        $this->actingAs($this->admin())
            ->post('/admin/kanaele/konten/'.$konto->id.'/test')
            ->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'channel_account_tested',
            'entity_type' => 'channel_account',
        ]);
    }

    /** Fall 12: Im Protokoll stehen nur die SCHLUESSEL, nie die Werte. */
    public function test_protokoll_enthaelt_keine_geheimnisse(): void
    {
        $kanal = $this->portal();

        $this->actingAs($this->admin())
            ->post('/admin/kanaele/'.$kanal->id.'/konten', [
                'name' => 'Protokoll-Konto',
                'credentials' => ['access_token' => 'NICHT-INS-LOG'],
            ])->assertRedirect();

        $protokoll = DB::table('activity_logs')->where('action', 'channel_account_created')->get();
        $this->assertCount(1, $protokoll);
        foreach ($protokoll as $zeile) {
            $this->assertStringNotContainsString('NICHT-INS-LOG', json_encode($zeile));
            $this->assertStringContainsString('access_token', (string) $zeile->meta);
        }
    }
}
