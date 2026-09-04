<?php

namespace App\Services\Commission\Sources;

use App\Models\Provision;
use App\Services\Commission\CommissionQuery;
use App\Services\Commission\CommissionSource;
use App\Support\Commission\CommissionEntry;
use Illuminate\Support\Collection;

/**
 * ARCH-3: `provisions` - Geld, das RAUSGEHT an eigene Mitarbeiter und Partner.
 *
 * Die einzige AUSGANGS-Quelle. Deshalb darf sie nie ohne Weiteres mit den
 * beiden Eingangsquellen zusammengezaehlt werden (siehe CommissionEntry).
 */
class ProvisionSource implements CommissionSource
{
    public function key(): string
    {
        return 'provisions';
    }

    public function label(): string
    {
        return 'Provisionen an Mitarbeiter/Partner';
    }

    public function direction(): string
    {
        return CommissionEntry::AUSGANG;
    }

    public function entries(CommissionQuery $query): Collection
    {
        return Provision::query()
            ->with(['user', 'partner'])
            ->when($query->from, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($query->to, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->when($query->contractId, fn ($q, $v) => $q->where('contract_id', $v))
            ->when($query->customerId, fn ($q, $v) => $q->where('customer_id', $v))
            ->orderByDesc('created_at')
            ->limit($query->limit)
            ->get()
            ->map(fn (Provision $p) => new CommissionEntry(
                source: $this->key(),
                sourceLabel: $this->label(),
                direction: $this->direction(),
                id: (string) $p->id,
                amount: (float) $p->amount,
                currency: $p->currency ?: 'EUR',
                bookedAt: $p->created_at,
                status: $p->status,
                contractId: $p->contract_id ? (string) $p->contract_id : null,
                customerId: $p->customer_id ? (string) $p->customer_id : null,
                counterparty: $p->user->name ?? $p->partner->name ?? null,
                reference: $p->type,
            ));
    }
}
