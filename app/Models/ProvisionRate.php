<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Provisions-Satz je Empfaenger und SPARTE (Provisions-Management):
 * Mitarbeiter A bekommt fuer eine GKV z.B. 50 EUR, Mitarbeiter B 40 EUR,
 * Partner X 60 EUR. Empfaenger ist genau EINER von beiden (user_id ODER
 * partner_id). Fehlt der Sparten-Satz, faellt die Berechnung auf den
 * globalen Satz am Mitarbeiter/Partner zurueck (provision_fixed/-percent).
 */
class ProvisionRate extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id', 'partner_id', 'contract_type', 'amount_fixed', 'amount_percent',
    ];

    protected $casts = [
        'amount_fixed' => 'decimal:2',
        'amount_percent' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function user() { return $this->belongsTo(User::class); }
    public function partner() { return $this->belongsTo(Partner::class); }

    /** Hat dieser Satz ueberhaupt einen Wert? (leere Saetze werden geloescht) */
    public function hasValue(): bool
    {
        return $this->amount_fixed !== null || $this->amount_percent !== null;
    }
}
