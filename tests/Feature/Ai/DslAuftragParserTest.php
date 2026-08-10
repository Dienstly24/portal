<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\DslAuftragParser;
use Tests\TestCase;

/**
 * Parser fuer die DSL-/Internet-Auftragsbestaetigung (z.B. CHECK24
 * "Ihr DSL Anschluss" oder Kabel-Auftrag fuer Vodafone Kabel Deutschland):
 * liest Kundendaten + Tarif + ALLE Preisdetails gratis aus dem Auftrag
 * (Betreiber-Vorgabe 10.08.2026).
 */
class DslAuftragParserTest extends TestCase
{
    private function auftragText(): string
    {
        return implode("\n", [
            'Ihr DSL Anschluss',
            'Ihre Kundendaten',
            'Adresse           Abdulsattar Mousa',
            '                  Kolberger Str. 13',
            '                  24768 Rendsburg',
            'Handynummer für Rückfragen    0152 13973931',
            'E-Mail            abdalstarbkur@icloud.com',
            'Geburtsdatum      23.02.1979',
            'IBAN              DE4622**********2425',
            'Anschlusstermin   schnellstmöglich',
            'Anbieter          Telekom',
            'Tarif             Magenta Zuhause L',
            'Max. Download     100 MBit/s',
            'Max. Upload       40,0 MBit/s',
            'Mindestlaufzeit   24 Monate',
            'Kündigungsfrist   1 Monat',
            'Grundgebühr Monat 1 - 3                    9,95 €',
            'Grundgebühr Monat 4 - 24                  48,95 €',
            'Telekom Speedport Smart 4 Monat 1 - 6      0,00 €',
            'Telekom Speedport Smart 4 Monat 7 - 24     6,95 €',
            'Versandkosten                              6,95 €',
            'CHECK24.net Cashback                    - 155,00 €',
            'Online-Vorteil                          - 100,00 €',
            'Routergutschrift                        - 100,00 €',
            'Durchschnitt pro Monat   34,79 €',
            'Auftragsnummer: 17485672',
        ]);
    }

    /**
     * Kabel-Auftrag fuer Vodafone Kabel Deutschland (CHECK24-Uebersicht mit
     * Preisuebersicht rechts): preisvariabler Tarif, einmalige Kosten,
     * Router "Vodafone Station", Cashback und Mindestlaufzeit.
     */
    private function kabelAuftragText(): string
    {
        return implode("\n", [
            'Ihre Kundendaten',
            'Adresse           Dunya Al Obaidi',
            '                  Grafenstr. 19',
            '                  24768 Rendsburg',
            'Handynummer für Rückfragen    0176 75722643',
            'E-Mail            Durp930@gmail.com',
            'Geburtsdatum      15.09.2006',
            'IBAN              DE6821************7192',
            'Kreditinstitut    Sparkasse Mittelholstein',
            'Zahlungsart       Bankeinzug',
            'Ihre Anschlussdaten',
            'Anschlusstermin   schnellstmöglich',
            'Ihr Tarif',
            'Anbieter          Vodafone Kabel Deutschland',
            'Tarif             Young GigaZuhause 300 Kabel',
            'Max. Download     300 MBit/s',
            'Max. Upload       75,0 MBit/s',
            'Mindestlaufzeit   24 Monate',
            'Kündigungsfrist   1 Monat',
            'Verlängerung      1 Monat',
            'Preisübersicht',
            'Tarifkosten                     einmalig   monatlich',
            'Grundgebühr Monat 1 - 9                    19,99 €',
            'Grundgebühr Monat 10 - 24                  49,99 €',
            'Bereitstellungsgebühr           49,99 €',
            'Versandkosten                    9,99 €',
            'Basis Kabelfernsehen (TV Connect)           0,00 €',
            'Hardware & Optionen',
            'Vodafone Station                            4,99 €',
            'Festnetz- und Mobilfunk-Flatrate            0,00 €',
            'Vorteile',
            'CHECK24.net Cashback                    - 430,00 €',
            'Durchschnitt pro Monat   28,31 €',
            'Mtl. Kosten ab dem 25. Monat               54,98 €',
            'Kosten ab dem 25. Monat im Detail       monatlich',
            'Grundgebühr                                49,99 €',
            'Vodafone Station                            4,99 €',
        ]);
    }

    public function test_parses_dsl_auftrag(): void
    {
        $r = (new DslAuftragParser())->parse($this->auftragText());
        $this->assertNotNull($r);
        $this->assertSame('internetvertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Abdulsattar', $p['first_name']);
        $this->assertSame('Mousa', $p['last_name']);
        $this->assertSame('1979-02-23', $p['birth_date']);
        $this->assertSame('Kolberger Str.', $p['street']);
        $this->assertSame('13', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);
        $this->assertSame('015213973931', $p['phone']);
        $this->assertSame('abdalstarbkur@icloud.com', $p['email']);

        $v = $r['data']['versicherung'];
        $this->assertSame('internet', $v['sparte']);
        $this->assertSame('Telekom', $v['insurer']);
        $this->assertSame('Magenta Zuhause L', $v['tariff']);
        $this->assertSame('17485672', $v['contract_number']);
        $this->assertSame(34.79, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);

        // Internet-Detaildaten (preisvariabler Tarif, Router, Bonus/Gutschein,
        // einmalige Kosten, Mindestlaufzeit).
        $i = $r['data']['internet'];
        $this->assertSame('Magenta Zuhause L', $i['tariff']);
        $this->assertSame('100 MBit/s', $i['speed']);
        $this->assertSame('40,0 MBit/s', $i['upload_speed']);
        $this->assertSame(9.95, $i['price_initial']);
        $this->assertSame(3, $i['price_initial_months']);
        $this->assertSame(48.95, $i['price_regular']);
        $this->assertTrue($i['has_router']);
        $this->assertSame('Telekom Speedport Smart 4', $i['router_name']);
        $this->assertSame(6.95, $i['router_price']);
        $this->assertSame(155.00, $i['bonus_amount']);
        $this->assertSame(100.00, $i['voucher_amount']);
        $this->assertSame(6.95, $i['shipping_fee']);
        $this->assertSame(24, $i['min_duration_months']);
        // Keine Bereitstellungsgebuehr im Dokument -> Feld bleibt leer.
        $this->assertArrayNotHasKey('setup_fee', $i);

        // Maskierte IBAN wird NICHT als Bankverbindung uebernommen.
        $this->assertArrayNotHasKey('iban', $r['data']['bank']);
    }

    public function test_parses_vodafone_kabel_auftrag_vollstaendig(): void
    {
        $r = (new DslAuftragParser())->parse($this->kabelAuftragText());
        $this->assertNotNull($r);
        $this->assertSame('internetvertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Dunya', $p['first_name']);
        $this->assertSame('Al Obaidi', $p['last_name']);
        $this->assertSame('2006-09-15', $p['birth_date']);
        $this->assertSame('Grafenstr.', $p['street']);
        $this->assertSame('19', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);
        $this->assertSame('017675722643', $p['phone']);
        $this->assertSame('durp930@gmail.com', $p['email']);

        $v = $r['data']['versicherung'];
        $this->assertSame('internet', $v['sparte']);
        // Anbieter ist die FIRMA - nie der Tarifname (die Ueberschrift
        // "Ihr Tarif" darf den Tarif-Ausdruck nicht verwirren).
        $this->assertSame('Vodafone Kabel Deutschland', $v['insurer']);
        $this->assertSame('Young GigaZuhause 300 Kabel', $v['tariff']);
        $this->assertSame(\App\Models\Contract::STAGE_ANTRAG, $v['document_stage']);
        $this->assertSame(28.31, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
        // Kein Auftragsnummern-Feld auf der Uebersicht, "schnellstmoeglich"
        // wird nie als Beginn geraten.
        $this->assertArrayNotHasKey('contract_number', $v);
        $this->assertArrayNotHasKey('start_date', $v);

        $i = $r['data']['internet'];
        $this->assertSame('Young GigaZuhause 300 Kabel', $i['tariff']);
        $this->assertSame('300 MBit/s', $i['speed']);
        $this->assertSame('75,0 MBit/s', $i['upload_speed']);
        $this->assertSame(19.99, $i['price_initial']);
        $this->assertSame(9, $i['price_initial_months']);
        $this->assertSame(49.99, $i['price_regular']);
        $this->assertSame(49.99, $i['setup_fee']);
        $this->assertSame(9.99, $i['shipping_fee']);
        $this->assertSame(24, $i['min_duration_months']);
        $this->assertTrue($i['has_router']);
        $this->assertSame('Vodafone Station', $i['router_name']);
        $this->assertSame(4.99, $i['router_price']);
        $this->assertSame(430.00, $i['bonus_amount']);
        $this->assertArrayNotHasKey('voucher_amount', $i);

        // Maskierte IBAN / Kreditinstitut werden NICHT uebernommen.
        $this->assertArrayNotHasKey('iban', $r['data']['bank']);

        // Zusammenfassung nennt ALLE Details, auch die ohne eigenes Feld
        // (Kuendigungsfrist, Kosten nach der Mindestlaufzeit, Anschlusstermin,
        // inklusive 0,00-Optionen).
        $this->assertStringContainsString('Vodafone Kabel Deutschland', $r['summary']);
        $this->assertStringContainsString('Grundgebuehr 19,99 EUR/Monat (Monat 1-9), danach 49,99 EUR/Monat', $r['summary']);
        $this->assertStringContainsString('einmalig: Bereitstellung 49,99 EUR + Versand 9,99 EUR', $r['summary']);
        $this->assertStringContainsString('Router Vodafone Station 4,99 EUR/Monat', $r['summary']);
        $this->assertStringContainsString('Bonus/Cashback 430,00 EUR', $r['summary']);
        $this->assertStringContainsString('Mindestlaufzeit 24 Monate (Kuendigungsfrist 1 Monat, Verlaengerung 1 Monat)', $r['summary']);
        $this->assertStringContainsString('ab Monat 25: 54,98 EUR/Monat', $r['summary']);
        $this->assertStringContainsString('Durchschnitt 28,31 EUR/Monat', $r['summary']);
        $this->assertStringContainsString('Basis Kabelfernsehen (TV Connect)', $r['summary']);
        $this->assertStringContainsString('Anschlusstermin schnellstmöglich', $r['summary']);
    }

    /**
     * ECHTES Spalten-OCR eines Screenshots (mit Chromium + Tesseract PSM 3
     * nachgestellt, Lehre 10.08.2026): Tesseract liest die CHECK24-Karten
     * als SPALTEN-Bloecke - erst alle Beschriftungen, dann alle Werte, dann
     * die Betraege. Kein Label trifft seinen Wert auf einer Zeile; vor dem
     * Fix blieben nur Name und Adresse uebrig.
     */
    private function kabelAuftragSpaltenOcrText(): string
    {
        return implode("\n", [
            'Ihre Kundendaten', '',
            'Adresse', '',
            'Handynummer für Rückfragen',
            'E-Mail', '',
            'Geburtsdatum', '',
            'IBAN', '',
            'Kreditinstitut', '',
            'Zahlungsart', '',
            'Ihre Anschlussdaten', '',
            'Anschlusstermin', '',
            'Ihr Tarif', '',
            'Anbieter', '',
            'Tarif', '',
            'Max. Download',
            'Max. Upload',
            'Mindestlaufzeit',
            'Kündigungsfrist', '',
            'Verlängerung', '',
            'Dunya Al Obaidi',
            'Grafenstr. 19',
            '24768 Rendsburg', '',
            '0176 75722643',
            'Durp930@gmail.com',
            '15.09.2006', '',
            'DE6821* 7192',
            'Sparkasse Mittelholstein', '',
            'Bankeinzug', '',
            'schnellstmöglich', '',
            'Vodafone Kabel Deutschland',
            'Young GigaZuhause 300 Kabel', '',
            '300 MBit/s',
            '75,0 MBit/s',
            '24 Monate',
            '1 Monat',
            '1 Monat', '',
            'ändern', '',
            'ändern', '',
            'Preisübersicht', '',
            'Tarifkosten', '',
            'Grundgebühr Monat 1-9',
            'Grundgebühr Monat 10 - 24',
            'Bereitstellungsgebühr',
            'Versandkosten', '',
            'Basis Kabelfernsehen (TV Connect)', '',
            'Hardware & Optionen',
            'Vodafone Station', '',
            'Festnetz- und Mobilfunk-Flatrate', '',
            'Vorteile',
            'CHECK24.net Cashback', '',
            'Durchschnitt pro Monat', '',
            'Wie errechnet sich der Durchschnitt pro Monat?', '',
            'einmalig monatlich', '',
            '19,99 €',
            '49,99 €',
            '49,99 €',
            '9,99€',
            '0,00€', '',
            '4,99€',
            '0,00€', '',
            '- 430,00 €', '',
            '28,31€', '',
            'Wir berücksichtigen alle innerhalb der ersten 24 Monate anfallenden Kosten und',
            'Vergünstigungen. Daraus ermitteln wir zur besseren Vergleichbarkeit den rechnerischen', '',
            'Durchschnittspreis pro Monat.', '',
            'Preise inkl. MwSt. Alle Angaben ohne Gewähr.', '',
            'Mtl. Kosten ab dem 25. Monat',
            'Kosten ab dem 25. Monat im Detail', '',
            'Grundgebühr',
            'Vodafone Station', '',
            '54,98 €', '',
            'monatlich', '',
            '49,99 €',
            '4,99€',
        ]);
    }

    public function test_parses_spalten_ocr_eines_screenshots_vollstaendig(): void
    {
        $r = (new DslAuftragParser())->parse($this->kabelAuftragSpaltenOcrText());
        $this->assertNotNull($r);

        $p = $r['data']['person'];
        $this->assertSame('Dunya', $p['first_name']);
        $this->assertSame('Al Obaidi', $p['last_name']);
        $this->assertSame('2006-09-15', $p['birth_date']);
        $this->assertSame('017675722643', $p['phone']);
        $this->assertSame('durp930@gmail.com', $p['email']);
        $this->assertSame('24768', $p['zip']);

        $v = $r['data']['versicherung'];
        $this->assertSame('Vodafone Kabel Deutschland', $v['insurer']);
        $this->assertSame('Young GigaZuhause 300 Kabel', $v['tariff']);
        $this->assertSame(28.31, $v['premium_amount']);
        // Das blosse Label "IBAN" o.ae. darf nie zur Auftragsnummer werden.
        $this->assertArrayNotHasKey('contract_number', $v);
        $this->assertArrayNotHasKey('start_date', $v);

        $i = $r['data']['internet'];
        $this->assertSame('300 MBit/s', $i['speed']);
        $this->assertSame('75,0 MBit/s', $i['upload_speed']);
        $this->assertSame(19.99, $i['price_initial']);
        $this->assertSame(9, $i['price_initial_months']);
        $this->assertSame(49.99, $i['price_regular']);
        $this->assertSame(49.99, $i['setup_fee']);
        $this->assertSame(9.99, $i['shipping_fee']);
        $this->assertSame(24, $i['min_duration_months']);
        $this->assertTrue($i['has_router']);
        $this->assertSame('Vodafone Station', $i['router_name']);
        $this->assertSame(4.99, $i['router_price']);
        $this->assertSame(430.00, $i['bonus_amount']);

        $this->assertStringContainsString('Mindestlaufzeit 24 Monate (Kuendigungsfrist 1 Monat, Verlaengerung 1 Monat)', $r['summary']);
        $this->assertStringContainsString('ab Monat 25: 54,98 EUR/Monat', $r['summary']);
        $this->assertStringContainsString('Anschlusstermin schnellstmöglich', $r['summary']);
    }

    /**
     * Spalten-OCR OHNE rekonstruierbare Paare (nur die Kundendaten-Karte
     * lesbar): der Parser darf NICHT mit "nur Name und Adresse" gewinnen -
     * null laesst die Analyse normal weiterlaufen (KI-Eskalation liest das
     * Bild vollstaendig).
     */
    public function test_gibt_auf_statt_fast_leer_zu_gewinnen(): void
    {
        $text = implode("\n", [
            'Ihre Kundendaten',
            'Adresse',
            'Geburtsdatum',
            'Anbieter',
            'Mindestlaufzeit',
            'Max. Download in MBit/s und Internet', // Trigger-Woerter vorhanden
            'Dunya Al Obaidi',
            'Grafenstr. 19',
            '24768 Rendsburg',
        ]);
        $this->assertNull((new DslAuftragParser())->parse($text));
    }

    public function test_ignores_non_dsl_documents(): void
    {
        $this->assertNull((new DslAuftragParser())->parse('Irgendein Dokument ohne Tarif und Anbieter.'));
        // Eine Kfz-Police (Anbieter, aber kein Internet-Marker) nicht anfassen.
        $this->assertNull((new DslAuftragParser())->parse("Anbieter: ADAC\nMindestlaufzeit 12 Monate\nKfz-Haftpflicht"));
    }

    /**
     * REGRESSION (Audit 10.08.2026): Ein STROM-/GAS-Auftrag enthaelt regulaer
     * "Anbieterwechsel", "Netzanschluss" und eine "Mindestlaufzeit" - das darf
     * den DSL-Parser NICHT ausloesen (frueher wurde der Energie-Auftrag
     * faelschlich als Internet-Vertrag mit leeren Energiedaten beansprucht,
     * sodass sich kein Energievertrag mehr anlegen liess).
     */
    public function test_does_not_claim_energy_orders(): void
    {
        $stromAuftrag = implode("\n", [
            'Stromliefervertrag - Auftragsbestätigung',
            'Anbieter          Stadtwerke Musterstadt',
            'Ihr Anbieterwechsel wird zum nächstmöglichen Termin durchgeführt.',
            'Netzanschluss / Netzbetreiber: Netze BW',
            'Marktlokation (MaLo-ID): 51238973456',
            'Zählernummer      1 LOG00 9228 3078',
            'Jahresverbrauch   3.500 kWh',
            'Arbeitspreis      28,50 ct/kWh',
            'Grundpreis        12,90 EUR/Monat',
            'Mindestlaufzeit   12 Monate',
        ]);

        $this->assertNull(
            (new DslAuftragParser())->parse($stromAuftrag),
            'Ein Strom-/Gas-Auftrag darf nicht als DSL-/Internet-Vertrag gelesen werden.'
        );
    }

    /**
     * Der Datenteil eines Formulars steht auf Seite 1; die AGB-Folgeseiten
     * eines Energie-Auftrags (mit "Anbieterwechsel"/"Netzanschluss"/
     * "Mindestlaufzeit") duerfen den DSL-Parser nicht mehr ausloesen.
     */
    public function test_ignores_agb_noise_on_later_pages(): void
    {
        $seite1 = "Stromliefervertrag der Stadtwerke\nKunde: Max Mustermann\nMarktlokation 51238973456\nJahresverbrauch 3500 kWh";
        $agb = "Allgemeine Geschäftsbedingungen\nMindestlaufzeit und Anbieterwechsel: ... Der Netzanschluss ...";
        $this->assertNull((new DslAuftragParser())->parse($seite1 . "\f" . $agb));
    }
}
