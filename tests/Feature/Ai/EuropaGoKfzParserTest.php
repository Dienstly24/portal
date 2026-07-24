<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\EuropaGoKfzParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die EUROPA-go-Kfz-Tarifaenderungsinformation: liest
 * Versicherer, Versicherungsnummer, Kennzeichen, Hersteller, Fahrleistung,
 * Halter, SF-Klasse (Haftpflicht), Tarif und Monatsbeitrag. Prueft besonders
 * die Trennung mehrteiliger Nachnamen ueber die Anrede ("Abo Al-Kheir").
 * Synthetische Daten, gleiche Struktur wie das Original (pdftotext -layout,
 * zweispaltiger Kopf, "Label: Wert   Label2: Wert2"-Datenblock).
 */
class EuropaGoKfzParserTest extends TestCase
{
    private function letterText(): string
    {
        return implode("\n", [
            '                                                          EUROPA-go',
            'EUROPA-go 44119 Dortmund                                  Servicecenter Kraftfahrt',
            '',
            'Herrn                                                     Zum Kundenportal:',
            'Ahmad Abo Al-Kheir                                        www.kundenportal.europa-go.de',
            'Zur Maikamer 1 a                                          www.europa-go.de',
            '46509 Xanten',
            '',
            'Information zur Tarifänderung ab 01.03.2026',
            'Versicherungsnummer:               543186134                  Jährl. Fahrleistung:      6.000 km',
            'Versichertes Wagnis:               PKW                        Km-Stand bei Antragst.:   188.000 km',
            'Hersteller:                        SKODA (CZ)                  Abstellort:               Einzel-/Doppelgarage',
            'Amtl. Kennzeichen:                 WES-AA 134                  Fahrzeugnutzung:          privat',
            'Zahlungsperiode:                   monatlich                  Fahrzeugnutzer:           Versicherungsnehmer',
            'PLZ des Halters:                   46509                      Fahrzeughalter:           Versicherungsnehmer',
            '',
            'Sehr geehrter Herr Abo Al-Kheir,',
            'wir informieren Sie über Ihren Vertragsstand zum 01.03.2026.',
            '',
            'Versicherungsumfang und Beiträge ab 01.03.2026',
            'Kfz-Haftpflicht (KH)',
            '- Basis-Tarif -              100 Mio. pauschal      17     N6     SF 2     56 %     64,38',
            '                                                    Monatlicher Gesamtbeitrag        64,38   *',
            '',
            'Versicherungsumfang und Beiträge ab 01.01.2027',
            'Kfz-Haftpflicht (KH)',
            '- Basis-Tarif -              100 Mio. pauschal      17     N6     SF 3     52 %     59,79',
            '                                                    Monatlicher Gesamtbeitrag        59,79   *',
            '',
            'Da Sie per Abruf zahlen, werden wir den neuen Beitrag von Ihrem Konto',
            '(IBAN DE07 XXXX XXXX XXXX XX69 44 / BIC WELADED1MOR) abbuchen.',
            '',
            'Beitragsgegenüberstellung',
            'Beitrag inklusive Versicherungssteuer        51,34   ---   51,34   64,38   ---   64,38',
            '',
            'Konto für Beitragszahlungen: Commerzbank Dortmund',
            'IBAN DE74 4404 0037 0340 9968 02   BIC COBADEFFXXX',
            'EUROPA-go ist eine Marke der EUROPA Versicherung AG',
        ]);
    }

    public function test_parses_all_key_fields(): void
    {
        $r = (new EuropaGoKfzParser())->parse($this->letterText());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        // Mehrteiliger Nachname ("Abo Al-Kheir") aus der Anrede, nicht nur das
        // letzte Wort.
        $this->assertSame('Ahmad', $p['first_name']);
        $this->assertSame('Abo Al-Kheir', $p['last_name']);
        $this->assertSame('Zur Maikamer', $p['street']);
        $this->assertSame('1 a', $p['house_number']);
        $this->assertSame('46509', $p['zip']);
        $this->assertSame('Xanten', $p['city']);
        $this->assertSame('male', $p['gender']);

        $k = $r['data']['kfz'];
        $this->assertSame('WES-AA 134', $k['license_plate']);
        $this->assertSame('SKODA', $k['manufacturer']); // Laenderzusatz "(CZ)" entfernt
        $this->assertSame('2', $k['sf_liability_class']); // aktuelle Klasse (ab 01.03.2026)
        $this->assertFalse($k['has_teilkasko']);
        $this->assertFalse($k['has_vollkasko']);
        $this->assertSame('versicherungsnehmer', $k['holder_type']);
        $this->assertSame(6000, $k['annual_mileage']);

        $v = $r['data']['versicherung'];
        $this->assertSame('EUROPA-go', $v['insurer']);
        $this->assertSame('543186134', $v['contract_number']);
        $this->assertSame('kfz', $v['sparte']);
        $this->assertSame('2026-03-01', $v['start_date']);
        // Der ERSTE (ab 01.03.2026 gueltige) Monatsbeitrag, nicht der von 2027.
        $this->assertSame(64.38, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
        $this->assertSame('Basis-Tarif', $v['tariff']);

        // Weder die maskierte Kunden-IBAN noch die Versicherer-IBAN uebernehmen.
        $this->assertSame([], $r['data']['bank']);
    }

    public function test_ignores_check24_protocol_and_unrelated_documents(): void
    {
        $protocol = "Vorlaeufiges Beratungsprotokoll zur Kfz-Versicherung - CHECK24\n"
            . "Gewaehlter Tarif: EUROPA-go Basis";
        $this->assertNull((new EuropaGoKfzParser())->parse($protocol));

        $this->assertNull((new EuropaGoKfzParser())->parse('Irgendein anderes Dokument'));
    }
}
