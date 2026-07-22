<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\GewerbeBeratungsdokumentationParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die "Beratungsdokumentation" gewerblicher Versicherungen
 * (Frachtfuehrerhaftpflicht, Betriebs-/Inhaltsversicherung u.a.). Liest den
 * Kunden aus der LINKEN Spalte ("Vorschlag fuer:") - NICHT den Makler
 * ("Ansprechpartner") - sowie Versicherer/Produkt/Beitrag aus dem
 * Empfehlungs-Block.
 */
class GewerbeBeratungsdokumentationParserTest extends TestCase
{
    /** Baut eine Zeile mit linker + rechter Spalte (grosser Abstand). */
    private function twoCol(string $left, string $right): string
    {
        return str_pad($left, 44) . $right;
    }

    private function dokuText(): string
    {
        return implode("\n", [
            '21.07.2026',
            'Beratungsdokumentation Ihres',
            'Vermittlungsauftrags:',
            'Frachtführerhaftpflicht',
            $this->twoCol('Vorschlag für:', 'Ansprechpartner:'),
            $this->twoCol('Testfirma Transport Einzelunternehmen', 'Max Makler'),
            'Ahmed Testkunde',
            $this->twoCol('Ottobeurer Str. 1', 'Furtweg 51a'),
            $this->twoCol('87781 Ungerhausen', '22523 Hamburg'),
            $this->twoCol('', 'E-Mail: makler@dienstly24.de'),
            $this->twoCol('Beratungsdokumentation', 'Vorgangsnummer:'),
            $this->twoCol('Frachtführerhaftpflicht', '3114192'),
            $this->twoCol('Gewünschter Versicherungsbeginn', '22.07.2026'),
            ' Unsere Empfehlung',
            $this->twoCol(' Versicherer:', 'Helvetia Versicherungs-AG'),
            $this->twoCol(' Produkt:', 'Frachtführerhaftpflicht'),
            $this->twoCol(' Versicherungssumme:', '2,5 Mio. €'),
            $this->twoCol(' Zahlweise:', 'Jährlich'),
            $this->twoCol(' Selbstbehalt:', '300 €'),
            $this->twoCol(' Vertragslaufzeit in Jahren:', '1 Jahr'),
            $this->twoCol(' Prämie gemäß Zahlweise:', '892,50 € (Jährlich) inkl. Versicherungssteuer'),
            $this->twoCol(' Jahresprämie:', '892,50 € inkl. Versicherungssteuer'),
        ]);
    }

    public function test_reads_contract_from_recommendation_block(): void
    {
        $r = (new GewerbeBeratungsdokumentationParser())->parse($this->dokuText());

        $this->assertNotNull($r);
        $this->assertSame('beratungsprotokoll', $r['type']);

        $v = $r['data']['versicherung'];
        $this->assertSame('Helvetia Versicherungs-AG', $v['insurer']);
        $this->assertSame('haftpflicht', $v['sparte']);
        $this->assertSame('Frachtführerhaftpflicht', $v['tariff']);
        $this->assertSame(892.5, $v['premium_amount']);
        $this->assertSame('yearly', $v['premium_interval']);
        $this->assertSame('2026-07-22', $v['start_date']);
    }

    public function test_reads_customer_from_left_column_not_broker(): void
    {
        $p = (new GewerbeBeratungsdokumentationParser())->parse($this->dokuText())['data']['person'];

        // Kunde aus "Vorschlag fuer" (links), NICHT der Makler "Max Makler".
        $this->assertSame('Ahmed', $p['first_name']);
        $this->assertSame('Testkunde', $p['last_name']);
        $this->assertSame('Ottobeurer Str.', $p['street']);
        $this->assertSame('1', $p['house_number']);
        $this->assertSame('87781', $p['zip']);
        $this->assertSame('Ungerhausen', $p['city']);
        // Die Makler-E-Mail (info@dienstly24.de) darf NICHT als Kunde landen.
        $this->assertArrayNotHasKey('email', $p);
    }

    public function test_non_matching_document_is_ignored(): void
    {
        $this->assertNull((new GewerbeBeratungsdokumentationParser())->parse('Irgendein Dokument.'));
        // CHECK24-Kfz-Protokoll (anderes Format) nicht anfassen.
        $this->assertNull((new GewerbeBeratungsdokumentationParser())->parse(
            "Vorlaeufiges Beratungsprotokoll zur Kfz-Versicherung CHECK24"
        ));
    }
}
