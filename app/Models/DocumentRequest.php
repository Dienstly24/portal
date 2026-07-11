<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Dokumentenanfrage an einen Kunden (Architekturplan Abschnitte 9/14).
 * Status-Workflow: open -> uploaded -> approved | rejected (-> Kunde
 * lädt erneut hoch, Status zurück auf uploaded).
 */
class DocumentRequest extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'customer_id', 'contract_id', 'title', 'description', 'deadline',
        'status', 'document_id', 'rejection_note', 'requested_by',
        'reviewed_by', 'uploaded_at', 'reviewed_at',
    ];

    public const STATUSES = [
        'open' => 'Offen – bitte hochladen',
        'uploaded' => 'In Prüfung',
        'approved' => 'Abgeschlossen',
        'rejected' => 'Erneut benötigt',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'uploaded_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function contract() { return $this->belongsTo(Contract::class); }
    public function document() { return $this->belongsTo(Document::class); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function scopeOpenForCustomer($q) { return $q->whereIn('status', ['open', 'rejected']); }
    public function scopeAwaitingReview($q) { return $q->where('status', 'uploaded'); }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Kunde darf hochladen, solange die Anfrage nicht abgeschlossen/in Prüfung ist. */
    public function acceptsUpload(): bool
    {
        return in_array($this->status, ['open', 'rejected'], true);
    }
}
