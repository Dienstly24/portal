<?php
namespace App\Services\Health;

use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;

/**
 * Berechnet, ab wann eine neue Krankenkasse gilt (Krankenkassenwechsel).
 * Drei Faelle (Betreiber-Vorgabe):
 *
 * - REGULAR ("wechsel"): regulaerer Wechsel wegen Statusaenderung. Der Monat
 *   der Unterlagen zaehlt nicht mehr; es gilt die gesetzliche Kuendigungsfrist
 *   von zwei vollen Monaten zum Monatsende. Praktisch: Wirksam ab dem 1. des
 *   Monats, der DREI Monate nach dem Einreichungsmonat liegt.
 *   Beispiel: Einreichung Juli -> wirksam 1. Oktober.
 *
 * - SONDER ("sonder"): Sonderkuendigungsrecht (z.B. bei Beitragserhoehung).
 *   Dasselbe Datum wie REGULAR, wird aber ausdruecklich als Sonderfall
 *   markiert (isSonder()).
 *
 * - NEW_JOB ("new_job"): Aufnahme einer neuen Beschaeftigung. Die neue Kasse
 *   gilt sofort ab dem Beschaeftigungsbeginn (oder, ohne Angabe, ab dem
 *   Einreichungsdatum).
 *
 * Alle Eingangsdaten werden explizit uebergeben - die Klasse ruft nie now()
 * selbst auf (reproduzierbar/testbar).
 */
class HealthInsuranceSwitchCalculator
{
    public const REASON_REGULAR = 'wechsel';
    public const REASON_SONDER = 'sonder';
    public const REASON_NEW_JOB = 'new_job';

    public const REASONS = [self::REASON_REGULAR, self::REASON_SONDER, self::REASON_NEW_JOB];

    /** Anzahl Monate bis zum Wirksamwerden beim regulaeren Wechsel/Sonderfall. */
    private const REGULAR_MONTHS = 3;

    /**
     * Wirksamkeitsdatum der neuen Krankenkasse.
     *
     * @param CarbonInterface $submittedAt Datum der Unterlagen (Upload).
     * @param string $reason einer aus self::REASONS
     * @param CarbonInterface|null $jobStart Beschaeftigungsbeginn (nur NEW_JOB)
     * @throws \InvalidArgumentException bei unbekanntem Grund
     */
    public function effectiveDate(CarbonInterface $submittedAt, string $reason, ?CarbonInterface $jobStart = null): CarbonImmutable
    {
        $submitted = CarbonImmutable::instance($submittedAt);

        return match ($reason) {
            self::REASON_NEW_JOB => ($jobStart !== null ? CarbonImmutable::instance($jobStart) : $submitted)->startOfDay(),
            self::REASON_REGULAR, self::REASON_SONDER =>
                $submitted->addMonthsNoOverflow(self::REGULAR_MONTHS)->startOfMonth(),
            default => throw new \InvalidArgumentException("Unbekannter Wechsel-Grund: {$reason}"),
        };
    }

    /** Ist dieser Grund ein Sonderkuendigungsrecht (zur Kennzeichnung)? */
    public function isSonder(string $reason): bool
    {
        return $reason === self::REASON_SONDER;
    }

    public function isValidReason(string $reason): bool
    {
        return in_array($reason, self::REASONS, true);
    }
}
