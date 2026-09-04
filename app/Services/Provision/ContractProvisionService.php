<?php

namespace App\Services\Provision;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Provision;
use App\Models\ProvisionAuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Automatische Vermittler-Provisionen (Provisions-Management, Betreiber-
 * Vorgabe 25.07.2026): Bei JEDER Vertragsanlage - egal ob manuell im
 * Formular, per Dokumenten-Eingang oder Import - wird die Provision fuer
 * den WERBER des Kunden (Mitarbeiter acquired_by ODER Partner
 * acquired_by_partner_id) automatisch berechnet und als offene Buchung
 * angelegt. Kein manueller Schritt noetig.
 *
 * Regeln:
 * - Ohne Werber oder ohne hinterlegten Satz entsteht KEINE Buchung
 *   (es werden nie Betraege erfunden).
 * - Je Vertrag genau EINE automatische Neuvertrag-Provision (idempotent);
 *   Boni/Abzuege sind zusaetzlich moeglich (mehrere Buchungen je Vertrag).
 * - Kuendigung/Loeschung eines Vertrags erzeugt eine negative GEGENBUCHUNG
 *   (type=storno) statt einer Loeschung - Originale bleiben fuer die
 *   Buchhaltung immer erhalten.
 * - Jede Buchung landet im ProvisionAuditLog (wer/wann/alt/neu/Grund).
 */
class ContractProvisionService
{
    public function __construct(private readonly ProvisionRateResolver $rates)
    {
    }

    /**
     * Provision fuer einen neu angelegten Vertrag anlegen. Gibt null zurueck,
     * wenn nichts zu buchen ist (kein Werber, kein Satz, Betrag 0,
     * bereits gebucht oder Vertrag nicht aktiv/angebahnt).
     */
    public function createForContract(Contract $contract): ?Provision
    {
        // Nur produktive Vertraege verguenten - ein bereits gekuendigt oder
        // abgelaufen angelegter Datensatz (Altbestand-Erfassung) loest nichts aus.
        if (! in_array($contract->status, ['active', 'pending'], true)) {
            return null;
        }

        $customer = $contract->customer;
        if (! $customer) {
            return null;
        }

        [$werberUser, $werberPartner] = $this->resolveWerber($customer);
        if (! $werberUser && ! $werberPartner) {
            return null;
        }

        $rate = $werberUser
            ? $this->rates->forUser($werberUser, $contract->type)
            : $this->rates->forPartner($werberPartner, $contract->type);
        if ($rate === null) {
            return null;
        }

        $amount = round($rate['fixed'] + $rate['percent'] / 100 * $contract->yearlyPremium(), 2);
        if ($amount <= 0) {
            return null;
        }

        // Idempotenz RACE-SICHER: die Zeilensperre auf den Vertrag serialisiert
        // gleichzeitige Aufrufe (Formular-Doppelklick, created-Hook vs.
        // nachtraegliche Werber-Buchung, zwei Verwalter). Ohne sie koennten
        // beide den exists()-Check bestehen und den Werber DOPPELT verguenten -
        // ein reiner UNIQUE-Index auf (contract_id,type) scheidet aus, weil
        // Boni/Abzuege/Storno legitim mehrfach je Vertrag vorkommen (Audit
        // CONC-1). lockForUpdate ist auf SQLite ein No-Op (Tests), wirkt aber
        // auf MySQL/Produktion.
        return DB::transaction(function () use ($contract, $customer, $werberUser, $werberPartner, $rate, $amount) {
            Contract::whereKey($contract->getKey())->lockForUpdate()->first();

            if (Provision::where('contract_id', $contract->id)->where('type', 'neuvertrag')->exists()) {
                return null;
            }

            $provision = Provision::create([
                'user_id' => $werberUser?->id,
                'partner_id' => $werberPartner?->id,
                'customer_id' => $customer->id,
                'contract_id' => $contract->id,
                'contract_type' => $contract->type,
                'insurer' => $contract->insurer,
                'type' => 'neuvertrag',
                'amount' => $amount,
                'status' => 'offen',
                'note' => $this->buildNote('Automatisch: Neuvertrag', $contract),
                'created_by' => auth()->check() && auth()->user()->isStaff() ? auth()->id() : null,
            ]);

            ProvisionAuditLog::write(
                $provision, 'created', 'amount', null,
                number_format($amount, 2, '.', ''),
                'Automatische Anlage bei Vertragsanlage'
                .($rate['source'] === 'sparte' ? ' (Sparten-Satz)' : ' (globaler Satz)'),
            );
            ActivityLog::record('provision_auto_created', 'provision', $provision->id, [
                'empfaenger' => $provision->recipientName(),
                'betrag' => $amount,
                'sparte' => $contract->type,
                'vertrag' => (string) $contract->id,
            ]);

            return $provision;
        });
    }

    /**
     * Nachberechnung, wenn einem Kunden (nachtraeglich) ein Werber zugewiesen
     * wird: alle produktiven Vertraege ohne automatische Provision buchen.
     * So braucht auch der Ablauf "Import zuerst, Werber spaeter" keinen
     * manuellen Schritt.
     */
    public function createForCustomerContracts(Customer $customer): int
    {
        $count = 0;
        foreach ($customer->contracts()->whereIn('status', ['active', 'pending'])->get() as $contract) {
            if ($this->createForContract($contract) !== null) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Negative Gegenbuchung(en) fuer einen gekuendigten/geloeschten Vertrag:
     * jede positive, nicht stornierte Buchung des Vertrags erhaelt genau eine
     * Storno-Buchung ueber den gleichen Betrag (related_provision_id). Die
     * Originale bleiben unveraendert in der Datenbank (Finanzhistorie).
     */
    public function createStornoForContract(Contract $contract, string $reason): int
    {
        $originals = Provision::where('contract_id', $contract->id)
            ->where('type', '!=', 'storno')
            ->where('status', '!=', 'storniert')
            ->where('amount', '>', 0)
            ->whereDoesntHave('counterBookings', fn ($q) => $q->where('type', 'storno'))
            ->get();

        $count = 0;
        foreach ($originals as $original) {
            $storno = Provision::create([
                'user_id' => $original->user_id,
                'partner_id' => $original->partner_id,
                'customer_id' => $original->customer_id,
                'contract_id' => $original->contract_id,
                'contract_type' => $original->contract_type,
                'insurer' => $original->insurer,
                'type' => 'storno',
                'related_provision_id' => $original->id,
                'amount' => round(-1 * (float) $original->amount, 2),
                'status' => 'offen',
                'note' => $this->buildNote('Automatische Gegenbuchung: '.$reason, $contract),
                'created_by' => auth()->check() && auth()->user()->isStaff() ? auth()->id() : null,
            ]);

            ProvisionAuditLog::write(
                $storno, 'created', 'amount', null,
                number_format((float) $storno->amount, 2, '.', ''), $reason,
            );
            ProvisionAuditLog::write(
                $original, 'storno_created', null, null,
                (string) $storno->id, $reason,
            );
            ActivityLog::record('provision_storno_created', 'provision', $storno->id, [
                'empfaenger' => $storno->recipientName(),
                'betrag' => (float) $storno->amount,
                'original' => (string) $original->id,
                'grund' => $reason,
            ]);
            $count++;
        }

        return $count;
    }

    /** Werber des Kunden aufloesen - GENAU einer: Mitarbeiter ODER Partner. */
    private function resolveWerber(Customer $customer): array
    {
        if ($customer->acquired_by) {
            return [$customer->acquirer, null];
        }
        if ($customer->acquired_by_partner_id) {
            return [null, $customer->acquirerPartner];
        }
        return [null, null];
    }

    /** Kurze, nachvollziehbare Buchungsnotiz (Sparte, Gesellschaft, Nummer). */
    private function buildNote(string $prefix, Contract $contract): string
    {
        $parts = array_filter([
            $contract->typeLabel(),
            $contract->insurer,
            $contract->contract_number ? 'Nr. '.$contract->contract_number : null,
        ]);
        return mb_substr($prefix.' - '.implode(', ', $parts), 0, 500);
    }
}
