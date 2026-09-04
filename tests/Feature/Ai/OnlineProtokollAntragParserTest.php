<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\OnlineProtokollAntragParser;
use Tests\TestCase;

/**
 * Antrag aus dem Online-Vergleichsportal des Maklerbundes (Mr-Money /
 * www.online-protokoll.de), z.B. Rechtsschutzversicherung. Synthetische
 * Daten, gleicher Aufbau wie das Original (Beschriftung rechtsbuendig).
 */
class OnlineProtokollAntragParserTest extends TestCase
{
    private function antragText(array $ersetzungen = []): string
    {
        $text = implode("\n", [
            'Antrag',
            'Rechtsschutzversicherung',
            '',
            ' Gewählter Tarif',
            '',
            '                                         Anbieter    BavariaDirekt-OERAG',
            '',
            '                                             Tarif   BavariaDirekt-OERAG-2024',
            '',
            '                                         Tarif-Nr.   213',
            '',
            ' Vermittler',
            '',
            '                                            Name     Muster Makler-Bund GmbH',
            '',
            '                                        Anschrift    Schillerstr. 3, 09366 Stollberg',
            '',
            '                                           E-Mail    post@makler-bund.de',
            '',
            ' Versicherungsnehmer',
            '',
            '              Gewünschter Versicherungsbeginn        11.08.2026',
            '                                                     Laufzeit 1 Jahr mit automatischer Verlängerung',
            '',
            '                                    Anrede, Titel    Herr',
            '',
            '                                        Vorname      Karim',
            '',
            // Tipp-Schreibweise klein - wird normalisiert.
            '                                      Nachname       muster',
            '',
            '                                 Postleitzahl, Ort   56567      Neuwied',
            '',
            '                                 Straße, Hausnr.     Apostelstr.                               55',
            '',
            '                                      Telefon-Nr.    017680212184',
            '',
            '                                  E-Mail-Adresse     karim.muster@example.com',
            '',
            '          Genaue derzeitige Berufsbezeichnung        Mitarbeiter',
            '',
            '                                   Geburtsdatum      30.01.2001',
            '',
            '                             Staatsangehörigkeit     Deutschland',
            '                                 Familienstand     ledig',
            '',
            'Zahlungsbedingungen',
            '',
            '                                Zahlungsweise      monatlich',
            '',
            '                                   Zahlungsart     Bankeinzug',
            'SEPA Lastschriftmandat',
            '                                           IBAN    DE02574501200131078784',
            '',
            '                            Kreditinstitut Name    Sparkasse Neuwied',
            '',
            '                                                        Abweichender Kontoinhaber',
            '',
            'Antragsdaten',
            '',
            '                                        Laufzeit   1 Jahr',
            '',
            '                                         Risiko    Privat- und Berufs, Verkehrs, Eigentum und Miet RS',
            '',
            '                         Versicherungssumme        unbegrenzt',
            '                               Selbstbeteiligung     300/150 EUR',
            '',
            '                               Spezial Straf RS    ja',
            '                           Erw. Internet-Schutz    ja',
            '',
            '                             Netto Jahresbeitrag     231,15 EUR',
            '                       Jahresbeitrag inkl. Steuer    275,07 EUR',
            '                       Beitrag gemäß Zahlweise       26,62 EUR',
            '',
            'Beratungsdokumentation',
            'Angaben des Kunden zu seinem Versicherungsbedarf und seinen Wünschen',
            '',
            '                                                   Privat-RS           Ja',
            '',
            '                                                  Berufs-RS            Ja',
            '',
            '                  Verkehrs-RS Familie (für alle KFZ)                   Ja',
            '',
            '                            Eigentums- und Mieter-RS                   Ja',
            '',
            '                        VERMIETETE Wohneinheiten                       nein',
            '',
            '                           Erweiterter Internet-Schutz                 nein',
            '             Gewünschter Versicherungsbeginn          schnellstmöglich',
            '',
            // Spaetere Filterkriterien gehoeren NICHT zu den Bausteinen.
            '                      Rechtsschutz für Unterhalt      Nein',
            '',
            '         (19165482-O/199/125) Datum: 09.08.2026, 16:36 Uhr     Unterschrift: ____',
        ]);

        return str_replace(array_keys($ersetzungen), array_values($ersetzungen), $text);
    }

    public function test_reads_rechtsschutz_antrag(): void
    {
        $r = (new OnlineProtokollAntragParser)->parse($this->antragText());

        $this->assertNotNull($r);
        $this->assertSame('versicherungsvertrag', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Karim', $p['first_name']);
        // Klein getippter Nachname wird normalisiert.
        $this->assertSame('Muster', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('2001-01-30', $p['birth_date']);
        $this->assertSame('Apostelstr.', $p['street']);
        $this->assertSame('55', $p['house_number']);
        $this->assertSame('56567', $p['zip']);
        $this->assertSame('Neuwied', $p['city']);
        $this->assertSame('karim.muster@example.com', $p['email']);
        $this->assertSame('017680212184', $p['phone']);
        $this->assertSame('ledig', $p['marital_status']);
        $this->assertSame('Mitarbeiter', $p['occupation']);

        $v = $r['data']['versicherung'];
        $this->assertSame('BavariaDirekt-OERAG', $v['insurer']);
        $this->assertSame('BavariaDirekt-OERAG-2024', $v['tariff']);
        $this->assertSame('rechtsschutz', $v['sparte']);
        // Datum aus dem VN-Block - "schnellstmoeglich" (Beratungsdoku) ist
        // KEIN Datum und wird nie geraten.
        $this->assertSame('2026-08-11', $v['start_date']);
        $this->assertSame(26.62, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
        // Ein Antrag traegt KEINE Vertragsnummer - die bringt erst die Police.
        $this->assertSame('antrag', $v['document_stage']);
        $this->assertArrayNotHasKey('contract_number', $v);
        $this->assertStringContainsString('19165482-O/199/125', $r['summary']);
        $this->assertStringContainsString('keine Vertragsnummer', $r['summary']);
        $this->assertStringContainsString('275,07 EUR', $r['summary']);

        // Kein abweichender Kontoinhaber eingetragen -> IBAN des Antragstellers.
        $this->assertSame('DE02574501200131078784', $r['data']['bank']['iban']);
        $this->assertSame('Karim Muster', $r['data']['bank']['account_holder']);

        // Deckungs-Bausteine: auf einen Blick, ob der Schutz umfassend ist
        // (4 von 6 gewuenscht) - das Filterkriterium "Rechtsschutz fuer
        // Unterhalt" nach dem Block gehoert NICHT dazu.
        $this->assertStringContainsString('Gewuenschte Bausteine (4 von 6): Privat-RS, Berufs-RS, Verkehrs-RS Familie (für alle KFZ), Eigentums- und Mieter-RS', $r['summary']);
        $this->assertStringContainsString('NICHT gewuenscht: VERMIETETE Wohneinheiten, Erweiterter Internet-Schutz', $r['summary']);
        $this->assertStringNotContainsString('Unterhalt', $r['summary']);
        // Die tatsaechlich beantragten Zusatz-Bausteine aus den Antragsdaten.
        $this->assertStringContainsString('Laut Antragsdaten: Spezial Straf RS ja, Erw. Internet-Schutz ja', $r['summary']);
    }

    public function test_foreign_account_holder_blocks_bank(): void
    {
        $r = (new OnlineProtokollAntragParser)->parse($this->antragText([
            '                                                        Abweichender Kontoinhaber' => '                                                        Abweichender Kontoinhaber: Max Fremd',
        ]));

        $this->assertNotNull($r);
        $this->assertSame([], $r['data']['bank']);
        $this->assertStringContainsString('Ohne Bankuebernahme', $r['summary']);
    }

    public function test_broker_email_is_never_taken(): void
    {
        // Ohne Kunden-E-Mail bleibt das Feld leer - die Vermittler-Adresse
        // (post@makler-bund.de) wird NIE uebernommen.
        $r = (new OnlineProtokollAntragParser)->parse($this->antragText([
            '                                  E-Mail-Adresse     karim.muster@example.com' => '',
        ]));

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('email', $r['data']['person']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new OnlineProtokollAntragParser;

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // Der NAFI-Kfz-Antrag hat einen eigenen Parser und keinen
        // "Gewaehlter Tarif"-Block mit Anbieter-Label.
        $this->assertNull($parser->parse("Antrag Kraftfahrtversicherung\nVersicherungsnehmer: Karim Muster"));
    }
}
