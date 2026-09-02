<?php
namespace App\Services\Provisionsmanagement;

use App\Models\Contract;
use App\Models\ContractCommission;
use App\Services\CommissionImport\CommissionAuditLogger;
use App\Support\CommissionKind;
use App\Support\CommissionStatus;
use App\Support\ContractCommissionStatus as Zustand;
use Illuminate\Support\Carbon;

/**
 * Berechnet den PROVISIONS-ZUSTAND eines Vertrags aus seinen Buchungen und
 * den Fristen seines Pools (Betreiber-Auftrag 02.09.2026, §17/§18/§20).
 *
 * WARUM ABGELEITET STATT GEPFLEGT: Der Zustand aendert sich, ohne dass
 * jemand etwas tut - allein dadurch, dass Zeit vergeht oder eine Abrechnung
 * eingeht. Eine von Hand gepflegte Spalte waere ab dem ersten Tag falsch.
 * Deshalb ist diese Klasse die EINZIGE Stelle, die `commission_status`
 * schreibt, und sie tut es aus Fakten: Buchungen, Datum, Frist.
 *
 * WELCHE VERTRAEGE UEBERHAUPT: nur solche, die einem Pool zugeordnet sind
 * ODER bereits eine Provision tragen. Ein Vertrag ohne beides ist kein Fall
 * fuer das Provisionsmanagement - stuende er trotzdem drin, meldete die
 * Liste "Provision fehlt" fuer den halben Bestand und waere damit wertlos.
 *
 * DIE FRIST BEGINNT AM ABSCHLUSS, nicht am Lieferbeginn: der Pool rechnet ab
 * dem Tag ab, an dem der Vertrag zustande kam. Fehlt jedes Datum, wird
 * NICHTS geraten - der Vertrag bleibt `neu` und faellt nie in eine Mahnliste.
 */
class CommissionStatusEngine
{
    public function __construct(
        private PoolRegistry $pools,
        private CommissionAuditLogger $audit,
    ) {
    }

    /**
     * Zustand eines Vertrags neu berechnen und speichern.
     *
     * @param iterable<ContractCommission>|null $commissions vorgeladene Buchungen (spart Abfragen im Stapel)
     * @return bool ob sich etwas geaendert hat
     */
    public function refresh(Contract $contract, ?iterable $commissions = null, bool $log = true): bool
    {
        $commissions ??= ContractCommission::where('contract_id', $contract->id)->get();
        $list = collect($commissions);

        $expected = $this->expectedDate($contract);
        $check = $this->checkDate($contract);
        $status = $this->determine($contract, $list, $expected, $check);

        $changed = $contract->expected_commission_date?->toDateString() !== $expected?->toDateString()
            || $contract->commission_check_date?->toDateString() !== $check?->toDateString()
            || $contract->commission_status !== $status;

        if (! $changed) {
            return false;
        }

        $before = $contract->commission_status;
        $contract->expected_commission_date = $expected;
        $contract->commission_check_date = $check;
        $contract->commission_status = $status;
        $contract->commission_status_at = now();
        // saveQuietly: die Neuberechnung ist Buchhaltung, kein
        // Vertragsereignis - sie darf keine Modell-Hooks (Provisionen,
        // Glocken, Wechsel-Automatik) ausloesen.
        $contract->saveQuietly();

        if ($log && $before !== $status) {
            $this->audit->log('provisionsstatus_geaendert', null, [
                'contract_id' => $contract->id,
                'field' => 'commission_status',
                'old_value' => $before === null ? null : Zustand::label($before),
                'new_value' => Zustand::label($status),
            ]);
        }
        return true;
    }

    /**
     * Alle betroffenen Vertraege neu bewerten (taeglicher Lauf).
     *
     * @param array<int,string>|null $contractIds nur diese Vertraege
     * @return array{geprueft:int,geaendert:int}
     */
    public function refreshAll(?array $contractIds = null): array
    {
        $geprueft = 0;
        $geaendert = 0;

        $query = Contract::query()->when(
            $contractIds !== null,
            fn ($q) => $q->whereIn('id', $contractIds),
            fn ($q) => $q->where(function ($w) {
                $w->whereNotNull('pool')
                  ->orWhereIn('id', ContractCommission::query()->whereNotNull('contract_id')->select('contract_id'));
            })
        );

        $query->orderBy('id')->chunkById(300, function ($chunk) use (&$geprueft, &$geaendert) {
            $buchungen = ContractCommission::whereIn('contract_id', $chunk->pluck('id'))
                ->get()->groupBy('contract_id');
            foreach ($chunk as $contract) {
                $geprueft++;
                if ($this->refresh($contract, $buchungen[$contract->id] ?? collect())) {
                    $geaendert++;
                }
            }
        });

        return ['geprueft' => $geprueft, 'geaendert' => $geaendert];
    }

    /** Der Tag, an dem der Vertrag zustande kam (Beginn der Frist). */
    public function closingDate(Contract $contract): ?Carbon
    {
        foreach (['signing_date', 'application_date', 'start_date'] as $feld) {
            $wert = $contract->{$feld} ?? null;
            if ($wert) {
                return Carbon::parse($wert)->startOfDay();
            }
        }
        // Kein Fachdatum: das Anlagedatum ist die ehrlichste Naeherung, die
        // wir haben - es steht IMMER und ist nie in der Zukunft.
        return $contract->created_at ? Carbon::parse($contract->created_at)->startOfDay() : null;
    }

    public function expectedDate(Contract $contract): ?Carbon
    {
        $start = $this->closingDate($contract);
        return $start?->copy()->addMonthsNoOverflow($this->pools->expectedMonths($contract->pool));
    }

    public function checkDate(Contract $contract): ?Carbon
    {
        $start = $this->closingDate($contract);
        return $start?->copy()->addMonthsNoOverflow($this->pools->checkMonths($contract->pool));
    }

    /**
     * Die eigentliche Regel. Reihenfolge ist Absicht - der erste Treffer
     * gewinnt, und die teuersten Faelle stehen oben: eine unklare Buchung
     * oder ein Storno soll nie hinter einem "erhalten" verschwinden.
     *
     * @param \Illuminate\Support\Collection<int,ContractCommission> $list
     */
    private function determine(Contract $contract, $list, ?Carbon $expected, ?Carbon $check): string
    {
        if ($list->isNotEmpty()) {
            $netto = round((float) $list->sum(fn ($c) => (float) $c->amount), 2);
            $hatStorno = $list->contains(fn ($c) => $c->status === CommissionStatus::STORNIERT
                || $c->commission_kind === CommissionKind::STORNO);
            $hatKorrektur = $list->contains(fn ($c) => $c->commission_kind === CommissionKind::KORREKTUR);
            $hatUnklar = $list->contains(fn ($c) => $c->status === CommissionStatus::UNKLAR);

            if ($hatUnklar) {
                return Zustand::PRUEFUNG;
            }
            // Storniert heisst: unter dem Strich ist nichts (oder weniger als
            // nichts) geblieben. Ein Storno neben einer groesseren
            // Abschlussprovision ist dagegen nur eine Teilrueckbuchung - der
            // Vertrag ist weiter verguetet.
            if ($hatStorno && $netto <= 0.0) {
                return Zustand::STORNIERT;
            }
            if ($hatKorrektur) {
                return Zustand::KORREKTUR;
            }
            $laufend = $list->contains(fn ($c) => in_array($c->commission_kind, [
                CommissionKind::FOLGE, CommissionKind::LAUFEND, CommissionKind::BESTAND,
            ], true));
            if ($laufend) {
                return Zustand::LAUFEND;
            }
            $offen = $list->contains(fn ($c) => CommissionStatus::isOutstanding($c->status));
            return $offen ? Zustand::ERHALTEN : Zustand::VOLLSTAENDIG;
        }

        // Keine Buchung: eine bewusste menschliche Entscheidung bleibt
        // stehen ("der Pool zahlt hier nicht, Sache erledigt").
        if (in_array($contract->commission_status, Zustand::MANUELL, true)) {
            return $contract->commission_status;
        }
        if ($expected === null || $check === null) {
            return Zustand::NEU;
        }

        $heute = Carbon::today();
        if ($heute->lt($expected)) {
            return Zustand::ERWARTET;
        }
        if ($heute->lt($check)) {
            return Zustand::UEBERFAELLIG;
        }
        return Zustand::FEHLT;
    }
}
