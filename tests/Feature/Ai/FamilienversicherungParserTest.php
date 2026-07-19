<?php

namespace Tests\Feature\Ai;

use App\Models\Document;
use App\Services\Ai\TemplateParsers\FamilienversicherungParser;
use App\Services\Health\FamilyBundleService;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den Fragebogen zur Familienversicherung: liest das
 * Mitglied UND die Angehoerigen (Ehegatte + Kinder). Fuer Kranken/Familie
 * werden die Angehoerigen bewusst mitgelesen (die "keine Kinder"-Regel gilt
 * nur fuer Kfz). Testtext bildet die Spaltentabelle nach (synthetische Daten).
 */
class FamilienversicherungParserTest extends TestCase
{
    private function famText(): string
    {
        // Achtung: Spaltenstruktur (2+ Leerzeichen) wie in der PDF-Textebene.
        return implode("\n", [
            'Fragebogen zur Familienversicherung',
            '                                          Max Mustermann',
            '                                          Vorname Name des Mitglieds',
            '     Beginn der Familienversicherung: 01.04.2026',
            '                                          Ehegatte          Kind',
            'Vorname                                   Anna              Ben',
            'Geburtsdatum                              02.03.1990 15.06.2015',
            'Geburtsort                                Berlin            Hamburg',
        ]);
    }

    public function test_parses_member_and_relatives(): void
    {
        $result = (new FamilienversicherungParser())->parse($this->famText());

        $this->assertNotNull($result);
        $this->assertSame('familienversicherung', $result['type']);

        $this->assertSame('Max', $result['data']['person']['first_name']);
        $this->assertSame('Mustermann', $result['data']['person']['last_name']);

        $personen = $result['data']['personen'];
        $this->assertCount(2, $personen);
        $this->assertSame('Anna', $personen[0]['first_name']);
        $this->assertSame('Mustermann', $personen[0]['last_name']); // Nachname des Mitglieds
        $this->assertSame('1990-03-02', $personen[0]['birth_date']);
        $this->assertSame('Berlin', $personen[0]['birth_place']);
        $this->assertSame('Ben', $personen[1]['first_name']);
        $this->assertSame('2015-06-15', $personen[1]['birth_date']);
        $this->assertSame('Hamburg', $personen[1]['birth_place']);

        $this->assertSame('krankenversicherung', $result['data']['versicherung']['sparte']);
        $this->assertSame('2026-04-01', $result['data']['versicherung']['start_date']);
    }

    public function test_detected_persons_include_member_and_relatives(): void
    {
        // Der bestehende Kranken-Familien-Workflow (detectPersons) muss Mitglied
        // + Angehoerige sehen -> Haupt-Frage + familienversichert.
        $data = (new FamilienversicherungParser())->parse($this->famText())['data'];
        $doc = new Document(['ai_extracted' => $data]);

        $persons = app(FamilyBundleService::class)->detectPersons([$doc]);

        $this->assertCount(3, $persons); // Max + Anna + Ben
        $names = array_map(fn ($p) => $p['first_name'] ?? null, $persons);
        $this->assertContains('Max', $names);
        $this->assertContains('Anna', $names);
        $this->assertContains('Ben', $names);
    }

    public function test_returns_null_for_non_family_document(): void
    {
        $this->assertNull((new FamilienversicherungParser())->parse(
            "Beitrittserklärung KKH Krankenversicherungsnummer A004167047"
        ));
        $this->assertNull((new FamilienversicherungParser())->parse("Irgendein Text"));
    }
}
