<?php
namespace App\Services;

use App\Mail\EscooterRenewalMail;
use App\Models\Contract;
use App\Models\ContractSwitchReminder;
use App\Models\EmailLog;
use App\Support\EscooterInsurance;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Jaehrliche E-Scooter-Erneuerungs-Erinnerung (Betreiber-Vorgabe 25.07.2026).
 *
 * Das Versicherungskennzeichen laeuft immer Ende Februar aus; ab dem 1. Maerz
 * braucht der Kunde ein neues. Anfang Februar (Fenster: 1.2. bis Saison-Ende)
 * bekommt jeder aktive E-Scooter-Vertrag EINMAL pro Saison eine Erinnerung,
 * bitte zu bestaetigen, ob der Roller noch gefahren wird - dann stellen wir ein
 * neues Kennzeichen (gueltig ab 01.03.) aus.
 *
 * Idempotenz ueber die vorhandene contract_switch_reminders-Tabelle mit
 * stage='renewal' und anchor=Saison-Ende: der Unique-Index (contract, stage,
 * anchor) verhindert Doppelversand ueber verpasste/mehrfache Cron-Laeufe.
 * Da die Saison eines E-Scooter-Vertrags immer Ende Februar endet, liegt das
 * Fenster naturgemaess nur im Februar - kein zusaetzlicher Monats-Filter noetig.
 *
 * Transaktionale Service-Mail: der Kunde braucht das neue Kennzeichen, um
 * weiter legal fahren zu duerfen. Daher KEIN Marketing-Consent noetig, nur eine
 * echte (nicht interne) E-Mail-Adresse.
 */
class EscooterRenewalReminderService
{
    private const STAGE = 'renewal';

    /**
     * Alle faelligen Erinnerungen versenden.
     * $visibleCustomerIds: null = alle (Cron); sonst Beschraenkung auf die dem
     * Berater zugewiesenen Kunden. Rueckgabe: Anzahl versendeter Mails.
     */
    public function run(?array $visibleCustomerIds = null): int
    {
        $sent = 0;
        foreach ($this->due($visibleCustomerIds) as [$contract, $anchor]) {
            $sent += $this->send($contract, $anchor) ? 1 : 0;
        }
        return $sent;
    }

    /**
     * Faellige (Vertrag, Anchor)-Paare ermitteln, ohne zu senden.
     *
     * @return array<int, array{0: Contract, 1: string}>
     */
    public function due(?array $visibleCustomerIds = null): array
    {
        $today = now()->startOfDay();
        $due = [];

        $contracts = Contract::with(['customer.user', 'vehicleDetail', 'switchReminders'])
            ->where('type', 'escooter')
            ->where('status', 'active')
            ->when($visibleCustomerIds !== null, fn($q) => $q->whereIn('customer_id', $visibleCustomerIds))
            ->get();

        foreach ($contracts as $contract) {
            // Saison-Ende (Ende Februar) - bevorzugt aus end_date, sonst aus dem
            // Beginn ueber die zentrale Fachregel berechnet.
            $seasonEnd = $contract->end_date
                ? Carbon::parse($contract->end_date)->startOfDay()
                : ($contract->start_date
                    ? Carbon::parse(EscooterInsurance::seasonEndDate($contract->start_date))->startOfDay()
                    : null);
            if ($seasonEnd === null) continue;

            // Fenster: 1. Februar der Saison bis zum Saison-Ende.
            $windowStart = $seasonEnd->copy()->startOfMonth();
            if ($today->lt($windowStart) || $today->gt($seasonEnd)) continue;

            $anchor = $seasonEnd->toDateString();
            $already = $contract->switchReminders
                ->first(fn($r) => $r->stage === self::STAGE && $r->anchor->toDateString() === $anchor);
            if ($already !== null) continue;

            $due[] = [$contract, $anchor];
        }

        return $due;
    }

    private function send(Contract $contract, string $anchor): bool
    {
        $customer = $contract->customer;
        // Transaktionale Mail: nur eine echte, nicht interne Adresse noetig
        // (kein Marketing-Consent).
        if (!$customer?->user?->hasRealEmail()) return false;

        try {
            // Erst protokollieren: Der Unique-Index faengt parallele Laeufe ab,
            // bevor eine Mail rausgeht.
            $reminder = ContractSwitchReminder::create([
                'contract_id' => $contract->id,
                'stage' => self::STAGE,
                'anchor' => $anchor,
                'sent_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return false;
        }

        try {
            Mail::to($customer->user->email)->send(new EscooterRenewalMail(
                $contract,
                route('unsubscribe', $customer->unsubscribeToken()),
            ));
            $status = 'sent';
        } catch (\Throwable $e) {
            // Protokoll zuruecknehmen, damit der naechste Lauf es erneut versucht.
            $reminder->delete();
            $status = 'failed';
            Log::warning("E-Scooter-Erneuerung {$contract->id} an {$customer->user->email} fehlgeschlagen: " . $e->getMessage());
        }

        EmailLog::create([
            'campaign_id' => null,
            'user_id' => $customer->user_id,
            'email' => $customer->user->email,
            'subject' => 'E-Scooter-Erneuerung (Kennzeichen)',
            'type' => 'escooter_renewal',
            'status' => $status,
        ]);

        return $status === 'sent';
    }
}
