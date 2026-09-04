<?php

namespace App\Services\Commission\Sources;

use App\Models\VermittlerSettlement;
use App\Services\Commission\CommissionQuery;
use App\Services\Commission\CommissionSource;
use App\Support\Commission\CommissionEntry;
use Illuminate\Support\Collection;

/**
 * ARCH-3: `vermittler_settlements` - der EINE Vermittler (TARIFCHECK24) mit
 * festem Format und EINER Kennung.
 *
 * Bleibt eine eigene Quelle: die Zeilen tragen bewusst eine Klartext-Kopie
 * von Vertrag und Kunde und ueberleben deshalb das Loeschen des Vertrags.
 * Genau das waere beim Verschmelzen in die Pool-Tabelle verloren gegangen.
 */
class VermittlerSettlementSource implements CommissionSource
{
    public function key(): string
    {
        return 'vermittler_settlements';
    }

    public function label(): string
    {
        return 'Abrechnung TARIFCHECK24';
    }

    public function direction(): string
    {
        return CommissionEntry::EINGANG;
    }

    public function entries(CommissionQuery $query): Collection
    {
        return VermittlerSettlement::query()
            ->when($query->from, fn ($q, $v) => $q->whereDate('statement_date', '>=', $v))
            ->when($query->to, fn ($q, $v) => $q->whereDate('statement_date', '<=', $v))
            ->when($query->contractId, fn ($q, $v) => $q->where('contract_id', $v))
            ->when($query->customerId, fn ($q, $v) => $q->where('customer_id', $v))
            ->orderByDesc('statement_date')
            ->limit($query->limit)
            ->get()
            ->map(fn (VermittlerSettlement $s) => new CommissionEntry(
                source: $this->key(),
                sourceLabel: $this->label(),
                direction: $this->direction(),
                id: (string) $s->id,
                amount: (float) $s->provision,
                currency: 'EUR',
                bookedAt: $s->statement_date,
                status: $s->match_result,
                contractId: $s->contract_id ? (string) $s->contract_id : null,
                customerId: $s->customer_id ? (string) $s->customer_id : null,
                // Bewusst die Klartext-Kopie: sie ueberlebt das Loeschen.
                counterparty: $s->produkt ?: 'TARIFCHECK24',
                reference: $s->vermittler_id ?: $s->reference_number,
            ));
    }
}
