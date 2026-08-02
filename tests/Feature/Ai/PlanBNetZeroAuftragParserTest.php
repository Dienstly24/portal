<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\PlanBNetZeroAuftragParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den Strom-Auftrag der PLAN-B NET ZERO ENERGY GmbH
 * (Versorgerwechsel). Beschriftung und Wert stehen NEBENeinander, oft zweimal
 * je Zeile - die Zeile wird in Spalten zerlegt. Synthetische Daten, gleiche
 * Struktur wie das Original (pdftotext -layout).
 */
class PlanBNetZeroAuftragParserTest extends TestCase
{
    /** Zeile mit bis zu zwei Beschriftung/Wert-Paaren. */
    private function row(string $l1, string $v1, string $l2 = '', string $v2 = ''): string
    {
        $line = '  ' . str_pad($l1, 40) . str_pad($v1, 45);
        return $l2 === '' ? rtrim($line) : rtrim($line . str_pad($l2, 45) . $v2);
    }

    private function auftragText(string $vorversorger = 'LichtBlick SE', string $lieferdatum = ''): string
    {
        return implode("\n", [
            '1660174              10009798                        02.08.2026',
            '',
            'Formular ausfüllen und',
            'unterschrieben zurücksenden an:',
            'PLAN-B NET ZERO ENERGY GmbH',
            'Mühlheimer Tor - Dieselstr. 2',
            '63165 Mühlheim am Main',
            'kunde@planbnetzero-energy.com',
            '',
            'Auftrag Stromlieferung',
            '1. Sie möchten',
            '  4 zu PLAN-B wechseln.',
            $this->row('derzeitige Vertragsnummer*', '20661010', 'derzeitiger Lieferant*', $vorversorger),
            '',
            '2. Ab wann möchten Sie beliefert werden?',
            $this->row('Datum', $lieferdatum === '' ? '☐' : $lieferdatum),
            '                                                        8 oder zum nächstmöglichen Termin',
            '',
            '3. Ihre Daten',
            '  4 Frau ☐ Herr ☐ Divers                  ☐ Firma',
            $this->row('Vorname / Firma*', 'Joumana', 'Nachname / Ansprechpartner*', 'El Karout'),
            $this->row('Telefon*', '0176-31135900', 'Geburtsdatum*', '08.02.1972'),
            $this->row('E-Mail*', 'ramielkarout@example.com'),
            'Mit * gekennzeichnete Felder sind Pflichtfelder.',
            '4. Welche Verbrauchsstelle soll PLAN-B beliefern?',
            $this->row('Straße / Hausnummer*', 'Rahel-Varnhagen-Weg 17', 'PLZ / Ort*', '21035 Hamburg'),
            // "Zaehlerstand" ist ein LEERES Feld - direkt danach folgt die
            // naechste Beschriftung.
            $this->row('Zählernummer*', '1ISK0073415272', 'Zählerstand', ''),
            $this->row('Ablesedatum', 'Jahresverbrauch in kWh*', '2800'),
            $this->row('Marktlokation (MaLo-ID)', '50835589844'),
            '6. Preise / Preissicherheit',
            '  Tarif / Preisstand                    Arbeitspreis (ct/kWh)                     Grundpreis (€/Monat)',
            '                              netto                brutto                netto              brutto',
            '  PBNZE NEO P0                27,79                33,07                 7,13               8,49',
            '8. Bonuszahlung',
            '  Neukundenbonus              0,00                 0,00',
            '  Sofortbonus                 0,00                 0,00',
            '  Ort                         Datum                Unterschrift Kunde',
            ' Hamburg                      02.08.2026           ✕',
        ]);
    }

    /** Seite 2 des Originals: ausgefuelltes SEPA-Lastschriftmandat. */
    private function sepaSeite(string $kontoinhaber = 'Joumana El Karout'): string
    {
        return implode("\n", [
            '13. Sie möchten bequem per Lastschriftverfahren (SEPA) bezahlen?',
            '  SEPA-Lastschriftmandat',
            '  Zahlungsempfänger: PLAN-B NET ZERO ENERGY GmbH, Mühlheimer Tor - Dieselstr. 2',
            '  IBAN                                              DE62100100100653725116',
            '  Kontoinhaber                                      ' . $kontoinhaber,
        ]);
    }

    private function vollesDokument(string $kontoinhaber = 'Joumana El Karout'): string
    {
        return implode("\f", [
            $this->auftragText(),
            $this->sepaSeite($kontoinhaber),
            "PLAN-B NET ZERO ENERGY GmbH – Allgemeine Geschäftsbedingungen (AGB)\n"
                . 'Der Vertrag verlängert sich jeweils um ein Jahr.',
            "Datenschutzhinweise\nDaten aus Beratungsprotokollen werden verarbeitet.",
        ]);
    }

    public function test_reads_the_whole_order_form(): void
    {
        $r = (new PlanBNetZeroAuftragParser())->parse($this->vollesDokument());

        $this->assertNotNull($r);
        $this->assertSame('energieauftrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Joumana', $p['first_name']);
        $this->assertSame('El Karout', $p['last_name']);
        $this->assertSame('female', $p['gender']);
        $this->assertSame('1972-02-08', $p['birth_date']);
        $this->assertSame('Rahel-Varnhagen-Weg', $p['street']);
        $this->assertSame('17', $p['house_number']);
        $this->assertSame('21035', $p['zip']);
        $this->assertSame('Hamburg', $p['city']);
        $this->assertSame('017631135900', $p['phone']);
        // Kunden-Adresse, NICHT kunde@planbnetzero-energy.com aus dem Briefkopf.
        $this->assertSame('ramielkarout@example.com', $p['email']);

        $v = $r['data']['versicherung'];
        $this->assertSame('PLAN-B NET ZERO ENERGY GmbH', $v['insurer']);
        $this->assertSame('strom', $v['sparte']);
        $this->assertSame('PBNZE NEO P0', $v['tariff']);
        $this->assertSame('antrag', $v['document_stage']);

        $e = $r['data']['energie'];
        $this->assertSame('1ISK0073415272', $e['meter_number']);
        $this->assertSame('50835589844', $e['malo_id']);
        $this->assertSame(2800, $e['consumption_kwh']);
        $this->assertSame('LichtBlick SE', $e['previous_provider']);
        $this->assertSame('20661010', $e['previous_customer_number']);
        // BRUTTO-Preise (so zahlt der Kunde), nicht netto.
        $this->assertSame(33.07, $e['working_price']);
        $this->assertSame(8.49, $e['base_price']);
        // Leeres Feld bleibt leer - kein Wert der Nachbarspalte.
        $this->assertArrayNotHasKey('meter_reading', $e);
    }

    public function test_order_number_is_not_used_as_contract_number(): void
    {
        $r = (new PlanBNetZeroAuftragParser())->parse($this->vollesDokument());

        // Ein Auftrag hat noch KEINE Vertragsnummer (Betreiber-Vorgabe
        // 02.08.2026). Die Auftragsnummer steht nur in der Zusammenfassung.
        $this->assertArrayNotHasKey('contract_number', $r['data']['versicherung']);
        $this->assertStringContainsString('Auftragsnummer 1660174', $r['summary']);
    }

    public function test_iban_only_when_account_holder_is_the_applicant(): void
    {
        $mine = (new PlanBNetZeroAuftragParser())->parse($this->vollesDokument());
        $this->assertSame('DE62100100100653725116', $mine['data']['bank']['iban']);
        $this->assertSame('Joumana El Karout', $mine['data']['bank']['account_holder']);

        // Fremder Kontoinhaber -> KEINE IBAN in der Kundenakte.
        $fremd = (new PlanBNetZeroAuftragParser())->parse($this->vollesDokument('Max Fremd'));
        $this->assertSame([], $fremd['data']['bank']);
    }

    public function test_no_delivery_date_is_invented(): void
    {
        // Der Kunde hat "zum naechstmoeglichen Termin" gewaehlt: kein Datum im
        // Auftrag. Es wird auch keines geschaetzt - die 14-Tage-Regel gilt nur
        // fuer Stadtwerke-Wechsel, hier ist der Vorversorger ein anderer.
        $r = (new PlanBNetZeroAuftragParser())->parse($this->vollesDokument());

        $this->assertArrayNotHasKey('start_date', $r['data']['versicherung']);
        $this->assertArrayNotHasKey('expected_start_within_days', $r['data']['versicherung']);
    }

    public function test_explicit_delivery_date_is_read(): void
    {
        $text = $this->auftragText(lieferdatum: '01.10.2026');

        $r = (new PlanBNetZeroAuftragParser())->parse($text);

        $this->assertSame('2026-10-01', $r['data']['versicherung']['start_date']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new PlanBNetZeroAuftragParser();

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // PLAN-B erwaehnt, aber kein Auftrag (z.B. eine Jahresrechnung).
        $this->assertNull($parser->parse("PLAN-B NET ZERO ENERGY GmbH\nJahresrechnung Strom"));
    }

    public function test_other_energy_parsers_do_not_claim_this_order(): void
    {
        // Der Auftrag nennt LichtBlick (als Vorversorger) und traegt in den AGB
        // das Wort "jeweils" - weder der LichtBlick- noch die EWE-Parser
        // duerfen ihn deshalb vereinnahmen.
        $text = $this->vollesDokument();

        $this->assertNull((new \App\Services\Ai\TemplateParsers\LichtblickAuftragParser())->parse($text));
        $this->assertNull((new \App\Services\Ai\TemplateParsers\EnergieAuftragParser())->parse($text));
        $this->assertNull((new \App\Services\Ai\TemplateParsers\EweVertragsbestaetigungParser())->parse($text));
    }
}
