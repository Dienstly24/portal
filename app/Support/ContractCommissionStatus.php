<?php
namespace App\Support;

/**
 * Der PROVISIONS-Zustand eines VERTRAGS (Betreiber-Vorgabe 02.09.2026, §20).
 *
 * ABGRENZUNG - drei Wahrheiten, die sich nie ueberschreiben:
 *  - `contracts.status`            = laeuft der Vertrag? (Fachstatus)
 *  - `contract_commissions.status` = ist DIESE Buchung bezahlt? (Geldstatus)
 *  - dieser Zustand hier           = ist der Vertrag verguetet worden?
 * Ein Vertrag kann laufen und trotzdem "Provision fehlt" sein; eine einzelne
 * Provision kann storniert sein, waehrend der Vertrag weiter Bestand hat.
 *
 * ABGELEITET, NICHT GEPFLEGT: der Zustand ergibt sich aus den Buchungen und
 * den Fristen des Pools (CommissionStatusEngine). Eine von Hand gepflegte
 * Spalte liefe auseinander, sobald eine Provision nachtraeglich eingeht -
 * genau der Fall, den §7 verlangt ("Status automatisch aktualisieren").
 * Einzige Ausnahme ist GEKLAERT: das ist eine menschliche Entscheidung
 * ("der Pool zahlt nicht, Sache erledigt") und wird nicht ueberschrieben.
 */
class ContractCommissionStatus
{
    public const NEU = 'neu';
    public const ERWARTET = 'erwartet';
    public const ERHALTEN = 'erhalten';
    public const LAUFEND = 'laufend';
    public const VOLLSTAENDIG = 'vollstaendig';
    public const FEHLT = 'fehlt';
    public const UEBERFAELLIG = 'ueberfaellig';
    public const STORNIERT = 'storniert';
    public const KORREKTUR = 'korrektur';
    public const PRUEFUNG = 'pruefung';
    public const GEKLAERT = 'geklaert';

    /** Zustand => [Anzeige, Badge-Klasse des Portals, Symbol]. */
    public const ALL = [
        self::NEU => ['label' => 'Neu', 'badge' => 'open', 'icon' => '•'],
        self::ERWARTET => ['label' => 'Provision erwartet', 'badge' => 'open', 'icon' => '⏳'],
        self::ERHALTEN => ['label' => 'Provision erhalten', 'badge' => 'active', 'icon' => '✓'],
        self::LAUFEND => ['label' => 'Laufende Provision', 'badge' => 'active', 'icon' => '↻'],
        self::VOLLSTAENDIG => ['label' => 'Vollständig abgerechnet', 'badge' => 'active', 'icon' => '✔'],
        self::FEHLT => ['label' => 'Provision fehlt', 'badge' => 'danger', 'icon' => '✕'],
        self::UEBERFAELLIG => ['label' => 'Überfällig', 'badge' => 'pending', 'icon' => '⏰'],
        self::STORNIERT => ['label' => 'Storniert', 'badge' => 'danger', 'icon' => '⤺'],
        self::KORREKTUR => ['label' => 'Korrektur', 'badge' => 'pending', 'icon' => '±'],
        self::PRUEFUNG => ['label' => 'Manuelle Prüfung', 'badge' => 'danger', 'icon' => '⚠'],
        self::GEKLAERT => ['label' => 'Geklärt', 'badge' => 'active', 'icon' => '☑'],
    ];

    /**
     * Zustaende, die eine ENTSCHEIDUNG eines Menschen sind und deshalb von
     * der taeglichen Neuberechnung nicht angefasst werden.
     *
     * @var array<int,string>
     */
    public const MANUELL = [self::GEKLAERT];

    /** Zustaende, die auf der Seite "Fehlende Provisionen" stehen. */
    public const OFFENE_FAELLE = [self::UEBERFAELLIG, self::FEHLT, self::PRUEFUNG];

    public static function label(?string $status): string
    {
        return self::ALL[$status]['label'] ?? '—';
    }

    public static function badge(?string $status): string
    {
        return self::ALL[$status]['badge'] ?? 'open';
    }

    public static function icon(?string $status): string
    {
        return self::ALL[$status]['icon'] ?? '•';
    }

    public static function isValid(?string $status): bool
    {
        return $status !== null && isset(self::ALL[$status]);
    }

    /** @return array<int,string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }
}
