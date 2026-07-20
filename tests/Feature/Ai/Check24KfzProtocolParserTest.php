<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\Check24KfzProtocolParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer das CHECK24-Kfz-Beratungsprotokoll: liest die Felder per
 * fester Regel aus der (immer gleich aufgebauten) Textebene - ohne KI.
 */
class Check24KfzProtocolParserTest extends TestCase
{
    /** Kunstprotokoll ohne echte PII, gleiche Struktur wie das Original. */
    private function protocolText(string $deckung = 'mit Teilkasko', string $sb = '300 € TK'): string
    {
        return implode("\n", [
            'Vorlaeufiges Beratungsprotokoll zur Kfz-Versicherung',
            '  CHECK24 Vergleichsportal                 E-Mail: kfz-beratung@check24.de',
            '  Versicherungsnehmer',
            '  Herr                                     Anschrift:                     E-Mail:',
            '  Max Mustermann                           Teststr. 12                    max.muster@example.com',
            '  Geboren am 01.02.1990                    12345 Berlin                   015112345678',
            '  Fahrzeug                                 Versicherungsnehmer',
            '  HSN/TSN: 1234/ABC                        Geschlecht: maennlich',
            '  Halter: Versicherungsnehmer              Wohnort: 12345 Berlin',
            '  Versicherungsbeginn: 01.08.2026          Deckung: ' . $deckung,
            '  Jährliche Fahrleistung: 10.000 km        Selbstbeteiligung: ' . $sb,
            '  Zahlweise: monatlich',
            'waehlte der Versicherungsnehmer selbstständig folgenden Tarif:',
            '',
            'HUK24 Classic mit Werkstattbindung',
            'Preisdetails',
            'Gesamtbeitrag (inkl. Versicherungssteuer)   150,00 € monatlich',
        ]);
    }

    public function test_parses_all_key_fields(): void
    {
        $r = (new Check24KfzProtocolParser())->parse($this->protocolText());

        $this->assertNotNull($r);
        $this->assertSame('beratungsprotokoll', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Max', $p['first_name']);
        $this->assertSame('Mustermann', $p['last_name']);
        $this->assertSame('1990-02-01', $p['birth_date']);
        $this->assertSame('12345', $p['zip']);
        $this->assertSame('Berlin', $p['city']);
        $this->assertSame('Teststr.', $p['street']);
        $this->assertSame('12', $p['house_number']);
        $this->assertSame('max.muster@example.com', $p['email']); // nicht die CHECK24-Mail
        $this->assertSame('015112345678', $p['phone']);

        $k = $r['data']['kfz'];
        $this->assertSame('1234', $k['hsn']);
        $this->assertSame('ABC', $k['tsn']);
        $this->assertSame('versicherungsnehmer', $k['holder_type']);
        $this->assertSame(10000, $k['annual_mileage']);
        $this->assertTrue($k['has_teilkasko']);
        $this->assertSame(300, $k['teilkasko_deductible']);

        $v = $r['data']['versicherung'];
        $this->assertSame('HUK24', $v['insurer']);
        $this->assertSame('kfz', $v['sparte']);
        $this->assertSame('2026-08-01', $v['start_date']);
        $this->assertSame(150.0, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
    }

    public function test_house_number_with_letter_and_space_is_split(): void
    {
        // "Mittelstr. 21 b" (Leerzeichen vor dem Zusatzbuchstaben) wurde bisher
        // NICHT getrennt - die Nummer blieb in der Strasse, die Hausnummer leer.
        $text = str_replace('Teststr. 12', 'Mittelstr. 21 b', $this->protocolText());
        $p = (new Check24KfzProtocolParser())->parse($text)['data']['person'];

        $this->assertSame('Mittelstr.', $p['street']);
        $this->assertSame('21 b', $p['house_number']);
    }

    public function test_phone_ignores_non_phone_numbers(): void
    {
        // Eine lange 0-Referenznummer VOR der echten Handynummer darf nicht als
        // Telefon uebernommen werden.
        $text = str_replace(
            '  Geboren am 01.02.1990                    12345 Berlin                   015112345678',
            "  Referenz 0123456789012 (Vorgang)\n  Geboren am 01.02.1990    12345 Berlin    015112345678",
            $this->protocolText()
        );
        $p = (new Check24KfzProtocolParser())->parse($text)['data']['person'];
        $this->assertSame('015112345678', $p['phone']);
    }

    public function test_reads_tatsaechliche_sf_klasse_haftpflicht(): void
    {
        // Zweitwagen-Sondereinstufung: Klasse + Typ + Grund; "Angegebene:
        // keine" -> keine echte (uebertragbare) Klasse.
        $text = $this->protocolText() . "\n"
            . "Angegebene SF-Klasse Haftpflicht            keine\n"
            . "Tatsächliche SF-Klasse Haftpflicht          SF 2 (Zweitwagen-Sondereinstufung)";
        $k = (new Check24KfzProtocolParser())->parse($text)['data']['kfz'];
        $this->assertSame('2', $k['sf_liability_class']);
        $this->assertSame('sondereinstufung', $k['sf_liability_type']);
        $this->assertSame('zweitwagen', $k['sf_liability_special_reason']);
        $this->assertArrayNotHasKey('sf_liability_real_class', $k);
    }

    public function test_sondereinstufung_keeps_real_class_from_angegebene(): void
    {
        // Betreiber-Fall: der Kunde hat ECHT SF 4 (angegeben), der neue
        // Versicherer gewaehrt SF 5 als Sondereinstufung (nicht uebertragbar).
        // Beides muss getrennt landen - bisher manuell auseinanderzuhalten.
        $text = $this->protocolText() . "\n"
            . "Angegebene SF-Klasse Haftpflicht            SF 4\n"
            . "Tatsächliche SF-Klasse Haftpflicht          SF 5 (Sondereinstufung)";
        $r = (new Check24KfzProtocolParser())->parse($text);
        $k = $r['data']['kfz'];

        $this->assertSame('5', $k['sf_liability_class']);
        $this->assertSame('sondereinstufung', $k['sf_liability_type']);
        $this->assertSame('4', $k['sf_liability_real_class']);
        $this->assertArrayNotHasKey('sf_liability_special_reason', $k); // Grund unbekannt -> leer
        $this->assertStringContainsString('SF 5 (Sondereinstufung, nicht uebertragbar; echte Klasse SF 4)', $r['summary']);
    }

    public function test_sf_without_sondereinstufung_is_tatsaechlich(): void
    {
        $text = $this->protocolText() . "\n"
            . "Angegebene SF-Klasse Haftpflicht            SF 6\n"
            . "Tatsächliche SF-Klasse Haftpflicht          SF 6";
        $k = (new Check24KfzProtocolParser())->parse($text)['data']['kfz'];
        $this->assertSame('6', $k['sf_liability_class']);
        $this->assertSame('tatsaechlich', $k['sf_liability_type']);
        $this->assertArrayNotHasKey('sf_liability_real_class', $k);
    }

    public function test_vorversicherung_is_cut_at_next_single_space_label(): void
    {
        // Manche Layouts trennen die naechste Spalte nur mit EINEM Leerzeichen.
        $text = $this->protocolText() . "\n"
            . "nehmer und 1 weitere Fahrer)            Vorversicherung: Verti Versicherung AG Zahlweise: jährlich";
        $v = (new Check24KfzProtocolParser())->parse($text)['data']['versicherung'];
        $this->assertSame('Verti Versicherung AG', $v['previous_insurer']);
    }

    public function test_reads_vorversicherung_and_ablauf_der_versicherung(): void
    {
        // Wechsel-Kontext: Vorversicherer (wo der Kunde herkommt) + Vertrags-
        // ablauf - beides musste der Betrieb bisher von Hand nachtragen.
        $text = $this->protocolText() . "\n"
            . "Hauptnutzer: Versicherungsnehmer,         Vorversicherung: HUK-Coburg\n"
            . "Ablauf der Versicherung                     29.06.2027 (automatische Verlängerung um 12 Monate)";
        $r = (new Check24KfzProtocolParser())->parse($text);
        $v = $r['data']['versicherung'];

        $this->assertSame('HUK-Coburg', $v['previous_insurer']);
        $this->assertSame('2027-06-29', $v['end_date']);
        $this->assertStringContainsString('Vorversicherung: HUK-Coburg', $r['summary']);
        $this->assertStringContainsString('Ablauf der Versicherung: 29.06.2027', $r['summary']);
    }

    public function test_haftpflicht_only_has_no_kasko(): void
    {
        $r = (new Check24KfzProtocolParser())->parse($this->protocolText('nur Haftpflicht', '0 €'));

        $this->assertFalse($r['data']['kfz']['has_teilkasko']);
        $this->assertFalse($r['data']['kfz']['has_vollkasko']);
        $this->assertArrayNotHasKey('teilkasko_deductible', $r['data']['kfz']);
    }

    /**
     * Protokoll im DA-Direkt-Layout: mehrwortiger Versicherer, Vollkasko mit
     * getrennten SB (VK/TK), Werkstattbindung, Vorversicherung mit Kuendigungs-
     * angabe. Enthaelt die Vergleichstabelle mit dem Monatsbeitrag.
     */
    private function daDirektProtocol(): string
    {
        return implode("\n", [
            'Vorlaeufiges Beratungsprotokoll zur Kfz-Versicherung',
            '  CHECK24 Vergleichsportal                 E-Mail: kfz-beratung@check24.de',
            '  Versicherungsnehmer',
            '  Herr                                     Anschrift:                     E-Mail:',
            '  Ahmad Alhaj Sulaiman                     Hohenloher Str. 7              alhajsulaiman85@gmail.com',
            '  Geboren am 01.06.1985                    71543 Wuestenrot               01746150627',
            '  Fahrzeug                                 Versicherungsnehmer            Rabatte',
            '  HSN/TSN: 0710/362                        Geschlecht: maennlich          Nur freie Werkstattwahl: nein',
            '  Halter: Versicherungsnehmer              Wohnort: 71543 Wuestenrot',
            '  Versicherungsbeginn: 04.02.2026          Deckung: mit Vollkasko',
            '  Jährliche Fahrleistung: 9.000 km         Selbstbeteiligung: 500 € VK, 150 € TK   Zahlweise: monatlich',
            '  Hauptnutzer: Versicherungsnehmer,        Vorversicherung: Generali',
            '  geb. 01.06.1985                          Seit wann: länger als 3 Jahre',
            '                                           Kündigung durch',
            '                                           Vorversicherer: nein',
            '                                           Schutzbrief gewünscht: nein',
            '                                           Fahrerschutz gewünscht: nein',
            'Position Versicherer / Tarif                                          Beitrag monatlich',
            '     1    DA Direkt Basis mit Werkstattbindung                                62,04 €',
            '     3    DA Direkt Komfort Smart mit Werkstattbindung                        64,56 €',
            'waehlte der Versicherungsnehmer selbstständig folgenden Tarif:',
            '',
            'DA Direkt Komfort Smart mit Werkstattbindung',
            'Angaben ohne Gewähr',
        ]);
    }

    public function test_multiword_insurer_and_tariff_are_split(): void
    {
        // Der Versicherer ist NICHT nur das erste Wort ("DA"), sondern
        // "DA Direkt"; der Rest ist der Tarifname.
        $v = (new Check24KfzProtocolParser())->parse($this->daDirektProtocol())['data']['versicherung'];
        $this->assertSame('DA Direkt', $v['insurer']);
        $this->assertSame('Komfort Smart mit Werkstattbindung', $v['tariff']);
        // Monatsbeitrag zum gewaehlten Tarif aus der Vergleichstabelle.
        $this->assertSame(64.56, $v['premium_amount']);
        $this->assertSame('monthly', $v['premium_interval']);
    }

    public function test_both_kasko_deductibles_are_read(): void
    {
        // "mit Vollkasko" + "500 € VK, 150 € TK": Teilkasko (150) UND Vollkasko
        // (500) gehoeren in den Vertrag - nicht nur eine der beiden.
        $k = (new Check24KfzProtocolParser())->parse($this->daDirektProtocol())['data']['kfz'];
        $this->assertTrue($k['has_vollkasko']);
        $this->assertTrue($k['has_teilkasko']);
        $this->assertSame(500, $k['vollkasko_deductible']);
        $this->assertSame(150, $k['teilkasko_deductible']);
    }

    public function test_werkstattbindung_is_read_as_extra(): void
    {
        $k = (new Check24KfzProtocolParser())->parse($this->daDirektProtocol())['data']['kfz'];
        $this->assertContains('werkstattbindung', $k['extras']);
        // "gewünscht: nein" -> Schutzbrief/Fahrerschutz NICHT aufnehmen.
        $this->assertNotContains('schutzbrief', $k['extras']);
        $this->assertNotContains('fahrerschutz', $k['extras']);
    }

    public function test_vorversicherung_details_are_read(): void
    {
        $v = (new Check24KfzProtocolParser())->parse($this->daDirektProtocol())['data']['versicherung'];
        $this->assertSame('Generali', $v['previous_insurer']);
        $this->assertSame('länger als 3 Jahre', $v['previous_insurance_since']);
        // "Kündigung durch Vorversicherer: nein" -> false (muss erhalten bleiben).
        $this->assertArrayHasKey('previous_insurance_terminated', $v);
        $this->assertFalse($v['previous_insurance_terminated']);
    }

    public function test_schutzbrief_and_fahrerschutz_extras_when_wished(): void
    {
        $text = str_replace(
            ['Schutzbrief gewünscht: nein', 'Fahrerschutz gewünscht: nein'],
            ['Schutzbrief gewünscht: ja', 'Fahrerschutz gewünscht: ja'],
            $this->daDirektProtocol()
        );
        $k = (new Check24KfzProtocolParser())->parse($text)['data']['kfz'];
        $this->assertContains('schutzbrief', $k['extras']);
        $this->assertContains('fahrerschutz', $k['extras']);
        $this->assertContains('werkstattbindung', $k['extras']);
    }

    public function test_non_check24_document_is_not_matched(): void
    {
        $this->assertNull((new Check24KfzProtocolParser())->parse('Irgendein anderes Dokument ohne die Marker.'));
        // Auch ein Nicht-Kfz-Beratungsprotokoll ohne CHECK24 nicht anfassen.
        $this->assertNull((new Check24KfzProtocolParser())->parse('Beratungsprotokoll zur Krankenversicherung'));
    }
}
