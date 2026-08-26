<?php
namespace App\Models;

use App\Support\CommissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Eine Provision aus einem FREMDSYSTEM, gebunden an einen Vertrag im Portal
 * (Betreiber-Auftrag 26.08.2026).
 *
 * INTERN UND VERTRAULICH. Dieses Modell darf NIE in einer Kunden-Antwort
 * auftauchen (Portal, Kunden-API, Kunden-E-Mail, Kunden-PDF). Deshalb gibt es
 * bewusst KEINE Beziehung von `Customer` hierher: wer die Provisionen eines
 * Kunden sehen will, muss es ausdruecklich abfragen - ein `with('customer')`
 * im Portal kann sie so nicht versehentlich mitladen.
 *
 * Ein Vertrag darf MEHRERE Provisionen haben (Abschluss + Bestand, Nachtrag,
 * Korrektur) - die Datenstruktur bildet das ab, statt sie zu erzwingen.
 */
class ContractCommission extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'import_id', 'internal_contract_number', 'internal_key',
        'external_contract_number', 'reference_number', 'vermittler_id',
        'order_number', 'external_id', 'contract_id', 'customer_id',
        'contract_label', 'customer_label', 'match_status', 'match_reason',
        'recipient_name', 'recipient_number', 'commission_type', 'product_name',
        'company', 'sparte', 'amount', 'currency', 'vat_amount', 'reserve_amount',
        'paid_amount', 'commission_date', 'due_date', 'payment_date', 'status',
        'storno_reason', 'invoice_number', 'invoice_date', 'invoice_amount',
        'invoice_linked_at', 'invoice_document_id', 'source_file', 'notes',
        'dedupe_key', 'row_hash', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'reserve_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'invoice_amount' => 'decimal:2',
        'commission_date' => 'date',
        'due_date' => 'date',
        'payment_date' => 'date',
        'invoice_date' => 'date',
        'invoice_linked_at' => 'datetime',
    ];

    /** Zuordnungs-Zustand: haben wir den Vertrag gefunden? */
    public const MATCH_ZUGEORDNET = 'zugeordnet';
    public const MATCH_OFFEN = 'offen';
    public const MATCH_MANUELL = 'manuell';

    public const MATCH_LABELS = [
        self::MATCH_ZUGEORDNET => 'Zugeordnet',
        self::MATCH_OFFEN => 'Nicht zugeordnet',
        self::MATCH_MANUELL => 'Manuell zugeordnet',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function contract() { return $this->belongsTo(Contract::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function import() { return $this->belongsTo(CommissionImport::class, 'import_id'); }
    public function invoiceDocument() { return $this->belongsTo(Document::class, 'invoice_document_id'); }
    public function auditLogs() { return $this->hasMany(CommissionAuditLog::class, 'commission_id')->latest('created_at'); }

    public function statusLabel(): string { return CommissionStatus::label($this->status); }
    public function statusBadge(): string { return CommissionStatus::badge($this->status); }
    public function statusIcon(): string { return CommissionStatus::icon($this->status); }

    public function matchLabel(): string
    {
        return self::MATCH_LABELS[$this->match_status] ?? $this->match_status;
    }

    public function isLinked(): bool
    {
        return $this->contract_id !== null;
    }

    /** Betrag mit Waehrung, deutsch formatiert. */
    public function amountLabel(): string
    {
        if ($this->amount === null) {
            return '—';
        }
        $symbol = $this->currency === 'EUR' ? '€' : (string) $this->currency;
        return number_format((float) $this->amount, 2, ',', '.') . ' ' . $symbol;
    }

    /** Noch offener Restbetrag (Teilzahlungen). */
    public function openAmount(): float
    {
        return round((float) $this->amount - (float) $this->paid_amount, 2);
    }

    /**
     * Status aus den DATEN ableiten, wenn die Quelle keinen mitliefert.
     * Reihenfolge ist Absicht: ein Zahlungsdatum ist ein FAKT, ein
     * Faelligkeitsdatum nur eine Erwartung. Geraten wird nichts - ohne
     * jeden Anhaltspunkt bleibt es `offen`.
     */
    public static function derive(?string $external, ?\DateTimeInterface $payment, ?\DateTimeInterface $due, ?float $paid, ?float $amount): string
    {
        $status = CommissionStatus::fromExternal($external);
        if ($status !== null) {
            return $status;
        }
        if ($payment !== null) {
            return ($paid !== null && $amount !== null && round($paid, 2) < round($amount, 2))
                ? CommissionStatus::TEILWEISE
                : CommissionStatus::BEZAHLT;
        }
        if ($paid !== null && $paid > 0 && $amount !== null && round($paid, 2) < round($amount, 2)) {
            return CommissionStatus::TEILWEISE;
        }
        if ($due !== null && $due <= now()->startOfDay()) {
            return CommissionStatus::FAELLIG;
        }
        return CommissionStatus::OFFEN;
    }

    public function scopeOutstanding($q)
    {
        return $q->whereIn('status', [CommissionStatus::OFFEN, CommissionStatus::FAELLIG, CommissionStatus::TEILWEISE]);
    }

    public function scopeUnmatched($q)
    {
        return $q->whereNull('contract_id');
    }
}
