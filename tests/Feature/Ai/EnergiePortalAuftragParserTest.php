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
        $r = (new EnergiePortalAuftragParser)->parse($this->screenshotText());

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

    /**
     * Produktionsfall 16.08.2026: die echte Screenshot-OCR erhaelt das
     * Spaltenraster NICHT - die drei Spalten landen mit nur EINEM Leerzeichen
     * in derselben Zeile. Vorher fehlten dadurch Name, Anschrift, Bank,
     * MaLo-ID und Zaehlernummer komplett, und der Tarif trug die IBAN der
     * Nachbarspalte.
     */
    public function test_reads_columns_glued_with_single_spaces(): void
    {
        $text = implode("\n", [
            '1672525 - Musterenergie AG - Fair Ökostrom 24',
            'Herr Karim Muster',
            'Übersicht Dokumente 1 Anfrage zum Vertrag',
            'Musterenergie Belieferungsanschrift Anschrift des Kontoinhaber',
            'Tarifübersicht Herr Karim Muster Herr Karim Muster',
            'Musterallee 141 Musterallee 141',
            'Anbieter Musterenergie AG 24768 Rendsburg 24768 Rendsburg',
            'Produkt Fair Ökostrom 24 geboren am: 03.04.1978',
            'Abnehmer Privat Konto: 0105653802',
            'Tariftyp Strom Tel: +49 0176 23681009 BLZ: 21450000 (Sparkasse Muster)',
            'Mail: karim.muster@example.com IBAN: DE82214500000105653802',
            'Tarifdaten BIC: NOLADE21RDB',
            'Grundpreis 167,80 € / Jahr Belieferung',
            'Arbeitspreis 32,45 ct / kWh Auftragsnummer 1672525',
            '1 Monat Kündigungsfrist Netzbetreiber Stadtwerke Muster GmbH',
            '24 Monate E-Preisgarantie MaLo-ID 51214126166',
            '24 Monate Vertragslaufzeit Vorjahresverbrauch HT 2800 kWh / Jahr',
            'Status 1000 - Auftrag komplett erfasst, wartend auf manuelle Prüfung',
            'Lieferdatum gew. Lieferdatum schnellstmöglich',
            'Zählernummer 1EBZ0103873550',
            'Lieferdatum ist voraussichtlich bish. Kundennummer 200063867',
            'Vorversorger Stadtwerke Muster GmbH',
            'Kundennummer Unterschriftsdatum 16.08.2026',
            'Zusatzinfos',
            'Zahlung erfolgt per Bankeinzug',
        ]);

        $r = (new EnergiePortalAuftragParser)->parse($text);

        $this->assertNotNull($r);
        $p = $r['data']['person'];
        // Der Name steht doppelt in der Zeile (Anschrift + Kontoinhaber) -
        // die zweite Anrede darf nicht in den Namen laufen.
        $this->assertSame('Karim', $p['first_name']);
        $this->assertSame('Muster', $p['last_name']);
        $this->assertSame('1978-04-03', $p['birth_date']);
        // Anschrift statt Reiterleiste ("Übersicht Dokumente 1 Anfrage ...").
        $this->assertSame('Musterallee', $p['street']);
        $this->assertSame('141', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);
        $this->assertSame('karim.muster@example.com', $p['email']);
        $this->assertSame('017623681009', $p['phone']);

        // Beschriftungen mitten in der Zeile werden gefunden.
        $e = $r['data']['energie'];
        $this->assertSame('51214126166', $e['malo_id']);
        $this->assertSame('1EBZ0103873550', $e['meter_number']);
        $this->assertSame('200063867', $e['previous_customer_number']);
        $this->assertSame('Stadtwerke Muster GmbH', $e['grid_operator']);
        $this->assertSame(2800, $e['consumption_kwh']);
        $this->assertSame(32.45, $e['working_price']);
        $this->assertSame(13.98, $e['base_price']);
        // Der Tarif endet vor der Beschriftung der Nachbarspalte - frueher
        // hing die IBAN daran.
        $this->assertSame('Fair Ökostrom 24', $e['tariff']);

        // Der Anbieter endet vor der Anschrift der Nachbarspalte.
        $this->assertSame('Musterenergie AG', $r['data']['versicherung']['insurer']);

        $b = $r['data']['bank'];
        $this->assertSame('DE82214500000105653802', $b['iban']);
        $this->assertSame('NOLADE21RDB', $b['bic']);
        $this->assertSame('Karim Muster', $b['account_holder']);
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

        $r = (new EnergiePortalAuftragParser)->parse($text);

        $this->assertNotNull($r);
        $this->assertSame('Musterallee', $r['data']['person']['street']);
        $this->assertSame('141', $r['data']['person']['house_number']);
        $this->assertSame('24768', $r['data']['person']['zip']);
        $this->assertSame('51214126166', $r['data']['energie']['malo_id']);
        // Ohne erkannten Kontoinhaber-Block keine Bankdaten.
        $this->assertSame([], $r['data']['bank']);
    }

    /**
     * Am ECHTEN OCR-Lauf nachgestellt (Chromium-Replik + Tesseract): die
     * kleine Tarif-Tabelle kommt verstuemmelt an ("Produkt Fair ö 24"),
     * waehrend die grosse KOPFZEILE sauber gelesen wird. Anbieter und
     * Produkt stammen deshalb bevorzugt aus der Kopfzeile; die Sparte
     * entscheidet der Produktname, wenn die OCR "Tariftyp" verliest.
     */
    public function test_header_line_wins_over_garbled_table(): void
    {
        $text = implode("\n", [
            'Ei 1672525 - Musterenergie AG - Fair Ökostrom 24',
            'Herr Karim Muster',
            'RheinEnergie Belieferungsanschrift Anschrift des Kontoinhaber',
            'Herr Karim Muster Herr Karim Muster',
            'Tarifübersicht Musterallee 141 Musterallee 141',
            'Anbieter Musterenergie AG 24768 Rendsburg 24768 Rendsburg',
            'Produkt Fair ö 24 geboren am: 03.04.1978',
            '" air Okostrom Konto: 0105653802',
            'Tarityp Mail: karim.muster@example.com IBAN: DE82214500000105653802',
            'BIC: NOLADE21RDB',
            'Auftragsnummer 1672525',
            'MaLo-ID 51214126166',
            'Vorversorger Stadtwerke Muster GmbH',
        ]);

        $r = (new EnergiePortalAuftragParser)->parse($text);

        $this->assertNotNull($r);
        // Sauberer Produktname aus der Kopfzeile statt "Fair ö 24".
        $this->assertSame('Fair Ökostrom 24', $r['data']['energie']['tariff']);
        $this->assertSame('Musterenergie AG', $r['data']['versicherung']['insurer']);
        // Verlesene Beschriftung "Tarityp" -> Sparte aus dem Produktnamen.
        $this->assertSame('strom', $r['data']['versicherung']['sparte']);
        $this->assertSame('DE82214500000105653802', $r['data']['bank']['iban']);
        $this->assertSame('NOLADE21RDB', $r['data']['bank']['bic']);
    }

    public function test_broken_iban_is_repaired_from_account_and_bank_code(): void
    {
        // Nur die PRUEFZIFFER ist verlesen; Konto + BLZ stehen separat und
        // sauber daneben. Eine deutsche IBAN besteht genau aus diesen beiden
        // Feldern, also wird sie NACHGERECHNET statt verworfen - das ist der
        // Unterschied zwischen "nicht lesbar" und "nicht vorhanden".
        $r = (new EnergiePortalAuftragParser)->parse($this->screenshotText([
            'IBAN: DE82214500000105653802' => 'IBAN: DE83214500000105653802',
        ]));

        $this->assertNotNull($r);
        $this->assertSame('DE82214500000105653802', $r['data']['bank']['iban']);
        // Uebernommen, aber ausdruecklich als pruefbeduerftig gekennzeichnet.
        $this->assertSame('pruefen', $r['data']['feldstatus']['bank.iban']['status']);
        $this->assertStringContainsString('Kontonummer + BLZ', $r['summary']);
    }

    public function test_unreadable_iban_without_account_data_is_never_taken(): void
    {
        // Ohne zweite Quelle bleibt es beim alten, strengen Verhalten: eine
        // IBAN ohne gueltige Pruefziffer kommt NIE in die Kundenakte.
        $r = (new EnergiePortalAuftragParser)->parse($this->screenshotText([
            'IBAN: DE82214500000105653802' => 'IBAN: DE82214500000105653803',
            'Konto: 0105653802' => '',
            'BLZ: 21450000 (Sparkasse Muster)' => '',
        ]));

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('iban', $r['data']['bank']);
        $this->assertSame('pruefen', $r['data']['feldstatus']['bank.iban']['status']);
        $this->assertStringContainsString('Pruefziffer stimmt nicht', $r['summary']);
    }

    public function test_iban_contradicting_the_printed_account_is_not_taken(): void
    {
        // Gueltige IBAN, die NICHT zur separat gedruckten Konto-/BLZ-Angabe
        // passt: zwei Quellen im selben Dokument sagen Verschiedenes. Frueher
        // wurde die IBAN uebernommen und ein Hinweis angehaengt - einen
        // Hinweis ueberliest man. Jetzt wird NICHTS uebernommen und das Feld
        // steht als "widersprüchliche Angaben" im Review.
        $r = (new EnergiePortalAuftragParser)->parse($this->screenshotText([
            'BLZ: 21450000 (Sparkasse Muster)' => 'BLZ: 20050550 (Sparkasse Muster)',
        ]));

        $this->assertArrayNotHasKey('iban', $r['data']['bank']);
        $this->assertSame('widerspruch', $r['data']['feldstatus']['bank.iban']['status']);
        $this->assertStringContainsString('widersprechen sich', $r['summary']);
    }

    public function test_foreign_account_holder_is_not_taken(): void
    {
        $r = (new EnergiePortalAuftragParser)->parse($this->screenshotText([
            'Tarifübersicht                  Herr Karim Muster                        Herr Karim Muster' => 'Tarifübersicht                  Herr Karim Muster                        Frau Erika Fremd',
        ]));

        $this->assertNotNull($r);
        $this->assertSame([], $r['data']['bank']);
        $this->assertStringContainsString('Ohne Bankuebernahme', $r['summary']);
        // Die Kundendaten bleiben vollstaendig.
        $this->assertSame('Muster', $r['data']['person']['last_name']);
    }

    public function test_real_delivery_date_wins_over_estimate(): void
    {
        $r = (new EnergiePortalAuftragParser)->parse($this->screenshotText([
            'gew. Lieferdatum       schnellstmöglich' => 'gew. Lieferdatum       01.10.2026',
        ]));

        $this->assertSame('2026-10-01', $r['data']['versicherung']['start_date']);
        $this->assertArrayNotHasKey('expected_start_within_days', $r['data']['versicherung']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new EnergiePortalAuftragParser;

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // Der LichtBlick-PDF-Auftrag hat seinen eigenen Parser und traegt
        // die Portal-Ueberschriften nicht.
        $this->assertNull($parser->parse(
            "LichtBlick ÖkoStrom\nAuftragsnummer 1659475\nArbeitspreis: 33,93 Cent/kWh"
        ));
    }

    /**
     * ZWEITE Bauform desselben Portals (gemeldeter Fall 28.08.2026): ein
     * NEUEINZUG statt eines Anbieterwechsels. Das aendert drei Dinge -
     * der Lieferbeginn heisst "Neueinzug zum" statt "gew. Lieferdatum", es
     * gibt keinen Vorversorger, und der Anbietername steht nur in der
     * Kopfzeile. Genau daran scheiterte der gemeldete Auftrag.
     */
    private function neueinzugText(array $ersetzungen = []): string
    {
        $text = implode("\n", [
            '1687519 - Nullenergie AG - NEO P0',
            'Frau Amira Beispiel',
            '',
            'Übersicht     Dokumente 1     Anfrage zum Vertrag',
            '',
            'Nullenergie                     Belieferungsanschrift                    Anschrift des Kontoinhaber',
            '',
            'Tarifübersicht                  Frau Amira Beispiel                      Frau Amira Beispiel',
            '                                Musterring 12                            Musterring 12',
            'Anbieter    Nullenergie AG      44787 Musterstadt                        44787 Musterstadt',
            '                                geboren am: 27.04.1985',
            'Produkt       NEO P0',
            '                                Tel: +49 015563 045916                   Konto: 0105653802',
            'Abnehmer      Privat            Mail: amira.beispiel@example.com         BLZ: 21450000 (Sparkasse Muster)',
            '                                                                         IBAN: DE82214500000105653802',
            'Tariftyp      Strom                                                      BIC: NOLADE21RDB',
            '',
            'Tarifdaten                      Belieferung',
            '',
            'Grundpreis    128,40 € / Jahr   Auftragsnummer         1687519',
            '',
            'Arbeitspreis  28,57 ct / kWh    Netzbetreiber          Musterstadt Netz GmbH',
            '',
            '4 Wochen Kündigungsfrist        Vorjahresverbrauch HT  2200 kWh / Jahr',
            '',
            '12 Monate E-Preisgarantie       Status                 1000 - Auftrag komplett erfasst',
            '',
            '12 Monate Vertragslaufzeit      Neueinzug zum          01.09.2026',
            '',
            'Lieferdatum                     Zählernummer           1EBZ0103716819',
            '',
            'Lieferdatum ist voraussichtlich Unterschriftsdatum     28.08.2026',
            '',
            '                                Zusatzinfos',
            '',
            '                                Zahlung                erfolgt per Bankeinzug',
        ]);

        return str_replace(array_keys($ersetzungen), array_values($ersetzungen), $text);
    }

    public function test_neueinzug_liefert_den_vertragsbeginn(): void
    {
        $r = (new EnergiePortalAuftragParser)->parse($this->neueinzugText());

        $this->assertNotNull($r);
        // Der Beginn stand im Dokument - nur unter einer anderen Beschriftung.
        $this->assertSame('2026-09-01', $r['data']['versicherung']['start_date']);
        $this->assertStringContainsString('Neueinzug (Neuanschluss, kein Anbieterwechsel)', $r['summary']);
        // Ohne Vorversorger wird KEIN Beginn geschaetzt - hier gibt es einen
        // echten, also braucht es die 20-Tage-Regel gar nicht.
        $this->assertArrayNotHasKey('expected_start_within_days', $r['data']['versicherung']);

        // Und die uebrigen Kernangaben stehen vollstaendig da.
        $this->assertSame('amira.beispiel@example.com', $r['data']['person']['email']);
        $this->assertSame('015563045916', $r['data']['person']['phone']);
        $this->assertSame('DE82214500000105653802', $r['data']['bank']['iban']);
        $this->assertSame('1687519', $r['data']['versicherung']['reference_number']);
        $this->assertSame('1EBZ0103716819', $r['data']['energie']['meter_number']);
    }

    /**
     * Dieselbe Beschriftung in weiteren Schreibweisen - die Erkennung darf
     * nicht an EINEM Wort haengen, sonst liest sie den naechsten Anbieter
     * nicht mehr.
     */
    public function test_lieferbeginn_wird_unter_mehreren_beschriftungen_gefunden(): void
    {
        foreach (['Lieferbeginn      ', 'Belieferungsbeginn', 'Vertragsbeginn    ',
            'Einzugsdatum      ', 'Einzug zum        '] as $label) {
            $r = (new EnergiePortalAuftragParser)->parse(
                $this->neueinzugText(['Neueinzug zum ' => $label])
            );
            $this->assertSame('2026-09-01', $r['data']['versicherung']['start_date'] ?? null,
                'Beschriftung "'.trim($label).'" wurde nicht gelesen.');
        }
    }

    /**
     * Das "@" ist auf einem Screenshot das fehleranfaelligste Zeichen -
     * genau daran scheiterte die E-Mail im gemeldeten Auftrag.
     */
    public function test_verlesenes_at_zeichen_verhindert_die_email_nicht_mehr(): void
    {
        foreach (['©', '®', '@®', '(at)'] as $verlesen) {
            $r = (new EnergiePortalAuftragParser)->parse($this->neueinzugText([
                'amira.beispiel@example.com' => 'amira.beispiel'.$verlesen.'example.com',
            ]));
            $this->assertSame('amira.beispiel@example.com', $r['data']['person']['email'] ?? null,
                'Schreibweise "'.$verlesen.'" wurde nicht repariert.');
            // Repariert heisst NICHT "sicher" - der Mitarbeiter soll hinsehen.
            $this->assertSame('pruefen', $r['data']['feldstatus']['person.email']['status']);
        }
    }

    /** Jedes Feld traegt seinen Zustand - der Mitarbeiter prueft nur die unsicheren. */
    public function test_feldstatus_benennt_gelesene_und_fehlende_angaben(): void
    {
        $r = (new EnergiePortalAuftragParser)->parse($this->neueinzugText([
            'Tel: +49 015563 045916' => '                      ',
        ]));

        $status = $r['data']['feldstatus'];
        $this->assertSame('sicher', $status['person.birth_date']['status']);
        $this->assertSame('sicher', $status['versicherung.start_date']['status']);
        $this->assertSame('sicher', $status['energie.meter_number']['status']);
        // Nicht im Dokument gefunden -> "fehlt". Es wird nichts geraten.
        $this->assertSame('fehlt', $status['person.phone']['status']);
        $this->assertSame('fehlt', $status['energie.malo_id']['status']);
        $this->assertArrayNotHasKey('phone', $r['data']['person']);
    }

    /**
     * Die E-Mail kann an ZWEI Stellen brechen, nicht nur an einer: am WERT
     * (das "@") und an der BESCHRIFTUNG. "Mail:" steht im Portal
     * unterstrichen, und ein Unterstrich verschmilzt beim Erkennen gern mit
     * dem Wort. Bricht die Beschriftung, half die Wert-Reparatur nicht - die
     * Suche fand ja gar keine Stelle zum Reparieren.
     */
    public function test_email_wird_auch_ohne_lesbare_beschriftung_gefunden(): void
    {
        foreach (['Maii:', 'Mall:', 'MaiI:', ''] as $verlesen) {
            $r = (new EnergiePortalAuftragParser)->parse($this->neueinzugText([
                'Mail: amira.beispiel@example.com' => $verlesen.' amira.beispiel@example.com',
            ]));
            $this->assertSame('amira.beispiel@example.com', $r['data']['person']['email'] ?? null,
                'Beschriftung "'.$verlesen.'" liess die Adresse verschwinden.');
            // Ohne Beschriftung ist die Adresse plausibel, nicht belegt.
            $this->assertSame('pruefen', $r['data']['feldstatus']['person.email']['status']);
        }
    }

    /** Beide Bruchstellen zugleich: Beschriftung UND "@" verlesen. */
    public function test_email_wird_auch_bei_zwei_lesefehlern_gefunden(): void
    {
        foreach (['©', '®', '€', '°'] as $statt) {
            $r = (new EnergiePortalAuftragParser)->parse($this->neueinzugText([
                'Mail: amira.beispiel@example.com' => 'Maii: amira.beispiel'.$statt.'example.com',
            ]));
            $this->assertSame('amira.beispiel@example.com', $r['data']['person']['email'] ?? null,
                '"'.$statt.'" statt "@" liess die Adresse verschwinden.');
        }
    }

    /**
     * Die Rueckfallebene darf NIE eine fremde Adresse zur Kundenadresse
     * machen - sonst stuende der Kundenservice des Versorgers als Kontakt in
     * der Kundenakte und bekaeme unsere Post.
     */
    public function test_fremde_adressen_werden_nie_zur_kundenadresse(): void
    {
        foreach ([
            'service@fremdanbieter.de',      // Sammelpostfach
            'info@fremdanbieter.de',         // Sammelpostfach
            'kundenservice@fremdanbieter.de', // Sammelpostfach
            'abrechnung@nullenergie.de',     // Domain = der Anbieter dieses Auftrags
            'berater@dienstly24.de',         // unser eigenes Haus
        ] as $fremd) {
            $r = (new EnergiePortalAuftragParser)->parse($this->neueinzugText([
                'Mail: amira.beispiel@example.com' => $fremd,
            ]));
            $this->assertArrayNotHasKey('email', $r['data']['person'],
                '"'.$fremd.'" wurde faelschlich als Kundenadresse uebernommen.');
            $this->assertSame('fehlt', $r['data']['feldstatus']['person.email']['status']);
        }
    }

    /** Steht die Adresse beschriftet da, gilt sie als belegt - nicht als Fund. */
    public function test_beschriftete_adresse_bleibt_sicher(): void
    {
        $r = (new EnergiePortalAuftragParser)->parse($this->neueinzugText());

        $this->assertSame('amira.beispiel@example.com', $r['data']['person']['email']);
        $this->assertSame('sicher', $r['data']['feldstatus']['person.email']['status']);
    }
}
