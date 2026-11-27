<?php

namespace Tests\Unit;

use App\Support\Anschrift;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Strasse und Hausnummer trennen - die EINE Regel (Anschrift::trenne) fuer
 * Dokumenten-Eingang, KI-Antwort, OCR und Bearbeiten-Formular
 * (Meldung 27.11.2026: "Koetzer Weg" ohne "12").
 */
class AnschriftTrennenTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string, 2: string, 3: ?string}> */
    public static function faelle(): array
    {
        return [
            'gemeldet' => ['Kötzer Weg 12', null, 'Kötzer Weg', '12'],
            'buchstabe' => ['Musterstraße 25a', null, 'Musterstraße', '25a'],
            'schraegstrich' => ['Hauptstraße 7/2', null, 'Hauptstraße', '7/2'],
            'bereich' => ['Am Markt 12-14', null, 'Am Markt', '12-14'],
            'buchstabe mit leerzeichen' => ['Lindenweg 21 b', null, 'Lindenweg', '21 b'],
            'abkuerzung ohne leerzeichen' => ['Musterstr.25', null, 'Musterstr.', '25'],
            'nr davor' => ['Kötzer Weg Nr. 12', null, 'Kötzer Weg', '12'],
            'komma am ende' => ['Kötzer Weg 12,', null, 'Kötzer Weg', '12'],
            'komma vor nummer' => ['Kötzer Weg, 12', null, 'Kötzer Weg', '12'],
            'doppelt gleich' => ['Kötzer Weg 12', '12', 'Kötzer Weg', '12'],
        ];
    }

    #[DataProvider('faelle')]
    public function test_trennt_strasse_und_nummer(string $strasse, ?string $nummer, string $erwStrasse, ?string $erwNummer): void
    {
        $this->assertSame([$erwStrasse, $erwNummer], Anschrift::trenne($strasse, $nummer));
    }

    public function test_widerspruch_bleibt_unveraendert(): void
    {
        $this->assertSame(['Kötzer Weg 12', '14'], Anschrift::trenne('Kötzer Weg 12', '14'));
    }

    public function test_strasse_des_17_juni_wird_nicht_zerschnitten(): void
    {
        $this->assertSame(['Straße des 17. Juni', null], Anschrift::trenne('Straße des 17. Juni', null));
    }

    public function test_nur_eine_nummer_wird_keine_strasse(): void
    {
        $this->assertSame(['23', null], Anschrift::trenne('23', null));
    }

    public function test_strasse_ohne_nummer_bleibt(): void
    {
        $this->assertSame(['Kötzer Weg', '12'], Anschrift::trenne('Kötzer Weg', '12'));
    }
}
