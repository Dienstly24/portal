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
        $r = (new AufenthaltstitelParser)->parse($this->singleCardOcr());

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
        $r = (new AufenthaltstitelParser)->parse($ocr);

        $this->assertNotNull($r);
        $this->assertSame('Mahmood', $r['data']['person']['last_name']);
        $this->assertSame('Baraka Daham Mahmood', $r['data']['person']['first_name']);
        $this->assertSame('female', $r['data']['person']['gender']);
        $this->assertSame('1992-11-30', $r['data']['person']['birth_date']);
    }

    public function test_multi_card_family_photo_is_left_to_ai(): void
    {
        // Zwei Karten im selben OCR-Text -> null (KI-Vision buendelt die Familie).
        $twoCards = $this->singleCardOcr()."\n".str_replace(
            ['MUSTAFA', 'Mustafa', '28 03 1987'],
            ['MAHMOOD', 'Baraka', '30 11 1992'],
            $this->singleCardOcr()
        );
        $this->assertNull((new AufenthaltstitelParser)->parse($twoCards));
    }

    /**
     * Rueckseite der Karte (wie ein echtes Kundenfoto): keine Vorderseiten-
     * Beschriftungen, dafuer TD1-MRZ (drei Zeilen), Anschrift-Aufkleber und
     * Geburtsort. Pruefziffern des Beispiels sind ICAO-korrekt.
     */
    private function backSideOcr(): string
    {
        return implode("\n", [
            '1. ANMERKUNGEN/REMARKS          3. GEBURTSORT/PLACE OF BIRTH',
            'ERWERBSTAETIGKEIT ERLAUBT       DEIR EZZOR',
            'SIEHE ZUSATZBLATT',
            'AUGENFARBE/EYE COLOUR',
            'BRAUN',
            'GROESSE/HEIGHT',
            '175cm',
            '2. AUSSTELLUNGSDATUM-BEHOERDE/',
            'DATE OF ISSUE - AUTHORITY',
            '11 07 2024 - ZAB Saarland',
            'Anschrift/Address/Adresse       YZ96LLV6N',
            '66113 Saarbruecken',
            'Hochwaldstrasse 9',
            'ARD<<YZ96LLV6N6<<<<<<<<<<<<<<<',
            '0503235M2707107SYR<<<<<<<<<<<2',
            'ALALI<<MOHAMMAD<<<<<<<<<<<<<<<',
        ]);
    }

    public function test_parses_back_side_via_mrz(): void
    {
        $r = (new AufenthaltstitelParser)->parse($this->backSideOcr());

        $this->assertNotNull($r);
        $this->assertSame('aufenthaltstitel', $r['type']);

        $p = $r['data']['person'];
        // Name aus MRZ-Zeile 3 (NACHNAME<<VORNAMEN).
        $this->assertSame('Alali', $p['last_name']);
        $this->assertSame('Mohammad', $p['first_name']);
        // Geburtsdatum/Geschlecht/Staat aus der MRZ-Datenzeile.
        $this->assertSame('2005-03-23', $p['birth_date']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('Syrien', $p['nationality']);
        // Dokumentennummer aus MRZ-Zeile 1 (Pruefziffer-validiert).
        $this->assertSame('YZ96LLV6N', $p['id_number']);
        // Anschrift-Aufkleber -> strukturierte Adresse.
        $this->assertSame('Hochwaldstrasse', $p['street']);
        $this->assertSame('9', $p['house_number']);
        $this->assertSame('66113', $p['zip']);
        $this->assertSame('Saarbruecken', $p['city']);
        // Geburtsort aus der GEBURTSORT-Spalte.
        $this->assertSame('Deir Ezzor', $p['birth_place']);

        // Ablauf (MRZ) in der Zusammenfassung sichtbar.
        $this->assertStringContainsString('10.07.2027', $r['summary']);
    }

    public function test_birth_place_survives_single_space_column_merge(): void
    {
        // Reale OCR-Ausgabe verschmilzt die beiden Spalten teils mit nur
        // EINEM Leerzeichen - der Ort steht dann hinter dem Anmerkungs-Text.
        $ocr = str_replace(
            [
                '1. ANMERKUNGEN/REMARKS          3. GEBURTSORT/PLACE OF BIRTH',
                'ERWERBSTAETIGKEIT ERLAUBT       DEIR EZZOR',
            ],
            [
                '1. ANMERKUNGEN/REMARKS 3. GEBURTSORT/PLACE OF BIRTH',
                'ERWERBSTAETIGKEIT ERLAUBT DEIR EZZOR',
            ],
            $this->backSideOcr()
        );
        $r = (new AufenthaltstitelParser)->parse($ocr);

        $this->assertNotNull($r);
        $this->assertSame('Deir Ezzor', $r['data']['person']['birth_place']);
    }

    public function test_birth_place_on_label_line(): void
    {
        // Ort direkt hinter der Beschriftung in derselben Zeile.
        $ocr = str_replace(
            [
                '1. ANMERKUNGEN/REMARKS          3. GEBURTSORT/PLACE OF BIRTH',
                'ERWERBSTAETIGKEIT ERLAUBT       DEIR EZZOR',
            ],
            [
                '3. GEBURTSORT/PLACE OF BIRTH DEIR EZZOR',
                'ERWERBSTAETIGKEIT ERLAUBT',
            ],
            $this->backSideOcr()
        );
        $r = (new AufenthaltstitelParser)->parse($ocr);

        $this->assertNotNull($r);
        $this->assertSame('Deir Ezzor', $r['data']['person']['birth_place']);
    }

    public function test_back_side_without_readable_birth_place_leaves_it_empty(): void
    {
        // Nur Anmerkungs-Text, kein Ort -> Feld bleibt leer statt falsch.
        $ocr = str_replace('DEIR EZZOR', '', $this->backSideOcr());
        $r = (new AufenthaltstitelParser)->parse($ocr);

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('birth_place', $r['data']['person']);
    }

    public function test_back_side_with_broken_check_digit_drops_field(): void
    {
        // Geburtsdatum-Pruefziffer kaputt (OCR-Zahlendreher) -> das Datum
        // wird verworfen, die uebrigen Felder bleiben.
        $ocr = str_replace('0503235M', '0503234M', $this->backSideOcr());
        $r = (new AufenthaltstitelParser)->parse($ocr);

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('birth_date', $r['data']['person']);
        $this->assertSame('Alali', $r['data']['person']['last_name']);
    }

    public function test_two_back_sides_are_left_to_ai(): void
    {
        // Zwei Karten-Rueckseiten in einem Foto -> null (KI ordnet zu).
        $two = $this->backSideOcr()."\n".str_replace(
            ['ALALI<<MOHAMMAD', '0503235M'],
            ['MAHMOOD<<BARAKA', '9211305F'],
            $this->backSideOcr()
        );
        $this->assertNull((new AufenthaltstitelParser)->parse($two));
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new AufenthaltstitelParser)->parse('Irgendein anderes Dokument'));
        // Personalausweis ist ein eigener Typ, nicht dieser Parser.
        $this->assertNull((new AufenthaltstitelParser)->parse("BUNDESREPUBLIK DEUTSCHLAND\nPERSONALAUSWEIS\nMuster"));
    }

    public function test_type_is_registered_and_heuristic_classifies_it(): void
    {
        // Der Typ ist in der Whitelist (KI-Antwort/Heuristik duerfen ihn nutzen).
        $this->assertArrayHasKey('aufenthaltstitel', Document::AI_TYPES);
        $this->assertSame('identity', Document::AI_TYPES['aufenthaltstitel']['category']);

        // Auch der kostenlose OCR-Heuristik-Fallback erkennt den Typ.
        $r = (new HeuristicDocumentClassifier)->classify("D AUFENTHALTSTITEL\nAUFENTHALTSERLAUBNIS\n25 ABS.3");
        $this->assertNotNull($r);
        $this->assertSame('aufenthaltstitel', $r['type']);
    }
}
