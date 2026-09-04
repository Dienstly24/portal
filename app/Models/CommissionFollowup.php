<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Der Bearbeitungsstand einer FEHLENDEN Provision (§19).
 *
 * "Provision fehlt" ist eine Feststellung; erst der Bearbeitungsstand macht
 * daraus einen Vorgang: Pool kontaktiert -> Antwort erhalten -> geklaert.
 * Ohne ihn steht dieselbe Zeile jeden Monat neu auf der Liste, und niemand
 * weiss, ob schon jemand nachgefragt hat.
 *
 * INTERN: Diese Notizen gehoeren zum Provisionsteil und unterliegen
 * derselben engen Sichtbarkeit - sie erscheinen nie in der Kundenakte.
 */
class CommissionFollowup extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'contract_id', 'status', 'contacted_on', 'contact_person',
        'response', 'note', 'updated_by',
    ];

    protected $casts = ['contacted_on' => 'date'];

    /** Bearbeitungsstand => Anzeige. */
    public const STATUSES = [
        'offen' => 'Offen',
        'pool_kontaktiert' => 'Pool kontaktiert',
        'in_klaerung' => 'In Klärung',
        'geklaert' => 'Geklärt',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function contract() { return $this->belongsTo(Contract::class); }
    public function editor() { return $this->belongsTo(User::class, 'updated_by'); }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
