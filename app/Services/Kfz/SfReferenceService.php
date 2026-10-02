<?php

namespace App\Services\Kfz;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractRevision;
use App\Models\ContractVehicleDetail;
use App\Models\CustomerFamilyRelation;
use App\Models\Document;
use App\Models\User;
use App\Models\VehicleSfReference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Der EINE Schreibweg fuer die Begruendung einer SF-Sondereinstufung
 * (Betreiber-Auftrag 01.10.2026, KFZ-Modul Phase 1).
 *
 * Regeln:
 *  - Nur fuer Sparten mit Sondereinstufung existiert eine Zeile; faellt
 *    die Sondereinstufung weg, faellt die Zeile weg (protokolliert).
 *  - Bezugsfahrzeug nur bei Zweitwagen/Drittwagen/Familie, und nur ein
 *    KFZ-Vertrag des Kunden oder seiner verknuepften Familie, den der
 *    Bearbeiter sehen darf. Nie der Vertrag selbst, nie ein Kreis.
 *  - "Geprueft" setzen nur admin/manager - bei allen anderen bleibt der
 *    gespeicherte Stand unveraendert (nicht: wird geloescht).
 *  - Jede Aenderung: ContractRevision (Version History) + ActivityLog
 *    "sf_reference_changed".
 */
class SfReferenceService
{
    private const MAX_CHAIN = 25;

    /** Felder, deren Aenderung protokolliert wird. */
    private const AUDITED = [
        'reference_type', 'reference_contract_id', 'reference_label',
        'ext_insurer', 'ext_contract_number', 'ext_license_plate', 'ext_sf_class',
        'holder_relation', 'holder_name', 'snapshot_sf_class', 'snapshot_date',
        'proof_document_id', 'verified', 'license_date', 'campaign_name', 'note',
    ];

    /**
     * Bezuege beider Sparten aus dem Formular uebernehmen.
     *
     * @param array<string,mixed> $input vehicle[sf_ref] ({haftpflicht: {...}, vollkasko: {...}})
     * @return list<string> Hinweise fuer die Erfolgsmeldung (z. B. angelegter Fremdvertrag)
     */
    public function sync(Contract $contract, ContractVehicleDetail $detail, array $input, ?User $user): array
    {
        $notes = [];
        $detail->load('sfReferences');
        $hp = is_array($input['haftpflicht'] ?? null) ? $input['haftpflicht'] : [];
        $vk = is_array($input['vollkasko'] ?? null) ? $input['vollkasko'] : [];

        // "Gleichen Bezug wie Haftpflicht uebernehmen": nur die IDENTITAET
        // des Erstwagens wird uebernommen - seine Vollkasko-SF bleibt eine
        // eigene Angabe (sie weicht von der Haftpflicht-SF regelmaessig ab).
        if (! empty($vk['copy_from_liability'])) {
            foreach (['reference_type', 'reference_contract_id', 'ext_insurer', 'ext_contract_number', 'ext_license_plate', 'holder_relation', 'holder_name', 'proof_document_id'] as $key) {
                $vk[$key] = $hp[$key] ?? null;
            }
            $vk['create_external'] = null; // HP legt den Fremdvertrag an - nie zweimal
        }

        $createdHp = $this->syncBranch($contract, $detail, 'haftpflicht', $hp, $user);
        if ($createdHp) {
            $notes[] = 'Erstwagen '.VehicleSfReference::labelFor($createdHp).' wurde als Fremdvertrag im Kundenbestand angelegt.';
            // Vollkasko mit "uebernehmen" zeigt auf denselben neuen Vertrag.
            if (! empty($vk['copy_from_liability'])) {
                $vk['reference_type'] = VehicleSfReference::TYPE_INTERNAL;
                $vk['reference_contract_id'] = $createdHp->id;
            }
        }
        $createdVk = $this->syncBranch($contract, $detail, 'vollkasko', $vk, $user);
        if ($createdVk && $createdVk->id !== $createdHp?->id) {
            $notes[] = 'Erstwagen '.VehicleSfReference::labelFor($createdVk).' wurde als Fremdvertrag im Kundenbestand angelegt.';
        }

        return $notes;
    }

    /** @return Contract|null neu angelegter Fremdvertrag (Erstwagen) */
    private function syncBranch(Contract $contract, ContractVehicleDetail $detail, string $branch, array $data, ?User $user): ?Contract
    {
        $existing = $detail->sfReferences->firstWhere('branch', $branch);
        $reason = $detail->sfSpecialReason($branch);

        if ($reason === null) {
            if ($existing) {
                $before = $this->auditState($existing);
                $existing->delete();
                $this->log($contract, $branch, $before, [], $user);
            }
            return null;
        }

        $blank = fn ($key) => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;
        $needsRef = VehicleSfReference::reasonNeedsReference($reason);
        $created = null;

        $attrs = array_fill_keys(['reference_type', 'reference_contract_id', 'reference_label', 'ext_insurer', 'ext_contract_number',
            'ext_license_plate', 'ext_sf_class', 'holder_relation', 'holder_name', 'license_date', 'campaign_name', 'note'], null);

        // Fuehrerscheindatum: Grundlage des SF-Vorschlags - bei Fuehrerschein-
        // UND Bezugs-Gruenden erfasst, sonst nicht.
        if ($needsRef || in_array($reason, ['fuehrerschein_3', 'fuehrerschein_5'], true)) {
            $attrs['license_date'] = $this->date($blank('license_date'));
        }
        if ($reason === 'sonderaktion') $attrs['campaign_name'] = $blank('campaign_name');
        if (in_array($reason, ['sonstige', 'firmenfahrzeug'], true) || $needsRef) $attrs['note'] = $blank('note');

        if ($needsRef) {
            $type = $blank('reference_type');
            if ($type === VehicleSfReference::TYPE_INTERNAL && $blank('reference_contract_id')) {
                $ref = $this->assertAllowedReference($contract, (string) $blank('reference_contract_id'), $user);
                $attrs['reference_type'] = VehicleSfReference::TYPE_INTERNAL;
                $attrs['reference_contract_id'] = $ref->id;
                $attrs['reference_label'] = VehicleSfReference::labelFor($ref);
            } elseif ($type === VehicleSfReference::TYPE_EXTERNAL && ($blank('ext_insurer') || $blank('ext_contract_number'))) {
                $sfClass = $blank('ext_sf_class');
                if ($sfClass !== null && ! in_array($sfClass, ContractVehicleDetail::sfClassKeys(), true)) $sfClass = null;
                if (! empty($data['create_external'])) {
                    $created = $this->createExternalContract($contract, $branch, $blank('ext_insurer'), $blank('ext_contract_number'), $blank('ext_license_plate'), $sfClass, $user);
                    $attrs['reference_type'] = VehicleSfReference::TYPE_INTERNAL;
                    $attrs['reference_contract_id'] = $created->id;
                    $attrs['reference_label'] = VehicleSfReference::labelFor($created);
                } else {
                    $attrs['reference_type'] = VehicleSfReference::TYPE_EXTERNAL;
                    $attrs['ext_insurer'] = $blank('ext_insurer');
                    $attrs['ext_contract_number'] = $blank('ext_contract_number');
                    $attrs['ext_license_plate'] = $blank('ext_license_plate') ? mb_strtoupper((string) $blank('ext_license_plate')) : null;
                    $attrs['ext_sf_class'] = $sfClass;
                }
            }
            $relation = $blank('holder_relation');
            $attrs['holder_relation'] = array_key_exists((string) $relation, VehicleSfReference::HOLDER_RELATIONS) ? $relation : null;
            $attrs['holder_name'] = in_array($attrs['holder_relation'], ['partner', 'familie', 'sonstige'], true) ? $blank('holder_name') : null;
        }

        // Nachweis: nur ein Dokument DIESES Kunden.
        $proof = $blank('proof_document_id');
        $attrs['proof_document_id'] = ($proof && Document::where('id', $proof)->where('customer_id', $contract->customer_id)->exists()) ? $proof : null;

        $row = $existing ?? new VehicleSfReference(['contract_vehicle_detail_id' => $detail->id, 'branch' => $branch]);
        $before = $existing ? $this->auditState($existing) : [];

        // Snapshot bei Gewaehrung: neu gesetzt, wenn sich der Bezug aendert
        // (oder noch keiner da ist) - nie bei jedem Speichern, sonst waere
        // er kein Stand "zum Zeitpunkt der Gewaehrung" mehr.
        $identityChanged = ! $existing
            || $existing->reference_type !== $attrs['reference_type']
            || $existing->reference_contract_id !== $attrs['reference_contract_id']
            || $existing->ext_contract_number !== $attrs['ext_contract_number']
            || $existing->ext_insurer !== $attrs['ext_insurer'];
        $row->fill($attrs);
        if ($attrs['reference_type'] === null) {
            $row->snapshot_sf_class = null;
            $row->snapshot_date = null;
        } elseif ($identityChanged || $row->snapshot_date === null) {
            $row->setRelation('referenceContract', $row->reference_contract_id ? Contract::with('vehicleDetail')->find($row->reference_contract_id) : null);
            $row->snapshot_sf_class = $row->currentReferenceSf();
            $validFrom = $branch === 'vollkasko' ? $detail->sf_comprehensive_valid_from : $detail->sf_liability_valid_from;
            $row->snapshot_date = $validFrom ? Carbon::parse($validFrom)->startOfDay() : now()->startOfDay();
        }

        // Geprueft: nur admin/manager duerfen den Stand aendern.
        if ($this->mayVerify($user)) {
            $wantVerified = ! empty($data['verified']) && $row->hasReference();
            if ($wantVerified && ! $row->verified) {
                $row->verified = true;
                $row->verified_by = $user?->id;
                $row->verified_at = now();
            } elseif (! $wantVerified && $row->verified) {
                $row->verified = false;
                $row->verified_by = null;
                $row->verified_at = null;
            }
        }
        // Ein geaenderter Bezug ist nicht mehr der gepruefte.
        if ($existing && $identityChanged && $row->verified) {
            $row->verified = false;
            $row->verified_by = null;
            $row->verified_at = null;
        }

        $row->save();
        $this->log($contract, $branch, $before, $this->auditState($row), $user);

        return $created;
    }

    public function mayVerify(?User $user): bool
    {
        return $user !== null && in_array($user->role, ['admin', 'manager'], true);
    }

    /**
     * Kunden, deren KFZ-Vertraege als Erstwagen in Frage kommen: der Kunde
     * selbst und seine verknuepfte Familie (beide Richtungen) - jeweils nur,
     * soweit der Bearbeiter sie sehen darf.
     *
     * @return list<string>
     */
    public function candidateCustomerIds(string $customerId, ?User $user): array
    {
        $ids = collect([$customerId])
            ->merge(CustomerFamilyRelation::where('customer_id', $customerId)->pluck('related_customer_id'))
            ->merge(CustomerFamilyRelation::where('related_customer_id', $customerId)->pluck('customer_id'))
            ->map(fn ($id) => (string) $id)
            ->unique();

        return $ids->filter(fn ($id) => $user === null || $user->canAccessCustomer($id))->values()->all();
    }

    /** @return Builder<Contract> */
    public function candidateQuery(Contract|string $contractOrCustomer, ?User $user): Builder
    {
        $customerId = $contractOrCustomer instanceof Contract ? (string) $contractOrCustomer->customer_id : $contractOrCustomer;
        $query = Contract::query()
            ->with(['vehicleDetail', 'customer.user'])
            ->where('type', 'kfz')
            ->whereIn('customer_id', $this->candidateCustomerIds($customerId, $user));
        if ($contractOrCustomer instanceof Contract && $contractOrCustomer->exists) {
            $query->where('id', '!=', $contractOrCustomer->id);
        }
        return $query;
    }

    /** Prueft Zugehoerigkeit, Selbst- und Kreisbezug. */
    public function assertAllowedReference(Contract $contract, string $referenceId, ?User $user): Contract
    {
        if ($referenceId === (string) $contract->id) {
            throw ValidationException::withMessages(['vehicle.sf_ref' => 'Ein Vertrag kann nicht sein eigenes Bezugsfahrzeug sein.']);
        }
        $ref = $this->candidateQuery($contract, $user)->where('id', $referenceId)->first();
        if (! $ref) {
            throw ValidationException::withMessages(['vehicle.sf_ref' => 'Das gewählte Bezugsfahrzeug gehört nicht zu diesem Kunden oder seiner Familie (oder ist kein KFZ-Vertrag).']);
        }
        if ($this->leadsBackTo($ref->id, (string) $contract->id)) {
            throw ValidationException::withMessages(['vehicle.sf_ref' => 'Zirkelbezug: '.VehicleSfReference::labelFor($ref).' stützt seine Einstufung bereits (direkt oder über weitere Verträge) auf diesen Vertrag.']);
        }
        return $ref;
    }

    /** Fuehrt die Bezugskette ab $startId irgendwann zu $targetId? */
    public function leadsBackTo(string $startId, string $targetId): bool
    {
        $seen = [];
        $queue = [$startId];
        $steps = 0;
        while ($queue && $steps++ < self::MAX_CHAIN) {
            $current = array_shift($queue);
            if ($current === $targetId) return true;
            if (isset($seen[$current])) continue;
            $seen[$current] = true;
            $next = VehicleSfReference::query()
                ->join('contract_vehicle_details', 'contract_vehicle_details.id', '=', 'vehicle_sf_references.contract_vehicle_detail_id')
                ->where('contract_vehicle_details.contract_id', $current)
                ->whereNotNull('vehicle_sf_references.reference_contract_id')
                ->pluck('vehicle_sf_references.reference_contract_id')
                ->map(fn ($id) => (string) $id)->all();
            array_push($queue, ...$next);
        }
        return false;
    }

    /**
     * Erstwagen bei einem anderen Versicherer als schlanken Fremdvertrag
     * anlegen - damit er im Bestand steht und beim naechsten Mal gewaehlt
     * werden kann. Fremdvertrag = keine Courtage, kein Eigenbestand.
     * Ein gleichnamiger Vertrag desselben Kunden wird wiederverwendet.
     */
    private function createExternalContract(Contract $contract, string $branch, ?string $insurer, ?string $number, ?string $plate, ?string $sfClass, ?User $user): Contract
    {
        if ($number !== null) {
            $same = Contract::where('customer_id', $contract->customer_id)->where('type', 'kfz')
                ->where('contract_number', $number)->get()
                ->first(fn (Contract $c) => Contract::insurersLookAlike($c->insurer, $insurer));
            if ($same) return $same;
        }

        $new = Contract::create([
            'id' => (string) Str::uuid(),
            'customer_id' => $contract->customer_id,
            'type' => 'kfz',
            'insurer' => $insurer ?: 'Unbekannter Versicherer',
            'contract_number' => $number,
            'status' => 'active',
            'origin' => Contract::ORIGIN_EXTERNAL,
            'origin_verified' => false,
            'premium_interval' => 'monthly',
            'notes' => 'Automatisch als Bezugsfahrzeug (Erstwagen) für die SF-Sondereinstufung von '.VehicleSfReference::labelFor($contract).' angelegt. Nur Dokumentation – nicht über uns vermittelt.',
            'added_by' => $user?->name,
        ]);
        ContractVehicleDetail::create([
            'contract_id' => $new->id,
            'vehicle_type' => 'pkw',
            'license_plate' => $plate ? mb_strtoupper($plate) : null,
            'sf_liability_class' => $branch === 'haftpflicht' ? $sfClass : null,
            'sf_liability_type' => $branch === 'haftpflicht' && $sfClass ? 'tatsaechlich' : null,
            'sf_comprehensive_class' => $branch === 'vollkasko' ? $sfClass : null,
            'sf_comprehensive_type' => $branch === 'vollkasko' && $sfClass ? 'tatsaechlich' : null,
            'has_teilkasko' => $branch === 'vollkasko' && $sfClass !== null,
            'has_vollkasko' => $branch === 'vollkasko' && $sfClass !== null,
        ]);
        ActivityLog::record('sf_reference_external_created', 'contract', $new->id, [
            'customer_id' => (string) $contract->customer_id,
            'for_contract_id' => (string) $contract->id,
            'branch' => $branch,
        ], $user?->id);

        return $new->fresh(['vehicleDetail']);
    }

    /** @return array<string,mixed> */
    private function auditState(VehicleSfReference $row): array
    {
        $state = [];
        foreach (self::AUDITED as $key) {
            $value = $row->getAttribute($key);
            $state[$key] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        return $state;
    }

    private function log(Contract $contract, string $branch, array $before, array $after, ?User $user): void
    {
        $changed = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ((string) json_encode($old) !== (string) json_encode($new)) {
                $changed[$key] = ['alt' => $old, 'neu' => $new];
            }
        }
        if (! $changed) return;

        ActivityLog::record('sf_reference_changed', 'contract', $contract->id, [
            'customer_id' => (string) $contract->customer_id,
            'branch' => $branch,
            'changes' => $changed,
        ], $user?->id);

        $text = function (array $state): ?string {
            if (! $state) return null;
            $ref = $state['reference_label'] ?? null;
            if (! $ref && ($state['ext_insurer'] ?? $state['ext_contract_number'] ?? null)) {
                $ref = trim(($state['ext_insurer'] ?? '').' '.($state['ext_contract_number'] ?? '')).' (extern)';
            }
            $parts = array_filter([
                $ref ? 'Bezug: '.$ref : null,
                ($state['ext_sf_class'] ?? null) ? 'SF Erstwagen: '.$state['ext_sf_class'] : null,
                ($state['snapshot_sf_class'] ?? null) ? 'SF bei Gewährung: '.$state['snapshot_sf_class'] : null,
                ($state['holder_relation'] ?? null) ? 'Halter: '.(VehicleSfReference::HOLDER_RELATIONS[$state['holder_relation']] ?? $state['holder_relation']) : null,
                ($state['license_date'] ?? null) ? 'Führerschein seit '.$state['license_date'] : null,
                ($state['campaign_name'] ?? null) ? 'Aktion: '.$state['campaign_name'] : null,
                ! empty($state['verified']) ? 'geprüft' : null,
            ]);
            return $parts ? Str::limit(implode(' · ', $parts), 250) : 'ohne Angaben';
        };

        ContractRevision::create([
            'contract_id' => $contract->id,
            'batch_id' => (string) Str::uuid(),
            'field' => 'sf_reference_'.$branch,
            'label' => 'SF-Bezug '.(VehicleSfReference::BRANCHES[$branch] ?? $branch),
            'old_value' => $text($before),
            'new_value' => $after ? $text($after) : 'entfernt',
            'source' => 'manual',
            'changed_by' => $user?->id,
        ]);
    }

    private function date(?string $value): ?string
    {
        if ($value === null) return null;
        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
