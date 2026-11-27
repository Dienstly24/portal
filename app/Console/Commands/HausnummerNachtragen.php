<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Document;
use App\Support\Anschrift;
use Illuminate\Console\Command;

/**
 * Traegt fehlende Hausnummern im Bestand nach (Meldung 27.11.2026: "Koetzer
 * Weg" in der Akte, Feld "Nr." leer, obwohl das Dokument "Koetzer Weg 12"
 * nennt).
 *
 * Betroffen sind Kunden mit Strasse, aber OHNE Hausnummer. Die Nummer wird
 * NUR aus Quellen genommen, die dieselbe Anschrift belegen - geraten wird nie:
 *   1. sie steht am Ende der Strasse ("Koetzer Weg 12" im Strassenfeld),
 *   2. sie steht in der alten Sammelspalte `address` bei DERSELBEN Strasse,
 *   3. ein Dokument DIESER Akte nennt dieselbe Strasse (und, falls beide
 *      bekannt, dieselbe PLZ) mit genau EINER Hausnummer. Nennen die
 *      Dokumente verschiedene Nummern, wird nichts geaendert und der Fall
 *      gemeldet.
 *
 * Es wird NUR ergaenzt (leeres Feld), nie ueberschrieben. Ohne --ausfuehren
 * ein Probelauf. Ausgabe mit Kundennummern, nie Namen.
 */
class HausnummerNachtragen extends Command
{
    protected $signature = 'kunden:hausnummer-nachtragen
        {--ausfuehren : Aenderungen wirklich speichern (sonst Probelauf)}';

    protected $description = 'Traegt fehlende Hausnummern aus Strasse, Alt-Adresse oder Dokumenten nach (nur ergaenzen)';

    public function handle(): int
    {
        $schreiben = (bool) $this->option('ausfuehren');
        $zaehler = ['strasse' => 0, 'alt_adresse' => 0, 'dokument' => 0, 'mehrdeutig' => 0, 'ohne_quelle' => 0];
        $zeilen = [];

        Customer::query()
            ->whereNotNull('address_street')->where('address_street', '!=', '')
            ->where(fn ($q) => $q->whereNull('address_house_number')->orWhere('address_house_number', ''))
            ->chunkById(200, function ($kunden) use ($schreiben, &$zaehler, &$zeilen) {
                foreach ($kunden as $kunde) {
                    [$quelle, $strasse, $nummer, $hinweis] = $this->ermittle($kunde);
                    $zaehler[$quelle]++;
                    if ($nummer === null) {
                        if ($quelle === 'mehrdeutig') {
                            $zeilen[] = [(string) $kunde->customer_number, $quelle, (string) $kunde->address_street, $hinweis];
                        }

                        continue;
                    }
                    $zeilen[] = [(string) $kunde->customer_number, $quelle, $strasse, $nummer];
                    if ($schreiben) {
                        $kunde->forceFill(['address_street' => $strasse, 'address_house_number' => $nummer])->save();
                        ActivityLog::record('customer_house_number_completed', 'customer', (string) $kunde->id, [
                            'quelle' => $quelle,
                        ]);
                    }
                }
            });

        if ($zeilen !== []) {
            $this->table(['Kundennummer', 'Quelle', 'Strasse', 'Hausnummer / Hinweis'], $zeilen);
        }
        $this->line('Aus dem Strassenfeld getrennt: '.$zaehler['strasse']);
        $this->line('Aus der alten Sammel-Adresse: '.$zaehler['alt_adresse']);
        $this->line('Aus einem Dokument der Akte: '.$zaehler['dokument']);
        $this->line('Mehrdeutig (Dokumente nennen verschiedene Nummern, bitte in der Akte klaeren): '.$zaehler['mehrdeutig']);
        $this->line('Ohne belegbare Quelle (unveraendert): '.$zaehler['ohne_quelle']);
        $this->line($schreiben ? 'Gespeichert.' : 'Probelauf - nichts gespeichert. Mit --ausfuehren speichern.');

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string, 2: ?string, 3: string} [Quelle, Strasse, Nummer, Hinweis] */
    private function ermittle(Customer $kunde): array
    {
        $strasse = trim((string) $kunde->address_street);

        [$s, $n] = Anschrift::trenne($strasse, null);
        if (is_string($n) && $n !== '') {
            return ['strasse', (string) $s, $n, ''];
        }

        $alt = trim(explode(',', (string) $kunde->address)[0]);
        [$as, $an] = Anschrift::trenne($alt, null);
        if (is_string($an) && $an !== '' && Anschrift::schluessel((string) $as) === Anschrift::schluessel($strasse)) {
            return ['alt_adresse', $strasse, $an, ''];
        }

        $nummern = [];
        Document::query()->where('customer_id', $kunde->id)->whereNotNull('ai_extracted')
            ->select(['id', 'customer_id', 'ai_extracted'])
            ->each(function (Document $doc) use ($kunde, $strasse, &$nummern) {
                $daten = $doc->getAttribute('ai_extracted');
                $p = is_array($daten) && is_array($daten['person'] ?? null) ? $daten['person'] : [];
                [$ds, $dn] = Anschrift::trenne($p['street'] ?? null, $p['house_number'] ?? null);
                if (! is_string($ds) || ! is_scalar($dn) || trim((string) $dn) === '') {
                    return;
                }
                if (Anschrift::schluessel($ds) !== Anschrift::schluessel($strasse)) {
                    return;
                }
                $zip = trim((string) ($p['zip'] ?? ''));
                if ($zip !== '' && filled($kunde->address_zip) && $zip !== trim((string) $kunde->address_zip)) {
                    return;
                }
                $nummern[mb_strtolower((string) preg_replace('/\s+/', '', (string) $dn))] = trim((string) $dn);
            });

        if (count($nummern) === 1) {
            return ['dokument', $strasse, array_values($nummern)[0], ''];
        }
        if (count($nummern) > 1) {
            return ['mehrdeutig', $strasse, null, implode(' / ', $nummern)];
        }

        return ['ohne_quelle', $strasse, null, ''];
    }
}
