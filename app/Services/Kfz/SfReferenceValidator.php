<?php

namespace App\Services\Kfz;

use App\Models\Contract;
use App\Models\ContractVehicleDetail;
use App\Models\SystemSetting;
use App\Models\VehicleSfReference;
use Illuminate\Support\Collection;

/**
 * Hinweise rund um die SF-Sondereinstufung (Betreiber-Auftrag 01.10.2026).
 *
 * Alles hier ist eine WARNUNG - sie haelt niemanden auf. Blockiert wird
 * genau ein Fall und nur auf Wunsch des Betreibers (Einstellung
 * sf_reference_required_on_submit, Standard AUS): Zweit-/Drittwagen ohne
 * Bezugsfahrzeug bei einem Antrag bzw. Vertrag (Regel SF-BEZUG-PFLICHT).
 */
class SfReferenceValidator
{
    public const SETTING_REQUIRED = 'sf_reference_required_on_submit';

    /** Muster einer zweckentfremdeten Vorversicherung ("Zweite Wagen ADAC"). */
    private const ZWEITWAGEN_PATTERN = '/(zweit|dritt|erstwagen|\b[123]\.\s*(wagen|fahrzeug|auto|pkw)\b|zweite[nrs]?\s+wagen|zweiter\s+wagen)/iu';

    public static function blockingEnabled(): bool
    {
        return SystemSetting::get(self::SETTING_REQUIRED, '0') === '1';
    }

    /**
     * Warnungen zu einem Vertrag - fuer den Vertrag selbst (als Zweitwagen)
     * UND fuer ihn als Erstwagen anderer Vertraege.
     *
     * @return list<string>
     */
    public function warnings(Contract $contract): array
    {
        $out = [];
        $veh = $contract->vehicleDetail;

        if ($veh) {
            $veh->loadMissing('sfReferences.referenceContract.vehicleDetail', 'sfReferences.referenceContract.customer.user');
            foreach (VehicleSfReference::BRANCHES as $branch => $title) {
                if ($branch === 'vollkasko' && ! $veh->has_vollkasko) continue;
                $reason = $veh->sfSpecialReason($branch);
                if (! VehicleSfReference::reasonNeedsReference($reason)) continue;
                $reasonLabel = ContractVehicleDetail::SF_SPECIAL_REASONS[$reason] ?? $reason;
                $ref = $veh->sfReference($branch);

                if (! $ref || ! $ref->hasReference()) {
                    $out[] = "SF {$title}: „{$reasonLabel}“ ohne Bezugsfahrzeug (Erstwagen) – bitte den Vertrag angeben, auf dem die Sondereinstufung beruht.";
                    continue;
                }
                if ($ref->reference_type === VehicleSfReference::TYPE_INTERNAL && ! $ref->referenceContract) {
                    $out[] = "SF {$title}: der Erstwagen {$ref->reference_label} wurde gelöscht – die Sondereinstufung kann betroffen sein.";
                    continue;
                }
                $refContract = $ref->referenceContract;
                if ($refContract && ! $refContract->isCurrentlyActive()) {
                    $out[] = "SF {$title}: Erstwagen ".VehicleSfReference::labelFor($refContract).' ist nicht mehr aktiv ('.$refContract->displayStatus()['label'].') – die Sondereinstufung kann betroffen sein.';
                }
                if ($refContract && (string) $refContract->customer_id !== (string) $contract->customer_id
                    && in_array($ref->holder_relation, [null, 'kunde'], true)) {
                    $out[] = "SF {$title}: der Erstwagen gehört einem anderen Kunden (".(data_get($refContract, 'customer.user.name', '—')).') – bitte angeben, in welcher Beziehung der Halter steht.';
                }
                $now = $ref->currentReferenceSf();
                if (ContractVehicleDetail::isDowngrade($ref->snapshot_sf_class, $now)) {
                    $out[] = "SF {$title}: der Erstwagen wurde seit der Gewährung zurückgestuft (".ContractVehicleDetail::sfLabel($ref->snapshot_sf_class).' → '.ContractVehicleDetail::sfLabel($now).') – Einstufung prüfen.';
                }
            }

            if ($hint = $this->previousInsuranceHint($contract)) {
                $out[] = $hint;
            }
        }

        // Dieser Vertrag als Erstwagen
        if (! $contract->isCurrentlyActive()) {
            foreach ($this->dependents($contract) as $dep) {
                $out[] = 'Dieser Vertrag ist Erstwagen für '.VehicleSfReference::labelFor($dep).' – ist er beendet, kann dessen Sondereinstufung entfallen.';
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Vertraege, deren Sondereinstufung sich auf $contract stuetzt.
     *
     * @return Collection<int, Contract>
     */
    public function dependents(Contract $contract): Collection
    {
        $contract->loadMissing('sfDependents.vehicleDetail.contract');
        return $contract->sfDependents
            ->map(fn (VehicleSfReference $r) => $r->vehicleDetail?->contract)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Sieht die Vorversicherung nach Erstwagen-Daten aus? Dann gehoert die
     * Angabe ins Bezugsfahrzeug der Sondereinstufung.
     */
    public function previousInsuranceHint(Contract $contract): ?string
    {
        $veh = $contract->vehicleDetail;
        if (! $veh || $veh->no_previous_insurance) return null;
        return $this->looksLikeZweitwagenEntry($veh->previous_insurer, $veh->previous_contract_number, $contract)
            ? 'Vorversicherung: die Angabe sieht nach dem Erstwagen aus (Zweitwagenregelung). Die Vorversicherung beschreibt den Vorvertrag DIESES Fahrzeugs – der Erstwagen gehört unter „SF-Klasse → Sondereinstufung → Bezugsfahrzeug“.'
            : null;
    }

    public function looksLikeZweitwagenEntry(?string $insurer, ?string $number, ?Contract $contract = null): bool
    {
        $text = trim(($insurer ?? '').' '.($number ?? ''));
        if ($text === '') return false;
        if (preg_match(self::ZWEITWAGEN_PATTERN, $text)) return true;
        if ($contract && $number) {
            return $this->matchingOwnContract($number, $contract) !== null;
        }
        return false;
    }

    /** Eigener KFZ-Vertrag (Kunde/Familie) mit dieser Vertragsnummer. */
    public function matchingOwnContract(string $number, Contract $contract): ?Contract
    {
        $needle = self::normalizeNumber($number);
        if ($needle === null || strlen($needle) < 5) return null;
        $ids = app(SfReferenceService::class)->candidateCustomerIds((string) $contract->customer_id, null);
        return Contract::whereIn('customer_id', $ids)->where('type', 'kfz')
            ->where('id', '!=', $contract->id)->whereNotNull('contract_number')->get()
            ->first(fn (Contract $c) => self::normalizeNumber($c->contract_number) === $needle);
    }

    public static function normalizeNumber(?string $number): ?string
    {
        $n = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $number));
        return $n !== '' ? $n : null;
    }

    /**
     * Blockierende Pruefung aus den FORMULARWERTEN (vor dem Speichern).
     * Liefert Fehlermeldungen je Feld, leer = alles gut.
     *
     * @param array<string,mixed> $vehicle Eingaben vehicle[...]
     * @return array<string,string>
     */
    public function blockingErrors(array $vehicle, ?string $stage): array
    {
        $rule = (array) config('kfz_rules.rules.SF-BEZUG-PFLICHT.werte', []);
        if (! self::blockingEnabled() || ! in_array($stage, (array) ($rule['stufen'] ?? ['antrag', 'vertrag']), true)) {
            return [];
        }
        $errors = [];
        $hasVk = ! empty($vehicle['has_teilkasko']) && ! empty($vehicle['has_vollkasko']);
        foreach (['haftpflicht' => 'sf_liability', 'vollkasko' => 'sf_comprehensive'] as $branch => $prefix) {
            if ($branch === 'vollkasko' && ! $hasVk) continue;
            if (($vehicle[$prefix.'_type'] ?? null) !== 'sondereinstufung' || empty($vehicle[$prefix.'_class'])) continue;
            $reason = $vehicle[$prefix.'_special_reason'] ?? null;
            if (! VehicleSfReference::reasonNeedsReference($reason)) continue;
            $ref = (array) ($vehicle['sf_ref'][$branch] ?? []);
            if ($branch === 'vollkasko' && ! empty($ref['copy_from_liability'])) {
                $ref = (array) ($vehicle['sf_ref']['haftpflicht'] ?? []);
            }
            $has = (($ref['reference_type'] ?? null) === 'internal' && ! empty($ref['reference_contract_id']))
                || (($ref['reference_type'] ?? null) === 'external' && (! empty($ref['ext_insurer']) || ! empty($ref['ext_contract_number'])));
            if (! $has) {
                $errors['vehicle.sf_ref.'.$branch] = 'SF '.VehicleSfReference::BRANCHES[$branch].': für „'
                    .(ContractVehicleDetail::SF_SPECIAL_REASONS[$reason] ?? $reason)
                    .'“ ist ein Bezugsfahrzeug (Erstwagen) Pflicht (Einstellung „Bezugsfahrzeug verpflichtend“).';
            }
        }
        return $errors;
    }
}
