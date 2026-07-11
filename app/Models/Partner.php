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

    protected $fillable = ['name', 'partner_number', 'contact_email', 'email_domains', 'iban', 'notes', 'is_active'];

    protected function casts(): array
    {
        return [
            'email_domains' => 'array',
            'is_active' => 'boolean',
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
