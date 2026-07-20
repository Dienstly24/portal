<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\BayerischeEscooterParser;
use Tests\TestCase;

/**
 * Gratis-Parser (ohne KI) fuer die Abschlussbestaetigung der E-Scooter-
 * Versicherung ("die Bayerische"). Der synthetische Text bildet die
 * Zwei-Spalten-Struktur von `pdftotext -layout` nach (Spalten durch mehrere
 * Leerzeichen getrennt). Synthetische Daten, gleiche Struktur wie das Original.
 */
class BayerischeEscooterParserTest extends TestCase
{
    private function confirmationText(): string
    {
        return implode("\n", [
            'Die wichtigsten Details fuer Sie im Ueberblick',
            '',
            'Sie haben die Bayerische E-Scooter-Versicherung abgeschlossen.',
            '',
            'Tarifname:                              Versicherungsbeginn:',
            'Haftpflicht                             20.07.2026',
            '',
            'Ihr E-Scooter:                          Versicherungsende:',
            '                                        28.02.2027 (bedarf keiner Kuendigung)',
            'Hersteller/Modellbezeichnung:',
            'ZHEJIANG KUANTU (RC)',
            '',
            'Fahrgestellnummer:',
            'ZSF10Z23075358',
            '',
            'Kennzeichen                             Einmaliger Beitrag:',
            '611MDS                                  41,60 EUR',
            '',
            'Persoenliche Daten',
            '',
            'Versicherungsnehmer:                    Geburtsdatum:',
            'Herr                                    17.08.2004',
            'Ali Aliq                                E-Mail-Adresse:',
            'Schulstr. 17                            schanksmarschal@gmail.com',
            '66740 Saarlouis',
            '',
            'Zahlungsangaben',
            '',
            'Kontoinhaber:                           IBAN:',
            'Ali Aliq                                DE29 5935 0110 1370 7899 25',
        ]);
    }

    public function test_parses_all_key_fields(): void
    {
        $r = (new BayerischeEscooterParser())->parse($this->confirmationText());

        $this->assertNotNull($r);
        $this->assertSame('escooter_vertrag', $r['type']);

        $v = $r['data']['versicherung'];
        $this->assertSame('escooter', $v['sparte']);
        $this->assertSame('die Bayerische', $v['insurer']);
        $this->assertSame('2026-07-20', $v['start_date']);
        $this->assertSame('2027-02-28', $v['end_date']); // Fachregel: Ende Februar
        $this->assertSame(41.6, $v['premium_amount']);
        $this->assertSame('einmalig', $v['premium_interval']);
        $this->assertSame('Haftpflicht', $v['tariff']);

        $k = $r['data']['kfz'];
        $this->assertSame('ZSF10Z23075358', $k['vin']);
        $this->assertSame('611MDS', $k['license_plate']);
        $this->assertSame('ZHEJIANG KUANTU (RC)', $k['manufacturer']);
        $this->assertFalse($k['has_teilkasko']); // Tarif Haftpflicht -> kein Teilkasko

        $p = $r['data']['person'];
        $this->assertSame('Ali', $p['first_name']);
        $this->assertSame('Aliq', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('2004-08-17', $p['birth_date']);
        $this->assertSame('Schulstr.', $p['street']);
        $this->assertSame('17', $p['house_number']);
        $this->assertSame('66740', $p['zip']);
        $this->assertSame('Saarlouis', $p['city']);
        $this->assertSame('schanksmarschal@gmail.com', $p['email']);

        $b = $r['data']['bank'];
        $this->assertSame('DE29593501101370789925', $b['iban']);
        $this->assertSame('Ali Aliq', $b['account_holder']);
    }

    public function test_teilkasko_tariff_sets_teilkasko(): void
    {
        $text = str_replace(
            ['Tarifname:                              Versicherungsbeginn:', 'Haftpflicht                             20.07.2026'],
            ['Tarifname:                              Versicherungsbeginn:', 'Teilkasko                               20.07.2026'],
            $this->confirmationText()
        );

        $r = (new BayerischeEscooterParser())->parse($text);
        $this->assertNotNull($r);
        $this->assertSame('Teilkasko', $r['data']['versicherung']['tariff']);
        $this->assertTrue($r['data']['kfz']['has_teilkasko']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new BayerischeEscooterParser())->parse('Irgendein anderes Dokument ohne Bezug.'));

        // Ein KFZ-Schreiben, das E-Scooter nur beilaeufig erwaehnt, aber keine
        // der festen E-Scooter-Felder traegt, darf NICHT vereinnahmt werden.
        $kfz = "ADAC Autoversicherung AG\nKfz-Versicherung\nWir bieten auch eine E-Scooter Police an.\nKennzeichen: M-AB 123";
        $this->assertNull((new BayerischeEscooterParser())->parse($kfz));
    }
}
