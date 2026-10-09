<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Weitere Person eines Vertrags (PR-6): versicherte Person, abweichender
 * Beitragszahler oder Beguenstigter. Der Versicherungsnehmer steht NICHT
 * hier, sondern bleibt contracts.customer_id.
 *
 * Einzige Schreibstelle: App\Services\Vertrag\VertragBeteiligteService.
 *
 * @property int $id
 * @property string $contract_id
 * @property string|null $customer_id
 * @property string $rolle
 * @property string $name
 * @property Carbon|null $geburtsdatum
 * @property string|null $anteil_prozent
 * @property string|null $notiz
 */
class VertragBeteiligter extends Model
{
    protected $table = 'vertrag_beteiligte';

    public const ROLLE_VERSICHERT = 'versicherte_person';
    public const ROLLE_BEITRAGSZAHLER = 'beitragszahler';
    public const ROLLE_BEGUENSTIGT = 'beguenstigter';

    /** Rollen in Anzeige-Reihenfolge. */
    public const ROLLEN = [
        self::ROLLE_VERSICHERT => 'Versicherte Person',
        self::ROLLE_BEITRAGSZAHLER => 'Beitragszahler (abweichend)',
        self::ROLLE_BEGUENSTIGT => 'Begünstigter',
    ];

    protected $fillable = [
        'contract_id', 'customer_id', 'rolle', 'name', 'geburtsdatum',
        'anteil_prozent', 'notiz', 'created_by',
    ];

    protected $casts = [
        'geburtsdatum' => 'date',
        'anteil_prozent' => 'decimal:2',
    ];

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function rolleLabel(): string
    {
        return self::ROLLEN[$this->rolle] ?? $this->rolle;
    }

    /**
     * Name fuer die Anzeige: die LEBENDE Akte, sonst die Kopie (Person ohne
     * Akte oder Akte inzwischen geloescht).
     */
    public function anzeigeName(): string
    {
        return $this->customer?->user?->name ?: $this->name;
    }

    /** Kurztext fuer den Vertragsverlauf ("Name, geb. ..., 50 %"). */
    public function kurztext(): string
    {
        // Bewusst OHNE Kundennummer: der Verlauf ist fuer jeden lesbar, der den
        // Vertrag oeffnen darf - auch wenn die beteiligte Akte ausserhalb
        // seines Bestands liegt.
        $teile = [$this->anzeigeName()];
        if ($this->geburtsdatum) {
            $teile[] = 'geb. '.$this->geburtsdatum->format('d.m.Y');
        }
        if ($this->anteil_prozent !== null) {
            $teile[] = rtrim(rtrim(number_format((float) $this->anteil_prozent, 2, ',', ''), '0'), ',').' %';
        }

        return implode(', ', $teile);
    }
}
