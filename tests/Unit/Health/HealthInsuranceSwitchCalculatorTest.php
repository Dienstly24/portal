<?php

namespace Tests\Unit\Health;

use App\Services\Health\HealthInsuranceSwitchCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Berechnung des Krankenkassen-Wirksamkeitsdatums (drei Faelle).
 */
class HealthInsuranceSwitchCalculatorTest extends TestCase
{
    private HealthInsuranceSwitchCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new HealthInsuranceSwitchCalculator();
    }

    public function test_regular_wechsel_is_first_of_month_three_months_later(): void
    {
        // Betreiber-Beispiel: Einreichung Juli -> wirksam 1. Oktober.
        $date = $this->calc->effectiveDate(CarbonImmutable::parse('2026-07-18'), 'wechsel');
        $this->assertSame('2026-10-01', $date->toDateString());
    }

    public function test_regular_wechsel_handles_year_rollover(): void
    {
        // November -> 1. Februar des Folgejahres.
        $date = $this->calc->effectiveDate(CarbonImmutable::parse('2026-11-05'), 'wechsel');
        $this->assertSame('2027-02-01', $date->toDateString());
    }

    public function test_regular_wechsel_end_of_month_still_counts_that_month(): void
    {
        // Auch am 31. Juli bleibt der Einreichungsmonat Juli -> 1. Oktober.
        $date = $this->calc->effectiveDate(CarbonImmutable::parse('2026-07-31'), 'wechsel');
        $this->assertSame('2026-10-01', $date->toDateString());
    }

    public function test_sonder_uses_same_date_but_is_flagged(): void
    {
        $date = $this->calc->effectiveDate(CarbonImmutable::parse('2026-07-18'), 'sonder');
        $this->assertSame('2026-10-01', $date->toDateString());
        $this->assertTrue($this->calc->isSonder('sonder'));
        $this->assertFalse($this->calc->isSonder('wechsel'));
    }

    public function test_new_job_uses_job_start_when_given(): void
    {
        $date = $this->calc->effectiveDate(
            CarbonImmutable::parse('2026-07-18'),
            'new_job',
            CarbonImmutable::parse('2026-08-01'),
        );
        $this->assertSame('2026-08-01', $date->toDateString());
    }

    public function test_new_job_without_start_is_immediate(): void
    {
        $date = $this->calc->effectiveDate(CarbonImmutable::parse('2026-07-18'), 'new_job');
        $this->assertSame('2026-07-18', $date->toDateString());
    }

    public function test_unknown_reason_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->effectiveDate(CarbonImmutable::parse('2026-07-18'), 'quatsch');
    }
}
