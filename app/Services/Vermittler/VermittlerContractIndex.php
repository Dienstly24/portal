<?php
namespace App\Services\Vermittler;

use App\Models\Contract;

/**
 * Nachschlagewerk fuer die Zuordnung: alle Vertraege, die eine Referenz-Nr.
 * oder eine Vermittler-ID tragen, einmal geladen und ueber die
 * NORMALISIERTEN Kennungen ansprechbar.
 *
 * Warum eine eigene Klasse: sowohl der Abrechnungs-Import als auch der
 * Import der Vorgangsliste beantworten dieselben zwei Fragen ("welcher
 * Vertrag traegt diese Id?" / "welcher traegt diese Referenz-Nr.?"). Zwei
 * Kopien dieser Logik wuerden frueher oder spaeter auseinanderlaufen - und
 * dann ordnet der eine Weg zu, was der andere ablehnt.
 */
class VermittlerContractIndex
{
    /** @var array<string,string> normalisierte Vermittler-ID => contract_id */
    private array $byVermittlerId = [];

    /** @var array<string,array<int,string>> normalisierte Referenz => contract_ids */
    private array $byReference = [];

    /** @var array<string,Contract> geladene Vertraege je contract_id */
    private array $contracts = [];

    private bool $loaded = false;

    /** Einmalig laden (idempotent - ein zweiter Aufruf kostet nichts). */
    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        Contract::with('customer.user')
            ->where(function ($q) {
                $q->where(fn ($w) => $w->whereNotNull('reference_number')->where('reference_number', '!=', ''))
                    ->orWhere(fn ($w) => $w->whereNotNull('vermittler_id')->where('vermittler_id', '!=', ''));
            })
            ->chunkById(500, fn ($chunk) => $chunk->each(fn ($c) => $this->remember($c)));
    }

    /** Vertrag zu einer Vermittler-ID (oder null). */
    public function byId(?string $vermittlerId): ?Contract
    {
        $key = VermittlerReference::key($vermittlerId);
        if ($key === null) {
            return null;
        }
        return $this->contracts[$this->byVermittlerId[$key] ?? ''] ?? null;
    }

    /**
     * Vertraege zu einer Referenz-Nr. MEHRZAHL ist Absicht: dieselbe
     * Referenz-Nr. an zwei Vertraegen ist ein Grund, NICHT zuzuordnen.
     *
     * @return array<int,Contract>
     */
    public function byReference(?string $reference): array
    {
        $key = VermittlerReference::key($reference);
        if ($key === null) {
            return [];
        }
        return array_values(array_filter(array_map(
            fn ($id) => $this->contracts[$id] ?? null,
            $this->byReference[$key] ?? []
        )));
    }

    /** Einen (ggf. gerade geaenderten) Vertrag in den Index aufnehmen. */
    public function remember(Contract $contract): void
    {
        $this->contracts[$contract->id] = $contract;

        $idKey = VermittlerReference::key($contract->vermittler_id);
        if ($idKey !== null) {
            $this->byVermittlerId[$idKey] = $contract->id;
        }

        $refKey = $contract->referenceKey();
        if ($refKey !== null && !in_array($contract->id, $this->byReference[$refKey] ?? [], true)) {
            $this->byReference[$refKey][] = $contract->id;
        }
    }
}
