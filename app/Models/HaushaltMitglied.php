<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mitgliedschaft einer Kundenakte in einem Haushalt (PR-5b).
 *
 * `valid_until` = Tag des AUSZUGS (erster Tag, an dem die Person nicht mehr
 * dazugehoert). Aktuell ist eine Mitgliedschaft, solange dieser Tag fehlt
 * oder in der Zukunft liegt. Beendet wird sie nie durch Loeschen.
 */
class HaushaltMitglied extends Model
{
    protected $table = 'haushalt_mitglieder';

    protected $fillable = [
        'haushalt_id', 'customer_id', 'hauptansprechpartner', 'beitragszahler',
        'valid_from', 'valid_until', 'created_by',
    ];

    protected $casts = [
        'hauptansprechpartner' => 'boolean',
        'beitragszahler' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    /** @param Builder<HaushaltMitglied> $query */
    public function scopeAktuell(Builder $query): Builder
    {
        $heute = Carbon::today()->toDateString();

        return $query->where(fn ($q) => $q->whereNull('haushalt_mitglieder.valid_until')
            ->orWhereDate('haushalt_mitglieder.valid_until', '>', $heute));
    }

    public function istAktuell(): bool
    {
        return $this->valid_until === null || $this->valid_until->gt(Carbon::today());
    }

    /** @return BelongsTo<Haushalt, $this> */
    public function haushalt(): BelongsTo
    {
        return $this->belongsTo(Haushalt::class, 'haushalt_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
