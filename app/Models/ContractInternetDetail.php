<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContractInternetDetail extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = [
        'contract_id', 'tariff', 'speed', 'upload_speed',
        // Preisvariabler DSL-Tarif: Aktionspreis fuer die ersten N Monate,
        // danach der regulaere Preis (EUR/Monat).
        'price_initial', 'price_initial_months', 'price_regular',
        // Router: inklusive oder mit monatlichem Aufpreis.
        'has_router', 'router_name', 'router_price',
        // Einmalige Vorteile beim Abschluss (Cashback/Bonus, Gutschein).
        'bonus_amount', 'voucher_amount',
        // Einmalige Kosten beim Abschluss (Bereitstellung/Anschluss, Versand)
        // und die Mindestlaufzeit (Monate) - beim Auftrag gibt es noch keinen
        // Anschlusstermin, Beginn/Ablauf sind also leer.
        'setup_fee', 'shipping_fee', 'min_duration_months',
    ];
    protected $casts = [
        'has_router' => 'boolean',
        'price_initial' => 'decimal:2',
        'price_regular' => 'decimal:2',
        'router_price' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'voucher_amount' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'price_initial_months' => 'integer',
        'min_duration_months' => 'integer',
    ];
    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
    public function contract() { return $this->belongsTo(Contract::class); }
}
