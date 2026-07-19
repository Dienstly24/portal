<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\KkhBeitrittserklaerungParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die KKH-Beitrittserklaerung. Die KKH-Formulare werden als
 * Bild-PDF hochgeladen und nach OCR per fester Regel gelesen - der Testtext
 * bildet das OCR-Layout nach (Wert steht UEBER der Beschriftung), mit
 * synthetischen Daten (keine echten Kundendaten).
 */
class KkhBeitrittserklaerungParserTest extends TestCase
{
    /** OCR-aehnlicher Text der KKH-Beitrittserklaerung (synthetisch). */
    private function kkhText(): string
    {
        return <<<TXT
        Beitrittserklärung
        Allgemeine Angaben zum Mitglied
        Mustermann Max Ali . R
        Verwandschaftsverhältnis zum Arbeitgeber ja
        Nachname Vorname
        Namenszusatz Vorsatzwort Art des Verwandschaftsverhältnisses
        01.02.1990 Aleppo Männlich . R
        Geburtsdatum Geburtsort Geschlecht Arbeitslosengeldbezieher
        Verheiratet Syrisch
        Familienstand Staatsangehörigkeit Agentur für Arbeit
        Teststraße 12
        Straße Hausnummer Straße Hausnummer
        12345 Berlin
        Postleitzahl Ort Bürgergeldbezieher
        30011990B042
        Sozialversicherungsnummer/Rentenversicherungsnummer Postleitzahl Ort
        B123456789
        Rentenbezieher
        Krankenversicherungsnummer
        01.09.2026 Beschäftigungsverhältnis
        Mitgliedschaftsbeginn Zum Zeitpunkt des gewünschten Beitritts im

        Zuletzt versichert

        TK Techniker Krankenkasse

        Vorversicherung

        KKH Kaufmännische Krankenkasse - 30125 Hannover - kkh.de
        TXT;
    }

    public function test_parses_kkh_beitrittserklaerung(): void
    {
        $result = (new KkhBeitrittserklaerungParser())->parse($this->kkhText());

        $this->assertNotNull($result);
        $this->assertSame('beitrittserklaerung', $result['type']);

        $p = $result['data']['person'];
        $this->assertSame('Mustermann', $p['last_name']);
        $this->assertSame('Max Ali', $p['first_name']);
        $this->assertSame('1990-02-01', $p['birth_date']);
        $this->assertSame('Aleppo', $p['birth_place']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('verheiratet', $p['marital_status']);
        $this->assertSame('Syrisch', $p['nationality']);
        $this->assertSame('Teststraße', $p['street']);
        $this->assertSame('12', $p['house_number']);
        $this->assertSame('12345', $p['zip']);
        $this->assertSame('Berlin', $p['city']);

        $g = $result['data']['gesundheit'];
        $this->assertSame('B123456789', $g['health_insurance_number']);
        $this->assertSame('30011990B042', $g['pension_number']);
        $this->assertSame('gesetzlich', $g['health_insurance_type']);
        $this->assertStringContainsString('KKH', $g['health_insurance_company']);
        $this->assertSame('TK Techniker Krankenkasse', $g['previous_insurer']);

        $v = $result['data']['versicherung'];
        $this->assertSame('krankenversicherung', $v['sparte']);
        $this->assertSame('2026-09-01', $v['start_date']);
    }

    public function test_extracts_leading_name_when_ocr_merges_right_column(): void
    {
        // Bei niedriger OCR-Aufloesung haengt die rechte Formularspalte an der
        // Namenszeile - nur die fuehrenden Namens-Tokens duerfen genommen werden.
        $text = "Beitrittserklärung\nKrankenversicherungsnummer\n"
            . "Alhamadeh Nouraddin Verwandschaftsverhältnis zum Arbeitgeber ja\n"
            . "Nachname Vorname\n"
            . "KKH Kaufmännische Krankenkasse - 30125 Hannover";

        $result = (new KkhBeitrittserklaerungParser())->parse($text);

        $this->assertNotNull($result);
        $this->assertSame('Alhamadeh', $result['data']['person']['last_name']);
        $this->assertSame('Nouraddin', $result['data']['person']['first_name']);
    }

    public function test_returns_null_for_non_kkh_document(): void
    {
        $this->assertNull((new KkhBeitrittserklaerungParser())->parse(
            "Irgendein anderes Dokument ohne die passenden Stichworte."
        ));
        // CHECK24-Protokoll ist NICHT die KKH-Beitrittserklaerung.
        $this->assertNull((new KkhBeitrittserklaerungParser())->parse(
            "BERATUNGSPROTOKOLL KFZ CHECK24 HSN/TSN: 1234/ABC"
        ));
    }
}
