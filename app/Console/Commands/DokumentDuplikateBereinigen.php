<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Document;
use App\Services\Dokumente\DokumentDublettenService;
use Illuminate\Console\Command;

/**
 * Doppelte Dokumente (gleicher Inhalt in derselben Akte) in den Papierkorb
 * legen - fuer ALLE Kunden oder einen (KI-128).
 *
 * Ohne --ausfuehren ein reiner PROBELAUF: er zaehlt, nennt Kundennummern
 * (nie Namen) und aendert nichts - auch keine fehlenden Hashes.
 * Mit --ausfuehren werden fehlende Hashes nachgetragen und die Kopien in
 * den Papierkorb gelegt (30 Tage wiederherstellbar in der Kundenakte).
 */
class DokumentDuplikateBereinigen extends Command
{
    protected $signature = 'dokumente:duplikate-bereinigen
        {--kunde= : Nur diese Kundennummer}
        {--ausfuehren : Wirklich in den Papierkorb legen (sonst Probelauf)}';

    protected $description = 'Doppelte Dokumente je Kundenakte finden und in den Papierkorb legen (30 Tage wiederherstellbar)';

    public function handle(DokumentDublettenService $service): int
    {
        $ausfuehren = (bool) $this->option('ausfuehren');
        $kunden = Customer::query()->whereIn('id', Document::whereNotNull('customer_id')->select('customer_id'));
        if ($nr = $this->option('kunde')) {
            $kunden->where('customer_number', $nr);
        }

        $summeEntfernt = $summeUebersprungen = $summeFehler = $akten = 0;
        $ohneHash = Document::whereNotNull('customer_id')->whereNull('content_hash')->count();

        $kunden->orderBy('customer_number')->each(function (Customer $k) use ($service, $ausfuehren, &$summeEntfernt, &$summeUebersprungen, &$summeFehler, &$akten) {
            if ($ausfuehren) {
                $r = $service->bereinigen((string) $k->id, null);
                $entfernt = $r['entfernt'];
                $uebersprungen = $r['uebersprungen'];
                $summeFehler += $r['fehler'];
            } else {
                $plan = $service->plan($k);
                $entfernt = count($plan['entfernen']);
                $uebersprungen = count($plan['uebersprungen']);
            }
            if ($entfernt + $uebersprungen === 0) {
                return;
            }
            $akten++;
            $summeEntfernt += $entfernt;
            $summeUebersprungen += $uebersprungen;
            $this->line(sprintf('  %s: %d %s, %d uebersprungen',
                $k->customer_number ?? $k->id, $entfernt, $ausfuehren ? 'entfernt' : 'wuerden entfernt', $uebersprungen));
        });

        $this->newLine();
        $this->info(sprintf('%s: %d Akten, %d Kopien %s, %d uebersprungen (Gruende siehe Kundenakte).',
            $ausfuehren ? 'Ergebnis' : 'PROBELAUF', $akten, $summeEntfernt,
            $ausfuehren ? 'in den Papierkorb gelegt' : 'wuerden in den Papierkorb gelegt', $summeUebersprungen));
        if (! $ausfuehren && $ohneHash > 0) {
            $this->warn("$ohneHash Dokumente haben noch keine Inhalts-Bestimmung - --ausfuehren traegt sie nach, danach koennen es mehr Treffer sein.");
        }
        if (! $ausfuehren) {
            $this->line('Zum Ausfuehren: php artisan dokumente:duplikate-bereinigen --ausfuehren');
        }

        return $summeFehler > 0 ? self::FAILURE : self::SUCCESS;
    }
}
