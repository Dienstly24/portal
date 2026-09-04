<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\KontaktSepaDatenParser;
use Tests\TestCase;

/**
 * Kontakt-/SEPA-Daten-Ansicht eines Antragsportals (Screenshot der Seiten
 * "Kundendaten" + "SEPA-Daten" einer gewerblichen Haftpflicht-Strecke):
 * beschriftete Felder, Werte rechts. Synthetische Daten, gleiche Struktur wie
 * das Original.
 */
class KontaktSepaDatenParserTest extends TestCase
{
    private function seiteText(string $kontoinhaber = 'Versicherungsnehmer', string $inhaberName = 'Saleh Muster Abdullah'): string
    {
        return implode("\n", [
            'Unternehmensname & Rechtsform:          Saleh Muster Abdullah',
            'Vertragsansprechpartner:                Herr Saleh Muster Abdullah',
            'E-Mail:                                 saleh.muster@example.com',
            'Festnetznummer:                         +491781117036',
            'Straße & Hausnummer:                    Mastbrooker Weg 15',
            'PLZ und Ort:                            24768 Rendsburg',
            '',
            'SEPA-Daten',
            '',
            'Für Frachtführerhaftpflicht ist Lastschrift als Zahlungsweise ausgewählt.',
            '',
            'Kontoinhaber:                           '.$kontoinhaber,
            'Name des Kontoinhabers:                 '.$inhaberName,
            'IBAN:                                   DE08300209005390827311',
            'BIC:                                    CMCIDEDDXXX',
            'Bank:                                   TARGOBANK',
        ]);
    }

    public function test_reads_contact_and_sepa_data(): void
    {
        $r = (new KontaktSepaDatenParser)->parse($this->seiteText());

        $this->assertNotNull($r);
        $this->assertSame('kontaktdaten', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Saleh', $p['first_name']);
        $this->assertSame('Muster Abdullah', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('saleh.muster@example.com', $p['email']);
        // "+49..." wird zur fuehrenden 0 normalisiert.
        $this->assertSame('01781117036', $p['phone']);
        $this->assertSame('Mastbrooker Weg', $p['street']);
        $this->assertSame('15', $p['house_number']);
        $this->assertSame('24768', $p['zip']);
        $this->assertSame('Rendsburg', $p['city']);
        // Einzelunternehmer: der "Unternehmensname" ist die Person selbst und
        // wird NICHT als Firma uebernommen.
        $this->assertArrayNotHasKey('company_name', $p);

        // Die Sparte aus dem Lastschrift-Satz - gewerblich, nicht privat.
        $this->assertSame('frachtfuehrerhaftpflicht', $r['data']['versicherung']['sparte']);

        $b = $r['data']['bank'];
        $this->assertSame('DE08300209005390827311', $b['iban']);
        $this->assertSame('CMCIDEDDXXX', $b['bic']);
        $this->assertSame('Saleh Muster Abdullah', $b['account_holder']);
    }

    public function test_foreign_account_holder_is_not_taken(): void
    {
        // Kontoinhaber ist ein Dritter -> keine Bankdaten in der Kundenakte.
        $r = (new KontaktSepaDatenParser)->parse(
            $this->seiteText('Abweichender Kontoinhaber', 'Max Fremd')
        );

        $this->assertNotNull($r);
        $this->assertSame([], $r['data']['bank']);
        $this->assertStringContainsString('ohne Bankuebernahme', $r['summary']);
        // Kontaktdaten werden trotzdem gelesen.
        $this->assertSame('Muster Abdullah', $r['data']['person']['last_name']);
    }

    public function test_real_company_name_is_taken(): void
    {
        // Unterscheidet sich der Unternehmensname vom Ansprechpartner, ist es
        // eine echte Firma.
        $text = str_replace(
            'Unternehmensname & Rechtsform:          Saleh Muster Abdullah',
            'Unternehmensname & Rechtsform:          Muster Transporte GmbH',
            $this->seiteText()
        );

        $r = (new KontaktSepaDatenParser)->parse($text);

        $this->assertSame('Muster Transporte GmbH', $r['data']['person']['company_name']);
        $this->assertSame('Saleh', $r['data']['person']['first_name']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new KontaktSepaDatenParser;

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // Der NAFI-Kfz-Antrag traegt IBAN + Beschriftungen, aber NICHT die
        // Kombination Kontoinhaber + Vertragsansprechpartner.
        $this->assertNull($parser->parse(
            "Antrag Kraftfahrtversicherung\nIBAN (SEPA): DE92 2145 0000 0105 7793 34\nZahlungspflichtige Person: Der Versicherungsnehmer"
        ));
    }
}
