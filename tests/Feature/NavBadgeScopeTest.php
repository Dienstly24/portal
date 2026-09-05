<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\DocumentRequest;
use App\Models\User;
use App\Support\Navigation\NavBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UX-3: die Zahl in der Seitenleiste darf NIE mehr zaehlen, als die Seite
 * dahinter zeigt.
 *
 * Ein Badge ist eine Aussage ueber den Bestand. Zaehlt es ueber das
 * Portfolio hinaus, verraet es einem eingeschraenkten Mitarbeiter, wie viel
 * anderswo im Haus los ist - und die Zahl stimmt obendrein nie mit der
 * Liste ueberein, die er nach dem Klick sieht. Genau das war bei
 * "Termine heute" und "Anforderungen" der Fall.
 */
class NavBadgeScopeTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $email): Customer
    {
        $u = User::factory()->create(['role' => 'customer', 'email' => $email]);

        // fresh(): Customer::create() liefert die im creating-Hook erzeugte
        // id als UUID-OBJEKT zurueck. attach() deutet ein Objekt als Liste
        // und schreibt dann eine leere Pivot-Zeile - dieselbe Falle steht
        // schon in EmployeeCustomerManagementTest.
        return Customer::create([
            'user_id' => $u->id,
            'customer_number' => 'C-'.strtoupper(substr(md5($email), 0, 8)),
        ])->fresh();
    }

    /** Mitarbeiter mit begrenztem Portfolio, dem $eigen zugeordnet ist. */
    private function mitarbeiterMit(Customer $eigen): User
    {
        $user = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $user->assignedCustomers()->attach($eigen->id);

        return $user;
    }

    private function termin(Customer $kunde, User $fuer): void
    {
        Appointment::create([
            'customer_id' => $kunde->id,
            'assigned_to' => $fuer->id,
            'title' => 'Beratung',
            'starts_at' => now()->setTime(10, 0),
            'ends_at' => now()->setTime(11, 0),
            'status' => 'scheduled',
        ]);
    }

    public function test_termin_badge_zaehlt_nur_das_eigene_portfolio(): void
    {
        $eigen = $this->kunde('eigen@k.de');
        $fremd = $this->kunde('fremd@k.de');
        $mitarbeiter = $this->mitarbeiterMit($eigen);
        $anderer = User::factory()->create(['role' => 'employee']);

        $this->termin($eigen, $mitarbeiter);
        $this->termin($fremd, $anderer);

        $this->assertSame(1, (new NavBadges($mitarbeiter))->get('appointments_today'));
    }

    public function test_termin_badge_stimmt_mit_der_terminseite_ueberein(): void
    {
        $eigen = $this->kunde('eigen2@k.de');
        $fremd = $this->kunde('fremd2@k.de');
        $mitarbeiter = $this->mitarbeiterMit($eigen);
        $this->termin($eigen, $mitarbeiter);
        $this->termin($fremd, User::factory()->create(['role' => 'employee']));

        $seite = $this->actingAs($mitarbeiter)->get(route('admin.appointments'));
        $seite->assertOk();

        // Genau die Aussage, um die es geht: Badge == Liste.
        $this->assertSame(
            $seite->viewData('appointments')->count(),
            (new NavBadges($mitarbeiter))->get('appointments_today'),
        );
    }

    public function test_admin_sieht_weiterhin_alle_termine(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->termin($this->kunde('a@k.de'), $admin);
        $this->termin($this->kunde('b@k.de'), $admin);

        $this->assertSame(2, (new NavBadges($admin))->get('appointments_today'));
    }

    public function test_anforderungen_badge_und_seite_bleiben_im_portfolio(): void
    {
        $eigen = $this->kunde('eigen3@k.de');
        $fremd = $this->kunde('fremd3@k.de');
        $mitarbeiter = $this->mitarbeiterMit($eigen);

        foreach ([$eigen, $fremd] as $k) {
            DocumentRequest::create([
                'customer_id' => $k->id,
                'title' => 'Ausweis',
                'status' => 'uploaded',
                'uploaded_at' => now(),
                'requested_by' => $mitarbeiter->id,
            ]);
        }

        $this->assertSame(1, (new NavBadges($mitarbeiter))->get('document_requests'));

        $seite = $this->actingAs($mitarbeiter)->get(route('admin.document_requests'));
        $seite->assertOk();
        $this->assertSame(1, $seite->viewData('awaitingReview')->count());
        // Der fremde Kunde darf auf der Seite nirgends auftauchen.
        $seite->assertDontSee($fremd->customer_number);
    }

    public function test_zahl_wird_zwischengespeichert_statt_je_aufruf_gezaehlt(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->termin($this->kunde('cache@k.de'), $admin);

        // Erster Aufruf fuellt den Cache ...
        $this->assertSame(1, (new NavBadges($admin))->get('appointments_today'));

        // ... ein NEUES Objekt (also ein neuer Request) darf dafuer keine
        // Abfrage mehr brauchen.
        $abfragen = 0;
        DB::listen(function () use (&$abfragen) { $abfragen++; });
        $this->assertSame(1, (new NavBadges($admin))->get('appointments_today'));
        $this->assertSame(0, $abfragen, 'Das Badge hat trotz Cache erneut gezaehlt.');
    }

    public function test_cache_ist_je_benutzer_getrennt(): void
    {
        $eigen = $this->kunde('sep1@k.de');
        $fremd = $this->kunde('sep2@k.de');
        $mitarbeiter = $this->mitarbeiterMit($eigen);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->termin($eigen, $mitarbeiter);
        $this->termin($fremd, $admin);

        // Reihenfolge ist Absicht: erst der Admin (2), dann der begrenzte
        // Mitarbeiter (1). Ein gemeinsamer Schluessel wuerde ihm die 2 des
        // Admins zeigen - also die Groesse eines fremden Bestandes.
        $this->assertSame(2, (new NavBadges($admin))->get('appointments_today'));
        $this->assertSame(1, (new NavBadges($mitarbeiter))->get('appointments_today'));

        $this->assertNotNull(Cache::get('nav_badges:v1:'.$admin->id.':appointments_today'));
        $this->assertNotNull(Cache::get('nav_badges:v1:'.$mitarbeiter->id.':appointments_today'));
    }
}
