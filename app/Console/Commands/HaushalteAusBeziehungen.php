<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\Haushalt;
use App\Models\HaushaltMitglied;
use App\Services\Haushalt\HaushaltService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Uebernahme des Bestands (PR-5b): aus den Beziehungen "Gleicher Haushalt"
 * (customer_relationships) entstehen Haushalte.
 *
 * Ohne Schalter ist es ein PROBELAUF - es wird nichts geschrieben.
 * - Paare werden zu Gruppen verbunden (A-B und B-C = ein Haushalt A, B, C).
 * - Eine Gruppe, in der schon jemand zu einem Haushalt gehoert, wird
 *   UEBERSPRUNGEN und gemeldet - zusammenlegen entscheidet ein Mensch.
 * - Es wird KEIN Hauptansprechpartner bestimmt und KEIN Einzugsdatum
 *   erfunden; die Karte in der Akte sagt, dass er fehlt.
 * - Die Beziehungen selbst bleiben unveraendert stehen.
 * --zuruecknehmen entfernt nur Uebernahme-Haushalte, an denen seither
 * niemand etwas geaendert hat; alle anderen bleiben und werden genannt.
 * Ausgegeben werden Kundennummern, keine Namen.
 */
class HaushalteAusBeziehungen extends Command
{
    protected $signature = 'haushalte:aus-beziehungen-bilden
        {--ausfuehren : Haushalte wirklich anlegen (sonst Probelauf)}
        {--zuruecknehmen : Unveraenderte Uebernahme-Haushalte wieder entfernen}';

    protected $description = 'Bildet Haushalte aus den Beziehungen "Gleicher Haushalt" (Standard: Probelauf)';

    public function handle(HaushaltService $haushalte): int
    {
        if ($this->option('zuruecknehmen')) {
            return $this->zuruecknehmen();
        }

        $gruppen = $this->gruppen();
        $vergeben = HaushaltMitglied::aktuell()->pluck('customer_id')->map(fn ($id) => (string) $id)->flip();
        $nummern = Customer::whereIn('id', array_merge(...array_values($gruppen) ?: [[]]))
            ->pluck('customer_number', 'id')->mapWithKeys(fn ($n, $id) => [(string) $id => (string) $n]);

        $anlegen = [];
        $uebersprungen = [];
        foreach ($gruppen as $ids) {
            if (array_filter($ids, fn ($id) => isset($vergeben[$id])) !== []) {
                $uebersprungen[] = $ids;
            } else {
                $anlegen[] = $ids;
            }
        }

        $zeile = fn (array $ids) => implode(', ', array_map(fn ($id) => $nummern[$id] ?? $id, $ids));
        foreach ($anlegen as $ids) {
            $this->line('Haushalt: '.$zeile($ids));
        }
        foreach ($uebersprungen as $ids) {
            $this->warn('Übersprungen (jemand gehört schon zu einem Haushalt): '.$zeile($ids));
        }
        $personen = array_sum(array_map('count', $anlegen));
        $this->info(count($anlegen).' Haushalt(e) mit '.$personen.' Person(en), '.count($uebersprungen).' übersprungen.');

        if (! $this->option('ausfuehren')) {
            $this->comment('Probelauf - nichts geschrieben. Mit --ausfuehren anlegen.');

            return self::SUCCESS;
        }

        foreach ($anlegen as $ids) {
            DB::transaction(function () use ($haushalte, $ids) {
                $kunden = Customer::whereIn('id', $ids)->get()->keyBy(fn ($c) => (string) $c->id);
                $erster = $kunden[$ids[0]];
                $haushalt = $haushalte->gruenden($erster, null, null, Haushalt::HERKUNFT_UEBERNAHME);
                foreach (array_slice($ids, 1) as $id) {
                    $haushalte->aufnehmen($haushalt, $kunden[$id], null, false);
                }
            });
        }
        $this->info('Angelegt. Hauptansprechpartner bitte in der Kundenakte festlegen.');

        return self::SUCCESS;
    }

    /**
     * Zusammenhaengende Gruppen aus allen "Gleicher Haushalt"-Paaren, deren
     * Akten noch leben (der globale Scope blendet zusammengefuehrte aus).
     *
     * @return array<int, array<int, string>>
     */
    private function gruppen(): array
    {
        $lebend = Customer::pluck('id')->map(fn ($id) => (string) $id)->flip();
        $eltern = [];
        $finde = function (string $x) use (&$eltern, &$finde): string {
            if (! isset($eltern[$x]) || $eltern[$x] === $x) {
                return $eltern[$x] = $x;
            }

            return $eltern[$x] = $finde($eltern[$x]);
        };

        foreach (CustomerRelationship::where('type', 'gleicher_haushalt')->get(['customer_a_id', 'customer_b_id']) as $r) {
            $a = (string) $r->customer_a_id;
            $b = (string) $r->customer_b_id;
            if (! isset($lebend[$a]) || ! isset($lebend[$b])) {
                continue;
            }
            $eltern[$finde($a)] = $finde($b);
        }

        $gruppen = [];
        foreach (array_keys($eltern) as $id) {
            $gruppen[$finde($id)][] = $id;
        }

        return array_values(array_map(function ($ids) {
            sort($ids);

            return $ids;
        }, array_filter($gruppen, fn ($ids) => count($ids) > 1)));
    }

    private function zuruecknehmen(): int
    {
        $entfernt = 0;
        $behalten = 0;
        foreach (Haushalt::where('herkunft', Haushalt::HERKUNFT_UEBERNAHME)->with('mitglieder')->get() as $haushalt) {
            $unveraendert = $haushalt->mitglieder->every(fn (HaushaltMitglied $m) => $m->created_by === null
                && ! $m->hauptansprechpartner && ! $m->beitragszahler && $m->valid_until === null
                && $m->updated_at?->equalTo($m->created_at));
            if (! $unveraendert) {
                $behalten++;

                continue;
            }
            if ($this->option('ausfuehren')) {
                $haushalt->delete();
            }
            $entfernt++;
        }
        $this->info(($this->option('ausfuehren') ? 'Entfernt: ' : 'Würde entfernen: ').$entfernt.', behalten (seither bearbeitet): '.$behalten.'.');

        return self::SUCCESS;
    }
}
