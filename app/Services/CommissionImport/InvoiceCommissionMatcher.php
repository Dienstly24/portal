<?php

namespace App\Services\CommissionImport;

use App\Models\Contract;
use App\Models\ContractCommission;
use App\Services\Vermittler\VermittlerReference;
use Illuminate\Support\Collection;

/**
 * Rechnung -> Vertrag -> Provision (Vorbereitung, Betreiber-Auftrag
 * 26.08.2026).
 *
 * ZIEL des Betreibers: spaeter soll das blosse Hochladen einer Rechnung
 * genuegen, um die zugehoerige Provision zu bestaetigen. Damit das moeglich
 * ist, muss HEUTE die Bruecke stehen - deshalb speichert der Import die
 * Kennungen dauerhaft am Vertrag und an der Provision.
 *
 * Diese Klasse ist die Lesehilfe dazu: sie findet in einem beliebigen Text
 * (Rechnungs-PDF, Betreff, Eingabefeld) die Kennungen und schlaegt die
 * passenden Provisionen vor. Sie BUCHT NICHTS. Die Bestaetigung einer
 * Zahlung bleibt eine bewusste Admin-Aktion - eine Rechnung ist ein Beleg
 * fuer eine Forderung, nicht fuer einen Geldeingang.
 */
class InvoiceCommissionMatcher
{
    /**
     * Kennungen aus einem Text ziehen.
     *
     * Die Muster sind bewusst ENG: die interne Vertragsnummer wird nur
     * erkannt, wenn ihre Beschriftung danebensteht ODER sie die typische
     * Form "V" + Ziffern hat. Eine freie Zahlensuche in einer Rechnung
     * fischt Betraege, Steuernummern und Datumsangaben mit heraus.
     *
     * @return array<string,array<int,string>> Feld => gefundene Werte
     */
    public function extract(string $text): array
    {
        $found = ['internal_contract_number' => [], 'reference_number' => [], 'vermittler_id' => [], 'order_number' => []];

        $labelled = [
            'internal_contract_number' => '(?:interne[rs]?\s+vertrags(?:nummer|nr\.?)|vertragsnummer\s+intern)',
            'reference_number' => '(?:referenz[-\s]?nr\.?|referenznummer|vorgangsnummer)',
            'vermittler_id' => '(?:vermittler[-\s]?id|vorgangs[-\s]?id)',
            'order_number' => '(?:auftrags?[-\s]?nr\.?|auftragsnummer)',
        ];
        foreach ($labelled as $field => $label) {
            if (preg_match_all('/'.$label.'\s*[:.]?\s*([A-Za-z0-9][A-Za-z0-9\-\/]{4,40})/iu', $text, $matches)) {
                foreach ($matches[1] as $value) {
                    $found[$field][] = trim($value, '-/');
                }
            }
        }

        // Form "V" + mindestens 6 Ziffern (Maklerpool: V19613073) - auch ohne
        // Beschriftung eindeutig genug.
        if (preg_match_all('/\bV\d{6,12}\b/', $text, $matches)) {
            foreach ($matches[0] as $value) {
                $found['internal_contract_number'][] = $value;
            }
        }

        return array_map(fn ($values) => array_values(array_unique($values)), $found);
    }

    /**
     * Vorschlag zu einer Kennung: Vertrag, Kunde und die Provisionen, die
     * noch auf Geld warten.
     *
     * @return array{contract:?Contract,commissions:Collection<int,ContractCommission>,note:?string}
     */
    public function lookup(string $identifier): array
    {
        $key = VermittlerReference::key($identifier);
        if ($key === null) {
            return ['contract' => null, 'commissions' => collect(), 'note' => 'Die Kennung ist zu kurz für eine Suche (mindestens '.VermittlerReference::MIN_LENGTH.' Zeichen).'];
        }

        $matcher = app(CommissionMatcher::class);
        $match = $matcher->match([
            'internal_contract_number' => $identifier,
            'reference_number' => $identifier,
            'vermittler_id' => $identifier,
            'order_number' => $identifier,
            'external_contract_number' => $identifier,
        ]);
        $contract = $match['contract'];

        // Auch OHNE Vertrag koennen Provisionen zu dieser Kennung vorliegen -
        // etwa wenn der Vertrag inzwischen geloescht wurde. Sie zu
        // verschweigen waere genau der Verlust, den die Klartext-Kopien in
        // der Tabelle verhindern sollen.
        $query = ContractCommission::query()->with('contract.customer.user');
        if ($contract !== null) {
            $query->where(function ($q) use ($contract, $key) {
                $q->where('contract_id', $contract->id)->orWhere('internal_key', $key);
            });
        } else {
            $query->where(function ($q) use ($key, $identifier) {
                $q->where('internal_key', $key)
                    ->orWhere('reference_number', $identifier)
                    ->orWhere('vermittler_id', $identifier)
                    ->orWhere('order_number', $identifier)
                    ->orWhere('external_contract_number', $identifier);
            });
        }

        return [
            'contract' => $contract,
            'commissions' => $query->orderByDesc('commission_date')->limit(50)->get(),
            'note' => $contract === null ? ($match['note'] ?? null) : null,
        ];
    }
}
