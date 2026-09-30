<?php

namespace Tests\Feature\Ai;

use App\Models\Document;
use App\Services\Ai\ClaudeDocumentAiProvider;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\DocumentAnalyzer;
use App\Services\Ai\RelevantPageSelector;
use App\Services\Ai\TemplateParsers\AufenthaltstitelParser;
use App\Services\Ai\TemplateParsers\PersonalausweisParser;
use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Betreiber-Meldung 30.09.2026: "vom Personalausweis und vom
 * Aufenthaltstitel brauchen wir die Anschrift und den Geburtsort - erkannt
 * wird nur die Vorderseite".
 *
 * Mit Tesseract an den beiden eingesandten Fotos nachgemessen. Die MRZ kam
 * dort NIE fehlerfrei an, und jede einzelne Abweichung liess die ganze
 * Rueckseite durchfallen:
 *   "<<<<cccccccccee" statt Fuellzeichen, "«", Rauschen am Zeilenrand,
 *   "LILMT..." statt "L1LMT..." in der Dokumentennummer, "0<<" statt
 *   "D<<" (Deutschland), die Beschriftung "Anschrift" als "Arschrifi",
 *   "STRAßE" als "STRAGE" - und fuer den Personalausweis gab es gar keinen
 *   Parser.
 *
 * Die Texte unten tragen GENAU dieses Rauschen, aber keine echten Daten:
 * Personalausweis = amtliches Muster (Erika Mustermann, T22000129).
 */
class AusweiskartenRueckseiteTest extends TestCase
{
    use RefreshDatabase;

    /** Rueckseite Personalausweis, wie Tesseract sie am Foto lieferte. */
    private function personalausweisRueckseite(): string
    {
        return implode("\n", [
            'Anschrift/Addrens/Adrense',
            '}',
            'Größe/Height/Taille 51147 Koeln',
            '172 cm Muehlenbeker Chaussee 21',
            'Datum/Date/Date',
            '13.04.23',
            'Behörde/Authority/Autorité',
            'STADT KOELN',
            'DIE OBERBÜRGERMEISTERIN',
            'Ordens- oder Künstlername',
            '',
            'IDD<<T22000I29 3<<<<cccccccccee',
            '8308126<31080110<<2108<<<ccce4',
            'MUSTERMANN<<ERIKA<<<<ccceccccee',
        ]);
    }

    private function personalausweisVorderseite(): string
    {
        return implode("\n", [
            'BUNDESREPUBLIK DEUTSCHLAND   FEDERAL REPUBLIC OF GERMANY',
            'PERSONALAUSWEIS   IDENTITY CARD   CARTE D\'IDENTITE     T22000129',
            'Name/Surname/Nom',
            'MUSTERMANN',
            'Geburtsname/Name at birth/Nom de naissance',
            'GABLER',
            'Vornamen/Given names/Prénoms',
            'ERIKA',
            'Geburtsdatum/Date of birth   Staatsangehörigkeit/Nationality',
            '12.08.1983                   DEUTSCH',
            'Geburtsort/Place of birth/Lieu de naissance',
            'BERLIN',
            'Gültig bis/Date of expiry/Date d\'expiration',
            '01.08.2031                   123456',
        ]);
    }

    /** Rueckseite Aufenthaltstitel mit dem Rauschen des zweiten Fotos. */
    private function aufenthaltstitelRueckseite(): string
    {
        return implode("\n", [
            '1. ANMERKUNGEN/REMARKS 3. GEBURTSORT/PLACE OF BIRTH',
            'ERWERBSTAETIGKEIT ERLAUBT DEIR EZ-ZOR',
            'AUGENFARBE/EYE COLOUR',
            'BRAUN',
            'Arschrifi Adern',
            '66538 NEUNKIRCHEN',
            'INNENSTADT',
            'BAHNHOFSTRAGE 51',
            '2. AUSSTELLUNGSDATUM-BEHOERDE/',
            '11 07 2024 - ZAB Saarland',
            '‘ARD<<XK402PQRM1<<<ccccceee',
            '9507122M3004157SYR«<<<<<<<<<8|',
            'ALSAMIR<<OMAR<<<ccceccccee',
        ]);
    }

    public function test_rueckseite_des_personalausweises_trotz_ocr_rauschen(): void
    {
        $r = (new PersonalausweisParser)->parse($this->personalausweisRueckseite());

        $this->assertNotNull($r, 'Die Rueckseite des Personalausweises wurde nicht erkannt.');
        $this->assertSame('personalausweis', $r['type']);
        $p = $r['data']['person'];
        $this->assertSame('Mustermann', $p['last_name']);
        $this->assertSame('Erika', $p['first_name']);
        // Pruefziffern stimmen erst nach der Reparatur (c -> <, 0<< -> D<<).
        $this->assertSame('1983-08-12', $p['birth_date']);
        $this->assertSame('Deutschland', $p['nationality']);
        // "T22000I29" -> "T22000129": deutsche Nummern kennen kein I.
        $this->assertSame('T22000129', $p['id_number']);
        // Anschrift trotz verschmolzener Nachbarspalte ("172 cm ...").
        $this->assertSame('51147', $p['zip']);
        $this->assertSame('Koeln', $p['city']);
        $this->assertSame('Muehlenbeker Chaussee', $p['street']);
        $this->assertSame('21', $p['house_number']);
        $this->assertArrayNotHasKey('birth_place', $p);
        $this->assertStringContainsString('01.08.2031', $r['summary']);
    }

    public function test_behoerde_wird_nie_die_anschrift_der_person(): void
    {
        $r = (new PersonalausweisParser)->parse($this->personalausweisRueckseite());

        $this->assertStringNotContainsStringIgnoringCase('Stadt', $r['data']['person']['street']);
        $this->assertSame('Koeln', $r['data']['person']['city']);
    }

    public function test_vorderseite_des_personalausweises_liefert_den_geburtsort(): void
    {
        $r = (new PersonalausweisParser)->parse($this->personalausweisVorderseite());

        $this->assertNotNull($r);
        $this->assertSame('personalausweis', $r['type']);
        $p = $r['data']['person'];
        $this->assertSame('Mustermann', $p['last_name']);
        $this->assertSame('Erika', $p['first_name']);
        $this->assertSame('1983-08-12', $p['birth_date']);
        $this->assertSame('Berlin', $p['birth_place']);
        $this->assertSame('Deutschland', $p['nationality']);
        $this->assertSame('T22000129', $p['id_number']);
        // Der Geburtsname ist kein Nachname - er steht nur in der Zusammenfassung.
        $this->assertStringContainsString('Geburtsname Gabler', $r['summary']);
        $this->assertStringContainsString('RUECKSEITE', $r['summary']);
    }

    public function test_beide_seiten_in_einem_bild_ergeben_anschrift_und_geburtsort(): void
    {
        $r = (new PersonalausweisParser)->parse(
            $this->personalausweisVorderseite()."\n".$this->personalausweisRueckseite()
        );

        $this->assertNotNull($r);
        $p = $r['data']['person'];
        $this->assertSame('Berlin', $p['birth_place']);
        $this->assertSame('51147', $p['zip']);
        $this->assertSame('Muehlenbeker Chaussee', $p['street']);
    }

    public function test_rueckseite_des_aufenthaltstitels_trotz_ocr_rauschen(): void
    {
        $r = (new AufenthaltstitelParser)->parse($this->aufenthaltstitelRueckseite());

        $this->assertNotNull($r, 'Die Rueckseite des Aufenthaltstitels wurde nicht erkannt.');
        $this->assertSame('aufenthaltstitel', $r['type']);
        $p = $r['data']['person'];
        $this->assertSame('Alsamir', $p['last_name']);
        $this->assertSame('Omar', $p['first_name']);
        $this->assertSame('1995-07-12', $p['birth_date']);
        $this->assertSame('Syrien', $p['nationality']);
        $this->assertSame('Deir Ez-Zor', $p['birth_place']);
        // Beschriftung zerlegt ("Arschrifi") - die Anschrift kommt trotzdem.
        $this->assertSame('66538', $p['zip']);
        $this->assertSame('Neunkirchen', $p['city']);
        // "STRAGE" ist ein verlesenes "STRAßE".
        $this->assertSame('Bahnhofstraße', $p['street']);
        $this->assertSame('51', $p['house_number']);
    }

    public function test_verlesene_datenzeile_kostet_nur_das_geburtsdatum_nicht_die_anschrift(): void
    {
        $ohneDatenzeile = str_replace("9507122M3004157SYR«<<<<<<<<<8|\n", '', $this->aufenthaltstitelRueckseite());

        $r = (new AufenthaltstitelParser)->parse($ohneDatenzeile);

        $this->assertNotNull($r);
        $p = $r['data']['person'];
        $this->assertSame('Alsamir', $p['last_name']);
        $this->assertSame('Bahnhofstraße', $p['street']);
        $this->assertSame('Deir Ez-Zor', $p['birth_place']);
        // Nichts geraten.
        $this->assertArrayNotHasKey('birth_date', $p);
    }

    public function test_dokumentennummer_nur_mit_stimmiger_pruefziffer(): void
    {
        // Pruefziffer 8 statt 3: auch die Ruecksetzung I -> 1 passt nicht.
        $falsch = str_replace('T22000I29 3', 'T22000I29 8', $this->personalausweisRueckseite());

        $r = (new PersonalausweisParser)->parse($falsch);

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('id_number', $r['data']['person']);
    }

    public function test_jeder_parser_beansprucht_nur_seine_karte(): void
    {
        $this->assertNull((new AufenthaltstitelParser)->parse($this->personalausweisRueckseite()));
        $this->assertNull((new AufenthaltstitelParser)->parse($this->personalausweisVorderseite()));
        $this->assertNull((new PersonalausweisParser)->parse($this->aufenthaltstitelRueckseite()));
    }

    public function test_namenszeile_allein_ohne_kartenbeschriftung_ist_keine_rueckseite(): void
    {
        // Ein beliebiges Schreiben mit einer "<<"-Zeile ist kein Ausweis.
        $text = "Sehr geehrte Damen und Herren\nMUSTERMANN<<ERIKA<<<<<<<<<<<<<<\n12345 Musterstadt\nMusterweg 1";

        $this->assertNull((new PersonalausweisParser)->parse($text));
        $this->assertNull((new AufenthaltstitelParser)->parse($text));
    }

    public function test_die_echte_kette_aus_dem_container(): void
    {
        // Der Composite nimmt den ERSTEN Parser, der zugreift - kein anderer
        // darf die Kartenrueckseiten vorher beanspruchen.
        $kette = app(DocumentTemplateParser::class);

        $pa = $kette->parse($this->personalausweisRueckseite());
        $this->assertSame('personalausweis', $pa['type'] ?? null);
        $this->assertSame('51147', $pa['data']['person']['zip']);

        $vorn = $kette->parse($this->personalausweisVorderseite());
        $this->assertSame('personalausweis', $vorn['type'] ?? null);
        $this->assertSame('Berlin', $vorn['data']['person']['birth_place']);

        $eat = $kette->parse($this->aufenthaltstitelRueckseite());
        $this->assertSame('aufenthaltstitel', $eat['type'] ?? null);
        $this->assertSame('66538', $eat['data']['person']['zip']);
    }

    /**
     * Die Rueckseite wird fuer die ANSCHRIFT hochgeladen. Erkennt der Parser
     * die Karte, liest die Anschrift aber nicht (unscharfes Foto - genau so
     * am eingesandten Personalausweis gemessen), darf das Ergebnis nicht als
     * "fertig" gelten: dann liest die KI das Bild.
     */
    public function test_rueckseite_ohne_lesbare_anschrift_geht_an_die_ki(): void
    {
        $ohneAnschrift = str_replace(
            ['Größe/Height/Taille 51147 Koeln', '172 cm Muehlenbeker Chaussee 21'],
            ['Größe/Height/Taille 5114 Kln, bi', '"72 cm Muehlenbeker Chantvee'],
            $this->personalausweisRueckseite()
        );
        $ki = $this->kiMitAnschrift();

        $r = $this->analysieren($ohneAnschrift, $ki);

        $this->assertTrue($ki->called, 'Ohne Anschrift muss die KI das Bild lesen.');
        $this->assertSame('51147', $r['data']['person']['zip']);
        $this->assertArrayNotHasKey('pflichtangaben', $r);
    }

    public function test_rueckseite_mit_anschrift_kostet_keine_ki(): void
    {
        $ki = $this->kiMitAnschrift();

        $r = $this->analysieren($this->personalausweisRueckseite(), $ki);

        $this->assertFalse($ki->called);
        $this->assertSame('template', $r['source']);
        $this->assertSame('51147', $r['data']['person']['zip']);
        // Das Steuersignal wird nie gespeichert.
        $this->assertArrayNotHasKey('pflichtangaben', $r);
    }

    public function test_ki_ohne_anschrift_verdraengt_das_vorlagen_ergebnis_nicht(): void
    {
        $ohneAnschrift = str_replace('Anschrift/Addrens/Adrense', 'Anschrift', $this->personalausweisRueckseite());
        $ohneAnschrift = str_replace(['Größe/Height/Taille 51147 Koeln', '172 cm Muehlenbeker Chaussee 21'], ['', ''], $ohneAnschrift);
        $ki = $this->recordingProvider(['type' => 'sonstiges', 'confidence' => 30, 'summary' => '', 'title' => null, 'data' => []]);

        $r = $this->analysieren($ohneAnschrift, $ki);

        $this->assertTrue($ki->called);
        $this->assertSame('personalausweis', $r['type']);
        $this->assertSame('Mustermann', $r['data']['person']['last_name']);
    }

    public function test_vorderseite_ohne_geburtsort_geht_an_die_ki(): void
    {
        $ohneOrt = str_replace("\nBERLIN\n", "\n\n", $this->personalausweisVorderseite());
        $ki = $this->recordingProvider([
            'type' => 'personalausweis', 'confidence' => 90, 'summary' => 'ok', 'title' => null,
            'data' => ['person' => ['last_name' => 'Mustermann', 'birth_place' => 'Berlin']],
        ]);

        $r = $this->analysieren($ohneOrt, $ki);

        $this->assertTrue($ki->called);
        $this->assertSame('Berlin', $r['data']['person']['birth_place']);
    }

    public function test_der_ki_prompt_kennt_den_personalausweis(): void
    {
        // Genau diese KI liest das Bild, wenn der Parser die Anschrift bzw.
        // den Geburtsort nicht findet - ohne Anleitung hielt sie die
        // ausstellende Behoerde fuer die Anschrift.
        $prompt = (new \ReflectionMethod(ClaudeDocumentAiProvider::class, 'systemPrompt'))
            ->invoke(app(ClaudeDocumentAiProvider::class));

        $this->assertStringContainsString('PERSONALAUSWEIS', $prompt);
        $this->assertStringContainsString('GEBURTSORT (person.birth_place)', $prompt);
        $this->assertStringContainsString('AUSSTELLENDE BEHOERDE', $prompt);
    }

    /** @return array<string,mixed> */
    private function analysieren(string $ocrText, DocumentAiProviderInterface $ki): array
    {
        Storage::fake('local');
        Storage::disk('local')->put('docs/karte.jpg', 'bild');
        $dokument = Document::create([
            'customer_id' => null,
            'category' => 'other',
            'file_name' => 'karte.jpg',
            'file_path' => 'docs/karte.jpg',
            'disk' => 'local',
            'visibility' => 'staff',
            'ai_status' => 'pending',
        ]);

        $ocr = new class($ocrText) implements TextExtractorInterface {
            public function __construct(private string $text) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function extract(string $binary, string $mime): string
            {
                return $this->text;
            }
        };
        $keineTextebene = new class extends PdfTextLayerExtractor {
            public function __construct() {}

            public function isAvailable(): bool
            {
                return false;
            }

            public function extract(string $binary): string
            {
                return '';
            }
        };

        return (new DocumentAnalyzer($ki, $ocr, $keineTextebene, new RelevantPageSelector, app(DocumentTemplateParser::class)))
            ->analyze($dokument);
    }

    private function kiMitAnschrift(): DocumentAiProviderInterface
    {
        return $this->recordingProvider([
            'type' => 'personalausweis', 'confidence' => 90, 'summary' => 'ok', 'title' => null,
            'data' => ['person' => ['last_name' => 'Mustermann', 'first_name' => 'Erika', 'zip' => '51147', 'city' => 'Koeln']],
        ]);
    }

    private function recordingProvider(?array $antwort): DocumentAiProviderInterface
    {
        return new class($antwort) implements DocumentAiProviderInterface {
            public bool $called = false;

            public function __construct(private ?array $antwort) {}

            public function isEnabled(): bool
            {
                return true;
            }

            public function model(): string
            {
                return 'fake';
            }

            public function analyze(string $binary, string $mime, string $ocrText, bool $preferText = false): ?array
            {
                $this->called = true;

                return $this->antwort;
            }
        };
    }
}
