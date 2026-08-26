<?php
namespace App\Support;

/**
 * Die EINE Quelle fuer den Zustand einer Provision (Betreiber-Vorgabe
 * 26.08.2026). Statuswerte stehen nie als Zeichenkette im Controller oder in
 * einer View - sonst laufen Liste, Filter und Vertragsakte auseinander.
 *
 * Getrennt vom VERTRAGS-Status (`contracts.status`) und vom
 * Abrechnungsstatus des Vermittlers: eine Provision kann storniert sein,
 * waehrend der Vertrag laeuft, und umgekehrt.
 */
class CommissionStatus
{
    public const OFFEN = 'offen';
    public const FAELLIG = 'faellig';
    public const BEZAHLT = 'bezahlt';
    public const TEILWEISE = 'teilweise_bezahlt';
    public const STORNIERT = 'storniert';
    public const UNKLAR = 'unklar';

    /** status => Anzeige (Label, Badge-Klasse des Portals, Symbol). */
    public const ALL = [
        self::OFFEN => ['label' => 'Offen', 'badge' => 'open', 'icon' => '○'],
        self::FAELLIG => ['label' => 'Fällig', 'badge' => 'pending', 'icon' => '⏰'],
        self::BEZAHLT => ['label' => 'Bezahlt', 'badge' => 'active', 'icon' => '✓'],
        self::TEILWEISE => ['label' => 'Teilweise bezahlt', 'badge' => 'pending', 'icon' => '◐'],
        self::STORNIERT => ['label' => 'Storniert', 'badge' => 'danger', 'icon' => '✕'],
        self::UNKLAR => ['label' => 'Unklar – Prüfung', 'badge' => 'danger', 'icon' => '⚠'],
    ];

    /**
     * Schreibweisen aus Fremddateien. Bewusst KEIN Rateweg: was hier nicht
     * steht, wird `unklar` und landet damit sichtbar auf dem Tisch des
     * Admins - ein falsch geratener Zahlungsstatus faellt sonst nie auf.
     */
    private const ALIASES = [
        'offen' => self::OFFEN, 'open' => self::OFFEN, 'unbezahlt' => self::OFFEN,
        '1' => self::OFFEN, 'neu' => self::OFFEN, 'erfasst' => self::OFFEN,
        'bestaetigt' => self::OFFEN, 'bestätigt' => self::OFFEN,
        'faellig' => self::FAELLIG, 'fällig' => self::FAELLIG, 'due' => self::FAELLIG,
        'bezahlt' => self::BEZAHLT, 'paid' => self::BEZAHLT, 'gezahlt' => self::BEZAHLT,
        'ausgezahlt' => self::BEZAHLT, 'abgerechnet' => self::BEZAHLT, '4' => self::BEZAHLT,
        'teilweise bezahlt' => self::TEILWEISE, 'teilzahlung' => self::TEILWEISE,
        'teilweise' => self::TEILWEISE, 'partial' => self::TEILWEISE,
        'storniert' => self::STORNIERT, 'storno' => self::STORNIERT,
        'cancelled' => self::STORNIERT, 'canceled' => self::STORNIERT, '2' => self::STORNIERT,
        'unklar' => self::UNKLAR, 'unbekannt' => self::UNKLAR, 'pruefung' => self::UNKLAR,
        'prüfung' => self::UNKLAR,
    ];

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function isValid(?string $status): bool
    {
        return $status !== null && isset(self::ALL[$status]);
    }

    /**
     * Status aus einem Fremdwert. Ein LEERER Wert ist kein Fehler - dann
     * entscheidet spaeter das Zahlungsdatum (siehe ContractCommission::derive).
     * Ein UNBEKANNTER Wert wird nie geraten und ergibt `unklar`.
     */
    public static function fromExternal(?string $value): ?string
    {
        $key = mb_strtolower(trim((string) $value));
        if ($key === '') {
            return null;
        }
        return self::ALIASES[$key] ?? self::UNKLAR;
    }

    public static function label(?string $status): string
    {
        return self::ALL[$status]['label'] ?? 'Unklar – Prüfung';
    }

    public static function badge(?string $status): string
    {
        return self::ALL[$status]['badge'] ?? 'danger';
    }

    public static function icon(?string $status): string
    {
        return self::ALL[$status]['icon'] ?? '⚠';
    }

    /** Zaehlt diese Provision als noch zu erwartendes Geld? */
    public static function isOutstanding(?string $status): bool
    {
        return in_array($status, [self::OFFEN, self::FAELLIG, self::TEILWEISE], true);
    }
}
