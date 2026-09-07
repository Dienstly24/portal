<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Konto innerhalb eines Kanals (eine WhatsApp-Nummer, eine
 * Facebook-Seite). Mehrere je Kanal sind ausdruecklich vorgesehen.
 *
 * SICHERHEIT: `credentials` traegt Zugangsdaten und ist deshalb
 * VERSCHLUESSELT gecastet. Sie duerfen nie ins Repository, nie ins
 * Frontend, nie in ein Log und nie in eine Ausnahme geraten - deshalb
 * stehen sie zusaetzlich in `$hidden`, damit ein versehentliches
 * toArray()/toJson() sie nicht mit ausliefert.
 */
class ChannelAccount extends Model
{
    protected $fillable = [
        'channel_id', 'name', 'external_account_id', 'credentials',
        'token_expires_at', 'settings', 'is_active', 'ai_mode',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'token_expires_at' => 'datetime',
    ];

    protected $hidden = ['credentials'];

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo { return $this->belongsTo(Channel::class); }
    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }

    public function scopeActive($q) { return $q->where('is_active', true); }

    /** Einzelner Zugangswert - nie das ganze Array nach aussen geben. */
    public function credential(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Ein Zugang, dessen Ablauf bekannt UND vergangen ist, gilt als
     * abgelaufen. Ist kein Ablauf hinterlegt (Dauer-Token), gilt er als
     * gueltig - "unbekannt" darf den Betrieb nicht anhalten.
     */
    public function tokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /**
     * Zugangsdaten ersetzen (Token-Rotation). Bewusst der EINE
     * Schreibweg, damit jede Aenderung protokolliert wird - und zwar
     * OHNE den Wert selbst.
     */
    public function rotateCredentials(array $credentials, ?\DateTimeInterface $expiresAt = null): void
    {
        $this->credentials = $credentials;
        $this->token_expires_at = $expiresAt === null ? null : Carbon::instance($expiresAt);
        $this->save();

        // Protokolliert werden nur die SCHLUESSEL, nie die Werte.
        ActivityLog::record('channel_account_credentials_rotated', 'channel_account', $this->id, [
            'name' => $this->name,
            'keys' => array_keys($credentials),
        ]);
    }
}
