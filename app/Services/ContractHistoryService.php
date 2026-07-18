<?php
namespace App\Services;

use App\Models\ContractHistory;
use Carbon\Carbon;

/**
 * Schreibt den Vertragsverlauf fort (alle Sparten). Beim Anlegen eines neuen
 * Zeitraums wird der zuvor laufende Eintrag derselben Sparte automatisch
 * beendet (effective_until = Tag vor dem neuen Beginn), sodass sich eine
 * lueckenlose Chronik ergibt.
 */
class ContractHistoryService
{
    /**
     * Einen Verlaufseintrag anlegen. Erwartet mindestens customer_id + branch;
     * effective_from steuert das Beenden des Vorgaengers.
     *
     * @param array{customer_id:string,branch:string,contract_id?:?string,provider?:?string,role?:?string,effective_from?:?string,reason?:?string,source_document_id?:?string,created_by?:?int} $data
     */
    public function record(array $data): ContractHistory
    {
        $from = $data['effective_from'] ?? null;

        if ($from !== null) {
            // Laufenden Vorgaenger derselben Sparte am Tag vor dem neuen
            // Beginn beenden (nur, wenn er frueher begann).
            $until = Carbon::parse($from)->subDay()->toDateString();
            ContractHistory::query()
                ->where('customer_id', $data['customer_id'])
                ->where('branch', $data['branch'])
                ->whereNull('effective_until')
                ->where(function ($q) use ($from) {
                    $q->whereNull('effective_from')->orWhere('effective_from', '<', $from);
                })
                ->update(['effective_until' => $until]);
        }

        return ContractHistory::create([
            'customer_id' => $data['customer_id'],
            'contract_id' => $data['contract_id'] ?? null,
            'branch' => $data['branch'],
            'provider' => $data['provider'] ?? null,
            'role' => $data['role'] ?? null,
            'effective_from' => $from,
            'effective_until' => null,
            'reason' => $data['reason'] ?? null,
            'source_document_id' => $data['source_document_id'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
    }
}
