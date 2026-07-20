<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\EnergieAuftragParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den Strom-/Gas-Auftrag (z.B. EWE business Grünstrom):
 * liest die Kern-Daten der ERSTEN Auftragsseite deterministisch aus der
 * Textebene. Besonderheit: der Wert steht jeweils UEBER dem Feld-Label, und
 * rechts laeuft ein AGB-Fliesstext mit, der nicht als Wert gelesen werden darf.
 */
class EnergieAuftragParserTest extends TestCase
{
    /** Baut eine Zeile: linke Spalte (Wert/Label) + optional rechte AGB-Spalte. */
    private function row(string $left, string $right = '', int $leftIndent = 10): string
    {
        $line = str_repeat(' ', $leftIndent) . $left;
        if ($right !== '') {
            $line = str_pad($line, 60) . $right;
        }
        return $line;
    }

    /** Kunst-Auftrag ohne echte PII, gleiche Struktur wie das EWE-Original. */
    private function auftragText(): string
    {
        return implode("\n", [
            $this->row('1645967', '10009798', 4),
            $this->row('Auftrag für business Grünstrom', '', 4),
            $this->row('1   Der/die Auftraggebende', '', 4),
            $this->row('EWE-Vertragsnummer/EWE-Kundennummer', '', 8),
            $this->row('Testfirma Imbiss Einzeluntern.', 'Hiermit ermächtigt der/die Kontoinhabende die EWE'),
            $this->row('Name, Vorname (ggf. Titel)', 'VERTRIEB GmbH, ... Lastschrift einzuziehen.', 8),
            $this->row('Musterweg', 'Muster, Max'),   // Strasse + Kontoinhaber rechts
            // Hausnummer steht als eigene rechte Spalte der Wertzeile:
            '          Musterweg                     12                 Muster, Max',
            $this->row('Straße       Hausnummer', 'der/die Kontoinhabende – falls abweichend von der/dem Auftraggebenden', 8),
            '          40210            Düsseldorf                      DE12500105170648489890',
            $this->row('PLZ       Ort', 'IBAN', 8),
            '          15.05.1990          0176-1234567                 Sparkasse Test',
            $this->row('Geburtsdatum       Telefonnummer für Rückfragen', 'Kreditinstitut', 8),
            $this->row('max.muster@example.com', ''),
            $this->row('E-Mail', '', 8),
            $this->row('3   Produktauswahl und weitere Angaben', '', 4),
            $this->row('EWE business Grünstrom', ''),
            $this->row('Produkt', 'zur individuellen Kundenberatung', 8),
            $this->row('Grundpreis*:', '', 8),
            $this->row('12,50                                 Euro/Monat', '', 50),
            $this->row('Arbeitspreis*:', '', 8),
            $this->row('28,00                                 Cent/kWh', '', 50),
            $this->row('3.1 Lieferung', '', 4),
            $this->row('123-4567890', 'nem bisherigen Lieferanten zu kündigen und die'),
            // Reine rechte AGB-Zeile (grosse Einrueckung) zwischen Wert und Label:
            str_repeat(' ', 60) . 'lichen Verträge mit dem zuständigen Netzbetreiber',
            $this->row('Zählernummer bei Lieferanschrift', 'schließen. Die EWE VERTRIEB GmbH ist berechtigt', 8),
            $this->row('3500', ''),
            $this->row('Letzter Jahresverbrauch in kWh', '', 8),
            $this->row('3.2 Falls Sie noch nicht Kund:in bei uns sind:', '', 4),
            $this->row('Stadtwerke Teststadt GmbH', ''),
            $this->row('Derzeitiger Lieferant', '', 8),
            $this->row('99887766', ''),
            $this->row('Kundennummer beim derzeitigen Lieferanten', '', 8),
            $this->row('EWE VERTRIEB GmbH – Sitz der Gesellschaft: Oldenburg', '', 4),
            $this->row('Gläubiger-Identifikationsnummer: DE86ZZZ00000023447', '', 4),
        ]);
    }

    public function test_recognizes_and_extracts_energy_order(): void
    {
        $r = (new EnergieAuftragParser())->parse($this->auftragText());

        $this->assertNotNull($r);
        $this->assertSame('energieauftrag', $r['type']);

        $v = $r['data']['versicherung'];
        $this->assertSame('strom', $v['sparte']);
        $this->assertSame('EWE VERTRIEB GmbH', $v['insurer']);
        $this->assertSame(12.5, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);

        $e = $r['data']['energie'];
        $this->assertSame('EWE business Grünstrom', $e['tariff']);
        $this->assertSame(3500, $e['consumption_kwh']);
        // Trotz der interleaved rechten AGB-Zeile ("...lichen Verträge...")
        // wird die richtige Zaehlernummer gelesen.
        $this->assertSame('123-4567890', $e['meter_number']);
        $this->assertSame('Stadtwerke Teststadt GmbH', $e['previous_provider']);
        $this->assertSame('99887766', $e['previous_customer_number']);
    }

    public function test_extracts_person_bank_and_company(): void
    {
        $r = (new EnergieAuftragParser())->parse($this->auftragText());
        $p = $r['data']['person'];

        $this->assertSame('Max', $p['first_name']);
        $this->assertSame('Muster', $p['last_name']);
        $this->assertSame('Testfirma Imbiss Einzeluntern.', $p['company_name']);
        $this->assertSame('1990-05-15', $p['birth_date']);
        $this->assertSame('40210', $p['zip']);
        $this->assertSame('Düsseldorf', $p['city']);
        $this->assertSame('max.muster@example.com', $p['email']);
        $this->assertSame('01761234567', $p['phone']);

        $b = $r['data']['bank'];
        // Kunden-IBAN (DE + 20 Ziffern), NICHT die Glaeubiger-ID "DE86ZZZ...".
        $this->assertSame('DE12500105170648489890', $b['iban']);
        $this->assertSame('Max Muster', $b['account_holder']);
    }

    public function test_non_energy_document_is_not_matched(): void
    {
        $this->assertNull((new EnergieAuftragParser())->parse('Irgendein anderes Dokument.'));
        // DSL-Auftrag (Anbieter/Mindestlaufzeit, aber kein Grundpreis/Arbeitspreis).
        $this->assertNull((new EnergieAuftragParser())->parse("EWE Anbieter Mindestlaufzeit 24 Monate DSL"));
    }
}
