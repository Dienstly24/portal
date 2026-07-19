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

    public function test_haftpflicht_only_has_no_kasko(): void
    {
        $r = (new Check24KfzProtocolParser())->parse($this->protocolText('nur Haftpflicht', '0 €'));

        $this->assertFalse($r['data']['kfz']['has_teilkasko']);
        $this->assertFalse($r['data']['kfz']['has_vollkasko']);
        $this->assertArrayNotHasKey('teilkasko_deductible', $r['data']['kfz']);
    }

    public function test_non_check24_document_is_not_matched(): void
    {
        $this->assertNull((new Check24KfzProtocolParser())->parse('Irgendein anderes Dokument ohne die Marker.'));
        // Auch ein Nicht-Kfz-Beratungsprotokoll ohne CHECK24 nicht anfassen.
        $this->assertNull((new Check24KfzProtocolParser())->parse('Beratungsprotokoll zur Krankenversicherung'));
    }
}
