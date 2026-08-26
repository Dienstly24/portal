<?php
namespace App\Services\CommissionImport;

use App\Models\CommissionAuditLog;
use App\Models\ContractCommission;

/**
 * Schreibt das Provisions-Protokoll. Eine eigene Klasse, damit JEDER
 * Schreibweg (Import, Formular, Zahlung, Rechnung) durch dieselbe Tuer geht -
 * ein Protokoll mit Luecken ist schlimmer als keines, weil man sich darauf
 * verlaesst.
 *
 * Das Protokollieren darf den Vorgang NIE zum Scheitern bringen (gleiche
 * Regel wie bei den geplanten Aufgaben und beim Fehler-Recorder): ein Fehler
 * hier wird geschluckt, sonst schluege ein Zahlungsvermerk fehl, weil sein
 * Protokolleintrag nicht geschrieben werden konnte.
 */
class CommissionAuditLogger
{
    /**
     * @param array<string,mixed> $attributes zusaetzliche Felder
     */
    public function log(string $action, ?ContractCommission $commission = null, array $attributes = []): void
    {
        try {
            $user = auth()->user();
            CommissionAuditLog::create(array_merge([
                'user_id' => $user?->id,
                'user_label' => $user?->name ?? 'System',
                'action' => $action,
                'commission_id' => $commission?->id,
                'contract_id' => $commission?->contract_id,
                'internal_contract_number' => $commission?->internal_contract_number,
                'source_file' => $commission?->source_file,
            ], $attributes));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Feldaenderungen protokollieren - eine Zeile je Feld. Bewusst
     * feingranular: "Provision bearbeitet" allein beantwortet die Frage
     * "wer hat den Betrag geaendert?" nicht.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<int,string> $fields
     */
    public function changes(string $action, ContractCommission $commission, array $before, array $after, array $fields): void
    {
        foreach ($fields as $field) {
            $old = $this->stringify($before[$field] ?? null);
            $new = $this->stringify($after[$field] ?? null);
            if ($old === $new) {
                continue;
            }
            $this->log($action, $commission, ['field' => $field, 'old_value' => $old, 'new_value' => $new]);
        }
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return mb_substr((string) $value, 0, 500);
    }
}
