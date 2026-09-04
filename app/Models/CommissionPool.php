<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ein PROVISIONS-POOL (Betreiber-Auftrag 02.09.2026): CHECK24, Maklerpool,
 * Energie-Pool, Fonds Finanz - und jeder weitere, der noch kommt.
 *
 * WARUM EINE TABELLE UND KEINE KONSTANTE: Ein neuer Pool ist im Alltag eine
 * EINSTELLUNG (Name, Fristen), keine Code-Aenderung. Waeren die Pools eine
 * Liste im Quelltext, brauchte jede neue Zusammenarbeit ein Deployment - und
 * bis dahin liefen die Vertraege dieses Pools ohne Frist, also unsichtbar.
 *
 * DIE FRISTEN SIND DER KERN: `expected_months` sagt, ab wann eine Provision
 * ueberfaellig WIRKT, `check_months`, ab wann sie als FEHLEND gilt. Ohne
 * diese zwei Zahlen stuende jeder frische Vertrag sofort als "Provision
 * fehlt" da - und die Liste, die Probleme zeigen soll, waere Rauschen.
 */
class CommissionPool extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'key', 'name', 'source_profile', 'expected_months', 'check_months',
        'active', 'contact', 'notes',
    ];

    protected $casts = [
        'expected_months' => 'integer',
        'check_months' => 'integer',
        'active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function scopeActive($q)
    {
        return $q->where('active', true);
    }

    /** Fristen als Klartext - dieselbe Formulierung in Liste und Detail. */
    public function deadlineLabel(): string
    {
        return 'Erwartet nach '.$this->expected_months.' Monaten, Prüffrist '
            .$this->check_months.' Monate';
    }
}
