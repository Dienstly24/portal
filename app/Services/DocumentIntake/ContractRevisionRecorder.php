<?php
namespace App\Services\DocumentIntake;

use App\Models\Contract;
use App\Models\ContractRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Wendet vorgeschlagene Feldwerte auf einen Vertrag (oder ein Detail-Modell)
 * an und schreibt fuer jede tatsaechliche Aenderung einen Audit-Eintrag
 * (ContractRevision): welches Feld, alter Wert, neuer Wert, wann, durch wen,
 * aus welchem Dokument.
 *
 * Kernregel (Betreiber-Vorgabe): Ein neu importierter Wert AKTUALISIERT das
 * Feld (z.B. Beitrag 350 -> 380,99), aber ein LEERER neuer Wert loescht nie
 * einen bestehenden - fehlt eine Angabe im neuen Dokument, bleibt der alte
 * Wert erhalten. So entsteht eine saubere, lueckenlose Version History statt
 * eines zweiten Vertrags fuer dasselbe Fahrzeug.
 */
class ContractRevisionRecorder
{
    /**
     * @param Contract $contract         Vertrag, zu dem der Audit-Eintrag gehoert
     * @param Model    $target           Zu aktualisierendes Modell (Vertrag oder Detail)
     * @param array<string,mixed> $proposed  Feld => neuer Wert (aus dem Dokument)
     * @param array<string,array{label:string,format?:callable}> $spec  je Feld: Anzeigename + optionaler Formatter
     * @param array{source:string,source_document_id?:?string,changed_by?:?int,batch_id:string} $ctx
     * @return list<string> tatsaechlich geaenderte Feld-Schluessel
     */
    public function apply(Contract $contract, Model $target, array $proposed, array $spec, array $ctx): array
    {
        $changed = [];

        foreach ($proposed as $field => $newRaw) {
            if (!isset($spec[$field])) {
                continue; // nur bekannte, mit Label versehene Felder protokollieren
            }
            // Leerer neuer Wert -> nie ueberschreiben (kein Datenverlust).
            if ($newRaw === null || $newRaw === '' || $newRaw === []) {
                continue;
            }

            $oldRaw = $target->{$field};
            if ($this->equal($oldRaw, $newRaw)) {
                continue; // keine Aenderung
            }

            $format = $spec[$field]['format'] ?? null;
            $oldDisplay = $this->display($oldRaw, $format);
            $newDisplay = $this->display($newRaw, $format);
            // Formatierte Werte identisch (z.B. 350 vs 350.00) -> keine Aenderung.
            if ($oldDisplay === $newDisplay) {
                continue;
            }

            $target->{$field} = $newRaw;

            ContractRevision::create([
                'contract_id' => $contract->id,
                'batch_id' => $ctx['batch_id'],
                'field' => $field,
                'label' => $spec[$field]['label'],
                'old_value' => $oldDisplay,
                'new_value' => $newDisplay,
                'source' => $ctx['source'],
                'source_document_id' => $ctx['source_document_id'] ?? null,
                'changed_by' => $ctx['changed_by'] ?? null,
            ]);

            $changed[] = $field;
        }

        if ($target->isDirty()) {
            $target->save();
        }

        return $changed;
    }

    /** Neue batch_id fuer einen zusammengehoerigen Aenderungsvorgang. */
    public function newBatchId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Werte-Vergleich unabhaengig von Typ-Feinheiten (Zahl 350 == "350.00",
     * Datum-Objekt == "2026-07-21", Array-Reihenfolge egal).
     */
    private function equal($old, $new): bool
    {
        if ($old === null) {
            return false;
        }
        if (is_array($old) || is_array($new)) {
            $a = collect((array) $old)->map(fn ($v) => (string) $v)->sort()->values()->all();
            $b = collect((array) $new)->map(fn ($v) => (string) $v)->sort()->values()->all();
            return $a === $b;
        }
        if (is_bool($new) || is_bool($old)) {
            return (bool) $old === (bool) $new;
        }
        if (is_numeric($old) && is_numeric($new)) {
            return (float) $old === (float) $new;
        }
        return trim((string) $old) === trim((string) $new);
    }

    /** Rohwert fuer die Anzeige/Speicherung aufbereiten (String oder null). */
    private function display($value, ?callable $format): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if ($format !== null) {
            return $format($value);
        }
        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d.m.Y');
        }
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        return (string) $value;
    }
}
