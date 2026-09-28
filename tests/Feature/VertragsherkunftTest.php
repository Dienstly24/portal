<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractRevision;
use App\Models\Customer;
use App\Models\CustomerChangeRequest;
use App\Models\Provision;
use App\Models\ProvisionRate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Reporting\AnalyticsFilters;
use App\Services\Reporting\DashboardAnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * VERTRAGSHERKUNFT (Betreiber-Auftrag 28.09.2026): Eigenvertrag,
 * Fremdvertrag (nur Dokumentation) oder uebernommen.
 *
 * Ausgangsfehler: ein als Vorvertrag erfasster ADAC-Vertrag sah genauso aus
 * wie ein selbst vermittelter. Wochen spaeter arbeitete jemand an einem
 * Vertrag ohne Mandat und ohne Courtage - und jede Kennzahl zaehlte ihn als
 * unseren Bestand.
 *
 * Jeder Fall hier scheitert ohne die Aenderung: vorher gab es weder die
 * Spalte noch die Pflichtwahl, Kennzahlen zaehlten jeden aktiven Vertrag,
 * und nichts warnte an einem Fremdvertrag.
 */
class VertragsherkunftTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Erika Muster', 'email' => 'kunde-'.uniqid().'@kunde.de']);

        return Customer::create(['user_id' => $user->id, 'customer_number' => 'K-'.uniqid()]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function mitarbeiter(): User
    {
        return User::factory()->create(['role' => 'employee', 'can_see_all_customers' => true]);
    }

    private function vertrag(Customer $kunde, array $attr = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $kunde->id,
            'type' => 'kfz',
            'insurer' => 'DA Direkt',
            'status' => 'active',
            'start_date' => now()->subMonths(2)->toDateString(),
        ], $attr));
    }

    private function fremd(Customer $kunde, array $attr = []): Contract
    {
        return $this->vertrag($kunde, array_merge([
            'insurer' => 'ADAC',
            'contract_number' => 'AD-9533316226',
            'origin' => Contract::ORIGIN_EXTERNAL,
            'origin_verified' => true,
            'premium_amount' => 80.84,
            'premium_interval' => 'monthly',
        ], $attr));
    }

    // ---------------------------------------------------------------
    // 1. Datenmodell + Altbestand
    // ---------------------------------------------------------------

    /** Altbestand und automatische Anlage: Eigenvertrag, aber UNGEPRUEFT. */
    public function test_ohne_angabe_gilt_eigenvertrag_als_ungepruefte_annahme(): void
    {
        $c = $this->vertrag($this->kunde())->fresh();

        $this->assertSame(Contract::ORIGIN_BROKERED, $c->origin);
        $this->assertFalse($c->origin_verified);
        $this->assertTrue($c->isOwnPortfolio());
    }

    // ---------------------------------------------------------------
    // 2. Formular: Pflichtwahl + Pruefung je Herkunft (serverseitig)
    // ---------------------------------------------------------------

    public function test_neuanlage_ohne_herkunft_wird_abgelehnt(): void
    {
        $kunde = $this->kunde();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $kunde->id), ['type' => 'kfz', 'insurer' => 'DA Direkt', 'status' => 'active'])
            ->assertSessionHasErrors('origin');

        $this->assertSame(0, Contract::count());
    }

    public function test_uebernommen_verlangt_das_datum_der_uebernahme(): void
    {
        $kunde = $this->kunde();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $kunde->id), [
                'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active', 'origin' => 'transferred',
            ])->assertSessionHasErrors('transfer_date');
    }

    public function test_neuanlage_mit_gewaehlter_herkunft_ist_geprueft(): void
    {
        $kunde = $this->kunde();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $kunde->id), [
                'type' => 'kfz', 'insurer' => 'ADAC', 'status' => 'cancelled', 'origin' => 'external',
                'previous_broker' => 'Versicherer direkt', 'origin_note' => 'Nur als Vorvertrag erfasst',
                'cancellation_submitted_by_us' => '1', 'transfer_date' => '2026-01-01',
            ])->assertSessionHas('success');

        $c = Contract::firstOrFail();
        $this->assertSame('external', $c->origin);
        $this->assertTrue($c->origin_verified);
        $this->assertSame('Versicherer direkt', $c->previous_broker);
        $this->assertTrue($c->cancellation_submitted_by_us);
        // Ein Uebernahmedatum gehoert nur zu "uebernommen" - nie still mitgespeichert.
        $this->assertNull($c->transfer_date);
    }

    /** Der haeufigste Ablauf: neuer Eigenvertrag + Vorvertrag als Fremdvertrag in EINEM Zug. */
    public function test_vorvertrag_wird_in_einem_zug_als_fremdvertrag_angelegt_und_verknuepft(): void
    {
        $kunde = $this->kunde();
        $beginn = now()->addMonth()->startOfMonth();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $kunde->id), [
                'type' => 'kfz', 'insurer' => 'DA Direkt', 'status' => 'active', 'origin' => 'brokered',
                'start_date' => $beginn->toDateString(), 'reference_number' => '1427-5555-1',
                'replaces_mode' => 'new',
                'predecessor' => ['insurer' => 'ADAC', 'contract_number' => 'AD-9533316226', 'cancellation_submitted_by_us' => '1'],
            ])->assertSessionHas('success');

        $neu = Contract::where('insurer', 'DA Direkt')->firstOrFail();
        $alt = Contract::where('insurer', 'ADAC')->firstOrFail();

        $this->assertSame($alt->id, $neu->replaces_contract_id);
        $this->assertSame($neu->id, $alt->successor->id);
        $this->assertTrue($alt->isExternal());
        $this->assertTrue($alt->cancellation_submitted_by_us);
        // Endet zum Beginn des neuen Vertrags - wie beim Versicherer-Wechsel.
        $this->assertSame($beginn->toDateString(), Carbon::parse($alt->end_date)->toDateString());
        $this->assertNotNull($alt->cancellation_date);
    }

    public function test_vorgaenger_muss_zum_selben_kunden_gehoeren(): void
    {
        $fremderKunde = $this->kunde();
        $fremderVertrag = $this->fremd($fremderKunde);
        $kunde = $this->kunde();

        $this->actingAs($this->admin())
            ->post(route('admin.contract.store', $kunde->id), [
                'type' => 'kfz', 'insurer' => 'DA Direkt', 'status' => 'active', 'origin' => 'brokered',
                'replaces_mode' => 'existing', 'replaces_contract_id' => $fremderVertrag->id,
            ])->assertSessionHasErrors('replaces_contract_id');
    }

    // ---------------------------------------------------------------
    // 3. Herkunft aendern: nur admin/manager, nur bestaetigt, protokolliert
    // ---------------------------------------------------------------

    private function updatePayload(Contract $c, array $extra = []): array
    {
        return array_merge([
            'type' => $c->type, 'insurer' => $c->insurer, 'status' => $c->status,
            'start_date' => $c->start_date,
        ], $extra);
    }

    public function test_mitarbeiter_darf_die_herkunft_nicht_aendern(): void
    {
        $c = $this->vertrag($this->kunde(), ['origin' => 'brokered']);

        $this->actingAs($this->mitarbeiter())
            ->put(route('admin.contract.update', $c->id), $this->updatePayload($c, ['origin' => 'external', 'origin_change_confirmed' => '1']))
            ->assertSessionHasErrors('origin');

        $this->assertSame('brokered', $c->fresh()->origin);
    }

    public function test_herkunftswechsel_braucht_bestaetigung_und_wird_protokolliert(): void
    {
        $c = $this->vertrag($this->kunde(), ['origin' => 'brokered']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.contract.update', $c->id), $this->updatePayload($c, ['origin' => 'external']))
            ->assertSessionHasErrors('origin_change_confirmed');
        $this->assertSame('brokered', $c->fresh()->origin);

        $this->actingAs($admin)
            ->put(route('admin.contract.update', $c->id), $this->updatePayload($c, ['origin' => 'external', 'origin_change_confirmed' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertSame('external', $c->fresh()->origin);
        $log = ActivityLog::where('action', 'contract_origin_changed')->where('entity_id', $c->id)->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(['brokered', 'external'], [$log->metaArray()['old'], $log->metaArray()['new']]);
        $this->assertDatabaseHas('contract_revisions', [
            'contract_id' => $c->id, 'field' => 'origin',
            'old_value' => 'Eigenvertrag', 'new_value' => 'Fremdvertrag – nur Dokumentation', 'changed_by' => $admin->id,
        ]);
    }

    /** Ein Formular ohne Herkunftsfeld aendert sie nie (anderer Schreibweg). */
    public function test_bearbeiten_ohne_herkunftsfeld_laesst_sie_unveraendert(): void
    {
        $c = $this->fremd($this->kunde());

        $this->actingAs($this->admin())
            ->put(route('admin.contract.update', $c->id), $this->updatePayload($c, ['notes' => 'x']))
            ->assertSessionHasNoErrors();

        $this->assertSame('external', $c->fresh()->origin);
        $this->assertSame(0, ContractRevision::where('field', 'origin')->count());
    }

    // ---------------------------------------------------------------
    // 4. Kennzahlen zaehlen NUR den Eigenbestand
    // ---------------------------------------------------------------

    public function test_dashboard_zaehlt_fremdvertraege_nicht_zum_bestand(): void
    {
        $kunde = $this->kunde();
        $this->vertrag($kunde);
        $this->vertrag($kunde, ['origin' => 'transferred', 'transfer_date' => '2026-05-01', 'insurer' => 'HUK']);
        $this->fremd($kunde);

        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('activeContracts', 2)
            ->assertViewHas('activeExternalContracts', 1)
            ->assertSee('zzgl. 1 Fremdvertrag');
    }

    public function test_auswertung_zaehlt_keine_fremdvertraege(): void
    {
        $kunde = $this->kunde();
        $this->vertrag($kunde, ['signing_date' => now()->toDateString()]);
        $this->fremd($kunde, ['signing_date' => now()->toDateString()]);

        $daten = (new DashboardAnalyticsService(null))
            ->auswerten(AnalyticsFilters::ausRequest(Request::create('/admin/reports', 'GET')));
        $monat = collect($daten['kpis'])->firstWhere('schluessel', 'monat');

        $this->assertSame(1, $monat['wert']);
    }

    public function test_kundenakte_weist_fremdvertrag_getrennt_aus(): void
    {
        $kunde = $this->kunde();
        $this->vertrag($kunde, ['premium_amount' => 50, 'premium_interval' => 'monthly']);
        $this->fremd($kunde);

        $this->actingAs($this->admin())->get(route('admin.customer', $kunde->id))
            ->assertOk()
            ->assertSee('1 aktiver Vertrag')
            ->assertSee('zzgl. 1 Fremdvertrag, 80,84 €/Monat', false)
            ->assertSee('📁 Fremdvertrag', false);
    }

    // ---------------------------------------------------------------
    // 5. Filter in der Vertragsliste + Uebernahmepotenzial
    // ---------------------------------------------------------------

    public function test_vertragsliste_filtert_nach_herkunft(): void
    {
        $kunde = $this->kunde();
        $this->vertrag($kunde, ['insurer' => 'Eigen AG']);
        $this->fremd($kunde, ['insurer' => 'Fremd Versicherung']);
        $admin = $this->admin();

        // Standard = Eigenbestand.
        $this->actingAs($admin)->get(route('admin.contracts'))
            ->assertOk()->assertSee('Eigen AG')->assertDontSee('Fremd Versicherung');
        $this->actingAs($admin)->get(route('admin.contracts', ['herkunft' => 'fremd']))
            ->assertOk()->assertSee('Fremd Versicherung')->assertDontSee('Eigen AG');
        $this->actingAs($admin)->get(route('admin.contracts', ['herkunft' => 'alle']))
            ->assertOk()->assertSee('Fremd Versicherung')->assertSee('Eigen AG');
    }

    public function test_uebernahmepotenzial_listet_nur_laufende_fremdvertraege(): void
    {
        $kunde = $this->kunde();
        $this->vertrag($kunde, ['insurer' => 'Eigen AG']);
        $this->fremd($kunde, ['insurer' => 'Laufend Fremd']);
        $this->fremd($kunde, ['insurer' => 'Beendet Fremd', 'contract_number' => null, 'status' => 'cancelled']);

        $this->actingAs($this->admin())->get(route('admin.contracts.fremdbestand'))
            ->assertOk()
            ->assertSee('Laufend Fremd')
            ->assertDontSee('Beendet Fremd')
            ->assertDontSee('Eigen AG');
    }

    // ---------------------------------------------------------------
    // 6. Leitplanken: Warnung + Grund bei Handlungen an Fremdvertraegen
    // ---------------------------------------------------------------

    public function test_vertragsakte_zeigt_die_warnleiste(): void
    {
        $c = $this->fremd($this->kunde(), ['previous_broker' => 'Makler Meier']);

        $this->actingAs($this->admin())->get(route('admin.contract.edit', $c->id))
            ->assertOk()
            ->assertSee('Dieser Vertrag wurde nicht über uns vermittelt.', false)
            ->assertSee('Makler Meier')
            ->assertSee('Übernahme anbieten', false);
    }

    public function test_kuendigung_eines_fremdvertrags_verlangt_einen_grund(): void
    {
        $c = $this->fremd($this->kunde());
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.contract.update', $c->id), $this->updatePayload($c, ['status' => 'cancelled']))
            ->assertSessionHasErrors('fremdvertrag_grund');
        $this->assertSame('active', $c->fresh()->status);

        $this->actingAs($admin)
            ->put(route('admin.contract.update', $c->id), $this->updatePayload($c, [
                'status' => 'cancelled', 'fremdvertrag_grund' => 'Kunde hat uns schriftlich beauftragt',
            ]))->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $c->fresh()->status);
        $log = ActivityLog::where('action', 'external_contract_action')->firstOrFail();
        $this->assertSame(['kuendigung'], $log->metaArray()['aktionen']);
    }

    public function test_aenderungsantrag_zu_fremdvertrag_warnt_und_verlangt_grund(): void
    {
        $kunde = $this->kunde();
        $c = $this->fremd($kunde);
        $antrag = CustomerChangeRequest::create([
            'customer_id' => $kunde->id, 'requested_by' => $kunde->user_id, 'type' => 'contract',
            'old_data' => ['id' => $c->id, 'insurer' => 'ADAC'],
            'new_data' => ['id' => $c->id, 'insurer' => 'ADAC', 'notes' => 'Bitte Beitrag senken'],
            'status' => 'pending', 'requested_at' => now(),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.change_requests'))
            ->assertOk()->assertSee('Dieser Vertrag wurde nicht über uns vermittelt.', false);

        $this->actingAs($admin)
            ->post(route('admin.change_requests.action', $antrag->id), ['action' => 'approve'])
            ->assertSessionHasErrors('fremdvertrag_grund');
        $this->assertSame('pending', $antrag->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.change_requests.action', $antrag->id), ['action' => 'approve', 'fremdvertrag_grund' => 'Kunde bat um Weiterleitung'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(ActivityLog::where('action', 'external_contract_action')->where('entity_id', $c->id)->exists());
    }

    // ---------------------------------------------------------------
    // 7. Geld: Fremdvertrag bucht nie eine Provision
    // ---------------------------------------------------------------

    public function test_fremdvertrag_bucht_keine_werber_provision(): void
    {
        $werber = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        ProvisionRate::create(['user_id' => $werber->id, 'contract_type' => 'kfz', 'amount_fixed' => 50, 'amount_percent' => 10]);
        $kunde = $this->kunde();
        $kunde->update(['acquired_by' => $werber->id]);

        $this->fremd($kunde->fresh());
        $this->assertSame(0, Provision::count());

        $this->vertrag($kunde->fresh());
        $this->assertSame(1, Provision::count());
    }

    // ---------------------------------------------------------------
    // 8. Datenpruefung (Dashboard-Aufgabe + Sammelaktion)
    // ---------------------------------------------------------------

    public function test_ungepruefte_herkunft_wird_gemeldet_und_abgearbeitet(): void
    {
        $kunde = $this->kunde();
        $a = $this->vertrag($kunde, ['insurer' => 'Alt A']);
        $b = $this->vertrag($kunde, ['insurer' => 'Alt B']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertViewHas('unverifiedOriginContracts', 2)
            ->assertSee('2 Verträge mit ungeprüfter Herkunft', false);

        $this->actingAs($admin)->get(route('admin.contracts.origin_review'))->assertOk()->assertSee('Alt A');

        $this->actingAs($admin)->post(route('admin.contracts.origin_review.store'), ['aktion' => 'bestaetigen', 'ids' => [$a->id]])
            ->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.contracts.origin_review.store'), ['aktion' => 'als_fremd', 'ids' => [$b->id]])
            ->assertSessionHas('success');

        $this->assertTrue($a->fresh()->origin_verified);
        $this->assertSame('brokered', $a->fresh()->origin);
        $this->assertSame('external', $b->fresh()->origin);
        $this->assertTrue(ActivityLog::where('action', 'contract_origin_changed')->where('entity_id', $b->id)->exists());
    }

    public function test_mitarbeiter_darf_in_der_pruefliste_nur_bestaetigen(): void
    {
        $c = $this->vertrag($this->kunde());

        $this->actingAs($this->mitarbeiter())
            ->post(route('admin.contracts.origin_review.store'), ['aktion' => 'als_fremd', 'ids' => [$c->id]])
            ->assertForbidden();
        $this->assertSame('brokered', $c->fresh()->origin);
    }

    // ---------------------------------------------------------------
    // 9. Kundenportal: getrennt oder ausgeblendet (Einstellung)
    // ---------------------------------------------------------------

    public function test_portal_zeigt_fremdvertraege_getrennt_oder_gar_nicht(): void
    {
        $kunde = $this->kunde();
        $this->vertrag($kunde, ['insurer' => 'Eigen AG']);
        $fremd = $this->fremd($kunde, ['insurer' => 'Fremd Versicherung']);

        $this->actingAs($kunde->user)->get(route('portal.contracts'))
            ->assertOk()
            ->assertSee('Weitere Verträge (nicht über uns betreut)', false)
            ->assertSee('Fremd Versicherung');

        SystemSetting::set(Contract::SETTING_PORTAL_EXTERNAL, 'ausblenden');

        $this->actingAs($kunde->user)->get(route('portal.contracts'))
            ->assertOk()->assertSee('Eigen AG')->assertDontSee('Fremd Versicherung');
        $this->actingAs($kunde->user)->get(route('portal.contracts.show', $fremd->id))->assertNotFound();
    }

    // ---------------------------------------------------------------
    // 10. KI-Assistent kennt die Herkunft
    // ---------------------------------------------------------------

    public function test_ki_kontext_markiert_fremdvertraege(): void
    {
        $c = $this->fremd($this->kunde(), ['previous_broker' => 'Makler Meier']);

        $hinweis = $c->assistantOriginHint();
        $this->assertNotNull($hinweis);
        $this->assertStringContainsString('KEIN Mandat', $hinweis);
        $this->assertStringContainsString('Makler Meier', $hinweis);
        $this->assertNull($this->vertrag($c->customer)->assistantOriginHint());
    }
}
