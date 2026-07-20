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

    public function test_reads_hyphenated_name_and_two_dates(): void
    {
        // Bindestrich-Nachname ("Al-Wattar"), zwei Daten (das ERSTE ist das
        // Geburtsdatum), und mehrere Felder in einer Zeile (Datum+Strasse,
        // PLZ+Ort+Telefon).
        $text = implode("\n", [
            'Salam Al-Wattar',
            '27.10.1970&28.08.2023 Seestr. 14',
            '23879 Mölln 01778664110',
            'Salamalwattar20@gmail.com',
            'DE82 2305 2750 0081 4355 63',
        ]);
        $p = (new KontaktdatenBlockParser())->parse($text)['data']['person'];

        $this->assertSame('Salam', $p['first_name']);
        $this->assertSame('Al-Wattar', $p['last_name']);
        $this->assertSame('1970-10-27', $p['birth_date']); // erstes Datum
        $this->assertSame('Seestr.', $p['street']);
        $this->assertSame('14', $p['house_number']);
        $this->assertSame('23879', $p['zip']);
        $this->assertSame('Mölln', $p['city']);
        $this->assertSame('01778664110', $p['phone']);
    }

    public function test_salutation_becomes_gender_and_three_part_name(): void
    {
        // "Herr" ist die Anrede (-> Geschlecht), NICHT der Vorname. Der Name
        // besteht aus drei Teilen (Vorname + zweiteiliger Nachname). PLZ steht
        // am Zeilenende, der Ort in der naechsten Zeile. Geburtsdatum 2-stellig.
        $text = implode("\n", [
            'Herr Ibrahim Al-Ali Al-Sharaa',
            '01.01.88 Falkenweg 40 71634',
            'Ludwigsburg 015560360109',
            'alalialsharaa.ibrahim@gmail.com',
            'DE44 1001 0010 0461 1063 8',
        ]);
        $p = (new KontaktdatenBlockParser())->parse($text)['data']['person'];

        $this->assertSame('Ibrahim', $p['first_name']);
        $this->assertSame('Al-Ali Al-Sharaa', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('1988-01-01', $p['birth_date']);
        $this->assertSame('Falkenweg', $p['street']);
        $this->assertSame('40', $p['house_number']);
        $this->assertSame('71634', $p['zip']);
        $this->assertSame('Ludwigsburg', $p['city']);
        $this->assertSame('015560360109', $p['phone']);
    }

    public function test_frau_salutation_sets_female_gender(): void
    {
        $text = implode("\n", [
            'Frau Layla Al-Hassan 15.03.1992',
            'Hauptstr. 7',
            '10115 Berlin',
            '01701234567',
            'layla@example.com',
            'DE89 3704 0044 0532 0130 00',
        ]);
        $p = (new KontaktdatenBlockParser())->parse($text)['data']['person'];
        $this->assertSame('Layla', $p['first_name']);
        $this->assertSame('Al-Hassan', $p['last_name']);
        $this->assertSame('female', $p['gender']);
    }

    public function test_requires_email_iban_and_plz(): void
    {
        // Ohne IBAN kein Kontaktblock (zu schwaches Signal).
        $noIban = "Max Mustermann 01.01.1990\nTeststr. 1\n12345 Berlin\nmax@example.com";
        $this->assertNull((new KontaktdatenBlockParser())->parse($noIban));

        $this->assertNull((new KontaktdatenBlockParser())->parse('Nur irgendein kurzer Text'));
    }
}
