<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContractInternetDetail extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['contract_id','tariff','speed'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }
    public function contract() { return $this->belongsTo(Contract::class); }
}
