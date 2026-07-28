<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\PrivathaftpflichtAntragParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den Privathaftpflicht-Antrag (Haftpflicht gegen Dritte),
 * z.B. AXA Privat-Schutz: liest Versicherer, Sparte, Tarif, Beginn/Ablauf,
 * Beitrag + Zahlweise, Person und Bankverbindung. Synthetische Daten, gleiche
 * Struktur wie das Original (pdftotext -layout).
 */
class PrivathaftpflichtAntragParserTest extends TestCase
{
    private function antragText(): string
    {
        return implode("\n", [
            ' Privathaftpflichtversicherung',
            ' Informationsblatt zu Versicherungsprodukten',
            ' Unternehmen: AXA Versicherung AG Deutschland 5515        Produkt: Privathaftpflichtversicherung',
            '',
            '                                                          AXA Versicherung AG',
            '                                                          Colonia-Allee 10-20',
            '                                                          51067 Köln',
            '                                                          28.07.2026',
            '',
            'Neuantrag auf Abschluss einer',
            'Privat-Schutz-Versicherung',
            '',
            'Antragsteller',
            '',
            'Antragsteller           Herr                              Geburtsdatum: 01.01.2002',
            '                        AHMAD ALJADDOU',
            '                        raabstr. 3',
            '                        90429 Nürnberg',
            '                        E-Mail: abl158fhg@gmail.com',
            '',
            'Versicherungsverträge und Laufzeiten',
            '',
            'Versicherungsverträge                           Beginn',
            'Privathaftpflicht                               28.07.2026',
            '',
            'Versicherungsablauf: 28.07.2027                                Hauptfälligkeit: 28.07.',
            '',
            'Zahlweise               monatlich',
            'Zahlart                 SEPA-Lastschrift',
            '',
            'Beitragsübersicht in EUR',
            'Gewünschte Verträge                             Nettobeitrag     Versicherungsteuer     Gesamtbeitrag',
            'Privathaftpflicht                                        6,05                     1,15          7,20',
            'Summe                                                    6,05                     1,15          7,20',
            '',
            'Abbuchung',
            'IBAN                                                      DE69 7425 1020 0052 5663 04',
            'BIC                                                       BYLADEM1CHM',
            'Geldinstitut, Ort                                         Sparkasse im Landkreis Cham, Cham',
            '',
            'Privathaftpflicht Privathaftpflicht komfort',
            '                      für eine Familie',
            'Versicherungs-        60.000.000 EUR Versicherungssumme pauschal für Personen-/Sach- und Vermögens-',
            'umfang                schäden (3-fach maximiert)',
            'Selbst-               Sie haben für Ihren Privathaftpflicht-Vertrag keine Selbstbeteiligung vereinbart.',
            'beteiligung',
            '',
            'Beitrag monatlich inkl. Versicherungsteuer                                            7,20 EUR',
        ]);
    }

    public function test_parses_privathaftpflicht_application(): void
    {
        $r = (new PrivathaftpflichtAntragParser())->parse($this->antragText());

        $this->assertNotNull($r);
        $this->assertSame('versicherungsvertrag', $r['type']);

        $p = $r['data']['person'];
        // GROSSGESCHRIEBENER Name wird normalisiert.
        $this->assertSame('Ahmad', $p['first_name']);
        $this->assertSame('Aljaddou', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('2002-01-01', $p['birth_date']);
        $this->assertSame('Raabstr.', $p['street']); // kleingeschrieben -> korrigiert
        $this->assertSame('3', $p['house_number']);
        $this->assertSame('90429', $p['zip']);
        $this->assertSame('Nürnberg', $p['city']);
        $this->assertSame('abl158fhg@gmail.com', $p['email']);

        $v = $r['data']['versicherung'];
        $this->assertSame('AXA Versicherung AG', $v['insurer']);
        $this->assertSame('haftpflicht', $v['sparte']);
        $this->assertSame('Privathaftpflicht komfort', $v['tariff']);
        $this->assertSame('2026-07-28', $v['start_date']);
        $this->assertSame('2027-07-28', $v['end_date']);
        // Gesamtbeitrag inkl. Steuer (NICHT der Nettobeitrag 6,05).
        $this->assertSame(7.2, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);

        // SEPA-Block = Bankverbindung des Kunden.
        $b = $r['data']['bank'];
        $this->assertSame('DE69742510200052566304', $b['iban']);
        $this->assertSame('BYLADEM1CHM', $b['bic']);

        // Versicherungssumme in der Zusammenfassung.
        $this->assertStringContainsString('60.000.000 EUR', $r['summary']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new PrivathaftpflichtAntragParser())->parse('Irgendein anderes Dokument'));
        // CHECK24-Protokoll hat einen eigenen Parser.
        $this->assertNull((new PrivathaftpflichtAntragParser())->parse(
            "Beratungsprotokoll CHECK24\nPrivathaftpflicht Antrag"
        ));
        // Reines Info-Blatt ohne Antrag/Angebot.
        $this->assertNull((new PrivathaftpflichtAntragParser())->parse(
            'Privathaftpflichtversicherung - allgemeine Kundeninformation'
        ));
    }
}
