<?php

namespace Tests\Feature\DocumentIntake;

use App\Jobs\AnalyzeDocumentJob;
use App\Models\Contract;
use App\Models\ContractVehicleDetail;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ai\Contracts\DocumentTemplateParser;
use App\Services\Ai\DocumentAnalyzer;
use App\Services\Ai\RelevantPageSelector;
use App\Services\Ai\TemplateParsers\FahrzeugscheinParser;
use App\Services\DocumentIntake\DocumentIntakeService;
use App\Services\Ocr\PdfTextLayerExtractor;
use App\Services\Ocr\TextExtractorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Betreiber-Wunsch 23.11.2026 (KI-130): "wenn ich den Fahrzeugschein schicke,
 * soll er Kunde und Vertrag ueber Kennzeichen oder FIN finden und die Daten
 * ins System bringen". Die Fixtures sind Tesseract-Ausgaben einer
 * Chromium-Nachbildung des Scheins mit ERFUNDENEN Daten - einmal als PNG
 * (dort fehlt die FIN), einmal JPEG-komprimiert (Spalten vermischt).
 */
class FahrzeugscheinZuordnungTest extends TestCase
{
    use RefreshDatabase;

    private const VIN = 'ZFA25000001234567';

    private function ocr(string $variante): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/fahrzeugschein_ocr_'.$variante.'.txt'));
    }

    private function kunde(string $name): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => Str::slug($name).Str::random(4).'@example.com']);

        return Customer::create(['user_id' => $user->id, 'customer_number' => (string) random_int(2600000, 2699999)]);
    }

    private function kfzVertrag(Customer $kunde, array $fahrzeug): Contract
    {
        $c = Contract::create([
            'customer_id' => $kunde->id, 'type' => 'kfz', 'insurer' => 'Itzehoer Versicherungen',
            'status' => 'active', 'contract_number' => 'T-'.random_int(1000, 9999),
        ]);
        ContractVehicleDetail::create(['contract_id' => $c->id] + $fahrzeug);

        return $c;
    }

    private function analysiere(string $text): Document
    {
        Storage::fake('local');
        $path = 'documents/eingang/'.uniqid().'.jpg';
        Storage::disk('local')->put($path, 'bild');
        $doc = Document::create([
            'category' => 'other', 'file_name' => 'Fahrzeugschein.jpg', 'file_path' => $path,
            'disk' => 'local', 'visibility' => 'internal', 'ai_status' => 'pending',
        ]);

        $ocr = new class($text) implements TextExtractorInterface {
            public function __construct(private string $text) {}
            public function isAvailable(): bool { return true; }
            public function extract(string $binary, string $mime): string { return $this->text; }
        };
        $keineTextebene = new class extends PdfTextLayerExtractor {
            public function __construct() {}
            public function isAvailable(): bool { return false; }
            public function extract(string $binary): string { return ''; }
        };
        $keineKi = new class implements DocumentAiProviderInterface {
            public function isEnabled(): bool { return false; }
            public function model(): string { return 'keins'; }
            public function analyze(string $binary, string $mime, string $ocrText, bool $preferText = false): ?array { return null; }
        };
        $analyzer = new DocumentAnalyzer($keineKi, $ocr, $keineTextebene, new RelevantPageSelector, app(DocumentTemplateParser::class));

        (new AnalyzeDocumentJob($doc->id))->handle($analyzer, app(DocumentIntakeService::class));

        return $doc->fresh();
    }

    public function test_parser_liest_den_komprimierten_schein(): void
    {
        $r = (new FahrzeugscheinParser)->parse($this->ocr('jpeg'));

        $this->assertNotNull($r);
        $this->assertSame('fahrzeugschein', $r['type']);
        $k = $r['data']['kfz'];
        $this->assertSame(self::VIN, $k['vin']);
        $this->assertSame('KI AB 123', $k['license_plate']);
        $this->assertSame('4136', $k['hsn']);
        $this->assertSame('ADM', $k['tsn']);
        $this->assertSame('2007-06-14', $k['first_registration']);
        $this->assertSame('FIAT', $k['manufacturer']);
        $this->assertSame('Ducato', $k['model']);
        $this->assertSame(88, $k['power_kw']);
        $this->assertSame('diesel', $k['fuel_type']);

        $p = $r['data']['person'];
        $this->assertSame('Haddad', $p['last_name']);
        $this->assertSame('Karim Samir Nabil', $p['first_name']);
        $this->assertSame('Musterweg', $p['street']);
        $this->assertSame('12', $p['house_number']);
        $this->assertSame('24103', $p['zip']);
        $this->assertSame('Kiel', $p['city']);
    }

    /** Fehlt die FIN in der OCR, bleibt sie leer - geraten wird sie nicht. */
    public function test_ohne_lesbare_fin_bleibt_das_kennzeichen(): void
    {
        $r = (new FahrzeugscheinParser)->parse($this->ocr('png'));

        $this->assertNotNull($r);
        $this->assertArrayNotHasKey('vin', $r['data']['kfz']);
        $this->assertSame('KI AB 123', $r['data']['kfz']['license_plate']);
        $this->assertSame('2026-08-11', $r['data']['kfz']['acquisition_date']);
        $this->assertStringContainsString('FIN nicht lesbar', $r['summary']);
    }

    /** [ohne Fix rot] FIN gefunden -> Kunde UND Vertrag, leere Fahrzeugfelder ergaenzt. */
    public function test_fin_ordnet_kunde_und_vertrag_automatisch_zu(): void
    {
        $kunde = $this->kunde('Rami Haddad');
        $this->kunde('Andere Person');
        $vertrag = $this->kfzVertrag($kunde, ['vin' => self::VIN, 'license_plate' => 'KI-AB 123']);

        $doc = $this->analysiere($this->ocr('jpeg'));

        $this->assertSame('fahrzeugschein', $doc->ai_type);
        $this->assertSame('fin', $doc->ai_extracted['match']['via']);
        $this->assertSame((string) $kunde->id, (string) $doc->customer_id, 'Vorher: kein Kunde gefunden.');
        $this->assertSame((string) $vertrag->id, (string) $doc->contract_id);

        $veh = $vertrag->fresh()->vehicleDetail;
        $this->assertSame('4136', $veh->hsn);
        $this->assertSame('ADM', $veh->tsn);
        $this->assertSame('FIAT', $veh->manufacturer);
        $this->assertSame('KI-AB 123', $veh->license_plate, 'Bestand wird nie ueberschrieben.');
    }

    /** Kennzeichen + gleicher Familienname -> automatisch. */
    public function test_kennzeichen_mit_passendem_namen_ordnet_zu(): void
    {
        $kunde = $this->kunde('Karim Haddad');
        $this->kfzVertrag($kunde, ['license_plate' => 'KI-AB 123']);

        $doc = $this->analysiere($this->ocr('png'));

        $this->assertSame('kennzeichen', $doc->ai_extracted['match']['via']);
        $this->assertSame((string) $kunde->id, (string) $doc->customer_id);
    }

    /** Kennzeichen allein (anderer Name) ist nur ein Vorschlag - Kennzeichen werden neu vergeben. */
    public function test_kennzeichen_ohne_passenden_namen_ist_nur_vorschlag(): void
    {
        $kunde = $this->kunde('Peter Schmidt');
        $this->kfzVertrag($kunde, ['license_plate' => 'KI-AB 123']);

        $doc = $this->analysiere($this->ocr('png'));

        $this->assertSame('confirm', $doc->ai_extracted['match']['tier']);
        $this->assertSame((string) $kunde->id, $doc->ai_extracted['match']['customer_id']);
        $this->assertNull($doc->customer_id);
    }

    /** Dieselbe FIN bei zwei Kunden -> nicht raten. */
    public function test_fin_bei_zwei_kunden_wird_nicht_zugeordnet(): void
    {
        $this->kfzVertrag($this->kunde('Rami Haddad'), ['vin' => self::VIN]);
        $this->kfzVertrag($this->kunde('Sami Haddad'), ['vin' => self::VIN]);

        $match = app(DocumentIntakeService::class)->findVehicleMatch(['kfz' => ['vin' => self::VIN]]);

        $this->assertNull($match);
    }

    public function test_teil_zwei_und_andere_dokumente_werden_nicht_beansprucht(): void
    {
        $parser = new FahrzeugscheinParser;

        $this->assertNull($parser->parse(str_replace('Teil I', 'Teil II', $this->ocr('jpeg'))));
        $this->assertNull($parser->parse("Antrag Kfz-Versicherung\nBitte Fahrzeugschein beilegen.\nKennzeichen KI AB 123"));
        $this->assertNull($parser->parse((string) file_get_contents(base_path('tests/Fixtures/itzehoer_kfz_police.txt'))));
    }
}
