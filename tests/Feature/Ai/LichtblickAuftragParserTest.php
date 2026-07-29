<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\LichtblickAuftragParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den LichtBlick-Auftrag (Versorgerwechsel, z.B. von den
 * Stadtwerken): liest Person (Geburtsdatum als TTMMJJ), Zaehlernummer/MaLo-ID,
 * bisherigen Versorger samt Kundennummer, Verbrauch, Tarifpreise, Kunden-IBAN
 * und die Auftragsnummer als vorlaeufige Vertragsnummer (Stufe 'antrag').
 * Ohne genannten Lieferbeginn + Stadtwerke-Wechsel meldet er den spaetesten
 * erwarteten Beginn (20 Tage: 14 Tage Kuendigungsfrist + Bearbeitung).
 * Synthetische Daten, gleiche Zweispalten-Struktur wie das Original.
 */
class LichtblickAuftragParserTest extends TestCase
{
    private function auftragText(bool $mitLieferbeginn = false, string $versorger = 'Stadtwerke Rendsburg GmbH'): string
    {
        $lieferbeginn = $mitLieferbeginn
            ? '               Datum des Lieferbeginns: 01.09.2026'
            : '               Datum des Lieferbeginns:';
        return implode("\n", [
            '               Auftrag                                                                                          29.07.2026',
            '',
            '               LichtBlick ÖkoStrom',
            '                                                                                                                Datum',
            '                                                                                                                10009798',
            '                                                                                                                Vertriebspartnernummer',
            '                                                                                                                1657453',
            '                                                                                                                UVP',
            '',
            '                 1 Adresse/Lieferstelle                                                                           3 Dein ÖkoStrom',
            '                                                                                                                inkl. USt.               exkl. USt.',
            '               Deine Kundendaten',
            '                                                                                                                Arbeitspreis:       33,93       Cent/kWh         28,51 Cent/kWh',
            '                                                                                                                Grundpreis:         13,35       €/Monat          11,22 €/Monat',
            '                   Frau       4 Herr           Divers    Firma',
            '                                                                                                                Tarif-Laufzeit: 24 Monate',
            '               Firma                                                          USt-IdNr.',
            '                Altahan',
            '               Nachname',
            '                Mashhour                                                                   021180',
            '               Vorname                                                                     Geburtsdatum',
            '                Liegnitzer Str.                                                             16',
            '               Straße                                                                      Hausnummer',
            '                24768                     Rendsburg',
            '               Postleitzahl             Ort',
            '                0176-32406432',
            '               Telefon- oder Mobilnummer tagsüber (für Rückfragen)',
            '                altahanmashoer@gmail.com',
            '               E-Mail-Adresse (erforderlich)',
            '                                                                                                                DE58214500000105742795',
            '                                                                                                                IBAN',
            '',
            '                 2 Daten zur Stromversorgung',
            '               42811442                                  – oder –',
            '                                                                     51214022992',
            '               Zählernummer                                          MaLo-ID',
            '',
            $lieferbeginn,
            '',
            '               4 Ich möchte LichtBlick ÖkoStrom in meiner/m jetzigen Wohnung/Haus beziehen.',
            '                ' . $versorger,
            '               Derzeitiger Stromversorger',
            '               200111411                                             64,24                                  €',
            '               Kundennummer beim derzeitigen Stromversorger          Abschlag im Monat',
            '                1800                                      kWh',
            '               Letzter Jahresstromverbrauch',
        ]);
    }

    public function test_parses_switch_order_with_expected_start(): void
    {
        $r = (new LichtblickAuftragParser())->parse($this->auftragText());

        $this->assertNotNull($r);
        $this->assertSame('energieauftrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Mashhour', $p['first_name']);
        $this->assertSame('Altahan', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        // Geburtsdatum als TTMMJJ ("021180") -> 1980-11-02.
        $this->assertSame('1980-11-02', $p['birth_date']);
        $this->assertSame('Liegnitzer Str.', $p['street']);
        $this->assertSame('16', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);
        $this->assertSame('017632406432', $p['phone']);
        $this->assertSame('altahanmashoer@gmail.com', $p['email']);

        $v = $r['data']['versicherung'];
        $this->assertSame('LichtBlick', $v['insurer']);
        $this->assertSame('strom', $v['sparte']);
        // Auftragsnummer als VORLAEUFIGE Vertragsnummer, Stufe 'antrag'.
        $this->assertSame('1657453', $v['contract_number']);
        $this->assertSame('antrag', $v['document_stage']);
        $this->assertSame('LichtBlick ÖkoStrom', $v['tariff']);
        // Abschlag 64,24 passt rechnerisch zum neuen Tarif -> uebernommen.
        $this->assertSame(64.24, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
        // Kein Lieferbeginn + Stadtwerke-Wechsel -> spaetestens 20 Tage nach Upload.
        $this->assertSame(20, $v['expected_start_within_days']);
        $this->assertArrayNotHasKey('start_date', $v);

        $e = $r['data']['energie'];
        // Zaehlernummer und MaLo-ID (auf zwei Zeilen umgebrochen).
        $this->assertSame('42811442', $e['meter_number']);
        $this->assertSame('51214022992', $e['malo_id']);
        $this->assertSame(1800, $e['consumption_kwh']);
        $this->assertSame('Stadtwerke Rendsburg GmbH', $e['previous_provider']);
        $this->assertSame('200111411', $e['previous_customer_number']);
        $this->assertSame(33.93, $e['working_price']);
        $this->assertSame(13.35, $e['base_price']);

        // Kunden-IBAN aus dem SEPA-Block.
        $this->assertSame('DE58214500000105742795', $r['data']['bank']['iban']);

        $this->assertStringContainsString('binnen ~20 Tagen', $r['summary']);
        $this->assertStringContainsString('Stadtwerke Rendsburg GmbH', $r['summary']);
    }

    public function test_explicit_delivery_date_wins_over_estimate(): void
    {
        $r = (new LichtblickAuftragParser())->parse($this->auftragText(mitLieferbeginn: true));

        $this->assertNotNull($r);
        $v = $r['data']['versicherung'];
        $this->assertSame('2026-09-01', $v['start_date']);
        $this->assertArrayNotHasKey('expected_start_within_days', $v);
    }

    public function test_no_estimate_when_previous_provider_is_not_stadtwerke(): void
    {
        // Anderer Vorversorger (kein Stadtwerk) -> die 14-Tage-Regel gilt nicht,
        // es wird KEIN Beginn geschaetzt.
        $r = (new LichtblickAuftragParser())->parse($this->auftragText(versorger: 'E.ON Energie Deutschland GmbH'));

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('expected_start_within_days', $r['data']['versicherung']);
        $this->assertSame('E.ON Energie Deutschland GmbH', $r['data']['energie']['previous_provider']);
    }

    public function test_mismatching_abschlag_is_not_taken_as_premium(): void
    {
        // Abschlag 95,00 passt NICHT zum Tarif (33,93 ct x 1800 kWh / 12 +
        // 13,35 = 64,25) -> vermutlich der ALTE Abschlag des Vorversorgers,
        // er darf nicht in den neuen Vertrag.
        $text = str_replace('64,24', '95,00', $this->auftragText());
        $r = (new LichtblickAuftragParser())->parse($text);

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('premium_amount', $r['data']['versicherung']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new LichtblickAuftragParser())->parse('Irgendein anderes Dokument'));
        // LichtBlick erwaehnt, aber kein Auftrag.
        $this->assertNull((new LichtblickAuftragParser())->parse("LichtBlick SE\nJahresrechnung Strom"));
    }
}
