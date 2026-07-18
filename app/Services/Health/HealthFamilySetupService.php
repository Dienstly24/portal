<?php
namespace App\Services\Health;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerFamily;
use App\Services\ContractHistoryService;
use Carbon\CarbonImmutable;

/**
 * Richtet nach der Mitarbeiter-Entscheidung den Krankenkassen-Fall ein
 * (Betreiber-Ablauf "Familie + Kassenwechsel"):
 *
 * - Der HAUPTVERSICHERTE ist der Kunde; die uebrigen Personen werden als
 *   Familienmitglieder angelegt (Status je Person: familienversichert oder
 *   eigenes Mitglied - die UI fragt den Mitarbeiter, Vorschlag: Vater).
 * - Das Wirksamkeitsdatum kommt aus HealthInsuranceSwitchCalculator
 *   (new_job sofort; wechsel/sonder: 1. des Monats +3).
 * - Bis zum Wirksamkeitsdatum bleibt der Kunde bei der ALTEN Kasse
 *   (health_insurance_company); der neue Vertrag steht auf 'pending' und wird
 *   am Stichtag automatisch aktiv (Befehl health:apply-due-switches).
 * - Der Verlauf (contract_histories) haelt fest: alte Kasse bis Stichtag-1,
 *   neue Kasse ab Stichtag, inkl. Grund und Rolle.
 */
class HealthFamilySetupService
{
    public function __construct(
        private readonly HealthInsuranceSwitchCalculator $calculator,
        private readonly ContractHistoryService $history,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $persons  Personen des Vorgangs (FamilyBundleService)
     * @param array{haupt_index:int,members?:list<array{index:int,status?:string,relation?:string}>,
     *              switch_reason:string,job_start?:?string,old_insurer?:?string,new_insurer:string,
     *              submitted_at?:?string,source_document_id?:?string,created_by?:?int} $options
     * @return array{effective_date:string,contract_id:string,family_created:int,is_sonder:bool}
     */
    public function setup(Customer $customer, array $persons, array $options): array
    {
        $reason = $options['switch_reason'];
        if (!$this->calculator->isValidReason($reason)) {
            throw new \InvalidArgumentException('Unbekannter Wechsel-Grund: ' . $reason);
        }

        $submitted = CarbonImmutable::parse($options['submitted_at'] ?? now()->toDateString());
        $jobStart = filled($options['job_start'] ?? null) ? CarbonImmutable::parse($options['job_start']) : null;
        $effective = $this->calculator->effectiveDate($submitted, $reason, $jobStart);

        $haupt = $persons[$options['haupt_index']] ?? [];
        $oldInsurer = $options['old_insurer'] ?? ($haupt['company'] ?? null);
        $newInsurer = $options['new_insurer'];

        // 1) Gesundheitsdaten des Kunden: KV-Nummer aus der Karte; die Kasse
        //    bleibt bis zum Stichtag die ALTE (Ist-Zustand).
        $updates = array_filter([
            'health_insurance_number' => blank($customer->health_insurance_number) ? ($haupt['health_insurance_number'] ?? null) : null,
            'health_insurance_company' => blank($customer->health_insurance_company) ? $oldInsurer : null,
        ], fn ($v) => filled($v));
        if ($updates !== []) {
            $customer->fill($updates)->save();
        }

        // 2) Neuer Kranken-Vertrag: pending bis zum Stichtag (der Befehl
        //    health:apply-due-switches aktiviert ihn und stellt die Kasse um).
        $contract = Contract::create([
            'customer_id' => $customer->id,
            'type' => 'krankenversicherung',
            'insurer' => $newInsurer,
            'status' => 'pending',
            'start_date' => $effective->toDateString(),
        ]);

        // 3) Verlauf: alte Kasse (laufend) + neue Kasse ab Stichtag - record()
        //    beendet den alten Zeitraum automatisch am Stichtag-1.
        if (filled($oldInsurer)) {
            $this->history->record([
                'customer_id' => (string) $customer->id,
                'branch' => 'krankenversicherung',
                'provider' => $oldInsurer,
                'role' => 'hauptversichert',
                'reason' => 'bestand',
                'created_by' => $options['created_by'] ?? null,
            ]);
        }
        $this->history->record([
            'customer_id' => (string) $customer->id,
            'contract_id' => (string) $contract->id,
            'branch' => 'krankenversicherung',
            'provider' => $newInsurer,
            'role' => 'hauptversichert',
            'effective_from' => $effective->toDateString(),
            'reason' => $reason,
            'source_document_id' => $options['source_document_id'] ?? null,
            'created_by' => $options['created_by'] ?? null,
        ]);

        // 4) Familienmitglieder anlegen (Mitarbeiter hat Status + Beziehung
        //    je Person entschieden; nicht ausgewaehlte werden uebersprungen).
        $created = 0;
        foreach (($options['members'] ?? []) as $member) {
            $person = $persons[$member['index'] ?? -1] ?? null;
            if ($person === null || (int) ($member['index'] ?? -1) === (int) $options['haupt_index']) {
                continue;
            }
            $status = in_array($member['status'] ?? null, array_keys(CustomerFamily::HEALTH_STATUS), true)
                ? $member['status'] : 'familienversichert';
            CustomerFamily::create([
                'customer_id' => $customer->id,
                'name' => trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')),
                'relation' => $member['relation'] ?? 'Familienmitglied',
                'birth_date' => $person['birth_date'] ?? null,
                'gender' => $person['gender'] ?? null,
                'health_insurance_status' => $status,
                'health_insurance_company' => $newInsurer,
                'health_insurance_number' => $person['health_insurance_number'] ?? null,
                'health_insurance_start' => $effective->toDateString(),
            ]);
            $created++;
        }

        // 5) Stichtag schon erreicht (new_job sofort): direkt umstellen.
        if ($effective->lessThanOrEqualTo(CarbonImmutable::parse(now()->toDateString()))) {
            $contract->update(['status' => 'active']);
            $customer->fill(['health_insurance_company' => $newInsurer])->save();
        }

        ActivityLog::create([
            'user_id' => $options['created_by'] ?? null,
            'action' => 'health_switch_setup',
            'entity_type' => 'customer',
            'entity_id' => $customer->id,
            'meta' => json_encode([
                'reason' => $reason,
                'sonder' => $this->calculator->isSonder($reason),
                'effective' => $effective->toDateString(),
                'from' => $oldInsurer,
                'to' => $newInsurer,
                'family_created' => $created,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'effective_date' => $effective->toDateString(),
            'contract_id' => (string) $contract->id,
            'family_created' => $created,
            'is_sonder' => $this->calculator->isSonder($reason),
        ];
    }
}
