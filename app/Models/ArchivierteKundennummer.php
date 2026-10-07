<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine Kundennummer, die nicht mehr gilt, aber belegt bleibt
 * (KI-094, 07.10.2026). Typischer Fall: einem Kind unter dem
 * Selbststaendigkeitsalter wurde eine eigene Nummer vergeben. Die Nummer
 * wird nie geloescht und nie neu vergeben - sie kann auf Schreiben stehen.
 */
class ArchivierteKundennummer extends Model
{
    protected $table = 'archivierte_kundennummern';

    public const GRUND_MINDERJAEHRIG = 'minderjaehrig';

    protected $fillable = [
        'customer_number', 'customer_id', 'bezugsperson_customer_id', 'grund', 'notiz', 'archiviert_von',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function bezugsperson(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'bezugsperson_customer_id');
    }
}
