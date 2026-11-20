<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Protokoll der Wechsel-Erinnerungen pro Vertrag (Paket C).
 * stage: first | followup. responded_at gesetzt = Kunde hat sich
 * gemeldet -> keine Folge-Erinnerung mehr für diese Periode.
 */
class ContractSwitchReminder extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['contract_id', 'stage', 'anchor', 'sent_at', 'responded_at', 'kurzreferenz'];
    protected $casts = ['anchor' => 'date', 'sent_at' => 'datetime', 'responded_at' => 'datetime'];
    protected static function boot() {
        parent::boot();
        static::creating(fn ($m) => $m->id = (string) Str::uuid());
    }
    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo { return $this->belongsTo(Contract::class); }

    /** Ohne leicht verwechselbare Zeichen (0/O, 1/I/L) - wird abgetippt. */
    private const ZEICHEN = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Kurze, nicht sprechende Referenz fuer WhatsApp ("R-7K3Q9X"). */
    public static function neueKurzreferenz(): string
    {
        do {
            $code = 'R-';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ZEICHEN[random_int(0, strlen(self::ZEICHEN) - 1)];
            }
        } while (static::where('kurzreferenz', $code)->exists());

        return $code;
    }
}
