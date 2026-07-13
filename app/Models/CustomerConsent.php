<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Nachweisbare, getrennte und widerrufbare Einwilligung eines Kunden
 * (DSGVO Art. 7). Aktuell genutzt fuer die E-Mail-Verbindung
 * (type=email_processing): Der Kunde erlaubt, dass an sein persoenliches
 * Import-Postfach weitergeleitete VERTRAGSBEZOGENE Mails verarbeitet
 * werden. Widerruf = revoked_at setzen; ab dann wird nichts mehr
 * verarbeitet.
 */
class CustomerConsent extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_EMAIL_PROCESSING = 'email_processing';

    /** Version des exakt akzeptierten Einwilligungstextes (Nachweisbarkeit). */
    public const EMAIL_TEXT_VERSION = '2026-07-13.v1';

    protected $fillable = [
        'customer_id', 'type', 'granted_at', 'revoked_at',
        'consent_text_version', 'ip_address', 'user_agent', 'source', 'import_token',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(fn ($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /** Aktiv = erteilt und nicht widerrufen. */
    public function scopeActive($q)
    {
        return $q->whereNotNull('granted_at')->whereNull('revoked_at');
    }

    public function scopeEmailProcessing($q)
    {
        return $q->where('type', self::TYPE_EMAIL_PROCESSING);
    }

    public function isActive(): bool
    {
        return $this->granted_at !== null && $this->revoked_at === null;
    }

    /** Eindeutiges, schwer zu erratendes Import-Token erzeugen. */
    public static function newImportToken(): string
    {
        do {
            $token = Str::lower(Str::random(16));
        } while (static::where('import_token', $token)->exists());

        return $token;
    }
}
