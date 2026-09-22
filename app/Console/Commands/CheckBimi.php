<?php

namespace App\Console\Commands;

use App\Support\BimiLogo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Selbstdiagnose der Markenlogo-Anzeige in Gmail (BIMI), Auftrag 22.09.2026.
 *
 * WARUM ES DIESEN BEFEHL GIBT - dieselbe Lehre wie bei `ki:pruefen`: BIMI ist
 * eine KETTE aus fuenf Gliedern (SPF/DKIM -> DMARC auf Durchsetzung ->
 * erreichbares SVG -> Zertifikat -> DNS-Eintrag). Reisst irgendeines davon,
 * sieht der Betreiber IMMER dasselbe: in Gmail steht weiterhin das "D". Es
 * gibt keine Fehlermeldung, keinen Bounce, keine Logzeile - und jede der
 * Ursachen hat eine andere Loesung. Der Befehl prueft die Kette in der
 * Reihenfolge, in der sie durchlaufen wird, und nennt zu jedem Fund den
 * naechsten Schritt.
 *
 * Der Befehl ist STRENG LESEND. Er aendert keinen DNS-Eintrag, keine
 * Mail-Einstellung und keine Datei - eine falsche Aenderung an SPF/DKIM/DMARC
 * kostet die Zustellbarkeit, und die ist deutlich mehr wert als ein Logo.
 *
 * Er braucht Netzzugang (DNS + HTTPS) und gehoert deshalb auf den Server,
 * nicht in die Arbeitsumgebung.
 */
class CheckBimi extends Command
{
    protected $signature = 'bimi:pruefen
        {--domain= : Zu pruefende Domain (Standard: Domain der Absenderadresse aus mail.from)}
        {--selector= : DKIM-Selector (Standard: hostingermail1, der Selector von Hostinger)}';

    protected $description = 'Markenlogo in Gmail (BIMI) pruefen: SPF, DKIM, DMARC, Logo-Datei, Zertifikat, DNS-Eintrag';

    /** @var list<string> Punkte, die die Anzeige des Logos verhindern. */
    private array $blocker = [];

    /** @var list<string> Punkte, die sie nicht verhindern, aber schwaechen. */
    private array $hinweise = [];

    public function handle(): int
    {
        $domain = $this->option('domain') ?: $this->domainAusAbsender();
        $selector = $this->option('selector') ?: 'hostingermail1';

        $this->line('');
        $this->line('=== BIMI / Markenlogo in Gmail: Selbstdiagnose ===');
        $this->line('Domain: '.$domain.'   Zeitpunkt: '.now()->format('d.m.Y H:i'));
        $this->line('Dieser Befehl aendert NICHTS. Er liest nur.');

        $this->pruefeAbsender($domain);
        $this->pruefeSpf($domain);
        $this->pruefeDkim($domain, $selector);
        $dmarcStreng = $this->pruefeDmarc($domain);
        $logoUrl = $this->pruefeBimiEintrag($domain);
        $this->pruefeLogoDatei();
        $this->pruefeLogoUrl($logoUrl, $domain);

        return $this->fazit($dmarcStreng);
    }

    private function domainAusAbsender(): string
    {
        $adresse = (string) config('mail.from.address');
        $teile = explode('@', $adresse);

        return count($teile) === 2 && $teile[1] !== '' ? $teile[1] : 'dienstly24.de';
    }

    /**
     * ALIGNMENT: BIMI sieht die Domain im sichtbaren Von-Feld an. Versendet
     * die Anwendung unter einer anderen Domain als der, die den BIMI-Eintrag
     * traegt, kann alles andere stimmen und das Logo erscheint trotzdem nie.
     */
    private function pruefeAbsender(string $domain): void
    {
        $this->abschnitt('Absender der Anwendung');
        $adresse = (string) config('mail.from.address');
        $mailer = (string) config('mail.default');
        $this->line('  Versandweg (mail.default): '.$mailer);
        $this->line('  Absenderadresse:           '.($adresse !== '' ? $adresse : '(nicht gesetzt)'));

        if ($mailer === 'log' || $mailer === 'array') {
            $this->hinweise[] = 'Der Versandweg steht auf "'.$mailer.'" - hier geht keine echte Mail raus (in Produktion pruefen).';
        }

        $absenderDomain = $this->domainAusAbsender();
        if (strcasecmp($absenderDomain, $domain) !== 0) {
            $this->blocker[] = 'Die Anwendung versendet unter "'.$absenderDomain.'", geprueft wird "'.$domain.'". BIMI gilt fuer die Domain im Von-Feld - beide muessen dieselbe sein.';
        }
    }

    private function pruefeSpf(string $domain): void
    {
        $this->abschnitt('SPF');
        $eintraege = array_values(array_filter($this->txt($domain), fn ($t) => str_starts_with(strtolower($t), 'v=spf1')));

        if ($eintraege === []) {
            $this->blocker[] = 'Kein SPF-Eintrag auf '.$domain.' gefunden.';
            $this->zeile(false, 'kein SPF-Eintrag gefunden');

            return;
        }

        if (count($eintraege) > 1) {
            $this->blocker[] = 'Es gibt '.count($eintraege).' SPF-Eintraege auf '.$domain.' - erlaubt ist genau EINER, sonst schlaegt die Pruefung fehl.';
        }

        foreach ($eintraege as $eintrag) {
            $this->zeile(true, $eintrag);
        }
    }

    private function pruefeDkim(string $domain, string $selector): void
    {
        $this->abschnitt('DKIM (Selector '.$selector.')');
        $name = $selector.'._domainkey.'.$domain;
        $eintraege = array_values(array_filter($this->txt($name), fn ($t) => str_contains(strtolower($t), 'k=rsa') || str_contains(strtolower($t), 'v=dkim1') || str_contains($t, 'p=')));

        if ($eintraege === []) {
            $this->hinweise[] = 'Unter '.$name.' steht kein DKIM-Schluessel. Entweder heisst der Selector anders (--selector) oder DKIM ist nicht eingerichtet.';
            $this->zeile(false, 'nichts unter '.$name);

            return;
        }

        foreach ($eintraege as $eintrag) {
            // Der oeffentliche Schluessel wird NICHT ausgegeben - er ist lang
            // und sagt im Klartext nichts aus. Nur: ist er da und nicht leer?
            if (preg_match('/p=\s*;?\s*$/', $eintrag) || preg_match('/p=\s*;/', $eintrag)) {
                $this->blocker[] = 'Der DKIM-Eintrag '.$name.' hat einen LEEREN Schluessel (p=) - damit signiert nichts.';
                $this->zeile(false, 'Schluessel ist leer (p=)');

                continue;
            }
            $this->zeile(true, 'Schluessel vorhanden ('.strlen($eintrag).' Zeichen)');
        }
    }

    /** @return bool true, wenn die Richtlinie fuer BIMI ausreicht. */
    private function pruefeDmarc(string $domain): bool
    {
        $this->abschnitt('DMARC');
        $eintraege = array_values(array_filter($this->txt('_dmarc.'.$domain), fn ($t) => str_starts_with(strtolower($t), 'v=dmarc1')));

        if ($eintraege === []) {
            $this->blocker[] = 'Kein DMARC-Eintrag auf _dmarc.'.$domain.' gefunden. Ohne DMARC gibt es kein BIMI.';
            $this->zeile(false, 'kein DMARC-Eintrag gefunden');

            return false;
        }

        $eintrag = $eintraege[0];
        $this->zeile(true, $eintrag);

        if (count($eintraege) > 1) {
            $this->blocker[] = 'Es gibt mehrere DMARC-Eintraege - erlaubt ist genau einer.';
        }

        $werte = [];
        foreach (explode(';', $eintrag) as $teil) {
            $teil = trim($teil);
            if ($teil !== '' && str_contains($teil, '=')) {
                [$k, $v] = explode('=', $teil, 2);
                $werte[strtolower(trim($k))] = strtolower(trim($v));
            }
        }

        $p = $werte['p'] ?? 'none';
        $sp = $werte['sp'] ?? $p;
        $pct = isset($werte['pct']) ? (int) $werte['pct'] : 100;
        $streng = in_array($p, ['quarantine', 'reject'], true) && $pct === 100;

        $this->line('  Richtlinie p='.$p.', sp='.$sp.', pct='.$pct);

        if (! $streng) {
            $this->blocker[] = 'BIMI verlangt eine DURCHGESETZTE Richtlinie: p=quarantine oder p=reject, dazu pct=100. Aktuell: p='.$p.', pct='.$pct.'. Diese Umstellung NIE ohne vorherige Auswertung der DMARC-Berichte machen - sie kann echte Mails in den Spam schieben.';
        }

        if ($streng && ! in_array($sp, ['quarantine', 'reject'], true)) {
            $this->hinweise[] = 'Die Unterdomain-Richtlinie sp='.$sp.' ist schwaecher als p - mehrere Anbieter verlangen auch auf Unterdomains Durchsetzung.';
        }

        if (! isset($werte['rua'])) {
            $this->hinweise[] = 'Es ist keine Berichtsadresse (rua=) gesetzt. Ohne Berichte laesst sich die Umstellung auf p=quarantine nicht verantworten - man saehe nicht, was dabei verloren geht.';
        }

        return $streng;
    }

    /** @return string|null die im Eintrag genannte Logo-Adresse */
    private function pruefeBimiEintrag(string $domain): ?string
    {
        $this->abschnitt('BIMI-Eintrag');
        $name = 'default._bimi.'.$domain;
        $eintraege = array_values(array_filter($this->txt($name), fn ($t) => str_starts_with(strtolower($t), 'v=bimi1')));

        if ($eintraege === []) {
            $this->blocker[] = 'Kein BIMI-Eintrag unter '.$name.'. Er ist der letzte Schritt - erst nach DMARC-Durchsetzung und Zertifikat setzen.';
            $this->zeile(false, 'nichts unter '.$name);

            return null;
        }

        if (count($eintraege) > 1) {
            $this->blocker[] = 'Es gibt mehrere BIMI-Eintraege unter '.$name.' - erlaubt ist genau einer.';
        }

        $eintrag = $eintraege[0];
        $this->zeile(true, $eintrag);

        $l = null;
        $a = null;
        foreach (explode(';', $eintrag) as $teil) {
            $teil = trim($teil);
            if (preg_match('/^l\s*=\s*(.*)$/i', $teil, $m)) {
                $l = trim($m[1]);
            }
            if (preg_match('/^a\s*=\s*(.*)$/i', $teil, $m)) {
                $a = trim($m[1]);
            }
        }

        if (! $l) {
            $this->blocker[] = 'Im BIMI-Eintrag fehlt die Logo-Adresse (l=).';
        } elseif (! str_starts_with(strtolower($l), 'https://')) {
            $this->blocker[] = 'Die Logo-Adresse muss mit https:// beginnen.';
        }

        if (! $a) {
            $this->blocker[] = 'Im BIMI-Eintrag fehlt das Zertifikat (a=). Gmail zeigt das Logo NUR mit einem VMC oder CMC - ohne a= bleibt es beim Buchstaben.';
        } else {
            $this->pruefeZertifikat($a);
        }

        return $l;
    }

    private function pruefeZertifikat(string $url): void
    {
        $antwort = $this->hole($url);
        if (! $antwort) {
            $this->blocker[] = 'Die Zertifikatsdatei '.$url.' ist nicht erreichbar.';

            return;
        }

        [$status, $typ, $inhalt] = $antwort;
        if ($status !== 200) {
            $this->blocker[] = 'Die Zertifikatsdatei antwortet mit HTTP '.$status.'.';

            return;
        }
        if (! str_contains($inhalt, '-----BEGIN CERTIFICATE-----')) {
            $this->blocker[] = 'Unter '.$url.' liegt keine PEM-Zertifikatskette.';

            return;
        }
        $this->zeile(true, 'Zertifikat erreichbar ('.substr_count($inhalt, '-----BEGIN CERTIFICATE-----').' Zertifikat(e), Content-Type '.$typ.')');
    }

    private function pruefeLogoDatei(): void
    {
        $this->abschnitt('Logo-Datei auf diesem Server');
        $ergebnis = BimiLogo::pruefeDatei();
        $this->line('  '.BimiLogo::pfad().' ('.number_format($ergebnis['groesse'] / 1024, 1, ',', '.').' KB)');

        foreach ($ergebnis['fehler'] as $fehler) {
            $this->zeile(false, $fehler);
            $this->blocker[] = 'Logo-Datei: '.$fehler;
        }
        foreach ($ergebnis['hinweise'] as $hinweis) {
            $this->hinweise[] = 'Logo-Datei: '.$hinweis;
        }
        if ($ergebnis['ok']) {
            $this->zeile(true, 'entspricht SVG Tiny PS');
        }
    }

    private function pruefeLogoUrl(?string $url, string $domain): void
    {
        $this->abschnitt('Logo ueber HTTPS');
        $url ??= 'https://'.config('website.canonical_host', $domain).'/dienstly-bimi-logo.svg';
        $this->line('  '.$url);

        $antwort = $this->hole($url);
        if (! $antwort) {
            $this->blocker[] = 'Das Logo ist unter '.$url.' nicht erreichbar.';
            $this->zeile(false, 'nicht erreichbar');

            return;
        }

        [$status, $typ, $inhalt] = $antwort;
        if ($status !== 200) {
            $this->blocker[] = 'Das Logo antwortet mit HTTP '.$status.' - es muss oeffentlich und ohne Anmeldung abrufbar sein.';
            $this->zeile(false, 'HTTP '.$status);

            return;
        }

        $this->zeile(true, 'HTTP 200, Content-Type: '.($typ ?: '(fehlt)'));

        if (! str_contains(strtolower($typ), 'image/svg+xml')) {
            $this->blocker[] = 'Der Server liefert den Content-Type "'.$typ.'" statt image/svg+xml.';
        }

        $ergebnis = BimiLogo::pruefe($inhalt);
        foreach ($ergebnis['fehler'] as $fehler) {
            $this->blocker[] = 'Ausgeliefertes Logo: '.$fehler;
        }

        $lokal = is_file(BimiLogo::pfad()) ? (string) file_get_contents(BimiLogo::pfad()) : '';
        if ($lokal !== '' && $lokal !== $inhalt) {
            $this->hinweise[] = 'Die ausgelieferte Datei weicht von der Datei auf diesem Server ab. Ein Zertifikat gilt fuer GENAU eine Fassung - hier ist zu klaeren, welche die richtige ist.';
        }
    }

    /** @return array{0:int,1:string,2:string}|null */
    private function hole(string $url): ?array
    {
        try {
            $antwort = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->get($url);

            return [$antwort->status(), (string) $antwort->header('Content-Type'), $antwort->body()];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function txt(string $name): array
    {
        try {
            $eintraege = @dns_get_record($name, DNS_TXT);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($eintraege)) {
            return [];
        }

        // Lange Eintraege kommen in Haeppchen zu 255 Zeichen - "entries"
        // zusammenzusetzen ist der Unterschied zwischen einem gelesenen und
        // einem abgeschnittenen Eintrag.
        return array_values(array_filter(array_map(
            fn ($e) => isset($e['entries']) && is_array($e['entries']) ? implode('', $e['entries']) : (string) ($e['txt'] ?? ''),
            $eintraege
        )));
    }

    private function abschnitt(string $titel): void
    {
        $this->line('');
        $this->line('--- '.$titel.' ---');
    }

    private function zeile(bool $ok, string $text): void
    {
        $this->line('  '.($ok ? '[ok]  ' : '[!]   ').$text);
    }

    private function fazit(bool $dmarcStreng): int
    {
        $this->line('');
        $this->line('=== Ergebnis ===');

        foreach ($this->blocker as $punkt) {
            $this->error('  BLOCKIERT: '.$punkt);
        }
        foreach ($this->hinweise as $punkt) {
            $this->warn('  Hinweis:   '.$punkt);
        }

        if ($this->blocker === []) {
            $this->info('  Die Kette ist vollstaendig. Gmail braucht nach der ersten Mail noch bis zu 48 Stunden,');
            $this->info('  und es entscheidet zusaetzlich nach dem Ruf des Absenders - ein gueltiger Eintrag ist');
            $this->info('  die Voraussetzung, keine Zusage.');

            return $this->hinweise === [] ? self::SUCCESS : self::SUCCESS;
        }

        $this->line('');
        if (! $dmarcStreng) {
            $this->line('  Naechster Schritt: DMARC-Berichte auswerten, dann schrittweise auf p=quarantine.');
            $this->line('  NICHT einfach umstellen - erst muss belegt sein, dass jeder Versandweg besteht.');
        }

        return self::FAILURE;
    }
}
