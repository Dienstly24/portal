<?php

namespace Tests\Feature\Ai;

use App\Models\Document;
use App\Services\Ai\HeuristicDocumentClassifier;
use App\Services\Ai\TemplateParsers\AufenthaltstitelParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den deutschen elektronischen Aufenthaltstitel (eAT) aus
 * der OCR-Textebene eines EINZELNEN Kartenfotos. Ein Foto mit MEHREREN Karten
 * (Familie) wird bewusst der KI-Vision ueberlassen (Parser -> null), damit nie
 * faelschlich nur eine Person aus einem Familien-Foto entsteht. Synthetische
 * OCR-Texte, gleiche feste Beschriftungen wie auf der Karte.
 */
class AufenthaltstitelParserTest extends TestCase
{
    private function singleCardOcr(): string
    {
        return implode("\n", [
            'D  AUFENTHALTSTITEL                         YZ119CMFH',
            'YZ119CMFH',
            'NAMEN Vornamen/SURNAMES Forenames',
            'MUSTAFA',
            'Mustafa',
            'GESCHLECHT/ STAATSANGEHOERIGKEIT/ GEBURTSDATUM/',
            'SEX        NATIONALITY            DATE OF BIRTH',
            'M          IRQ                    28 03 1987',
            'ART DES TITELS/TYPE OF PERMIT   KARTE GUELTIG BIS/CARD EXPIRY',
            'AUFENTHALTSERLAUBNIS            02 07 2027',
            'ANMERKUNGEN/REMARKS',
            '25 ABS.3',
            '904962',
            'RESIDENCE PERMIT',
        ]);
    }

    public function test_parses_single_residence_permit(): void
    {
        $r = (new AufenthaltstitelParser())->parse($this->singleCardOcr());

        $this->assertNotNull($r);
        $this->assertSame('aufenthaltstitel', $r['type']);

        $p = $r['data']['person'];
        // Nachname aus Grossbuchstaben "MUSTAFA" normalisiert zu "Mustafa".
        $this->assertSame('Mustafa', $p['last_name']);
        $this->assertSame('Mustafa', $p['first_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('1987-03-28', $p['birth_date']);
        // Laendercode IRQ -> Land Irak.
        $this->assertSame('Irak', $p['nationality']);
        $this->assertSame('YZ119CMFH', $p['id_number']);

        // Ablaufdatum in der Zusammenfassung sichtbar.
        $this->assertStringContainsString('02.07.2027', $r['summary']);
    }

    public function test_female_permit_maps_gender(): void
    {
        $ocr = str_replace(
            ['MUSTAFA', 'Mustafa', 'M          IRQ', '28 03 1987'],
            ['MAHMOOD', 'Baraka Daham Mahmood', 'F          IRQ', '30 11 1992'],
            $this->singleCardOcr()
        );
        $r = (new AufenthaltstitelParser())->parse($ocr);

        $this->assertNotNull($r);
        $this->assertSame('Mahmood', $r['data']['person']['last_name']);
        $this->assertSame('Baraka Daham Mahmood', $r['data']['person']['first_name']);
        $this->assertSame('female', $r['data']['person']['gender']);
        $this->assertSame('1992-11-30', $r['data']['person']['birth_date']);
    }

    public function test_multi_card_family_photo_is_left_to_ai(): void
    {
        // Zwei Karten im selben OCR-Text -> null (KI-Vision buendelt die Familie).
        $twoCards = $this->singleCardOcr() . "\n" . str_replace(
            ['MUSTAFA', 'Mustafa', '28 03 1987'],
            ['MAHMOOD', 'Baraka', '30 11 1992'],
            $this->singleCardOcr()
        );
        $this->assertNull((new AufenthaltstitelParser())->parse($twoCards));
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new AufenthaltstitelParser())->parse('Irgendein anderes Dokument'));
        // Personalausweis ist ein eigener Typ, nicht dieser Parser.
        $this->assertNull((new AufenthaltstitelParser())->parse("BUNDESREPUBLIK DEUTSCHLAND\nPERSONALAUSWEIS\nMuster"));
    }

    public function test_type_is_registered_and_heuristic_classifies_it(): void
    {
        // Der Typ ist in der Whitelist (KI-Antwort/Heuristik duerfen ihn nutzen).
        $this->assertArrayHasKey('aufenthaltstitel', Document::AI_TYPES);
        $this->assertSame('identity', Document::AI_TYPES['aufenthaltstitel']['category']);

        // Auch der kostenlose OCR-Heuristik-Fallback erkennt den Typ.
        $r = (new HeuristicDocumentClassifier())->classify("D AUFENTHALTSTITEL\nAUFENTHALTSERLAUBNIS\n25 ABS.3");
        $this->assertNotNull($r);
        $this->assertSame('aufenthaltstitel', $r['type']);
    }
}
