<?php

namespace Tests\Unit;

use App\Services\FondsFinanz\FondsFinanzSubjectParser;
use App\Support\MimeHeaderDecoder;
use PHPUnit\Framework\TestCase;

class FondsFinanzSubjectParserTest extends TestCase
{
    private FondsFinanzSubjectParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new FondsFinanzSubjectParser();
    }

    public function test_extracts_plain_full_name(): void
    {
        $data = $this->parser->parse('Fonds Finanz Info No. 2959197012 zum Kunden Al Ali Ahmad');
        $this->assertSame('Al Ali Ahmad', $data->customerName);
        $this->assertSame('2959197012', $data->fondsFinanzNumber);
        $this->assertNull($data->line);
    }

    public function test_reorders_lastname_firstname_and_strips_line_token(): void
    {
        $data = $this->parser->parse('Neues Dokument zum Kunden Alibrahim, Omar, Sach');
        $this->assertSame('Omar Alibrahim', $data->customerName);
        $this->assertSame('Sach', $data->line);
    }

    public function test_keeps_company_name_and_strips_line_token(): void
    {
        $data = $this->parser->parse('Neues Dokument zum Kunden Tiger Snacks, Sach');
        $this->assertSame('Tiger Snacks', $data->customerName);
        $this->assertSame('Sach', $data->line);
    }

    public function test_subject_without_customer_yields_nothing(): void
    {
        $data = $this->parser->parse('Bitte bestätigen Sie Ihre Angaben');
        $this->assertNull($data->customerName);
        $this->assertFalse($data->hasCustomer());
    }

    public function test_mime_encoded_subject_is_decoded(): void
    {
        $this->assertSame(
            'Bitte bestätigen Sie Ihre Angaben',
            MimeHeaderDecoder::decode('=?utf-8?Q?Bitte_best=C3=A4tigen_Sie_Ihre_Angaben?=')
        );
    }

    public function test_plain_subject_is_left_untouched(): void
    {
        $this->assertSame('Neue Vertragsinformation', MimeHeaderDecoder::decode('Neue Vertragsinformation'));
    }
}
