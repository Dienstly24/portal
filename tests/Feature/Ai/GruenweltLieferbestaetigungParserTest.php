<?php

namespace Tests\Feature\Ai;

use App\Models\Contract;
use App\Services\Ai\TemplateParsers\GruenweltLieferbestaetigungParser;
use Tests\TestCase;

/**
 * Lieferbestaetigung der Gruenwelt-Gesellschaften (Strom/Gas) - das
 * Bestaetigungsschreiben NACH dem Auftrag. Der Aufbau ist der der echten
 * Textebene (Kopfblock rechts mit einfachem Leerzeichen, Empfaengerblock mit
 * angehaengter Service-Spalte, Vertragsdaten als Tabelle mit grossem
 * Spaltenabstand); die WERTE sind erfunden - echte Kundendaten gehoeren nicht
 * ins Repository.
 */
class GruenweltLieferbestaetigungParserTest extends TestCase
{
    private function schreiben(string $sparte = 'strom'): string
    {
        $lieferung = $sparte === 'gas' ? 'Gaslieferung' : 'Stromlieferung';
        $tarif = $sparte === 'gas' ? 'Erdgas Direkt Basic' : 'Strom Direkt Basic';

        return <<<TXT
                    Grünwelt Wärmestrom GmbH • Postfach 90 01 13 • 39133 Magdeburg

                                                            Grünwelt Wärmestrom GmbH
                                                            Postfach 90 01 13
                                                            39133 Magdeburg

        Herr                                        E-Mail kundenservice@gruenweltenergie.de
                                                   Telefon 0800 5555 773
        Jonas Bergmann                                 Fax 0800 5555 774
        Lindenweg 12                                       kostenlos aus dem dt. Festnetz
        30457 Hannover                                     Montag − Freitag 08:00 − 18:00 Uhr

                                              Bestellnummer 81234567
                                        Vertragskontonummer 121009876543
                                            Mandatsreferenz 121009876543-01-1
                                           Verbrauchsstelle Lindenweg 12,
                                                            30457 Hannover

                                                            08.05.2026
        Lieferbestätigung

        Sehr geehrter Herr Bergmann,

        wir freuen uns, dass Sie sich für einen günstigen Tarif von Grünwelt entschieden haben.
        Die oben benannte Verbrauchsstelle haben wir erfolgreich bei Ihrem zuständigen
        Netzbetreiber angemeldet. Mit diesem Schreiben bestätigen wir Ihnen den Beginn der
        {$lieferung} ab dem 21.05.2026 und teilen Ihnen den Abschlagsbetrag mit.

                                                            IBAN: DE41 5501 0400 0553 0454 33
                                                            BIC: AARBDE5WDOM

ZUSAMMENFASSUNG IHRER VERTRAGSDATEN*

Bestelldatum                                 04.05.2026
Lieferbeginn                                 21.05.2026
Tarif                                        {$tarif}
Arbeitspreis HT                              31,20 ct/kWh brutto (26,22 ct/kWh netto)
Grundpreis                                   159,00 EUR/Jahr brutto (133,61 EUR/Jahr netto)
Zahlweise                                    SEPA-Lastschriftmandat erteilt
Vertragslaufzeit ab Lieferbeginn             24 Monate
Kündigungsfrist zum Laufzeitende             1 Monat
Verlängerung jeweils um                      unbestimmte Zeit
Eingeschränkte Preisgarantie ab Lieferbeginn 24 Monate
Zählernummer                                 618350
Marktlokationsidentifikationsnummer          10099887766
Ihr Jahresverbrauch                          2.000 kWh
Kontoinhaber                                 Jonas Bergmann
Kreditinstitut                               Sparkasse Hannover
IBAN                                         DE70 XXXX XXXX XXXX XX55 30
BIC                                          SPKHDE2HXXX

Bitte melden Sie Ihren Zählerstand zum Lieferbeginn Ihrem zuständigen Messstellenbetreiber enercity Netz GmbH.

Aufgeteilt auf insgesamt 12 Abschläge im Abrechnungszeitraum ergibt sich Ihr monatlicher Abschlag in Höhe von 65,00 EUR
brutto (54,62 EUR netto).
TXT;
    }

    public function test_reads_the_supply_confirmation(): void
    {
        $r = (new GruenweltLieferbestaetigungParser())->parse($this->schreiben());

        $this->assertNotNull($r);
        $this->assertSame('energieauftrag', $r['type']);

        $v = $r['data']['versicherung'];
        $this->assertSame('Grünwelt Wärmestrom GmbH', $v['insurer']);
        $this->assertSame('strom', $v['sparte']);
        // Bestaetigung = Stufe 'vertrag' -> ergaenzt den vorhandenen Antrag.
        $this->assertSame(Contract::STAGE_VERTRAG, $v['document_stage']);
        $this->assertSame('121009876543', $v['contract_number']);
        // Die Bestellnummer ist die Kennung des Vorgangs, NIE die Vertragsnummer.
        $this->assertSame('81234567', $v['reference_number']);
        $this->assertSame('2026-05-21', $v['start_date']);
        $this->assertSame('Strom Direkt Basic', $v['tariff']);
        $this->assertSame(65.0, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);

        $e = $r['data']['energie'];
        $this->assertSame('618350', $e['meter_number']);
        $this->assertSame('10099887766', $e['malo_id']);
        $this->assertSame(2000, $e['consumption_kwh']);
        $this->assertSame(31.2, $e['working_price']);
        // Grundpreis steht je JAHR, die Kundenakte fuehrt ihn je MONAT.
        $this->assertSame(13.25, $e['base_price']);

        $p = $r['data']['person'];
        $this->assertSame('Jonas', $p['first_name']);
        $this->assertSame('Bergmann', $p['last_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('Lindenweg', $p['street']);
        $this->assertSame('12', $p['house_number']);
        $this->assertSame('30457', $p['zip']);
        $this->assertSame('Hannover', $p['city']);
        // Service-Spalte des Versorgers darf nie in die Kundenakte geraten.
        $this->assertArrayNotHasKey('email', $p);
        $this->assertArrayNotHasKey('phone', $p);
    }

    public function test_never_takes_bank_details(): void
    {
        // Die Kunden-IBAN ist maskiert, die vollstaendige IBAN im Brieffuss
        // gehoert Gruenwelt selbst - beides darf nie in die Kundenakte.
        $r = (new GruenweltLieferbestaetigungParser())->parse($this->schreiben());

        $this->assertSame([], $r['data']['bank']);
        $this->assertStringNotContainsString('DE41', json_encode($r));
        $this->assertStringNotContainsString('DE70', json_encode($r));
    }

    public function test_summary_carries_the_fields_without_own_column(): void
    {
        $r = (new GruenweltLieferbestaetigungParser())->parse($this->schreiben());

        $this->assertStringContainsString('Laufzeit 24 Monate', $r['summary']);
        $this->assertStringContainsString('Kuendigungsfrist 1 Monat', $r['summary']);
        $this->assertStringContainsString('Verlaengerung um unbestimmte Zeit', $r['summary']);
        $this->assertStringContainsString('Grundpreis 159,00 EUR/Jahr brutto', $r['summary']);
        // Der genannte Betrieb ist der MESSSTELLENbetreiber - er darf nicht
        // als Netzbetreiber ins Feld wandern, geht aber auch nicht verloren.
        $this->assertStringContainsString('Messstellenbetreiber enercity Netz GmbH', $r['summary']);
        $this->assertArrayNotHasKey('grid_operator', $r['data']['energie']);
    }

    public function test_recognizes_the_gas_variant(): void
    {
        $r = (new GruenweltLieferbestaetigungParser())->parse($this->schreiben('gas'));

        $this->assertNotNull($r);
        $this->assertSame('gas', $r['data']['versicherung']['sparte']);
        $this->assertSame('Erdgas Direkt Basic', $r['data']['versicherung']['tariff']);
    }

    public function test_ignores_other_letters_of_the_same_supplier(): void
    {
        // "Gruenwelt" allein genuegt nicht - eine Jahresabrechnung bestaetigt
        // keine Vertragsdaten und darf den Parser nicht ausloesen.
        $abrechnung = "Grünwelt Wärmestrom GmbH\nJahresabrechnung\nVertragskontonummer 121009876543\n";
        $this->assertNull((new GruenweltLieferbestaetigungParser())->parse($abrechnung));

        $this->assertNull((new GruenweltLieferbestaetigungParser())->parse('Irgendein anderes Dokument'));
    }
}
