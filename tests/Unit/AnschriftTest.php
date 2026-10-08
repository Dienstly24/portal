<?php

namespace Tests\Unit;

use App\Support\Anschrift;
use PHPUnit\Framework\TestCase;

/** Hausnummer genau einmal, Widerspruch nie geraten (KI-069). */
class AnschriftTest extends TestCase
{
    public function test_sauber_erfasst_bleibt_unveraendert(): void
    {
        $this->assertSame(['zeile' => 'Nagelshof 20', 'fall' => 'sauber'], Anschrift::strassenzeile('Nagelshof', '20', null));
        $this->assertSame('Hauptstr. 5 a', Anschrift::strassenzeile('Hauptstr.', '5', 'a')['zeile']);
    }

    public function test_dieselbe_nummer_doppelt_wird_einmal_gezeigt(): void
    {
        $this->assertSame(['zeile' => 'Nagelshof 20', 'fall' => 'doppelt'], Anschrift::strassenzeile('Nagelshof 20', '20', null));
        $this->assertSame('doppelt', Anschrift::strassenzeile('Hauptstr. 5a', '5', 'a')['fall']);
        $this->assertSame(['zeile' => 'Hauptstr. 5 a', 'fall' => 'doppelt'], Anschrift::strassenzeile('Hauptstr. 5', '5', 'a'));
    }

    public function test_widerspruch_bleibt_wie_erfasst_und_wird_gemeldet(): void
    {
        $this->assertSame(['zeile' => 'Nagelshof 20 51', 'fall' => 'widerspruch'], Anschrift::strassenzeile('Nagelshof 20', '51', null));
    }

    public function test_nummer_nur_in_der_strasse(): void
    {
        $this->assertSame(['zeile' => 'Nagelshof 20', 'fall' => 'nur_in_strasse'], Anschrift::strassenzeile('Nagelshof 20', null, null));
    }

    public function test_zahl_im_strassennamen_wird_nie_zerlegt(): void
    {
        $this->assertSame(['zeile' => 'Straße des 17. Juni 5', 'fall' => 'sauber'], Anschrift::strassenzeile('Straße des 17. Juni', '5', null));
    }

    public function test_schluessel_gleicht_schreibweisen_an(): void
    {
        $k = Anschrift::schluessel('Hauptstraße 5, 24103 Kiel');
        $this->assertSame($k, Anschrift::schluessel('Hauptstrasse 5, 24103 Kiel'));
        $this->assertSame($k, Anschrift::schluessel('Hauptstr. 5, 24103 Kiel'));
        $this->assertSame($k, Anschrift::schluessel('HAUPTSTR 5 24103 KIEL'));
        $this->assertNotSame($k, Anschrift::schluessel('Hauptstr. 7, 24103 Kiel'));
        $this->assertSame(Anschrift::schluessel('Bürgerweg 1'), Anschrift::schluessel('Buergerweg 1'));
    }
}
