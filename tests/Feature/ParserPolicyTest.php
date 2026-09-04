<?php

namespace Tests\Feature;

use App\Services\Ai\Concerns\ValidatesExtractedFields;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\TemplateParsers\CompositeDocumentTemplateParser;
use App\Services\Ai\TemplateParsers\VermittlerVorgangslisteHinweisParser;
use Tests\TestCase;

/**
 * ARCH-8: die Parser-Strategie als pruefbare Regel statt als Absichtserklaerung.
 *
 * Begruendung und Entscheidungsregel stehen in
 * docs/ARCHITEKTUR_PARSER_STRATEGIE.md. Hier steht der Teil davon, den eine
 * Maschine halten kann - denn eine Richtlinie, die nur in einem Dokument
 * steht, wird beim 44. Parser nicht gelesen.
 */
class ParserPolicyTest extends TestCase
{
    /** @return array<int, class-string> */
    private function parserKlassen(): array
    {
        $klassen = [];
        foreach (glob(app_path('Services/Ai/TemplateParsers/*.php')) as $datei) {
            $klasse = 'App\\Services\\Ai\\TemplateParsers\\'.basename($datei, '.php');
            if (class_exists($klasse) && is_subclass_of($klasse, DocumentTemplateParser::class)) {
                $klassen[] = $klasse;
            }
        }

        return $klassen;
    }

    /**
     * Der Kern der Regel: JEDE Quelle muendet in denselben Trichter.
     *
     * Genau das war die Lehre vom 28.08.2026 - die Trennregel fuer
     * Strasse/Hausnummer lag in jedem Parser einzeln, die KI-Antwort hatte
     * sie gar nicht, und in der Kundenakte fehlte die Hausnummer. Ein
     * Parser, der den gemeinsamen Baustein umgeht, ist deshalb kein
     * Geschmacksunterschied, sondern ein Loch.
     */
    public function test_jeder_feldliefernde_parser_nutzt_die_gemeinsame_pruefung(): void
    {
        // Ausgenommen: der Composite verteilt nur, und der Hinweis-Parser
        // erkennt eine Vermittler-Vorgangsliste, ohne Felder zu liefern.
        $ohneFelder = [
            CompositeDocumentTemplateParser::class,
            VermittlerVorgangslisteHinweisParser::class,
        ];

        foreach ($this->parserKlassen() as $klasse) {
            if (in_array($klasse, $ohneFelder, true)) {
                continue;
            }

            $this->assertContains(
                ValidatesExtractedFields::class,
                class_uses($klasse) ?: [],
                $klasse.' umgeht die gemeinsame Feldpruefung. Regeln wie die IBAN-Pruefziffer '
                .'oder die Trennung von Strasse und Hausnummer gelten dann fuer diesen Parser nicht - '
                .'siehe docs/ARCHITEKTUR_PARSER_STRATEGIE.md.'
            );
        }
    }

    /**
     * Ein nicht registrierter Parser ist toter Code, der wie ein Feature
     * aussieht: er liegt im Verzeichnis, laeuft aber nie.
     */
    public function test_jeder_parser_ist_registriert(): void
    {
        $registriert = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        foreach ($this->parserKlassen() as $klasse) {
            if ($klasse === CompositeDocumentTemplateParser::class) {
                continue;
            }
            $kurz = class_basename($klasse);
            $this->assertStringContainsString(
                $kurz,
                $registriert,
                $kurz.' ist nicht im AppServiceProvider registriert und laeuft deshalb nie.'
            );
        }
    }

    /**
     * Ein Parser ohne Test ist ein Versprechen ohne Deckung - und er laeuft
     * ab seiner Registrierung auf JEDEM hochgeladenen Dokument mit.
     */
    public function test_jeder_parser_hat_eine_testabdeckung(): void
    {
        $tests = implode("\n", array_map(
            'file_get_contents',
            array_merge(
                glob(base_path('tests/Feature/**/*.php')),
                glob(base_path('tests/Feature/*.php')),
                glob(base_path('tests/Unit/**/*.php')),
                glob(base_path('tests/Unit/*.php')),
            )
        ));

        $ohne = [];
        foreach ($this->parserKlassen() as $klasse) {
            $kurz = class_basename($klasse);
            if (! str_contains($tests, $kurz)) {
                $ohne[] = $kurz;
            }
        }

        $this->assertSame(
            [],
            $ohne,
            'Diese Parser werden von keinem Test erwaehnt: '.implode(', ', $ohne)
            .'. Sie laufen trotzdem auf jedem Dokument mit - ein Fehlalarm haelt dann '
            .'ein echtes Kundendokument von seiner Akte fern.'
        );
    }

    /**
     * Der Composite darf an einem kaputten Parser nicht scheitern: alle
     * Parser laufen auf jedem Text, und ein Wurf wuerde das Dokument auf
     * 'failed' setzen und sogar die KI-Eskalation verhindern.
     */
    public function test_ein_kaputter_parser_stoppt_die_analyse_nicht(): void
    {
        $kaputt = new class implements DocumentTemplateParser {
            public function parse(string $text): ?array
            {
                throw new \RuntimeException('absichtlich kaputt');
            }
        };

        $treffer = new class implements DocumentTemplateParser {
            public function parse(string $text): ?array
            {
                return ['type' => 'test', 'confidence' => 90, 'summary' => 'ok', 'title' => null, 'data' => []];
            }
        };

        $composite = new CompositeDocumentTemplateParser([$kaputt, $treffer]);

        $this->assertSame('test', $composite->parse('irgendein Text')['type']);
    }
}
