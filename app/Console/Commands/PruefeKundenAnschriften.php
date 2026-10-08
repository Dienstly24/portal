<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Support\Anschrift;
use Illuminate\Console\Command;

/**
 * Listet Anschriften, in denen die Hausnummer in der Strasse UND im eigenen
 * Feld steht (KI-069, gemeldet als "Nagelshof 20 51"). STRENG LESEND -
 * es wird nichts geaendert.
 *
 * - widerspruch: zwei VERSCHIEDENE Nummern (20 gegen 51). Welche stimmt,
 *   steht nirgends - das klaert ein Mensch in der Akte.
 * - doppelt: dieselbe Nummer zweimal. Die Anzeige ist seit PR-4 schon
 *   richtig; aufraeumen kann man es in der Akte, noetig ist es nicht.
 *
 * Ausgegeben werden Kundennummer und Strassenzeile, keine Namen.
 */
class PruefeKundenAnschriften extends Command
{
    protected $signature = 'kunden:anschriften-pruefen
        {--alle : Auch die Faelle "doppelt" einzeln auflisten}
        {--csv= : Ergebnis zusaetzlich als CSV-Datei schreiben}';

    protected $description = 'Findet Anschriften mit doppelter oder widerspruechlicher Hausnummer (nur lesend)';

    public function handle(): int
    {
        $zaehler = ['widerspruch' => 0, 'doppelt' => 0];
        $zeilen = [];

        Customer::query()
            ->whereNotNull('address_street')->where('address_street', '!=', '')
            ->whereNotNull('address_house_number')->where('address_house_number', '!=', '')
            ->select(['id', 'customer_number', 'address_street', 'address_house_number', 'address_house_suffix', 'address_zip', 'address_city'])
            ->chunkById(500, function ($kunden) use (&$zaehler, &$zeilen) {
                foreach ($kunden as $k) {
                    $fall = Anschrift::strassenzeile($k->address_street, $k->address_house_number, $k->address_house_suffix)['fall'];
                    if (! isset($zaehler[$fall])) {
                        continue;
                    }
                    $zaehler[$fall]++;
                    $zeilen[] = [
                        $fall,
                        (string) $k->customer_number,
                        (string) $k->address_street,
                        trim($k->address_house_number.' '.$k->address_house_suffix),
                        trim($k->address_zip.' '.$k->address_city),
                    ];
                }
            });

        $kopf = ['Fall', 'Kundennummer', 'Strasse (wie erfasst)', 'Hausnummer-Feld', 'PLZ Ort'];
        $anzeigen = $this->option('alle') ? $zeilen : array_values(array_filter($zeilen, fn ($z) => $z[0] === 'widerspruch'));
        if ($anzeigen !== []) {
            $this->table($kopf, $anzeigen);
        }

        $this->line("Widerspruch (zwei verschiedene Nummern, bitte in der Akte klaeren): {$zaehler['widerspruch']}");
        $this->line("Doppelt (dieselbe Nummer zweimal, Anzeige bereits korrekt): {$zaehler['doppelt']}");

        if ($pfad = $this->option('csv')) {
            $fh = fopen((string) $pfad, 'w');
            if ($fh === false) {
                $this->error('CSV-Datei kann nicht geschrieben werden: '.$pfad);

                return self::FAILURE;
            }
            fputcsv($fh, $kopf, ';');
            foreach ($zeilen as $z) {
                fputcsv($fh, $z, ';');
            }
            fclose($fh);
            $this->info('CSV geschrieben: '.$pfad);
        }

        return self::SUCCESS;
    }
}
