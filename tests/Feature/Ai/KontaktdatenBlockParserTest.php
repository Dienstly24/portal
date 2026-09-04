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

        $r = (new KontaktdatenBlockParser)->parse($text);
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
        $long = "Rechnung Nr. 4711\n".str_repeat("Zeile mit Text zur Laenge des Dokuments.\n", 20)
            ."12345 Musterstadt\nkontakt@firma.de\nDE53 7425 0000 0041 2922 10";
        $this->assertNull((new KontaktdatenBlockParser)->parse($long));
    }

    public function test_reads_hyphenated_name_and_two_dates(): void
    {
        // Bindestrich-Nachname ("Al-Wattar"), zwei Daten (das ERSTE ist das
        // Geburtsdatum), und mehrere Felder in einer Zeile (Datum+Strasse,
        // PLZ+Ort+Telefon).
        $text = implode("\n", [
            'Salam Al-Wattar',
            '27.10.1970 & 28.08.2023 Seestr. 14',
            '23879 Mölln 01778664110',
            'Salamalwattar20@gmail.com',
            'DE82 2305 2750 0081 4355 63',
        ]);
        $r = (new KontaktdatenBlockParser)->parse($text);
        $p = $r['data']['person'];

        $this->assertSame('Salam', $p['first_name']);
        $this->assertSame('Al-Wattar', $p['last_name']);
        $this->assertSame('1970-10-27', $p['birth_date']); // erstes Datum
        // Zweites Datum (mit "&" getrennt) sichtbar in der Zusammenfassung.
        $this->assertStringContainsString('28.08.2023', $r['summary']);
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
        $p = (new KontaktdatenBlockParser)->parse($text)['data']['person'];

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
        $p = (new KontaktdatenBlockParser)->parse($text)['data']['person'];
        $this->assertSame('Layla', $p['first_name']);
        $this->assertSame('Al-Hassan', $p['last_name']);
        $this->assertSame('female', $p['gender']);
    }

    public function test_second_date_beside_birthdate_is_recognized(): void
    {
        // "04.08.75/05.09.17": erstes Datum = Geburtsdatum, zweites Datum =
        // z.B. Datum der Bescheinigung/des Aufenthaltstitels (darf das
        // Geburtsdatum nicht verfaelschen und geht nicht verloren).
        $text = implode("\n", [
            'Refat Alhussein Aleliwi',
            '04.08.75/05.09.17',
            'Königsheide 53',
            '44536 Lünen',
            '01708211387',
            'refat.re75@hotmail.com',
            'DE55 2856 2297 0035 5267 00',
        ]);
        $r = (new KontaktdatenBlockParser)->parse($text);
        $p = $r['data']['person'];

        $this->assertSame('Refat', $p['first_name']);
        $this->assertSame('Alhussein Aleliwi', $p['last_name']);
        // Geburtsdatum = ERSTES Datum, nicht das zweite.
        $this->assertSame('1975-08-04', $p['birth_date']);
        // Zweites Datum sichtbar in der Zusammenfassung.
        $this->assertStringContainsString('05.09.2017', $r['summary']);
        $this->assertStringContainsString('44536', $p['zip']);
        $this->assertSame('01708211387', $p['phone']);
        $this->assertSame('DE55285622970035526700', $r['data']['bank']['iban']);
    }

    public function test_two_dates_on_the_name_line_and_international_phone(): void
    {
        // BEIDE Daten stehen direkt auf der NAMENszeile ("Haya Afara 08.07.92 &
        // 12.11.24") - der Name muss trotzdem erkannt werden; das Geburtsdatum
        // ist das erste Datum, das zweite (z.B. Fuehrerschein/Bescheinigung)
        // erscheint in der Zusammenfassung. Telefon im +49-Format.
        $text = implode("\n", [
            'Haya Afara 08.07.92 & 12.11.24',
            'Birkenweg 20',
            '74731 Walldürn',
            '+4915226593331',
            'hayasande475@gmail.com',
            'DE97 6735 2565 0001 5899 28',
        ]);
        $r = (new KontaktdatenBlockParser)->parse($text);

        $this->assertNotNull($r);
        $p = $r['data']['person'];
        $this->assertSame('Haya', $p['first_name']);
        $this->assertSame('Afara', $p['last_name']);
        $this->assertSame('1992-07-08', $p['birth_date']); // erstes Datum
        $this->assertStringContainsString('12.11.2024', $r['summary']); // zweites Datum sichtbar
        $this->assertSame('Birkenweg', $p['street']);
        $this->assertSame('20', $p['house_number']);
        $this->assertSame('74731', $p['zip']);
        $this->assertSame('Walldürn', $p['city']);
        $this->assertSame('015226593331', $p['phone']); // +49 -> fuehrende 0
        $this->assertSame('hayasande475@gmail.com', $p['email']);
        $this->assertSame('DE97673525650001589928', $r['data']['bank']['iban']);
    }

    /**
     * Der gemeldete Fall (21.08.2026), mit erfundenen Werten im GLEICHEN
     * Aufbau (echte Kundendaten gehoeren nicht ins Repository): derselbe
     * Kontaktzettel als Screenshot,
     * einmal mit gruenem Hintergrund und farbigen Links, einmal schwarz auf
     * weiss. Fuer die OCR sind das zwei verschiedene Bilder - der Parser muss
     * beide Fassungen lesen, auch wenn die Erkennung Zeichen verwechselt.
     */
    public function test_reads_reported_contact_note(): void
    {
        $text = implode("\n", [
            'Nabil Karimi 30.07.85 & 07.08.24',
            'Hohe Wiesen 3',
            '88284 Wolpertswende',
            '01791234567',
            'nabil.karimi@example.com',
            'DE89 3704 0044 0532 0130 00',
        ]);

        $r = (new KontaktdatenBlockParser)->parse($text);
        $this->assertNotNull($r);
        $p = $r['data']['person'];

        $this->assertSame('Nabil', $p['first_name']);
        $this->assertSame('Karimi', $p['last_name']);
        $this->assertSame('1985-07-30', $p['birth_date']);
        $this->assertStringContainsString('07.08.2024', $r['summary']);
        $this->assertSame('Hohe Wiesen', $p['street']);
        $this->assertSame('3', $p['house_number']);
        $this->assertSame('88284', $p['zip']);
        $this->assertSame('Wolpertswende', $p['city']);
        $this->assertSame('01791234567', $p['phone']);
        $this->assertSame('nabil.karimi@example.com', $p['email']);
        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
    }

    public function test_repairs_typical_ocr_damage_in_iban_and_email(): void
    {
        // Genau die Verwechslungen, an denen die Erkennung eines hellen
        // Screenshots scheitert: O statt 0 in der IBAN, fehlender Punkt vor
        // der Endung in der E-Mail.
        $text = implode("\n", [
            'Nabil Karimi 30.07.85 & 07.08.24',
            'Hohe Wiesen 3',
            '88284 Wolpertswende',
            '01791234567',
            'nabil.karimi@example com',
            'DE89 37O4 OO44 O532 O13O OO',
        ]);

        $r = (new KontaktdatenBlockParser)->parse($text);
        $this->assertNotNull($r);
        $this->assertSame('nabil.karimi@example.com', $r['data']['person']['email']);
        // IBAN wird nur uebernommen, weil die Pruefziffer nach der Reparatur stimmt.
        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
    }

    public function test_repairs_ocr_damage_measured_on_a_real_screenshot(): void
    {
        // Genau die Ausgabe, die Tesseract aus einem nachgestellten
        // Chat-Screenshot (340 px breit, JPEG-komprimiert) geliefert hat:
        // "DE89" wird zu "DEB9", das "@" zu "@®". Beides liess den Block
        // frueher komplett durchfallen.
        $text = implode("\n", [
            'Nabil Karimi 30.07.85 & 07.08.24',
            'Hohe Wiesen 3',
            '88284 Wolpertswende',
            '01791234567',
            'nabil.karimi@®example.com',
            'DEB9 3704 0044 0532 0130 00',
        ]);

        $r = (new KontaktdatenBlockParser)->parse($text);
        $this->assertNotNull($r);
        $this->assertSame('nabil.karimi@example.com', $r['data']['person']['email']);
        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
        $this->assertSame(70, $r['confidence']);
    }

    public function test_never_takes_an_iban_with_broken_checksum(): void
    {
        // Eine IBAN, die sich nicht reparieren laesst (falsche Pruefziffer),
        // wird NIE uebernommen - der Block wird aber trotzdem gelesen.
        $text = implode("\n", [
            'Nabil Karimi 30.07.85',
            'Hohe Wiesen 3',
            '88284 Wolpertswende',
            '01791234567',
            'nabil.karimi@example.com',
            'DE89 3704 0044 0532 0130 01',
        ]);

        $r = (new KontaktdatenBlockParser)->parse($text);
        $this->assertNotNull($r);
        $this->assertSame([], $r['data']['bank']);
        $this->assertStringContainsString('Keine gueltige IBAN', $r['summary']);
        $this->assertLessThan(70, $r['confidence']);
    }

    public function test_reads_grouped_phone_number(): void
    {
        $text = implode("\n", [
            'Nabil Karimi 30.07.85',
            'Hohe Wiesen 3',
            '88284 Wolpertswende',
            '0179 123 4567',
            'nabil.karimi@example.com',
        ]);

        $p = (new KontaktdatenBlockParser)->parse($text)['data']['person'];
        $this->assertSame('01791234567', $p['phone']);
    }

    public function test_letterhead_without_personal_signal_is_not_a_contact_block(): void
    {
        // Nur Anschrift + Telefon (Briefkopf) reicht NICHT - ohne E-Mail oder
        // IBAN ist das kein Kontaktzettel des Kunden.
        $text = "Muster Handel\nHauptstr. 5\n12345 Berlin\n030123456789";
        $this->assertNull((new KontaktdatenBlockParser)->parse($text));
    }

    public function test_short_document_is_not_a_contact_block(): void
    {
        // Kurz genug fuer den Block, aber es ist ersichtlich ein Dokument.
        $text = implode("\n", [
            'Rechnung Nr. 4711',
            'Max Mustermann',
            'Hauptstr. 5',
            '12345 Berlin',
            'max@example.com',
            'DE89 3704 0044 0532 0130 00',
        ]);
        $this->assertNull((new KontaktdatenBlockParser)->parse($text));
    }

    public function test_requires_two_signals_with_one_personal(): void
    {
        // E-Mail + PLZ/Ort genuegen jetzt (frueher war die IBAN Pflicht - EIN
        // von der OCR verlesenes Zeichen liess den ganzen Block durchfallen).
        $ohneIban = "Max Mustermann 01.01.1990\nTeststr. 1\n12345 Berlin\nmax@example.com";
        $this->assertNotNull((new KontaktdatenBlockParser)->parse($ohneIban));

        // Nur ein persoenliches Signal ohne zweites Signal reicht nicht.
        $nurEmail = "Max Mustermann\nmax@example.com";
        $this->assertNull((new KontaktdatenBlockParser)->parse($nurEmail));

        $this->assertNull((new KontaktdatenBlockParser)->parse('Nur irgendein kurzer Text'));
    }
}
