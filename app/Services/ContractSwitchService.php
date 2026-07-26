<?php

namespace App\Services;

use App\Models\Contract;
use App\Services\DocumentIntake\ContractRevisionRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Versicherer-Wechsel automatisch verbuchen (Betreiber-Vorgabe 26.07.2026):
 * Im Maklergeschaeft endet ein Vertrag dadurch, dass fuer dasselbe Fahrzeug
 * ein NEUER Vertrag (anderer Versicherer) angelegt wird. Der Altvertrag
 * bekommt dann automatisch die Kuendigung erfasst:
 *   - Kuendigungsdatum (eingereicht) = heute; bei rueckwirkend erfasstem
 *     Wechsel der Wechseltag selbst (nie ein Datum in der Zukunft erfinden)
 *   - Ablauf = Beginn des neuen Vertrags (der Wechseltag ist das faktische
 *     Ende) - ein bereits erfasster FRUEHERER Ablauf bleibt unangetastet
 * Ergebnis in der Akte: saubere Kette "Gekuendigt zum X" -> "Aktiv ab X".
 * Den Status stellt der Tages-Job contracts:apply-endings am Tag X um
 * (ohne Provisions-Storno - die Verkaufs-Provision bleibt verdient).
 * Jede Anpassung steht als Eintrag in der Version History des Altvertrags.
 */
class ContractSwitchService
{
    public function __construct(private readonly ContractRevisionRecorder $recorder)
    {
    }

    /**
     * Kuendigung am Altvertrag erfassen, damit er zum Wechseltag endet.
     * $switchDate = Beginn des neuen Vertrags. Nur fehlende bzw. SPAETERE
     * Werte werden angepasst; $source ist 'system' (Admin-Formular) oder
     * 'document' (Dokumenten-Eingang) fuer die Version History.
     */
    public function recordCancellationForSwitch(Contract $old, Carbon $switchDate, string $source, ?int $byUserId = null): void
    {
        $switchDate = $switchDate->copy()->startOfDay();
        $proposed = [];

        if (empty($old->cancellation_date)) {
            $proposed['cancellation_date'] = Carbon::today()->min($switchDate)->toDateString();
        }

        $end = $old->end_date ? Carbon::parse($old->end_date)->startOfDay() : null;
        if (!$end || $end->greaterThan($switchDate)) {
            $proposed['end_date'] = $switchDate->toDateString();
        }

        if ($proposed === []) {
            return;
        }

        $fmt = fn ($v) => Carbon::parse($v)->format('d.m.Y');
        $this->recorder->apply($old, $old, $proposed, [
            'cancellation_date' => ['label' => 'Kündigungsdatum', 'format' => $fmt],
            'end_date' => ['label' => 'Ablauf', 'format' => $fmt],
        ], [
            'source' => $source,
            'changed_by' => $byUserId,
            'batch_id' => (string) Str::uuid(),
        ]);
    }
}
