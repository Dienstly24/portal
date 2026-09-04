<?php

namespace Tests\Feature;

use App\Models\CommissionPool;
use App\Models\Contract;
use App\Models\ContractCommission;
use App\Models\Customer;
use App\Models\User;
use App\Services\CommissionImport\CommissionImportService;
use App\Services\Provisionsmanagement\CommissionStatusEngine;
use App\Services\Provisionsmanagement\ReferenceLinkService;
use App\Support\CommissionKind;
use App\Support\ContractCommissionStatus as Zustand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROVISIONSMANAGEMENT (Betreiber-Auftrag 02.09.2026) - die Abnahmefaelle
 * 1-12 der Spezifikation, eins zu eins.
 *
 * Sie halten die Zusagen fest, auf die sich der Betrieb verlaesst:
 *  - eine Abrechnung findet ihren Vertrag ueber die Referenz-Nr., und die
 *    Pool-Id bleibt dauerhaft daran haengen,
 *  - eine Folgedatei mit NUR der Pool-Id findet ihn trotzdem,
 *  - keine Provision geht verloren, keine wird doppelt gebucht,
 *  - Storno und Provision stehen NEBENeinander, nie uebereinander,
 *  - eine ausbleibende Provision faellt von selbst auf,
 *  - und nichts davon erreicht Kunden oder Mitarbeiter ohne Provisionsrecht.
 */
class ProvisionsmanagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    // ----------------------------------------------------------- Hilfsmittel

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function customer(string $name = 'Max Mustermann'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);
        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5($name.$user->id), 0, 8)),
        ]);
    }

    private function contract(Customer $customer, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $customer->id,
            'type' => 'kfz',
            'insurer' => 'Allianz',
            'status' => 'active',
            'pool' => 'check24',
        ], $overrides));
    }

    private function service(): CommissionImportService
    {
        return app(CommissionImportService::class);
    }

    private function file(string $content, string $name = 'check24.csv'): string
    {
        $dir = storage_path('framework/testing/provisionsmanagement');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/'.uniqid().'-'.$name;
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Die Bauform der CHECK24-Abrechnung: Datum, Produkt, Id, Status,
     * Provision, Stornogrund und - solange sie mitkommt - die Referenz-Nr.
     *
     * @param array<int,array<string,string>> $rows
     */
    private function check24Csv(array $rows, bool $mitReferenz = true): string
    {
        $header = 'Datum;Produkt;Id;Status;Provision;Stornogrund'.($mitReferenz ? ';Referenz-Nr.' : '');
        $lines = [$header];
        foreach ($rows as $row) {
            $zeile = [
                $row['datum'] ?? '15.06.2026',
                $row['produkt'] ?? 'Kfz-Versicherung',
                $row['id'] ?? '987654',
                $row['status'] ?? 'bestaetigt',
                $row['betrag'] ?? '120,00',
                $row['stornogrund'] ?? '',
            ];
            if ($mitReferenz) {
                $zeile[] = $row['referenz'] ?? 'REF-12345';
            }
            $lines[] = implode(';', $zeile);
        }
        return implode("\n", $lines)."\n";
    }

    private function importiere(string $csv, bool $anlegen = false, string $pool = 'check24'): void
    {
        $import = $this->service()->analyze($this->file($csv), 'check24.csv', null, null, null, null, null, null, $pool);
        $this->service()->confirm($import, null, $anlegen);
    }

    // ------------------------------------- TEST 1: Referenz-Nr. -> Vertrag

    public function test_1_check24_datei_ordnet_ueber_referenznummer_zu_und_merkt_sich_die_id(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => 'REF-12345']);

        $this->importiere($this->check24Csv([[]]));

        $commission = ContractCommission::sole();
        $this->assertSame($contract->id, $commission->contract_id, 'Die Referenz-Nr. muss den Vertrag finden.');
        $this->assertSame('check24', $commission->pool);
        $this->assertSame(120.0, (float) $commission->amount);

        // Die CHECK24-Id haengt ab jetzt am Vertrag ...
        $this->assertSame('987654', $contract->fresh()->vermittler_id);
        // ... und das PAAR ist dauerhaft gespeichert (§15).
        $this->assertDatabaseHas('commission_reference_links', [
            'pool' => 'check24',
            'reference_number' => 'REF-12345',
            'external_id' => '987654',
            'contract_id' => $contract->id,
        ]);
    }

    // ------------------------- TEST 2: nur die Id -> ueber das Mapping finden

    public function test_2_folgedatei_nur_mit_id_findet_den_vertrag_ueber_das_gespeicherte_paar(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => 'REF-12345']);
        $this->importiere($this->check24Csv([[]]));

        // Die Kennung am Vertrag wird bewusst entfernt: so bleibt als Bruecke
        // AUSSCHLIESSLICH das gespeicherte Paar - genau der Fall aus §15.
        $contract->forceFill(['vermittler_id' => null])->saveQuietly();
        $this->assertDatabaseCount('commission_reference_links', 1);

        $this->importiere($this->check24Csv([[
            'datum' => '15.09.2026', 'betrag' => '15,00', 'produkt' => 'Kfz-Versicherung',
        ]], false));

        $zweite = ContractCommission::where('amount', 15.00)->sole();
        $this->assertSame($contract->id, $zweite->contract_id);
        $this->assertStringContainsString('Pool-Id', (string) $zweite->match_reason);
    }

    // ------------------------- TEST 3: unbekannter Kunde -> alles entsteht

    public function test_3_provision_fuer_unbekannten_kunden_legt_kunde_vertrag_und_provision_an(): void
    {
        $csv = "Datum;Produkt;Id;Status;Provision;Kunde;Anschrift\n"
            ."15.06.2026;Hausratversicherung;555111;bestaetigt;90,00;Nadine Neu;Musterweg 3, 12345 Musterstadt\n";

        $this->importiere($csv, true);

        $commission = ContractCommission::sole();
        $this->assertNotNull($commission->contract_id, 'Die Provision muss an den neuen Vertrag gebunden sein.');
        $this->assertNotNull($commission->customer_id);

        $contract = Contract::findOrFail($commission->contract_id);
        // Ein aus Geld abgeleiteter Vertrag ist NIE aktiv: dass eine Provision
        // floss, belegt, dass es den Vertrag GAB - nicht, dass er heute laeuft.
        $this->assertSame('pending', $contract->status);
        $this->assertSame('check24', $contract->pool);
        $this->assertNotNull($contract->commission_import_id, 'Die Herkunft muss am Vertrag stehen.');
    }

    // ------------------------------- TEST 4: dieselbe Datei zweimal

    public function test_4_dieselbe_datei_zweimal_erzeugt_keine_doppelbuchung(): void
    {
        $this->contract($this->customer(), ['reference_number' => 'REF-12345']);
        $csv = $this->check24Csv([[]]);

        $this->importiere($csv);
        $this->importiere($csv);

        $this->assertSame(1, ContractCommission::count(), 'Ein zweiter Lauf derselben Datei darf nichts hinzufuegen.');
    }

    // ------------------------------- TEST 5: Provision und Storno nebeneinander

    public function test_5_provision_und_storno_stehen_nebeneinander_und_ergeben_das_netto(): void
    {
        $this->contract($this->customer(), ['reference_number' => 'REF-12345']);

        $this->importiere($this->check24Csv([
            ['betrag' => '3,07', 'produkt' => 'AP'],
            ['betrag' => '-3,66', 'produkt' => 'APStorno', 'status' => 'storniert',
                'stornogrund' => 'Widerruf', 'datum' => '20.01.2027'],
        ]));

        $this->assertSame(2, ContractCommission::count(), 'Beide Buchungen bleiben erhalten.');
        $this->assertEqualsWithDelta(-0.59, (float) ContractCommission::sum('amount'), 0.001);

        $storno = ContractCommission::where('amount', '<', 0)->sole();
        $this->assertSame(CommissionKind::STORNO, $storno->commission_kind);
    }

    // ---------------------- TEST 6: 5 Monate ohne Provision -> "Provision fehlt"

    public function test_6_vertrag_ohne_provision_faellt_nach_ablauf_der_prueffrist_auf(): void
    {
        $contract = $this->contract($this->customer(), [
            'signing_date' => now()->subMonthsNoOverflow(6)->toDateString(),
        ]);

        app(CommissionStatusEngine::class)->refreshAll();

        $contract->refresh();
        $this->assertSame(Zustand::FEHLT, $contract->commission_status);
        // Die Fristen stehen als DATUM am Vertrag - nicht als Rechenweg, den
        // jede Ansicht neu nachvollziehen muesste.
        $this->assertNotNull($contract->expected_commission_date);
        $this->assertNotNull($contract->commission_check_date);

        $frisch = $this->contract($this->customer('Frisch Abgeschlossen'), [
            'signing_date' => now()->subDays(10)->toDateString(),
        ]);
        app(CommissionStatusEngine::class)->refreshAll();
        $this->assertSame(Zustand::ERWARTET, $frisch->fresh()->commission_status,
            'Ein frischer Vertrag darf nie als fehlend gelten.');
    }

    // ---------------- TEST 7: Provision kommt nach -> Zustand aktualisiert sich

    public function test_7_spaeter_importierte_provision_aktualisiert_den_zustand(): void
    {
        $contract = $this->contract($this->customer(), [
            'reference_number' => 'REF-12345',
            'signing_date' => now()->subMonthsNoOverflow(6)->toDateString(),
        ]);
        app(CommissionStatusEngine::class)->refreshAll();
        $this->assertSame(Zustand::FEHLT, $contract->fresh()->commission_status);

        $this->importiere($this->check24Csv([[]]));

        $this->assertSame(Zustand::ERHALTEN, $contract->fresh()->commission_status,
            'Die eingegangene Provision muss den Zustand von selbst umstellen.');
    }

    // ------------------- TEST 8: mehrdeutig -> keine automatische Zuordnung

    public function test_8_mehrdeutiger_datensatz_wird_nie_automatisch_zugeordnet(): void
    {
        // Zwei Vertraege unter derselben Referenz-Nr. - im Bestand moeglich,
        // fuer eine Maschine nicht aufloesbar.
        $this->contract($this->customer('Kunde A'), ['reference_number' => 'REF-99999']);
        $this->contract($this->customer('Kunde B'), ['reference_number' => 'REF-99999']);

        $this->importiere($this->check24Csv([['referenz' => 'REF-99999', 'id' => '111222']]));

        $commission = ContractCommission::sole();
        $this->assertNull($commission->contract_id, 'Bei zwei Treffern wird bewusst nichts zugeordnet.');
        $this->assertSame(ContractCommission::MATCH_OFFEN, $commission->match_status);
        // Die Provision geht trotzdem nicht verloren.
        $this->assertSame(120.0, (float) $commission->amount);

        $this->actingAs($this->admin())
            ->get(route('admin.provisionsmanagement.unclear'))
            ->assertOk()
            ->assertSee('REF-99999');
    }

    // ------------------------- TEST 9: Korrektur durch den Admin -> Protokoll

    public function test_9_manuelle_zuordnung_steht_im_protokoll(): void
    {
        $ziel = $this->contract($this->customer('Richtiger Kunde'), ['reference_number' => 'REF-77777']);
        $this->importiere($this->check24Csv([['referenz' => 'REF-00000', 'id' => '333444']]));

        $commission = ContractCommission::sole();
        $this->assertNull($commission->contract_id);

        $this->actingAs($this->admin())
            ->post(route('admin.commissions_internal.link', $commission->id), ['contract_id' => $ziel->id])
            ->assertRedirect();

        $this->assertSame($ziel->id, $commission->fresh()->contract_id);
        $this->assertDatabaseHas('commission_audit_logs', [
            'commission_id' => $commission->id,
            'action' => 'vertrag_zugeordnet',
            'field' => 'contract_id',
        ]);
    }

    // ------------------------- TEST 10: normaler Mitarbeiter -> Zugriff verweigert

    public function test_10_mitarbeiter_ohne_provisionsrecht_wird_ueberall_abgewiesen(): void
    {
        $employee = User::factory()->create([
            'role' => 'employee',
            'can_manage_commissions' => false,
            'can_see_all_customers' => true,
            'can_manage_contracts' => true,
        ]);
        $contract = $this->contract($this->customer());

        $routen = [
            route('admin.provisionsmanagement.dashboard'),
            route('admin.provisionsmanagement.imports'),
            route('admin.provisionsmanagement.statements'),
            route('admin.provisionsmanagement.contracts'),
            route('admin.provisionsmanagement.missing'),
            route('admin.provisionsmanagement.unclear'),
            route('admin.provisionsmanagement.analytics'),
            route('admin.provisionsmanagement.settings'),
            route('admin.provisionsmanagement.export'),
            route('admin.provisionsmanagement.contract', $contract->id),
        ];
        foreach ($routen as $url) {
            $this->actingAs($employee)->get($url)->assertForbidden();
        }

        // Auch die schreibenden Wege - eine Ansicht zu sperren genuegt nicht.
        $this->actingAs($employee)
            ->post(route('admin.provisionsmanagement.recalculate'))->assertForbidden();
        $this->actingAs($employee)
            ->post(route('admin.provisionsmanagement.followup', $contract->id), ['status' => 'offen'])
            ->assertForbidden();
    }

    // ------------------------- TEST 11: Kunde sieht im Portal keine Provision

    public function test_11_kunde_sieht_im_portal_keine_provisionsdaten(): void
    {
        $customer = $this->customer('Portal Kunde');
        $contract = $this->contract($customer, ['reference_number' => 'REF-12345', 'contract_number' => 'V-PORTAL-1']);
        $this->importiere($this->check24Csv([['betrag' => '4711,11']]));

        $antwort = $this->actingAs($customer->user)->get(route('portal.contracts.show', $contract->id));
        $antwort->assertOk();
        $antwort->assertDontSee('4.711,11');
        $antwort->assertDontSee('4711.11');
        $antwort->assertDontSee('987654');           // Pool-Id
        $antwort->assertDontSee('Provisionsmanagement');

        // Und der direkte Aufruf des Bereichs bleibt verschlossen (die
        // Beraterwelt weist ein Kundenkonto schon an der Tuer ab - ob mit
        // 403 oder Umleitung, entscheidet die Rollen-Middleware).
        $this->actingAs($customer->user)
            ->get(route('admin.provisionsmanagement.dashboard'))
            ->assertStatus(302);
    }

    // ------------------------- TEST 12: Support sieht in der Akte keine Provision

    public function test_12_support_sieht_in_der_kundenakte_keine_provisionsdaten(): void
    {
        $customer = $this->customer('Akten Kunde');
        $this->contract($customer, ['reference_number' => 'REF-12345']);
        $this->importiere($this->check24Csv([['betrag' => '4711,11']]));

        $support = User::factory()->create([
            'role' => 'support',
            'can_manage_commissions' => false,
            'can_see_all_customers' => true,
        ]);

        $antwort = $this->actingAs($support)->get(route('admin.customer', $customer->id));
        $antwort->assertOk();
        $antwort->assertDontSee('4.711,11');
        $antwort->assertDontSee('Wirtschaftlichkeit');

        $this->actingAs($support)
            ->get(route('admin.provisionsmanagement.customer', $customer->id))
            ->assertForbidden();
    }

    // ------------------------------------------------- Ergaenzende Zusagen

    /**
     * JEDE Seite des Bereichs muss sich oeffnen lassen - mit Daten und ohne.
     *
     * Gelernt am echten Betrieb: die Import-Historie warf einen 500er, weil
     * in der View eine METHODE ohne Klammern stand (`$import->providerLabel`).
     * Eloquent deutet das als Beziehung und wirft - im Test fiel es nicht auf,
     * weil zwar die Zahlen der Seite geprueft waren, aber nie ihr AUFRUF.
     * Dieser Test ruft deshalb stumpf alles auf, was einen Menuepunkt hat.
     */
    public function test_jede_seite_des_bereichs_laesst_sich_oeffnen(): void
    {
        $admin = $this->admin();
        $contract = $this->contract($this->customer(), ['reference_number' => 'REF-12345']);

        $seiten = fn () => [
            route('admin.provisionsmanagement.dashboard'),
            route('admin.provisionsmanagement.imports'),
            route('admin.provisionsmanagement.statements'),
            route('admin.provisionsmanagement.contracts'),
            route('admin.provisionsmanagement.contract', $contract->id),
            route('admin.provisionsmanagement.missing'),
            route('admin.provisionsmanagement.unclear'),
            route('admin.provisionsmanagement.unclear', ['art' => 'status']),
            route('admin.provisionsmanagement.analytics'),
            route('admin.provisionsmanagement.settings'),
            route('admin.provisionsmanagement.export'),
            // Die Nachbarseiten teilen sich die Navigation - ein Fehler dort
            // trifft denselben Bereich.
            route('admin.commissions_internal.index'),
            route('admin.commissions_internal.import'),
            route('admin.commissions_internal.audit'),
            route('admin.commissions_internal.invoice'),
        ];

        // 1. Leerer Bestand: die haeufigste Lage kurz nach dem Livegang.
        foreach ($seiten() as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        // 2. Mit Daten - erst dann laufen die Zeilen der Tabellen durch.
        $this->importiere($this->check24Csv([
            ['betrag' => '120,00'],
            ['betrag' => '-20,00', 'status' => 'storniert', 'stornogrund' => 'Widerruf'],
        ]));
        $this->importiere($this->check24Csv([['referenz' => 'REF-OHNE', 'id' => '556677']]));
        app(CommissionStatusEngine::class)->refreshAll();

        foreach ($seiten() as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $this->actingAs($admin)
            ->get(route('admin.provisionsmanagement.customer', $contract->customer_id))
            ->assertOk();
    }

    public function test_pool_fristen_sind_einstellung_und_wirken_sofort(): void
    {
        $pool = CommissionPool::where('key', 'check24')->sole();
        $contract = $this->contract($this->customer(), [
            'signing_date' => now()->subMonthsNoOverflow(4)->toDateString(),
        ]);
        app(CommissionStatusEngine::class)->refreshAll();
        $this->assertSame(Zustand::UEBERFAELLIG, $contract->fresh()->commission_status);

        // Prueffrist auf 3 Monate verkuerzen: derselbe Vertrag ist damit
        // "Provision fehlt" - und zwar sofort, nicht erst im Nachtlauf.
        $this->actingAs($this->admin())
            ->put(route('admin.provisionsmanagement.pool_update', $pool->id), [
                'name' => $pool->name,
                'expected_months' => 2,
                'check_months' => 3,
                'active' => 1,
            ])->assertRedirect();

        $this->assertSame(Zustand::FEHLT, $contract->fresh()->commission_status);
    }

    public function test_prueffrist_liegt_nie_vor_der_erwartung(): void
    {
        $pool = CommissionPool::where('key', 'maklerpool')->sole();

        $this->actingAs($this->admin())
            ->put(route('admin.provisionsmanagement.pool_update', $pool->id), [
                'name' => $pool->name, 'expected_months' => 6, 'check_months' => 2, 'active' => 1,
            ])->assertRedirect();

        $this->assertSame(6, $pool->fresh()->check_months,
            'Eine Prueffrist vor der Erwartung waere ein Widerspruch und wird angehoben.');
    }

    public function test_nachverfolgung_geklaert_nimmt_den_vertrag_aus_der_mahnliste(): void
    {
        $contract = $this->contract($this->customer(), [
            'signing_date' => now()->subMonthsNoOverflow(8)->toDateString(),
        ]);
        app(CommissionStatusEngine::class)->refreshAll();
        $this->assertSame(Zustand::FEHLT, $contract->fresh()->commission_status);

        $this->actingAs($this->admin())
            ->post(route('admin.provisionsmanagement.followup', $contract->id), [
                'status' => 'geklaert',
                'contacted_on' => now()->toDateString(),
                'contact_person' => 'Frau Meier',
                'response' => 'Kein Anspruch, Vertrag wurde widerrufen.',
            ])->assertRedirect();

        $this->assertSame(Zustand::GEKLAERT, $contract->fresh()->commission_status);
        $this->assertDatabaseHas('commission_followups', [
            'contract_id' => $contract->id,
            'status' => 'geklaert',
            'contact_person' => 'Frau Meier',
        ]);

        // Der Nachtlauf laesst eine menschliche Entscheidung in Ruhe.
        app(CommissionStatusEngine::class)->refreshAll();
        $this->assertSame(Zustand::GEKLAERT, $contract->fresh()->commission_status);

        // Wird der Fall wieder geoeffnet, rechnet das System normal weiter.
        $this->actingAs($this->admin())
            ->post(route('admin.provisionsmanagement.followup', $contract->id), ['status' => 'in_klaerung'])
            ->assertRedirect();
        $this->assertSame(Zustand::FEHLT, $contract->fresh()->commission_status);
    }

    public function test_dashboard_und_auswertung_zeigen_die_summen_der_pools(): void
    {
        $this->contract($this->customer(), ['reference_number' => 'REF-12345']);
        $this->importiere($this->check24Csv([
            ['betrag' => '120,00'],
            ['betrag' => '-20,00', 'datum' => '20.06.2026', 'status' => 'storniert', 'stornogrund' => 'Widerruf'],
        ]));

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.provisionsmanagement.dashboard'))
            ->assertOk()->assertSee('CHECK24')->assertSee('100,00');

        $this->actingAs($admin)->get(route('admin.provisionsmanagement.analytics'))
            ->assertOk()->assertSee('Storno');

        $csv = $this->actingAs($admin)->get(route('admin.provisionsmanagement.export'));
        $csv->assertOk();
        $this->assertStringContainsString('Pool', $csv->streamedContent());
    }

    public function test_der_export_wird_protokolliert(): void
    {
        $this->actingAs($this->admin())->get(route('admin.provisionsmanagement.export'))->assertOk();
        $this->assertDatabaseHas('commission_audit_logs', ['action' => 'export']);
    }

    public function test_provisionsart_wird_gedeutet_ohne_den_originaltext_zu_verlieren(): void
    {
        $this->assertSame(CommissionKind::STORNO, CommissionKind::detect('APStorno'));
        $this->assertSame(CommissionKind::ABSCHLUSS, CommissionKind::detect('AP'));
        $this->assertSame(CommissionKind::BESTAND, CommissionKind::detect('Bestandscourtage'));
        $this->assertSame(CommissionKind::FOLGE, CommissionKind::detect('Folgeprovision'));
        // Unbekanntes wird nie geraten ...
        $this->assertSame(CommissionKind::SONSTIGE, CommissionKind::detect('Wasauchimmer'));
        // ... ein Minus dagegen ist im Provisionsgeschaeft eindeutig.
        $this->assertSame(CommissionKind::STORNO, CommissionKind::detect(null, -12.5));
    }

    public function test_kennungspaar_bleibt_bei_widerspruch_ohne_zuordnung(): void
    {
        $contract = $this->contract($this->customer(), ['reference_number' => 'REF-A1234']);
        $links = app(ReferenceLinkService::class);

        $links->remember('check24', 'REF-A1234', '900001', $contract);
        // Dieselbe Id, andere Referenz: das ist ein Widerspruch im Bestand.
        $links->remember('check24', 'REF-B1234', '900001');

        $this->assertDatabaseCount('commission_reference_links', 2);
        $ergebnis = $links->resolveByExternalId('check24', '900001');
        $this->assertNull($ergebnis['contract'], 'Bei zwei Referenzen zu einer Id wird nichts zugeordnet.');
        $this->assertNotNull($ergebnis['note']);
    }
}
