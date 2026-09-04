<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\SparkasseDirektKfzParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer das Kfz-Angebot/den Antrag der Sparkassen
 * DirektVersicherung (Tarifumstellung): liest Antragsteller, Fahrzeug, Deckung
 * und Vertragsdaten aus dem Spaltenlayout (Beschriftung links, Wert rechts).
 * Synthetische Daten, gleiche Struktur wie das Original (pdftotext -layout).
 */
class SparkasseDirektKfzParserTest extends TestCase
{
    /** Zeile "Label<Abstand>Wert" wie im Original. */
    private function row(string $label, string $value, string $hint = ''): string
    {
        $line = str_pad($label, 56).$value;
        return $hint === '' ? $line : str_pad($line, 110).$hint;
    }

    private function angebotText(): string
    {
        return implode("\n", [
            '                                                        Es schreibt Ihnen:',
            '                                                        Ihr Service-Center',
            '    Herrn                                                   DirektVersicherung',
            '    Erik Musterfahrer                                   Kölner Landstraße 33',
            '                                                        40591 Düsseldorf',
            '    Musterstr. 2 b                                      service@sparkassen-direkt.de',
            '    63546 Hammersbach                                   vertrag@sparkassen-direkt.de',
            '',
            'Unser Angebot für Sie:',
            'Sparpreise, Service und Sicherheit bei der Sparkassen DirektVersicherung',
            'Sparkassen DirektVersicherung AG',
            "\f",
            '                                                        Angebot vom 31.07.2026',
            'Antrag auf Kfz-Versicherung 30003380161-4',
            'Tarifumstellung',
            '',
            $this->row('Antragsteller', 'Herr'),
            str_pad('', 56).'Erik Musterfahrer',
            str_pad('', 56).'Musterstr. 2 b',
            str_pad('', 56).'63546 Hammersbach',
            $this->row('Geburtsdatum', '23.07.1992'),
            $this->row('Telefon', '0176/80559524'),
            $this->row('E-Mail', 'erik.musterfahrer@example.com'),
            $this->row('Tarifgruppe', 'Sonstiges'),
            '',
            $this->row('Fahrzeughalter', 'Herrn'),
            str_pad('', 56).'Erik Musterfahrer',
            str_pad('', 56).'Musterstr. 2 b',
            str_pad('', 56).'63546 Hammersbach',
            '',
            'Tarifumstellung',
            $this->row('Versicherungsbeginn', '19.09.2026, 00:00 Uhr'),
            'frühestens mit Antragseingang',
            $this->row('Versicherungsablauf', 'Versicherungsbeginn plus ein Jahr, 00:00 Uhr'),
            "\f",
            'Fahrzeugangaben                                                                    Zulassungsbescheinigung',
            $this->row('Fahrzeugart', 'Personenkraftwagen'),
            $this->row('Hersteller-/Typ-Nummer', '0710 / 916', 'Feld 2.1 und 2.2'),
            $this->row('Hersteller', 'Mercedes-Benz'),
            $this->row('Typ', '203 (C 200 CDI)'),
            $this->row('Amtliches Kennzeichen', 'HU-AF100'),
            $this->row('Stärke in kW', '90,0 kW'),
            $this->row('Erstzulassung', '01.2004', 'Feld B'),
            $this->row('Erwerb des Fahrzeugs', '09.2025', 'Feld E'),
            $this->row('Leasingfahrzeug', 'Nein'),
            '',
            $this->row('Jährliche Fahrleistung', '6.000 km'),
            $this->row('Fahrzeugnutzung', 'überwiegend privat'),
            $this->row('Zweitwagen', 'Ja'),
            '',
            'Tarifumstellung zu',
            $this->row('Name der Gesellschaft', 'Sparkassen DirektVersicherung AG'),
            $this->row('Versicherungsnummer', '30003380161-4'),
            $this->row('Zahlweise', '1/2 jährlich'),
            "\f",
            $this->row('Kfz-Haftpflichtversicherung', 'AutoBasis'),
            $this->row('Versicherungssumme', '100 Mio. EUR pausch. (max. 15 Mio. EUR p.P.)'),
            $this->row('AutoSchutzbrief / Rabattschutz', 'Nein / Nein'),
            $this->row('Schadenfreiheitsklasse', 'SF3'),
            '',
            $this->row('Teilkasko', 'AutoBasis'),
            $this->row('Teilkasko-Selbstbeteiligung', '150 EUR'),
            $this->row('Werkstattservice / Rabattschutz', 'Ja / Nein'),
            '',
            'Ihr 1/2 jährlicher Beitrag',
            $this->row('Kfz-Haftpflichtversicherung', '425,56 EUR'),
            $this->row('Teilkasko', '52,18 EUR'),
            $this->row('Gesamtbeitrag', '477,74 EUR'),
            '',
            'Unsere Empfehlung für Sie',
            $this->row('     FahrerSchutzPlus', '14,88 EUR             Versicherungssumme 15 Mio. EUR'),
            '',
            'Folgende Unterlagen wurden mir ausgehändigt: Beratungsprotokoll, Informationsblatt',
            'zu Versicherungsprodukten.',
        ]);
    }

    public function test_reads_applicant_vehicle_and_contract(): void
    {
        $r = (new SparkasseDirektKfzParser)->parse($this->angebotText());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Erik', $p['first_name']);
        $this->assertSame('Musterfahrer', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('1992-07-23', $p['birth_date']);
        $this->assertSame('Musterstr.', $p['street']);
        $this->assertSame('2 b', $p['house_number']);
        $this->assertSame('63546', $p['zip']);
        $this->assertSame('Hammersbach', $p['city']);
        $this->assertSame('017680559524', $p['phone']);
        // Die Kunden-Adresse, NICHT service@/vertrag@sparkassen-direkt.de.
        $this->assertSame('erik.musterfahrer@example.com', $p['email']);

        $v = $r['data']['versicherung'];
        $this->assertSame('Sparkassen DirektVersicherung AG', $v['insurer']);
        $this->assertSame('30003380161-4', $v['contract_number']);
        $this->assertSame('kfz', $v['sparte']);
        $this->assertSame('AutoBasis', $v['tariff']);
        $this->assertSame('2026-09-19', $v['start_date']);
        // "Versicherungsbeginn plus ein Jahr" - Angabe des Dokuments.
        $this->assertSame('2027-09-19', $v['end_date']);
        $this->assertSame(477.74, $v['premium_amount']);
        $this->assertSame('semiannual', $v['premium_interval']);
        // Angebot/Antrag - noch nicht angenommen.
        $this->assertSame('antrag', $v['document_stage']);

        $k = $r['data']['kfz'];
        $this->assertSame('HU-AF100', $k['license_plate']);
        $this->assertSame('0710', $k['hsn']);
        $this->assertSame('916', $k['tsn']);
        $this->assertSame('Mercedes-Benz', $k['manufacturer']);
        $this->assertSame('203 (C 200 CDI)', $k['model']);
        $this->assertSame(90, $k['power_kw']);
        $this->assertSame('3', $k['sf_liability_class']);
        $this->assertTrue($k['has_teilkasko']);
        $this->assertSame(150, $k['teilkasko_deductible']);
        $this->assertFalse($k['has_vollkasko']);
        $this->assertSame(6000, $k['annual_mileage']);
        $this->assertSame('versicherungsnehmer', $k['holder_type']);
        // Werkstattservice "Ja" -> Werkstattbindung; Schutzbrief und
        // Rabattschutz stehen auf "Nein" und duerfen nicht auftauchen.
        $this->assertSame(['werkstattbindung'], $k['extras']);

        // Das SEPA-Mandat ist ein LEERES Formularfeld - keine erfundene IBAN.
        $this->assertSame([], $r['data']['bank']);
    }

    public function test_recommendation_is_not_added_to_the_premium(): void
    {
        $r = (new SparkasseDirektKfzParser)->parse($this->angebotText());

        // "FahrerSchutzPlus 14,88 EUR" ist eine Empfehlung, kein gewaehlter
        // Baustein: weder im Beitrag noch als Zusatzleistung.
        $this->assertSame(477.74, $r['data']['versicherung']['premium_amount']);
        $this->assertNotContains('fahrerschutz', $r['data']['kfz']['extras']);
    }

    public function test_month_only_dates_are_reported_but_not_stored(): void
    {
        $r = (new SparkasseDirektKfzParser)->parse($this->angebotText());

        // "01.2004" nennt keinen Tag - ein Datum daraus waere erfunden.
        $this->assertArrayNotHasKey('first_registration', $r['data']['kfz']);
        // Der Mitarbeiter sieht die Angabe trotzdem.
        $this->assertStringContainsString('Erstzulassung 01.2004', $r['summary']);
        $this->assertStringContainsString('Erwerb 09.2025', $r['summary']);
        $this->assertStringContainsString('Zweitwagen', $r['summary']);
    }

    /**
     * Das Angebot enthaelt selbst ein "Beratungsprotokoll" (eigener Abschnitt)
     * und erwaehnt es im Kleingedruckten. Das darf den Parser nicht stoppen -
     * das Ausschlussmerkmal gilt fremden CHECK24-Vergleichsangeboten.
     */
    public function test_own_consultation_section_does_not_block_the_parser(): void
    {
        $text = $this->angebotText()."\f".implode("\n", [
            'Ihre Kfz-Versicherung',
            'Beratungsprotokoll',
            'Datum der Beratung                 31.07.2026, 10:30 Uhr',
        ]);

        $r = (new SparkasseDirektKfzParser)->parse($text);

        $this->assertNotNull($r);
        $this->assertSame('30003380161-4', $r['data']['versicherung']['contract_number']);
    }

    public function test_ignores_foreign_documents(): void
    {
        $parser = new SparkasseDirektKfzParser;

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // Fremdes CHECK24-Vergleichsangebot, das die Sparkassen
        // DirektVersicherung nur als Tarif nennt.
        $this->assertNull($parser->parse(
            "Vorlaeufiges Beratungsprotokoll zur Kfz-Versicherung - CHECK24\n"
            .'Gewaehlter Tarif: Sparkassen DirektVersicherung AutoBasis'
        ));
        // Andere Sparte derselben Gesellschaft.
        $this->assertNull($parser->parse("Sparkassen DirektVersicherung AG\nUnfallversicherung"));
    }

    /**
     * Ein NAFI-Maklerantrag, der die Sparkassen DirektVersicherung als
     * Gesellschaft nennt, traegt die Ueberschrift "Antrag
     * Kraftfahrtversicherung" - er gehoert zum NAFI-Parser, nicht in dieses
     * Spaltenlayout (Ausschluss-Riegel).
     */
    public function test_nafi_antrag_is_left_to_its_own_parser(): void
    {
        $text = "Antrag Kraftfahrtversicherung\n"
            ."Versicherer / Risikoträger: Sparkassen DirektVersicherung\n"
            ."KFZ-VERSICHERUNG\nAmtliches Kennzeichen: RD-AS 1212";
        $this->assertNull((new SparkasseDirektKfzParser)->parse($text));
    }
}
