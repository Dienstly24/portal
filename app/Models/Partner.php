<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Makler-/Vertriebspartner bzw. Gesellschaft, von der Provisions-
 * gutschriften eingehen (Architekturplan Abschnitte 10/16). Bewusst
 * getrennt vom Kundenmodell - ein Partner ist nie ein Customer.
 */
class Partner extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['name', 'user_id', 'partner_number', 'contact_email', 'email_domains', 'iban', 'notes', 'is_active', 'logo_path', 'provision_fixed', 'provision_percent'];

    protected function casts(): array
    {
        return [
            'email_domains' => 'array',
            'is_active' => 'boolean',
            'provision_fixed' => 'decimal:2',
            'provision_percent' => 'decimal:2',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function commissions()
    {
        return $this->hasMany(Commission::class)->latest('statement_date');
    }

    /** Login-Account des Partners (role=partner), optional. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Diesem Partner zugeordnete Kunden. */
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    /** Vom Partner geworbene Kunden (Neukunden-Bericht/Provision). */
    public function acquiredCustomers()
    {
        return $this->hasMany(Customer::class, 'acquired_by_partner_id');
    }

    /** Sparten-Provisionssaetze dieses Partners (Provisions-Management). */
    public function provisionRates()
    {
        return $this->hasMany(ProvisionRate::class);
    }

    public function externalReferences()
    {
        return $this->morphMany(ExternalReference::class, 'referenceable');
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /** Summe aller gebuchten Provisionen (Partnerhistorie). */
    public function bookedTotal(): float
    {
        return (float) $this->commissions()->where('status', 'booked')->sum('amount');
    }
}
