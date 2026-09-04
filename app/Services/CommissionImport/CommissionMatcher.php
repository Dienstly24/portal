<?php

namespace App\Services\CommissionImport;

use App\Models\Contract;
use App\Models\ContractEnergyDetail;
use App\Services\Vermittler\VermittlerReference;

/**
 * Findet zu einem Provisions-Datensatz den passenden Vertrag im Portal.
 *
 * VIER REGELN, dieselben wie beim Vermittler-Abgleich - sie haben sich dort
 * bewaehrt und duerfen hier nicht anders lauten:
 *  1. NIE RATEN. Mehrere Treffer, ein Widerspruch oder eine zu kurze Kennung
 *     heissen "nicht zugeordnet", nicht "wahrscheinlich der da".
 *  2. NIE EINEN VERTRAG ANLEGEN. Eine fremde Abrechnung ist kein Beleg fuer
 *     einen Vertrag, den wir nicht kennen.
 *  3. NIE VERTRAGSDATEN AENDERN. Einzige Ausnahme: eine LEERE Kennung darf
 *     ergaenzt werden - das ist der Sinn der Bruecke.
 *  4. NAMEN ZAEHLEN NICHT. "Ahmad Al Huweij" gibt es mehrfach; ein
 *     Namenstreffer wuerde eine Provision an den falschen Kunden haengen.
 *
 * Die Reihenfolge der Kennungen ist nach TRENNSCHAERFE sortiert: die interne
 * Vertragsnummer identifiziert genau einen Vertrag, eine Auftragsnummer
 * dagegen einen VORGANG, der auch zwei Vertraege tragen kann (Strom + Gas).
 */
class CommissionMatcher
{
    /** @var array<string,array<string,array<int,string>>> Feld => Schluessel => contract_ids */
    private array $index = [];

    /** @var array<string,Contract> */
    private array $contracts = [];

    /** @var array<string,array<string,array<int,string>>> Kennung => Schluessel => contract_ids */
    private array $energyIndex = [];

    private bool $loaded = false;

    /**
     * Vertragsspalte je Kennung des Imports. Mehrzahl ist Absicht: die
     * Auftragsnummer des Energieportals landet bei uns in `reference_number`,
     * die Referenz-Nr. der Antragsstrecke ebenfalls - beide fragen also
     * dieselbe Spalte ab.
     *
     * @var array<string,array<int,string>>
     */
    private const LOOKUP = [
        'internal_contract_number' => ['internal_contract_number'],
        'vermittler_id' => ['vermittler_id'],
        'reference_number' => ['reference_number', 'internal_contract_number'],
        'order_number' => ['reference_number'],
        'external_contract_number' => ['contract_number'],
    ];

    /** Klartext je Kennung - steht so in der Begruendung der Zuordnung. */
    private const REASON = [
        'internal_contract_number' => 'Interne Vertragsnummer',
        'vermittler_id' => 'Vermittler-Id',
        'reference_number' => 'Referenz-Nr.',
        'order_number' => 'Auftr.-Nr.',
        'external_contract_number' => 'Vertragsnummer der Gesellschaft',
        'meter_number' => 'Zählernummer',
        'malo_id' => 'MaLo-ID',
    ];

    /**
     * Kennungen des ENERGIEVERTRAGS - sie stehen nicht am Vertrag selbst,
     * sondern an seinen Energie-Details, und werden deshalb ueber eine
     * eigene Normalisierung verglichen (die Zaehlernummer steht auf dem
     * Zaehler mit Leerzeichen, im Auftrag ohne).
     *
     * Sie laufen NACH allen Vertragsnummern: eine Marktlokation kann Strom
     * UND Gas tragen und ueber die Jahre mehrere Vertraege nacheinander.
     * Genau dafuer gibt es die Mehrdeutigkeits-Regel unten - zwei Treffer
     * heissen "nichts zuordnen", nicht "den ersten nehmen".
     *
     * @var array<string,callable-string>
     */
    private const ENERGY_LOOKUP = [
        'meter_number' => 'normalizeMeter',
        'malo_id' => 'normalizeMalo',
    ];

    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        Contract::with('customer.user')
            ->where(function ($q) {
                foreach (['internal_contract_number', 'reference_number', 'vermittler_id', 'contract_number'] as $column) {
                    $q->orWhere(fn ($w) => $w->whereNotNull($column)->where($column, '!=', ''));
                }
            })
            ->chunkById(500, fn ($chunk) => $chunk->each(fn ($c) => $this->remember($c)));

        // Energie-Kennungen getrennt laden: sie haengen an den Details, und
        // die Vertragsabfrage oben findet Energievertraege ohne jede
        // Vertragsnummer gar nicht - genau die aber, die eine Abrechnung nur
        // ueber die Zaehlernummer wiederfindet.
        ContractEnergyDetail::query()
            ->select(['id', 'contract_id', 'meter_number', 'malo_id'])
            ->where(function ($q) {
                $q->orWhere(fn ($w) => $w->whereNotNull('meter_number')->where('meter_number', '!=', ''))
                    ->orWhere(fn ($w) => $w->whereNotNull('malo_id')->where('malo_id', '!=', ''));
            })
            ->chunkById(500, function ($chunk) {
                foreach ($chunk as $detail) {
                    foreach (self::ENERGY_LOOKUP as $feld => $normalizer) {
                        $key = ContractEnergyDetail::{$normalizer}($detail->{$feld});
                        if ($key === null || $detail->contract_id === null) {
                            continue;
                        }
                        if (! in_array($detail->contract_id, $this->energyIndex[$feld][$key] ?? [], true)) {
                            $this->energyIndex[$feld][$key][] = $detail->contract_id;
                        }
                    }
                }
            });
    }

    public function remember(Contract $contract): void
    {
        $this->contracts[$contract->id] = $contract;
        foreach (['internal_contract_number', 'reference_number', 'vermittler_id', 'contract_number'] as $column) {
            $key = VermittlerReference::key($contract->{$column});
            if ($key === null) {
                continue;
            }
            if (! in_array($contract->id, $this->index[$column][$key] ?? [], true)) {
                $this->index[$column][$key][] = $contract->id;
            }
        }
    }

    /**
     * Vertrag zu den Kennungen einer Zeile.
     *
     * @param array<string,mixed> $mapped gedeutete Werte der Zeile
     * @return array{contract:?Contract,reason:?string,note:?string}
     */
    public function match(array $mapped): array
    {
        $this->load();

        $tried = [];
        foreach (self::LOOKUP as $field => $columns) {
            $value = $mapped[$field] ?? null;
            $key = VermittlerReference::key(is_string($value) ? $value : null);
            if ($key === null) {
                continue;
            }
            $tried[] = self::REASON[$field].' „'.$value.'“';

            $ids = [];
            foreach ($columns as $column) {
                foreach ($this->index[$column][$key] ?? [] as $id) {
                    $ids[$id] = true;
                }
            }
            $ids = array_keys($ids);

            if ($ids === []) {
                continue;
            }
            if (count($ids) > 1) {
                // Zwei Vertraege unter derselben Kennung: das ist ein Zustand
                // im Bestand, den nur ein Mensch aufloesen kann. Eine
                // automatische Wahl waere hier besonders teuer - es geht um
                // Geld, das dem falschen Vertrag zugerechnet wuerde.
                return [
                    'contract' => null,
                    'reason' => null,
                    'note' => self::REASON[$field].' „'.$value.'“ trifft '.count($ids)
                        .' Verträge. Es wurde bewusst nichts zugeordnet.',
                ];
            }
            return [
                'contract' => $this->contracts[$ids[0]] ?? null,
                'reason' => self::REASON[$field],
                'note' => null,
            ];
        }

        // Erst wenn keine Vertragsnummer getroffen hat: die Kennungen der
        // LIEFERSTELLE. Sie sind unschaerfer, aber bei Energie-Abrechnungen
        // oft die einzige Bruecke - ein Auftrag hat noch keine Vertragsnummer,
        // eine Zaehlernummer hat er von Anfang an.
        foreach (self::ENERGY_LOOKUP as $field => $normalizer) {
            $value = $mapped[$field] ?? null;
            $key = ContractEnergyDetail::{$normalizer}(is_string($value) ? $value : null);
            // Kurze Zahlenfolgen treffen halbe Bestaende - dieselbe
            // Mindestlaenge wie bei den Vertragsnummern.
            if ($key === null || strlen($key) < VermittlerReference::MIN_LENGTH) {
                continue;
            }
            $tried[] = self::REASON[$field].' „'.$value.'“';

            $ids = $this->energyIndex[$field][$key] ?? [];
            if ($ids === []) {
                continue;
            }
            if (count($ids) > 1) {
                return [
                    'contract' => null,
                    'reason' => null,
                    'note' => self::REASON[$field].' „'.$value.'“ trifft '.count($ids)
                        .' Verträge (an einer Lieferstelle können Strom und Gas hängen).'
                        .' Es wurde bewusst nichts zugeordnet.',
                ];
            }
            $contract = $this->contracts[$ids[0]] ?? Contract::with('customer.user')->find($ids[0]);
            if ($contract === null) {
                continue;
            }
            $this->contracts[$contract->id] = $contract;

            return ['contract' => $contract, 'reason' => self::REASON[$field], 'note' => null];
        }

        return [
            'contract' => null,
            'reason' => null,
            'note' => $tried === []
                ? 'Die Zeile enthält keine verwertbare Kennung (zu kurz oder leer).'
                : 'Kein Vertrag gefunden zu: '.implode(', ', $tried).'.',
        ];
    }

    /**
     * Fehlende Kennungen am Vertrag ERGAENZEN - nie ueberschreiben.
     *
     * Das ist der einzige Schreibzugriff des Imports auf einen Vertrag und
     * genau der Zweck der Bruecke: ist die interne Vertragsnummer einmal
     * hinterlegt, genuegt sie in jeder spaeteren Datei. Eine ABWEICHENDE
     * vorhandene Nummer bleibt unangetastet und wird gemeldet - sie bedeutet,
     * dass eine der beiden Angaben falsch ist, und das entscheidet kein Import.
     *
     * @param array<string,mixed> $mapped
     * @return array<int,array{field:string,old:?string,new:string}> was ergaenzt wurde
     */
    public function completeIdentifiers(Contract $contract, array $mapped): array
    {
        $filled = [];
        $pairs = [
            'internal_contract_number' => 'internal_contract_number',
            'reference_number' => 'reference_number',
            'vermittler_id' => 'vermittler_id',
        ];
        foreach ($pairs as $field => $column) {
            $value = VermittlerReference::display(is_string($mapped[$field] ?? null) ? $mapped[$field] : null);
            if ($value === null || VermittlerReference::key($value) === null) {
                continue;
            }
            if (filled($contract->{$column})) {
                continue; // vorhandener Wert bleibt - auch ein abweichender
            }
            $contract->{$column} = $value;
            $filled[] = ['field' => $column, 'old' => null, 'new' => $value];
        }
        if ($filled !== []) {
            $contract->saveQuietly();
            $this->remember($contract);
        }
        return $filled;
    }

    /**
     * Widerspricht die Zeile den Kennungen des gefundenen Vertrags? Ein
     * Widerspruch ist kein Fehler der Datei, sondern eine Frage an den
     * Menschen - deshalb Klartext statt Code.
     *
     * @param array<string,mixed> $mapped
     */
    public function conflict(Contract $contract, array $mapped): ?string
    {
        $checks = [
            'internal_contract_number' => ['internal_contract_number', 'Interne Vertragsnummer'],
            'vermittler_id' => ['vermittler_id', 'Vermittler-Id'],
        ];
        foreach ($checks as $field => [$column, $label]) {
            $value = $mapped[$field] ?? null;
            if (! is_string($value) || VermittlerReference::key($value) === null || blank($contract->{$column})) {
                continue;
            }
            if (! VermittlerReference::same($value, $contract->{$column})) {
                return $label.' der Datei („'.$value.'“) weicht vom Vertrag ab („'
                    .$contract->{$column}.'“).';
            }
        }
        return null;
    }
}
