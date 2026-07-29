<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Vorbereitete Mitteilung an eine Gesellschaft ("Bitte neue Bankverbindung
 * / Adresse ab dem ... beruecksichtigen"). Entsteht AUTOMATISCH, sobald
 * eine sensible Kundenaenderung freigegeben wurde - je Gesellschaft des
 * Kunden genau EINE Mitteilung (mit allen betroffenen Vertragsnummern).
 *
 * Der Text ist fertig formuliert; ein Mitarbeiter prueft, ergaenzt die
 * Empfaengeradresse und sendet mit einem Klick (Nachweis als Anhang).
 * Nichts wird ohne Mitarbeiter verschickt - nach aussen geht nie eine
 * automatische E-Mail.
 */
class ChangeNotification extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'change_request_id', 'customer_id', 'insurer', 'contract_numbers',
        'recipient', 'subject', 'body', 'status', 'channel', 'sent_at',
        'sent_by', 'note',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public const STATUS_LABELS = [
        'pending' => 'Offen',
        'sent' => 'Gesendet',
        'skipped' => 'Nicht nötig',
    ];

    public const CHANNEL_LABELS = [
        'email' => 'E-Mail',
        'post' => 'Post',
        'portal' => 'Portal der Gesellschaft',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($m) {
            $m->id = $m->id ?: (string) Str::uuid();
        });
    }

    public function changeRequest()
    {
        return $this->belongsTo(CustomerChangeRequest::class, 'change_request_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function scopeOpen($q)
    {
        return $q->where('status', 'pending');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
