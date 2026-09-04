<?php

namespace App\Services;

use App\Mail\SchutzbriefRenewalMail;
use App\Models\Contract;
use App\Models\ContractSwitchReminder;
use App\Models\EmailLog;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Jaehrliche Schutzbrief-/Mobilclub-Verlaengerungs-Erinnerung (Betreiber-
 * Vorgabe 28.07.2026, gilt fuer ALLE Vertraege der Sparte schutzbrief, z.B.
 * die ADAC-Mitgliedschaft).
 *
 * Solche Mitgliedschaften verlaengern sich automatisch um ein Jahr, und die
 * Kuendigung ist NUR bis 3 Monate vor dem Verlaengerungs-Stichtag moeglich -
 * danach laeuft der Vertrag ein weiteres Jahr. Damit der Kunde genug
 * Bedenkzeit hat, beginnt die Erinnerung bereits 5 Monate vor dem Stichtag
 * (= 7 Monate nach Beginn) und wird bis zum LETZTEN Kuendigungstag
 * (Stichtag - 3 Monate) nachgeholt; nach der Frist wird bewusst NICHT mehr
 * erinnert (eine Kuendigung ist dann nicht mehr moeglich). Die Mail nennt
 * beide Daten und erklaert, was der Schutzbrief leistet (Pannenhilfe,
 * Abschleppen, Weiter-/Rueckreise ...), damit der Kunde versteht, was er im
 * Fall einer Kuendigung aufgibt - EINMAL pro Jahr.
 *
 * Der Verlaengerungs-Stichtag ist der JAHRESTAG des Ablaufdatums (bzw.
 * Beginn + 1 Jahr, wenn kein Ablauf erfasst ist) - ein vergangenes Datum wird
 * jahrweise in die Zukunft projiziert, denn ein blosses Ablaufdatum ist KEIN
 * Vertragsende (stillschweigende Verlaengerung); so erinnert das System jedes
 * Jahr aufs Neue, ohne dass jemand das Datum pflegen muss.
 *
 * Idempotenz ueber contract_switch_reminders (stage='schutzbrief_renewal',
 * anchor=Verlaengerungs-Stichtag): der Unique-Index verhindert Doppelversand
 * auch ueber verpasste/mehrfache Cron-Laeufe hinweg. Transaktionale
 * Service-Mail (Vertragsverlaengerung) - noetig ist nur eine echte
 * E-Mail-Adresse, kein Marketing-Consent.
 */
class SchutzbriefRenewalReminderService
{
    private const STAGE = 'schutzbrief_renewal';

    /**
     * Beginn des Erinnerungsfensters vor dem Verlaengerungs-Stichtag (Monate):
     * 5 Monate vorher = 7 Monate nach Vertragsbeginn - genug Bedenkzeit vor
     * der Kuendigungsfrist.
     */
    private const LEAD_MONTHS = 5;

    /** Kuendigungsfrist: letzter Kuendigungstag = Stichtag - 3 Monate. */
    public const NOTICE_MONTHS = 3;

    /**
     * Alle faelligen Erinnerungen versenden. Rueckgabe: Anzahl versendeter Mails.
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
     * Faellige (Vertrag, Verlaengerungs-Stichtag)-Paare ermitteln, ohne zu senden.
     *
     * @return array<int, array{0: Contract, 1: string}>
     */
    public function due(?array $visibleCustomerIds = null): array
    {
        $today = now()->startOfDay();
        $due = [];

        $contracts = Contract::with(['customer.user', 'switchReminders'])
            ->where('type', 'schutzbrief')
            ->currentlyActive()
            ->when($visibleCustomerIds !== null, fn ($q) => $q->whereIn('customer_id', $visibleCustomerIds))
            ->get();

        foreach ($contracts as $contract) {
            $renewal = $this->nextRenewalDate($contract, $today);
            if ($renewal === null) {
                continue;
            }

            // Fenster: 5 Monate vor dem Stichtag (= 7 Monate nach Beginn) bis
            // zum LETZTEN Kuendigungstag (Stichtag - 3 Monate). Danach ist eine
            // Kuendigung nicht mehr moeglich - dann wird bewusst nicht mehr
            // erinnert. Ein spaeter Einstieg (Cron-Ausfall) holt die Erinnerung
            // innerhalb des Fensters nach.
            $windowStart = $renewal->copy()->subMonthsNoOverflow(self::LEAD_MONTHS);
            $windowEnd = $this->lastCancellationDate($renewal);
            if ($today->lt($windowStart) || $today->gt($windowEnd)) {
                continue;
            }

            $anchor = $renewal->toDateString();
            $already = $contract->switchReminders
                ->first(fn ($r) => $r->stage === self::STAGE && $r->anchor->toDateString() === $anchor);
            if ($already !== null) {
                continue;
            }

            $due[] = [$contract, $anchor];
        }

        return $due;
    }

    /**
     * Naechster Verlaengerungs-Stichtag: Jahrestag des Ablaufdatums (bzw.
     * Beginn + 1 Jahr), jahrweise in die Zukunft projiziert.
     */
    public function nextRenewalDate(Contract $contract, ?Carbon $today = null): ?Carbon
    {
        $today ??= now()->startOfDay();
        $renewal = $contract->end_date
            ? Carbon::parse($contract->end_date)->startOfDay()
            : ($contract->start_date ? Carbon::parse($contract->start_date)->addYear()->startOfDay() : null);
        if ($renewal === null) {
            return null;
        }
        while ($renewal->lt($today)) {
            $renewal->addYear();
        }
        return $renewal;
    }

    /**
     * Letzter Kuendigungstag: 3 Monate vor dem Verlaengerungs-Stichtag. Wer
     * spaeter kuendigt, bleibt ein weiteres Jahr im Vertrag.
     */
    public function lastCancellationDate(Carbon $renewal): Carbon
    {
        return $renewal->copy()->subMonthsNoOverflow(self::NOTICE_MONTHS);
    }

    private function send(Contract $contract, string $anchor): bool
    {
        $customer = $contract->customer;
        if (! $customer?->user?->hasRealEmail()) {
            return false;
        }

        try {
            // Erst protokollieren: der Unique-Index faengt parallele Laeufe ab,
            // bevor eine Mail rausgeht.
            $reminder = ContractSwitchReminder::create([
                'contract_id' => $contract->id,
                'stage' => self::STAGE,
                'anchor' => $anchor,
                'sent_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        try {
            $renewal = Carbon::parse($anchor);
            Mail::to($customer->user->email)->send(new SchutzbriefRenewalMail(
                $contract,
                $renewal,
                $this->lastCancellationDate($renewal),
                route('unsubscribe', $customer->unsubscribeToken()),
            ));
            $status = 'sent';
        } catch (\Throwable $e) {
            // Protokoll zuruecknehmen, damit der naechste Lauf es erneut versucht.
            $reminder->delete();
            $status = 'failed';
            Log::warning("Schutzbrief-Verlaengerung {$contract->id} an {$customer->user->email} fehlgeschlagen: ".$e->getMessage());
        }

        EmailLog::create([
            'campaign_id' => null,
            'user_id' => $customer->user_id,
            'email' => $customer->user->email,
            'subject' => 'Schutzbrief-Verlaengerung',
            'type' => 'schutzbrief_renewal',
            'status' => $status,
        ]);

        return $status === 'sent';
    }
}
