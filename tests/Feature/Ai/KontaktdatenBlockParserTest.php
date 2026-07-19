<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\KontaktdatenBlockParser;
use Tests\TestCase;

/**
 * Parser fuer den kompakten Kontaktdaten-Block (Name, Geburtsdatum, Anschrift,
 * Telefon, E-Mail, IBAN) - z.B. als Foto/Screenshot aus einer Chat-Nachricht.
 * Greift nur bei starken, eindeutigen Signalen in kurzem Text.
 */
class KontaktdatenBlockParserTest extends TestCase
{
    public function test_parses_compact_contact_block(): void
    {
        $text = implode("\n", [
            'Hamzeh Jassem 01.01.2005',
            'Unterwerkstr. 39',
            '84032 Altdorf',
            '017680557743',
            'hamzehjassem9@gmail.com',
            'DE53 7425 0000 0041 2922 10',
        ]);

        $r = (new KontaktdatenBlockParser())->parse($text);
        $this->assertNotNull($r);
        $this->assertSame('kontaktdaten', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Hamzeh', $p['first_name']);
        $this->assertSame('Jassem', $p['last_name']);
        $this->assertSame('2005-01-01', $p['birth_date']);
        $this->assertSame('Unterwerkstr.', $p['street']);
        $this->assertSame('39', $p['house_number']);
        $this->assertSame('84032', $p['zip']);
        $this->assertSame('Altdorf', $p['city']);
        $this->assertSame('017680557743', $p['phone']);
        $this->assertSame('hamzehjassem9@gmail.com', $p['email']);
        $this->assertSame('DE53742500000041292210', $r['data']['bank']['iban']);
    }

    public function test_ignores_long_documents_even_with_email_and_iban(): void
    {
        // Ein echtes (langes) Dokument mit E-Mail + IBAN im Fuss darf NICHT als
        // Kontaktblock gelesen werden.
        $long = "Rechnung Nr. 4711\n" . str_repeat("Zeile mit Text zur Laenge des Dokuments.\n", 20)
            . "12345 Musterstadt\nkontakt@firma.de\nDE53 7425 0000 0041 2922 10";
        $this->assertNull((new KontaktdatenBlockParser())->parse($long));
    }

    public function test_requires_email_iban_and_plz(): void
    {
        // Ohne IBAN kein Kontaktblock (zu schwaches Signal).
        $noIban = "Max Mustermann 01.01.1990\nTeststr. 1\n12345 Berlin\nmax@example.com";
        $this->assertNull((new KontaktdatenBlockParser())->parse($noIban));

        $this->assertNull((new KontaktdatenBlockParser())->parse('Nur irgendein kurzer Text'));
    }
}
