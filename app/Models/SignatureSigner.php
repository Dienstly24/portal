<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Ein Unterzeichner. Er braucht KEIN Dienstly24-Konto - er bekommt eine
 * Einladung mit einem Zugang, der nur zu diesem einen Dokument fuehrt.
 *
 * In der Datenbank steht ausschliesslich der HASH des Zugangs. Wer die
 * Tabelle liest (Sicherung, Auswertung, Datenbank-Werkzeug), kann damit
 * keine Unterschriftsseite oeffnen.
 */
class SignatureSigner extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const VIEWED = 'viewed';

    public const SIGNED = 'signed';

    public const DECLINED = 'declined';

    public const LABELS = [
        self::PENDING => 'Wartet',
        self::SENT => 'Eingeladen',
        self::VIEWED => 'Geöffnet',
        self::SIGNED => 'Unterschrieben',
        self::DECLINED => 'Abgelehnt',
    ];

    protected $fillable = [
        'signature_request_id', 'name', 'email', 'signing_order', 'status',
        'token_hash', 'token_created_at', 'token_expires_at', 'token_revoked_at',
        'verification_hash', 'verification_expires_at', 'verification_attempts', 'verified_at',
        'invited_at', 'reminded_at', 'reminder_count',
        'viewed_at', 'signed_at', 'declined_at', 'decline_reason',
        'ip_address', 'user_agent',
    ];

    protected $casts = [
        'signing_order' => 'integer',
        'verification_attempts' => 'integer',
        'reminder_count' => 'integer',
        'token_created_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'token_revoked_at' => 'datetime',
        'verification_expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'invited_at' => 'datetime',
        'reminded_at' => 'datetime',
        'viewed_at' => 'datetime',
        'signed_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    /**
     * Der Hash gehoert NIE in eine Ausgabe. $hidden ist die Absicherung fuer
     * den Fall, dass irgendwo ein toArray()/toJson() auf dem Modell landet -
     * dieselbe Vorsichtsmassnahme wie bei den Kanal-Zugangsdaten.
     */
    protected $hidden = ['token_hash', 'verification_hash'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id ??= (string) Str::uuid();
        });
    }

    public function request()
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    public function fields()
    {
        return $this->hasMany(SignatureField::class, 'signature_signer_id')->orderBy('page')->orderBy('sort');
    }

    public function statusLabel(): string
    {
        return self::LABELS[$this->status] ?? '—';
    }

    public function hasSigned(): bool
    {
        return $this->status === self::SIGNED;
    }

    public function hasDeclined(): bool
    {
        return $this->status === self::DECLINED;
    }

    /** Steht noch aus (weder unterschrieben noch abgelehnt)? */
    public function isPending(): bool
    {
        return ! $this->hasSigned() && ! $this->hasDeclined();
    }

    public function tokenIsLive(): bool
    {
        return $this->token_hash !== null
            && $this->token_revoked_at === null
            && ($this->token_expires_at === null || $this->token_expires_at->isFuture());
    }
}
