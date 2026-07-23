<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\AdmiralDirektKfzParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die AdmiralDirekt-Kfz-Beitragsrechnung: liest Versicherer,
 * Versicherungs-Nummer, Kennzeichen, Deckung/Selbstbeteiligung, SF-Klasse
 * (Haftpflicht, hier Sondereinstufung M), Jahresbeitrag und Abrechnungszeitraum.
 * Synthetische Daten, gleiche Struktur wie das Original (pdftotext -layout,
 * zweispaltiger Kopf mit Empfaenger links / Absender rechts).
 */
class AdmiralDirektKfzParserTest extends TestCase
{
    private function letterText(): string
    {
        return implode("\n", [
            '                    AdmiralDirekt, Itzehoer Platz, 25521 Itzehoe',
            '',
            '                    Herrn                                                        AdmiralDirekt',
            '                    Hasan Alsaied                                                Itzehoer Platz',
            '                    Bachackerweg 124                                             www.admiraldirekt.de',
            '                    45772 Marl                                                   Itzehoe, 27.06.2026',
            '',
            '                    Versicherungs-Nr.                         27393863-001',
            '                    Fahrzeug                                  RE XY 435 (Pkw)',
            '',
            '                    Beitragsrechnung für das Jahr 2026/2027',
            '',
            '                    Am 13.08.2026 wird der jährliche Beitrag für den Zeitraum 13.08.2026 bis 12.08.2027 fällig.',
            '',
            '                    Kfz-Haftpflicht          0        0        M        M        766,98 €    989,19 €',
            '                    Teilkasko                                                    42,53 €     45,98 €',
            '                    Gesamtbeitrag inkl. 19 % Versicherungssteuer (165,28 €)                  1.035,17 €',
            '',
            '                    Zahlungsdetails',
            '                    Den Betrag in Höhe von 1.035,17 € buchen wir am 13.08.2026 von folgendem Konto ab:',
            '                    IBAN DE72 4265 0150 XXXX XXXX 53',
            '',
            '                    Versicherungsumfang',
            '                    Basis Tarif mit:',
            '                    • Kfz-Haftpflicht inklusive Umweltschäden nach dem Umweltschadensgesetz',
            '                    • Teilkaskoversicherung mit 150 € Selbstbeteiligung (mit Werkstattbonus)',
            '',
            '                    Sondereinstufung',
            '                    Bei einem Wechsel bestätigen wir nur die tatsächliche SF-Klasse für das abgelaufene',
            '                    Versicherungsjahr (Kfz-Haftpflicht M) und etwaige aufgetretene Schäden.',
            '',
            '                    Versicherer der Produkte der Marke AdmiralDirekt',
            '                    Itzehoer Versicherung/Brandgilde von 1691',
            '                    BIC HYVEDEMM300   IBAN DE22 2003 0000 0010 4231 52',
        ]);
    }

    public function test_parses_all_key_fields(): void
    {
        $r = (new AdmiralDirektKfzParser())->parse($this->letterText());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Hasan', $p['first_name']);
        $this->assertSame('Alsaied', $p['last_name']);
        $this->assertSame('Bachackerweg', $p['street']);
        $this->assertSame('124', $p['house_number']);
        $this->assertSame('45772', $p['zip']);
        $this->assertSame('Marl', $p['city']);
        $this->assertSame('male', $p['gender']);

        $k = $r['data']['kfz'];
        $this->assertSame('RE XY 435', $k['license_plate']);
        $this->assertSame('M', $k['sf_liability_class']);
        $this->assertTrue($k['has_teilkasko']);
        $this->assertSame(150, $k['teilkasko_deductible']);
        $this->assertFalse($k['has_vollkasko']);
        $this->assertContains('werkstattbindung', $k['extras']);

        $v = $r['data']['versicherung'];
        $this->assertSame('AdmiralDirekt', $v['insurer']);
        $this->assertSame('27393863-001', $v['contract_number']);
        $this->assertSame('kfz', $v['sparte']);
        $this->assertSame('2026-08-13', $v['start_date']);
        $this->assertSame('2027-08-12', $v['end_date']);
        // Gesamtbeitrag (Jahr), NICHT der eingeklammerte Steuerbetrag (165,28).
        $this->assertSame(1035.17, $v['premium_amount']);
        $this->assertSame('yearly', $v['premium_interval']);
        $this->assertSame('Basis Tarif', $v['tariff']);

        // Weder die maskierte Kunden-IBAN noch die Versicherer-IBAN uebernehmen.
        $this->assertSame([], $r['data']['bank']);
    }

    public function test_ignores_check24_protocol_and_unrelated_documents(): void
    {
        $protocol = "Vorlaeufiges Beratungsprotokoll zur Kfz-Versicherung - CHECK24\n"
            . "Gewaehlter Tarif: AdmiralDirekt Basis";
        $this->assertNull((new AdmiralDirektKfzParser())->parse($protocol));

        $this->assertNull((new AdmiralDirektKfzParser())->parse('Irgendein anderes Dokument'));
    }
}
