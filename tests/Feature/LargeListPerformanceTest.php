<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grosse Listen duerfen nicht mit dem Bestand wachsen.
 *
 * Die Vertragsliste lud frueher ALLE Vertraege und filterte sie per
 * JavaScript ueber die fertigen Tabellenzeilen; das Vertragsformular
 * schrieb den KOMPLETTEN Kundenbestand als JSON ins HTML. Beides
 * funktioniert genau so lange, wie die Zahlen klein sind - danach waechst
 * jeder Seitenaufruf linear mit, bis er in ein Speicher- oder Zeitlimit
 * laeuft. Und es faellt erst auf, wenn es zu spaet ist.
 *
 * Diese Tests halten die neue Grenze fest: gefiltert und gesucht wird in
 * der Datenbank, geliefert wird eine Seite.
 */
class LargeListPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kunde(string $name, array $attrs = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-' . strtoupper(substr(md5($name . uniqid()), 0, 8)),
        ], $attrs));
    }

    private function vertrag(Customer $kunde, array $attrs = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $kunde->id,
            'type' => 'kfz',
            'insurer' => 'ADAC Autoversicherung AG',
            'status' => 'active',
            'start_date' => now()->subMonth()->toDateString(),
        ], $attrs));
    }

    // ------------------------------------------------- Seitenweise Ausgabe

    public function test_die_vertragsliste_liefert_eine_seite_statt_des_ganzen_bestands(): void
    {
        $kunde = $this->kunde('Max Muster');
        for ($i = 0; $i < 55; $i++) {
            $this->vertrag($kunde, ['contract_number' => 'V-' . $i]);
        }

        $antwort = $this->actingAs($this->admin())->get(route('admin.contracts'));
        $antwort->assertOk();

        // 50 je Seite: die 55 Vertraege stehen NICHT alle im HTML.
        $this->assertSame(50, substr_count($antwort->getContent(), 'class="contract-row'));
        $antwort->assertSee('Seite 1 / 2');

        // Und die zweite Seite bringt den Rest.
        $seite2 = $this->actingAs($this->admin())->get(route('admin.contracts', ['page' => 2]));
        $this->assertSame(5, substr_count($seite2->getContent(), 'class="contract-row'));
    }

    public function test_die_gesamtzahl_bleibt_trotz_seitenweiser_ausgabe_ehrlich(): void
    {
        $kunde = $this->kunde('Max Muster');
        for ($i = 0; $i < 55; $i++) {
            $this->vertrag($kunde);
        }

        // Der Zaehler nennt den ganzen Bestand, nicht die Seitengroesse.
        $this->actingAs($this->admin())->get(route('admin.contracts'))
            ->assertOk()
            ->assertSee('Aktiver Bestand (55)')
            ->assertSee('55 aktive Verträge');
    }

    // -------------------------------------------------------- Gruppenfilter

    public function test_der_gruppenfilter_laeuft_in_der_datenbank(): void
    {
        $kunde = $this->kunde('Max Muster');
        $this->vertrag($kunde, ['insurer' => 'Laufend AG']);
        $this->vertrag($kunde, ['insurer' => 'Beendet AG', 'status' => 'cancelled']);
        $this->vertrag($kunde, ['insurer' => 'Antrag AG', 'status' => 'pending']);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.contracts', ['gruppe' => 'aktiv']))
            ->assertSee('Laufend AG')->assertDontSee('Beendet AG')->assertDontSee('Antrag AG');

        $this->actingAs($admin)->get(route('admin.contracts', ['gruppe' => 'historie']))
            ->assertSee('Beendet AG')->assertDontSee('Laufend AG');

        $this->actingAs($admin)->get(route('admin.contracts', ['gruppe' => 'anbahnung']))
            ->assertSee('Antrag AG')->assertDontSee('Laufend AG');

        // "alle" ist eine bewusste Auswahl und zeigt wirklich alles.
        $this->actingAs($admin)->get(route('admin.contracts', ['gruppe' => 'alle']))
            ->assertSee('Laufend AG')->assertSee('Beendet AG')->assertSee('Antrag AG');
    }

    public function test_eine_unbekannte_gruppe_faellt_auf_den_aktiven_bestand_zurueck(): void
    {
        $kunde = $this->kunde('Max Muster');
        $this->vertrag($kunde, ['insurer' => 'Laufend AG']);
        $this->vertrag($kunde, ['insurer' => 'Beendet AG', 'status' => 'cancelled']);

        $this->actingAs($this->admin())->get(route('admin.contracts', ['gruppe' => 'quatsch']))
            ->assertOk()
            ->assertSee('Laufend AG')
            ->assertDontSee('Beendet AG');
    }

    // --------------------------------------------------------------- Suche

    public function test_die_suche_findet_ueber_gesellschaft_nummer_und_kunde(): void
    {
        $einer = $this->kunde('Anna Beispiel');
        $anderer = $this->kunde('Bernd Zweiter');
        $this->vertrag($einer, ['insurer' => 'Allianz SE', 'contract_number' => 'AZ-4711']);
        $this->vertrag($anderer, ['insurer' => 'HUK Coburg', 'contract_number' => 'HK-0815']);

        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.contracts', ['q' => 'Allianz']))
            ->assertSee('AZ-4711')->assertDontSee('HK-0815');

        $this->actingAs($admin)->get(route('admin.contracts', ['q' => 'HK-0815']))
            ->assertSee('HUK Coburg')->assertDontSee('Allianz SE');

        // Kundenname - frueher der einzige durchsuchbare Wert, jetzt einer von mehreren.
        $this->actingAs($admin)->get(route('admin.contracts', ['q' => 'Bernd']))
            ->assertSee('HUK Coburg')->assertDontSee('Allianz SE');
    }

    public function test_die_zaehler_folgen_der_suche(): void
    {
        $kunde = $this->kunde('Max Muster');
        $this->vertrag($kunde, ['insurer' => 'Allianz SE']);
        $this->vertrag($kunde, ['insurer' => 'HUK Coburg']);
        $this->vertrag($kunde, ['insurer' => 'HUK Coburg', 'status' => 'cancelled']);

        $this->actingAs($this->admin())->get(route('admin.contracts', ['q' => 'HUK']))
            ->assertOk()
            ->assertSee('Aktiver Bestand (1)')
            ->assertSee('Beendet / Historie (1)')
            ->assertSee('Alle (2)');
    }

    public function test_ein_prozentzeichen_in_der_suche_ist_kein_platzhalter(): void
    {
        $kunde = $this->kunde('Max Muster');
        $this->vertrag($kunde, ['insurer' => 'Allianz SE']);

        // Waere % ein LIKE-Platzhalter, kaeme hier ein Treffer zurueck.
        $this->actingAs($this->admin())->get(route('admin.contracts', ['q' => '%']))
            ->assertOk()
            ->assertDontSee('Allianz SE');
    }

    // ------------------------------------------- Portfolio bleibt bindend

    public function test_die_liste_zeigt_nie_fremde_vertraege(): void
    {
        $eigen = $this->kunde('Eigener Kunde');
        $fremd = $this->kunde('Fremder Kunde');
        $this->vertrag($eigen, ['insurer' => 'Eigen AG']);
        $this->vertrag($fremd, ['insurer' => 'Fremd AG']);

        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        $mitarbeiter->assignedCustomers()->attach((string) $eigen->id);

        $this->actingAs($mitarbeiter)->get(route('admin.contracts', ['gruppe' => 'alle']))
            ->assertOk()
            ->assertSee('Eigen AG')
            ->assertDontSee('Fremd AG')
            ->assertSee('Alle (1)');
    }

    // ------------------------------------------- Kundenauswahl im Formular

    public function test_das_vertragsformular_traegt_den_kundenbestand_nicht_mehr_im_html(): void
    {
        $this->kunde('Geheim Testkunde');

        $this->actingAs($this->admin())->get(route('admin.contract.new'))
            ->assertOk()
            // Frueher stand jeder Kundenname als JSON in der Seite.
            ->assertDontSee('Geheim Testkunde');
    }

    public function test_die_kundensuche_des_formulars_liefert_treffer(): void
    {
        $this->kunde('Anna Beispiel');
        $this->kunde('Bernd Zweiter');

        $antwort = $this->actingAs($this->admin())
            ->getJson(route('admin.customers.search', ['q' => 'Anna']));

        $antwort->assertOk();
        $namen = collect($antwort->json('customers'))->pluck('name');
        $this->assertContains('Anna Beispiel', $namen->all());
        $this->assertNotContains('Bernd Zweiter', $namen->all());
    }

    public function test_die_kundensuche_haelt_sich_ans_portfolio(): void
    {
        $eigen = $this->kunde('Eigener Kunde');
        $this->kunde('Fremder Kunde');

        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        $mitarbeiter->assignedCustomers()->attach((string) $eigen->id);

        $antwort = $this->actingAs($mitarbeiter)
            ->getJson(route('admin.customers.search', ['q' => 'Kunde']));

        $namen = collect($antwort->json('customers'))->pluck('name');
        $this->assertSame(['Eigener Kunde'], $namen->all());
    }

    public function test_die_kundensuche_gibt_nie_eine_platzhalter_adresse_aus(): void
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'name' => 'Ohne Mail',
            'email' => 'import-999@dienstly24.internal',
        ]);
        Customer::create(['user_id' => $user->id, 'customer_number' => 'C-INTERN1']);

        $antwort = $this->actingAs($this->admin())
            ->getJson(route('admin.customers.search', ['q' => 'Ohne Mail']));

        $treffer = collect($antwort->json('customers'))->firstWhere('name', 'Ohne Mail');
        $this->assertNotNull($treffer);
        // Die interne Platzhalter-Adresse kann keine Mail empfangen - sie
        // als Kontakt anzuzeigen waere irrefuehrend.
        $this->assertNull($treffer['email']);
    }

    // ------------------------------------------- Zusammenfuehren-Formular

    public function test_das_zusammenfuehren_formular_traegt_den_bestand_nicht_mehr_im_html(): void
    {
        $haupt = $this->kunde('Julia Schmidt');
        $this->kunde('Voellig Unbeteiligt');

        $this->actingAs($this->admin())->get(route('admin.customer.merge', $haupt->id))
            ->assertOk()
            // Frueher stand jeder Kunde als <option> in der Auswahlliste.
            ->assertDontSee('Voellig Unbeteiligt');
    }

    public function test_die_kundensuche_kann_den_eigenen_kunden_ausschliessen(): void
    {
        $haupt = $this->kunde('Julia Schmidt');
        $this->kunde('Julia Schmidt Zwei');

        $antwort = $this->actingAs($this->admin())->getJson(
            route('admin.customers.search', ['q' => 'Julia', 'exclude' => $haupt->id])
        );

        $namen = collect($antwort->json('customers'))->pluck('name');
        // Niemand fuehrt einen Kunden mit sich selbst zusammen.
        $this->assertNotContains('Julia Schmidt', $namen->all());
        $this->assertContains('Julia Schmidt Zwei', $namen->all());
    }

    // ------------------------------------------------------------- Export

    public function test_der_export_wird_gestreamt_und_bleibt_vollstaendig(): void
    {
        foreach (['Anna Erste', 'Bernd Zweiter', 'Clara Dritte'] as $name) {
            $this->kunde($name);
        }

        $antwort = $this->actingAs($this->admin())->get(route('admin.export'))->assertOk();

        // Gestreamt: der Inhalt kommt aus streamedContent(), nicht aus getContent().
        $inhalt = $antwort->streamedContent();
        foreach (['Anna', 'Bernd', 'Clara'] as $teil) {
            $this->assertStringContainsString($teil, $inhalt);
        }
        $antwort->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_der_export_protokolliert_die_richtige_anzahl(): void
    {
        foreach (['Anna Erste', 'Bernd Zweiter'] as $name) {
            $this->kunde($name);
        }

        $this->actingAs($this->admin())->get(route('admin.export'))->assertOk();

        $eintrag = \App\Models\ActivityLog::where('action', 'customers_exported')->latest('created_at')->firstOrFail();
        $this->assertSame(2, $eintrag->metaArray()['count']);
    }

    public function test_der_export_zeigt_nie_fremde_kunden(): void
    {
        $eigen = $this->kunde('Eigener Kunde');
        $this->kunde('Fremder Kunde');

        $mitarbeiter = User::factory()->create(['role' => 'employee', 'can_import_export' => true]);
        $mitarbeiter->assignedCustomers()->attach((string) $eigen->id);

        $inhalt = $this->actingAs($mitarbeiter)->get(route('admin.export'))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Eigener', $inhalt);
        $this->assertStringNotContainsString('Fremder', $inhalt);
    }

    // ------------------------------------------------- E-Mail-Eingang

    public function test_der_eingang_deckelt_die_liste_und_nennt_die_gesamtzahl(): void
    {
        $kunde = $this->kunde('Max Muster');
        $konto = EmailAccount::create([
            'name' => 'Test', 'email_address' => 'info@dienstly24.de',
            'provider' => 'imap', 'folders' => ['INBOX'], 'is_active' => true,
        ]);

        for ($i = 0; $i < 105; $i++) {
            EmailMessage::create([
                'email_account_id' => $konto->id,
                'message_uid' => 'uid-' . $i,
                'customer_id' => $kunde->id,
                'match_status' => 'suggested',
                'subject' => 'Testmail ' . $i,
                'from_address' => 'kunde' . $i . '@example.com',
                'received_at' => now()->subMinutes(200 - $i),
            ]);
        }

        $antwort = $this->actingAs($this->admin())->get(route('admin.email_inbox'))->assertOk();

        // Die Ueberschrift nennt den ganzen Stapel ...
        $antwort->assertSee('Zuordnung bestätigen (105)', false);
        // ... sagt aber ehrlich, dass nur ein Teil gezeigt wird.
        $antwort->assertSee('die ältesten 100 werden gezeigt', false);
    }
}
