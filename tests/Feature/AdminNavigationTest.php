<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Task;
use App\Models\User;
use App\Support\Navigation\AdminNavigation;
use App\Support\Navigation\NavGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Seitenleiste der Beraterwelt (Umbau 03.09.2026, Umstellung 06.09.2026).
 *
 * Die Tests halten die Entscheidungen fest, die man einer fertigen
 * Navigation nicht mehr ansieht - und die beim naechsten neuen Bereich
 * sonst als Erstes wieder verloren gehen:
 *  - in der Seitenleiste steht NUR der taegliche Arbeitsweg; Vertrieb,
 *    Marketing und Administration liegen als Untermenues in den
 *    Einstellungen und bleiben von dort vollstaendig erreichbar,
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
    private function navGroups(User $user): array
    {
        $out = [];
        foreach ($this->nav($user)->groups() as $g) {
            $out[$g->key] = $g;
        }

        return $out;
    }

    /** @return array<string,NavGroup> Die Untermenues der Einstellungen-Ansicht. */
    private function settingsGroups(User $user): array
    {
        $out = [];
        foreach ($this->nav($user)->settingsGroups() as $g) {
            $out[$g->key] = $g;
        }

        return $out;
    }

    // ------------------------------------------------ Struktur (Fall 1-4)

    public function test_die_seitenleiste_traegt_nur_den_taeglichen_arbeitsweg(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $groups = $this->navGroups($admin);

        $this->assertSame(
            ['kunden', 'dokumente', 'postfach', 'mein-tag'],
            array_keys($groups),
            'Die Reihenfolge IST die Information (Betreiber-Vorgabe 06.09.2026).'
        );

        foreach ($groups as $key => $group) {
            $this->assertTrue($group->openByDefault, "Arbeitsbereich {$key} muss offen stehen.");
        }

        // Steuerung und Technik stehen NICHT mehr in der Seitenleiste.
        foreach (['vertrieb', 'marketing', 'administration'] as $key) {
            $this->assertArrayNotHasKey($key, $groups);
        }
    }

    public function test_die_punkte_stehen_innerhalb_ihrer_gruppe_in_der_vorgegebenen_reihenfolge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $groups = $this->navGroups($admin);
        $settings = $this->settingsGroups($admin);

        $keys = fn (NavGroup $g) => array_map(fn ($i) => $i->key, $g->items);

        $this->assertSame(['kunden', 'interessenten', 'vertraege', 'aenderungen'], $keys($groups['kunden']));
        $this->assertSame(['eingang', 'anforderungen'], $keys($groups['dokumente']));
        $this->assertSame(['kundenchat', 'tickets', 'anfragen', 'email', 'team'], $keys($groups['postfach']));
        $this->assertSame(['aufgaben', 'termine'], $keys($groups['mein-tag']));

        $this->assertSame(['provisionen', 'partner', 'vergleichsportale', 'berichte'], $keys($settings['vertrieb']));
        $this->assertSame(
            ['leistungsseiten', 'medien', 'werbung', 'banner', 'newsletter', 'ankuendigungen'],
            $keys($settings['marketing'])
        );
        $this->assertSame(
            ['mitarbeiter', 'zeiten', 'protokoll', 'ki-wissen', 'systemzustand', 'fehler', 'datenimport', 'einstellungen'],
            $keys($settings['administration'])
        );
    }

    public function test_steuerung_und_technik_liegen_als_untermenues_in_den_einstellungen(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $settings = $this->settingsGroups($admin);

        $this->assertSame(['vertrieb', 'marketing', 'administration'], array_keys($settings));

        // Der EINE Weg dorthin steht in der Seitenleiste.
        $this->assertSame(route('admin.settings'), $this->nav($admin)->settings()->url);

        // Und die Einstellungen-Ansicht zeigt jeden dieser Punkte wirklich an.
        $antwort = $this->actingAs($admin)->get(route('admin.settings'))->assertOk();
        foreach ($settings as $group) {
            foreach ($group->items as $item) {
                $antwort->assertSee($item->url, false);
            }
        }
    }

    public function test_wer_keine_einstellungen_darf_landet_nicht_in_einem_403(): void
    {
        foreach (['manager', 'support', 'employee'] as $rolle) {
            $user = User::factory()->create(['role' => $rolle]);
            $punkt = $this->nav($user)->settings();

            $this->assertSame(route('admin.verwaltung'), $punkt->url,
                "Rolle {$rolle} darf nicht auf die admin-only Einstellungen zeigen.");

            $antwort = $this->actingAs($user)->get($punkt->url);
            $this->assertNotSame(403, $antwort->getStatusCode());

            // Auch hier ist jeder fuer die Rolle sichtbare Punkt erreichbar.
            foreach ($this->nav($user)->settingsGroups() as $group) {
                foreach ($group->items as $item) {
                    $antwort->assertSee($item->url, false);
                }
            }
        }
    }

    public function test_technik_und_konfiguration_liegen_ausschliesslich_in_der_administration(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $groups = $this->navGroups($user);
        $adminLabels = array_map(fn ($i) => $i->label, $this->settingsGroups($user)['administration']->items);

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
        $settings = $this->settingsGroups(User::factory()->create(['role' => 'admin']));
        $labels = array_map(fn ($i) => $i->label, $settings['vertrieb']->items);

        $this->assertSame(['Provisionen'], array_values(array_filter(
            $labels,
            fn ($l) => str_contains($l, 'Provision') || str_contains($l, 'Vermittler')
        )), 'Provisionen, Vermittler-Abrechnung und Provisionsmanagement sind EIN Modul mit Registerkarten.');
    }

    public function test_die_gesamte_navigation_bleibt_uebersichtlich(): void
    {
        $nav = $this->nav(User::factory()->create(['role' => 'admin']));
        $sichtbar = 2; // Dashboard + Einstellungen
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

        // Die Badge-Zahlen werden seit UX-3 kurz zwischengespeichert
        // (NavBadges::TTL_SEKUNDEN). Ohne dieses Leeren pruefte die zweite
        // Zusicherung den Cache-Eintrag der ersten - also nicht mehr die
        // Zaehlregel, um die es hier geht.
        Cache::flush();

        $this->assertSame(1, $this->badge($user, 'mein-tag', 'aufgaben'));
    }

    public function test_ankuendigungen_tragen_keine_zahl(): void
    {
        Announcement::create(['title' => 'Info', 'body' => 'Text', 'expires_at' => null]);

        $settings = $this->settingsGroups(User::factory()->create(['role' => 'admin']));
        foreach ($settings['marketing']->items as $item) {
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

        $groups = $this->navGroups($user);
        $this->assertSame(1, $groups['mein-tag']->badgeSum());
    }

    // ------------------------------------------- Rollen & Wege (Fall 8-10)

    public function test_mitarbeiter_sehen_keine_verwaltung(): void
    {
        $user = User::factory()->create(['role' => 'employee']);

        $this->assertArrayNotHasKey('administration', $this->settingsGroups($user));
        $this->assertArrayHasKey('postfach', $this->navGroups($user));
    }

    public function test_kein_menuepunkt_fuehrt_in_ein_403(): void
    {
        foreach (['admin', 'manager', 'support', 'employee'] as $rolle) {
            $user = User::factory()->create(['role' => $rolle]);
            $nav = $this->nav($user);

            $ziele = [$nav->home()->url, $nav->settings()->url];
            foreach (array_merge($nav->groups(), $nav->settingsGroups()) as $g) {
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
        foreach ($this->navGroups($user)[$group]->items as $navItem) {
            if ($navItem->key === $item) {
                return $navItem->badge;
            }
        }

        $this->fail("Punkt {$item} fehlt in Gruppe {$group}.");
    }
}
