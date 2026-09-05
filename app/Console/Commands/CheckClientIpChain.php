<?php

namespace App\Console\Commands;

use App\Support\TrustedProxies;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Beantwortet die EINE Frage, die SEC-2 auf Netzwerkseite offen laesst:
 * sieht die Anwendung die ECHTE Client-IP - oder die Adresse des Vorschalt-
 * Dienstes (CDN/Loadbalancer)?
 *
 * Warum das nicht aus dem Repository zu beantworten ist: was in
 * $request->ip() steht, haengt von REMOTE_ADDR (also der Proxy-Kette auf
 * dem Server) und der Liste in config/trustedproxy.php ab. Die Kette
 * steht nicht im Code. Die WIRKUNG steht aber laengst in der Datenbank:
 * jeder ActivityLog-Eintrag und jeder DSGVO-Einwilligungsnachweis traegt
 * die IP, die die Anwendung tatsaechlich gesehen hat.
 *
 * Deshalb liest dieser Befehl (rein lesend, ohne Netzzugriff) genau diese
 * Spalten aus und macht daraus eine Aussage:
 *
 *  - Verteilen sich viele Nutzer auf viele Adressen  -> Kette ist korrekt.
 *  - Fallen viele Nutzer auf EINE Adresse zusammen   -> die Anwendung
 *    sieht den Vorschalt-Dienst. Folge: EIN gemeinsamer Rate-Limit-Eimer
 *    fuer alle Besucher (die Bremsen fuer Login/Reset/Registrierung
 *    wirken faktisch nicht mehr) UND eine falsche IP im
 *    Einwilligungsnachweis, also ausgerechnet dort, wo sie etwas belegen
 *    soll. Behebung: diese Adresse in TRUSTED_PROXIES eintragen.
 *
 * Der Befehl RAET nie: ohne genuegend Daten sagt er das und beendet sich
 * mit "unklar", statt eine Zahl zu erfinden.
 */
class CheckClientIpChain extends Command
{
    protected $signature = 'netz:client-ip-pruefen {--tage=30 : Betrachteter Zeitraum} {--top=10 : Wie viele Adressen auflisten}';

    protected $description = 'Prueft anhand der aufgezeichneten IPs, ob die Anwendung die echte Client-IP sieht (Audit SEC-2).';

    /** Ab so vielen verschiedenen Nutzern auf EINER Adresse ist es kein Zufall. */
    private const VERDACHT_AB_NUTZERN = 3;

    /** Anteil, ab dem eine einzelne Adresse den Bestand dominiert. */
    private const VERDACHT_AB_ANTEIL = 0.8;

    public function handle(): int
    {
        $tage = max(1, (int) $this->option('tage'));
        $top = max(1, min(50, (int) $this->option('top')));
        $seit = now()->subDays($tage);

        $this->line('Zeitraum: die letzten '.$tage.' Tage');
        $this->newLine();

        $this->zeigeVertrauensliste();

        $befund = $this->pruefeAktivitaetsprotokoll($seit, $top);
        $this->pruefeEinwilligungen($seit);

        $this->newLine();

        return match ($befund) {
            'proxy' => $this->melde(
                'Die Anwendung sieht NICHT die echte Client-IP.',
                [
                    'Die oben genannte Adresse ist der Vorschalt-Dienst (CDN/Loadbalancer).',
                    'Sie steht nicht in der Vertrauensliste, deshalb wird ihr',
                    'X-Forwarded-For-Header ignoriert.',
                    '',
                    'Behebung in der Server-.env unter /var/www/dienstly24/portal:',
                    '  TRUSTED_PROXIES=<diese Adresse>',
                    'Danach: php artisan config:clear',
                    '',
                    'Sicherheitshinweis: nur Adressen eintragen, die wirklich der',
                    'eigene Vorschalt-Dienst sind. Wer hier eintraegt, darf seine',
                    'Client-IP frei behaupten.',
                ],
                1
            ),
            'ok' => $this->melde(
                'Die Anwendung sieht die echte Client-IP.',
                ['Die Adressen verteilen sich wie erwartet - kein Handlungsbedarf.'],
                0
            ),
            default => $this->melde(
                'Unklar - zu wenig Daten fuer eine Aussage.',
                [
                    'Der Befund braucht Verkehr im betrachteten Zeitraum.',
                    'Erneut mit groesserem Zeitraum versuchen, z.B.:',
                    '  php artisan netz:client-ip-pruefen --tage=90',
                ],
                0
            ),
        };
    }

    private function zeigeVertrauensliste(): void
    {
        $liste = TrustedProxies::resolve();

        if ($liste === '*') {
            $this->warn('Vertrauensliste: * (JEDER Proxy) - das ist der Zustand, den SEC-2 abgeschafft hat.');
            $this->newLine();

            return;
        }

        $this->line('Vertrauensliste ('.count($liste).' Eintraege, Quelle: '
            .(trim((string) config('trustedproxy.proxies', '')) !== '' ? 'TRUSTED_PROXIES aus der .env' : 'Standardliste im Code').'):');
        $this->line('  '.implode(', ', array_slice($liste, 0, 6)).(count($liste) > 6 ? ' ...' : ''));
        $this->newLine();
    }

    /** @return 'ok'|'proxy'|'unklar' */
    private function pruefeAktivitaetsprotokoll(\DateTimeInterface $seit, int $top): string
    {
        if (! Schema::hasTable('activity_logs')) {
            $this->warn('Tabelle activity_logs fehlt - uebersprungen.');

            return 'unklar';
        }

        $zeilen = DB::table('activity_logs')
            ->select('ip', DB::raw('COUNT(*) as treffer'), DB::raw('COUNT(DISTINCT user_id) as nutzer'))
            ->whereNotNull('ip')
            ->where('created_at', '>=', $seit)
            ->groupBy('ip')
            ->orderByDesc('treffer')
            ->limit($top)
            ->get();

        $gesamt = (int) DB::table('activity_logs')
            ->whereNotNull('ip')
            ->where('created_at', '>=', $seit)
            ->count();

        $this->info('Aufgezeichnete IPs im Aktivitaetsprotokoll');

        if ($gesamt === 0) {
            $this->line('  keine Eintraege im Zeitraum.');
            $this->newLine();

            return 'unklar';
        }

        $this->table(
            ['IP (wie die App sie sah)', 'Eintraege', 'Anteil', 'versch. Nutzer', 'in Vertrauensliste?'],
            $zeilen->map(fn ($z) => [
                (string) $z->ip,
                (string) $z->treffer,
                round(100 * $z->treffer / $gesamt).' %',
                (string) $z->nutzer,
                $this->istVertraut((string) $z->ip) ? 'ja' : 'nein',
            ])->all()
        );

        $spitze = $zeilen->first();

        if ($spitze === null) {
            return 'unklar';
        }

        $anteil = $spitze->treffer / $gesamt;
        $vieleNutzer = (int) $spitze->nutzer >= self::VERDACHT_AB_NUTZERN;

        // Loopback ist der nginx auf derselben Maschine und steht in der
        // Liste - dass er dominiert, ist normal und kein Befund.
        $istLoopback = in_array((string) $spitze->ip, ['127.0.0.1', '::1'], true);

        if ($anteil >= self::VERDACHT_AB_ANTEIL && $vieleNutzer && ! $istLoopback && ! $this->istVertraut((string) $spitze->ip)) {
            return 'proxy';
        }

        if ($istLoopback && $vieleNutzer) {
            $this->warn('Hinweis: '.$spitze->ip.' ist der lokale Webserver. Die Adresse steht zwar in der');
            $this->warn('Vertrauensliste, sie kommt hier aber trotzdem an - der Webserver setzt also');
            $this->warn('offenbar keinen X-Forwarded-For-Header. Zu pruefen ist die vhost-Konfiguration.');

            return 'proxy';
        }

        return $zeilen->count() > 1 ? 'ok' : 'unklar';
    }

    private function pruefeEinwilligungen(\DateTimeInterface $seit): void
    {
        if (! Schema::hasTable('customer_consents')) {
            return;
        }

        $gesamt = (int) DB::table('customer_consents')
            ->whereNotNull('ip_address')
            ->where('created_at', '>=', $seit)
            ->count();

        if ($gesamt === 0) {
            return;
        }

        $verschieden = (int) DB::table('customer_consents')
            ->whereNotNull('ip_address')
            ->where('created_at', '>=', $seit)
            ->distinct()
            ->count('ip_address');

        $this->info('DSGVO-Einwilligungsnachweise: '.$gesamt.' Nachweise, '.$verschieden.' verschiedene IPs');

        if ($verschieden === 1 && $gesamt >= self::VERDACHT_AB_NUTZERN) {
            $this->warn('  Alle Nachweise tragen dieselbe IP - als Beweismittel nach Art. 7 DSGVO wertlos.');
        }
    }

    private function istVertraut(string $ip): bool
    {
        $liste = TrustedProxies::resolve();

        if ($liste === '*') {
            return true;
        }

        return IpUtils::checkIp($ip, $liste);
    }

    /** @param  array<int,string>  $zeilen */
    private function melde(string $titel, array $zeilen, int $code): int
    {
        $code === 0 ? $this->info('Ergebnis: '.$titel) : $this->error('Ergebnis: '.$titel);

        foreach ($zeilen as $zeile) {
            $this->line($zeile === '' ? '' : '  '.$zeile);
        }

        return $code;
    }
}
