<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Family\AbhaengigesKindService;
use App\Support\FamilienAlter;
use Illuminate\Console\Command;

/**
 * Ein Kind mit eigener Kundennummer zum abhaengigen Familienmitglied
 * umstellen (KI-094). OHNE --ausfuehren ist es ein PROBELAUF: er zeigt
 * Befund und Plan und aendert nichts. Haengen Vertraege, Provisionen oder
 * Signaturen an der Akte, verweigert der Befehl auch mit --ausfuehren -
 * das entscheidet der Betreiber.
 */
class StelleKindAufAbhaengigUm extends Command
{
    protected $signature = 'kunden:kind-umstellen
        {nummer : Kundennummer des Kindes}
        {--elternteil= : Kundennummer des Elternteils (sonst Vater, dann Mutter laut Familienbeziehung)}
        {--ausfuehren : wirklich umstellen (ohne: nur Probelauf)}';

    protected $description = 'Kind unter dem Selbststaendigkeitsalter: Kundennummer archivieren, als abhaengiges Familienmitglied unter den Elternteil stellen (Standard: Probelauf)';

    public function handle(AbhaengigesKindService $service): int
    {
        $kind = Customer::with('user')->where('customer_number', $this->argument('nummer'))->first();
        if ($kind === null) {
            $this->error('Keine Akte mit Kundennummer '.$this->argument('nummer').'.');

            return 1;
        }

        $b = $service->befund($kind);
        $elternteil = $this->option('elternteil')
            ? Customer::with('user')->where('customer_number', $this->option('elternteil'))->first()
            : $b['bezugsperson'];

        $this->line('Kind: '.($kind->user->name ?? '—').' ('.$kind->customer_number.'), Alter '.($b['alter'] ?? 'unbekannt')
            .' - Grenze '.FamilienAlter::selbststaendig().' Jahre');
        $this->line('Elternteil: '.($elternteil ? ($elternteil->user->name ?? '—').' ('.$elternteil->customer_number.')' : 'NICHT bestimmt'));
        $this->line('Verknuepfte Daten (bleiben an der Kinderakte, unter dem Elternteil sichtbar): '
            .(collect($b['verknuepft'])->map(fn ($n, $t) => $t.'='.$n)->implode(', ') ?: 'keine'));
        $this->newLine();
        $this->line('Plan:');
        $this->line('  1. Kundennummer '.$kind->customer_number.' ins Archiv (bleibt belegt, wird nie neu vergeben, Suche findet das Kind weiter).');
        $this->line('  2. Kundennummer an der Akte leeren - die Akte selbst bleibt vollstaendig (nichts geloescht).');
        $this->line('  3. Als abhaengiges Kind von '.($elternteil?->user->name ?? '?').' verknuepfen.');
        $this->line('  4. Portalzugang des Kindes stilllegen (nicht loeschen)'.($b['portal']['echte_email'] ? '' : ' - es gibt keinen nutzbaren').'.');
        $this->line('  5. Vermerk in beiden Akten + ActivityLog.');

        if ($b['blockiert'] !== []) {
            $this->newLine();
            $this->error('BLOCKIERT - bitte zuerst mit dem Betreiber klaeren: '
                .collect($b['blockiert'])->map(fn ($n, $t) => (AbhaengigesKindService::BLOCKIERENDE_TABELLEN[$t] ?? $t).': '.$n)->implode(', '));

            return 1;
        }
        if ($elternteil === null) {
            $this->error('Kein eindeutiger Elternteil - bitte mit --elternteil=<Kundennummer> angeben.');

            return 1;
        }

        if (! $this->option('ausfuehren')) {
            $this->newLine();
            $this->warn('PROBELAUF - nichts geaendert. Zum Ausfuehren: --ausfuehren');

            return 0;
        }

        try {
            $alt = $service->umstellen($kind, $elternteil);
        } catch (\DomainException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info('Umgestellt. Kundennummer '.$alt.' archiviert; '.($kind->user->name ?? 'Kind').' steht jetzt unter '.$elternteil->customer_number.'.');

        return 0;
    }
}
