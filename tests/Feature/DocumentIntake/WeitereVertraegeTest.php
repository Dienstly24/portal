<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentIntake\DocumentIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ZWEI VERTRAEGE aus EINEM Dokument bzw. Vorgang (Betreiber-Auftrag
 * 23.11.2026): Kfz-Antrag + ADAC-Mitgliedschaft (Schutzbrief/Pannenhilfe).
 * Vorher verschmolz `mergeExtractions` die Versicherungsdaten aller
 * Dokumente eines Vorgangs zu EINEM Vertrag - der Kfz-Vertrag bekam die
 * ADAC-Mitgliedsnummer als Vertragsnummer, die Mitgliedschaft fehlte.
 */
class WeitereVertraegeTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create(['user_id' => $user->id, 'customer_number' => 'C-'.strtoupper(Str::random(6))]);
    }

    private function doc(string $type, array $extracted, string $name = 'x.pdf'): Document
    {
        return Document::create([
            'customer_id' => null, 'category' => 'contract', 'file_name' => $name,
            'file_path' => 'documents/eingang/'.Str::random(8).'.pdf', 'disk' => 'local',
            'ai_status' => 'done', 'ai_type' => $type, 'ai_extracted' => $extracted,
        ]);
    }

    private function kfzAntrag(array $extra = []): array
    {
        return [
            'person' => ['first_name' => 'Erika', 'last_name' => 'Muster'],
            'versicherung' => ['insurer' => 'WGV', 'sparte' => 'kfz', 'tariff' => 'Optimal',
                'document_stage' => 'antrag', 'premium_amount' => 58.28, 'premium_interval' => 'monthly'],
            'kfz' => ['manufacturer' => 'smart', 'model' => 'forfour 1.3', 'initial_mileage' => 183000],
        ] + $extra;
    }

    private function adac(): array
    {
        return ['versicherung' => ['insurer' => 'ADAC', 'sparte' => 'schutzbrief',
            'contract_number' => '712345678', 'document_stage' => 'vertrag'], 'kfz' => []];
    }

    public function test_ein_dokument_ergibt_zwei_vertraege(): void
    {
        $customer = $this->customer();
        $doc = $this->doc('kfz_vertrag', $this->kfzAntrag(['weitere_vertraege' => [$this->adac()]]));
        $intake = app(DocumentIntakeService::class);

        $kfz = $intake->createContractFromExtraction($doc, $customer, null);
        $weitere = $intake->createWeitereVertraege($doc->fresh(), $customer, null);

        $this->assertSame('kfz', $kfz->type);
        $this->assertNull($kfz->contract_number);
        $this->assertCount(1, $weitere);
        $this->assertSame('schutzbrief', $weitere[0]->type);
        $this->assertSame('ADAC', $weitere[0]->insurer);
        $this->assertSame('712345678', $weitere[0]->contract_number);
        $this->assertSame(2, Contract::where('customer_id', $customer->id)->count());
        // Das Dokument bleibt am Hauptvertrag.
        $this->assertSame((string) $kfz->id, (string) $doc->fresh()->contract_id);
    }

    public function test_bekannte_mitgliedschaft_wird_nicht_doppelt_angelegt(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);
        $doc = $this->doc('kfz_vertrag', $this->kfzAntrag(['weitere_vertraege' => [$this->adac()]]));

        $intake->createWeitereVertraege($doc, $customer, null);
        $intake->createWeitereVertraege($doc->fresh(), $customer, null);

        $this->assertSame(1, Contract::where('customer_id', $customer->id)->where('type', 'schutzbrief')->count());
    }

    /** Der gemeldete Vorgang: Kfz-Unterlagen + ADAC-Mitgliedschaft als eigenes Dokument. */
    public function test_vorgang_mischt_die_mitgliedsnummer_nicht_in_den_kfz_vertrag(): void
    {
        $customer = $this->customer();
        $intake = app(DocumentIntakeService::class);
        $protokoll = $this->doc('beratungsprotokoll', $this->kfzAntrag(), 'protokoll.pdf');
        $antrag = $this->doc('versicherungsvertrag', ['versicherung' => ['insurer' => 'WGV', 'sparte' => 'kfz',
            'reference_number' => '1234-5678-9012-34', 'document_stage' => 'antrag']], 'bestaetigung.pdf');
        $mitgliedschaft = $this->doc('versicherungsvertrag', [
            'person' => ['first_name' => 'Erika', 'last_name' => 'Muster'],
        ] + $this->adac(), 'adac.pdf');

        $merged = $intake->mergeExtractions([$protokoll, $antrag, $mitgliedschaft]);

        $this->assertSame('WGV', $merged['versicherung']['insurer']);
        $this->assertArrayNotHasKey('contract_number', $merged['versicherung'], 'vorher: 712345678 am Kfz-Vertrag');
        $this->assertCount(1, $merged['weitere_vertraege']);
        $this->assertSame('712345678', $merged['weitere_vertraege'][0]['versicherung']['contract_number']);

        $kfz = $intake->createContractFromExtraction($protokoll, $customer, null, $merged);
        $weitere = $intake->createWeitereVertraege($protokoll, $customer, null, $merged);

        $this->assertNull($kfz->contract_number);
        $this->assertSame('1234-5678-9012-34', $kfz->reference_number);
        $this->assertSame('712345678', $weitere[0]->contract_number);
        // Das ADAC-Dokument haengt an SEINEM Vertrag.
        $this->assertSame((string) $weitere[0]->id, (string) $mitgliedschaft->fresh()->contract_id);
    }

    public function test_vorgang_mit_einer_sparte_bleibt_wie_bisher(): void
    {
        $intake = app(DocumentIntakeService::class);
        $a = $this->doc('beratungsprotokoll', $this->kfzAntrag());
        $b = $this->doc('versicherungsvertrag', ['versicherung' => ['insurer' => 'WGV', 'sparte' => 'kfz',
            'reference_number' => '1234-5678-9012-34']]);

        $merged = $intake->mergeExtractions([$a, $b]);

        $this->assertSame('1234-5678-9012-34', $merged['versicherung']['reference_number']);
        $this->assertSame('Optimal', $merged['versicherung']['tariff']);
        $this->assertArrayNotHasKey('weitere_vertraege', $merged);
    }
}
