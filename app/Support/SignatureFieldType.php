<?php

namespace App\Support;

/**
 * Die Feldarten, die auf ein Dokument gesetzt werden koennen.
 *
 * Bewusst kurz gehalten (Betreiber-Vorgabe: kein DocuSign-Nachbau). Jede
 * weitere Art muss sich beim Unterschreiben UND beim Stempeln ins fertige
 * PDF beweisen - eine Feldart, die nur im Editor existiert, ist eine
 * Zusage, die das Dokument nicht einloest.
 */
final class SignatureFieldType
{
    public const SIGNATURE = 'unterschrift';

    public const INITIALS = 'initialen';

    public const NAME = 'name';

    public const DATE = 'datum';

    public const TEXT = 'text';

    public const CHECKBOX = 'kreuz';

    /**
     * Ein hinterlegtes FIRMENBILD (Unternehmenssignatur, Stempel, Logo).
     *
     * Es ist die einzige Feldart, die KEINEM Unterzeichner gehoert: sie
     * wird vom Mitarbeiter beim Vorbereiten gesetzt und ist damit schon
     * fertig, bevor die Einladung rausgeht. Niemand wird dafuer
     * eingeladen, niemand stimmt dafuer zu - und im Protokoll steht
     * deshalb "eingesetzt von", nicht "unterschrieben von".
     */
    public const COMPANY = 'firma';

    public const LABELS = [
        self::SIGNATURE => 'Unterschrift',
        self::INITIALS => 'Initialen',
        self::NAME => 'Name',
        self::DATE => 'Datum',
        self::TEXT => 'Textfeld',
        self::CHECKBOX => 'Kästchen',
        self::COMPANY => 'Firmenbild',
    ];

    /** Feldarten, die als HANDSCHRIFT gezeichnet werden (Finger/Maus). */
    public const DRAWN = [self::SIGNATURE, self::INITIALS];

    /**
     * Vorgabegroesse eines neuen Feldes, anteilig zur Seite. Eine
     * Unterschrift braucht Platz; ein Kreuz waere in derselben Groesse ein
     * Klotz.
     *
     * @return array{0: float, 1: float}
     */
    public static function defaultSize(string $type): array
    {
        return match ($type) {
            self::SIGNATURE, self::COMPANY => [0.28, 0.055],
            self::INITIALS => [0.10, 0.045],
            self::CHECKBOX => [0.025, 0.018],
            self::DATE => [0.16, 0.025],
            default => [0.22, 0.028],
        };
    }

    /** Feldarten, die ein BILD tragen (Handschrift oder Firmenbild). */
    public static function isImage(string $type): bool
    {
        return self::isDrawn($type) || $type === self::COMPANY;
    }

    public static function isDrawn(string $type): bool
    {
        return in_array($type, self::DRAWN, true);
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }
}
