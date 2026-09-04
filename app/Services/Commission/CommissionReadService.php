<?php

namespace App\Services\Commission;

use App\Support\Commission\CommissionEntry;
use Illuminate\Support\Collection;

/**
 * ARCH-3: EIN Leseweg fuer alle Provisionsquellen.
 *
 * WAS HIER BEWUSST NICHT PASSIERT: die drei Fachbereiche werden NICHT
 * verschmolzen. Sie beschreiben verschiedene Tatsachen und muessen das
 * weiter tun -
 *   provisions              = Geld, das RAUSGEHT an eigene Mitarbeiter
 *                             und Partner,
 *   contract_commissions    = Geld, das REINKOMMT, aus beliebig vielen
 *                             Pools mit fremden Spalten,
 *   vermittler_settlements  = der EINE Vermittler mit festem Format,
 *                             dessen Zeilen das Loeschen des Vertrags
 *                             ueberleben muessen.
 * Sie zu einer Tabelle zu zwingen hiesse, eine der drei Wahrheiten zu
 * verbiegen. Doppelt war nur das LESEN: jede Auswertung hat sich ihre
 * Summen selbst zusammengesucht.
 *
 * ZUGRIFF: hier stehen Betraege. Der Aufrufer MUSS das Recht
 * 'provisionen-verwalten' bereits geprueft haben - dieser Dienst ist eine
 * Lesehilfe, keine Berechtigungsschicht, und ersetzt die Pruefung an Route
 * und Controller nicht.
 */
class CommissionReadService
{
    /** @var array<int, CommissionSource> */
    private array $sources;

    public function __construct(CommissionSource ...$sources)
    {
        $this->sources = $sources;
    }

    /** @return array<string, string> Schluessel => Anzeigename */
    public function availableSources(): array
    {
        return collect($this->sources)->mapWithKeys(fn (CommissionSource $s) => [$s->key() => $s->label()])->all();
    }

    /**
     * Alle Buchungen der gewaehlten Quellen, nach Buchungstag absteigend.
     *
     * @return Collection<int, CommissionEntry>
     */
    public function entries(CommissionQuery $query): Collection
    {
        return collect($this->sources)
            ->filter(fn (CommissionSource $s) => $query->wantsSource($s->key()))
            ->flatMap(fn (CommissionSource $s) => $s->entries($query))
            ->sortByDesc(fn (CommissionEntry $e) => $e->bookedAt?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * Summen JE RICHTUNG - nie als eine Zahl.
     *
     * Eingang und Ausgang zu verrechnen ergaebe einen "Gewinn", der keiner
     * ist: die Ausgangsprovision eines Vertrags und die Eingangsprovision
     * desselben Vertrags fallen Monate auseinander, und beide Seiten sind
     * unvollstaendig, solange nicht alle Pools abgerechnet haben. Wer die
     * Differenz sehen will, bildet sie ausdruecklich - und weiss dann, was
     * er da rechnet.
     *
     * @return array{eingang: float, ausgang: float, anzahl: int}
     */
    public function totals(CommissionQuery $query): array
    {
        $entries = $this->entries($query);

        return [
            'eingang' => round((float) $entries->where('direction', CommissionEntry::EINGANG)->sum('amount'), 2),
            'ausgang' => round((float) $entries->where('direction', CommissionEntry::AUSGANG)->sum('amount'), 2),
            'anzahl' => $entries->count(),
        ];
    }

    /**
     * Summen je Quelle - die Frage "was hat uns welcher Weg gebracht?".
     *
     * @return array<string, array{label: string, richtung: string, summe: float, anzahl: int}>
     */
    public function bySource(CommissionQuery $query): array
    {
        return $this->entries($query)
            ->groupBy(fn (CommissionEntry $e) => $e->source)
            ->map(fn (Collection $group) => [
                'label' => $group->first()->sourceLabel,
                'richtung' => $group->first()->direction,
                'summe' => round((float) $group->sum('amount'), 2),
                'anzahl' => $group->count(),
            ])
            ->all();
    }

    /**
     * Alles, was zu EINEM Vertrag gebucht wurde - quer ueber alle Quellen.
     *
     * Das ist die Frage, fuer die es bisher keinen Weg gab: ein Vertrag kann
     * gleichzeitig eine Ausgangsprovision an den Werber, eine Abrechnung des
     * Maklerpools und eine Zeile beim Vermittler haben. Wer sie sehen wollte,
     * musste drei Seiten oeffnen.
     *
     * @return Collection<int, CommissionEntry>
     */
    public function forContract(string $contractId): Collection
    {
        return $this->entries(new CommissionQuery(contractId: $contractId));
    }
}
