<?php
namespace App\Services;

use App\Mail\ContractSwitchMail;
use App\Models\Contract;
use App\Models\ContractSwitchReminder;
use App\Models\EmailLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Wechsel-Erinnerungs-Engine (Verbesserungsplan Paket C, 2026-07-12).
 *
 * Geschäftsregel: Erinnerungen NUR für Kfz, Strom/Gas, Internet und
 * gesetzliche Krankenversicherung (subtype=gkv). Alle übrigen Sparten
 * (Hausrat, Haftpflicht, Rechtsschutz, ...) bewusst NIE - Bestandserhalt.
 *
 * Rechtsgrundlagen (geprüft 12.07.2026):
 * - Internet: §56 TKG - nach Erstlaufzeit jederzeit mit 1 Monat kündbar.
 * - Strom/Gas: §41b EnWG / faire Verbraucherverträge - wie Internet.
 * - Kfz: VVG/AKB - Jahresvertrag, Kündigungsfrist 1 Monat zum Ende des
 *   Versicherungsjahres (Kalenderjahr-Verträge: Stichtag 30.11.).
 * - GKV: §175 SGB V - 12 Monate Bindungsfrist; Wechsel wirksam zum
 *   Ablauf des übernächsten Kalendermonats (Antrag Monat M -> aktiv 1. des M+3).
 *
 * Fenster-Logik statt Stichtags-Logik: Ein Vertrag ist "fällig", solange
 * heute im Erinnerungsfenster liegt und noch keine Erinnerung dieser
 * Stufe für diese Vertragsperiode (anchor) protokolliert ist. So gehen
 * verpasste Cron-Tage und spät erfasste Verträge nicht verloren, und
 * Button + Cron können nie doppelt senden (Unique-Index).
 */
class ContractSwitchReminderService
{
    /** Fenster-Offsets vor end_date je Sparte. */
    private const END_DATE_RULES = [
        'internet'  => ['first' => '6 months', 'followup' => '3 months'],
        'strom_gas' => ['first' => '6 months', 'followup' => '3 months'],
        'kfz'       => ['first' => '2 months', 'followup' => '6 weeks'],
    ];

    /** Mindestabstand zwischen 1. und 2. Erinnerung (spät erfasste Verträge). */
    private const MIN_GAP_DAYS = 14;

    /** GKV: Folge-Erinnerung frühestens 3 Monate nach der ersten. */
    private const GKV_FOLLOWUP_AFTER = '3 months';

    /**
     * Alle fälligen Erinnerungen versenden.
     * $visibleCustomerIds: null = alle (Cron/Admin); sonst Beschränkung
     * auf die dem Berater zugewiesenen Kunden (Button im Backend).
     * Rückgabe: Anzahl versendeter Mails.
     */
    public function run(?array $visibleCustomerIds = null): int
    {
        $sent = 0;
        foreach ($this->due($visibleCustomerIds) as [$contract, $stage, $anchor]) {
            $sent += $this->send($contract, $stage, $anchor) ? 1 : 0;
        }
        return $sent;
    }

    /**
     * Fällige (Vertrag, Stufe, Anchor)-Tripel ermitteln, ohne zu senden.
     * Auch für die Kennzahl im E-Mail-Marketing-Dashboard genutzt.
     */
    public function due(?array $visibleCustomerIds = null): array
    {
        $today = now()->startOfDay();
        $due = [];

        // ---- End-Datum-basierte Sparten (Internet, Strom/Gas, Kfz) ----
        $contracts = Contract::with(['customer.user', 'switchReminders'])
            ->whereIn('type', array_keys(self::END_DATE_RULES))
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->when($visibleCustomerIds !== null, fn($q) => $q->whereIn('customer_id', $visibleCustomerIds))
            ->get();

        foreach ($contracts as $contract) {
            $rule = self::END_DATE_RULES[$contract->type];
            $end = Carbon::parse($contract->end_date)->startOfDay();
            $anchor = $end->toDateString();
            $firstStart = $end->copy()->sub($rule['first']);
            $followupStart = $end->copy()->sub($rule['followup']);
            // Kfz: nach dem Kündigungs-Stichtag (1 Monat vor Ablauf, z.B.
            // 30.11.) ist der Zug abgefahren - nicht mehr erinnern.
            // Internet/Energie bleiben bis zum Ablauf (danach ohnehin
            // monatlich kündbar, neue Periode = neuer Anchor).
            $deadline = $contract->type === 'kfz' ? $end->copy()->subMonth() : $end;

            if ($today->lt($firstStart) || $today->gt($deadline)) continue;

            $first = $contract->switchReminders->first(fn($r) => $r->stage === 'first' && $r->anchor->toDateString() === $anchor);
            $followup = $contract->switchReminders->first(fn($r) => $r->stage === 'followup' && $r->anchor->toDateString() === $anchor);

            if ($first === null) {
                $due[] = [$contract, 'first', $anchor];
            } elseif (
                $followup === null
                && $first->responded_at === null            // Kunde hat reagiert -> Schluss
                && $today->gte($followupStart)
                && $first->sent_at->copy()->addDays(self::MIN_GAP_DAYS)->lte($today)
            ) {
                $due[] = [$contract, 'followup', $anchor];
            }
        }

        // ---- GKV: Bindungsfrist-basiert (§175 SGB V), kein end_date nötig ----
        $gkv = Contract::with(['customer.user', 'switchReminders'])
            ->where('type', 'krankenversicherung')
            ->where('subtype', 'gkv')
            ->where('status', 'active')
            ->whereNotNull('start_date')
            ->when($visibleCustomerIds !== null, fn($q) => $q->whereIn('customer_id', $visibleCustomerIds))
            ->get();

        foreach ($gkv as $contract) {
            $eligible = Carbon::parse($contract->start_date)->startOfDay()->addMonths(12);
            if ($today->lt($eligible)) continue;
            $anchor = $eligible->toDateString();

            $first = $contract->switchReminders->first(fn($r) => $r->stage === 'first' && $r->anchor->toDateString() === $anchor);
            $followup = $contract->switchReminders->first(fn($r) => $r->stage === 'followup' && $r->anchor->toDateString() === $anchor);

            if ($first === null) {
                $due[] = [$contract, 'first', $anchor];
            } elseif (
                $followup === null
                && $first->responded_at === null
                && $first->sent_at->copy()->add(self::GKV_FOLLOWUP_AFTER)->lte($today)
            ) {
                $due[] = [$contract, 'followup', $anchor];
            }
        }

        return $due;
    }

    /**
     * Kunde hat sich gemeldet -> offene Erinnerungen dieses Vertrags als
     * beantwortet markieren; die Folge-Erinnerung entfällt damit.
     */
    public function markResponded(Contract $contract): int
    {
        return $contract->switchReminders()
            ->whereNull('responded_at')
            ->update(['responded_at' => now()]);
    }

    private function send(Contract $contract, string $stage, string $anchor): bool
    {
        $customer = $contract->customer;
        if (!$customer?->isMarketingReachable()) return false;

        try {
            // Erst protokollieren: Der Unique-Index fängt parallele Läufe
            // (Button + Cron gleichzeitig) ab, bevor eine Mail rausgeht.
            $reminder = ContractSwitchReminder::create([
                'contract_id' => $contract->id,
                'stage' => $stage,
                'anchor' => $anchor,
                'sent_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return false;
        }

        try {
            Mail::to($customer->user->email)->send(new ContractSwitchMail(
                $contract,
                $stage,
                route('unsubscribe', $customer->unsubscribeToken()),
            ));
            $status = 'sent';
        } catch (\Throwable $e) {
            // Protokoll zurücknehmen, damit der nächste Lauf es erneut versucht.
            $reminder->delete();
            $status = 'failed';
            Log::warning("Wechsel-Erinnerung {$contract->id} ({$stage}) an {$customer->user->email} fehlgeschlagen: " . $e->getMessage());
        }

        EmailLog::create([
            'campaign_id' => null,
            'user_id' => $customer->user_id,
            'email' => $customer->user->email,
            'subject' => "Wechsel-Erinnerung {$contract->type} ({$stage})",
            'type' => 'contract_switch',
            'status' => $status,
        ]);

        return $status === 'sent';
    }
}
