<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AiConversation;
use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\User;
use App\Services\Matching\CustomerMergeService;
use App\Services\Matching\MergeBlockedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Merge-Sperren (Betreiber-Auftrag 03.10.2026, PR-2 des Dubletten-Plans):
 * KI-065 (zwei aktive Portalzugaenge -> ein Login geloescht), KI-066
 * (Rueckrichtung der Familienrolle verloren), KI-067 (KI-Unterhaltung -
 * Fehlbefund, hier belegt), KI-068 (Sammel-Merge auch fuer "moeglich").
 */
class MergeSperrenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kunde(string $name, string $email, array $felder = [], bool $angemeldet = false): Customer
    {
        $user = User::factory()->create([
            'role' => 'customer', 'name' => $name, 'email' => $email,
            'first_login_at' => $angemeldet ? now()->subMonth() : null,
        ]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5($email.microtime()), 0, 8)),
        ], $felder));
    }

    /** Dieselbe Person laut Stammdaten, aber zwei benutzte Portalzugaenge. */
    private function zweiAktiveZugaenge(): array
    {
        $a = $this->kunde('Lina Haddad', 'lina@example.com', ['birth_date' => '1985-03-03'], true);
        $b = $this->kunde('Lina Haddad', 'lina.haddad@example.com', ['birth_date' => '1985-03-03'], true);

        return [$a->fresh('user'), $b->fresh('user')];
    }

    public function test_zwei_aktive_portalzugaenge_sperren_den_merge(): void
    {
        [$a, $b] = $this->zweiAktiveZugaenge();
        $service = app(CustomerMergeService::class);

        $sperren = $service->mergeBlockers($a, $b);
        $this->assertCount(1, $sperren);
        $this->assertStringContainsString('aktiven Portalzugang', $sperren[0]);

        try {
            $service->merge($a, $b, null);
            $this->fail('Ohne Begruendung darf nicht zusammengefuehrt werden.');
        } catch (MergeBlockedException $e) {
            $this->assertSame($sperren, $e->gruende);
        }
        $this->assertSame(2, Customer::whereIn('id', [$a->id, $b->id])->count());
        $this->assertNotNull(User::find($b->user_id));
    }

    public function test_uebersteuerter_merge_deaktiviert_den_zweiten_zugang_statt_ihn_zu_loeschen(): void
    {
        [$a, $b] = $this->zweiAktiveZugaenge();
        $verlierer = $b->user_id;

        app(CustomerMergeService::class)->merge($a, $b, null, 'Doppelt registriert, Kundin bestaetigt per Telefon');

        $this->assertNull(Customer::find($b->id));
        $user = User::find($verlierer);
        $this->assertNotNull($user, 'Ein benutzter Zugang wird nie geloescht.');
        $this->assertFalse((bool) $user->is_active);

        $log = ActivityLog::where('action', 'customers_merged')->latest('id')->first();
        $meta = is_array($log->meta) ? $log->meta : json_decode((string) $log->meta, true);
        $this->assertTrue($meta['portal_account_deaktiviert']);
        $this->assertSame('Doppelt registriert, Kundin bestaetigt per Telefon', $meta['uebersteuert']['begruendung']);
    }

    public function test_formular_verlangt_begruendung_bei_zwei_aktiven_zugaengen(): void
    {
        [$a, $b] = $this->zweiAktiveZugaenge();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.customer.merge.do', $a->id), ['duplicate_id' => $b->id])
            ->assertRedirect(route('admin.customer.merge', $a->id).'?duplicate='.$b->id)
            ->assertSessionHasErrors('konflikt_bestaetigt');
        $this->assertNotNull(Customer::find($b->id));

        $this->actingAs($admin)->post(route('admin.customer.merge.do', $a->id), [
            'duplicate_id' => $b->id,
            'konflikt_bestaetigt' => '1',
            'konflikt_begruendung' => 'Zweites Konto versehentlich angelegt',
        ])->assertRedirect(route('admin.customer', $a->id));
        $this->assertNull(Customer::find($b->id));
        $this->assertFalse((bool) User::find($b->user_id)->is_active);
    }

    public function test_nur_einer_mit_aktivem_zugang_sperrt_nicht(): void
    {
        $a = $this->kunde('Omar Saleh', 'omar@example.com', ['birth_date' => '1979-01-01'], true);
        $b = $this->kunde('Omar Saleh', 'omar.alt@example.com', ['birth_date' => '1979-01-01']);

        $this->assertSame([], app(CustomerMergeService::class)->mergeBlockers($a->fresh('user'), $b->fresh('user')));
    }

    public function test_familienrolle_bleibt_in_beiden_richtungen_erhalten(): void
    {
        $haupt = $this->kunde('Sami Nasser', 'sami@example.com', ['birth_date' => '1970-01-01']);
        $dup = $this->kunde('Sami Nasser', 'sami2@example.com', ['birth_date' => '1970-01-01']);
        $tochter = $this->kunde('Rana Nasser', 'rana@example.com', ['birth_date' => '2015-01-01']);

        // Paar (Hin- und Rueckrichtung) haengt am Duplikat.
        CustomerFamilyRelation::create(['customer_id' => $dup->id, 'related_customer_id' => $tochter->id, 'relationship_type' => 'tochter']);
        CustomerFamilyRelation::create(['customer_id' => $tochter->id, 'related_customer_id' => $dup->id, 'relationship_type' => 'vater']);
        // Zeile zwischen Haupt und Duplikat - wuerde zum Selbst-Paar.
        CustomerFamilyRelation::create(['customer_id' => $haupt->id, 'related_customer_id' => $dup->id, 'relationship_type' => 'sonstiges']);

        app(CustomerMergeService::class)->merge($haupt->fresh('user'), $dup->fresh('user'), null);

        $this->assertTrue(CustomerFamilyRelation::where('customer_id', $haupt->id)->where('related_customer_id', $tochter->id)->where('relationship_type', 'tochter')->exists());
        $this->assertTrue(
            CustomerFamilyRelation::where('customer_id', $tochter->id)->where('related_customer_id', $haupt->id)->where('relationship_type', 'vater')->exists(),
            'Die Rueckrichtung (Tochter -> Vater) darf beim Merge nicht verloren gehen.'
        );
        $this->assertFalse(CustomerFamilyRelation::whereColumn('customer_id', 'related_customer_id')->exists(), 'Kein Selbst-Paar.');
        $this->assertSame(2, CustomerFamilyRelation::count());
    }

    public function test_ki_unterhaltungen_beider_akten_bleiben_erhalten(): void
    {
        // KI-067 war ein Fehlbefund: der UNIQUE auf ai_conversations.customer_id
        // ist seit 2026_09_06_110000 weg - beide Unterhaltungen ueberleben.
        $haupt = $this->kunde('Karim Aziz', 'karim@example.com', ['birth_date' => '1988-08-08']);
        $dup = $this->kunde('Karim Aziz', 'karim2@example.com', ['birth_date' => '1988-08-08']);
        AiConversation::create(['customer_id' => $haupt->id]);
        AiConversation::create(['customer_id' => $dup->id]);

        app(CustomerMergeService::class)->merge($haupt->fresh('user'), $dup->fresh('user'), null);

        $this->assertSame(2, AiConversation::where('customer_id', $haupt->id)->count());
    }

    public function test_sammelauswahl_fuehrt_moegliche_dubletten_nicht_zusammen(): void
    {
        // Gleicher Name, KEIN Geburtsdatum -> nur "moeglich", nie im Sammel-Merge.
        $a = $this->kunde('Nour Khalil', 'nour1@example.com');
        $b = $this->kunde('Nour Khalil', 'nour2@example.com');

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.merge'), [
            'pairs' => ["{$a->id}|{$b->id}"],
        ])->assertRedirect(route('admin.customers.duplicates'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'nicht eindeutig dieselbe Person'));

        $this->assertSame(2, Customer::whereIn('id', [$a->id, $b->id])->count());
    }

    public function test_sammelauswahl_respektiert_portalsperre(): void
    {
        [$a, $b] = $this->zweiAktiveZugaenge();

        $this->actingAs($this->admin())->post(route('admin.customers.duplicates.merge'), [
            'pairs' => ["{$a->id}|{$b->id}"],
        ])->assertRedirect(route('admin.customers.duplicates'));

        $this->assertSame(2, Customer::whereIn('id', [$a->id, $b->id])->count());
    }
}
