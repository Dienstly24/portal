<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\TemplateParsers\TarifcheckKfzAntragUebersichtParser;
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
            '71364 Winnenden',
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
        $this->assertSame('71364', $p['zip']);
        $this->assertSame('Winnenden', $p['city']);
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
            'Versicherungsnehmer Herr Max Mustermann', 'Musterstr. 22/2', '71364 Winnenden',
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

    public function test_abschluss_seite_bleibt_beim_eigenen_parser(): void
    {
        $this->assertNull((new TarifcheckKfzAntragUebersichtParser)->parse(
            $this->ocr()."\nReferenznummer 1477-6741-9200-53\nIhr Antrag ist bei uns eingegangen"
        ));
    }

    public function test_ohne_kunde_greift_er_nicht(): void
    {
        $this->assertNull((new TarifcheckKfzAntragUebersichtParser)->parse(
            "Ihr Beitrag\nIhr Tarif\nWGV\nOptimal\nIhr Auto\nVersicherungsumfang\nHaftpflicht\nAdresse des Versicherungsnehmers"
        ));
    }
}
