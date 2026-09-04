<?php

namespace App\Services\Provisionsmanagement;

use App\Models\CommissionFollowup;
use App\Models\Contract;
use App\Models\Customer;
use App\Services\CommissionImport\CommissionAuditLogger;
use App\Support\ContractCommissionStatus as Zustand;
use Illuminate\Support\Carbon;

/**
 * FEHLENDE PROVISIONEN (§18) und ihr Bearbeitungsstand (§19).
 *
 * Die Liste beantwortet eine einzige Frage: welcher Vertrag ist abgeschlossen,
 * aber nicht verguetet - und wie lange schon? Sie wird bewusst NICHT aus
 * einer eigenen Tabelle gespeist, sondern aus dem Zustand am Vertrag: eine
 * zweite Liste liefe auseinander, sobald eine Provision nachtraeglich eingeht.
 *
 * Der FOLLOW-UP haengt daneben und ueberschreibt den Zustand nie. Er sagt,
 * was WIR getan haben ("Pool kontaktiert am ..."), nicht, was der Fall ist.
 * Nur "geklaert" wirkt auf den Zustand zurueck - das ist die bewusste
 * Entscheidung eines Menschen, hier keine Provision mehr zu erwarten.
 */
class MissingCommissionService
{
    public function __construct(
        private CommissionStatusEngine $engine,
        private CommissionAuditLogger $audit,
    ) {
    }

    /**
     * Abfrage der offenen Faelle mit den Filtern der Seite.
     *
     * @param array{pool?:?string,status?:?string,monat?:?string,produkt?:?string,mitarbeiter?:?string,kunde?:?string} $filter
     */
    public function query(array $filter = [])
    {
        $status = $filter['status'] ?? null;

        return Contract::query()
            ->with(['customer.user', 'commissionFollowup'])
            ->when(
                $status !== null && $status !== '' && Zustand::isValid($status),
                fn ($q) => $q->where('commission_status', $status),
                fn ($q) => $q->whereIn('commission_status', Zustand::OFFENE_FAELLE)
            )
            ->when(($filter['pool'] ?? null), fn ($q, $p) => $q->where('pool', $p))
            ->when(($filter['produkt'] ?? null), fn ($q, $p) => $q->where('insurer', 'like', '%'.$this->escape($p).'%'))
            ->when(($filter['monat'] ?? null), function ($q, $monat) {
                // Monat des Abschlusses (YYYY-MM) - dieselbe Datumsleiter
                // wie in der Statusberechnung, damit Liste und Zustand
                // dieselbe Zeitrechnung benutzen.
                $q->whereRaw('substr(COALESCE(signing_date, application_date, start_date, DATE(created_at)), 1, 7) = ?', [$monat]);
            })
            ->when(($filter['kunde'] ?? null), function ($q, $suche) {
                $q->whereIn('customer_id', Customer::search($suche)->select('id'));
            })
            ->when(($filter['mitarbeiter'] ?? null), fn ($q, $id) => $q->whereIn(
                'customer_id',
                Customer::where('acquired_by', $id)->select('id')
            ))
            ->orderBy('commission_check_date');
    }

    /** Wie viele Monate liegt der Abschluss zurueck? */
    public function monthsSinceClosing(Contract $contract): ?int
    {
        $start = $this->engine->closingDate($contract);
        return $start === null ? null : $start->diffInMonths(Carbon::today());
    }

    /**
     * Bearbeitungsstand setzen.
     *
     * @param array{status:string,contacted_on?:?string,contact_person?:?string,response?:?string,note?:?string} $daten
     */
    public function updateFollowup(Contract $contract, array $daten, ?int $userId = null): CommissionFollowup
    {
        $followup = CommissionFollowup::firstOrNew(['contract_id' => $contract->id]);
        $vorher = $followup->status;

        $followup->fill([
            'status' => array_key_exists($daten['status'] ?? '', CommissionFollowup::STATUSES)
                ? $daten['status'] : 'offen',
            'contacted_on' => $daten['contacted_on'] ?? $followup->contacted_on,
            'contact_person' => $daten['contact_person'] ?? $followup->contact_person,
            'response' => $daten['response'] ?? $followup->response,
            'note' => $daten['note'] ?? $followup->note,
            'updated_by' => $userId,
        ])->save();

        $this->audit->log('provision_nachverfolgung', null, [
            'contract_id' => $contract->id,
            'field' => 'followup_status',
            'old_value' => $vorher === null ? null : (CommissionFollowup::STATUSES[$vorher] ?? $vorher),
            'new_value' => $followup->statusLabel(),
        ]);

        // "Geklaert" heisst: hier wird nichts mehr erwartet. Der Zustand am
        // Vertrag folgt dieser Entscheidung, die taegliche Neuberechnung
        // laesst ihn danach in Ruhe (Zustand::MANUELL). Wird der Fall wieder
        // geoeffnet, rechnet sie ihn beim naechsten Lauf normal weiter.
        if ($followup->status === 'geklaert') {
            $this->setStatus($contract, Zustand::GEKLAERT);
        } elseif ($contract->commission_status === Zustand::GEKLAERT) {
            $contract->commission_status = null;
            $contract->saveQuietly();
            $this->engine->refresh($contract);
        }

        return $followup;
    }

    /** Zustand von Hand setzen (nur die Faelle, die ein Mensch entscheidet). */
    public function setStatus(Contract $contract, string $status): void
    {
        if (! Zustand::isValid($status)) {
            return;
        }
        $vorher = $contract->commission_status;
        if ($vorher === $status) {
            return;
        }
        $contract->commission_status = $status;
        $contract->commission_status_at = now();
        $contract->saveQuietly();

        $this->audit->log('provisionsstatus_geaendert', null, [
            'contract_id' => $contract->id,
            'field' => 'commission_status',
            'old_value' => $vorher === null ? null : Zustand::label($vorher),
            'new_value' => Zustand::label($status),
        ]);
    }

    /** Nutzereingabe erzeugt nie einen LIKE-Platzhalter. */
    private function escape(string $wert): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $wert);
    }
}
