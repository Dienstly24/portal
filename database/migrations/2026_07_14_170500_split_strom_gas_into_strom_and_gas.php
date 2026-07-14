<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sparten-Trennung (Betreiber-Vorgabe 14.07.2026): die bisherige Sammelsparte
 * "strom_gas" wird in zwei eigenstaendige Sparten "strom" und "gas" aufgeteilt.
 *
 * Zuordnung anhand des Tarif-/Produktnamens im Energie-Detaildatensatz:
 * enthaelt er "gas" (auch "Erdgas", "Ökogas") -> gas, sonst -> strom.
 * Vertraege ohne Detaildatensatz fallen auf "strom" zurueck.
 *
 * contracts.type ist bereits ein string(40) (frueher flexibilisiert), daher
 * keine Enum-Aenderung noetig.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1) Vertraege mit Energie-Detail: nach Tarifname entscheiden.
        $rows = DB::table('contracts')
            ->leftJoin('contract_energy_details', 'contracts.id', '=', 'contract_energy_details.contract_id')
            ->where('contracts.type', 'strom_gas')
            ->select('contracts.id', 'contract_energy_details.tariff')
            ->get();

        foreach ($rows as $row) {
            $isGas = preg_match('/gas/i', (string) $row->tariff) === 1;
            DB::table('contracts')->where('id', $row->id)->update([
                'type' => $isGas ? 'gas' : 'strom',
            ]);
        }

        // 2) Sicherheitsnetz: verbliebene "strom_gas" (kein Detail) -> strom.
        DB::table('contracts')->where('type', 'strom_gas')->update(['type' => 'strom']);
    }

    public function down(): void
    {
        // Rueckabwicklung: strom + gas wieder zur Sammelsparte zusammenfuehren.
        DB::table('contracts')->whereIn('type', ['strom', 'gas'])->update(['type' => 'strom_gas']);
    }
};
