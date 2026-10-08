<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Ein Haushalt: mehrere Kundenakten, die zusammen wohnen (PR-5b).
 * Geschrieben wird ausschliesslich ueber den HaushaltService.
 */
class Haushalt extends Model
{
    protected $table = 'haushalte';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['name', 'herkunft', 'notiz', 'created_by'];

    public const HERKUNFT_MANUELL = 'manuell';

    public const HERKUNFT_UEBERNAHME = 'uebernahme';

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    /** @return HasMany<HaushaltMitglied, $this> */
    public function mitglieder(): HasMany
    {
        return $this->hasMany(HaushaltMitglied::class, 'haushalt_id');
    }

    /** @return HasMany<HaushaltMitglied, $this> */
    public function aktuelleMitglieder(): HasMany
    {
        return $this->mitglieder()->aktuell();
    }

    /** @return BelongsTo<User, $this> */
    public function ersteller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
