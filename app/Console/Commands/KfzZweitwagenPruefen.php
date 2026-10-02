<?php

namespace App\Console\Commands;

use App\Models\ContractVehicleDetail;
use App\Services\Kfz\SfReferenceValidator;
use Illuminate\Console\Command;

/**
 * Bestandspruefung (01.10.2026): KFZ-Vertraege, deren VORVERSICHERUNG nach
 * zweckentfremdeten Erstwagen-Daten aussieht ("Zweite Wagen ADAC",
 * "AD-5406305005" = Nummer eines eigenen Vertrags).
 *
 * STRENG LESEND: der Befehl schreibt nichts, er legt einen Bericht mit
 * Vorschlaegen vor. Uebernommen wird erst nach Freigabe des Betreibers -
 * dann von Hand im Formular (Bezugsfahrzeug waehlen, Vorversicherung
 * leeren).
 */
class KfzZweitwagenPruefen extends Command
{
    protected $signature = 'kfz:zweitwagen-pruefen {--csv= : Bericht zusaetzlich als CSV-Datei schreiben (Pfad)}';

    protected $description = 'Findet zweckentfremdete Vorversicherungs-Angaben (Erstwagen einer Zweitwagenregelung) und schlaegt Verknuepfungen vor - schreibt nichts';

    public function handle(SfReferenceValidator $validator): int
    {
        $rows = [];
        ContractVehicleDetail::query()
            ->with(['contract.customer.user', 'sfReferences'])
            ->where(fn ($q) => $q->whereNotNull('previous_insurer')->orWhereNotNull('previous_contract_number'))
            ->chunkById(200, function ($details) use ($validator, &$rows) {
                foreach ($details as $detail) {
                    $contract = $detail->contract;
                    if (! $contract || $contract->type !== 'kfz') continue;

                    $text = trim(($detail->previous_insurer ?? '').' '.($detail->previous_contract_number ?? ''));
                    $muster = $validator->looksLikeZweitwagenEntry($detail->previous_insurer, $detail->previous_contract_number);
                    $treffer = $detail->previous_contract_number
                        ? $validator->matchingOwnContract($detail->previous_contract_number, $contract)
                        : null;
                    if (! $muster && ! $treffer) continue;

                    $hatBezug = $detail->sfReferences->contains(fn ($r) => $r->hasReference());
                    $sonder = collect(['haftpflicht', 'vollkasko'])->map(fn ($b) => $detail->sfSpecialReason($b))->filter()->unique()->implode('/');

                    $rows[] = [
                        'kunde' => trim(data_get($contract, 'customer.customer_number', '').' '.data_get($contract, 'customer.user.name', '')),
                        'vertrag' => trim($contract->insurer.' '.($contract->contract_number ?? '')),
                        'vertrag_id' => $contract->id,
                        'vorversicherung' => $text,
                        'befund' => implode(' + ', array_filter([$muster ? 'Text sieht nach Zweitwagen aus' : null, $treffer ? 'Nummer = eigener Vertrag' : null])),
                        'sondereinstufung' => $sonder !== '' ? $sonder : 'keine',
                        'bereits_verknuepft' => $hatBezug ? 'ja' : 'nein',
                        'vorschlag' => $treffer
                            ? 'Bezugsfahrzeug = '.trim($treffer->insurer.' '.$treffer->contract_number).' (ID '.$treffer->id.'), Vorversicherung leeren'
                            : 'Erstwagen extern erfassen ('.$text.'), Vorversicherung pruefen/leeren',
                    ];
                }
            });

        if (! $rows) {
            $this->info('Keine verdaechtigen Vorversicherungs-Angaben gefunden.');
            return self::SUCCESS;
        }

        $this->warn(count($rows).' Vertrag/Vertraege mit vermutlich zweckentfremdeter Vorversicherung. Es wurde NICHTS geaendert.');
        $this->table(
            ['Kunde', 'Vertrag', 'Vorversicherung', 'Befund', 'Sondereinstufung', 'Verknuepft', 'Vorschlag'],
            array_map(fn ($r) => [$r['kunde'], $r['vertrag'], $r['vorversicherung'], $r['befund'], $r['sondereinstufung'], $r['bereits_verknuepft'], $r['vorschlag']], $rows)
        );

        if ($path = $this->option('csv')) {
            $fh = @fopen((string) $path, 'w');
            if (! $fh) {
                $this->error('CSV konnte nicht geschrieben werden: '.$path);
                return self::FAILURE;
            }
            fwrite($fh, "\u{FEFF}"); // BOM: Excel erkennt UTF-8
            fputcsv($fh, array_keys($rows[0]), ';');
            foreach ($rows as $r) fputcsv($fh, $r, ';');
            fclose($fh);
            $this->info('CSV geschrieben: '.$path);
        }

        return self::SUCCESS;
    }
}
