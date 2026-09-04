<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Einmalkennwoerter sind selbst geschrieben - also muessen sie gegen
 * die AMTLICHEN Testvektoren laufen, nicht nur gegen sich selbst.
 * Quelle: RFC 6238, Anhang B (SHA1, Geheimnis "12345678901234567890").
 */
class TotpTest extends TestCase
{
    private function rfcSecret(): string
    {
        return Totp::base32Encode('12345678901234567890');
    }

    #[DataProvider('rfcVectors')]
    public function test_matches_rfc_6238_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::code($this->rfcSecret(), $time, 8));
    }

    public static function rfcVectors(): array
    {
        return [
            'T=59' => [59, '94287082'],
            'T=1111111109' => [1111111109, '07081804'],
            'T=1111111111' => [1111111111, '14050471'],
            'T=1234567890' => [1234567890, '89005924'],
            'T=2000000000' => [2000000000, '69279037'],
            'T=20000000000' => [20000000000, '65353130'],
        ];
    }

    public function test_base32_round_trip(): void
    {
        foreach (['A', 'hallo welt', "\x00\xFF\x10\x7F", random_bytes(20)] as $raw) {
            $this->assertSame($raw, Totp::base32Decode(Totp::base32Encode($raw)));
        }
    }

    public function test_generated_secret_is_valid_base32_of_expected_length(): void
    {
        $secret = Totp::generateSecret();
        $this->assertSame(32, strlen($secret), '160 Bit ergeben 32 Base32-Zeichen.');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
    }

    public function test_verify_accepts_the_current_code(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now), $now));
    }

    /** Uhren gehen selten exakt - ein Fenster davor/danach zaehlt noch. */
    public function test_verify_tolerates_one_step_of_clock_drift(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now - Totp::PERIOD), $now));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now + Totp::PERIOD), $now));
    }

    /** Aber nicht beliebig weit - sonst waere ein alter Code ewig gueltig. */
    public function test_verify_rejects_codes_outside_the_window(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now - 5 * Totp::PERIOD), $now));
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now + 5 * Totp::PERIOD), $now));
    }

    public function test_verify_rejects_nonsense(): void
    {
        $secret = Totp::generateSecret();
        foreach (['', '12345', '1234567', 'abcdef', '000000 '] as $bad) {
            $this->assertFalse(Totp::verify($secret, $bad), "Abgelehnt werden muss: '{$bad}'");
        }
    }

    /** Ein anderes Geheimnis darf nie denselben Code liefern. */
    public function test_codes_differ_per_secret(): void
    {
        $now = 1_700_000_000;
        $a = Totp::code(Totp::generateSecret(), $now);
        $b = Totp::code(Totp::generateSecret(), $now);
        $this->assertNotSame($a, $b);
    }

    public function test_provisioning_uri_carries_secret_and_issuer(): void
    {
        $uri = Totp::provisioningUri('JBSWY3DPEHPK3PXP', 'admin@dienstly24.de', 'Dienstly24');

        $this->assertStringStartsWith('otpauth://totp/Dienstly24:admin%40dienstly24.de?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Dienstly24', $uri);
    }
}
