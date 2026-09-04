<?php

namespace App\Support\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * DIE EINE Quelle der Beraterwelt-Navigation (Betreiber-Auftrag 03.09.2026).
 *
 * Vorher stand die Navigation als 200 Zeilen Blade im Layout: Rolle, Zaehler,
 * Icon, Aktiv-Muster und Reihenfolge lagen je Punkt ineinander, und jeder
 * neue Bereich wurde einfach unten angehaengt - so entstanden 8 Gruppen mit
 * 31 flachen Punkten, in denen die taegliche Arbeit zwischen Verwaltung und
 * Technik verschwand.
 *
 * ORDNUNGSPRINZIP (in dieser Reihenfolge, sie IST die Information):
 *  1. Was ein Mitarbeiter jeden Tag tut, steht oben und offen:
 *     Postfach -> Mein Tag -> Kunden -> Dokumente.
 *  2. Was Steuerung ist, steht darunter und zugeklappt: Vertrieb, Marketing.
 *  3. Was Technik und Konfiguration ist, steht ganz unten und zugeklappt:
 *     Administration. Diese Punkte ruft man ein paar Mal im Monat auf - sie
 *     duerfen den Arbeitsweg nicht saeumen.
 *
 * WAS KEIN EIGENER PUNKT MEHR IST (es wurde zur Registerkarte im Modul):
 *  - "Verfassen" ist eine Aktion im E-Mail-Postfach, kein Ort.
 *  - "Vermittler-Abrechnung", "Provisionen" und "Provisionsmanagement" waren
 *    drei Punkte fuer EINEN Vorgang - jetzt ein Modul mit Registerkarten.
 *  - "Kinder werden 15" ist eine Liste im Kundenmodul, kein Hauptbereich.
 *  - "Aktivitaetslog" und "Aktivitaet & Zeiten" stehen zusammen in der
 *    Administration.
 *
 * Sichtbarkeit spiegelt die ROUTE: ein Punkt, der zu 403 fuehrt, ist
 * schlimmer als ein fehlender. Sie ist aber nie der Schutz selbst - der
 * haengt weiterhin an Middleware und Gate.
 */
final class AdminNavigation
{
    public function __construct(private readonly User $user, private readonly NavBadges $badges)
    {
    }

    public static function for(User $user): self
    {
        return new self($user, new NavBadges($user));
    }

    /** Der Startpunkt steht ausserhalb jeder Gruppe - er ist nie zugeklappt. */
    public function home(): NavItem
    {
        return new NavItem('dashboard', 'Dashboard', route('admin.dashboard'), 'dashboard', ['admin.dashboard']);
    }

    /** @return NavGroup[] Nur Gruppen, die fuer diesen Nutzer Punkte haben. */
    public function groups(): array
    {
        $groups = [
            $this->postfach(),
            $this->meinTag(),
            $this->kunden(),
            $this->dokumente(),
            $this->vertrieb(),
            $this->marketing(),
            $this->administration(),
        ];

        return array_values(array_filter($groups, fn (NavGroup $g) => ! $g->isEmpty()));
    }

    /**
     * Ein Eingang fuer alles, was von aussen hereinkommt - Chat, Ticket,
     * Anfrage, E-Mail - plus den internen Draht. Vorher lagen dieselben
     * Nachrichten in zwei Gruppen ("Kommunikation" und "E-Mail"), und ein
     * Mitarbeiter musste wissen, ueber welchen Kanal ein Kunde geschrieben
     * hat, bevor er nachsehen konnte.
     */
    private function postfach(): NavGroup
    {
        return new NavGroup('postfach', 'Postfach', $this->items([
            ['kundenchat', 'Kundenchat', 'admin.customer_chat', 'chat', ['admin.customer_chat*'], 'customer_messages', NavBadges::TONE_URGENT],
            ['tickets', 'Tickets', 'admin.tickets', 'ticket', ['admin.tickets*', 'admin.ticket.*'], 'tickets'],
            ['anfragen', 'Anfragen', 'admin.inquiries', 'megaphone', ['admin.inquiries*']],
            ['email', 'E-Mail', 'admin.email_inbox', 'mail', ['admin.email_inbox*', 'admin.email.*', 'admin.email_accounts.*', 'admin.templates*'], 'email_suggestions'],
            ['team', 'Team-Chat', 'admin.chat.index', 'team', ['admin.chat.*'], 'team_chat', NavBadges::TONE_URGENT],
        ]));
    }

    /** Der Arbeitstag: was heute zu tun ist und wann. */
    private function meinTag(): NavGroup
    {
        return new NavGroup('mein-tag', 'Mein Tag', $this->items([
            ['aufgaben', 'Aufgaben', 'admin.tasks', 'task', ['admin.tasks*'], 'tasks_due'],
            ['termine', 'Termine', 'admin.appointments', 'calendar', ['admin.appointments*'], 'appointments_today'],
        ]));
    }

    /**
     * Der Kern des CRM in Arbeitsreihenfolge: Interessent -> Kunde ->
     * Vertrag, dazu was am Kunden zu entscheiden ist. Familien-Uebergaenge
     * und Aenderungsantraege gehoeren hierher, nicht in die Verwaltung.
     */
    private function kunden(): NavGroup
    {
        return new NavGroup('kunden', 'Kunden', $this->items([
            ['kunden', 'Kunden', 'admin.customers', 'users', ['admin.customers*', 'admin.customer*', 'admin.family.*']],
            ['interessenten', 'Interessenten', 'admin.leads.index', 'lead', ['admin.leads*']],
            ['vertraege', 'Verträge', 'admin.contracts', 'contract', ['admin.contracts*', 'admin.contract.*']],
            ['aenderungen', 'Änderungsanträge', 'admin.change_requests', 'change', ['admin.change_requests*', 'admin.change_notifications*'], 'change_requests'],
        ]));
    }

    /** Papier rein, Papier angefordert - zwei Seiten desselben Vorgangs. */
    private function dokumente(): NavGroup
    {
        return new NavGroup('dokumente', 'Dokumente', $this->items([
            ['eingang', 'Eingang', 'admin.documents.inbox', 'inbox', ['admin.documents.inbox'], 'document_inbox'],
            ['anforderungen', 'Anforderungen', 'admin.document_requests', 'contract', ['admin.document_requests*'], 'document_requests'],
        ]));
    }

    /**
     * Steuerung statt Tagesgeschaeft - deshalb zugeklappt.
     * "Provisionen" ist EIN Punkt fuer alle Geldwege: das
     * Provisionsmanagement bringt Importe, Abrechnungen, Buchungen,
     * fehlende Provisionen, den TARIFCHECK24-Abgleich und die Auszahlungen
     * an eigene Vermittler als Registerkarten mit.
     */
    private function vertrieb(): NavGroup
    {
        $items = [];

        if (Gate::allows('provisionen-verwalten')) {
            $items[] = ['provisionen', 'Provisionen', 'admin.provisionsmanagement.dashboard', 'money', [
                'admin.provisionsmanagement.*', 'admin.commissions_internal.*',
                'admin.commissions*', 'admin.provisions*', 'admin.vermittler.*',
            ], 'commissions'];
        } elseif ($this->isManagement()) {
            // Ohne das Recht bleibt der Weg zu den Auszahlungen an die eigenen
            // Vermittler - die Provisions-EINGAENGE der Pools sieht er nicht.
            $items[] = ['provisionen', 'Provisionen', 'admin.commissions', 'money',
                ['admin.commissions*', 'admin.provisions*', 'admin.vermittler.*'], 'commissions'];
        }

        if ($this->isManagement()) {
            $items[] = ['partner', 'Partner', 'admin.partners', 'partner', ['admin.partners*', 'admin.partner*']];
            $items[] = ['vergleichsportale', 'Vergleichsportale', 'admin.tarifrechner', 'calculator', ['admin.tarifrechner*']];
        }

        // Berichte darf jede Staff-Rolle sehen (die Route ist offen).
        $items[] = ['berichte', 'Berichte', 'admin.reports', 'chart', ['admin.reports*']];

        // Ohne Verwaltungsrolle bleiben nur die Berichte - dann waere
        // "Vertrieb" eine Ueberschrift ueber etwas anderem.
        $label = $this->isManagement() ? 'Vertrieb' : 'Auswertung';

        return new NavGroup('vertrieb', $label, $this->items($items), openByDefault: false);
    }

    /**
     * Alles, was nach aussen sichtbar ist. Website-Medien standen bisher
     * einzeln zwischen den Arbeitsbereichen, Banner und Werbeanzeigen waren
     * dagegen nur ueber die Einstellungen erreichbar - beides an derselben
     * Stelle falsch.
     */
    private function marketing(): NavGroup
    {
        $items = [
            ['medien', 'Website-Medien', 'admin.media', 'image', ['admin.media*']],
            ['newsletter', 'Newsletter', 'admin.email_marketing', 'mail', ['admin.email_marketing*']],
            ['ankuendigungen', 'Ankündigungen', 'admin.announcements', 'megaphone', ['admin.announcements*']],
        ];

        if ($this->isManagement()) {
            $items[] = ['banner', 'Banner', 'admin.banners', 'image', ['admin.banners*']];
            $items[] = ['werbung', 'Werbeanzeigen', 'admin.werbung', 'globe', ['admin.werbung*']];
            $items[] = ['leistungsseiten', 'Leistungsseiten', 'admin.service_pages', 'globe', ['admin.service_pages*']];
        }

        return new NavGroup('marketing', 'Marketing', $this->items($items), openByDefault: false);
    }

    /**
     * Technik und Konfiguration - der Bereich, der im Alltag NICHT im Weg
     * stehen darf. Zugeklappt, ganz unten, und nur fuer die Verwaltung.
     */
    private function administration(): NavGroup
    {
        if (! $this->isManagement()) {
            return new NavGroup('administration', 'Administration', [], openByDefault: false);
        }

        $items = [
            ['mitarbeiter', 'Mitarbeiter', 'admin.employees', 'employees', ['admin.employees*', 'admin.employee*', 'admin.team.*']],
            ['zeiten', 'Zeiten & Aktivität', 'admin.activity.index', 'clock', ['admin.activity.*']],
            ['protokoll', 'Aktivitätslog', 'admin.activity_log', 'log', ['admin.activity_log*']],
            ['ki-wissen', 'KI-Wissensbasis', 'admin.ai_knowledge', 'brain', ['admin.ai_knowledge*', 'admin.ai_knowledge_gaps*']],
            ['systemzustand', 'Systemzustand', 'admin.system_health', 'pulse', ['admin.system_health*']],
            ['fehler', 'Fehler', 'admin.errors', 'alert', ['admin.errors*']],
            ['datenimport', 'Import / Export', 'admin.import_export', 'inbox', ['admin.import_export*', 'admin.import*', 'admin.export*']],
        ];

        // Die Einstellungen sind role:admin - ein Manager bekaeme dort 403.
        if ($this->user->role === 'admin') {
            $items[] = ['einstellungen', 'Einstellungen', 'admin.settings', 'settings', ['admin.settings*']];
        }

        return new NavGroup('administration', 'Administration', $this->items($items), openByDefault: false);
    }

    /**
     * Baut die Punkte und laesst die Zaehler erst HIER zu Zahlen werden -
     * so steht in der Beschreibung oben nur die Struktur.
     *
     * @param array<int,array{0:string,1:string,2:string,3:string,4?:array,5?:string,6?:string}> $rows
     * @return NavItem[]
     */
    private function items(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            [$key, $label, $routeName, $icon] = $row;
            $patterns = $row[4] ?? [];
            $badgeKey = $row[5] ?? null;

            $items[] = new NavItem(
                key: $key,
                label: $label,
                url: route($routeName),
                icon: $icon,
                activePatterns: $patterns,
                badge: $badgeKey ? $this->badges->get($badgeKey) : 0,
                badgeTone: $row[6] ?? NavBadges::TONE_ATTENTION,
            );
        }

        return $items;
    }

    private function isManagement(): bool
    {
        return in_array($this->user->role, ['admin', 'manager'], true);
    }
}
