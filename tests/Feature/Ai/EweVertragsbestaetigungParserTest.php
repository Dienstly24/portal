<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\EweVertragsbestaetigungParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die EWE-Vertragsbestaetigung (Strom/Gas): liest Person,
 * Vertragsnummer, Kundennummer, Tarif, Lieferbeginn/Ende, Abschlag, MaLo-ID und
 * Netzbetreiber. Synthetische Daten, gleiche Struktur wie das Original
 * (pdftotext -layout, mehrspaltig; E-Mail am Spaltenrand umgebrochen).
 */
class EweVertragsbestaetigungParserTest extends TestCase
{
    private function confirmationText(): string
    {
        return implode("\n", [
            '    EWE VERTRIEB GmbH, 26015 Oldenburg                                  EWE VERTRIEB GmbH',
            '    Mohammed Atya',
            '    Weinberggasse 8',
            '    94209 Regen                                                         Ihre Vertragsnummer: 1004418075',
            '                                                                        Ihre Kundennummer: 22434078',
            '                                                                        Lieferstelle: Weinberggasse 8, 94209 Regen',
            '',
            'Herzlich willkommen – Ihre Vertragsbestätigung',
            'Sie haben sich für unser Produkt EWE Zuhause+ Grünstrom 24 entschieden.',
            '',
            'Ihre persönlichen Daten:',
            'Name:           Mohammed Atya                                                    Geburtsdatum:                    02.01.1997',
            'Adresse:        Weinberggasse 8                                                  E-Mail:                          mohammedatya2019@gmail.c',
            '                94209 Regen                                                                                       om',
            '                                                                                 Telefonnummer:                   01521 2673376',
            '',
            'Ihre Produktdetails:',
            'Energie     Produkt                               Preisgarantie       Lieferbeginn      Arbeitspreis (Cent/kWh)     Grundpreis (Euro/Jahr)',
            'Strom       EWE Zuhause+ Grünstrom 24             24 Monate           28.07.2026        25,18        29,96          201,92         240,29',
            '',
            'Ihre Erstlaufzeit endet am 27.07.2028. Sie können Ihren Vertrag kündigen.',
            '',
            'Produkt                                                                  Nettobetrag        MwSt-%      MwSt-Betrag     Monatliche Zahlung',
            'EWE Zuhause+ Grünstrom 24                                                42,02              19 %        7,98            50,00',
            '',
            '        Bank:                                   SPARKASSE PASSAU',
            '        IBAN:                                   DE55**************3391',
            '        Kontoinhaber:                           Mohammed Atya',
            '        Gläubiger-Identifikationsnummer:        DE86ZZZ00000023447',
            '',
            'Ihre technischen Daten:',
            'Energie                                     Marktlokations-ID',
            'Strom                                       50307481544',
            '',
            'Ihr zuständiger Netzbetreiber:',
            'Firmenname                                  Adresse                                         Registername/Registernummer',
            'Bayernwerk Netz                             Lilienthalstr 0007                              Amtsgericht Regensburg',
            'EWE VERTRIEB GmbH, Cloppenburger Str. 310, 26133 Oldenburg   IBAN: DE43 2802 0050 1426 2927 00',
        ]);
    }

    public function test_parses_ewe_electricity_confirmation(): void
    {
        $r = (new EweVertragsbestaetigungParser())->parse($this->confirmationText());

        $this->assertNotNull($r);
        $this->assertSame('energieauftrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Mohammed', $p['first_name']);
        $this->assertSame('Atya', $p['last_name']);
        $this->assertSame('1997-01-02', $p['birth_date']);
        $this->assertSame('Weinberggasse', $p['street']);
        $this->assertSame('8', $p['house_number']);
        $this->assertSame('94209', $p['zip']);
        $this->assertSame('Regen', $p['city']);
        // E-Mail wurde ueber den Spaltenumbruch hinweg zusammengesetzt.
        $this->assertSame('mohammedatya2019@gmail.com', $p['email']);
        $this->assertSame('015212673376', $p['phone']);

        $v = $r['data']['versicherung'];
        $this->assertSame('EWE VERTRIEB GmbH', $v['insurer']);
        $this->assertSame('strom', $v['sparte']); // NICHT "gas" (Boilerplate "Erdgas")
        $this->assertSame('1004418075', $v['contract_number']);
        $this->assertSame('2026-07-28', $v['start_date']);
        $this->assertSame('2028-07-27', $v['end_date']);
        $this->assertSame(50.0, $v['premium_amount']); // Monatliche Zahlung, NICHT der Nettobetrag
        $this->assertSame('monthly', $v['premium_interval']);
        $this->assertSame('EWE Zuhause+ Grünstrom 24', $v['tariff']);
        // Die Bestaetigung belegt den Abschluss: Stufe 'vertrag'. Damit
        // vervollstaendigt sie einen frueher hochgeladenen Auftrag, statt
        // einen zweiten Vertrag anzulegen.
        $this->assertSame('vertrag', $v['document_stage']);

        $e = $r['data']['energie'];
        $this->assertSame('50307481544', $e['malo_id']);
        $this->assertSame('22434078', $e['customer_number']); // Kundennummer beim Anbieter
        $this->assertSame('Bayernwerk Netz', $e['grid_operator']);
        // Tarifpreise: jeweils der BRUTTO-Wert; der Grundpreis steht pro Jahr
        // (240,29) und wird auf den Monat umgerechnet.
        $this->assertSame(29.96, $e['working_price']);
        $this->assertSame(20.02, $e['base_price']);

        // Weder die maskierte Kunden-IBAN noch die Bankverbindung der EWE
        // duerfen als Bank uebernommen werden.
        $this->assertSame([], $r['data']['bank']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new EweVertragsbestaetigungParser())->parse('Irgendein anderes Dokument'));
        // EWE erwaehnt, aber keine Vertragsbestaetigung.
        $this->assertNull((new EweVertragsbestaetigungParser())->parse('EWE Newsletter zum Thema Energie sparen'));
    }
}
