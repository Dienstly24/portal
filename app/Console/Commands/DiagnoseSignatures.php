<?php

namespace App\Console\Commands;

use App\Models\SignatureRequest;
use App\Models\SystemSetting;
use App\Services\Signature\SignatureDiagnostics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Warum fehlt die Unterschrift im fertigen PDF? (Betreiber-Meldung 02.10.2026)
 *
 * STRENG LESEND - der Befehl schreibt nichts: keine Datenbankzeile, keine
 * Datei, kein Protokoll-Ereignis. Er ist die Vorbedingung fuer jede
 * Reparatur: erst wenn feststeht, WARUM ein Dokument ohne Unterschrift
 * dasteht, wird etwas daran geaendert.
 *
 * Die Ausgabe enthaelt keine Namen, Titel oder E-Mail-Adressen und kann
 * deshalb unveraendert weitergegeben werden.
 *
 *   php artisan signaturen:diagnose <id>          eine Anfrage, ausfuehrlich
 *   php artisan signaturen:diagnose --alle        alle betroffenen, je eine Zeile
 *   php artisan signaturen:diagnose <id> --json   maschinenlesbar
 *   php artisan signaturen:diagnose --zeichen     Zeichen ausserhalb WinAnsi in Namen
 */
class DiagnoseSignatures extends Command
{
    protected $signature = 'signaturen:diagnose
        {id? : Kennung der Signaturanfrage}
        {--alle : Alle Anfragen pruefen, in denen etwas gestempelt werden sollte}
        {--json : Ausgabe als JSON}
        {--ohne-render : Kein Bildvergleich mit pdftoppm (schneller, aber ohne Sichtbarkeitsurteil)}
        {--ohne-log : Logdateien nicht durchsuchen}
        {--zeichen : Namen auf Zeichen pruefen, die die PDF-Schrift (WinAnsi) nicht darstellen kann}';

    protected $description = 'Signaturen: pruefen, warum Unterschriften oder Firmenbilder im fertigen PDF fehlen (nur lesend)';

    /** Kurzbeschreibung je Ursache - fuer Menschen, nicht fuer Maschinen. */
    private const TEXTE = [
        SignatureDiagnostics::KEIN_SIGNIERTES_PDF => 'Alle haben unterschrieben, aber es gibt kein fertiges PDF (Ergebnis wurde zur Pruefung nachgebaut).',
        SignatureDiagnostics::FIRMENBILD_OPAK => 'Bild ist deckend bzw. Stempelfarbe ist hell: der Stempler setzt EINE Farbe -> weisse/einfarbige Flaeche statt Bild.',
        SignatureDiagnostics::BILD_MEHRFARBIG => 'Bild ist mehrfarbig: der Stempler setzt nur eine Farbe (Risiko, keine Ursache fuer "unsichtbar").',
        SignatureDiagnostics::CONTENTS_INDIREKT => '/Contents der Seite ist ein INDIREKTES Array: der Stempler haengt es als Strom an -> poppler "Weird page contents", Stempel faellt weg.',
        SignatureDiagnostics::BILD_FEHLT => 'Bilddatei fehlt, ist unlesbar oder leer.',
        SignatureDiagnostics::FELD_AUSSERHALB => 'Feldkasten liegt (teilweise) ausserhalb der Seite.',
        SignatureDiagnostics::SEITE_FEHLT => 'Feld verweist auf eine Seite, die es im Dokument nicht gibt (wird beim Stempeln uebersprungen).',
        SignatureDiagnostics::SIGNIERT_GLEICH_ORIGINAL => 'Das "unterschriebene" PDF ist Byte fuer Byte das Original.',
        SignatureDiagnostics::ERZEUGUNG_FEHLGESCHLAGEN => 'Im Protokoll steht ein Fehlschlag beim Erzeugen bzw. Unterschreiben.',
        SignatureDiagnostics::OBJEKT_MEHRFACH => 'Seitenobjekt steht mehrfach in der Datei (fortgeschrieben/Objekt-Strom): Gefahr, dass eine ALTE Fassung gestempelt wird.',
        SignatureDiagnostics::GENERATION => 'Seitenobjekt hat Generationsnummer > 0, der Stempler schreibt Generation 0 -> Betrachter kann die Fortschreibung ignorieren.',
        SignatureDiagnostics::VORSPANN => 'Vor "%PDF-" stehen Bytes (Vorspann): Offsets der Fortschreibung koennen verschoben sein.',
        SignatureDiagnostics::RENDER_FEHLER => 'poppler meldet beim signierten PDF einen Fehler (siehe Abschnitt poppler).',
        SignatureDiagnostics::HASH_ABWEICHUNG => 'Gespeicherter SHA-256 passt nicht zur Datei im Speicher.',
        SignatureDiagnostics::UNBEKANNT => 'UNSICHTBAR, aber keine bekannte Ursache passt - weitere Ursache, bitte den JSON-Bericht schicken.',
    ];

    public function handle(SignatureDiagnostics $diagnostics): int
    {
        if ($this->option('zeichen')) {
            return $this->zeichenPruefen();
        }

        $rendern = ! $this->option('ohne-render');
        $logs = ! $this->option('ohne-log');

        if ($this->option('alle')) {
            return $this->bestand($diagnostics, $rendern);
        }

        $id = (string) $this->argument('id');
        if ($id === '') {
            $this->error('Bitte eine Kennung angeben oder --alle bzw. --zeichen verwenden.');

            return self::INVALID;
        }
        $request = SignatureRequest::find($id);
        if ($request === null) {
            $this->error('Keine Signaturanfrage mit dieser Kennung.');

            return self::FAILURE;
        }

        $bericht = $diagnostics->diagnose($request, $rendern, $logs);

        if ($this->option('json')) {
            $this->line((string) json_encode($bericht, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->ausgeben($bericht);
        }

        return $bericht['ursachen'] === [] && $bericht['zusammenfassung']['unsichtbar'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<string, mixed>  $b */
    private function ausgeben(array $b): void
    {
        $this->line('');
        $this->line('=== Signatur-Diagnose (nur lesend) ===');
        $this->line('Anfrage: '.$b['id'].'   Status: '.$b['status'].'   Unterschrieben: '.$b['davon_unterschrieben'].' von '.$b['unterzeichner']);
        $this->line('Erstellt: '.($b['erstellt'] ?? '-').'   Abgeschlossen: '.($b['abgeschlossen'] ?? '-'));

        $this->line('');
        $this->line('--- Dateien');
        foreach ($b['dateien'] as $k => $v) {
            $this->line(sprintf('  %-30s %s', $k, is_bool($v) ? ($v ? 'ja' : 'NEIN') : ($v ?? '-')));
        }
        if ($b['ergebnis_simuliert']) {
            $this->warn('  Fertiges PDF fehlt - fuer die Pruefung im Speicher NACHGEBAUT (nichts gespeichert).');
        }

        if (isset($b['pdf'])) {
            $this->line('');
            $this->line('--- Aufbau des Originals');
            $this->line('  Seiten: '.$b['pdf']['seiten'].'   xref: '.$b['pdf']['xref'].'   Objekt-Stroeme: '.($b['pdf']['objektstroeme'] ? 'ja' : 'nein')
                .'   Fortschreibungen: '.$b['pdf']['fortschreibungen'].'   Vorspann: '.$b['pdf']['vorspann_bytes'].' Bytes');
            foreach ($b['seiten'] as $s) {
                $v = $s['versionen'];
                $this->line(sprintf('  Seite %d: obj %d, /Rotate %d, MediaBox [%s]%s, Anzeige %sx%s pt, /Contents: %s, Fassungen: %d Klartext%s, Generation %s',
                    $s['seite'], $s['objekt'], $s['rotate'], implode(' ', $s['mediabox']),
                    $s['cropbox'] ? ', CropBox ['.implode(' ', $s['cropbox']).']' : '',
                    $s['anzeige'][0], $s['anzeige'][1], $s['contents'], $v['klartext'],
                    $v['im_objektstrom'] ? ' + Objekt-Strom' : '', implode('/', $v['generationen']) ?: '-'));
            }
        }

        $this->line('');
        $this->line('--- Felder (Box = Anteil der Anzeige-Seite: x, y, Breite, Hoehe; Ursprung oben links)');
        foreach ($b['felder'] as $f) {
            $sicht = $f['sichtbarkeit'];
            $urteil = strtoupper($sicht['urteil']).(isset($sicht['geaendert_anteil']) ? ' ('.round($sicht['geaendert_anteil'] * 100, 2).' % des Kastens veraendert)' : '');
            $this->line(sprintf('  [%s] Seite %d, Box [%s] = [%s] pt, gefuellt: %s -> %s',
                $f['art'], $f['seite'], implode(', ', $f['box_anteilig']),
                isset($f['box_punkte']) ? implode(', ', $f['box_punkte']) : '-',
                $f['gefuellt'] ? 'ja' : 'nein', $urteil));
            if (! empty($f['bild'])) {
                $i = $f['bild'];
                $this->line(sprintf('      Bild (%s): %dx%d px, %d Bytes, deckend %.1f %%, halbdeckend %.1f %%, Farben %d, Stempelfarbe %s, sha256 %s…',
                    $f['bild_quelle'] ?? '?', $i['breite'], $i['hoehe'], $i['bytes'], $i['deckend_anteil'] * 100,
                    $i['halbdeckend_anteil'] * 100, $i['farben_deckend'], $i['stempel_farbe'] ?? '-', substr($i['sha256'], 0, 16)));
            }
            foreach (array_merge($f['ursachen'], $f['risiken']) as $u) {
                $this->line('      -> '.$u);
            }
        }

        foreach ($b['poppler'] as $p) {
            if ($p['meldung_signiert'] !== '' || $p['meldung_original'] !== '' || ! $p['verfuegbar']) {
                $this->line('');
                $this->line('--- poppler, Seite '.$p['seite'].($p['verfuegbar'] ? '' : ' (NICHT gerendert)'));
                $this->line('  Original: '.($p['meldung_original'] ?: '-'));
                $this->line('  Signiert: '.($p['meldung_signiert'] ?: '-'));
            }
        }

        if ($b['fehlerereignisse'] !== []) {
            $this->line('');
            $this->line('--- Fehler im Signatur-Protokoll');
            foreach ($b['fehlerereignisse'] as $e) {
                $this->line('  '.$e['zeit'].' '.$e['ereignis'].': '.$e['text']);
            }
        }
        if ($b['log'] !== []) {
            $this->line('');
            $this->line('--- Logzeilen zu dieser Anfrage (letzte 10)');
            foreach ($b['log'] as $l) {
                $this->line('  '.$l);
            }
        }
        foreach ($b['hinweise'] as $h) {
            $this->warn('Hinweis: '.$h);
        }

        $z = $b['zusammenfassung'];
        $this->line('');
        $this->line('=== Ergebnis: '.$z['ausgefuellt'].' von '.$z['felder'].' Feldern ausgefuellt, '.$z['sichtbar'].' sichtbar, '.$z['unsichtbar'].' UNSICHTBAR');
        if ($b['ursachen'] === []) {
            $this->info('Keine Ursache gefunden.');
        }
        foreach ($b['ursachen'] as $u) {
            $this->line('  '.$u.': '.(self::TEXTE[$u] ?? ''));
        }
        $this->line('');
    }

    private function bestand(SignatureDiagnostics $diagnostics, bool $rendern): int
    {
        $zeilen = [];
        $zaehler = [];
        $betroffen = 0;
        $gesamt = 0;

        $diagnostics->bestand()->chunk(50, function ($requests) use ($diagnostics, $rendern, &$zeilen, &$zaehler, &$betroffen, &$gesamt) {
            foreach ($requests as $request) {
                $gesamt++;
                try {
                    $b = $diagnostics->diagnose($request, $rendern, false);
                } catch (\Throwable $e) {
                    $zeilen[] = [$request->id, $request->status, '-', 'DIAGNOSE FEHLGESCHLAGEN: '.mb_substr($e->getMessage(), 0, 120)];
                    $betroffen++;

                    continue;
                }
                $z = $b['zusammenfassung'];
                if ($b['ursachen'] === [] && $z['unsichtbar'] === 0) {
                    continue;
                }
                $betroffen++;
                foreach ($b['ursachen'] as $u) {
                    $zaehler[$u] = ($zaehler[$u] ?? 0) + 1;
                }
                $zeilen[] = [$b['id'], $b['status'], $z['sichtbar'].'/'.$z['ausgefuellt'], implode(', ', $b['ursachen'])];
            }
        });

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'geprueft' => $gesamt, 'betroffen' => $betroffen, 'ursachen' => $zaehler,
                'anfragen' => array_map(fn ($z) => ['id' => $z[0], 'status' => $z[1], 'sichtbar' => $z[2], 'ursachen' => $z[3]], $zeilen),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $betroffen === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->line('');
        $this->line('=== Signatur-Diagnose: Bestand (nur lesend) ===');
        $this->line('Geprueft: '.$gesamt.'   Betroffen: '.$betroffen.($rendern ? '' : '   (ohne Bildvergleich)'));
        if ($zeilen !== []) {
            $this->table(['Anfrage', 'Status', 'sichtbar/gefuellt', 'Ursachen'], $zeilen);
        }
        if ($zaehler !== []) {
            arsort($zaehler);
            $this->line('Haeufigkeit je Ursache:');
            foreach ($zaehler as $u => $n) {
                $this->line(sprintf('  %4d  %s - %s', $n, $u, self::TEXTE[$u] ?? ''));
            }
        }
        $this->line('');

        return $betroffen === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Welche Zeichen in Namen kann die PDF-Standardschrift (Helvetica,
     * WinAnsi) NICHT darstellen? Grundlage fuer die Entscheidung, ob eine
     * Unicode-Schrift eingebettet werden muss (Betreiber 02.10.2026).
     *
     * Ausgegeben werden nur das ZEICHEN, wie oft es vorkommt und in welcher
     * Spalte - nie ein Name.
     */
    private function zeichenPruefen(): int
    {
        $quellen = [
            'users.name' => fn () => DB::table('users')->whereNotNull('name')->pluck('name'),
            'customers.company_name' => fn () => DB::table('customers')->whereNotNull('company_name')->pluck('company_name'),
            'signature_signers.name' => fn () => DB::table('signature_signers')->whereNotNull('name')->pluck('name'),
            'einstellung company_name' => fn () => collect([(string) SystemSetting::get('company_name', '')]),
        ];

        $fund = [];
        $werte = 0;
        $betroffeneWerte = 0;
        foreach ($quellen as $spalte => $holen) {
            foreach ($holen() as $text) {
                $werte++;
                $fremd = self::zeichenAusserhalbWinAnsi((string) $text);
                if ($fremd === []) {
                    continue;
                }
                $betroffeneWerte++;
                foreach ($fremd as $zeichen) {
                    $fund[$zeichen]['anzahl'] = ($fund[$zeichen]['anzahl'] ?? 0) + 1;
                    $fund[$zeichen]['spalten'][$spalte] = true;
                }
            }
        }

        $this->line('');
        $this->line('=== Zeichen ausserhalb WinAnsi (PDF-Standardschrift) ===');
        $this->line('Gepruefte Werte: '.$werte.'   davon mit mindestens einem solchen Zeichen: '.$betroffeneWerte);
        if ($fund === []) {
            $this->info('Keine - die Standardschrift genuegt fuer alle Namen.');

            return self::SUCCESS;
        }
        uasort($fund, fn ($a, $b) => $b['anzahl'] <=> $a['anzahl']);
        $zeilen = [];
        foreach ($fund as $zeichen => $f) {
            $zeilen[] = [$zeichen, sprintf('U+%04X', mb_ord($zeichen)), $f['anzahl'], implode(', ', array_keys($f['spalten']))];
        }
        $this->table(['Zeichen', 'Unicode', 'Vorkommen', 'Spalte'], $zeilen);

        return self::FAILURE;
    }

    /** @return list<string> */
    public static function zeichenAusserhalbWinAnsi(string $text): array
    {
        $fremd = [];
        foreach (mb_str_split($text) as $zeichen) {
            if ($zeichen === '?' || ord($zeichen[0]) < 0x80) {
                continue;
            }
            if (mb_convert_encoding($zeichen, 'Windows-1252', 'UTF-8') === '?') {
                $fremd[$zeichen] = true;
            }
        }

        return array_keys($fremd);
    }
}
