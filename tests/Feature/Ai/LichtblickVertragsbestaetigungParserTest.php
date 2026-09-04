<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\LichtblickAuftragParser;
use App\Services\Ai\TemplateParsers\LichtblickVertragsbestaetigungParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die LichtBlick-Kundenschreiben NACH dem Auftrag
 * (Vertragsbestaetigung und Abschlagsuebersicht): Kopfblock mit Kundennummer,
 * endgueltiger Vertragsnummer, Zaehlernummer, MaLo-ID und Tarif; dazu
 * Lieferbeginn und monatlicher Brutto-Abschlag. Die Schreiben kommen als
 * Handyfoto - der Text entspricht dem OCR (keine Spaltengeometrie).
 * Synthetische Daten, gleiche Struktur wie die Originale.
 */
class LichtblickVertragsbestaetigungParserTest extends TestCase
{
    private function bestaetigungText(): string
    {
        return implode("\n", [
            'LichtBlick',
            'LichtBlick SE - Postfach 10 09 23 - 20006 Hamburg',
            '03.08.2026',
            'Kundennummer: 21363427',
            'Vertragsnummer: 31682484',
            'Zählernummer: 42811442',
            'Marktlokations-ID: 51214022992',
            'Lieferstelle: Mashhour Altahan',
            'Liegnitzer Str. 16',
            '24768 Rendsburg',
            'Tarif: ÖkoStrom 24',
            'Ab jetzt fließt nur noch gute Energie!',
            'Hallo Mashhour Altahan,',
            'hiermit bestätigen wir Ihren Vertrag: Ab dem 15.08.2026 werden Sie mit ÖkoStrom von LichtBlick versorgt.',
            'Ihr künftiger monatlicher Abschlag beträgt 65,00 € (inkl. 10,38 € MwSt. in gesetzlicher Höhe), basierend auf',
            'dem von Ihnen angegebenen Jahresverbrauch von 1.800,00 kWh.',
            'Bitte denken Sie daran, uns Ihren Zählerstand zum 15.08.2026 mitzuteilen.',
            'Klimafreundliche Grüße',
            'Ihr LichtBlick-Kundenservice',
            // Fusszeile: Anschrift und BANKVERBINDUNG der LichtBlick SELBST.
            'LichtBlick SE',
            'Klostertor 1',
            '20097 Hamburg',
            'Bankverbindung:',
            'Commerzbank AG (BIC: DRESDEFF200)',
            'IBAN: DE44 2008 0000 0913 2399 03',
        ]);
    }

    private function abschlagsuebersichtText(): string
    {
        return implode("\n", [
            'LichtBlick',
            '03.08.2026',
            'Kundennummer: 21363427',
            'Vertragsnummer: 31682484',
            'Zählernummer: 42811442',
            'Marktlokations-ID: 51214022992',
            'Lieferstelle: Mashhour Altahan',
            'Liegnitzer Str. 16',
            '24768 Rendsburg',
            'Tarif: ÖkoStrom 24',
            'Damit Sie gut planen können: Ihre Abschlagsübersicht',
            'Hallo Mashhour Altahan,',
            'Nachfolgend finden Sie eine Übersicht Ihrer Abschlagszahlungen, gültig ab dem 15.08.2026.',
            'Bezeichnung Nettobetrag MwSt. Bruttobetrag',
            'Abschlagszahlung 54,62 € 10,38 € (19 %) 65,00 €',
            'Monatliche Abschlagsplanung',
            '01.09.2026 01.10.2026 02.11.2026 01.12.2026 04.01.2027 01.02.2027',
            '65,00 € 65,00 € 65,00 € 65,00 € 65,00 € 65,00 €',
            'Die Abschlagszahlungen wird LichtBlick von Ihrem hinterlegten Konto einziehen:',
            // Maskierte Kunden-IBAN - darf NIE uebernommen werden.
            'IBAN: DE58****************2795',
            'SPARKASSE MITTELHOLSTEIN AG (BIC: NOLADE21RDB)',
            'LichtBlick SE',
            'Klostertor 1',
            '20097 Hamburg',
            'IBAN: DE44 2008 0000 0913 2399 03',
        ]);
    }

    public function test_reads_confirmation_letter(): void
    {
        $r = (new LichtblickVertragsbestaetigungParser)->parse($this->bestaetigungText());

        $this->assertNotNull($r);
        $this->assertSame('energieauftrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Mashhour', $p['first_name']);
        $this->assertSame('Altahan', $p['last_name']);
        // Anschrift des KUNDEN - nicht "Klostertor 1, 20097 Hamburg"
        // (LichtBlick-Fusszeile).
        $this->assertSame('Liegnitzer Str.', $p['street']);
        $this->assertSame('16', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);

        $v = $r['data']['versicherung'];
        $this->assertSame('LichtBlick', $v['insurer']);
        $this->assertSame('strom', $v['sparte']);
        // Die ENDGUELTIGE Vertragsnummer, Stufe 'vertrag'.
        $this->assertSame('31682484', $v['contract_number']);
        $this->assertSame('vertrag', $v['document_stage']);
        $this->assertSame('2026-08-15', $v['start_date']);
        $this->assertSame(65.0, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
        $this->assertSame('ÖkoStrom 24', $v['tariff']);

        $e = $r['data']['energie'];
        $this->assertSame('21363427', $e['customer_number']);
        $this->assertSame('42811442', $e['meter_number']);
        $this->assertSame('51214022992', $e['malo_id']);
        $this->assertSame(1800, $e['consumption_kwh']);

        // KEINE Bankdaten: die IBAN im Fusstext gehoert der LichtBlick.
        $this->assertSame([], $r['data']['bank']);
    }

    public function test_reads_payment_plan_letter(): void
    {
        $r = (new LichtblickVertragsbestaetigungParser)->parse($this->abschlagsuebersichtText());

        $this->assertNotNull($r);
        $v = $r['data']['versicherung'];
        $this->assertSame('31682484', $v['contract_number']);
        $this->assertSame('vertrag', $v['document_stage']);
        $this->assertSame('2026-08-15', $v['start_date']);
        // BRUTTO-Spalte der Tabellenzeile (54,62 netto -> 65,00 brutto).
        $this->assertSame(65.0, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);

        $this->assertSame('21363427', $r['data']['energie']['customer_number']);
        $this->assertSame('42811442', $r['data']['energie']['meter_number']);

        // Weder die maskierte Kunden-IBAN noch die LichtBlick-eigene IBAN.
        $this->assertSame([], $r['data']['bank']);
        $this->assertStringContainsString('Abschlagsuebersicht', $r['summary']);
    }

    public function test_does_not_claim_the_order_form_and_vice_versa(): void
    {
        // Der AUFTRAG hat noch keine "Vertragsnummer:" - dieser Parser laesst
        // ihn in Ruhe (eigener Parser); umgekehrt vereinnahmt der
        // Auftrags-Parser die Bestaetigungsschreiben nicht.
        $auftrag = "Auftrag\nLichtBlick ÖkoStrom\nZählernummer\n42811442";
        $this->assertNull((new LichtblickVertragsbestaetigungParser)->parse($auftrag));

        $this->assertNull((new LichtblickAuftragParser)->parse($this->bestaetigungText()));
        $this->assertNull((new LichtblickAuftragParser)->parse($this->abschlagsuebersichtText()));
    }

    /**
     * REGRESSION (Audit 11.08.2026): ein LichtBlick-AUFTRAG (Seite 1 ohne
     * Vertragsnummer), dessen AGB-Folgeseiten die Woerter "Kundennummer:" und
     * "Vertragsnummer:" im Rechtstext tragen, darf NICHT als Bestaetigung
     * (Stufe vertrag) vereinnahmt werden - die Erkennung laeuft nur auf der
     * ersten Seite.
     */
    public function test_does_not_claim_order_with_agb_labels_on_later_pages(): void
    {
        // Realistische erste Seite (Auftrags-Formular, > 200 Zeichen, damit
        // firstPage() sie als echte Seite nimmt) - OHNE "Vertragsnummer:".
        $seite1 = implode("\n", [
            'LichtBlick SE - Ihr Auftrag für ÖkoStrom',
            'Vielen Dank für Ihren Wechsel zu LichtBlick. Wir kümmern uns um alles Weitere,',
            'einschließlich der Kündigung bei Ihrem bisherigen Anbieter.',
            'Kundennummer:   20097',
            'Lieferstelle: Musterstraße 1, 20095 Hamburg',
            'Zählernummer 42811442',
            'Jahresverbrauch 3500 kWh',
            'Ihr gewählter Tarif: LichtBlick ÖkoStrom mit Preisgarantie bis Ende des Jahres.',
        ]);
        // Folgeseite (AGB/Rechtstext) mit den Bestaetigungs-Beschriftungen:
        $agb = "Allgemeine Geschäftsbedingungen\nBei Rueckfragen halten Sie Ihre "
            .'Vertragsnummer: 1234567 und Ihre Kundennummer: 20097 bereit.';
        $this->assertGreaterThan(200, mb_strlen($seite1));

        $result = (new LichtblickVertragsbestaetigungParser)->parse($seite1."\f".$agb);
        $this->assertNull($result, 'Der Auftrag darf nicht ueber AGB-Labels als Bestaetigung gelesen werden.');
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new LichtblickVertragsbestaetigungParser;
        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // EWE-Bestaetigung (eigener Parser) hat andere Kopf-Beschriftungen.
        $this->assertNull($parser->parse("EWE VERTRIEB GmbH\nVertragsbestätigung\nVertragskonto: 123"));
    }
}
