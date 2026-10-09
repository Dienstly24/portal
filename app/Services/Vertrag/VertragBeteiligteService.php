<?php

namespace App\Services\Vertrag;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractRevision;
use App\Models\Customer;
use App\Models\VertragBeteiligter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Einzige Schreibstelle fuer die weiteren Personen eines Vertrags (PR-6).
 *
 * Regeln:
 *  - Der Versicherungsnehmer bleibt contracts.customer_id und wird hier nie
 *    geaendert - auch nicht "nebenbei".
 *  - Versicherte Personen: beliebig viele, der VN darf selbst dazugehoeren
 *    (er versichert sich und seine Kinder).
 *  - Beitragszahler: hoechstens EINER, und nie der VN - ohne Eintrag zahlt
 *    der VN; ihn einzutragen waere eine zweite Quelle fuer dieselbe Aussage.
 *  - Beguenstigte: Anteil optional, Summe nie ueber 100 %.
 *  - Jede Aenderung steht in der Version History des Vertrags UND im
 *    ActivityLog. Entfernen loescht die Zeile, der alte Wert bleibt im
 *    Verlauf lesbar.
 */
class VertragBeteiligteService
{
    /**
     * @param array{name?:?string, geburtsdatum?:?string, anteil_prozent?:float|string|null, notiz?:?string} $angaben
     *
     * @throws \InvalidArgumentException mit einem Satz fuer die Oberflaeche
     */
    public function hinzufuegen(Contract $vertrag, string $rolle, ?Customer $kunde, array $angaben, ?int $userId = null): VertragBeteiligter
    {
        if (! isset(VertragBeteiligter::ROLLEN[$rolle])) {
            throw new \InvalidArgumentException('Unbekannte Rolle.');
        }

        $name = trim((string) ($angaben['name'] ?? ''));
        if (! $kunde && $name === '') {
            throw new \InvalidArgumentException('Bitte einen Kunden auswählen oder einen Namen eintragen.');
        }

        if ($kunde && $rolle === VertragBeteiligter::ROLLE_BEITRAGSZAHLER
            && (string) $kunde->id === (string) $vertrag->customer_id) {
            throw new \InvalidArgumentException('Der Versicherungsnehmer zahlt ohnehin, solange kein abweichender Beitragszahler eingetragen ist.');
        }

        $anteil = $angaben['anteil_prozent'] ?? null;
        $anteil = ($anteil === null || $anteil === '') ? null : round((float) str_replace(',', '.', (string) $anteil), 2);
        if ($anteil !== null && $rolle !== VertragBeteiligter::ROLLE_BEGUENSTIGT) {
            throw new \InvalidArgumentException('Ein Anteil gilt nur für Begünstigte.');
        }
        if ($anteil !== null && ($anteil <= 0 || $anteil > 100)) {
            throw new \InvalidArgumentException('Der Anteil muss zwischen 0 und 100 % liegen.');
        }

        return DB::transaction(function () use ($vertrag, $rolle, $kunde, $name, $angaben, $anteil, $userId) {
            // Sperre auf die Vertragszeile: zwei gleichzeitige Eintraege
            // duerfen nicht gemeinsam die 100 % oder den einen Zahler reissen.
            Contract::whereKey($vertrag->id)->lockForUpdate()->first();
            $vorhanden = VertragBeteiligter::where('contract_id', $vertrag->id)->get();

            if ($kunde && $vorhanden->contains(fn ($b) => (string) $b->customer_id === (string) $kunde->id && $b->rolle === $rolle)) {
                throw new \InvalidArgumentException('Diese Person ist in dieser Rolle bereits eingetragen.');
            }
            if ($rolle === VertragBeteiligter::ROLLE_BEITRAGSZAHLER
                && $vorhanden->contains(fn ($b) => $b->rolle === VertragBeteiligter::ROLLE_BEITRAGSZAHLER)) {
                throw new \InvalidArgumentException('Es gibt bereits einen abweichenden Beitragszahler. Bitte zuerst den bisherigen entfernen.');
            }
            if ($anteil !== null) {
                $summe = (float) $vorhanden->where('rolle', VertragBeteiligter::ROLLE_BEGUENSTIGT)->sum('anteil_prozent');
                if ($summe + $anteil > 100.0001) {
                    throw new \InvalidArgumentException('Die Anteile der Begünstigten ergäben zusammen mehr als 100 % (bisher '
                        .rtrim(rtrim(number_format($summe, 2, ',', ''), '0'), ',').' %).');
                }
            }

            $beteiligter = VertragBeteiligter::create([
                'contract_id' => $vertrag->id,
                'customer_id' => $kunde?->id,
                'rolle' => $rolle,
                // Kopie des Namens: bleibt, wenn die Akte spaeter geloescht wird.
                'name' => Str::limit($kunde?->user?->name ?: $name, 160, ''),
                'geburtsdatum' => $kunde ? null : (($angaben['geburtsdatum'] ?? null) ?: null),
                'anteil_prozent' => $anteil,
                'notiz' => ($angaben['notiz'] ?? null) ?: null,
                'created_by' => $userId,
            ]);
            $beteiligter->setRelation('customer', $kunde);

            $this->protokolliere($vertrag, $beteiligter, null, $beteiligter->kurztext(), 'contract_party_added', $userId);

            return $beteiligter;
        });
    }

    public function entfernen(VertragBeteiligter $beteiligter, ?int $userId = null): void
    {
        DB::transaction(function () use ($beteiligter, $userId) {
            $vertrag = $beteiligter->contract()->firstOrFail();
            $alt = $beteiligter->kurztext();
            $beteiligter->delete();
            $this->protokolliere($vertrag, $beteiligter, $alt, null, 'contract_party_removed', $userId);
        });
    }

    /** @return Collection<int, VertragBeteiligter> Beteiligte eines Vertrags, nach Rolle sortiert. */
    public function fuerVertrag(Contract $vertrag): Collection
    {
        $reihenfolge = array_flip(array_keys(VertragBeteiligter::ROLLEN));

        return VertragBeteiligter::with('customer.user')
            ->where('contract_id', $vertrag->id)
            ->orderBy('id')->get()
            ->sortBy(fn ($b) => $reihenfolge[$b->rolle] ?? 99)->values();
    }

    /**
     * Vertraege, an denen dieser Kunde beteiligt ist, OHNE VN zu sein.
     * Vertraege, deren VN der Bearbeiter nicht sehen darf, stehen nur als
     * ANZAHL da (sonst waere die Beteiligung ein Fenster in eine fremde Akte).
     *
     * @param  array<int, string>|null  $sichtbar  null = alle Kunden sichtbar
     * @return array{eintraege: Collection<int, VertragBeteiligter>, verborgen: int}
     */
    public function beteiligungenVon(Customer $kunde, ?array $sichtbar): array
    {
        $alle = VertragBeteiligter::with('contract.customer.user')
            ->where('customer_id', $kunde->id)
            ->whereHas('contract', fn ($q) => $q->where('customer_id', '!=', $kunde->id))
            ->orderBy('id')->get();

        $sichtbarSet = $sichtbar === null ? null : array_flip(array_map('strval', $sichtbar));
        $eintraege = $alle->filter(fn ($b) => $sichtbarSet === null || isset($sichtbarSet[(string) $b->contract?->customer_id]))->values();

        return ['eintraege' => $eintraege, 'verborgen' => $alle->count() - $eintraege->count()];
    }

    private function protokolliere(Contract $vertrag, VertragBeteiligter $b, ?string $alt, ?string $neu, string $aktion, ?int $userId): void
    {
        ContractRevision::create([
            'contract_id' => $vertrag->id,
            'batch_id' => (string) Str::uuid(),
            'field' => 'beteiligte_'.$b->rolle,
            'label' => $b->rolleLabel(),
            'old_value' => $alt,
            'new_value' => $neu,
            'source' => 'manual',
            'changed_by' => $userId,
        ]);

        // Ohne Namen im ActivityLog - der steht im Vertragsverlauf, der mit
        // dem Vertrag lebt; das ActivityLog kennt nur Kennungen.
        ActivityLog::record($aktion, 'contract', $vertrag->id, [
            'rolle' => $b->rolle,
            'beteiligter_id' => $b->id,
            'customer_id' => $b->customer_id,
        ], $userId);
    }
}
