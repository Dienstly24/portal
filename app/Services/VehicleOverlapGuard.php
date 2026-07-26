<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractVehicleDetail;
use Illuminate\Support\Carbon;

/**
 * Doppelversicherungs-Schutz (Betreiber-Vorgabe 26.07.2026): dasselbe
 * Fahrzeug darf nie zwei Vertraege mit ueberschneidendem Versicherungs-
 * zeitraum haben. Ein Versicherer-Wechsel ist ausdruecklich erlaubt und
 * besteht aus ZWEI Vertraegen: der alte gekuendigt zum X, der neue aktiv
 * ab X - die Zeitraeume beruehren sich (halb-offene Intervalle),
 * ueberschneiden sich aber nicht. Ein Kunde darf beliebig viele Fahrzeuge
 * besitzen; geprueft wird nur je Fahrzeug.
 *
 * Fahrzeug-Identitaet (streng nach Aussagekraft):
 *   1. FIN/VIN - eindeutig je Fahrzeug. Unterschiedliche FIN = sicher
 *      verschiedene Fahrzeuge, auch bei gleichem Kennzeichen.
 *   2. Kennzeichen (normalisiert, umlaut-tolerant: "LÜN-G 1110" = "LUN-G1110").
 *   3. HSN+TSN nur als letzte Stufe, wenn BEIDE Seiten weder FIN noch
 *      Kennzeichen haben (HSN/TSN bezeichnet das Modell, nicht das
 *      Einzelfahrzeug - bei zwei baugleichen Fahrzeugen Kennzeichen pflegen).
 */
class VehicleOverlapGuard
{
    /**
     * Liefert den kollidierenden Bestandsvertrag oder null.
     *
     * $candidate ist ein NICHT gespeicherter Vertrag, nur mit den fuer den
     * Zeitraum relevanten Feldern befuellt (customer_id, type, status,
     * start_date, end_date, cancellation_date). $vehicle sind die
     * Fahrzeugfelder aus dem Formular (license_plate, vin, hsn, tsn).
     * $ignoreId blendet beim Bearbeiten den eigenen Vertrag aus.
     */
    public function findConflict(Contract $candidate, array $vehicle, ?string $ignoreId = null): ?Contract
    {
        if ($candidate->type !== 'kfz') {
            return null;
        }

        $vin = ContractVehicleDetail::normalizeVin($vehicle['vin'] ?? null);
        $plate = ContractVehicleDetail::normalizePlate($vehicle['license_plate'] ?? null);
        $hsn = trim((string) ($vehicle['hsn'] ?? ''));
        $tsn = mb_strtoupper(trim((string) ($vehicle['tsn'] ?? '')));
        if (!$vin && !$plate && ($hsn === '' || $tsn === '')) {
            return null; // ohne Fahrzeug-Identitaet keine Pruefung moeglich
        }

        $newStart = $candidate->start_date ? Carbon::parse($candidate->start_date)->startOfDay() : null;
        $newEnd = $candidate->coverageEndsAt();

        $siblings = Contract::where('customer_id', $candidate->customer_id)
            ->where('type', 'kfz')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->with('vehicleDetail')
            ->get();

        foreach ($siblings as $other) {
            if (!$other->vehicleDetail) {
                continue;
            }
            if (!$this->sameVehicle($vin, $plate, $hsn, $tsn, $other->vehicleDetail)) {
                continue;
            }
            $otherStart = $other->start_date ? Carbon::parse($other->start_date)->startOfDay() : null;
            if ($this->overlaps($newStart, $newEnd, $otherStart, $other->coverageEndsAt())) {
                return $other;
            }
        }

        return null;
    }

    /** Deutsche, handlungsleitende Fehlermeldung fuer das Formular. */
    public function conflictMessage(Contract $conflict): string
    {
        $veh = $conflict->vehicleDetail;
        $fahrzeug = $veh?->license_plate ?: ($veh?->vin ? 'FIN ' . $veh->vin : 'dieses Fahrzeug');
        $nummer = $conflict->contract_number ? ' (' . $conflict->contract_number . ')' : '';
        $ende = $conflict->coverageEndsAt();

        if ($ende) {
            return 'Doppelversicherung verhindert: Für ' . $fahrzeug . ' besteht bereits der Vertrag '
                . $conflict->insurer . $nummer . ' mit Schutz bis ' . $ende->format('d.m.Y')
                . '. Ein weiterer Vertrag für dieses Fahrzeug darf frühestens ab diesem Tag beginnen.';
        }

        return 'Doppelversicherung verhindert: Für ' . $fahrzeug . ' läuft bereits der Vertrag '
            . $conflict->insurer . $nummer . ' ohne erfasstes Ende. Beim Versicherer-Wechsel zuerst dort die '
            . 'Kündigung erfassen (Kündigungsdatum + Ablauf), danach den neuen Vertrag ab dem Ablauftag anlegen.';
    }

    /** Identitaets-Vergleich, konservativ: im Zweifel NICHT dasselbe Fahrzeug. */
    private function sameVehicle(?string $vin, ?string $plate, string $hsn, string $tsn, ContractVehicleDetail $veh): bool
    {
        $otherVin = ContractVehicleDetail::normalizeVin($veh->vin);
        $otherPlate = ContractVehicleDetail::normalizePlate($veh->license_plate);

        // FIN entscheidet: gleiche FIN = gleiches Fahrzeug; verschiedene FIN
        // = sicher verschiedene Fahrzeuge (Kennzeichen koennen wandern).
        if ($vin && $otherVin) {
            return $vin === $otherVin;
        }
        if ($plate && $otherPlate) {
            return $plate === $otherPlate;
        }

        // HSN/TSN nur, wenn keine Seite ein staerkeres Merkmal hat.
        if (!$vin && !$plate && !$otherVin && !$otherPlate && $hsn !== '' && $tsn !== '') {
            return $hsn === trim((string) $veh->hsn)
                && $tsn === mb_strtoupper(trim((string) $veh->tsn));
        }

        return false;
    }

    /**
     * Halb-offene Intervalle [Beginn, Ende): nahtloser Wechsel (altes Ende =
     * neuer Beginn) ist ERLAUBT. null-Beginn = unbekannt/frueher, null-Ende
     * = offen. Leere Intervalle (Ende <= Beginn) ueberschneiden nie.
     */
    private function overlaps(?Carbon $aStart, ?Carbon $aEnd, ?Carbon $bStart, ?Carbon $bEnd): bool
    {
        if ($aStart && $aEnd && $aStart->greaterThanOrEqualTo($aEnd)) {
            return false;
        }
        if ($bStart && $bEnd && $bStart->greaterThanOrEqualTo($bEnd)) {
            return false;
        }
        $aBeginntVorBEnde = $bEnd === null || $aStart === null || $aStart->lessThan($bEnd);
        $bBeginntVorAEnde = $aEnd === null || $bStart === null || $bStart->lessThan($aEnd);
        return $aBeginntVorBEnde && $bBeginntVorAEnde;
    }
}
