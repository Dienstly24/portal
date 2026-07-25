<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\TemplateParsers\GeburtsurkundeParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer die deutsche Geburtsurkunde: liest das Kind (Hauptperson)
 * sowie Mutter und Vater aus den festen Abschnitten (Kind / 1. Mutter /
 * 2. Vater). Die Eltern kommen mit ihrer Rolle ("relation") in die
 * Personenliste - Grundlage der spaeteren Kind-Eltern-Verknuepfung.
 * Synthetische OCR-Daten, gleiche Struktur wie das Original.
 */
class GeburtsurkundeParserTest extends TestCase
{
    private function urkundeOcr(): string
    {
        return implode("\n", [
            'Geburtsurkunde',
            'Zur Beantragung von Elterngeld',
            'Standesamt          Fuerstenfeldbruck',
            'Registernummer      G 140/2020',
            'Ort, Tag der Geburt   Fuerstenfeldbruck, 26.02.2020',
            'Kind',
            'Geburtsname         Al Mansoer',
            'Vorname(n)          Kahlan Tariq Mohammed (Vorname und Vatersnamen)',
            'Geschlecht          maennlich',
            '1. Mutter',
            'Familienname        Al-Sewari',
            'Geburtsname',
            'Vorname(n)          Rasha Hussein Mohammed (Vorname und Vatersnamen)',
            '2. Vater',
            'Familienname        Al Mansoer',
            'Geburtsname',
            'Vorname(n)          Tariq Mohammed Abbas (Vorname und Vatersnamen)',
            'Ort, Tag            Fuerstenfeldbruck, 17.03.2020',
            'Urkundsperson       (Oswald, Standesbeamtin)',
        ]);
    }

    public function test_parses_child_and_parents(): void
    {
        $r = (new GeburtsurkundeParser())->parse($this->urkundeOcr());

        $this->assertNotNull($r);
        $this->assertSame('geburtsurkunde', $r['type']);

        // Kind = Hauptperson.
        $child = $r['data']['person'];
        $this->assertSame('Kahlan Tariq Mohammed', $child['first_name']);
        $this->assertSame('Al Mansoer', $child['last_name']);
        $this->assertSame('2020-02-26', $child['birth_date']);
        $this->assertSame('Fuerstenfeldbruck', $child['birth_place']);
        $this->assertSame('male', $child['gender']);

        // Eltern in der Personenliste, mit Rolle.
        $personen = $r['data']['personen'];
        $this->assertCount(2, $personen);

        $mutter = collect($personen)->firstWhere('relation', 'mutter');
        $this->assertNotNull($mutter);
        $this->assertSame('Rasha Hussein Mohammed', $mutter['first_name']);
        $this->assertSame('Al-Sewari', $mutter['last_name']);
        $this->assertSame('female', $mutter['gender']);

        $vater = collect($personen)->firstWhere('relation', 'vater');
        $this->assertNotNull($vater);
        $this->assertSame('Tariq Mohammed Abbas', $vater['first_name']);
        $this->assertSame('Al Mansoer', $vater['last_name']);
        $this->assertSame('male', $vater['gender']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new GeburtsurkundeParser())->parse('Irgendein anderes Dokument'));
    }
}
