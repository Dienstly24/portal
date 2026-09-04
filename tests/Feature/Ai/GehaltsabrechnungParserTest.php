<?php

namespace Tests\Feature\Ai;

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

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new GehaltsabrechnungParser)->parse('Irgendein anderes Dokument'));
    }
}
