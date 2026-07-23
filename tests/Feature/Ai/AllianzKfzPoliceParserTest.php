<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\AllianzKfzPoliceParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den Allianz-Kfz-Versicherungsschein (Police, inkl.
 * Nutz-/Flottenfahrzeug AKB-NF): liest Versicherer, Versicherungsschein-Nummer,
 * Kennzeichen, Fahrzeugdaten (FIN/HSN/Hersteller), Halter, Deckung, SF-Klasse
 * (Haftpflicht), Schutzbrief, Beitrag, Beginn + Ablauf. Synthetische Daten,
 * gleiche Struktur wie das Original (pdftotext -layout).
 */
class AllianzKfzPoliceParserTest extends TestCase
{
    private function policeText(): string
    {
        return implode("\n", [
            'Kopie für Vermittler:in 11104668',
            '',
            'Versicherungsnehmer:in',
            '',
            'Amer Saleem Jawad Hadab',
            'Stockholmstr. 51',
            '24109 Kiel',
            '',
            '  Versicherungsschein Kfz-Versicherung',
            'Versicherungsschein-Nummer: AS-9708926939',
            'Ausfertigungsgrund: Neuabschluss Ihrer Kfz-Versicherung',
            '',
            'Kfz-Versicherung',
            'Versicherungsbeginn:                                       21.07.2026, 0 Uhr',
            'Versicherungsablauf:                                       21.07.2027, 0 Uhr',
            '',
            'Versichertes Fahrzeug - privat genutzt',
            'Fahrzeugart:                                               LKW bis 3,5 t zulGG Privatnutzung',
            'Amtliches Kennzeichen:                                     KI BV 9371',
            'Hersteller:                                                FIAT',
            'Leistung:                                                  88 KW',
            'Hersteller-Schlüssel-Nr.:                                  4136',
            'Fahrzeug-Identifizierungs-Nr.:                             ZFA25000001795408',
            'Erstzulassung Fahrzeug:                                    06.2010',
            '',
            'Das Fahrzeug ist zugelassen auf den Versicherungsnehmer.',
            '',
            'Versicherte Leistungen',
            '• Kfz-Haftpflichtversicherung',
            '  - Schutzbrief Firmen',
            '• Kaskoversicherung',
            '  Eine Kaskoversicherung besteht durch diesen Vertrag nicht.',
            '',
            'Schadenfreiheitsklasse im Versicherungsjahr 2026',
            '• Kfz-Haftpflichtversicherung',
            '  Klasse 0                    (Beitragssatz 110 %)',
            '',
            'Es wurde vierteljährliche Zahlungsperiode vereinbart.',
            '',
            'Nettobeitrag vierteljährlich                Versicherungsteuer          Versicherungsbeitrag',
            'Kfz-Haftpflichtversicherung',
            'Beitragssatz 110 % (Klasse 0)               320,16    19,00    60,83    380,99',
            'Gesamtbeitrag                                                            380,99',
            '',
            'Einzugsermächtigung / Bankverbindung',
            'IBAN DEXXXXXXXXXXXXXXXX8804,',
            'BIC NOLADE21KIE,',
            'lautend auf Herrn Amer Saleem Jawad Hadab',
            'bei der Förde Sparkasse ein.',
            '',
            '20. Juli 2026',
            'Allianz Versicherungs-Aktiengesellschaft',
        ]);
    }

    public function test_parses_all_key_fields(): void
    {
        $r = (new AllianzKfzPoliceParser())->parse($this->policeText());

        $this->assertNotNull($r);
        $this->assertSame('kfz_vertrag', $r['type']);

        $p = $r['data']['person'];
        // Mehrteiliger Vorname, letzter Namensteil = Nachname.
        $this->assertSame('Amer Saleem Jawad', $p['first_name']);
        $this->assertSame('Hadab', $p['last_name']);
        $this->assertSame('Stockholmstr.', $p['street']);
        $this->assertSame('51', $p['house_number']);
        $this->assertSame('24109', $p['zip']);
        $this->assertSame('Kiel', $p['city']);
        $this->assertSame('male', $p['gender']);

        $k = $r['data']['kfz'];
        $this->assertSame('KI BV 9371', $k['license_plate']);
        $this->assertSame('ZFA25000001795408', $k['vin']);
        $this->assertSame('4136', $k['hsn']);
        $this->assertSame('FIAT', $k['manufacturer']);
        $this->assertSame('0', $k['sf_liability_class']);
        $this->assertFalse($k['has_teilkasko']);
        $this->assertFalse($k['has_vollkasko']);
        $this->assertContains('schutzbrief', $k['extras']);
        $this->assertSame('versicherungsnehmer', $k['holder_type']);

        $v = $r['data']['versicherung'];
        $this->assertSame('Allianz', $v['insurer']);
        $this->assertSame('AS-9708926939', $v['contract_number']);
        $this->assertSame('kfz', $v['sparte']);
        $this->assertSame('2026-07-21', $v['start_date']);
        $this->assertSame('2027-07-21', $v['end_date']);
        $this->assertSame(380.99, $v['premium_amount']);
        $this->assertSame('quarterly', $v['premium_interval']);

        // Maskierte Kunden-IBAN darf NICHT uebernommen werden.
        $this->assertSame([], $r['data']['bank']);
    }

    public function test_ignores_check24_protocol_and_unrelated_documents(): void
    {
        $protocol = "Vorlaeufiges Beratungsprotokoll zur Kfz-Versicherung - CHECK24\n"
            . "Gewaehlter Tarif: Allianz Direct Komfort";
        $this->assertNull((new AllianzKfzPoliceParser())->parse($protocol));

        $this->assertNull((new AllianzKfzPoliceParser())->parse('Irgendein anderes Dokument'));
        // DA-Direkt-Police darf NICHT von diesem Parser vereinnahmt werden.
        $this->assertNull((new AllianzKfzPoliceParser())->parse(
            "DA Direkt\nVersicherungsschein Nr. VSE/302.544.159/09\nKraftfahrtversicherung"
        ));
    }
}
