<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Bestaetigte Beziehung zwischen zwei Kunden ("Verwandte Kunden"), die
 * bewusst NICHT als Dublette gelten (z. B. Familie mit gleicher Anschrift).
 * Das Paar wird immer in fester Reihenfolge gespeichert (a < b).
 */
class CustomerRelationship extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['customer_a_id', 'customer_b_id', 'type', 'note', 'created_by'];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = (string) Str::uuid());
    }

    public function customerA()
    {
        return $this->belongsTo(Customer::class, 'customer_a_id');
    }

    public function customerB()
    {
        return $this->belongsTo(Customer::class, 'customer_b_id');
    }

    /** Reihenfolge-unabhaengiger Schluessel eines Paares (a < b). */
    public static function pairKey(string $x, string $y): array
    {
        return $x < $y ? [$x, $y] : [$y, $x];
    }

    /** Set aller markierten Paare als "a|b"-Schluessel (fuer schnellen Ausschluss). */
    public static function dismissedKeySet(): array
    {
        return static::query()
            ->get(['customer_a_id', 'customer_b_id'])
            ->mapWithKeys(fn ($r) => [$r->customer_a_id . '|' . $r->customer_b_id => true])
            ->all();
    }
}
