<?php

namespace Tests\Unit;

use App\Support\QrCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der QR-Erzeuger ist selbst geschrieben, deshalb halten diese Tests
 * seine Eckwerte fest.
 *
 * Bei der Entwicklung wurde er Modul fuer Modul gegen eine unabhaengige
 * Referenz-Implementierung geprueft (Funktionsmuster identisch,
 * Reed-Solomon identisch, Maskenbewertung fuer alle acht Masken
 * identisch) und die erzeugten Symbole wurden mit einem echten
 * QR-Decoder gelesen. Diese Testdatei sichert das Ergebnis ab: die
 * Pruefsummen unten stammen aus genau diesem geprueften Stand. Aendert
 * sich eine, hat jemand die Erzeugung veraendert - das darf nie
 * unbemerkt passieren, weil ein kaputter QR-Code erst beim Mitarbeiter
 * auffaellt, der seine App nicht einrichten kann.
 */
class QrCodeTest extends TestCase
{
    private function flat(string $text): string
    {
        return implode('', array_map(
            fn ($row) => implode('', array_map(fn ($v) => $v ? '1' : '0', $row)),
            QrCode::matrix($text),
        ));
    }

    #[DataProvider('goldenSamples')]
    public function test_matrix_stays_byte_for_byte_stable(string $text, int $size, string $sha1): void
    {
        $matrix = QrCode::matrix($text);

        $this->assertCount($size, $matrix, 'Symbolgroesse (Version) hat sich geaendert.');
        $this->assertSame($sha1, sha1($this->flat($text)), 'Das erzeugte Symbol weicht vom geprueften Stand ab.');
    }

    public static function goldenSamples(): array
    {
        return [
            'kurz' => ['A', 21, '2e938cc0ac4ab12fa0cebcf779f19a79410b76fe'],
            'wort' => ['hello', 21, '0217d03435c72a05d611c3deb2b4fabcf814ec6f'],
            'otpauth' => [
                'otpauth://totp/Dienstly24:admin%40dienstly24.de?secret=JBSWY3DPEHPK3PXP&issuer=Dienstly24',
                41,
                'dfb6122f6e8a7a8d6cde149ca5161c1859fd3957',
            ],
        ];
    }

    /** Die drei Suchmuster muessen exakt der Norm entsprechen. */
    public function test_finder_patterns_are_correct(): void
    {
        $m = QrCode::matrix('Dienstly24');
        $size = count($m);

        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$ox, $oy]) {
            for ($y = 0; $y < 7; $y++) {
                for ($x = 0; $x < 7; $x++) {
                    $ring = ($x === 0 || $x === 6 || $y === 0 || $y === 6);
                    $core = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;
                    $this->assertSame($ring || $core, $m[$oy + $y][$ox + $x], "Suchmuster bei ({$ox},{$oy}) falsch.");
                }
            }
        }
    }

    /** Taktmuster: abwechselnd, beginnend dunkel. */
    public function test_timing_patterns_alternate(): void
    {
        $m = QrCode::matrix('Dienstly24');
        $size = count($m);

        for ($i = 8; $i < $size - 8; $i++) {
            $this->assertSame($i % 2 === 0, $m[6][$i], "Waagerechtes Taktmuster bei {$i} falsch.");
            $this->assertSame($i % 2 === 0, $m[$i][6], "Senkrechtes Taktmuster bei {$i} falsch.");
        }
    }

    /** Das immer dunkle Modul darf nie fehlen. */
    public function test_dark_module_is_set(): void
    {
        $m = QrCode::matrix('Dienstly24');
        $this->assertTrue($m[count($m) - 8][8]);
    }

    /** Die Version waechst mit der Textlaenge - und passt immer. */
    public function test_version_grows_with_length(): void
    {
        $previous = 0;
        foreach ([10, 50, 100, 200, 400, 666] as $length) {
            $size = count(QrCode::matrix(str_repeat('X', $length)));
            $this->assertGreaterThanOrEqual($previous, $size);
            $this->assertSame(0, ($size - 17) % 4, 'Groesse muss 17 + 4*Version sein.');
            $previous = $size;
        }
    }

    public function test_too_long_text_is_rejected_clearly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::matrix(str_repeat('X', 700));
    }

    /** Das SVG muss eigenstaendig sein - keine externe Quelle. */
    public function test_svg_is_self_contained(): void
    {
        $svg = QrCode::svg('otpauth://totp/Test:a@b.de?secret=JBSWY3DPEHPK3PXP&issuer=Test');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
        $this->assertStringNotContainsString('http://', str_replace('http://www.w3.org/2000/svg', '', $svg));
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringContainsString('role="img"', $svg);
    }
}
