<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\WgvKfzPoliceParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den Kfz-Versicherungsschein der WGV-Versicherung AG.
 * Der Schein kommt als HANDYFOTO: die OCR setzt zwischen Beschriftung und
 * Wert nur EIN Leerzeichen. Synthetische Daten, gleiche Struktur wie das
 * Original (Seite 1 Vertrag/Fahrzeug, Seite 2 Beitrag).
 */
class WgvKfzPoliceParserTest extends TestCase
{
    private function scheinText(string $umfangExtra = ''): string
    {
        $seite1 = implode("\n", [
            'WGV Versicherung • 70164 Stuttgart',
            'WGV-Versicherung AG',
            'Servicezentrum Ravensburg',
            'Meersburger Straße 3',
            '88213 Ravensburg',
            'Hauptverwaltung:',
            'Tübinger Straße 55',
            '70178 Stuttgart',
            'kfz-vertrag@wgv.de',
            'Datum 29.07.2026',
            'Herrn',
            'Karim Al Sabeel Muster',
            'Silcherweg 6',
            '88284 Wolpertswende',
            'Versicherungsschein zur Kraftfahrtversicherung',
            'Mitglieds-/Kundennummer: P 92 655 774-0',
            'Versicherungsscheinnummer: V 90 115 823/426',
            'für Fahrzeug: RV-CF 642',
            'Rechnungsnummer: R 585 646 043 2',
            'Ausfertigungsgrund: Neuvertrag gemäß Antrag vom 26.07.2026',
            'Versicherungslaufzeit:',
            'Versicherungsbeginn: 01.07.2026 0 Uhr',
            'Versicherungsablauf: 01.01.2027 0 Uhr',
            'Versichertes Fahrzeug RV-CF 642',
            'Fahrzeugart Personenkraftwagen',
            'Fahrzg.-Ident-Nr. ZFA19900000012774',
            'Hersteller FIAT (I) INKL. ALFA, LANCIA,',
            'Stärke 57 KW',
            'Erstzulassung 05.09.2006',
            'Erstzulassung auf VN 28.07.2026',
            'Herst.Nr./Typ Nr. 4136 / 668',
            'Einstufungen KH',
            'Typklasse 17',
            'Schadensfreiheitsklasse 1/2',
            'Beitragssatz 76%',
            'Weitere Merkmale zur Beitragsberechnung:',
            'Postleitzahl des Fahrzeughalters: 88284',
            'Berufsgruppe: Angestellte (m/w/d)',
            'Geburtsdatum des Versicherungsnehmers: 01.01.1999',
            'Jährliche Fahrleistung: 5.000 km',
            'Aktueller Kilometerstand am 26.07.2026: 135.752 km',
            'Nutzungsart: ausschließlich privat inkl. Arbeitsweg',
            'Bankverbindung:',
            'Landesbank Baden-Württemberg',
            'IBAN: DE79 6005 0101 0002 1270 08',
        ]);

        $seite2 = implode("\n", array_filter([
            'Versicherungsschein-Nr.: V 90 115 823/426',
            'monatlich',
            'Zahlungsperiode:',
            'Versicherungsumfang:',
            'Kraftfahrtversicherung OPTIMAL-Tarif',
            'Kraftfahrt-Haftpflichtversicherung (KH)',
            'EUR 100 Mio. pauschal für Personen-, Sach- und Vermögensschäden',
            $umfangExtra,
            '846,96 EUR',
            'Jahresbeitrag',
            'Jahresbeitrag für Vertrag einschl. gesetzliche Versicherungsteuer: 846,96 EUR',
            'Beitragsrechnung',
            'Belastung für die Zeit vom 01.07.2026 bis 01.08.2026: 70,58 EUR',
            // Rechtstext - erwaehnt "Kaskoversicherung" nur beispielhaft.
            'WICHTIGER HINWEIS AUF DIE NACHTEILIGEN FOLGEN DER VERSPÄTETEN BEITRAGSZAHLUNG:',
            'Ihre Kfz-Versicherung kann aus mehreren rechtlich selbstständigen Einzelverträgen bestehen',
            '(z. B. Kfz-Haftpflichtversicherung und Kaskoversicherung).',
            'Folgebeitrag 70,58 EUR',
            'Folgebeitrag fällig jeweils zum 01. eines Monats',
            'Die darin jeweils enthaltene VersSt. (19%) beträgt: 11,27 EUR',
            // Maskierte Kunden-IBAN.
            'Aufgrund des bestehenden SEPA-Lastschriftmandates wird der Beitrag von dem Konto mit der IBAN',
            'DE78 XXX X XX XX X XXX XX86 39 eingezogen.',
        ]));

        return $seite1."\f".$seite2;
    }

    public function test_reads_contract_person_and_vehicle(): void
    {
        $r = (new WgvKfzPoliceParser)->parse($this->scheinText());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        // Arabischer Name: erster Teil Vorname, Rest Nachname.
        $this->assertSame('Karim', $p['first_name']);
        $this->assertSame('Al Sabeel Muster', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('1999-01-01', $p['birth_date']);
        // Kunden-Anschrift, NICHT die der WGV (Meersburger Str./Tübinger Str.).
        $this->assertSame('Silcherweg', $p['street']);
        $this->assertSame('6', $p['house_number']);
        $this->assertSame('88284', $p['zip']);
        $this->assertSame('Wolpertswende', $p['city']);
        $this->assertSame('Angestellte', $p['occupation']);

        $v = $r['data']['versicherung'];
        $this->assertSame('WGV Versicherung AG', $v['insurer']);
        $this->assertSame('V 90 115 823/426', $v['contract_number']);
        $this->assertSame('kfz', $v['sparte']);
        $this->assertSame('OPTIMAL', $v['tariff']);
        $this->assertSame('2026-07-01', $v['start_date']);
        $this->assertSame('2027-01-01', $v['end_date']);
        // Der WIEDERKEHRENDE Beitrag, nicht der Jahresbeitrag.
        $this->assertSame(70.58, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
        $this->assertSame('vertrag', $v['document_stage']);

        $k = $r['data']['kfz'];
        $this->assertSame('RV-CF 642', $k['license_plate']);
        $this->assertSame('pkw', $k['vehicle_type']);
        $this->assertSame('ZFA19900000012774', $k['vin']);
        // Aus "FIAT (I) INKL. ALFA, LANCIA," wird die Marke.
        $this->assertSame('FIAT', $k['manufacturer']);
        $this->assertSame(57, $k['power_kw']);
        $this->assertSame('2006-09-05', $k['first_registration']);
        $this->assertSame('2026-07-28', $k['acquisition_date']);
        $this->assertSame('4136', $k['hsn']);
        $this->assertSame('668', $k['tsn']);
        $this->assertSame('1/2', $k['sf_liability_class']);
        $this->assertSame(5000, $k['annual_mileage']);
        $this->assertSame(135752, $k['initial_mileage']);
    }

    public function test_coverage_is_taken_from_the_scope_not_the_legal_text(): void
    {
        // Der Schein deckt NUR Haftpflicht; "Kaskoversicherung" steht lediglich
        // im Rechtstext der Beitragsseite und darf nicht als Deckung zaehlen.
        $r = (new WgvKfzPoliceParser)->parse($this->scheinText());
        $this->assertFalse($r['data']['kfz']['has_teilkasko']);
        $this->assertFalse($r['data']['kfz']['has_vollkasko']);
        $this->assertStringContainsString('keine Kasko', $r['summary']);

        // Mit echter Vollkasko im Versicherungsumfang wird sie erkannt.
        $mitKasko = (new WgvKfzPoliceParser)->parse(
            $this->scheinText('Fahrzeugvollversicherung (Vollkasko) mit 500 EUR Selbstbeteiligung')
        );
        $this->assertTrue($mitKasko['data']['kfz']['has_vollkasko']);
        $this->assertTrue($mitKasko['data']['kfz']['has_teilkasko']);
    }

    public function test_no_bank_data_is_taken(): void
    {
        $r = (new WgvKfzPoliceParser)->parse($this->scheinText());

        // Die Kunden-IBAN ist maskiert, die vollstaendige IBAN im Fussbereich
        // gehoert der WGV - beides bleibt draussen.
        $this->assertSame([], $r['data']['bank']);
        $this->assertStringNotContainsString('DE79', json_encode($r['data'], JSON_UNESCAPED_UNICODE));
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new WgvKfzPoliceParser;

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // WGV genannt, aber keine Kfz-Unterlage.
        $this->assertNull($parser->parse("WGV Versicherung AG\nHausratversicherung Beitragsrechnung"));
        // Fremdes CHECK24-Vergleichsangebot, das die WGV nur als Tarif nennt.
        $this->assertNull($parser->parse(
            "Vorlaeufiges Beratungsprotokoll zur Kfz-Versicherung - CHECK24\nTarif: WGV Optimal"
        ));
    }
}
