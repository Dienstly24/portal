<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\ReisepassMrzParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den Reisepass ueber die maschinenlesbare Zone (MRZ,
 * ICAO 9303 TD3). Dekodiert Nachname, Vorname, Passnummer, Staatsangehoerigkeit,
 * Geburtsdatum, Geschlecht und Ablauf exakt aus den zwei MRZ-Zeilen - hier am
 * Beispiel eines syrischen Passes. Faellt der MRZ-Name aus, greift die VIZ-
 * Beschriftung (Surname/Name).
 */
class ReisepassMrzParserTest extends TestCase
{
    private function passportOcr(): string
    {
        return implode("\n", [
            'SYRIAN ARAB REPUBLIC',
            'Type PN   Country Code SYR   Passport No N02558396',
            'Name SAFA',
            'Surname KUTAISH',
            'Date of Birth 05/02/2001   Sex F   Place of Birth DAMASCUS',
            'Date of Expiry 14/06/2031   Date of Issue 15/06/2025',
            'PNSYRKUTAISH<<SAFA<<<<<<<<<<<<<<<<<<<<<<<<<<',
            'N02558396 5SYR0102052F310614314010088064<<06',
        ]);
    }

    public function test_decodes_mrz_of_syrian_passport(): void
    {
        $r = (new ReisepassMrzParser())->parse($this->passportOcr());

        $this->assertNotNull($r);
        $this->assertSame('reisepass', $r['type']);

        $p = $r['data']['person'];
        $this->assertSame('Kutaish', $p['last_name']);
        $this->assertSame('Safa', $p['first_name']);
        $this->assertSame('2001-02-05', $p['birth_date']);
        $this->assertSame('female', $p['gender']);
        $this->assertSame('Syrien', $p['nationality']); // SYR -> Land
        $this->assertSame('N02558396', $p['id_number']); // Passnummer
        // Ablauf in der Zusammenfassung.
        $this->assertStringContainsString('14.06.2031', $r['summary']);
    }

    public function test_falls_back_to_visible_labels_when_mrz_name_is_lost(): void
    {
        // OCR hat die "<"-Zeichen der Namenszeile zerstoert -> Name aus VIZ.
        $ocr = str_replace(
            'PNSYRKUTAISH<<SAFA<<<<<<<<<<<<<<<<<<<<<<<<<<',
            'PNSYRKUTAISHKKSAFAKKKKKKKKKKKKKKKKKKKKKKKKKK',
            $this->passportOcr()
        );
        $r = (new ReisepassMrzParser())->parse($ocr);

        $this->assertNotNull($r);
        // Datenzeile (Zeile 2) liefert weiterhin Passnummer/Geburtsdatum/Sex.
        $this->assertSame('N02558396', $r['data']['person']['id_number']);
        $this->assertSame('2001-02-05', $r['data']['person']['birth_date']);
        // Name aus den sichtbaren Beschriftungen "Surname"/"Name".
        $this->assertSame('Kutaish', $r['data']['person']['last_name']);
        $this->assertSame('Safa', $r['data']['person']['first_name']);
    }

    public function test_ignores_documents_without_mrz(): void
    {
        $this->assertNull((new ReisepassMrzParser())->parse('Irgendein anderes Dokument ohne MRZ'));
        $this->assertNull((new ReisepassMrzParser())->parse("Reisepass\nName Muster\nkeine maschinenlesbare Zone"));
    }
}
