<?php

namespace Tests\Feature\Ai;

use App\Models\Document;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\TemplateParsers\MitgliedsbescheinigungParser;
use Tests\TestCase;

/**
 * Bestaetigung einer gesetzlichen Krankenkasse ("<Name> ist bei uns
 * versichert"). Die Besonderheit: der Brief geht an den ARBEITGEBER - im
 * Empfaengerblock steht die FIRMA, der Kunde steht nur im Fliesstext. Wer die
 * uebliche Regel "Empfaenger = Kunde" anwendet, legt den Arbeitgeber als
 * Kunden an.
 *
 * Alle Werte sind ERFUNDEN und tragen nur denselben Aufbau wie das Original;
 * echte Versichertendaten gehoeren nicht ins Repository.
 */
class MitgliedsbescheinigungParserTest extends TestCase
{
    /** Fassung wie aus der OCR eines Fotos: je Zeile eine Zelle. */
    private function ocrText(): string
    {
        return implode("\n", [
            'KKH Kaufmännische Krankenkasse 30125 Hannover',
            '',
            'Pflegedienst Nordlicht',
            'Standort Hafen - Jan Petersen',
            '',
            '‚Deichweg 8',
            '',
            '25813 Husum',
            '',
            'Bestätigung: Lena Marie Vossberg ist bei uns versichert',
            '',
            'Guten Tag,',
            '',
            'KKH',
            '',
            'Es berät Sie',
            'Ihr Serviceteam',
            '',
            'Telefon 0231 2927070',
            'serviceteam2@kkh.de',
            '',
            'Bitte stets angeben',
            'Servicezeichen 0123456789012',
            '',
            '01.10.2026',
            '',
            'gern teilen wir Ihnen mit, dass Frau Lena Marie Vossberg, geboren am 14.03.2004, RVNR',
            '',
            '65140304V017, ab dem 01.08.2026 bei uns versichert ist.',
            '',
            'Bitte bestätigen Sie uns den Beginn der versicherungspflichtigen Beschäftigung',
            'unseres Mitglieds nach $ 6 DEÜV. Verwenden Sie für die Datenübermittlung unsere',
            'Krankenkassennummer 92111581.',
            '',
            'Wenn Sie künftig die Sozialversicherungsbeiträge für unser Mitglied an uns abführen, geben Sie',
            'dabei bitte stets Ihre Betriebsnummer an.',
        ]);
    }

    /** Fassung wie aus der PDF-Textebene: rechte Service-Spalte auf denselben Zeilen. */
    private function layoutText(): string
    {
        return implode("\n", [
            'KKH Kaufmännische Krankenkasse   30125 Hannover',
            '                                                              Es berät Sie',
            'Pflegedienst Nordlicht                                        Telefon 0231 2927070',
            'Standort Hafen - Jan Petersen                                 serviceteam2@kkh.de',
            'Deichweg 8',
            '25813 Husum                                                   Servicezeichen 0123456789012',
            '',
            '                                                              01.10.2026',
            '',
            'Bestätigung: Lena Marie Vossberg ist bei uns versichert',
            '',
            'Guten Tag,',
            '',
            'gern teilen wir Ihnen mit, dass Frau Lena Marie Vossberg, geboren am 14.03.2004, RVNR',
            '65140304V017, ab dem 01.08.2026 bei uns versichert ist.',
            '',
            '     Bitte bestätigen Sie uns den Beginn der versicherungspflichtigen Beschäftigung',
            '     unseres Mitglieds nach § 6 DEÜV. Verwenden Sie für die Datenübermittlung unsere',
            '     Krankenkassennummer 92111581.',
            '',
            'Mit herzlichen Grüßen',
            '',
            'Postanschrift      Persönliche Beratung vor Ort    Öffnungszeiten und',
            'KKH                Mülheimer Str. 72-74            Terminvereinbarungen finden',
            '30125 Hannover     47057 Duisburg                  Sie unter kkh.de/servicestelle',
        ]);
    }

    private function parser(): MitgliedsbescheinigungParser
    {
        return new MitgliedsbescheinigungParser;
    }

    public function test_liest_mitglied_aus_dem_fliesstext(): void
    {
        $r = $this->parser()->parse($this->ocrText());

        $this->assertNotNull($r, 'Die Bestaetigung wurde gar nicht erkannt.');
        $this->assertSame('mitgliedsbescheinigung', $r['type']);
        $p = $r['data']['person'];
        $this->assertSame('Lena Marie', $p['first_name']);
        $this->assertSame('Vossberg', $p['last_name']);
        $this->assertSame('2004-03-14', $p['birth_date']);
        $this->assertSame('female', $p['gender']);
    }

    public function test_der_arbeitgeber_wird_nie_zum_kunden(): void
    {
        $r = $this->parser()->parse($this->ocrText());
        $p = $r['data']['person'];

        // Der Empfaengerblock traegt die FIRMA - sie darf nirgends als Person
        // landen, und ihre Anschrift nicht als Kundenanschrift.
        $this->assertSame('Vossberg', $p['last_name']);
        $this->assertStringNotContainsStringIgnoringCase('Pflegedienst', (string) ($p['first_name'] ?? ''));
        $this->assertStringNotContainsStringIgnoringCase('Nordlicht', (string) ($p['last_name'] ?? ''));
        $this->assertArrayNotHasKey('street', $p);
        $this->assertArrayNotHasKey('zip', $p);
        $this->assertArrayNotHasKey('city', $p);
    }

    public function test_uebernimmt_den_arbeitgeber_in_seine_eigenen_felder(): void
    {
        $p = $this->parser()->parse($this->ocrText())['data']['person'];

        $this->assertSame('Pflegedienst Nordlicht', $p['employer_name']);
        $this->assertSame('Deichweg 8, 25813 Husum', $p['employer_address']);
    }

    public function test_liest_die_pdf_textebene_mit_geteilten_zeilen_gleich(): void
    {
        $r = $this->parser()->parse($this->layoutText());

        $this->assertNotNull($r);
        $p = $r['data']['person'];
        $this->assertSame('Vossberg', $p['last_name']);
        $this->assertSame('Pflegedienst Nordlicht', $p['employer_name']);
        // Die rechte Service-Spalte steht auf denselben Zeilen - sie darf
        // niemals zur Anschrift werden.
        $this->assertSame('Deichweg 8, 25813 Husum', $p['employer_address']);
        $this->assertStringNotContainsString('Telefon', $p['employer_address']);
        $this->assertStringNotContainsString('Servicezeichen', $p['employer_address']);
        // Und der Brieffuss ("30125 Hannover") ist nicht der Empfaenger.
        $this->assertStringNotContainsString('Hannover', $p['employer_address']);
    }

    public function test_die_krankenkassennummer_ist_nie_die_versichertennummer(): void
    {
        $r = $this->parser()->parse($this->ocrText());
        $g = $r['data']['gesundheit'];

        // 92111581 ist die Betriebsnummer der KASSE - bei allen Versicherten
        // dieselbe. Dieses Schreiben nennt gar keine Versichertennummer.
        $this->assertArrayNotHasKey('health_insurance_number', $g);
        $this->assertStringContainsString('keine Versichertennummer', $r['summary']);
    }

    public function test_die_service_adresse_der_kasse_wird_nie_kundenkontakt(): void
    {
        $r = $this->parser()->parse($this->ocrText());

        $this->assertArrayNotHasKey('email', $r['data']['person']);
        $this->assertStringNotContainsString('@kkh.de', json_encode($r['data'], JSON_UNESCAPED_UNICODE));
    }

    public function test_liest_kasse_rvnr_und_beginn(): void
    {
        $r = $this->parser()->parse($this->ocrText());

        $this->assertSame('KKH Kaufmännische Krankenkasse', $r['data']['gesundheit']['health_insurance_company']);
        $this->assertSame('gesetzlich', $r['data']['gesundheit']['health_insurance_type']);
        $this->assertSame('65140304V017', $r['data']['gesundheit']['pension_number']);
        $this->assertSame('2026-08-01', $r['data']['versicherung']['start_date']);
        $this->assertSame('krankenversicherung', $r['data']['versicherung']['sparte']);
        // Die Bescheinigung BELEGT die Mitgliedschaft - sie ergaenzt damit den
        // Antrag der Beitrittserklaerung, statt einen zweiten Vertrag anzulegen.
        $this->assertSame('vertrag', $r['data']['versicherung']['document_stage']);
    }

    public function test_eine_widersprechende_rvnr_wird_nicht_uebernommen(): void
    {
        // Die RVNR traegt ihr eigenes Geburtsdatum (TTMMJJ an Stelle 3-8).
        // 15.03.04 statt 14.03.04 = eine verlesene Ziffer.
        $text = str_replace('65140304V017', '65150304V017', $this->ocrText());
        $r = $this->parser()->parse($text);

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('pension_number', $r['data']['gesundheit']);
        $this->assertSame('2004-03-14', $r['data']['person']['birth_date']);
    }

    public function test_ausfertigung_an_den_versicherten_selbst_ist_seine_anschrift(): void
    {
        // Ohne Arbeitgeber-Beleg und mit dem NAMEN DES MITGLIEDS im
        // Empfaengerblock ist es die Ausfertigung an den Versicherten.
        $text = implode("\n", [
            'KKH Kaufmännische Krankenkasse 30125 Hannover',
            '',
            'Frau',
            'Lena Marie Vossberg',
            'Deichweg 8',
            '25813 Husum',
            '',
            'Mitgliedsbescheinigung',
            '',
            'Guten Tag,',
            '',
            'gern teilen wir Ihnen mit, dass Frau Lena Marie Vossberg, geboren am 14.03.2004,',
            'ab dem 01.08.2026 bei uns versichert ist.',
        ]);

        $p = $this->parser()->parse($text)['data']['person'];

        $this->assertSame('Deichweg', $p['street']);
        $this->assertSame('8', $p['house_number']);
        $this->assertSame('25813', $p['zip']);
        $this->assertSame('Husum', $p['city']);
        $this->assertArrayNotHasKey('employer_name', $p);
    }

    public function test_schweigt_bei_einem_brief_ohne_bestaetigung(): void
    {
        $text = implode("\n", [
            'KKH Kaufmännische Krankenkasse 30125 Hannover',
            '',
            'Pflegedienst Nordlicht',
            'Deichweg 8',
            '25813 Husum',
            '',
            'Ihre Beitragsrechnung',
            '',
            'Guten Tag,',
            '',
            'anbei erhalten Sie die Abrechnung Ihrer Krankenkasse fuer das Quartal.',
        ]);

        $this->assertNull($this->parser()->parse($text));
    }

    public function test_schweigt_ohne_name_und_geburtsdatum_des_mitglieds(): void
    {
        $text = implode("\n", [
            'KKH Kaufmännische Krankenkasse 30125 Hannover',
            '',
            'Pflegedienst Nordlicht',
            'Deichweg 8',
            '25813 Husum',
            '',
            'Bestätigung: unser Mitglied ist bei uns versichert',
            '',
            'Guten Tag,',
            '',
            'gern bestaetigen wir die Mitgliedschaft.',
        ]);

        // Lieber die normale Analyse/KI, als den Empfaenger zum Kunden machen.
        $this->assertNull($this->parser()->parse($text));
    }

    public function test_die_echte_kette_aus_dem_container_liest_die_bestaetigung(): void
    {
        // Waechter: einen Parser zu schreiben genuegt nicht - er muss in der
        // Kette VOR jedem generischeren stehen und registriert sein.
        $r = app(DocumentTemplateParser::class)->parse($this->ocrText());

        $this->assertNotNull($r, 'Die Kette aus dem Container erkennt die Bestaetigung nicht.');
        $this->assertSame('mitgliedsbescheinigung', $r['type']);
        $this->assertSame('Vossberg', $r['data']['person']['last_name']);
        $this->assertSame('Pflegedienst Nordlicht', $r['data']['person']['employer_name']);
    }

    public function test_der_dokumenttyp_ist_registriert_und_kein_neues_geschaeft(): void
    {
        $this->assertArrayHasKey('mitgliedsbescheinigung', Document::AI_TYPES);
        // Die Mitgliedschaft entsteht mit der Beitrittserklaerung. Wuerde die
        // Bescheinigung als neues Geschaeft gelten, entstuende ein ZWEITER
        // Kranken-Vertrag fuer dieselbe Mitgliedschaft.
        $this->assertNotContains('mitgliedsbescheinigung', Document::NEW_BUSINESS_TYPES);
    }
}
