<?php

namespace App\Services\Kfz;

use App\Models\Contract;
use App\Models\ContractVehicleDetail;
use App\Models\Task;
use App\Models\User;
use App\Models\VehicleSfReference;
use App\Services\Notifications\NotificationService;
use App\Services\Workflow\SystemUserResolver;
use Illuminate\Support\Facades\Log;

/**
 * Meldet dem Betreuer eines Zweitwagens, wenn sich am ERSTWAGEN etwas
 * aendert, das die Sondereinstufung beruehren kann (Regel
 * SF-BEZUG-BENACHRICHTIGUNG): Rueckstufung, Vertrag nicht mehr aktiv,
 * Vertrag geloescht. Die jaehrliche Hoeherstufung loest bewusst NICHTS aus -
 * sonst entstuenden jedes Jahr Aufgaben ohne Handlungsbedarf.
 *
 * Je Ereignis: eine Glocke an die Betreuer (dedup_key) und EINE offene
 * Aufgabe am Zweitwagen-Vertrag (keine zweite, solange sie offen ist).
 * Das Melden darf den ausloesenden Vorgang nie scheitern lassen.
 */
class SfReferenceNotifier
{
    public const EVENT_DOWNGRADE = 'rueckstufung';
    public const EVENT_INACTIVE = 'nicht_mehr_aktiv';
    public const EVENT_DELETED = 'geloescht';

    /** SF des Erstwagens hat sich in einer Sparte verschlechtert. */
    public function sfChanged(ContractVehicleDetail $detail, string $branch, ?string $old, ?string $new): void
    {
        if (! ContractVehicleDetail::isDowngrade($old, $new)) return; // Hoeherstufung/gleich: nichts
        $this->safely(function () use ($detail, $branch, $old, $new) {
            $erstwagen = $detail->contract;
            if (! $erstwagen) return;
            $text = 'SF '.(VehicleSfReference::BRANCHES[$branch] ?? $branch).' des Erstwagens wurde zurückgestuft: '
                .ContractVehicleDetail::sfLabel($old).' → '.ContractVehicleDetail::sfLabel($new).'.';
            $this->notifyDependents($erstwagen, self::EVENT_DOWNGRADE.'-'.$branch.'-'.$new, $text, $branch);
        });
    }

    public function contractInactive(Contract $erstwagen): void
    {
        $this->safely(fn () => $this->notifyDependents(
            $erstwagen,
            self::EVENT_INACTIVE,
            'Der Erstwagen ist nicht mehr aktiv ('.$erstwagen->displayStatus()['label'].').'
        ));
    }

    public function contractDeleted(Contract $erstwagen): void
    {
        $this->safely(fn () => $this->notifyDependents($erstwagen, self::EVENT_DELETED, 'Der Erstwagen wurde gelöscht.'));
    }

    private function notifyDependents(Contract $erstwagen, string $event, string $what, ?string $branch = null): void
    {
        $refs = VehicleSfReference::with('vehicleDetail.contract.customer')
            ->where('reference_contract_id', $erstwagen->id)
            ->when($branch, fn ($q) => $q->where('branch', $branch))
            ->get();

        foreach ($refs->groupBy(fn ($r) => $r->vehicleDetail?->contract_id) as $contractId => $group) {
            $zweitwagen = $group->first()->vehicleDetail?->contract;
            if (! $zweitwagen || ! $zweitwagen->customer) continue;

            $erstLabel = VehicleSfReference::labelFor($erstwagen);
            $zweitLabel = VehicleSfReference::labelFor($zweitwagen);
            $title = 'SF-Sondereinstufung prüfen: '.$zweitLabel;
            $body = $what.' Erstwagen: '.$erstLabel.'. Die Sondereinstufung von '.$zweitLabel.' beruht darauf.';

            $recipients = $zweitwagen->customer->betreuer()->pluck('users.id');
            if ($recipients->isEmpty()) {
                $recipients = User::whereIn('role', ['admin', 'manager'])->where('is_active', true)->pluck('id');
            }
            app(NotificationService::class)->pushMany($recipients, [
                'type' => NotificationService::TYPE_SYSTEM,
                'title' => $title,
                'body' => $body,
                'link' => route('admin.contract.edit', $zweitwagen->id),
                'dedup_key' => 'sf-erstwagen-'.$event.'-'.$zweitwagen->id.'-'.$erstwagen->id,
            ]);

            $assignee = $zweitwagen->customer->betreuerPrimary()->id ?? $recipients->first();
            $offen = Task::where('contract_id', $zweitwagen->id)->where('status', '!=', 'done')
                ->where('title', $title)->exists();
            if ($assignee && ! $offen) {
                Task::create([
                    'assigned_to' => $assignee,
                    'created_by' => app(SystemUserResolver::class)->resolveId(),
                    'customer_id' => $zweitwagen->customer_id,
                    'contract_id' => $zweitwagen->id,
                    'title' => $title,
                    'description' => $body.' Bitte beim Versicherer klären, ob die Sondereinstufung bestehen bleibt.',
                    'type' => 'follow_up',
                    'status' => 'open',
                    'priority' => 'high',
                    'due_date' => now()->addDays(7)->toDateString(),
                ]);
            }
        }
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('SF-Bezug: Benachrichtigung fehlgeschlagen: '.$e->getMessage());
        }
    }
}
