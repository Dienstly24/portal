<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\BigGesundParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die Formulare der BIG direkt gesund (gesetzliche
 * Krankenkasse): Mitgliedsantrag (Beitritt) und Antrag Plusbonus
 * (Zusatzversicherung/Bonus mit Bankverbindung). Synthetische Daten, gleiche
 * Struktur wie das Original (pdftotext -layout).
 */
class BigGesundParserTest extends TestCase
{
    private function mitgliedsantragText(): string
    {
        return implode("\n", [
            '                 Mitgliedsantrag',
            '                 Erstellungsdatum: 22.08.2025',
            '',
            '                 Anrede                                            Herr',
            '                 Vorname                                           Majd Aldin',
            '                 Nachname                                          Alkhatib',
            '                 Geburtsdatum                                      06.04.1994',
            '                 Geburtsort                                        Daraa',
            '                 Familienstand                                     ledig',
            '                 Adresse                                           Garten 86',
            '                                                                   72663 Grossbettlingen',
            '                                                                   Deutschland',
            '                 Telefon (Festnetz/Mobil)',
            '                                                                   01798536485',
            '                 E-Mail                                            kunde@example.de',
            '                 Bei welcher Krankenkasse sind Sie bisher          DAK-Gesundheit',
            '                 versichert?',
            '                 Versichertennummer (optional)                     G781955599',
            'BB1 202207',
            '             Bankverbindungen',
            '             Dortmunder Volksbank',
            '             IBAN DE48 4416 0014 2361 5550 00 · BIC GENODEM1DOR',
            '             Sparkasse Aachen',
            '             IBAN DE36 3905 0000 0001 8033 60 · BIC AACSDE33XXX   Markgrafenstrasse 22 · 10117 Berlin',
            '             datenschutz@big-direkt.de',
            'BIG direkt gesund',
        ]);
    }

    private function plusbonusText(): string
    {
        return implode("\n", [
            '          Antrag Plusbonus',
            '          200 Euro kassieren zum Mitgliedschaftsbeginn.',
            '           Persoenliche Angaben',
            '                maennlich        weiblich        divers',
            '                                                            Garten',
            '           Alkhatib                                                                   86',
            '          Name                                          Strasse                       Hausnummer',
            '',
            '           Majd Aldin                                    72663                         Grossbettlingen',
            '          Vorname                                       PLZ                           Ort',
            '',
            '           Zahlungsempfaenger*in',
            '           Majd Aldin Alkhatib                           Kreissparkasse Ludwigsburg',
            '          Kontoinhaber*in                               Kreditinstitut',
            '           DE20 6115 0020 0103 5852 02                                   ESSLDE66XXX',
            '          IBAN (Internationale Bankkontonummer)                         BIC',
            '            Hoehe der jaehrlichen Police',
            '                                    230                  Euro',
            '          BIG direkt gesund',
            '          44137 Dortmund                    0800 5456 5456',
        ]);
    }

    public function test_parses_mitgliedsantrag_key_fields(): void
    {
        $r = (new BigGesundParser())->parse($this->mitgliedsantragText());

        $this->assertNotNull($r);
        $this->assertSame('beitrittserklaerung', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Majd Aldin', $p['first_name']);
        $this->assertSame('Alkhatib', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('1994-04-06', $p['birth_date']);
        $this->assertSame('Daraa', $p['birth_place']);
        $this->assertSame('ledig', $p['marital_status']);
        $this->assertSame('Garten', $p['street']);
        $this->assertSame('86', $p['house_number']);
        $this->assertSame('72663', $p['zip']);
        $this->assertSame('Grossbettlingen', $p['city']);
        $this->assertSame('01798536485', $p['phone']);
        $this->assertSame('kunde@example.de', $p['email']);

        $g = $r['data']['gesundheit'];
        $this->assertSame('BIG direkt gesund', $g['health_insurance_company']);
        $this->assertSame('gesetzlich', $g['health_insurance_type']);
        $this->assertSame('DAK-Gesundheit', $g['previous_insurer']);
        $this->assertSame('G781955599', $g['health_insurance_number']);

        $this->assertSame('krankenversicherung', $r['data']['versicherung']['sparte']);

        // Die im Fuss abgedruckten Kassen-IBANs duerfen NICHT als Kunden-Bank
        // uebernommen werden.
        $this->assertSame([], $r['data']['bank']);
    }

    public function test_parses_plusbonus_bank_and_name(): void
    {
        $r = (new BigGesundParser())->parse($this->plusbonusText());

        $this->assertNotNull($r);
        $this->assertSame('beitrittserklaerung', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Majd Aldin', $p['first_name']);
        $this->assertSame('Alkhatib', $p['last_name']);
        $this->assertSame('72663', $p['zip']);
        $this->assertSame('Grossbettlingen', $p['city']);

        $b = $r['data']['bank'];
        $this->assertSame('DE20611500200103585202', $b['iban']);
        $this->assertSame('ESSLDE66XXX', $b['bic']);
        $this->assertSame('Majd Aldin Alkhatib', $b['account_holder']);

        // Nicht der beworbene Bonus (200), sondern die jaehrliche Police (230).
        $this->assertStringContainsString('230 EUR/Jahr', $r['summary']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new BigGesundParser())->parse('Irgendein anderes Dokument'));
        // BIG-Dokument ohne erkennbaren Formulartyp -> null (normale Analyse).
        $this->assertNull((new BigGesundParser())->parse('BIG direkt gesund - Newsletter'));
    }
}
