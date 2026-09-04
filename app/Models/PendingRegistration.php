<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Vorgemerkte Selbst-Registrierung (Audit SEC-1).
 *
 * Siehe Migration: bis zur Bestaetigung der E-Mail-Adresse entsteht
 * KEIN User, KEINE Kundenakte und - der eigentliche Punkt - KEINE
 * Kundennummer.
 */
class PendingRegistration extends Model
{
    protected $fillable = [
        'email', 'first_name', 'last_name', 'birth_date', 'password',
        'token_hash', 'email_consent', 'preferred_lang', 'register_ip',
        'send_count', 'last_sent_at', 'expires_at',
    ];

    /**
     * `password` ist bereits ein bcrypt-Hash und darf NICHT als
     * 'hashed' gecastet werden (das wuerde ihn ein zweites Mal hashen).
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'email_consent' => 'boolean',
            'last_sent_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** Der Bestaetigungslink gilt 24 Stunden. */
    public const LIFETIME_HOURS = 24;

    /**
     * Hoechstens so viele Bestaetigungsmails je Vormerkung.
     *
     * "Erneut senden" ist der Weg, ueber den sich eine FREMDE Adresse
     * zuspammen laesst: Angreifer traegt fremde Adresse ein und loest
     * dann in Serie den Versand aus. Der Zaehler deckelt das dauerhaft -
     * anders als ein Zeit-Throttle, der nach Ablauf wieder Luft gibt.
     */
    public const MAX_SENDS = 5;

    /** Frueheste Wiederholung, damit ein Klick nicht sofort Mails stapelt. */
    public const RESEND_COOLDOWN_SECONDS = 120;

    protected $hidden = ['password', 'token_hash'];

    /**
     * Erzeugt ein Token und gibt den KLARTEXT zurueck - gespeichert wird
     * nur der Hash. Der Klartext existiert damit ausschliesslich in der
     * einen Mail, die gerade rausgeht.
     */
    public static function newToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $token): string
    {
        // sha256 statt bcrypt: der Wert ist bereits 64 Zeichen Zufall,
        // ein Brute-Force-Schutz gegen schwache Eingaben wird nicht
        // gebraucht - wohl aber ein schneller, indizierbarer Vergleich.
        return hash('sha256', $token);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    /** Darf jetzt (erneut) eine Bestaetigungsmail rausgehen? */
    public function mayResend(): bool
    {
        if ($this->send_count >= self::MAX_SENDS) {
            return false;
        }

        if ($this->last_sent_at === null) {
            return true;
        }

        return $this->last_sent_at->addSeconds(self::RESEND_COOLDOWN_SECONDS)->isPast();
    }

    public static function freshExpiry(): Carbon
    {
        return now()->addHours(self::LIFETIME_HOURS);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }
}
