<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Protokoll der Wechsel-Erinnerungen pro Vertrag (Paket C).
 * stage: first | followup. responded_at gesetzt = Kunde hat sich
 * gemeldet -> keine Folge-Erinnerung mehr für diese Periode.
 */
class ContractSwitchReminder extends Model {
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['contract_id','stage','anchor','sent_at','responded_at'];
    protected $casts = ['anchor' => 'date', 'sent_at' => 'datetime', 'responded_at' => 'datetime'];
    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = (string) Str::uuid());
    }
    public function contract() { return $this->belongsTo(Contract::class); }
}
