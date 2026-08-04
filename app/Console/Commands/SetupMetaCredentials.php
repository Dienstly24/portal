<?php

namespace App\Console\Commands;

use App\Support\EnvFileWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Gefuehrter Einrichtungs-Assistent fuer die Meta-API (Social-Publishing
 * Phase 2) - gebaut fuer Betreiber OHNE Technik-Kenntnisse:
 *
 *   php artisan meta:einrichten
 *
 * Der Assistent fragt NUR das System-User-Token ab (Eingabe unsichtbar),
 * findet Seite + Instagram-Konto AUTOMATISCH ueber /me/accounts, testet
 * die Verbindung und schreibt die Werte selbst in die Server-.env.
 * Das Token wandert so direkt vom Business Manager auf den Server -
 * nie durch Chat, Repo oder Ticket (Sicherheitsregel des Projekts).
 *
 *   php artisan meta:einrichten --pruefen   -> nur Verbindung testen
 */
class SetupMetaCredentials extends Command
{
    protected $signature = 'meta:einrichten {--pruefen : Nur die aktuelle Verbindung testen, nichts aendern}';

    protected $description = 'Meta-API (Facebook/Instagram) einrichten: Token abfragen, Seite finden, Verbindung testen, .env schreiben';

    public function handle(EnvFileWriter $env): int
    {
        if ($this->option('pruefen')) {
            return $this->pruefen();
        }

        $this->info('Meta-API einrichten (Facebook + Instagram)');
        $this->line('Benoetigt wird nur das System-User-Token aus dem Business Manager.');
        $this->line('Anleitung Schritt fuer Schritt: docs/ANLEITUNG_META_API_AR.md');
        $this->newLine();

        $token = trim((string) $this->secret('System-User-Token einfuegen (Eingabe bleibt unsichtbar)'));
        if ($token === '') {
            $this->error('Kein Token eingegeben - abgebrochen.');

            return self::FAILURE;
        }

        // Seiten + verknuepfte Instagram-Konten automatisch ermitteln -
        // der Betreiber muss keine IDs heraussuchen. WICHTIG: access_token
        // mitabfragen - das ist das PAGE Access Token, das Seiten-Posts
        // und Seiten-Insights zwingend brauchen (das System-User-Token
        // reicht dort nicht); von einem nie ablaufenden System-User-Token
        // abgeleitet laeuft es ebenfalls nicht ab.
        $accounts = $this->graphGet('me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'limit' => 25,
        ], $token);
        if ($accounts === null) {
            return self::FAILURE;
        }

        $pages = $accounts['data'] ?? [];
        if (empty($pages)) {
            $this->error('Dem Token ist keine Facebook-Seite zugewiesen.');
            $this->line('Im Business Manager beim Systembenutzer "Assets zuweisen" -> Seite auswaehlen (Anleitung Schritt 2), dann hier neu starten.');

            return self::FAILURE;
        }

        if (count($pages) === 1) {
            $page = $pages[0];
        } else {
            $namen = array_map(fn ($p) => $p['name'], $pages);
            $auswahl = $this->choice('Mehrere Seiten gefunden - welche soll das System nutzen?', $namen);
            $page = $pages[array_search($auswahl, $namen, true)];
        }

        $ig = $page['instagram_business_account'] ?? null;

        // Werbekonto fuer die Anzeigen-Steuerung - optional: ohne bleibt
        // das Posten voll nutzbar, nur /admin/werbung bleibt deaktiviert.
        $adAccountId = '';
        $adAccountName = null;
        $adAccounts = $this->graphGet('me/adaccounts', ['fields' => 'id,name,currency', 'limit' => 25], $token);
        $adData = $adAccounts['data'] ?? [];
        if (!empty($adData)) {
            if (count($adData) === 1) {
                $konto = $adData[0];
            } else {
                $namen = array_map(fn ($a) => $a['name'] . ' (' . $a['id'] . ')', $adData);
                $auswahl = $this->choice('Mehrere Werbekonten gefunden - welches soll das System nutzen?', $namen);
                $konto = $adData[array_search($auswahl, $namen, true)];
            }
            $adAccountId = $konto['id']; // kommt bereits als act_...
            $adAccountName = $konto['name'];
            // Budgets werden im System als EUR beschriftet und in Cent
            // uebertragen - eine andere Konto-Waehrung wuerde still falsche
            // Betraege bedeuten, deshalb deutlich warnen.
            $waehrung = strtoupper((string) ($konto['currency'] ?? 'EUR'));
            if ($waehrung !== 'EUR') {
                $this->warn('ACHTUNG: Das Werbekonto rechnet in ' . $waehrung . ', das System beschriftet Budgets als EUR - Betraege gelten dann in ' . $waehrung . '.');
            }
        }

        $this->newLine();
        $this->info('Gefunden:');
        $this->line('  Facebook-Seite: ' . $page['name'] . ' (ID ' . $page['id'] . ')');
        $this->line($ig
            ? '  Instagram:      @' . ($ig['username'] ?? '?') . ' (ID ' . $ig['id'] . ')'
            : '  Instagram:      KEIN Business-Konto mit der Seite verknuepft - es wird nur Facebook eingerichtet.');
        $this->line($adAccountName
            ? '  Werbekonto:     ' . $adAccountName . ' (' . $adAccountId . ')'
            : '  Werbekonto:     KEINES zugewiesen - Anzeigen-Steuerung bleibt aus (im Business Manager dem Systembenutzer das Werbekonto zuweisen, dann neu ausfuehren).');

        $pageToken = (string) ($page['access_token'] ?? '');
        if ($pageToken === '') {
            // Ohne Page-Token scheitern Seiten-Posts - lieber hier stoppen
            // als spaeter mit einem kryptischen (#200) beim Posten.
            $this->error('Meta hat kein Seiten-Token geliefert - der Systembenutzer braucht "Inhalte veroeffentlichen" (oder volle Kontrolle) auf der Seite.');

            return self::FAILURE;
        }

        $env->set([
            'META_PAGE_ID' => $page['id'],
            'META_IG_USER_ID' => $ig['id'] ?? '',
            'META_AD_ACCOUNT_ID' => $adAccountId,
            'META_ACCESS_TOKEN' => $token,
            'META_PAGE_ACCESS_TOKEN' => $pageToken,
        ]);

        // Frische Werte sofort wirksam machen (Deploy cached spaeter neu).
        if (!app()->runningUnitTests()) {
            $this->callSilent('config:clear');
        }

        $this->newLine();
        $this->info('Fertig! Werte in der .env gespeichert, Verbindung getestet.');
        $this->line('In der Bannerverwaltung (Banner -> Social-Media) erscheinen jetzt die Buttons "Jetzt per API posten" und die automatische Planung.');

        return self::SUCCESS;
    }

    /** Aktuelle Konfiguration nur testen (--pruefen). */
    private function pruefen(): int
    {
        $cfg = config('services.meta', []);
        if (empty($cfg['token']) || empty($cfg['page_id'])) {
            $this->warn('Meta-API ist nicht konfiguriert (META_... fehlen in der .env). Einrichten mit: php artisan meta:einrichten');

            return self::FAILURE;
        }

        $page = $this->graphGet((string) $cfg['page_id'], ['fields' => 'name'], (string) $cfg['token']);
        if ($page === null) {
            return self::FAILURE;
        }
        $this->info('Facebook-Seite: ' . ($page['name'] ?? '?') . ' - Verbindung OK.');

        // Posten braucht das SEITEN-Token - explizit mittesten, sonst
        // meldet der Check OK, obwohl jeder Facebook-Post scheitern wuerde.
        $pageToken = (string) ($cfg['page_token'] ?? '');
        if ($pageToken === '') {
            $abgeleitet = $this->graphGet((string) $cfg['page_id'], ['fields' => 'access_token'], (string) $cfg['token']);
            $pageToken = (string) ($abgeleitet['access_token'] ?? '');
        }
        if ($pageToken === '') {
            $this->error('Seiten-Token fehlt - meta:einrichten erneut ausfuehren (Posten auf die Seite wuerde scheitern).');

            return self::FAILURE;
        }
        $this->info('Seiten-Token: vorhanden - Posten auf die Seite moeglich.');

        if (!empty($cfg['ig_user_id'])) {
            $ig = $this->graphGet((string) $cfg['ig_user_id'], ['fields' => 'username'], (string) $cfg['token']);
            if ($ig === null) {
                return self::FAILURE;
            }
            $this->info('Instagram: @' . ($ig['username'] ?? '?') . ' - Verbindung OK.');
        } else {
            $this->warn('Instagram ist nicht konfiguriert (nur Facebook aktiv).');
        }

        if (!empty($cfg['ad_account_id'])) {
            $konto = $this->graphGet((string) $cfg['ad_account_id'], ['fields' => 'name'], (string) $cfg['token']);
            if ($konto === null) {
                return self::FAILURE;
            }
            $this->info('Werbekonto: ' . ($konto['name'] ?? '?') . ' - Verbindung OK.');
        } else {
            $this->warn('Werbekonto ist nicht konfiguriert (Anzeigen-Steuerung aus).');
        }

        return self::SUCCESS;
    }

    /** Graph-GET mit verstaendlicher Fehlererklaerung; null bei Fehler. */
    private function graphGet(string $path, array $params, string $token): ?array
    {
        $url = 'https://graph.facebook.com/'
            . config('services.meta.graph_version', 'v23.0') . '/' . ltrim($path, '/');

        try {
            // Token als Bearer-Header, nie in der URL - Verbindungsfehler
            // enthalten sonst das Token im Meldungstext.
            $resp = Http::timeout(20)->withToken($token)->get($url, $params);
        } catch (\Throwable $e) {
            $this->error('Meta ist nicht erreichbar: ' . $e->getMessage());

            return null;
        }

        $json = $resp->json() ?? [];
        if ($resp->failed() || isset($json['error'])) {
            $code = $json['error']['code'] ?? 0;
            $msg = $json['error']['message'] ?? ('HTTP ' . $resp->status());
            $this->error('Meta meldet einen Fehler: ' . $msg);
            $this->line(match (true) {
                $code === 190 => 'Das Token ist ungueltig oder abgelaufen -> im Business Manager neu generieren (Anleitung Schritt 2, "Laeuft nie ab" waehlen).',
                $code === 200 || str_contains($msg, 'permission') => 'Dem Token fehlen Berechtigungen -> beim Generieren ALLE Berechtigungen aus der Anleitung (Schritt 2) anhaken.',
                $code === 100 => 'ID nicht gefunden -> Zuweisung der Seite/des Instagram-Kontos im Business Manager pruefen.',
                default => 'Die vollstaendige Meldung steht oben - bei Unklarheit den Text (OHNE Token!) in den Chat kopieren.',
            });

            return null;
        }

        return $json;
    }
}
