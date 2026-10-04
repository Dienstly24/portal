<?php

namespace App\Models;

use App\Services\Matching\CustomerMergeUndoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Protokoll EINER Zusammenfuehrung (KI-064). Haelt fest, was ein spaeteres
 * Rueckgaengigmachen braucht: welche Zeilen je Tabelle umgehaengt wurden,
 * welche Kollisionszeilen dabei verworfen wurden (vollstaendig), welche
 * Felder der Hauptkunde vom Duplikat ergaenzt bekam und ob das Portalkonto
 * getauscht wurde.
 *
 * Das Protokoll ist VERSCHLUESSELT: verworfene Zeilen tragen Kundendaten.
 * Kein Aenderungsweg - es wird einmal geschrieben.
 *
 * @property array<string, mixed> $protokoll
 */
class CustomerMerge extends Model
{
    /**
     * So lange laesst sich eine Zusammenfuehrung zuruecknehmen. Danach
     * leert `kunden:zusammenfuehrungen-abschliessen` das Protokoll und die
     * Stammdaten der Huelle - die Kundennummer bleibt als Alias stehen.
     */
    public const RUECKGAENGIG_TAGE = 30;

    public function kannRueckgaengig(): bool
    {
        return app(CustomerMergeUndoService::class)->hindernisse($this) === [];
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'protokoll' => 'encrypted:array',
            'undone_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function primary(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'primary_customer_id');
    }

    /** Die archivierte Huelle - am globalen Scope vorbei, sie ist ja archiviert. */
    /** @return BelongsTo<Customer, $this> */
    public function duplicate(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'duplicate_customer_id')
            ->withoutGlobalScope(Customer::SCOPE_NICHT_ARCHIVIERT);
    }
}
