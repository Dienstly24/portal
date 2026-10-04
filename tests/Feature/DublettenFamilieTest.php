<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\User;
use App\Services\Matching\DuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Familienmitglieder werden NIE versehentlich zusammengefuehrt (Betreiber-
 * Auftrag 03.10.2026, KI-063).
 *
 * Gemeldeter Fall: Maher Abboud (geb. 10.02.1971) und Ahmad Jihad Abboud
 * (geb. 2002) standen nur wegen derselben E-Mail-Adresse als
 * "44 % · Wahrscheinlich · ✓ sicher" in der Dubletten-Liste - Vater und
 * Sohn, per "Alle auswaehlen" + "Zusammenfuehren" mit einem Klick
 * verschmelzbar, und der Merge loescht die Akte des Sohnes.
 */
class DublettenFamilieTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kunde(string $name, string $email, array $felder = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5($email.microtime()), 0, 8)),
        ], $felder));
    }

    /** Der gemeldete Fall: gleiche (Familien-)E-Mail und Anschrift, verschiedene Geburtsdaten. */
    private function vaterUndSohn(): array
    {
        $adresse = ['address_street' => 'Nagelshof', 'address_house_number' => '20', 'address_zip' => '51069', 'address_city' => 'Köln'];
        $vater = $this->kunde('Maher Abboud', 'familie.abboud@example.com', $adresse + ['birth_date' => '1971-02-10']);
        $sohn = $this->kunde('Ahmad Jihad Abboud', 'ahmad.abboud@example.com', $adresse + [
            'birth_date' => '2002-05-04',
            'email2' => 'familie.abboud@example.com',
        ]);

        return [$vater, $sohn];
    }

    private function paarAusScan(Customer $a, Customer $b): ?array
    {
        foreach (app(DuplicateDetectionService::class)->scan()['pairs'] as $p) {
            $ids = [(string) $p['primary']->id, (string) $p['duplicate']->id];
            if (in_array((string) $a->id, $ids, true) && in_array((string) $b->id, $ids, true)) {
                return $p;
            }
        }

        return null;
    }

    public function test_vater_und_sohn_mit_gleicher_email_sind_moegliche_familie_nicht_sicher(): void
    {
        [$vater, $sohn] = $this->vaterUndSohn();

        $paar = $this->paarAusScan($vater, $sohn);
        $this->assertNotNull($paar, 'Das Paar soll weiterhin angezeigt werden - als Familie.');
        $this->assertSame(DuplicateDetectionService::KLASSE_FAMILIE, $paar['klasse']);
        $this->assertContains('Gleiche E-Mail-Adresse', $paar['signals']);
        $gruende = implode(' ', $paar['konflikte']);
        $this->assertStringContainsString('Abweichendes Geburtsdatum', $gruende);
        $this->assertStringContainsString('10.02.1971', $gruende);
        $this->assertStringContainsString('04.05.2002', $gruende);
        $this->assertStringContainsString('Abweichender Vorname', $gruende);
    }

    public function test_seite_zeigt_moegliche_familie_ohne_sicher_und_ohne_zusammenfuehren(): void
    {
        [$vater, $sohn] = $this->vaterUndSohn();

        $html = $this->actingAs($this->admin())->get(route('admin.customers.duplicates'))->assertOk()->getContent();

        $this->assertStringContainsString('Mögliche Familie · verschiedene Personen', $html);
        $this->assertStringNotContainsString('dup-klasse-sicher', $html, 'Vater und Sohn duerfen nie als "sicher" erscheinen.');
        $this->assertStringNotContainsString('duplicate='.$sohn->id, $html, 'Kein "Prüfen & zusammenführen" fuer Familie.');
        $this->assertStringNotContainsString('duplicate='.$vater->id, $html);
        $this->assertStringContainsString('Beziehung festlegen', $html);
        // Kein Knopf "Alle sicheren zusammenführen": es gibt kein sicheres Paar.
        $this->assertStringNotContainsString('Alle sicheren zusammenführen', $html);
    }

    public function test_alle_sicheren_zusammenfuehren_laesst_vater_und_sohn_stehen(): void
    {
        [$vater, $sohn] = $this->vaterUndSohn();

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.merge_all'))
            ->assertRedirect(route('admin.customers.duplicates'));

        $this->assertSame(2, Customer::whereIn('id', [$vater->id, $sohn->id])->count());
    }

    /** "Alle auswaehlen" + "Zusammenfuehren" - der Weg, der vorher gar nicht pruefte. */
    public function test_sammelauswahl_fuehrt_vater_und_sohn_nicht_zusammen(): void
    {
        [$vater, $sohn] = $this->vaterUndSohn();
        Contract::create(['customer_id' => $sohn->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.merge'), [
            'pairs' => ["{$vater->id}|{$sohn->id}"],
        ])->assertRedirect(route('admin.customers.duplicates'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'NICHT zusammengeführt'));

        $this->assertSame(2, Customer::whereIn('id', [$vater->id, $sohn->id])->count());
        $this->assertSame(1, Contract::where('customer_id', $sohn->id)->count(), 'Der Vertrag bleibt beim Sohn.');
    }

    /**
     * Ueber eine Akte OHNE Geburtsdatum duerfen zwei verschiedene Personen
     * nicht in dieselbe Gruppe rutschen (A-B und A-C ausgewaehlt, B und C
     * widersprechen sich).
     */
    public function test_sammelauswahl_prueft_jedes_paar_der_gruppe(): void
    {
        $ohneDatum = $this->kunde('Hans Gross', 'hans0@example.com');
        $vater = $this->kunde('Hans Gross', 'hans1@example.com', ['birth_date' => '1960-03-01']);
        $sohn = $this->kunde('Hans Gross', 'hans2@example.com', ['birth_date' => '1990-03-01']);

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.merge'), [
            'pairs' => ["{$ohneDatum->id}|{$vater->id}", "{$ohneDatum->id}|{$sohn->id}"],
        ])->assertRedirect(route('admin.customers.duplicates'));

        $this->assertSame(3, Customer::whereIn('id', [$ohneDatum->id, $vater->id, $sohn->id])->count());
    }

    /**
     * Fehlt das Geburtsdatum beim Sohn, war das Paar vorher KEIN Widerspruch
     * (gemeinsames Namenswort "Abboud" genuegte) - mit gemeinsamer IBAN wurde
     * es per "Alle sicheren" automatisch verschmolzen. Der andere Vorname
     * ist jetzt selbst ein Widerspruch.
     */
    public function test_anderer_vorname_ist_widerspruch_auch_ohne_geburtsdatum(): void
    {
        $vater = $this->kunde('Maher Abboud', 'maher@example.com', ['iban' => 'DE89370400440532013000', 'birth_date' => '1971-02-10']);
        $sohn = $this->kunde('Ahmad Abboud', 'ahmad@example.com', ['iban' => 'DE89 3704 0044 0532 0130 00', 'email2' => 'maher@example.com']);

        $paar = $this->paarAusScan($vater, $sohn);
        $this->assertSame(DuplicateDetectionService::KLASSE_FAMILIE, $paar['klasse']);
        $this->assertStringContainsString('Abweichender Vorname', implode(' ', $paar['konflikte']));

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.merge_all'));
        $this->assertSame(2, Customer::whereIn('id', [$vater->id, $sohn->id])->count());
    }

    public function test_sicher_nur_mit_gleichem_namen_und_geburtsdatum(): void
    {
        $a = $this->kunde('Lena Berg', 'lena1@example.com', ['birth_date' => '1985-07-01']);
        $b = $this->kunde('Lena Berg', 'lena2@example.com', ['birth_date' => '1985-07-01']);
        // Nur Name + gemeinsame E-Mail, ohne Geburtsdatum: moeglich, nicht sicher.
        $c = $this->kunde('Tom Kern', 'tom@example.com');
        $d = $this->kunde('Tom Kern', 'tom2@example.com', ['email2' => 'tom@example.com']);

        $this->assertSame(DuplicateDetectionService::KLASSE_SICHER, $this->paarAusScan($a, $b)['klasse']);
        $this->assertSame(DuplicateDetectionService::KLASSE_MOEGLICH, $this->paarAusScan($c, $d)['klasse']);

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.merge_all'));

        $this->assertSame(1, Customer::whereIn('id', [$a->id, $b->id])->count(), 'Sichere Dublette wird zusammengefuehrt.');
        $this->assertSame(2, Customer::whereIn('id', [$c->id, $d->id])->count(), 'Moegliche Dublette bleibt der Einzelpruefung.');
    }

    public function test_tippfehler_und_zusaetzlicher_vorname_sind_kein_widerspruch(): void
    {
        $svc = app(DuplicateDetectionService::class);
        $a = $this->kunde('Mohamad Rahimi', 'm1@example.com', ['birth_date' => '1990-01-01']);
        $b = $this->kunde('Mohammad Rahimi', 'm2@example.com', ['birth_date' => '1990-01-01']);
        $c = $this->kunde('Ahmad Abboud', 'a1@example.com');
        $d = $this->kunde('Ahmad Jihad Abboud', 'a2@example.com');

        $this->assertSame([], $svc->identityConflicts($a, $b));
        $this->assertSame([], $svc->identityConflicts($c, $d));
    }

    public function test_festgelegte_familienrolle_erscheint_nie_wieder_als_dublette(): void
    {
        [$vater, $sohn] = $this->vaterUndSohn();
        // Nur die Familienrolle, OHNE Gleichlauf-Zeile in customer_relationships
        // (Altbestand / Dokumenten-Eingang).
        CustomerFamilyRelation::create(['customer_id' => $vater->id, 'related_customer_id' => $sohn->id, 'relationship_type' => 'sohn']);

        $this->assertNull($this->paarAusScan($vater, $sohn));
    }

    public function test_einzel_merge_bei_widerspruch_nur_mit_bestaetigung_und_begruendung(): void
    {
        [$vater, $sohn] = $this->vaterUndSohn();
        $admin = $this->admin();

        // Ohne Bestaetigung: nichts passiert, zurueck auf das Formular MIT dem Duplikat.
        $this->actingAs($admin)->post(route('admin.customer.merge.do', $vater->id), ['duplicate_id' => $sohn->id])
            ->assertRedirect(route('admin.customer.merge', $vater->id).'?duplicate='.$sohn->id)
            ->assertSessionHasErrors('konflikt_bestaetigt');
        $this->assertSame(2, Customer::whereIn('id', [$vater->id, $sohn->id])->count());

        // Das Formular zeigt den Widerspruch und die Felder zum Uebersteuern.
        $this->actingAs($admin)->get(route('admin.customer.merge', $vater->id).'?duplicate='.$sohn->id)
            ->assertOk()->assertSee('Zusammenführen gesperrt')->assertSee('Abweichendes Geburtsdatum')->assertSee('konflikt_begruendung', false);

        // Bewusst uebersteuert: wird zusammengefuehrt UND protokolliert.
        $this->actingAs($admin)->post(route('admin.customer.merge.do', $vater->id), [
            'duplicate_id' => $sohn->id,
            'konflikt_bestaetigt' => '1',
            'konflikt_begruendung' => 'Geburtsdatum beim Import vertippt, laut Ausweis identisch.',
        ])->assertRedirect(route('admin.customer', $vater->id));

        $this->assertSame(1, Customer::whereIn('id', [$vater->id, $sohn->id])->count());
        $log = ActivityLog::where('action', 'customer_merge_override')->first();
        $this->assertNotNull($log);
        $this->assertSame((string) $sohn->id, $log->meta['duplicate_id']);
        $this->assertStringContainsString('vertippt', $log->meta['begruendung']);
    }
}
