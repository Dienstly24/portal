<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\DocumentIntake\ContractRevisionRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Erreichte Vertragsenden automatisch anwenden (Betreiber-Vorgabe
 * 26.07.2026 - der gespeicherte Status soll der Realitaet folgen):
 *
 *  1. Gekuendigte Vertraege: ist das WIRKSAME Ende erreicht (siehe
 *     Contract::effectiveCancellationDate), wird status active -> cancelled
 *     gestellt. WICHTIG (Betreiber-Klarstellung 26.07.2026): das ist ein
 *     NATUERLICHES Vertragsende (z.B. Wechsel-Kette) - die einmalige
 *     Verkaufs-Provision des Werbers bleibt verdient, es wird KEIN
 *     Provisions-Storno gebucht (endsWithoutStorno). Storno gibt es nur
 *     bei Loeschung oder manueller Stornierung.
 *  2. E-Scooter enden fix zum Saisonende ("bedarf keiner Kuendigung"):
 *     nach dem Ablaufdatum wird status active -> expired gestellt.
 *
 * Laufende Vertraege ohne Kuendigung bleiben unangetastet: Versicherungen
 * verlaengern sich stillschweigend, ein blosses Ablaufdatum ist KEIN Ende.
 * Jede Umstellung landet als System-Eintrag in der Version History.
 */
class ApplyContractEndings extends Command
{
    protected $signature = 'contracts:apply-endings';

    protected $description = 'Erreichte Vertragsenden anwenden: gekuendigt zum X -> cancelled, E-Scooter nach Saisonende -> expired (natuerliches Ende, kein Provisions-Storno)';

    public function handle(ContractRevisionRecorder $recorder): int
    {
        $today = Carbon::today();
        $gekuendigt = 0;
        $abgelaufen = 0;

        $fehler = 0;

        // 1) Kuendigungen, deren wirksames Ende erreicht ist.
        // Pro Vertrag gekapselt: ein einzelner fehlerhafter Datensatz darf nicht
        // den gesamten Tageslauf (und damit alle folgenden Vertraege) stoppen
        // (Audit RESIL-1, Muster wie SendTaskAutoEmails::process).
        $mitKuendigung = Contract::where('status', 'active')
            ->whereNotNull('cancellation_date')->get();
        foreach ($mitKuendigung as $contract) {
            try {
                $ende = $contract->effectiveCancellationDate();
                if (!$ende || $ende->greaterThan($today)) {
                    continue;
                }
                $this->setStatus($recorder, $contract, 'cancelled');
                $gekuendigt++;
            } catch (\Throwable $e) {
                $fehler++;
                \Log::warning('contracts:apply-endings: Vertrag ' . $contract->id . ' uebersprungen: ' . $e->getMessage());
            }
        }

        // 2) E-Scooter nach Saisonende (nur ohne Kuendigungsfall).
        $escooter = Contract::where('status', 'active')->where('type', 'escooter')
            ->whereNull('cancellation_date')->whereNotNull('end_date')
            ->whereDate('end_date', '<', $today)->get();
        foreach ($escooter as $contract) {
            try {
                $this->setStatus($recorder, $contract, 'expired');
                $abgelaufen++;
            } catch (\Throwable $e) {
                $fehler++;
                \Log::warning('contracts:apply-endings: E-Scooter ' . $contract->id . ' uebersprungen: ' . $e->getMessage());
            }
        }

        $this->info('Umgestellt: ' . $gekuendigt . ' gekuendigt, ' . $abgelaufen . ' abgelaufen (E-Scooter).'
            . ($fehler > 0 ? ' ' . $fehler . ' uebersprungen (siehe Log).' : ''));
        return self::SUCCESS;
    }

    /** Statuswechsel inkl. Version-History-Eintrag (Quelle: System). */
    private function setStatus(ContractRevisionRecorder $recorder, Contract $contract, string $status): void
    {
        // Natuerliches Ende: einmalige Verkaufs-Provision bleibt verdient.
        $contract->endsWithoutStorno = true;
        $recorder->apply($contract, $contract, ['status' => $status], [
            'status' => ['label' => 'Status', 'format' => fn ($v) => Contract::STATUS_LABELS[$v] ?? (string) $v],
        ], [
            'source' => 'system',
            'changed_by' => null,
            'batch_id' => (string) Str::uuid(),
        ]);
    }
}
