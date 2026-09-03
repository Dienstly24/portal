<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Task;
use App\Models\User;
use App\Support\Navigation\AdminNavigation;
use App\Support\Navigation\NavGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seitenleiste der Beraterwelt (Umbau 03.09.2026).
 *
 * Die Tests halten die Entscheidungen fest, die man einer fertigen
 * Navigation nicht mehr ansieht - und die beim naechsten neuen Bereich
 * sonst als Erstes wieder verloren gehen:
 *  - taegliche Arbeit oben und offen, Technik unten und zugeklappt,
 *  - ein Badge ist eine AUFFORDERUNG, keine Statistik,
 *  - kein Punkt fuehrt in ein 403,
 *  - kein zusammengelegter Bereich wird unerreichbar.
 */
class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function nav(User $user): AdminNavigation
    {
        $this->actingAs($user);
        $this->get(route('admin.dashboard')); // Request-Kontext fuer route()/routeIs()

        return AdminNavigation::for($user);
    }

    /** @return array<string,NavGroup> */
    private function groups(User $user): array
    {
        $out = [];
        foreach ($this->nav($user)->groups() as $g) {
            $out[$g->key] = $g;
        }

        return $out;
    }

    // ------------------------------------------------ Struktur (Fall 1-4)

    public function test_die_taegliche_arbeit_steht_oben_und_offen(): void
    {
        $groups = $this->groups(User::factory()->create(['role' => 'admin']));

        $this->assertSame(
            ['postfach', 'mein-tag', 'kunden', 'dokumente', 'vertrieb', 'marketing', 'administration'],
            array_keys($groups),
            'Die Reihenfolge IST die Information: erst der Arbeitstag, dann Steuerung, dann Technik.'
        );

        foreach (['postfach', 'mein-tag', 'kunden', 'dokumente'] as $key) {
            $this->assertTrue($groups[$key]->openByDefault, "Arbeitsbereich {$key} muss offen stehen.");
        }
        foreach (['vertrieb', 'marketing', 'administration'] as $key) {
            $this->assertFalse($groups[$key]->openByDefault, "Bereich {$key} darf den Arbeitsweg nicht saeumen.");
        }
    }

    public function test_technik_und_konfiguration_liegen_ausschliesslich_in_der_administration(): void
    {
        $groups = $this->groups(User::factory()->create(['role' => 'admin']));
        $adminLabels = array_map(fn ($i) => $i->label, $groups['administration']->items);

        foreach (['Systemzustand', 'Fehler', 'Aktivitätslog', 'Einstellungen'] as $label) {
            $this->assertContains($label, $adminLabels);
        }

        // ... und in keinem der taeglichen Bereiche.
        foreach (['postfach', 'mein-tag', 'kunden', 'dokumente'] as $key) {
            foreach ($groups[$key]->items as $item) {
                $this->assertNotContains($item->label, ['Systemzustand', 'Fehler', 'Aktivitätslog', 'Einstellungen']);
            }
        }
    }

    public function test_die_drei_provisions_bereiche_sind_ein_punkt(): void
    {
        $groups = $this->groups(User::factory()->create(['role' => 'admin']));
        $labels = array_map(fn ($i) => $i->label, $groups['vertrieb']->items);

        $this->assertSame(['Provisionen'], array_values(array_filter(
            $labels,
            fn ($l) => str_contains($l, 'Provision') || str_contains($l, 'Vermittler')
        )), 'Provisionen, Vermittler-Abrechnung und Provisionsmanagement sind EIN Modul mit Registerkarten.');
    }

    public function test_die_gesamte_navigation_bleibt_uebersichtlich(): void
    {
        $nav = $this->nav(User::factory()->create(['role' => 'admin']));
        $sichtbar = 1; // Dashboard
        foreach ($nav->groups() as $g) {
            $sichtbar += $g->openByDefault ? count($g->items) : 0;
        }

        // Vorher: 31 Punkte, alle Gruppen offen. Was ohne Zutun sichtbar ist,
        // muss auf einen Blick erfassbar bleiben.
        $this->assertLessThanOrEqual(15, $sichtbar);
    }

    // ------------------------------------------------- Badges (Fall 5-7)

    public function test_badge_nur_bei_faelliger_aufgabe_nicht_bei_jeder_offenen(): void
    {
        $user = User::factory()->create(['role' => 'employee']);

        $this->task($user, now()->addMonth(), 'Spaeter');

        $this->assertSame(0, $this->badge($user, 'mein-tag', 'aufgaben'),
            'Eine Aufgabe fuer naechsten Monat ist heute keine Handlung.');

        $this->task($user, now()->subDay(), 'Ueberfaellig');

        $this->assertSame(1, $this->badge($user, 'mein-tag', 'aufgaben'));
    }

    public function test_ankuendigungen_tragen_keine_zahl(): void
    {
        Announcement::create(['title' => 'Info', 'body' => 'Text', 'expires_at' => null]);

        $groups = $this->groups(User::factory()->create(['role' => 'admin']));
        foreach ($groups['marketing']->items as $item) {
            if ($item->key === 'ankuendigungen') {
                $this->assertFalse($item->hasBadge(),
                    'Aktive Ankuendigungen sind eine Statistik, keine offene Aufgabe.');

                return;
            }
        }
        $this->fail('Punkt "Ankündigungen" fehlt.');
    }

    public function test_eingeklappte_gruppe_zeigt_die_summe_ihrer_offenen_vorgaenge(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->task($user, today(), 'Heute');

        $groups = $this->groups($user);
        $this->assertSame(1, $groups['mein-tag']->badgeSum());
    }

    // ------------------------------------------- Rollen & Wege (Fall 8-10)

    public function test_mitarbeiter_sehen_keine_verwaltung(): void
    {
        $groups = $this->groups(User::factory()->create(['role' => 'employee']));

        $this->assertArrayNotHasKey('administration', $groups);
        $this->assertArrayHasKey('postfach', $groups);
    }

    public function test_kein_menuepunkt_fuehrt_in_ein_403(): void
    {
        foreach (['admin', 'manager', 'support', 'employee'] as $rolle) {
            $user = User::factory()->create(['role' => $rolle]);
            $nav = $this->nav($user);

            $ziele = [$nav->home()->url];
            foreach ($nav->groups() as $g) {
                foreach ($g->items as $item) {
                    $ziele[] = $item->url;
                }
            }

            foreach ($ziele as $url) {
                $antwort = $this->actingAs($user)->get($url);
                $this->assertNotSame(403, $antwort->getStatusCode(),
                    "Rolle {$rolle} sieht einen Punkt, der auf {$url} verboten ist.");
            }
        }
    }

    public function test_zusammengelegte_bereiche_bleiben_erreichbar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // "Verfassen" ist keine Seitenleiste mehr, sondern Registerkarte im
        // E-Mail-Modul; "Kinder werden 15" haengt an der Kundenliste.
        $this->actingAs($admin)->get(route('admin.email_inbox'))
            ->assertOk()->assertSee(route('admin.email.compose'), false);

        $this->actingAs($admin)->get(route('admin.customers'))
            ->assertOk()->assertSee(route('admin.family.transitions'), false);

        // Die drei Provisions-Wege finden sich gegenseitig.
        $this->actingAs($admin)->get(route('admin.provisionsmanagement.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.commissions'), false)
            ->assertSee(route('admin.vermittler.index'), false);
    }

    // --------------------------------------------------- Aktiver Zustand

    public function test_die_aktive_seite_ist_markiert_und_ihre_gruppe_offen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.tickets'))
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee('data-has-active="1"', false);
    }

    private function task(User $user, $due, string $titel): void
    {
        Task::create([
            'title' => $titel, 'assigned_to' => $user->id, 'created_by' => $user->id,
            'type' => 'follow_up', 'status' => 'open', 'priority' => 'medium',
            'due_date' => $due->toDateString(),
        ]);
    }

    private function badge(User $user, string $group, string $item): int
    {
        foreach ($this->groups($user)[$group]->items as $navItem) {
            if ($navItem->key === $item) {
                return $navItem->badge;
            }
        }

        $this->fail("Punkt {$item} fehlt in Gruppe {$group}.");
    }
}
