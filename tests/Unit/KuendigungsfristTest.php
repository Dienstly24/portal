<?php

namespace Tests\Unit;

use App\Support\Kuendigungsfrist;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Letzter Eingangstag der Kuendigung = (Verlaengerungstag - 1 Tag) minus
 * Kuendigungsfrist, ohne Monatsueberlauf (Betreiber-Vorgabe 20.11.2026).
 */
class KuendigungsfristTest extends TestCase
{
    public static function faelle(): array
    {
        return [
            'Jahreswechsel, 1 Monat' => ['2027-01-01', 1, '2026-11-30'],
            'Monatsende Maerz -> Februar' => ['2027-03-31', 1, '2027-02-28'],
            'Schaltjahr' => ['2028-03-31', 1, '2028-02-29'],
            'Monatsanfang' => ['2027-03-01', 1, '2027-01-28'],
            'Mai -> Maerz 30.' => ['2027-05-01', 1, '2027-03-30'],
            '3 Monate' => ['2027-01-01', 3, '2026-09-30'],
            '3 Monate Monatsende' => ['2027-06-01', 3, '2027-02-28'],
            'keine Frist' => ['2027-01-01', 0, '2026-12-31'],
            'negative Frist zaehlt als 0' => ['2027-01-01', -2, '2026-12-31'],
        ];
    }

    #[DataProvider('faelle')]
    public function test_eingangsfrist(string $verlaengerung, int $monate, string $erwartet): void
    {
        $this->assertSame(
            $erwartet,
            Kuendigungsfrist::eingangBis(CarbonImmutable::parse($verlaengerung), $monate)->toDateString()
        );
    }

    public function test_uhrzeit_spielt_keine_rolle(): void
    {
        $this->assertSame('2026-11-30',
            Kuendigungsfrist::eingangBis(CarbonImmutable::parse('2027-01-01 23:59:00'))->toDateString());
    }
}
