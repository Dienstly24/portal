<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ein Eintrag im Vertragsverlauf eines Kunden (je Sparte): welcher Anbieter,
 * in welchem Zeitraum, aus welchem Grund. Ein offener Eintrag
 * (effective_until = null) ist der aktuell laufende.
 */
class ContractHistory extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'customer_id', 'contract_id', 'branch', 'provider', 'role',
        'effective_from', 'effective_until', 'reason', 'source_document_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /** Laufende (nicht beendete) Eintraege. */
    public function scopeOpen($q)
    {
        return $q->whereNull('effective_until');
    }
}
