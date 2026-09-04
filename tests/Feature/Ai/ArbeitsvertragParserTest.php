<?php

namespace Tests\Feature\Ai;

use App\Models\Document;
use App\Services\Ai\HeuristicDocumentClassifier;
use App\Services\Ai\TemplateParsers\ArbeitsvertragParser;
use Tests\TestCase;

/**
 * Gratis-Parser fuer den Arbeitsvertrag: aus dem genormten Vertragskopf
 * ("Zwischen <Firma> ... (im Folgenden: Arbeitgeber) und Herrn/Frau <Name>
 * ... (im Folgenden: Arbeitnehmer)") werden Arbeitgeber (Firmenname +
 * Anschrift), Arbeitnehmer (Name, Anschrift, Anrede) sowie Taetigkeit und
 * Beginn gelesen. Synthetischer OCR-Text nach einem echten Kundenbeispiel.
 */
class ArbeitsvertragParserTest extends TestCase
{
    private function contractOcr(): string
    {
        return implode("\n", [
            'Arbeitsvertrag',
            '',
            'Zwischen',
            '',
            'DF Bau GmbH',
            'vertreten durch',
            'DI Falco Domenico',
            'Beethovenstrasse 31, 66126 Saarbruecken',
            '(im Folgenden: Arbeitgeber)',
            '',
            'und',
            '',
            'Herrn Al Ali Mohammad',
            'Hochwaldstrasse 9, 66113 Saarbruecken',
            '(im Folgenden: Arbeitnehmer)',
            '',
            'werden folgende Vereinbarungen getroffen:',
            '',
            'Paragraf 1 Beginn des Arbeitsverhaeltnisses/Taetigkeit',
            'Der Arbeitnehmer wird mit Wirkung vom 06.07.2026 als Bauhelfer eingestellt.',
            '',
            'Paragraf Probezeit/Kuendigungsfristen',
            'Die Probezeit betraegt 6 Monate.',
        ]);
    }

    public function test_parses_employer_and_employee(): void
    {
        $r = (new ArbeitsvertragParser)->parse($this->contractOcr());

        $this->assertNotNull($r);
        $this->assertSame('arbeitsvertrag', $r['type']);

        $p = $r['data']['person'];
        // Arbeitgeber: Firmenname (Zeile mit Rechtsform) + Anschrift -
        // NICHT der Vertreter ("DI Falco Domenico").
        $this->assertSame('DF Bau GmbH', $p['employer_name']);
        $this->assertSame('Beethovenstrasse 31, 66126 Saarbruecken', $p['employer_address']);

        // Arbeitnehmer = Hauptperson (Anrede "Herrn" -> male; letztes
        // Namenswort als Nachname).
        $this->assertSame('Mohammad', $p['last_name']);
        $this->assertSame('Al Ali', $p['first_name']);
        $this->assertSame('male', $p['gender']);
        $this->assertSame('Hochwaldstrasse', $p['street']);
        $this->assertSame('9', $p['house_number']);
        $this->assertSame('66113', $p['zip']);
        $this->assertSame('Saarbruecken', $p['city']);

        // Taetigkeit + Beginn.
        $this->assertSame('Bauhelfer', $p['occupation']);
        $this->assertStringContainsString('Arbeitgeber DF Bau GmbH', $r['summary']);
        $this->assertStringContainsString('06.07.2026', $r['summary']);
    }

    public function test_frau_maps_female_gender(): void
    {
        $ocr = str_replace('Herrn Al Ali Mohammad', 'Frau Maria Muster', $this->contractOcr());
        $r = (new ArbeitsvertragParser)->parse($ocr);

        $this->assertNotNull($r);
        $this->assertSame('Muster', $r['data']['person']['last_name']);
        $this->assertSame('Maria', $r['data']['person']['first_name']);
        $this->assertSame('female', $r['data']['person']['gender']);
    }

    public function test_ignores_unrelated_documents(): void
    {
        $this->assertNull((new ArbeitsvertragParser)->parse('Irgendein anderes Dokument'));
        // Nur die Erwaehnung "Arbeitsvertrag" ohne Partei-Marker reicht nicht.
        $this->assertNull((new ArbeitsvertragParser)->parse("Anlage zum Arbeitsvertrag\nSonstiges"));
    }

    public function test_without_employer_and_employee_left_to_ai(): void
    {
        // Vertragskopf unlesbar (weder Arbeitgeber-Firma noch Arbeitnehmer-
        // Name gefunden) -> null, die normale Analyse (Heuristik/KI) laeuft.
        $r = (new ArbeitsvertragParser)->parse(implode("\n", [
            'Arbeitsvertrag',
            'zwischen dem Arbeitgeber und dem Arbeitnehmer',
            'Paragraf 3 Taetigkeit ...',
        ]));
        $this->assertNull($r);
    }

    public function test_type_is_registered_and_heuristic_classifies_it(): void
    {
        // Der Typ ist in der Whitelist (KI-Antwort/Heuristik duerfen ihn nutzen).
        $this->assertArrayHasKey('arbeitsvertrag', Document::AI_TYPES);

        // Auch der kostenlose OCR-Heuristik-Fallback erkennt den Typ.
        $r = (new HeuristicDocumentClassifier)->classify("ARBEITSVERTRAG\nZwischen ...");
        $this->assertNotNull($r);
        $this->assertSame('arbeitsvertrag', $r['type']);
    }
}
