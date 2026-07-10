<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Einzelne Provisionsgutschrift (Architekturplan Abschnitt 10).
 * Status-Workflow (HITL, Abschnitt 13): pending_review -> booked|rejected.
 * Der Lexoffice-Beleg wird erst bei der Buchung durch einen Mitarbeiter
 * erzeugt - nie automatisch ("nie stillschweigend falsche Beträge buchen").
 */
class Commission extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'partner_id', 'contract_id', 'credit_note_number', 'amount', 'currency',
        'statement_date', 'status', 'lexoffice_voucher_id', 'email_message_id',
        'reviewed_by', 'reviewed_at',
    ];

    public const STATUSES = [
        'pending_review' => 'Zu prüfen',
        'booked' => 'Gebucht',
        'rejected' => 'Abgelehnt',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'statement_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function partner() { return $this->belongsTo(Partner::class); }
    public function contract() { return $this->belongsTo(Contract::class); }
    public function emailMessage() { return $this->belongsTo(EmailMessage::class); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function scopePendingReview($q) { return $q->where('status', 'pending_review'); }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
