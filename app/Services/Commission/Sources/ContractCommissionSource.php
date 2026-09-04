<?php

namespace App\Services\Commission\Sources;

use App\Models\ContractCommission;
use App\Services\Commission\CommissionQuery;
use App\Services\Commission\CommissionSource;
use App\Support\Commission\CommissionEntry;
use Illuminate\Support\Collection;

/**
 * ARCH-3: `contract_commissions` - Geld, das REINKOMMT, aus beliebig vielen
 * Quellen (Maklerpool, Vergleichsportal, Energieportal).
 *
 * Gebucht wird auf `commission_date`, ersatzweise `booking_date`: eine Zeile
 * ohne Provisionsdatum hat trotzdem einen Buchungstag, und ein Bericht, der
 * sie deshalb weglaesst, zeigt zu wenig Geld an.
 */
class ContractCommissionSource implements CommissionSource
{
    public function key(): string
    {
        return 'contract_commissions';
    }

    public function label(): string
    {
        return 'Provisionsabrechnungen der Pools';
    }

    public function direction(): string
    {
        return CommissionEntry::EINGANG;
    }

    public function entries(CommissionQuery $query): Collection
    {
        return ContractCommission::query()
            ->when($query->from, fn ($q, $v) => $q->whereDate('commission_date', '>=', $v))
            ->when($query->to, fn ($q, $v) => $q->whereDate('commission_date', '<=', $v))
            ->when($query->contractId, fn ($q, $v) => $q->where('contract_id', $v))
            ->when($query->customerId, fn ($q, $v) => $q->where('customer_id', $v))
            ->orderByDesc('commission_date')
            ->limit($query->limit)
            ->get()
            ->map(fn (ContractCommission $c) => new CommissionEntry(
                source: $this->key(),
                sourceLabel: $this->label(),
                direction: $this->direction(),
                id: (string) $c->id,
                amount: (float) $c->amount,
                currency: $c->currency ?: 'EUR',
                bookedAt: $c->commission_date ?: $c->booking_date,
                status: $c->status,
                contractId: $c->contract_id ? (string) $c->contract_id : null,
                customerId: $c->customer_id ? (string) $c->customer_id : null,
                counterparty: $c->pool ?: $c->provider,
                reference: $c->internal_contract_number ?: $c->reference_number,
            ));
    }
}
