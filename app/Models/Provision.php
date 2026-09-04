<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Vermittler-Provision (AUSGANG): Verguetung an einen Mitarbeiter ODER einen
 * Vertriebspartner fuer geworbene Neukunden/-vertraege. Nicht verwechseln mit
 * Commission (eingehende Provisionsgutschriften von Gesellschaften).
 *
 * Provisions-Management (25.07.2026): Provisionen entstehen AUTOMATISCH bei
 * Vertragsanlage (ContractProvisionService, Satz je Sparte aus
 * ProvisionRate) und durchlaufen den Workflow
 * offen -> freigegeben -> ausgezahlt (oder storniert). Wird ein Vertrag
 * gekuendigt/geloescht, entsteht eine negative GEGENBUCHUNG (type=storno,
 * related_provision_id) - Originale bleiben immer erhalten (Buchhaltung).
 * Jede Aenderung steht im ProvisionAuditLog.
 */
class Provision extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUSES = [
        'offen' => 'Offen',
        'freigegeben' => 'Freigegeben',
        'ausgezahlt' => 'Ausgezahlt',
        'storniert' => 'Storniert',
    ];

    /**
     * Buchungsarten: neuvertrag = automatische Anlage je Neuvertrag,
     * storno = automatische negative Gegenbuchung, bonus/abzug = manuelle
     * Korrekturen der Verwaltung, manuell = freie Erfassung (Altbestand).
     * Architektur bewusst erweiterbar (z.B. kuenftig 'bestandspflege' fuer
     * laufende monatliche Provisionen oder 'kampagne' fuer Aktions-Boni).
     */
    public const TYPES = [
        'neuvertrag' => 'Neuvertrag',
        'storno' => 'Storno-Abzug',
        'bonus' => 'Bonus',
        'abzug' => 'Abzug',
        'manuell' => 'Manuell',
    ];

    protected $fillable = [
        'user_id', 'partner_id', 'customer_id', 'contract_id', 'contract_type',
        'insurer', 'type', 'related_provision_id', 'period_from', 'period_to',
        'amount', 'currency', 'status', 'note', 'created_by',
        'approved_by', 'approved_at', 'paid_by', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'period_from' => 'date',
        'period_to' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo { return $this->belongsTo(Partner::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function contract() { return $this->belongsTo(Contract::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function payer() { return $this->belongsTo(User::class, 'paid_by'); }
    /** Original-Provision, auf die sich eine Storno-Gegenbuchung bezieht. */
    public function relatedProvision() { return $this->belongsTo(Provision::class, 'related_provision_id'); }
    /** Gegenbuchungen, die auf diese Provision zeigen. */
    public function counterBookings() { return $this->hasMany(Provision::class, 'related_provision_id'); }
    /** Unveraenderliche Aenderungshistorie, neueste zuerst. */
    public function auditLogs() { return $this->hasMany(ProvisionAuditLog::class)->orderByDesc('created_at'); }

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

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ($this->type ?? 'Manuell');
    }

    /** Sparten-Label des zugehoerigen Produkts (z.B. "KFZ"), sonst null. */
    public function contractTypeLabel(): ?string
    {
        if (! $this->contract_type) return null;
        return Contract::TYPES[$this->contract_type]['label']
            ?? Contract::LEGACY_TYPES[$this->contract_type]['label']
            ?? $this->contract_type;
    }

    /** Negative Buchung (Storno-Gegenbuchung oder Abzug)? */
    public function isDeduction(): bool
    {
        return (float) $this->amount < 0;
    }

    /**
     * Betrag darf nur angepasst werden, solange nichts ausgezahlt oder
     * storniert ist (Buchhaltung: abgeschlossene Buchungen sind unveraenderlich).
     */
    public function isAmountAdjustable(): bool
    {
        return in_array($this->status, ['offen', 'freigegeben'], true);
    }
}
