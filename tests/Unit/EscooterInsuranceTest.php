<?php

namespace Tests\Unit;

use App\Support\EscooterInsurance;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Fachregel E-Scooter-Saison: Der Vertrag endet immer am Ende des Februars der
 * Saison, in die der Beginn faellt (Verkehrsjahr 1. Maerz bis Ende Februar).
 */
class EscooterInsuranceTest extends TestCase
{
    public function test_start_in_summer_ends_end_of_february_next_year(): void
    {
        // Beispiel aus der Bestaetigung: Beginn 20.07.2026 -> Ende 28.02.2027.
        $this->assertSame('2027-02-28', EscooterInsurance::seasonEndDate('2026-07-20'));
    }

    public function test_start_in_january_ends_same_year_february(): void
    {
        // Beginn im Januar faellt noch in die laufende Saison -> Ende Februar
        // DESSELBEN Jahres.
        $this->assertSame('2027-02-28', EscooterInsurance::seasonEndDate('2027-01-15'));
    }

    public function test_leap_year_ends_on_29_february(): void
    {
        // Saison, die im Schaltjahr 2028 endet -> 29.02.2028.
        $this->assertSame('2028-02-29', EscooterInsurance::seasonEndDate('2027-03-01'));
    }

    public function test_start_exactly_on_first_of_march_rolls_to_next_year(): void
    {
        $this->assertSame('2027-02-28', EscooterInsurance::seasonEndDate('2026-03-01'));
    }

    public function test_accepts_carbon_instance(): void
    {
        $end = EscooterInsurance::seasonEnd(Carbon::parse('2026-07-20'));
        $this->assertSame('2027-02-28', $end->format('Y-m-d'));
    }
}
