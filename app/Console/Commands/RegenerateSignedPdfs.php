<?php

namespace App\Console\Commands;

use App\Models\SignatureRequest;
use App\Services\Signature\SignatureDiagnostics;
use App\Services\Signature\SignedPdfRegenerator;
use App\Support\SignatureStatus;
use Illuminate\Console\Command;

/**
 * Erzeugt die unterschriebenen PDF der betroffenen Vorgaenge neu
 * (KI-055/056/062, Betreiber-Meldung 02.10.2026).
 *
 * OHNE --ausfuehren aendert der Befehl NICHTS - er zeigt nur, was er tun
 * wuerde. Welche Vorgaenge betroffen sind, entscheidet dieselbe Diagnose
 * wie `signaturen:diagnose` (Bildvergleich), nicht eine Vermutung.
 *
 *   php artisan signaturen:neu-erzeugen --alle                 Probelauf
 *   php artisan signaturen:neu-erzeugen --alle --ausfuehren    erzeugen
 *   php artisan signaturen:neu-erzeugen <id> --ausfuehren      eine Anfrage
 *   ... --kopie-senden                                         korrigierte Kopie an die Unterzeichner
 */
class RegenerateSignedPdfs extends Command
{
    protected $signature = 'signaturen:neu-erzeugen
        {id? : Kennung einer Signaturanfrage}
        {--alle : Alle Vorgaenge, deren fertiges PDF ein Feld nicht sichtbar zeigt}
        {--ausfuehren : Wirklich erzeugen (ohne: nur anzeigen)}
        {--kopie-senden : Die korrigierte Fassung zusaetzlich an die Unterzeichner schicken}';

    protected $description = 'Signaturen: unterschriebene PDF aus den gespeicherten Daten neu erzeugen (altes PDF bleibt erhalten)';

    public function handle(SignatureDiagnostics $diagnostics, SignedPdfRegenerator $regenerator): int
    {
        $ausfuehren = (bool) $this->option('ausfuehren');
        $kandidaten = [];

        if ($this->argument('id')) {
            $request = SignatureRequest::find((string) $this->argument('id'));
            if ($request === null) {
                $this->error('Keine Signaturanfrage mit dieser Kennung.');

                return self::FAILURE;
            }
            $kandidaten[] = $request;
        } elseif ($this->option('alle')) {
            foreach ($diagnostics->bestand()->get() as $request) {
                if ($request->status === SignatureStatus::COMPLETION_FAILED) {
                    $kandidaten[] = $request;

                    continue;
                }
                if (! $request->isCompleted()) {
                    continue; // offene Vorgaenge bekommen ihr PDF erst beim Abschluss - mit dem neuen Stempler
                }
                $bericht = $diagnostics->diagnose($request, true, false);
                if ($bericht['zusammenfassung']['unsichtbar'] > 0) {
                    $kandidaten[] = $request;
                }
            }
        } else {
            $this->error('Bitte eine Kennung angeben oder --alle verwenden.');

            return self::INVALID;
        }

        $this->line('');
        $this->line('=== Signaturen neu erzeugen '.($ausfuehren ? '' : '(PROBELAUF - es wird nichts geaendert)').' ===');
        $this->line('Kandidaten: '.count($kandidaten));
        if ($kandidaten === []) {
            $this->info('Nichts zu tun.');

            return self::SUCCESS;
        }

        $zeilen = [];
        $fehler = 0;
        foreach ($kandidaten as $request) {
            if (! $ausfuehren) {
                $zeilen[] = [$request->id, $request->status, 'wuerde neu erzeugt', ''];

                continue;
            }
            try {
                $r = $regenerator->neuErzeugen($request, (bool) $this->option('kopie-senden'));
            } catch (\Throwable $e) {
                $r = ['ergebnis' => 'nicht_bestanden', 'befunde' => [mb_substr($e->getMessage(), 0, 160)], 'alt' => null, 'neu' => null];
            }
            if ($r['ergebnis'] === 'nicht_bestanden') {
                $fehler++;
            }
            $zeilen[] = [
                $request->id,
                $request->refresh()->status,
                $r['ergebnis'],
                $r['befunde'] !== [] ? implode(' ', $r['befunde']) : ($r['neu'] ? 'neu '.substr($r['neu'], 0, 16).'…' : ''),
            ];
        }
        $this->table(['Anfrage', 'Status', 'Ergebnis', 'Hinweis'], $zeilen);
        if (! $ausfuehren) {
            $this->line('Zum Ausfuehren: --ausfuehren anhaengen. Das alte PDF bleibt in jedem Fall erhalten.');
        }

        return $fehler === 0 ? self::SUCCESS : self::FAILURE;
    }
}
