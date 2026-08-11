<?php

namespace Tests\Feature\Ai;

use App\Models\Contract;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\TemplateParsers\CompositeDocumentTemplateParser;
use Tests\TestCase;

/**
 * Der Composite buendelt alle Vorlagen-Parser (erste Erkennung gewinnt).
 * Diese Tests sichern das Zusammenspiel ab, das die einzelnen Parser-Tests
 * NICHT pruefen: die Reihenfolge/Ueberschneidung der Parser und die
 * Fehler-Isolierung.
 */
class CompositeDocumentTemplateParserTest extends TestCase
{
    /** Zeile "Beschriftung:" links, Wert rechtsbuendig (wie pdftotext -layout). */
    private function row(string $label, string $value): string
    {
        return ' ' . str_pad($label . ':', 60) . str_pad($value, 60, ' ', STR_PAD_LEFT);
    }

    /**
     * Ein NAFI-Maklerantrag, der die WGV als Gesellschaft nennt, traegt sowohl
     * "WGV" als auch "Kraftfahrtversicherung" - er darf NICHT vom
     * WGV-Versicherungsschein-Parser als bestaetigter Vertrag (Stufe 'vertrag')
     * gelesen werden, sondern bleibt ein ANTRAG. Geprueft ueber die ECHTE
     * Registrierungsreihenfolge (Container-Binding), damit auch die
     * Parser-Reihenfolge abgesichert ist.
     */
    public function test_nafi_antrag_naming_wgv_stays_an_application(): void
    {
        $text = implode("\n", [
            'WGV Versicherung AG',
            'Antrag Kraftfahrtversicherung',
            'Achtung: Antrag wurde bereits ONLINE zum Versicherer gesendet!',
            '',
            ' Versicherungsnehmer',
            $this->row('Anrede, Titel, Vorname, Nachname', 'Herr Ali Mustermann'),
            $this->row('Straße', 'Alte Kieler Landstr. 90'),
            $this->row('Plz, Ort', '24768 Rendsburg'),
            $this->row('Geburtsdatum', '08.05.1980'),
            '',
            ' Antragsdaten',
            $this->row('Tarif', 'WGV OPTIMAL'),
            $this->row('Versicherer / Risikoträger', 'WGV'),
            $this->row('Gewünschter Versicherungsbeginn', '06.08.2026'),
            $this->row('Amtliches Kennzeichen', 'RD - AS 1212'),
            $this->row('Fahrgestellnummer', 'ZFA25000001174717'),
            '',
            $this->row('Zu zahlender Gesamtbeitrag (vierteljährlich)', '296,00 EUR'),
            $this->row('Zahlungsperiode', 'Vierteljährlich'),
        ]);

        $result = app(DocumentTemplateParser::class)->parse($text);

        $this->assertNotNull($result);
        $this->assertSame('kfz_vertrag', $result['type']);
        // Kernpunkt: es bleibt ein ANTRAG, kein bestaetigter Vertrag.
        $this->assertSame(
            Contract::STAGE_ANTRAG,
            $result['data']['versicherung']['document_stage'] ?? null,
            'NAFI-Antrag (WGV) darf nicht als bestaetigter Versicherungsschein gelesen werden.'
        );
    }

    /**
     * Ein einzelner fehlerhafter Parser darf die gesamte kostenlose Analyse
     * NICHT zum Absturz bringen: der Composite ueberspringt den Werfer und
     * nutzt den naechsten Parser, der das Dokument erkennt.
     */
    public function test_a_throwing_parser_is_isolated(): void
    {
        $throwing = new class implements DocumentTemplateParser {
            public function parse(string $text): ?array
            {
                throw new \RuntimeException('kaputt');
            }
        };
        $working = new class implements DocumentTemplateParser {
            public function parse(string $text): ?array
            {
                return ['type' => 'sonstiges', 'confidence' => 10, 'summary' => 'ok', 'title' => null, 'data' => []];
            }
        };

        $composite = new CompositeDocumentTemplateParser([$throwing, $working]);

        $result = $composite->parse('irgendein Text');

        $this->assertNotNull($result);
        $this->assertSame('sonstiges', $result['type']);
    }

    /** Erkennt kein Parser das Dokument, liefert der Composite null (Analyse laeuft weiter). */
    public function test_returns_null_when_no_parser_matches(): void
    {
        $result = app(DocumentTemplateParser::class)->parse('Ein voellig unspezifischer Fliesstext ohne Formularmerkmale.');

        $this->assertNull($result);
    }

    /**
     * Ein Buendel-PDF mit Deckungsauftrag UND Beratungsdokumentation (beide
     * Fonds-Finanz, gleiche Vorgangsnummer) muss ueber die echte Reihenfolge
     * als DECKUNGSAUFTRAG (Vertragsdaten, Stufe antrag) herauskommen, nicht als
     * reines Beratungsprotokoll ohne Vertragskern.
     */
    public function test_deckungsauftrag_wins_over_beratungsdokumentation_in_bundle(): void
    {
        $text = implode("\n", [
            'Deckungsauftrag zur',
            'Frachtführerhaftpflicht',
            'Deckungsauftrag für:                             Ansprechpartner:',
            'Karim Muster Einzelunternehmen                   FondsFinanz',
            'Vorgangsnummer:                7654321',
            'Informationen zur Beitragsberechnung',
            'Produktname                                                    CargoTrucker (06/2022)',
            'Beginn / Ende                                                  2026-08-07/2027-08-06',
            'Gesamtprämie brutto                                            238',
            'Daten des Versicherungsnehmers',
            'Firmenname                                              Karim Muster',
            'Rechtsform                                              Einzelunternehmen',
            'Anschrift                                               Musterallee 7',
            '                                                        24768 Rendsburg',
            '',
            // ... angehaengte Beratungsdokumentation desselben Vorgangs:
            'BERATUNGSDOKUMENTATION',
            'Vorgangsnummer: 7654321',
            'Vorschlag für:',
            'Karim Muster',
        ]);

        $result = app(DocumentTemplateParser::class)->parse($text);

        $this->assertNotNull($result);
        $this->assertSame('versicherungsvertrag', $result['type'], 'Deckungsauftrag (Vertragsdaten) muss gewinnen, nicht die Beratungsdoku');
        $this->assertSame(Contract::STAGE_ANTRAG, $result['data']['versicherung']['document_stage'] ?? null);
    }

    /**
     * REGRESSION (Audit 10.08.2026): ein STROM-/GAS-Auftrag darf ueber die
     * ECHTE Parser-Reihenfolge NIE als Internet-Vertrag herauskommen (der
     * DSL-Parser beanspruchte ihn frueher wegen "Anbieterwechsel"/
     * "Netzanschluss"/"Mindestlaufzeit"). Ein generischer Stadtwerke-Auftrag
     * trifft keinen spezifischen Energie-Parser -> Composite liefert null und
     * die normale Analyse (KI/Heuristik) erkennt den Energie-Auftrag.
     */
    public function test_energy_order_is_never_claimed_as_internet(): void
    {
        $stromAuftrag = implode("\n", [
            'Stromliefervertrag - Auftragsbestätigung',
            'Anbieter          Stadtwerke Musterstadt',
            'Ihr Anbieterwechsel wird zum nächstmöglichen Termin durchgeführt.',
            'Netzanschluss / Netzbetreiber: Netze BW',
            'Marktlokation (MaLo-ID): 51238973456',
            'Jahresverbrauch   3.500 kWh',
            'Arbeitspreis      28,50 ct/kWh',
            'Mindestlaufzeit   12 Monate',
        ]);

        $result = app(DocumentTemplateParser::class)->parse($stromAuftrag);

        // Entweder null (dann uebernimmt KI/Heuristik als Energie-Auftrag) -
        // aber NIEMALS faelschlich ein Internet-Vertrag.
        $this->assertNotSame('internetvertrag', $result['type'] ?? null);
    }
}
