<?php

namespace App\Services\Vermittler;

use App\Models\Contract;
use App\Models\VermittlerImport;
use App\Models\VermittlerMatchEvent;
use App\Models\VermittlerSettlement;

/**
 * Import der VORGANGSLISTE des Vermittler-Portals - der Schritt, der die
 * Bruecke Referenz-Nr. -> Vermittler-ID herstellt, BEVOR die erste
 * Abrechnung kommt.
 *
 * Der Unterschied zum Abrechnungs-Import ist fachlich, nicht technisch:
 *
 *  - Diese Liste ist KEINE Abrechnung. Sie nennt keinen Betrag und
 *    bestaetigt nichts. Deshalb entsteht hier NIE eine Provision, nie ein
 *    Storno und nie ein "Nicht in Abrechnung gefunden" - eine Liste offener
 *    Vorgaenge ist keine vollstaendige Abrechnung, aus ihrem Fehlen laesst
 *    sich nichts folgern.
 *  - Sie ist immer AELTER als eine Abrechnung. Ein Vertrag, der bereits
 *    abgerechnet oder storniert ist, wird von ihr nie zurueckgestuft
 *    (VermittlerStatusMap::mayAdvance).
 *
 * Gleich bleiben die Grundregeln: nie raten, nie Vertragsdaten aendern,
 * nie loeschen, nie doppelt.
 */
class VermittlerVorgangslisteImporter
{
    public function __construct(
        private VermittlerVorgangslisteParser $parser,
        private VermittlerContractIndex $index,
    ) {}

    /**
     * Freitext-Weg (Screenshot/PDF): der Parser muss die Tabelle erst
     * rekonstruieren und meldet, wenn ihm das nicht sicher gelingt.
     */
    public function importText(string $text, string $filename, ?int $userId = null): VermittlerImport
    {
        $parsed = $this->parser->parse($text);

        return $this->importRows($parsed['rows'], $parsed['ambiguous'], $parsed['notes'], $filename, $userId);
    }

    /**
     * Zeilen-Weg (CSV oder bereits gelesener Text).
     *
     * @param array<int,array<string,?string>> $rows
     * @param bool $ambiguous true = die Paarung Vorgang/Referenz-Nr. ist
     *        nicht belegbar; dann wird NICHTS automatisch verknuepft.
     * @param array<int,string> $notes
     */
    public function importRows(array $rows, bool $ambiguous, array $notes, string $filename, ?int $userId = null): VermittlerImport
    {
        $parsed = ['rows' => $rows, 'ambiguous' => $ambiguous, 'notes' => $notes];

        $import = VermittlerImport::create([
            'filename' => mb_substr($filename, 0, 255),
            'file_hash' => hash('sha256', json_encode($rows) ?: $filename),
            'imported_by' => $userId,
        ]);

        $this->index->load();

        $counts = [
            'rows_total' => count($parsed['rows']), 'rows_matched' => 0, 'rows_new_link' => 0,
            'rows_unmatched' => 0, 'rows_review' => 0, 'rows_storno' => 0,
            'rows_unchanged' => 0, 'rows_invalid' => 0, 'contracts_not_found' => 0,
        ];

        foreach ($parsed['rows'] as $row) {
            $result = $this->handleRow($import, $row, $parsed['ambiguous'], $parsed['notes'], $userId);
            $counts[match ($result) {
                'matched' => 'rows_matched',
                'linked' => 'rows_new_link',
                'review' => 'rows_review',
                'unchanged' => 'rows_unchanged',
                default => 'rows_unmatched',
            }]++;
        }

        $import->update($counts);

        return $import->refresh();
    }

    /** Sieht der Text nach einer Vorgangsliste aus? (fuer den Hinweis im Eingang) */
    public function looksLikeVorgangsliste(string $text): bool
    {
        return $this->parser->looksLikeVorgangsliste($text);
    }

    private function handleRow(VermittlerImport $import, array $row, bool $ambiguous, array $notes, ?int $userId): string
    {
        $vermittlerId = VermittlerReference::display($row['vermittler_id']);
        if ($vermittlerId === null) {
            return 'unmatched';
        }
        $reference = VermittlerReference::display($row['reference_number']);

        $viaId = $this->index->byId($vermittlerId);
        $viaRef = $reference !== null ? $this->index->byReference($reference) : [];

        [$contract, $result, $note] = $this->decide($vermittlerId, $reference, $viaId, $viaRef, $ambiguous, $notes);

        $settlement = $this->storeSettlement($import, $vermittlerId, $row, $reference, $contract, $result, $note);

        if ($contract !== null && $result !== 'review') {
            $this->applyToContract($contract, $settlement, $import, $row, $userId);
        } elseif ($contract !== null && $note !== null) {
            $this->flagForReview($contract, $import, $note, $userId);
        }

        return $result;
    }

    /**
     * Die Entscheidung. Sie darf nur zwei Ausgaenge kennen, bei denen etwas
     * geschrieben wird: "kenne ich schon" und "kann ich eindeutig
     * verknuepfen". Alles andere geht in die Pruefliste.
     *
     * @return array{0: ?Contract, 1: string, 2: ?string}
     */
    private function decide(string $vermittlerId, ?string $reference, ?Contract $viaId, array $viaRef, bool $ambiguous, array $notes): array
    {
        // Hat die Erkennung selbst gewackelt (Tabelle spaltenweise gelesen),
        // wird in dieser Datei NICHTS automatisch verknuepft. Ein falsch
        // gepaartes Zahlenpaar waere hier besonders teuer: es haengt die
        // Abrechnung eines fremden Kunden an diesen Vertrag.
        if ($ambiguous) {
            return [$viaId, 'review', 'Zuordnung der Referenz-Nummern nicht eindeutig lesbar. '
                .($notes[0] ?? '').' Bitte die Liste als CSV exportieren oder von Hand zuordnen.'];
        }

        if ($viaId !== null) {
            if ($reference !== null
                && $viaId->referenceKey() !== null
                && ! VermittlerReference::same($viaId->reference_number, $reference)) {
                return [$viaId, 'review', 'Referenz-Nr. weicht ab: Vertrag "'.$viaId->reference_number
                    .'" / Liste "'.$reference.'"'];
            }
            return [$viaId, 'matched', null];
        }

        if ($reference === null) {
            // Ohne Referenz-Nr. traegt die Zeile nichts zur Bruecke bei -
            // sie wird gezeigt, aber nie geraten.
            return [null, 'unmatched', 'Ohne Referenz-Nr. in der Liste – keine Zuordnung möglich'];
        }

        if (count($viaRef) > 1) {
            return [null, 'review', 'Doppelte Referenz-Nr.: '.count($viaRef)
                .' Verträge tragen "'.$reference.'"'];
        }

        if (count($viaRef) === 1) {
            $contract = $viaRef[0];
            if (filled($contract->vermittler_id)
                && ! VermittlerReference::same($contract->vermittler_id, $vermittlerId)) {
                return [$contract, 'review', 'Vertrag trägt bereits die Vermittler-ID "'
                    .$contract->vermittler_id.'"'];
            }
            return [$contract, 'linked', null];
        }

        return [null, 'unmatched', 'Referenz-Nr. "'.$reference.'" ist bei keinem Vertrag erfasst'];
    }

    /**
     * Die Zeile festhalten. Natuerlicher Schluessel bleibt die Vermittler-ID -
     * dieselbe Zeile bekommt spaeter die echte Abrechnung (Betrag, Status)
     * und wird dann AKTUALISIERT statt doppelt angelegt.
     */
    private function storeSettlement(VermittlerImport $import, string $vermittlerId, array $row, ?string $reference, ?Contract $contract, string $result, ?string $note): VermittlerSettlement
    {
        $settlement = VermittlerSettlement::where('vermittler_id', $vermittlerId)->first()
            ?? new VermittlerSettlement(['vermittler_id' => $vermittlerId]);

        $data = [
            'import_id' => $import->id,
            'import_result' => $result,
            'match_note' => $note,
        ];

        // Nur ERGAENZEN: eine bereits eingelesene Abrechnung ist die
        // juengere und genauere Quelle - Betrag und Status bleiben ihr.
        foreach ([
            'produkt' => $row['produkt'] ?? null,
            'statement_date' => $row['datum'] ?? null,
            'reference_number' => $reference,
            'reference_key' => VermittlerReference::key($reference),
        ] as $field => $value) {
            if (blank($settlement->{$field}) && filled($value)) {
                $data[$field] = $value;
            }
        }

        // Status-Text der Liste nur setzen, solange die Zeile noch keinen
        // echten Abrechnungsstatus hat (die Liste ist immer die aeltere Info).
        $statusText = $row['status'] ?? null;
        if (filled($statusText) && (blank($settlement->status_code) || VermittlerStatusMap::forText($settlement->status_code) !== null)) {
            $data['status_code'] = mb_substr((string) $statusText, 0, 20);
        }

        if ($contract !== null && $result !== 'review') {
            $data['contract_id'] = $contract->id;
            $data['customer_id'] = $contract->customer_id;
            $data['contract_label'] = mb_substr(trim($contract->insurer.' · '.($contract->contract_number ?: $contract->typeLabel())), 0, 190);
            $data['customer_label'] = mb_substr((string) ($contract->customer?->user?->name ?? ''), 0, 190) ?: null;
            $data['match_result'] = 'matched';
        } elseif (blank($settlement->match_result) || $settlement->match_result === 'unmatched') {
            $data['match_result'] = $result === 'linked' ? 'matched' : ($result === 'matched' ? 'matched' : $result);
        }

        $settlement->fill($data)->save();

        return $settlement;
    }

    /** Bruecke am Vertrag festhalten - ohne je einen Vertragswert zu aendern. */
    private function applyToContract(Contract $contract, VermittlerSettlement $settlement, VermittlerImport $import, array $row, ?int $userId): void
    {
        $events = [];
        $update = [
            'vermittler_last_import_id' => $import->id,
            'vermittler_last_imported_at' => now(),
        ];

        if (blank($contract->vermittler_id)) {
            $update['vermittler_id'] = $settlement->vermittler_id;
            $update['vermittler_matched_at'] = now();
            $events[] = ['id_linked', 'Vermittler-ID '.$settlement->vermittler_id
                .' aus der Vorgangsliste zugeordnet'];
        }

        // Leere Referenz-Nr. ergaenzen (Ergaenzen ist kein Ueberschreiben).
        if (blank($contract->reference_number) && filled($settlement->reference_number)) {
            $update['reference_number'] = $settlement->reference_number;
            $events[] = ['reference_stored', 'Referenz-Nr. '.$settlement->reference_number
                .' aus der Vorgangsliste ergänzt'];
        }

        $new = VermittlerStatusMap::forText($row['status'] ?? null) ?? Contract::VERMITTLER_ID_ZUGEORDNET;
        $current = $contract->vermittlerStatus();
        if ($new !== $current && VermittlerStatusMap::mayAdvance($current, $new)) {
            $update['vermittler_status'] = $new;
            $events[] = ['status_changed', Contract::VERMITTLER_STATUSES[$new]['label']];
        }

        $contract->forceFill($update)->saveQuietly();
        $this->index->remember($contract);

        foreach ($events as [$action, $detail]) {
            VermittlerMatchEvent::record($action, [
                'contract_id' => $contract->id,
                'reference_number' => $contract->reference_number,
                'vermittler_id' => $contract->vermittler_id ?: $settlement->vermittler_id,
                'detail' => mb_substr($detail, 0, 255),
                'import_id' => $import->id,
                'user_id' => $userId,
            ]);
        }
    }

    /** Widerspruch sichtbar machen, ohne etwas zu verknuepfen. */
    private function flagForReview(Contract $contract, VermittlerImport $import, string $note, ?int $userId): void
    {
        if (VermittlerStatusMap::mayAdvance($contract->vermittlerStatus(), Contract::VERMITTLER_PRUEFUNG)) {
            $contract->forceFill([
                'vermittler_status' => Contract::VERMITTLER_PRUEFUNG,
                'vermittler_last_import_id' => $import->id,
                'vermittler_last_imported_at' => now(),
            ])->saveQuietly();
            $this->index->remember($contract);
        }

        VermittlerMatchEvent::record('conflict', [
            'contract_id' => $contract->id,
            'reference_number' => $contract->reference_number,
            'vermittler_id' => $contract->vermittler_id,
            'detail' => mb_substr($note, 0, 255),
            'import_id' => $import->id,
            'user_id' => $userId,
        ]);
    }
}
