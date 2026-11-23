<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\TemplateParsers\TarifcheckKfzAntragUebersichtParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Zusammenfassung der Kfz-Antragsstrecke als Screenshot (Betreiber-Auftrag
 * 23.11.2026). Synthetische Daten, aber genau die Zeilenfolge, die Tesseract
 * (deu+eng, 2x vergroessert wie in `TesseractTextExtractor`) aus dem echten
 * Screenshot liefert - inklusive der Spalten-Mischzeilen ("Ihr Tarif
 * 01511234567", "Optimal Angaben zum neuen Fahrzeug andern") und des als
 * "©O" verlesenen Info-Symbols vor dem Beitrag.
 */
class TarifcheckKfzAntragUebersichtParserTest extends TestCase
{
    private function ocr(array $ersetzen = []): string
    {
        $text = implode("\n", [
            'Ihr Beitrag',
            '',
            'Adresse des Versicherungsnehmers ändern',
            'monatlich ©O58,28€',
            'f E-Mail-Adresse max.mustermann@example.com',
            'Versicherungsnehmer Herr Max Mustermann',
            '',
            'Musterstr. 22/2',
            '12345 Musterstadt',
            '',
            'Ihr Tarif 01511234567',
            '',
            'WGV',
            '',
            'WGV',
            '',
            'Optimal Angaben zum neuen Fahrzeug andern',
            'Ihr Schutz Kilometerstand 183.000 km',
            '',
            'Ihr Auto',
            '',
            'smart forfour 1.3',
            '',
            'Versicherungsumfang',
            '',
            'Haftpflicht',
            '',
            'Versicherungsbeginn Zahlungsdaten ändern',
            '23.11.2026 (bzw. Tag der',
            '',
            'Zulassung) IBAN DE89 3704 0044 0532 0130 00',
            '',
            '© Tarifdetails anzeigen Kontoinhaber Max Mustermann',
            '',
            '(Versicherungsnehmer)',
        ]);

        return strtr($text, $ersetzen);
    }

    public function test_liest_die_komplette_zusammenfassung(): void
    {
        $r = (new TarifcheckKfzAntragUebersichtParser)->parse($this->ocr());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Max', $p['first_name']);
        $this->assertSame('Mustermann', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('Musterstr.', $p['street']);
        $this->assertSame('22/2', $p['house_number']);
        $this->assertSame('12345', $p['zip']);
        $this->assertSame('Musterstadt', $p['city']);
        $this->assertSame('max.mustermann@example.com', $p['email']);
        $this->assertSame('01511234567', $p['phone']);

        $v = $r['data']['versicherung'];
        $this->assertSame('WGV', $v['insurer']);
        $this->assertSame('Optimal', $v['tariff']);
        $this->assertSame('kfz', $v['sparte']);
        $this->assertSame('antrag', $v['document_stage']);
        $this->assertSame('2026-11-23', $v['start_date']);
        $this->assertSame(58.28, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
        $this->assertArrayNotHasKey('contract_number', $v);

        $k = $r['data']['kfz'];
        $this->assertSame('smart', $k['manufacturer']);
        $this->assertSame('forfour 1.3', $k['model']);
        $this->assertSame(183000, $k['initial_mileage']);
        $this->assertArrayNotHasKey('annual_mileage', $k, 'Kilometerstand ist keine Jahresfahrleistung');
        $this->assertFalse($k['has_teilkasko']);
        $this->assertFalse($k['has_vollkasko']);

        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
        $this->assertSame('Max Mustermann', $r['data']['bank']['account_holder']);
        $this->assertStringContainsString('23.11.2026 (bzw. Tag der Zulassung)', $r['summary']);
    }

    /** Waechter: die ECHTE Kette aus dem Container waehlt diesen Parser. */
    public function test_die_echte_kette_erkennt_den_screenshot(): void
    {
        $r = app(DocumentTemplateParser::class)->parse($this->ocr());

        $this->assertNotNull($r);
        $this->assertStringStartsWith('Kfz-Antrag (Zusammenfassung der Antragsstrecke) - WGV Optimal', $r['summary']);
    }

    public function test_fremdes_konto_wird_nie_uebernommen(): void
    {
        $r = (new TarifcheckKfzAntragUebersichtParser)->parse($this->ocr([
            'Kontoinhaber Max Mustermann' => 'Kontoinhaber Erika Beispiel',
            '(Versicherungsnehmer)' => '',
        ]));

        $this->assertNotNull($r);
        $this->assertSame([], $r['data']['bank']);
        $this->assertStringContainsString('IBAN NICHT uebernommen', $r['summary']);
    }

    public function test_iban_mit_falscher_pruefziffer_wird_verworfen(): void
    {
        $r = (new TarifcheckKfzAntragUebersichtParser)->parse($this->ocr([
            'DE89 3704' => 'DE88 3704',
        ]));

        $this->assertSame([], $r['data']['bank']);
    }

    public function test_teilkasko_jaehrlich_und_beginn_ohne_datum(): void
    {
        $r = (new TarifcheckKfzAntragUebersichtParser)->parse($this->ocr([
            "\nHaftpflicht\n" => "\nHaftpflicht + Teilkasko\n",
            'monatlich ©O58,28€' => 'jährlich ©O1.058,28€',
            '23.11.2026 (bzw. Tag der' => 'Tag der Zulassung',
            'Zulassung) IBAN' => 'IBAN',
        ]));

        $this->assertTrue($r['data']['kfz']['has_teilkasko']);
        $this->assertFalse($r['data']['kfz']['has_vollkasko']);
        $this->assertSame(1058.28, $r['data']['versicherung']['premium_amount']);
        $this->assertSame('yearly', $r['data']['versicherung']['premium_interval']);
        $this->assertArrayNotHasKey('start_date', $r['data']['versicherung'], '"Tag der Zulassung" ist kein Datum');
    }

    public function test_andere_gesellschaft_und_frau(): void
    {
        $r = (new TarifcheckKfzAntragUebersichtParser)->parse($this->ocr([
            "WGV\n\nWGV\n\nOptimal" => "HUK24\n\nClassic",
            'Herr Max Mustermann' => 'Frau Erika Mustermann',
            'Kontoinhaber Max Mustermann' => 'Kontoinhaber Erika Mustermann',
        ]));

        $this->assertSame('HUK24', $r['data']['versicherung']['insurer']);
        $this->assertSame('Classic', $r['data']['versicherung']['tariff']);
        $this->assertSame('female', $r['data']['person']['gender']);
        $this->assertSame('Erika', $r['data']['person']['first_name']);
    }

    /**
     * Verkleinerter Screenshot (gemessen an 1000 px Breite): Tesseract liest
     * die Spalten NACHEINANDER und "Ihr" als "Thr".
     */
    public function test_spaltenweise_gelesen_und_thr_statt_ihr(): void
    {
        $text = implode("\n", [
            'Ihr Beitrag', 'monatlich ©58,28€', 'Thr Tarif', 'WGV', 'Optimal', 'Ihr Schutz',
            'Thr Auto', 'smart forfour 1.3', 'Versicherungsumfang', 'Haftpflicht',
            'Versicherungsbeginn', '23.11.2026 (bzw. Tag der', 'Zulassung)', '@ Tarifdetails anzeigen',
            'Adresse des Versicherungsnehmers andern', 'E-Mail-Adresse max.mustermann@example.com',
            'Versicherungsnehmer Herr Max Mustermann', 'Musterstr. 22/2', '12345 Musterstadt',
            '01511234567', 'Angaben zum neuen Fahrzeug ändern', 'Kilometerstand 183.000 km',
            'Zahlungsdaten ändern', 'IBAN DE89 3704 0044 05320130 00', 'Kontoinhaber Max Mustermann',
            '(Versicherungsnehmer)',
        ]);

        $r = (new TarifcheckKfzAntragUebersichtParser)->parse($text);

        $this->assertNotNull($r);
        $this->assertSame('WGV', $r['data']['versicherung']['insurer']);
        $this->assertSame('Optimal', $r['data']['versicherung']['tariff']);
        $this->assertSame('smart', $r['data']['kfz']['manufacturer']);
        $this->assertSame('01511234567', $r['data']['person']['phone']);
        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
        $this->assertSame('2026-11-23', $r['data']['versicherung']['start_date']);
    }

    /**
     * Betreiber-Upload 23.11.2026: derselbe Screenshot als PDF-SCAN mit
     * niedriger Aufloesung (nachgestellt mit 72 dpi). Die OCR liest
     * "Ihr Beitra", den Tarifnamen verstuemmelt ("imal .") und eine
     * Rauschzeile ("Gem"). Vorher: "Sonstiges Dokument / KI empfohlen".
     */
    public function test_pdf_scan_mit_niedriger_aufloesung(): void
    {
        $text = implode("\n", [
            'Ihr Beitra , .', '9 Adresse des Versicherungsnehmers ändern', 'monatlich © 58,28 €',
            'E-Mail-Adresse max.mustermann@example.com', 'Versicherungsnehmer Herr Max Mustermann', '',
            'Musterstr. 22/2', '', '12345 Musterstadt', 'Ihr Tarif 01511234567', 'WGV', '', 'imal .', '',
            'Gem Angaben zum neuen Fahrzeug ändern', 'Ihr Schutz Kilometerstand 183.000 km', 'Ihr Auto',
            'smart forfour 1.3', 'Versicherungsumfang', 'Haftpflicht', 'Versicherungsbeginn Zahlungsdaten ändern',
            '23.11.2026 (bzw. Tag der', 'Zulassung) IBAN DE89 37040044 0532 0130 00',
            '@ Tarifdetails anzeigen Kontoinhaber Max Mustermann', '(Versicherungsnehmer)',
        ]);

        $r = app(DocumentTemplateParser::class)->parse($text);

        $this->assertNotNull($r);
        $v = $r['data']['versicherung'];
        $this->assertSame('WGV', $v['insurer']);
        $this->assertArrayNotHasKey('tariff', $v, 'verstuemmelter Tarifname wird nicht uebernommen');
        $this->assertSame(58.28, $v['premium_amount']);
        $this->assertSame('2026-11-23', $v['start_date']);
        $this->assertSame('Mustermann', $r['data']['person']['last_name']);
        $this->assertSame(183000, $r['data']['kfz']['initial_mileage']);
        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
        $this->assertStringContainsString('Tarifname nicht sicher lesbar', $r['summary']);
    }

    public function test_abschluss_seite_bleibt_beim_eigenen_parser(): void
    {
        $this->assertNull((new TarifcheckKfzAntragUebersichtParser)->parse(
            $this->ocr()."\nReferenznummer 1477-6741-9200-53\nIhr Antrag ist bei uns eingegangen"
        ));
    }

    public function test_ohne_kunde_und_ohne_gesellschaft_greift_er_nicht(): void
    {
        $this->assertNull((new TarifcheckKfzAntragUebersichtParser)->parse(
            "Ihr Beitrag\nIhr Tarif\nunleserlich\nIhr Auto\nVersicherungsumfang\nHaftpflicht"
        ));
    }

    /** Seite 1 der ADAC-Variante (Kunde, Fahrzeug, Club, Zahlung) - wie Tesseract sie liefert. */
    private function adacKunde(): string
    {
        return implode("\n", [
            'Adresse des Versicherungsnehmers ändern', 'E-Mail-Adresse erika.muster@example.com',
            'Versicherungsnehmer Frau Erika Muster', 'Musterweg 5', '54321 Beispielstadt', '01761234567',
            'Angaben zum neuen Fahrzeug ändern', 'Kilometerstand 154.321 km',
            'Automobilclub-Mitgliedschaft ändern', 'ADAC Mitgliedschaft bereits ADAC Mitglied (712345678)',
            'Zahlungsdaten ändern', 'IBAN DE89 3704 0044 0532 0130 00', 'Kontoinhaber Erika Muster',
            '(Versicherungsnehmer)',
        ]);
    }

    /** Seite 2: Tarif/Schutz. "122,/1" = die 7 als "/" gelesen (gemessen). */
    private function adacTarif(string $preis = 'monatlich ®©122,/1€', string $streich = '129,17€ -5%'): string
    {
        return implode("\n", [
            'Ihr Beitrag', $streich, $preis, 'Ihr Tarif', 'ADAC Auto-', 'versicherung AG',
            'ADAC Autoversicherung AG', 'Basis mit Werkstattbonus (Mitglieder)', 'Ihr Schutz', 'Ihr Auto',
            'Ford Focus 1.4', 'Versicherungsumfang', 'Haftpflicht und Teilkasko', 'Selbstbeteiligung',
            '150€ (TK)', 'Versicherungsbeginn', '23.11.2026 (bzw. Tag der Zulassung)',
        ]);
    }

    public function test_adac_variante_mit_zweitem_vertrag(): void
    {
        foreach ([$this->adacKunde()."\n".$this->adacTarif(), $this->adacTarif()."\n".$this->adacKunde()] as $text) {
            $r = app(DocumentTemplateParser::class)->parse($text);

            $this->assertNotNull($r);
            $v = $r['data']['versicherung'];
            $this->assertSame('ADAC Autoversicherung AG', $v['insurer']);
            $this->assertSame('Basis mit Werkstattbonus (Mitglieder)', $v['tariff']);
            $this->assertSame(122.71, $v['premium_amount'], '"122,/1" ueber Streichpreis -5 % abgesichert');
            $this->assertSame('kfz', $v['sparte']);
            $this->assertArrayNotHasKey('contract_number', $v, 'die Mitgliedsnummer ist NICHT die Kfz-Vertragsnummer');

            $k = $r['data']['kfz'];
            $this->assertTrue($k['has_teilkasko']);
            $this->assertFalse($k['has_vollkasko']);
            $this->assertSame(150, $k['teilkasko_deductible']);
            $this->assertSame('Ford', $k['manufacturer']);
            $this->assertSame(154321, $k['initial_mileage']);

            $this->assertSame('Erika', $r['data']['person']['first_name']);
            $this->assertSame('female', $r['data']['person']['gender']);
            $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);

            $this->assertCount(1, $r['data']['weitere_vertraege']);
            $club = $r['data']['weitere_vertraege'][0]['versicherung'];
            $this->assertSame('ADAC', $club['insurer']);
            $this->assertSame('schutzbrief', $club['sparte']);
            $this->assertSame('712345678', $club['contract_number']);
            $this->assertStringContainsString('ZWEITER VERTRAG', $r['summary']);
        }
    }

    public function test_verlesener_beitrag_ohne_streichpreis_bleibt_leer(): void
    {
        $r = (new TarifcheckKfzAntragUebersichtParser)->parse($this->adacKunde()."\n".$this->adacTarif(streich: ''));

        $this->assertArrayNotHasKey('premium_amount', $r['data']['versicherung']);
        $this->assertSame('monthly', $r['data']['versicherung']['premium_interval']);
    }

    /** Zwei Bilder EINZELN hochgeladen: jede Haelfte wird fuer sich erkannt. */
    public function test_haelften_einzeln(): void
    {
        $kunde = app(DocumentTemplateParser::class)->parse($this->adacKunde());
        $this->assertSame('kontaktdaten', $kunde['type']);
        $this->assertSame('Erika', $kunde['data']['person']['first_name'], 'nie "Versicherungsnehmer" als Vorname');
        $this->assertSame('Muster', $kunde['data']['person']['last_name']);
        $this->assertSame([], $kunde['data']['versicherung']);
        $this->assertSame('712345678', $kunde['data']['weitere_vertraege'][0]['versicherung']['contract_number']);

        $tarif = app(DocumentTemplateParser::class)->parse($this->adacTarif());
        $this->assertSame('kfz_vertrag', $tarif['type']);
        $this->assertSame('ADAC Autoversicherung AG', $tarif['data']['versicherung']['insurer']);
        $this->assertSame([], $tarif['data']['person']);
    }

    /** Andere Antraege nennen "Adresse des Versicherungsnehmers" auch - ohne "aendern"-Link. */
    public function test_fremder_antrag_ist_keine_kundenseite(): void
    {
        $this->assertNull((new TarifcheckKfzAntragUebersichtParser)->parse(implode("\n", [
            'Adresse des Versicherungsnehmers', 'Versicherungsnehmer Herr Max Mustermann',
            'Musterstr. 1', '12345 Musterstadt', 'Zahlungsdaten', 'IBAN DE89 3704 0044 0532 0130 00',
        ])));
    }

    /**
     * KRAVAG-Screenshot (Betreiber-Meldung 23.11.2026, KI-131): Tesseract
     * liefert dort erst ALLE Beschriftungen einer Karte, dann alle Werte.
     * Nachgestellt mit Chromium + Tesseract (erfundene Daten), einmal im
     * Original, einmal 2x vergroessert wie im `TesseractTextExtractor`.
     * Vorher fehlten Name, IBAN, Beitrag ("monatiich"), Tarif und km-Stand.
     *
     * @return iterable<string, array{string}>
     */
    public static function spaltenFassungen(): iterable
    {
        yield 'original' => ['kfz_antrag_uebersicht_spalten.txt'];
        yield 'vergroessert' => ['kfz_antrag_uebersicht_spalten_2x.txt'];
    }

    #[DataProvider('spaltenFassungen')]
    public function test_werte_getrennt_von_ihren_beschriftungen(string $datei): void
    {
        $r = app(DocumentTemplateParser::class)->parse((string) file_get_contents(base_path('tests/Fixtures/'.$datei)));

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);
        $p = $r['data']['person'];
        $this->assertSame('Lina', $p['first_name']);
        $this->assertSame('Haddad', $p['last_name']);
        $this->assertSame('female', $p['gender']);
        $this->assertSame('karim.muster77@example.com', $p['email']);
        $this->assertSame('72793', $p['zip']);
        $v = $r['data']['versicherung'];
        $this->assertSame('KRAVAG', $v['insurer']);
        $this->assertSame('KfzPolice-Kompakt mit Werkstattservice', $v['tariff']);
        $this->assertSame(153.11, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
        $k = $r['data']['kfz'];
        $this->assertSame('Mercedes-Benz', $k['manufacturer']);
        $this->assertSame('C 200 Kompressor', $k['model']);
        $this->assertSame(225000, $k['initial_mileage']);
        $this->assertSame(150, $k['teilkasko_deductible']);
        $this->assertSame('DE89370400440532013000', $r['data']['bank']['iban']);
    }

    /** Ohne den Vermerk "(Versicherungsnehmer)" unter dem Namen kein Konto. */
    public function test_getrennter_kontoinhaber_ohne_vermerk_bleibt_ohne_iban(): void
    {
        $text = str_replace('(Versicherungsnehmer)', '', (string) file_get_contents(base_path('tests/Fixtures/kfz_antrag_uebersicht_spalten.txt')));

        $r = (new TarifcheckKfzAntragUebersichtParser)->parse($text);

        $this->assertSame([], $r['data']['bank']);
    }
}
