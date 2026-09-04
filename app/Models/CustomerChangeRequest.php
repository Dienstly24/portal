<?php

namespace App\Models;

use App\Services\ChangeRequest\ChangeProofPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Generischer Änderungsantrag eines Kunden (Self-Service).
 * Typen: family|address|email|phone|bank|contract|profile
 * Status: pending|approved|rejected
 * Die eigentlichen Daten werden erst bei Genehmigung über den
 * ChangeRequestService angewendet.
 *
 * Sensible Änderungen (Bank, Adresse, Name) tragen zusätzlich den
 * Nachweis (documents), das Ergebnis der automatischen Prüfung
 * (proof_status/proof_result) und das vom Kunden erfasste Datum, AB WANN
 * die Änderung gilt (effective_from).
 */
class CustomerChangeRequest extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'customer_id', 'requested_by', 'type', 'old_data', 'new_data',
        'status', 'requested_at', 'reviewed_by', 'reviewed_at', 'notes',
        'effective_from', 'proof_status', 'proof_result', 'proof_checked_at',
        'auto_approved',
    ];

    protected $casts = [
        'old_data' => 'encrypted:array',
        'new_data' => 'encrypted:array',
        'proof_result' => 'encrypted:array',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'proof_checked_at' => 'datetime',
        'effective_from' => 'date',
        'auto_approved' => 'boolean',
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

    /** Anzeige des Prüfergebnisses: Text, Farbe, Symbol. */
    public const PROOF_STATES = [
        'none' => ['label' => 'Kein Nachweis nötig', 'color' => '#5F6B62', 'icon' => '—'],
        'missing' => ['label' => 'Nachweis fehlt', 'color' => '#A32D2D', 'icon' => '⚠️'],
        'pending' => ['label' => 'Prüfung läuft', 'color' => '#B5651D', 'icon' => '⏳'],
        'verified' => ['label' => 'Nachweis bestätigt', 'color' => '#17A65B', 'icon' => '✅'],
        'partial' => ['label' => 'Teilweise bestätigt', 'color' => '#B5651D', 'icon' => '⚠️'],
        'mismatch' => ['label' => 'Nachweis passt NICHT', 'color' => '#A32D2D', 'icon' => '❌'],
        'unreadable' => ['label' => 'Nicht maschinell lesbar – bitte selbst prüfen', 'color' => '#5F6B62', 'icon' => '👁️'],
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
    public function documents() { return $this->hasMany(ChangeRequestDocument::class, 'change_request_id'); }
    public function notifications() { return $this->hasMany(ChangeNotification::class, 'change_request_id'); }

    public function scopePending($q) { return $q->where('status', 'pending'); }

    public function typeLabel(): string {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function proofState(): array {
        return self::PROOF_STATES[$this->proof_status] ?? self::PROOF_STATES['none'];
    }

    /** Nachweispflichtig? (Bank, Adresse, Name/Anschrift im Profil) */
    public function requiresProof(): bool {
        return app(ChangeProofPolicy::class)
            ->requiresProof($this->type, $this->new_data ?? []);
    }

    /** Prüfpunkte aus proof_result (leer, solange nicht geprüft). */
    public function proofChecks(): array {
        return $this->proof_result['checks'] ?? [];
    }
}
