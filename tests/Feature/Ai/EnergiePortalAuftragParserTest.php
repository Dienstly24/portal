<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\EnergiePortalAuftragParser;
use Tests\TestCase;

/**
 * Auftrags-Uebersicht aus dem Vertriebsportal eines Energie-Vergleichs-
 * portals, hochgeladen als SCREENSHOT (OCR). Synthetische Daten, gleicher
 * Aufbau wie das Original: drei Spalten nebeneinander, deren Zellen die OCR
 * in einer Zeile zusammenfasst.
 */
class EnergiePortalAuftragParserTest extends TestCase
{
    private function screenshotText(array $ersetzungen = []): string
    {
        $text = implode("\n", [
            '1672525 - Musterenergie AG - Fair Ökostrom 24',
            'Herr Karim Muster',
            '',
            'Übersicht     Dokumente 1     Anfrage zum Vertrag',
            '',
            'Musterenergie                   Belieferungsanschrift                    Anschrift des Kontoinhaber',
            '',
            'Tarifübersicht                  Herr Karim Muster                        Herr Karim Muster',
            '                                Musterallee 141                          Musterallee 141',
            'Anbieter      Musterenergie AG  24768 Rendsburg                          24768 Rendsburg',
            '                                geboren am: 03.04.1978',
            'Produkt       Fair Ökostrom 24',
            '                                Tel: +49 0176 23681009                   Konto: 0105653802',
            'Abnehmer      Privat            Mail: karim.muster@example.com           BLZ: 21450000 (Sparkasse Muster)',
            '                                                                         IBAN: DE82214500000105653802',
            'Tariftyp      Strom                                                      BIC: NOLADE21RDB',
            '',
            'Tarifdaten                      Belieferung',
            '',
            'Grundpreis    167,80 € / Jahr   Auftragsnummer         1672525',
            '',
            'Arbeitspreis  32,45 ct / kWh    Netzbetreiber          Stadtwerke Muster GmbH',
            '',
            '1 Monat Kündigungsfrist         MaLo-ID                51214126166',
            '',
            '24 Monate E-Preisgarantie       Vorjahresverbrauch HT  2800 kWh / Jahr',
            '',
            '24 Monate Vertragslaufzeit      Status                 1000 - Auftrag komplett erfasst, wartend auf manuelle Prüfung',
            '',
            '                                gew. Lieferdatum       schnellstmöglich',
            'Lieferdatum',
            '                                Zählernummer           1EBZ0103873550',
            '',
            'Lieferdatum ist voraussichtlich  bish. Kundennummer    200063867',
            '',
            '                                Vorversorger           Stadtwerke Muster GmbH',
            'Kundennummer',
            '                                Unterschriftsdatum     16.08.2026',
            '',
            '                                Zusatzinfos',
            '',
            '                                Zahlung                erfolgt per Bankeinzug',
        ]);

        return str_replace(array_keys($ersetzungen), array_values($ersetzungen), $text);
    }

    public function test_reads_portal_order_overview(): void
    {
        $r = (new EnergiePortalAuftragParser())->parse($this->screenshotText());

        $this->assertNotNull($r);
        $this->assertSame('energieauftrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Karim', $p['first_name']);
        $this->assertSame('Muster', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('1978-04-03', $p['birth_date']);
        // Anschrift aus dem Belieferungsblock - NICHT die Reiterleiste
        // ("Dokumente 1") und nicht der Portal-Kopf.
        $this->assertSame('Musterallee', $p['street']);
        $this->assertSame('141', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);
        $this->assertSame('karim.muster@example.com', $p['email']);
        // "+49 0176 ..." wird zur nationalen Schreibweise normalisiert.
        $this->assertSame('017623681009', $p['phone']);

        $e = $r['data']['energie'];
        $this->assertSame('51214126166', $e['malo_id']);
        $this->assertSame('1EBZ0103873550', $e['meter_number']);
        $this->assertSame(2800, $e['consumption_kwh']);
        $this->assertSame(32.45, $e['working_price']);
        // Grundpreis steht je JAHR, die Kundenakte fuehrt ihn je Monat.
        $this->assertSame(13.98, $e['base_price']);
        $this->assertSame('Stadtwerke Muster GmbH', $e['grid_operator']);
        $this->assertSame('Stadtwerke Muster GmbH', $e['previous_provider']);
        $this->assertSame('200063867', $e['previous_customer_number']);
        $this->assertSame('Fair Ökostrom 24', $e['tariff']);

        $v = $r['data']['versicherung'];
        $this->assertSame('Musterenergie AG', $v['insurer']);
        $this->assertSame('strom', $v['sparte']);
        $this->assertSame('antrag', $v['document_stage']);
        // Ein Auftrag traegt KEINE Vertragsnummer.
        $this->assertArrayNotHasKey('contract_number', $v);
        // "schnellstmoeglich" ist kein Datum - beim Stadtwerke-Wechsel gilt
        // die 14-Tage-Frist + Bearbeitung.
        $this->assertArrayNotHasKey('start_date', $v);
        $this->assertSame(20, $v['expected_start_within_days']);

        $b = $r['data']['bank'];
        $this->assertSame('DE82214500000105653802', $b['iban']);
        $this->assertSame('NOLADE21RDB', $b['bic']);
        $this->assertSame('Karim Muster', $b['account_holder']);

        $this->assertStringContainsString('Auftragsnummer 1672525 (keine Vertragsnummer', $r['summary']);
        $this->assertStringContainsString('167,80 € / Jahr = 13,98 EUR/Monat', $r['summary']);
        $this->assertStringContainsString('Vertragslaufzeit: 24 Monate', $r['summary']);
        $this->assertStringContainsString('Kündigungsfrist: 1 Monat.', $r['summary']);
        $this->assertStringContainsString('Portal-Status: 1000', $r['summary']);
    }

    public function test_reads_block_wise_ocr_output(): void
    {
        // Manche OCR-Laeufe geben die Spalten NACHEINANDER aus (Block fuer
        // Block) statt nebeneinander - die Anschrift muss trotzdem sitzen.
        $text = implode("\n", [
            '1672525 - Musterenergie AG - Fair Ökostrom 24',
            'Herr Karim Muster',
            'Tarifübersicht',
            'Anbieter      Musterenergie AG',
            'Produkt       Fair Ökostrom 24',
            'Tariftyp      Strom',
            'Belieferungsanschrift',
            'Herr Karim Muster',
            'Musterallee 141',
            '24768 Rendsburg',
            'geboren am: 03.04.1978',
            'Belieferung',
            'Auftragsnummer         1672525',
            'MaLo-ID                51214126166',
            'Vorversorger           Stadtwerke Muster GmbH',
        ]);

        $r = (new EnergiePortalAuftragParser())->parse($text);

        $this->assertNotNull($r);
        $this->assertSame('Musterallee', $r['data']['person']['street']);
        $this->assertSame('141', $r['data']['person']['house_number']);
        $this->assertSame('24768', $r['data']['person']['zip']);
        $this->assertSame('51214126166', $r['data']['energie']['malo_id']);
        // Ohne erkannten Kontoinhaber-Block keine Bankdaten.
        $this->assertSame([], $r['data']['bank']);
    }

    public function test_foreign_account_holder_is_not_taken(): void
    {
        $r = (new EnergiePortalAuftragParser())->parse($this->screenshotText([
            'Tarifübersicht                  Herr Karim Muster                        Herr Karim Muster' =>
            'Tarifübersicht                  Herr Karim Muster                        Frau Erika Fremd',
        ]));

        $this->assertNotNull($r);
        $this->assertSame([], $r['data']['bank']);
        $this->assertStringContainsString('Ohne Bankuebernahme', $r['summary']);
        // Die Kundendaten bleiben vollstaendig.
        $this->assertSame('Muster', $r['data']['person']['last_name']);
    }

    public function test_real_delivery_date_wins_over_estimate(): void
    {
        $r = (new EnergiePortalAuftragParser())->parse($this->screenshotText([
            'gew. Lieferdatum       schnellstmöglich' => 'gew. Lieferdatum       01.10.2026',
        ]));

        $this->assertSame('2026-10-01', $r['data']['versicherung']['start_date']);
        $this->assertArrayNotHasKey('expected_start_within_days', $r['data']['versicherung']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new EnergiePortalAuftragParser();

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // Der LichtBlick-PDF-Auftrag hat seinen eigenen Parser und traegt
        // die Portal-Ueberschriften nicht.
        $this->assertNull($parser->parse(
            "LichtBlick ÖkoStrom\nAuftragsnummer 1659475\nArbeitspreis: 33,93 Cent/kWh"
        ));
    }
}
