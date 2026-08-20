<?php
namespace App\Services\Vermittler;

use App\Models\Contract;
use App\Models\VermittlerSettlement;
use Illuminate\Support\Facades\DB;

/**
 * Auswertungen ueber die Vermittler-Abrechnung (Betreiber-Ziel: welche
 * Vertraege sind wirklich Geld wert und arbeitet der Vermittler sauber?).
 *
 * Alle Zahlen kommen aus den GESPEICHERTEN Abrechnungsdaten - es wird nichts
 * hochgerechnet und nichts geschaetzt. Stornierte Datensaetze gehen NIE in
 * die Provisionssumme ein (der Vermittler zahlt sie nicht aus), werden aber
 * getrennt ausgewiesen, damit der Verlust sichtbar bleibt.
 */
class VermittlerReportService
{
    /** Kennzahlen des Bestands: was haben wir eingereicht, was kam zurueck? */
    public function performance(): array
    {
        $rows = Contract::query()
            ->whereNotNull('vermittler_status')
            ->where('vermittler_status', '!=', Contract::VERMITTLER_NEU)
            ->groupBy('vermittler_status')
            ->select('vermittler_status', DB::raw('count(*) as anzahl'))
            ->pluck('anzahl', 'vermittler_status')
            ->all();

        $eingereicht = array_sum($rows);
        $abgerechnet = ($rows[Contract::VERMITTLER_IN_ABRECHNUNG] ?? 0)
            + ($rows[Contract::VERMITTLER_ABGERECHNET] ?? 0);

        return [
            'eingereicht' => $eingereicht,
            'abgerechnet' => $abgerechnet,
            'storniert' => $rows[Contract::VERMITTLER_STORNIERT] ?? 0,
            'nicht_gefunden' => $rows[Contract::VERMITTLER_NICHT_GEFUNDEN] ?? 0,
            'pruefung' => $rows[Contract::VERMITTLER_PRUEFUNG] ?? 0,
            'offen' => ($rows[Contract::VERMITTLER_REFERENZ] ?? 0) + ($rows[Contract::VERMITTLER_ID_ZUGEORDNET] ?? 0),
            // Bestaetigungsquote: nur aussagekraeftig, wenn ueberhaupt etwas
            // eingereicht wurde - sonst bleibt sie leer statt "0 %".
            'quote' => $eingereicht > 0 ? round($abgerechnet / $eingereicht * 100, 1) : null,
        ];
    }

    /** Je Produkt des Vermittlers: Anzahl, Storno und tatsaechliche Provision. */
    public function byProduct(): array
    {
        // Aggregation je (Produkt, Status-Code) - das sind wenige Zeilen; die
        // fachliche Einordnung (was gilt als Storno) passiert danach in PHP
        // ueber dieselbe Status-Zuordnung wie ueberall sonst.
        $rows = VermittlerSettlement::query()
            ->selectRaw('produkt, status_code, count(*) as anzahl, sum(provision) as provision')
            ->groupBy('produkt', 'status_code')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $produkt = trim((string) $row->produkt) ?: 'Ohne Produktangabe';
            $result[$produkt] ??= [
                'produkt' => $produkt, 'anzahl' => 0, 'bestaetigt' => 0,
                'storniert' => 0, 'provision' => 0.0, 'provision_storno' => 0.0,
            ];
            $isStorno = VermittlerStatusMap::forCode($row->status_code) === Contract::VERMITTLER_STORNIERT;
            $result[$produkt]['anzahl'] += (int) $row->anzahl;
            $result[$produkt][$isStorno ? 'storniert' : 'bestaetigt'] += (int) $row->anzahl;
            $result[$produkt][$isStorno ? 'provision_storno' : 'provision'] += (float) $row->provision;
        }

        usort($result, fn ($a, $b) => $b['anzahl'] <=> $a['anzahl']);

        return $result;
    }

    /** Je Kunde: wie viel hat dieser Kunde tatsaechlich eingebracht? */
    public function byCustomer(int $limit = 50): array
    {
        $rows = VermittlerSettlement::query()
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, status_code, count(*) as anzahl, sum(provision) as provision')
            ->groupBy('customer_id', 'status_code')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->customer_id] ??= [
                'customer_id' => $row->customer_id, 'anzahl' => 0,
                'bestaetigt' => 0, 'storniert' => 0, 'provision' => 0.0,
            ];
            $isStorno = VermittlerStatusMap::forCode($row->status_code) === Contract::VERMITTLER_STORNIERT;
            $result[$row->customer_id]['anzahl'] += (int) $row->anzahl;
            $result[$row->customer_id][$isStorno ? 'storniert' : 'bestaetigt'] += (int) $row->anzahl;
            if (!$isStorno) {
                $result[$row->customer_id]['provision'] += (float) $row->provision;
            }
        }

        usort($result, fn ($a, $b) => $b['provision'] <=> $a['provision']);
        $result = array_slice($result, 0, $limit);

        $customers = \App\Models\Customer::with('user')
            ->whereIn('id', array_column($result, 'customer_id'))->get()->keyBy('id');
        foreach ($result as &$entry) {
            $entry['customer'] = $customers[$entry['customer_id']] ?? null;
        }

        return $result;
    }
}
