<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\NovitasBeitrittserklaerungParser;
use PHPUnit\Framework\TestCase;

/**
 * Novitas-BKK-Beitrittserklaerung: die Textebene dieser Formulare hat kaputt
 * kodierte Beschriftungen (Mojibake), aber saubere WERTE. Der Parser ankert
 * auf den byte-stabilen Mojibake-Labels und liest die Werte relativ dazu.
 *
 * Die Tests verwenden SYNTHETISCHE Daten (keine echten Kundendaten), bilden
 * aber die reale Zeilen-/Spaltenstruktur inkl. der echten Mojibake-Anker nach.
 */
class NovitasBeitrittserklaerungParserTest extends TestCase
{
    /** Baut eine realistische -layout-Textebene mit Mojibake-Labels nach. */
    private function novitasText(): string
    {
        // Baut die reale -layout-Textebene nach: kaputt kodierte Beschriftungen
        // (Mojibake) neben sauberen Werten, in derselben Zeilen-/Spaltenlage wie
        // die echten Formulare. Die verwendeten Mojibake-Anker stehen als
        // Konstanten im Parser (K-53 = "Name", B3/26/01 = "weiblich" usw.).
        return implode("\n", [
            '                                              D38/77M -?G5',
            '                                              01.03.2026',
            '        H01 2/7 X 7/01?A3:>/013:?3',
            '        />?  X B3/26/01                    5 776/01           ;/A3:> G723>?/55?',
            '        Mustermann                                        Erika Beispiel',
            '        K-53                                             =:7-53',
            '        07.07.1988                    Musterstadt                    ledig',
            '        32G:?>;-?G5',
            '        12345               Beispielstadt                    12345678A012',
            '        I:? 32G:?>=:? 4-5/6/37>?-7;',
            '        Musterweg 12',
            '        E?:-93.',
            '                                     31.12.2025                    AOK Beispiel',
            '        A=5 CNNC 2/> CNNC 23/ ;3: L:-7<37<->>3',
            '        MN-/6M ;:3>>3 /7@= 7=A/?->M2<<C;3',
        ]);
    }

    public function test_parses_all_person_and_health_fields(): void
    {
        $result = (new NovitasBeitrittserklaerungParser())->parse($this->novitasText());

        $this->assertNotNull($result, 'Novitas-Formular muss erkannt werden');
        $this->assertSame('beitrittserklaerung', $result['type']);

        $person = $result['data']['person'];
        $this->assertSame('Mustermann', $person['last_name']);
        $this->assertSame('Erika Beispiel', $person['first_name']);
        $this->assertSame('1988-07-07', $person['birth_date']);
        $this->assertSame('Musterstadt', $person['birth_place']);
        $this->assertSame('ledig', $person['marital_status']);
        $this->assertSame('female', $person['gender']); // X steht vor "weiblich"
        $this->assertSame('12345', $person['zip']);
        $this->assertSame('Beispielstadt', $person['city']);
        $this->assertSame('Musterweg', $person['street']);
        $this->assertSame('12', $person['house_number']);

        $health = $result['data']['gesundheit'];
        $this->assertSame('Novitas BKK', $health['health_insurance_company']);
        $this->assertSame('gesetzlich', $health['health_insurance_type']);
        $this->assertSame('AOK Beispiel', $health['previous_insurer']);
        $this->assertSame('12345678A012', $health['pension_number']);

        // Mitgliedsbeginn = erstes Datum = Versicherungsbeginn.
        $this->assertSame('2026-03-01', $result['data']['versicherung']['start_date']);
    }

    public function test_detects_male_when_x_precedes_maennlich(): void
    {
        // Wie oben, aber das X steht vor "maennlich" statt vor "weiblich".
        $text = str_replace(
            '/>?  X B3/26/01                    5 776/01',
            '/>?  B3/26/01 X                    5 776/01',
            $this->novitasText()
        );
        $result = (new NovitasBeitrittserklaerungParser())->parse($text);
        $this->assertSame('male', $result['data']['person']['gender']);
    }

    public function test_ignores_non_novitas_documents(): void
    {
        // KKH-Beitrittserklaerung (anderer Anbieter) darf NICHT hier landen.
        $kkh = "Beitrittserklärung\nKKH Kaufmännische Krankenkasse\n"
            . "Nachname Vorname\nMustermann Max\nKrankenversicherungsnummer\nA123456789";
        $this->assertNull((new NovitasBeitrittserklaerungParser())->parse($kkh));

        // Beliebiger Fremdtext.
        $this->assertNull((new NovitasBeitrittserklaerungParser())->parse('Irgendein anderes Dokument'));
    }
}
