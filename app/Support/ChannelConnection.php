<?php

namespace App\Support;

/**
 * WIE ein Kanal-Konto angebunden ist und WIE es ihm gerade geht.
 *
 * Zwei getrennte Angaben, und das ist der Kern der Sache (Auftrag 35):
 * eine funktionierende Cloud-API-Verbindung beweist NICHT, dass die
 * Coexistence eingerichtet ist. Es sind zwei verschiedene Freigaben von
 * Meta. Wer beides in einen Zustand "verbunden" wirft, liest spaeter
 * "verbunden" und wundert sich, warum Nachrichten aus der Business App
 * nicht ankommen.
 *
 * Der Zustand wird deshalb GESETZT, wenn etwas Belegbares passiert ist -
 * nie aus dem blossen Vorhandensein eines Tokens abgeleitet.
 */
final class ChannelConnection
{
    /** Anbindungsart. */
    public const TYPE_CLOUD_API = 'cloud_api';
    public const TYPE_COEXISTENCE = 'coexistence';

    public const TYPES = [
        self::TYPE_CLOUD_API => 'Cloud API',
        self::TYPE_COEXISTENCE => 'Coexistence (Business App + Cloud API)',
    ];

    /** Verbindungszustand. */
    public const NOT_CONNECTED = 'not_connected';
    public const PENDING = 'pending';
    public const CONNECTED = 'connected';
    public const AUTH_ERROR = 'auth_error';
    public const WEBHOOK_ERROR = 'webhook_error';
    public const DISCONNECTED = 'disconnected';

    public const STATUSES = [
        self::NOT_CONNECTED => 'Nicht verbunden',
        self::PENDING => 'Verbindung ausstehend',
        self::CONNECTED => 'Verbunden',
        self::AUTH_ERROR => 'Authentifizierungsfehler',
        self::WEBHOOK_ERROR => 'Webhook-Fehler',
        self::DISCONNECTED => 'Getrennt',
    ];

    /**
     * Der Zustand IM KLARTEXT, mit der Anbindungsart darin.
     *
     * "Verbunden - Coexistence" und "Verbunden - Cloud API" sind zwei
     * verschiedene Aussagen. Sie hier zusammenzuziehen waere bequem und
     * genau die Verwechslung, die der Auftrag ausschliesst.
     */
    public static function label(?string $status, ?string $type): string
    {
        $zustand = self::STATUSES[$status ?? self::NOT_CONNECTED] ?? self::STATUSES[self::NOT_CONNECTED];

        if (($status ?? '') !== self::CONNECTED) {
            return $zustand;
        }

        return $zustand.' - '.(self::TYPES[$type ?? self::TYPE_CLOUD_API] ?? self::TYPES[self::TYPE_CLOUD_API]);
    }

    /** Ampelfarbe fuer die Oberflaeche. */
    public static function tone(?string $status): string
    {
        return match ($status) {
            self::CONNECTED => 'ok',
            self::PENDING => 'warn',
            self::AUTH_ERROR, self::WEBHOOK_ERROR, self::DISCONNECTED => 'error',
            default => 'muted',
        };
    }

    /**
     * Coexistence gilt NUR als eingerichtet, wenn sie ausdruecklich
     * vermerkt wurde UND die Verbindung steht. Ein Token allein reicht
     * nie - genau das ist Auftrag 35.
     */
    public static function isCoexistence(?string $status, ?string $type): bool
    {
        return $status === self::CONNECTED && $type === self::TYPE_COEXISTENCE;
    }
}
