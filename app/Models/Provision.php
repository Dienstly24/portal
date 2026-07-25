<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Vermittler-Provision (AUSGANG): Verguetung an einen Mitarbeiter ODER einen
 * Vertriebspartner fuer geworbene Neukunden. Nicht verwechseln mit
 * Commission (eingehende Provisionsgutschriften von Gesellschaften).
 */
class Provision extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUSES = [
        'offen'      => 'Offen',
        'ausgezahlt' => 'Ausgezahlt',
        'storniert'  => 'Storniert',
    ];

    protected $fillable = [
        'user_id', 'partner_id', 'customer_id', 'period_from', 'period_to',
        'amount', 'currency', 'status', 'note', 'created_by', 'paid_by', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'period_from' => 'date',
        'period_to' => 'date',
        'paid_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function user() { return $this->belongsTo(User::class); }
    public function partner() { return $this->belongsTo(Partner::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function payer() { return $this->belongsTo(User::class, 'paid_by'); }

    public function scopeOffen($q) { return $q->where('status', 'offen'); }

    /** Anzeigename des Empfaengers (Mitarbeiter oder Partner). */
    public function recipientName(): string
    {
        if ($this->user_id) {
            return $this->user?->name ?? 'Geloeschter Mitarbeiter';
        }
        return $this->partner?->name ?? 'Geloeschter Partner';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
