<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\AntragBestaetigungParser;
use Tests\TestCase;

/**
 * Abschluss-Seite einer Online-Antragsstrecke ("Vielen Dank, Ihr Antrag ist
 * bei uns eingegangen"), als Screenshot hochgeladen. Sie traegt die
 * REFERENZNUMMER des Vorgangs - die Bruecke zu spaeterer Post - aber noch
 * keine Vertragsnummer. Synthetische Daten, gleicher Aufbau wie das Original.
 */
class AntragBestaetigungParserTest extends TestCase
{
    private function seiteText(array $ersetzungen = []): string
    {
        $text = implode("\n", [
            'Vielen Dank, Ihr Antrag ist bei uns',
            'eingegangen.',
            '',
            'Ihr Antrag wurde an die AdmiralDirekt übermittelt. Wir haben Ihnen eine',
            'Bestätigung an karim.muster@example.com gesendet.',
            '',
            'Referenznummer: 1477-6741-9200-53',
            'Versicherungsbeginn: Tag der Zulassung',
            '',
            'So geht es weiter',
            'Versicherung beantragt',
            'Ihr Antrag wurde an die AdmiralDirekt übermittelt, die eVB wurde ausgestellt.',
            'Auto zulassen',
            'Lassen Sie Ihr neues Auto mit der eVB bei der Zulassungsstelle zu.',
            'Versicherungsschein erhalten',
            'Sie erhalten Ihren Versicherungsschein automatisch von der AdmiralDirekt.',
            '',
            'Ihre eVB-Nummer für die Zulassung',
            '',
            'SHTC3HB',
        ]);

        return str_replace(array_keys($ersetzungen), array_values($ersetzungen), $text);
    }

    public function test_reads_reference_and_evb(): void
    {
        $r = (new AntragBestaetigungParser)->parse($this->seiteText());

        $this->assertNotNull($r);
        $this->assertSame('versicherungsvertrag', $r['type']);

        $v = $r['data']['versicherung'];
        // Die Referenznummer bekommt ihr EIGENES Feld - so findet spaetere
        // Post (Police, Abrechnung) denselben Vorgang wieder.
        $this->assertSame('1477-6741-9200-53', $v['reference_number']);
        // Aber sie ist NIE die Vertragsnummer.
        $this->assertArrayNotHasKey('contract_number', $v);
        $this->assertSame('antrag', $v['document_stage']);
        $this->assertSame('AdmiralDirekt', $v['insurer']);
        $this->assertSame('kfz', $v['sparte']);

        // "Tag der Zulassung" ist kein Datum - es wird nichts geraten.
        $this->assertArrayNotHasKey('start_date', $v);
        $this->assertStringContainsString('Tag der Zulassung', $r['summary']);
        $this->assertStringContainsString('wird nicht geraten', $r['summary']);

        // eVB nur als Hinweis (Zulassung), nie als Vertragsnummer.
        $this->assertStringContainsString('eVB-Nummer SHTC3HB', $r['summary']);

        $this->assertSame('karim.muster@example.com', $r['data']['person']['email']);
    }

    public function test_real_start_date_is_taken(): void
    {
        $r = (new AntragBestaetigungParser)->parse($this->seiteText([
            'Versicherungsbeginn: Tag der Zulassung' => 'Versicherungsbeginn: 01.09.2026',
        ]));

        $this->assertSame('2026-09-01', $r['data']['versicherung']['start_date']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $parser = new AntragBestaetigungParser;

        $this->assertNull($parser->parse('Irgendein anderes Dokument'));
        // Eine Police hat eine Vertragsnummer, aber keine Antrags-Bestaetigung.
        $this->assertNull($parser->parse(
            "Versicherungsschein\nVertragsnummer BH260738644\nandsafe AG"
        ));
    }
}
