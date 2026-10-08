<?php

namespace App\Console\Commands;

use App\Services\Family\AbhaengigesKindService;
use App\Support\FamilienAlter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Bestandsaufnahme KI-095 - STRENG LESEND, aendert nichts.
 *
 * Listet jedes Kind unter dem Selbststaendigkeitsalter, das eine eigene
 * Kundennummer traegt, mit allem, was an seiner Akte haengt, und dem
 * Vorschlag fuer die Umstellung. Vertraege, Provisionen und Signaturen
 * stehen ausdruecklich als "BLOCKIERT" da: dort entscheidet der Betreiber,
 * bevor irgendetwas umgestellt wird.
 */
class PruefeKinderMitKundennummer extends Command
{
    protected $signature = 'kunden:kinder-pruefen
        {--nummer= : nur diese Kundennummer}
        {--csv= : Ergebnis zusaetzlich als CSV in diese Datei schreiben}';

    protected $description = 'Kinder unter dem Selbststaendigkeitsalter mit eigener Kundennummer auflisten (nur lesend)';

    public function handle(AbhaengigesKindService $service): int
    {
        $alter = FamilienAlter::selbststaendig();
        $kinder = $service->kinderMitKundennummer();
        if ($nummer = $this->option('nummer')) {
            $kinder = $kinder->where('customer_number', $nummer)->values();
        }

        $this->info('Regel: unter '.$alter.' Jahren keine eigene Kundennummer (Erinnerung ab '.FamilienAlter::erinnerung().').');
        $this->info('Gefunden: '.$kinder->count().' Kind(er) mit eigener Kundennummer. Es wurde NICHTS geaendert.');
        $this->newLine();

        $zeilen = [];
        foreach ($kinder as $kind) {
            $b = $service->befund($kind);
            $eltern = collect($b['bezugspersonen'])
                ->map(fn ($e) => $e['rolle'].': '.($e['customer']->user->name ?? '—').' ('.($e['customer']->customer_number ?? 'ohne Nr.').')')
                ->implode('; ');
            $verknuepft = collect($b['verknuepft'])->map(fn ($n, $t) => $t.'='.$n)->implode(', ');
            $blockiert = collect($b['blockiert'])
                ->map(fn ($n, $t) => (AbhaengigesKindService::BLOCKIERENDE_TABELLEN[$t] ?? $t).': '.$n)->implode(', ');
            $vorschlag = $b['bezugsperson'];

            $this->line('<options=bold>'.$kind->customer_number.' - '.($kind->user->name ?? '—').'</>');
            $this->line('  Geburtsdatum: '.Carbon::parse($kind->birth_date)->format('d.m.Y').' (Alter '.$b['alter'].')'
                .' | angelegt: '.($b['angelegt'] ?? '—').' | Herkunft: '.($b['herkunft'] ?? '—'));
            $this->line('  Eltern laut Familienbeziehung: '.($eltern !== '' ? $eltern : 'KEINE verknuepft'));
            $this->line('  Vorgeschlagene Bezugsperson: '.($vorschlag
                ? ($vorschlag->user->name ?? '—').' ('.$vorschlag->customer_number.')'
                : 'keine eindeutige - bitte mit --elternteil=<Nummer> angeben'));
            $this->line('  Verknuepfte Daten: '.($verknuepft !== '' ? $verknuepft : 'keine'));
            $this->line('  Portal: '.($b['portal']['echte_email'] ? 'echte E-Mail' : 'keine E-Mail')
                .($b['portal']['angemeldet'] ? ', hat sich schon angemeldet' : '')
                .($b['portal']['aktiv'] ? '' : ', deaktiviert'));
            if ($blockiert !== '') {
                $this->line('  <fg=red>BLOCKIERT - Betreiber entscheidet zuerst: '.$blockiert.'</>');
            } else {
                $this->line('  <fg=green>Umstellbar:</> php artisan kunden:kind-umstellen '.$kind->customer_number
                    .($vorschlag ? '' : ' --elternteil=<Nummer>').' (zuerst ohne --ausfuehren)');
            }
            $this->newLine();

            $zeilen[] = [
                $kind->customer_number, $kind->user?->name, $kind->birth_date, $b['alter'], $b['angelegt'], $b['herkunft'],
                $eltern, $vorschlag?->customer_number, $verknuepft, $blockiert, $blockiert === '' ? 'ja' : 'nein',
            ];
        }

        $ohneDatum = $service->kinderOhneGeburtsdatum();
        if ($ohneDatum->isNotEmpty()) {
            $this->warn($ohneDatum->count().' Akte(n) als KIND verknuepft, mit Kundennummer, aber OHNE Geburtsdatum - Alter nicht belegbar, wird nicht umgestellt:');
            foreach ($ohneDatum as $c) {
                $this->line('  '.$c->customer_number.' - '.($c->user->name ?? '—'));
            }
        }

        if ($pfad = $this->option('csv')) {
            $fh = fopen($pfad, 'w');
            fputcsv($fh, ['Kundennummer', 'Name', 'Geburtsdatum', 'Alter', 'Angelegt', 'Herkunft', 'Eltern', 'Vorschlag Bezugsperson', 'Verknuepfte Daten', 'Blockiert', 'Umstellbar'], ';');
            foreach ($zeilen as $z) {
                fputcsv($fh, $z, ';');
            }
            fclose($fh);
            $this->info('CSV geschrieben: '.$pfad);
        }

        return 0;
    }
}
