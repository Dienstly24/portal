<?php

namespace Tests\Feature\DocumentIntake;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DokumentPapierkorb;
use App\Models\User;
use App\Services\Dokumente\DokumentDublettenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * KI-128 (Betreiber-Auftrag 23.11.2026): doppelte Dokumente einer Akte
 * finden und in den Papierkorb legen - 30 Tage wiederherstellbar, nie
 * raten (verschiedene Vertraege, Kundensicht, Verknuepfungen bleiben).
 */
class DokumentDuplikateBereinigenTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function kunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create(['user_id' => $user->id, 'customer_number' => 'C-'.strtoupper(Str::random(8))]);
    }

    private function doc(Customer $k, string $inhalt, array $extra = []): Document
    {
        $pfad = 'customers/'.$k->id.'/documents/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($pfad, "%PDF-1.4\n".$inhalt);

        return Document::create(array_merge([
            'customer_id' => $k->id, 'category' => 'other', 'file_name' => 'scan.pdf',
            'file_path' => $pfad, 'disk' => 'local', 'visibility' => 'customer',
        ], $extra));
    }

    public function test_kopien_wandern_in_den_papierkorb_die_aelteste_bleibt(): void
    {
        Storage::fake('local');
        $k = $this->kunde();
        $alt = $this->doc($k, 'A');
        $this->travel(1)->days();
        $kopie1 = $this->doc($k, 'A');
        $kopie2 = $this->doc($k, 'A');
        $anderes = $this->doc($k, 'B');

        $this->actingAs($this->user('manager'))
            ->post(route('admin.customer.documents.dedupe', $k->id))
            ->assertSessionHas('success');

        $this->assertEqualsCanonicalizing([$alt->id, $anderes->id], Document::where('customer_id', $k->id)->pluck('id')->all());
        $this->assertSame(2, DokumentPapierkorb::where('customer_id', $k->id)->count());
        Storage::disk('local')->assertMissing($kopie1->file_path);
        $eintrag = DokumentPapierkorb::where('document_id', $kopie1->id)->firstOrFail();
        Storage::disk('local')->assertExists($eintrag->file_path);
        $this->assertSame($alt->id, $eintrag->behalten_document_id);
        $this->assertTrue($eintrag->endgueltig_ab->greaterThan(now()->addDays(29)));
        $this->assertNotNull($kopie2->id);
    }

    public function test_wiederherstellen_bringt_dokument_unter_derselben_kennung_zurueck(): void
    {
        Storage::fake('local');
        $k = $this->kunde();
        $this->doc($k, 'A');
        $kopie = $this->doc($k, 'A', ['file_name' => 'Kopie.pdf', 'category' => 'police']);
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('admin.customer.documents.dedupe', $k->id));
        $eintrag = DokumentPapierkorb::firstOrFail();

        $this->actingAs($admin)->post(route('admin.documents.trash.restore', $eintrag->id))->assertSessionHas('success');

        $zurueck = Document::findOrFail($kopie->id);
        $this->assertSame('Kopie.pdf', $zurueck->file_name);
        $this->assertSame('police', $zurueck->category);
        Storage::disk('local')->assertExists($zurueck->file_path);
        $this->assertSame(0, DokumentPapierkorb::count());
    }

    public function test_kopie_mit_vertrag_wird_behalten_statt_der_aelteren(): void
    {
        Storage::fake('local');
        $k = $this->kunde();
        $vertrag = Contract::create(['customer_id' => $k->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);
        $ohne = $this->doc($k, 'A');
        $this->travel(1)->days();
        $mit = $this->doc($k, 'A', ['contract_id' => $vertrag->id]);

        $this->actingAs($this->user('admin'))->post(route('admin.customer.documents.dedupe', $k->id));

        $this->assertNotNull(Document::find($mit->id));
        $this->assertNull(Document::find($ohne->id));
    }

    public function test_nie_raten_verschiedene_vertraege_kundensicht_und_verknuepfungen_bleiben(): void
    {
        Storage::fake('local');
        $k = $this->kunde();
        $v1 = Contract::create(['customer_id' => $k->id, 'type' => 'kfz', 'insurer' => 'HUK', 'status' => 'active']);
        $v2 = Contract::create(['customer_id' => $k->id, 'type' => 'kfz', 'insurer' => 'AXA', 'status' => 'active']);
        $this->doc($k, 'V', ['contract_id' => $v1->id]);
        $this->doc($k, 'V', ['contract_id' => $v2->id]);

        $this->doc($k, 'S', ['visibility' => 'internal']);
        $this->travel(1)->days();
        $sichtbar = $this->doc($k, 'S', ['visibility' => 'customer', 'contract_id' => null]);
        // Verknuepfte Kopie: wird BEVORZUGT behalten, auch wenn sie juenger ist.
        $angefordert = $this->doc($k, 'R');
        $zweiteAnforderung = $this->doc($k, 'R');
        foreach ([$angefordert, $zweiteAnforderung] as $d) {
            DocumentRequest::create(['customer_id' => $k->id, 'title' => 'Police', 'status' => 'uploaded', 'document_id' => $d->id, 'requested_by' => $this->user('admin')->id]);
        }

        $plan = app(DokumentDublettenService::class)->plan($k);
        $gruende = collect($plan['uebersprungen'])->pluck('grund')->all();

        $this->assertContains('Kopien haengen an verschiedenen Vertraegen', $gruende);
        $this->assertContains('Verknuepft mit einer Dokumentanforderung', $gruende);
        $this->assertNotNull(Document::find($sichtbar->id));
        // Entfernt wird nur die interne Kopie von "S" - die sichtbare bleibt.
        $this->assertCount(1, $plan['entfernen']);
        $this->assertSame('internal', $plan['entfernen'][0]['doc']->visibility);
    }

    public function test_juengere_verknuepfte_kopie_wird_behalten(): void
    {
        Storage::fake('local');
        $k = $this->kunde();
        $alt = $this->doc($k, 'Q');
        $this->travel(1)->days();
        $neu = $this->doc($k, 'Q');
        DocumentRequest::create(['customer_id' => $k->id, 'title' => 'Ausweis', 'status' => 'uploaded', 'document_id' => $neu->id, 'requested_by' => $this->user('admin')->id]);

        $plan = app(DokumentDublettenService::class)->plan($k);

        $this->assertCount(1, $plan['entfernen']);
        $this->assertSame($alt->id, $plan['entfernen'][0]['doc']->id);
        $this->assertSame($neu->id, $plan['entfernen'][0]['behalten']->id);
    }

    public function test_akteuebergreifend_ist_kein_duplikat(): void
    {
        Storage::fake('local');
        $a = $this->kunde();
        $b = $this->kunde();
        $this->doc($a, 'X');
        $this->doc($b, 'X');

        $this->assertCount(0, app(DokumentDublettenService::class)->plan($a)['entfernen']);
    }

    public function test_mitarbeiter_darf_nicht_bereinigen(): void
    {
        Storage::fake('local');
        $k = $this->kunde();
        $this->doc($k, 'A');
        $this->doc($k, 'A');

        // Falsche Rolle: die Rollen-Middleware leitet zum Dashboard um (Projektkonvention).
        $this->actingAs($this->user('employee'))->post(route('admin.customer.documents.dedupe', $k->id))->assertRedirect(route('admin.dashboard'));
        $this->assertSame(2, Document::where('customer_id', $k->id)->count());
    }

    public function test_papierkorb_wird_nach_30_tagen_endgueltig_geleert(): void
    {
        Storage::fake('local');
        $k = $this->kunde();
        $this->doc($k, 'A');
        $this->doc($k, 'A');
        $this->actingAs($this->user('admin'))->post(route('admin.customer.documents.dedupe', $k->id));
        $pfad = DokumentPapierkorb::firstOrFail()->file_path;

        $this->artisan('dokumente:papierkorb-leeren')->assertSuccessful();
        $this->assertSame(1, DokumentPapierkorb::count(), 'vor Ablauf bleibt er');

        $this->travel(31)->days();
        $this->artisan('dokumente:papierkorb-leeren')->assertSuccessful();
        $this->assertSame(0, DokumentPapierkorb::count());
        Storage::disk('local')->assertMissing($pfad);
    }

    public function test_befehl_ist_ohne_schalter_ein_probelauf(): void
    {
        Storage::fake('local');
        $k = $this->kunde();
        $this->doc($k, 'A');
        $this->doc($k, 'A');

        $this->artisan('dokumente:duplikate-bereinigen')->expectsOutputToContain('PROBELAUF')->assertSuccessful();
        $this->assertSame(2, Document::where('customer_id', $k->id)->count());

        $this->artisan('dokumente:duplikate-bereinigen', ['--ausfuehren' => true])->assertSuccessful();
        $this->assertSame(1, Document::where('customer_id', $k->id)->count());
    }

    public function test_kundenakte_zeigt_knopf_und_papierkorb(): void
    {
        Storage::fake('local');
        $k = $this->kunde();
        $this->doc($k, 'A');
        $this->doc($k, 'A');
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('admin.customer', $k->id))->assertOk()->assertSee('Duplikate entfernen');
        $this->actingAs($admin)->post(route('admin.customer.documents.dedupe', $k->id));
        $this->actingAs($admin)->get(route('admin.customer', $k->id))->assertOk()
            ->assertSee('Papierkorb (1)')->assertSee('Wiederherstellen')->assertDontSee('Duplikate entfernen');
    }
}
