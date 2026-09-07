<?php

namespace App\Services\Messaging\Dto;

/**
 * Ergebnis eines Verbindungstests (Auftrag Abschnitte 70/97).
 *
 * Die Zustaende sind bewusst BENANNT und nicht "hat geklappt / hat nicht
 * geklappt": ein abgelaufenes Token, falsche Zugangsdaten und ein
 * ausgefallener Dienst verlangen drei verschiedene Handlungen. Ein
 * blosses "Fehler" laesst den Betreiber raten, welche.
 *
 * SICHERHEIT: `message` ist ein Satz fuer Menschen. Hier darf NIE ein
 * Token, ein Schluessel oder ein Teil davon hineingeraten - auch nicht
 * aus einer weitergereichten Fehlermeldung der Plattform.
 */
final class ConnectionTest
{
    public const CONNECTED = 'connected';
    public const INVALID_CREDENTIALS = 'invalid_credentials';
    public const EXPIRED = 'expired';
    public const NOT_CONFIGURED = 'not_configured';
    public const UNAVAILABLE = 'unavailable';
    public const RATE_LIMITED = 'rate_limited';
    public const NOT_SUPPORTED = 'not_supported';

    public const LABELS = [
        self::CONNECTED => 'Verbunden',
        self::INVALID_CREDENTIALS => 'Zugangsdaten abgelehnt',
        self::EXPIRED => 'Zugang abgelaufen',
        self::NOT_CONFIGURED => 'Noch nicht eingerichtet',
        self::UNAVAILABLE => 'Dienst nicht erreichbar',
        self::RATE_LIMITED => 'Zu viele Anfragen',
        self::NOT_SUPPORTED => 'Kein Verbindungstest moeglich',
    ];

    /** Ampelfarbe fuer die Oberflaeche - Zustand, keine Markenfarbe. */
    public const TONES = [
        self::CONNECTED => 'ok',
        self::INVALID_CREDENTIALS => 'fail',
        self::EXPIRED => 'fail',
        self::NOT_CONFIGURED => 'info',
        self::UNAVAILABLE => 'warn',
        self::RATE_LIMITED => 'warn',
        self::NOT_SUPPORTED => 'info',
    ];

    private function __construct(
        public readonly string $status,
        public readonly string $message,
    ) {}

    public static function make(string $status, string $message = ''): self
    {
        $status = isset(self::LABELS[$status]) ? $status : self::UNAVAILABLE;

        return new self($status, $message ?: self::LABELS[$status]);
    }

    public static function connected(string $message = ''): self
    {
        return self::make(self::CONNECTED, $message);
    }

    public function ok(): bool
    {
        return $this->status === self::CONNECTED;
    }

    public function label(): string
    {
        return self::LABELS[$this->status];
    }

    public function tone(): string
    {
        return self::TONES[$this->status];
    }
}
