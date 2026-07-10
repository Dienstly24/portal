<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Generischer Änderungsantrag eines Kunden (Self-Service).
 * Typen: family|address|email|phone|bank|contract|profile
 * Status: pending|approved|rejected
 * Die eigentlichen Daten werden erst bei Genehmigung über den
 * ChangeRequestService angewendet.
 */
class CustomerChangeRequest extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'customer_id', 'requested_by', 'type', 'old_data', 'new_data',
        'status', 'requested_at', 'reviewed_by', 'reviewed_at', 'notes',
    ];

    protected $casts = [
        'old_data' => 'encrypted:array',
        'new_data' => 'encrypted:array',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public const TYPES = ['family', 'address', 'email', 'phone', 'bank', 'contract', 'profile'];

    public const TYPE_LABELS = [
        'family' => 'Familienmitglied',
        'address' => 'Adresse',
        'email' => 'E-Mail-Adresse',
        'phone' => 'Telefonnummer',
        'bank' => 'Bankverbindung',
        'contract' => 'Vertrag',
        'profile' => 'Profildaten',
    ];

    protected static function boot() {
        parent::boot();
        static::creating(function ($m) {
            $m->id = $m->id ?: (string) Str::uuid();
            $m->requested_at = $m->requested_at ?: now();
        });
    }

    public function customer() { return $this->belongsTo(Customer::class); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }

    public function scopePending($q) { return $q->where('status', 'pending'); }

    public function typeLabel(): string {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }
}
