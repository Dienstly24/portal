<?php
namespace App\Services\Vermittler;

use App\Models\Contract;
use App\Models\VermittlerMatchEvent;
use App\Models\VermittlerSettlement;

/**
 * Die Zuordnung von HAND - und das Protokollieren jeder Aenderung an den
 * beiden Kennungen.
 *
 * Der Import ordnet nur zu, was eindeutig ist; alles andere landet in der
 * Pruefliste und wird hier durch einen Mitarbeiter entschieden. Auch diese
 * bewusste Entscheidung aendert keine Vertragsdaten - nur die
 * Abrechnungs-Spalten und die Historie.
 */
class VermittlerLinkService
{
    /**
     * Einen Abrechnungs-Datensatz einem Vertrag zuordnen.
     *
     * @throws \RuntimeException wenn der Vertrag bereits eine ANDERE
     *         Vermittler-ID traegt - dann ist erst zu klaeren, welche stimmt.
     */
    public function linkManually(VermittlerSettlement $settlement, Contract $contract, ?int $userId = null): void
    {
        if (filled($contract->vermittler_id)
            && !VermittlerReference::same($contract->vermittler_id, $settlement->vermittler_id)) {
            throw new \RuntimeException(
                'Der Vertrag trägt bereits die Vermittler-ID "' . $contract->vermittler_id
                . '". Bitte zuerst klären, welche Zuordnung stimmt.'
            );
        }

        $status = $settlement->contractStatus();

        $contract->forceFill([
            'vermittler_id' => $settlement->vermittler_id,
            'vermittler_status' => $status,
            'vermittler_matched_at' => now(),
            'vermittler_last_import_id' => $settlement->import_id,
            'vermittler_last_imported_at' => now(),
        ])->saveQuietly();

        $settlement->update([
            'contract_id' => $contract->id,
            'customer_id' => $contract->customer_id,
            'contract_label' => mb_substr(trim($contract->insurer . ' · ' . ($contract->contract_number ?: $contract->typeLabel())), 0, 190),
            'customer_label' => mb_substr((string) ($contract->customer?->user?->name ?? ''), 0, 190) ?: null,
            'match_result' => 'matched',
            'import_result' => 'matched',
            'match_note' => null,
        ]);

        VermittlerMatchEvent::record('manual_link', [
            'contract_id' => $contract->id,
            'reference_number' => $contract->reference_number,
            'vermittler_id' => $settlement->vermittler_id,
            'detail' => 'Vermittler-ID ' . $settlement->vermittler_id . ' manuell zugeordnet · '
                . Contract::VERMITTLER_STATUSES[$status]['label'],
            'import_id' => $settlement->import_id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Aenderungen an Referenz-Nr. / Vermittler-ID im Vertragsformular
     * protokollieren. Der Betreiber soll spaeter sehen, ob eine Kennung
     * von Hand kam oder aus einer Abrechnung.
     *
     * @param array{reference_number: ?string, vermittler_id: ?string} $before
     */
    public function recordContractEdit(Contract $contract, array $before, ?int $userId = null): void
    {
        $refBefore = VermittlerReference::display($before['reference_number'] ?? null);
        $refAfter = VermittlerReference::display($contract->reference_number);
        $idBefore = VermittlerReference::display($before['vermittler_id'] ?? null);
        $idAfter = VermittlerReference::display($contract->vermittler_id);

        $events = [];
        if ($refAfter !== $refBefore) {
            $events[] = $refBefore === null
                ? ['reference_stored', 'Referenz-Nr. ' . $refAfter . ' hinterlegt']
                : ($refAfter === null
                    ? ['reference_changed', 'Referenz-Nr. ' . $refBefore . ' entfernt']
                    : ['reference_changed', 'Referenz-Nr. ' . $refBefore . ' → ' . $refAfter]);
        }
        if ($idAfter !== $idBefore) {
            $events[] = $idBefore === null
                ? ['id_linked', 'Vermittler-ID ' . $idAfter . ' zugeordnet']
                : ($idAfter === null
                    ? ['id_unlinked', 'Vermittler-ID ' . $idBefore . ' entfernt']
                    : ['id_changed', 'Vermittler-ID ' . $idBefore . ' → ' . $idAfter]);
        }

        if ($events === []) {
            return;
        }

        // Zustand nachziehen, solange der Vertrag noch nie in einer
        // Abrechnung stand. Ein bereits abgerechneter oder stornierter
        // Vertrag wird durch eine Formular-Eingabe NIE zurueckgestuft.
        if (in_array($contract->vermittlerStatus(), Contract::VERMITTLER_PRE_MATCH, true)
            || $contract->vermittlerStatus() === Contract::VERMITTLER_ID_ZUGEORDNET) {
            $derived = $idAfter !== null
                ? Contract::VERMITTLER_ID_ZUGEORDNET
                : ($refAfter !== null ? Contract::VERMITTLER_REFERENZ : Contract::VERMITTLER_NEU);
            if ($derived !== $contract->vermittler_status) {
                $contract->forceFill(['vermittler_status' => $derived])->saveQuietly();
            }
        }

        foreach ($events as [$action, $detail]) {
            VermittlerMatchEvent::record($action, [
                'contract_id' => $contract->id,
                'reference_number' => $refAfter,
                'vermittler_id' => $idAfter,
                'detail' => mb_substr($detail, 0, 255),
                'user_id' => $userId,
            ]);
        }

        // Traegt eine Abrechnungszeile bereits diese ID, ohne dass sie einem
        // Vertrag zugeordnet war, wird sie jetzt nachtraeglich verbunden -
        // genau der Fall "erst Abrechnung, spaeter Vertrag erfasst".
        if ($idAfter !== null) {
            $orphan = VermittlerSettlement::where('vermittler_id', $idAfter)
                ->whereNull('contract_id')->first();
            if ($orphan) {
                $this->linkManually($orphan, $contract->refresh(), $userId);
            }
        }
    }
}
