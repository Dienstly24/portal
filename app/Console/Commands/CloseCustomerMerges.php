<?php

namespace App\Console\Commands;

use App\Console\Concerns\ProcessesRecordsSafely;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Schliesst Zusammenfuehrungen nach Ablauf der Rueckgaengig-Frist ab
 * (KI-064, Datenminimierung Art. 5 Abs. 1 lit. c DSGVO).
 *
 * Solange ein Merge zuruecknehmbar ist, MUSS die Huelle ihre Stammdaten und
 * das Protokoll die verworfenen Zeilen behalten - sonst gaebe es nichts
 * zurueckzuholen. Danach sind beide nur noch eine zweite Kopie von Daten,
 * die beim Hauptkunden stehen. Was BLEIBT: die Huelle mit Kundennummer und
 * Verweis (der Alias - alte Schreiben nennen die Nummer) und die Zahlen des
 * Protokolls (was wurde wie viel umgehaengt).
 */
class CloseCustomerMerges extends Command
{
    use ProcessesRecordsSafely;

    protected $signature = 'kunden:zusammenfuehrungen-abschliessen {--probelauf : Nur anzeigen, nichts aendern}';

    protected $description = 'Leert Protokoll und Stammdaten archivierter Akten nach Ablauf der Rueckgaengig-Frist';

    /** Diese Spalten der Huelle bleiben - alles uebrige Nullbare wird geleert. */
    private const BEHALTEN = ['id', 'user_id', 'customer_number', 'merged_into_id', 'archived_at', 'created_at', 'updated_at'];

    public function handle(): int
    {
        $faellig = CustomerMerge::whereNull('undone_at')
            ->where('created_at', '<', now()->subDays(CustomerMerge::RUECKGAENGIG_TAGE))
            ->get()
            ->filter(fn (CustomerMerge $m) => ($m->protokoll['abgeschlossen'] ?? false) !== true);

        $this->info($faellig->count().' Zusammenfuehrung(en) nach Fristablauf.');
        if ($this->option('probelauf') || $faellig->isEmpty()) {
            return self::SUCCESS;
        }

        $spalten = $this->leerbareSpalten();

        $this->verarbeiteEinzeln($faellig, function (CustomerMerge $merge) use ($spalten) {
            DB::transaction(function () use ($merge, $spalten) {
                $protokoll = $merge->protokoll;
                $verliererId = $protokoll['konto']['verlierer_user_id'] ?? null;
                $merge->forceFill(['protokoll' => [
                    'abgeschlossen' => true,
                    'abgeschlossen_am' => now()->toDateTimeString(),
                    'umgehaengt' => array_map(fn ($ids) => count((array) $ids), $protokoll['umgehaengt'] ?? []),
                    'verworfen' => array_map(fn ($zeilen) => count((array) $zeilen), $protokoll['verworfen'] ?? []),
                ]])->save();

                $huelle = Customer::mitArchiv()->find($merge->duplicate_customer_id);
                if (! $huelle || ! $huelle->isArchived()) {
                    return;
                }
                if ($spalten !== []) {
                    DB::table('customers')->where('id', $huelle->id)
                        ->update(array_fill_keys($spalten, null) + ['updated_at' => now()]);
                }

                // Das Konto der Huelle und das stillgelegte Verlierer-Konto -
                // nur, wenn KEINE lebende Akte es benutzt (beim Konto-Tausch
                // teilt die Huelle das Konto des Hauptkunden).
                foreach (array_unique(array_filter([$huelle->user_id, $verliererId])) as $kontoId) {
                    $konto = User::find($kontoId);
                    if ($konto && $konto->role === 'customer'
                        && ! Customer::where('user_id', $konto->id)->exists()) {
                        $konto->forceFill([
                            'name' => 'Zusammengefuehrte Akte',
                            'email' => 'archiv-'.$konto->id.'@dienstly24.internal',
                            'password' => Hash::make(Str::random(48)),
                            'remember_token' => null,
                            'is_active' => false,
                        ])->save();
                    }
                }
            });
        }, 'Zusammenfuehrung');

        return $this->ergebnisMitUebersprungenen();
    }

    /** @return list<string> */
    private function leerbareSpalten(): array
    {
        return array_values(array_map(
            fn (array $c) => $c['name'],
            array_filter(Schema::getColumns('customers'), fn (array $c) => ($c['nullable'] ?? false)
                && ! in_array($c['name'], self::BEHALTEN, true))
        ));
    }
}
