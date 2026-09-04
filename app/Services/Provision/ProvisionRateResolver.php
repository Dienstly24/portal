<?php

namespace App\Services\Provision;

use App\Models\Partner;
use App\Models\ProvisionRate;
use App\Models\User;

/**
 * Loest den anzuwendenden Provisions-Satz fuer einen Empfaenger und eine
 * Sparte auf (Provisions-Management): Zuerst der SPARTEN-Satz aus
 * provision_rates (je Mitarbeiter/Partner je Produkt), als Fallback der
 * GLOBALE Satz am Mitarbeiter/Partner (provision_fixed/-percent). Gibt es
 * keinen von beiden, wird KEINE Provision berechnet - es werden nie Betraege
 * erfunden (HITL-Prinzip).
 */
class ProvisionRateResolver
{
    /** @return array{fixed: float, percent: float, source: string}|null */
    public function forUser(User $user, ?string $contractType): ?array
    {
        return $this->resolve(
            $contractType ? ProvisionRate::where('user_id', $user->id)->where('contract_type', $contractType)->first() : null,
            $user->provision_fixed,
            $user->provision_percent,
        );
    }

    /** @return array{fixed: float, percent: float, source: string}|null */
    public function forPartner(Partner $partner, ?string $contractType): ?array
    {
        return $this->resolve(
            $contractType ? ProvisionRate::where('partner_id', $partner->id)->where('contract_type', $contractType)->first() : null,
            $partner->provision_fixed,
            $partner->provision_percent,
        );
    }

    /** @return array{fixed: float, percent: float, source: string}|null */
    private function resolve(?ProvisionRate $rate, $globalFixed, $globalPercent): ?array
    {
        if ($rate && $rate->hasValue()) {
            return [
                'fixed' => (float) ($rate->amount_fixed ?? 0),
                'percent' => (float) ($rate->amount_percent ?? 0),
                'source' => 'sparte',
            ];
        }
        if ($globalFixed !== null || $globalPercent !== null) {
            return [
                'fixed' => (float) ($globalFixed ?? 0),
                'percent' => (float) ($globalPercent ?? 0),
                'source' => 'global',
            ];
        }
        return null;
    }
}
