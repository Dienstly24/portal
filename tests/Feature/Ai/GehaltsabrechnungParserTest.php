<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\TemplateParsers\GehaltsabrechnungParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die Entgelt-/Gehaltsabrechnung: liest die Personendaten
 * des Arbeitnehmers (Name, Anschrift, Geburtsdatum) fuer die Kunden-Zuordnung
 * sowie Krankenkasse, Ueberweisungs-IBAN und Einkommen (Brutto/Netto).
 * Synthetische Daten, gleiche Struktur wie das Original (pdftotext -layout,
 * zweispaltig: Anschrift links, Merkmale rechts).
 */
class GehaltsabrechnungParserTest extends TestCase
{
    private function payslipText(): string
    {
        return implode("\n", [
            'Entgeltabrechnung                       05405/55331                 Mai 2026',
            '                                                                    Geburtsdatum                 15.11.1993',
            '                                                                    Steuerklasse                          3',
            'MHL-Nord GmbH',
            'Preetzer Str. 207 · 24147 Kiel',
            'Herrn                                                               Midijob                            Nein',
            'Tariq Mohammed Abbas Al Mansoer                                     Mehrfachbeschäftigung              Nein',
            'Schleswiger Chaussee 72                                             Krankenkasse               NOVITAS BKK',
            '24768 Rendsburg                                                     KK-Beitragssatz                  14,60',
            '',
            'Gesamtbrutto                                                        2.512,00               12.605,53',
            'Gesamtnetto                                                         1.978,18                9.918,83',
            'Auszahlung                                                            200,18                  710,83',
            '',
            'Überweisung   IBAN DE33 7005 3070 0032 2051 89',
            '              Spk Fürstenfeldbruck - Fürstenfeldbruck',
        ]);
    }

    public function test_parses_employee_health_bank_and_income(): void
    {
        $r = (new GehaltsabrechnungParser)->parse($this->payslipText());

        $this->assertNotNull($r);
        $this->assertSame('gehaltsabrechnung', $r['type']);

        $p = $r['data']['person'];
        // Voller Name (fuer die Zuordnung), Geburtsdatum, Anschrift.
        $this->assertSame('Tariq Mohammed Abbas Al Mansoer', trim(($p['first_name'] ?? '').' '.($p['last_name'] ?? '')));
        $this->assertSame('1993-11-15', $p['birth_date']);
        $this->assertSame('Schleswiger Chaussee', $p['street']);
        $this->assertSame('72', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);
        $this->assertSame('male', $p['gender']);

        // Krankenkasse.
        $this->assertSame('NOVITAS BKK', $r['data']['gesundheit']['health_insurance_company']);
        $this->assertSame('gesetzlich', $r['data']['gesundheit']['health_insurance_type']);

        // Ueberweisungskonto = IBAN des Arbeitnehmers.
        $this->assertSame('DE33700530700032205189', $r['data']['bank']['iban']);
        $this->assertSame('Tariq Mohammed Abbas Al Mansoer', $r['data']['bank']['account_holder']);

        // Arbeitgeber + Einkommen in der Zusammenfassung.
        $this->assertStringContainsString('MHL-Nord GmbH', $r['summary']);
        $this->assertStringContainsString('2.512,00 EUR', $r['summary']);
        $this->assertStringContainsString('1.978,18 EUR', $r['summary']);
        $this->assertStringContainsString('Mai 2026', $r['summary']);
    }

    /**
     * Zweite, HAEUFIGE Bauform - am echten Dokument gemessen (30.09.2026,
     * pdftotext -layout). Drei Dinge unterscheiden sie von der ersten:
     *
     * (1) Die Ueberschrift heisst "Verdienstabrechnung".
     * (2) Es gibt KEINE Anrede ueber dem Namen.
     * (3) Im Empfaengerblock bleibt eine Zeile LEER - links steht dort
     *     nichts, die erste Zelle der Zeile gehoert zur Merkmalsspalte
     *     ("Telefon"). Wer "die erste Zelle" liest, bekommt sie als Strasse.
     *
     * Der ARBEITGEBER steht als einzeilige Absenderzeile ueber dem
     * Empfaenger - ohne Rechtsform im Namen. Werte erfunden, Aufbau und
     * Spaltenpositionen wie gemessen.
     */
    private function verdienstabrechnungText(): string
    {
        return implode("\n", [
            '                                                                                                                        Seite 8',
            '',
            'SHV KV OS Mitte Nord Holstenkamp 7b 24537 Neumünster           SHV KV OS Mitte Nord',
            '                                                               Holstenkamp 7b',
            '                                                               24537 Neumünster',
            '',
            '                                                               Abrechnungsmonat             September 2026',
            'Yusuf Al Rahman                                                Sachbearbeiter/-in           Nina Beispiel',
            '                                                               Telefon                      040 000000-000',
            'Lornsenstrasse 14                                              Sachbearbeiter/-in           Tom Beispiel',
            '24837 Schleswig                                                Personalschlüssel            0000/00000/0000000000/1',
            'Landesunterkunft Beispiel',
            '',
            '                                                  Verdienstabrechnung',
            'LA        Text                                                                            Betrag EUR         Jahreswerte',
            '          Steuerklasse 1 (I) / kein Kinderfreibetrag',
            '          Geburtsdatum: 12.03.1990',
            'BRG       Gesamtbrutto                                                                          2810,30              25950,74',
            'GSN       Gesetzliches Netto                                                                    1943,77              18096,07',
            'AZB       Auszahlungsbetrag                                                                     1942,52              18499,85',
            '          Auf Konto (IBAN) : DE34 2005 0550 1234 5678 90, Musterbank, BIC',
            '          MUSTDEFFXXX',
        ]);
    }

    public function test_reads_employee_and_employer_from_verdienstabrechnung(): void
    {
        $r = (new GehaltsabrechnungParser)->parse($this->verdienstabrechnungText());

        $this->assertNotNull($r, 'Die Ueberschrift "Verdienstabrechnung" muss erkannt werden.');
        $this->assertSame('gehaltsabrechnung', $r['type']);

        $p = $r['data']['person'];
        // Kunde: Name und Anschrift - darum geht es beim Zuordnen.
        $this->assertSame('Yusuf Al', $p['first_name']);
        $this->assertSame('Rahman', $p['last_name']);
        $this->assertSame('Lornsenstrasse', $p['street']);
        $this->assertSame('14', $p['house_number']);
        $this->assertSame('24837', $p['zip']);
        $this->assertSame('Schleswig', $p['city']);
        $this->assertSame('1990-03-12', $p['birth_date']);

        // Arbeitgeber aus der Absenderzeile - in die Kundenakte, nicht nur
        // in die Zusammenfassung.
        $this->assertSame('SHV KV OS Mitte Nord', $p['employer_name']);
        $this->assertSame('Holstenkamp 7b, 24537 Neumünster', $p['employer_address']);

        // Ueberweisungskonto trotz gebrochener Beschriftung "(IBAN) :".
        $this->assertSame('DE34200505501234567890', $r['data']['bank']['iban']);
    }

    public function test_never_takes_the_neighbouring_column_as_the_street(): void
    {
        // Die leere Zeile im Empfaengerblock: links steht nichts, die erste
        // Zelle der Zeile ist "Telefon". Sie darf nie zur Anschrift werden.
        $r = (new GehaltsabrechnungParser)->parse($this->verdienstabrechnungText());

        $this->assertNotNull($r);
        $this->assertStringNotContainsStringIgnoringCase('telefon', $r['data']['person']['street']);
        $this->assertStringNotContainsStringIgnoringCase('sachbearbeiter', (string) ($r['data']['person']['employer_name'] ?? ''));
    }

    public function test_employer_never_becomes_the_customer(): void
    {
        // Rechts steht derselbe Aufbau (Name, Strasse, PLZ Ort) fuer den
        // Arbeitgeber. Der Kunde ist der Block LINKS.
        $r = (new GehaltsabrechnungParser)->parse($this->verdienstabrechnungText());

        $this->assertNotNull($r);
        $this->assertSame('Rahman', $r['data']['person']['last_name']);
        $this->assertSame('24837', $r['data']['person']['zip']);
    }

    public function test_reads_employer_block_when_the_name_stands_above_the_address(): void
    {
        // Andere Bauform: der Arbeitgeber steht als BLOCK (Name, darunter
        // die Anschrift) - dann traegt die Anschrift-Zeile keinen Namen.
        $r = (new GehaltsabrechnungParser)->parse($this->payslipText());

        $this->assertNotNull($r);
        $this->assertSame('MHL-Nord GmbH', $r['data']['person']['employer_name']);
        $this->assertSame('Preetzer Str. 207, 24147 Kiel', $r['data']['person']['employer_address']);
    }

    public function test_the_real_chain_from_the_container_reads_the_payslip(): void
    {
        // Wie beim Vertriebsportal-Auftrag: geprueft wird die ECHTE Kette -
        // ein anderer Parser darf die Abrechnung nicht vorher beanspruchen.
        $kette = app(DocumentTemplateParser::class);
        $r = $kette->parse($this->verdienstabrechnungText());

        $this->assertNotNull($r);
        $this->assertSame('gehaltsabrechnung', $r['type']);
        $this->assertSame('SHV KV OS Mitte Nord', $r['data']['person']['employer_name']);
    }

    public function test_invents_no_employer_when_the_address_carries_no_name(): void
    {
        // Absenderzeile ohne Namen und ohne Namen darueber: das Feld bleibt
        // LEER. Ein Firmenname, in dem eine Strasse steckt, waere schlimmer.
        $ocr = str_replace(
            'SHV KV OS Mitte Nord Holstenkamp 7b 24537 Neumünster           SHV KV OS Mitte Nord',
            'Holstenkamp 7b 24537 Neumünster                                SHV KV OS Mitte Nord',
            $this->verdienstabrechnungText()
        );
        $r = (new GehaltsabrechnungParser)->parse($ocr);

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('employer_name', $r['data']['person']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new GehaltsabrechnungParser)->parse('Irgendein anderes Dokument'));
    }
}
