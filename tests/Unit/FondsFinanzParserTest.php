<?php

namespace Tests\Unit;

use App\Services\FondsFinanz\FondsFinanzParser;
use PHPUnit\Framework\TestCase;

class FondsFinanzParserTest extends TestCase
{
    private FondsFinanzParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new FondsFinanzParser();
    }

    public function test_parses_complete_structured_notification(): void
    {
        $data = $this->parser->parse(implode("\n", [
            'Sehr geehrte Damen und Herren,',
            '',
            'Kunde: Max Mustermann',
            'Geburtsdatum: 12.04.1988',
            'Gesellschaft: Allianz Versicherungs-AG',
            'Sparte: Kfz',
            'Produkt: Kfz-Haftpflicht Komfort',
            'Vertragsnummer: AZ-123456789',
            'Dokumentnummer: DOC-2026-0815',
            'Vorgangsnummer: FF-778899',
        ]));

        $this->assertSame('Max Mustermann', $data->customerName);
        $this->assertSame('1988-04-12', $data->birthDate);
        $this->assertSame('Allianz Versicherungs-AG', $data->company);
        $this->assertSame('Kfz', $data->line);
        $this->assertSame('Kfz-Haftpflicht Komfort', $data->product);
        $this->assertSame('AZ-123456789', $data->contractNumber);
        $this->assertSame('DOC-2026-0815', $data->documentNumber);
        $this->assertSame('FF-778899', $data->fondsFinanzNumber);
        $this->assertTrue($data->isImportable());
    }

    public function test_accepts_label_variants(): void
    {
        $data = $this->parser->parse(implode("\n", [
            'Versicherungsnehmer: Erika Musterfrau',
            'Versicherungsschein-Nr: VS-42',
            'Versicherer: HUK-COBURG',
        ]));

        $this->assertSame('Erika Musterfrau', $data->customerName);
        $this->assertSame('VS-42', $data->contractNumber);
        $this->assertSame('HUK-COBURG', $data->company);
    }

    public function test_unknown_layout_is_not_importable(): void
    {
        $data = $this->parser->parse('Hallo, hier ist ein völlig unstrukturierter Text ohne Labels.');

        $this->assertNull($data->customerName);
        $this->assertNull($data->contractNumber);
        $this->assertFalse($data->isImportable());
    }

    public function test_contract_number_alone_is_not_importable(): void
    {
        $data = $this->parser->parse('Vertragsnummer: X-1');

        $this->assertFalse($data->isImportable());
    }

    public function test_invalid_birth_date_is_discarded_not_guessed(): void
    {
        $data = $this->parser->parse("Kunde: Max Mustermann\nGeburtsdatum: irgendwann 1988\nVertragsnummer: X-1");

        $this->assertNull($data->birthDate);
        $this->assertTrue($data->isImportable());
    }

    public function test_first_occurrence_of_a_field_wins(): void
    {
        $data = $this->parser->parse("Vertragsnummer: A-1\nVertragsnummer: B-2\nKunde: Max");

        $this->assertSame('A-1', $data->contractNumber);
    }
}
