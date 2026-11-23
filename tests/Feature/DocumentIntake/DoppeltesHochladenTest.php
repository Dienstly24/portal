<?php

namespace Tests\Feature\DocumentIntake;

use App\Http\Controllers\Admin\CustomerDocumentController;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Support\Dateiname;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * KI-126 (Betreiber-Meldung 23.11.2026): wurde eine Datei, die schon in der
 * Kundenakte lag, spaeter erneut hochgeladen, legte das System sie
 * kommentarlos ein zweites Mal ab - kein Hinweis "bereits vorhanden", kein
 * Angebot, die alte Fassung zu ersetzen.
 *
 * Jetzt: ohne Entscheidung wird NICHTS gespeichert, sondern zurueckgefragt
 * (409 mit der Liste der Konflikte); danach ersetzen / ueberspringen /
 * beide behalten.
 */
class DoppeltesHochladenTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-'.strtoupper(substr(md5((string) $user->id), 0, 8)),
        ]);
    }

    private function pdf(string $name, string $text): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".$text."\n%%EOF");
    }

    private function hochladen(User $admin, Customer $kunde, array $dateien, ?string $entscheidung = null)
    {
        $daten = ['documents' => $dateien, 'category' => 'police'];
        if ($entscheidung !== null) {
            $daten['bei_konflikt'] = $entscheidung;
        }

        return $this->actingAs($admin)->postJson(route('admin.customer.document.store', $kunde->id), $daten);
    }

    public function test_identische_datei_wird_erkannt_und_nicht_still_doppelt_gespeichert(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $kunde = $this->kunde();

        $this->hochladen($admin, $kunde, [$this->pdf('Police.pdf', 'Inhalt A')])->assertOk();

        // Dieselbe Datei, Wochen spaeter, sogar unter anderem Namen.
        $antwort = $this->hochladen($admin, $kunde, [$this->pdf('Scan_0815.pdf', 'Inhalt A')]);

        $antwort->assertStatus(409)
            ->assertJsonPath('konflikte.0.art', 'identisch')
            ->assertJsonPath('konflikte.0.vorhanden_name', 'Police.pdf');
        $this->assertSame(1, Document::where('customer_id', $kunde->id)->count(), 'ohne Entscheidung wird nichts gespeichert');
    }

    public function test_gleicher_name_mit_neuem_inhalt_kann_die_alte_fassung_ersetzen(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $kunde = $this->kunde();

        $this->hochladen($admin, $kunde, [$this->pdf('Police.pdf', 'Fassung 1')])->assertOk();
        $alt = Document::where('customer_id', $kunde->id)->firstOrFail();
        $alterPfad = $alt->file_path;

        $this->hochladen($admin, $kunde, [$this->pdf('Police (1).pdf', 'Fassung 2')])
            ->assertStatus(409)
            ->assertJsonPath('konflikte.0.art', 'gleicher_name');

        $this->hochladen($admin, $kunde, [$this->pdf('Police (1).pdf', 'Fassung 2')], 'ersetzen')
            ->assertOk()
            ->assertJsonPath('ersetzt', 1)
            ->assertJsonPath('count', 0);

        $this->assertSame(1, Document::where('customer_id', $kunde->id)->count(), 'ersetzt, nicht verdoppelt');
        $alt->refresh();
        $this->assertNotSame($alterPfad, $alt->file_path);
        Storage::disk('local')->assertMissing($alterPfad);
        $this->assertSame(hash('sha256', "%PDF-1.4\nFassung 2\n%%EOF"), $alt->content_hash, 'Hash folgt der NEUEN Datei');
    }

    public function test_ueberspringen_laedt_nur_die_neuen_dateien_hoch(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $kunde = $this->kunde();

        $this->hochladen($admin, $kunde, [$this->pdf('a.pdf', 'A')])->assertOk();

        $this->hochladen($admin, $kunde, [$this->pdf('a.pdf', 'A'), $this->pdf('b.pdf', 'B')], 'ueberspringen')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('uebersprungen', 1);

        $this->assertEqualsCanonicalizing(['a.pdf', 'b.pdf'], Document::where('customer_id', $kunde->id)->pluck('file_name')->all());
    }

    public function test_beide_behalten_speichert_bewusst_doppelt(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $kunde = $this->kunde();

        $this->hochladen($admin, $kunde, [$this->pdf('a.pdf', 'A')])->assertOk();
        $this->hochladen($admin, $kunde, [$this->pdf('a.pdf', 'A')], 'beide_behalten')->assertOk()->assertJsonPath('count', 1);

        $this->assertSame(2, Document::where('customer_id', $kunde->id)->count());
    }

    public function test_datei_eines_fremden_kunden_ist_kein_konflikt(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $kunde = $this->kunde();
        $fremd = $this->kunde();

        $this->hochladen($admin, $fremd, [$this->pdf('a.pdf', 'A')])->assertOk();
        // Die Akte von $kunde ist leer - kein Hinweis auf die fremde Akte.
        $this->hochladen($admin, $kunde, [$this->pdf('a.pdf', 'A')])->assertOk()->assertJsonPath('count', 1);
    }

    public function test_formular_ohne_js_bekommt_eine_warnung_statt_still_doppelt(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $kunde = $this->kunde();

        $this->hochladen($admin, $kunde, [$this->pdf('a.pdf', 'A')])->assertOk();
        $this->actingAs($admin)->post(route('admin.customer.document.store', $kunde->id), [
            'documents' => [$this->pdf('a.pdf', 'A')],
        ])->assertSessionHas('warning');

        $this->assertSame(1, Document::where('customer_id', $kunde->id)->count());
    }

    public function test_kundenakte_markiert_vorhandene_duplikate(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $kunde = $this->kunde();

        $this->hochladen($admin, $kunde, [$this->pdf('a.pdf', 'A')])->assertOk();
        $this->hochladen($admin, $kunde, [$this->pdf('a.pdf', 'A')], 'beide_behalten')->assertOk();

        $html = $this->actingAs($admin)->get(route('admin.customer', $kunde->id))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '⧉ Doppelt'), 'nur die SPAETERE Kopie traegt den Hinweis');
    }

    public function test_dateiname_vergleich_ignoriert_browser_kopienzusatz(): void
    {
        $this->assertSame('police.pdf', CustomerDocumentController::normalisiereDateiname('Police (1).pdf'));
        $this->assertSame('police.pdf', CustomerDocumentController::normalisiereDateiname('  POLICE.pdf'));
    }

    /** KI-127: RFC-2047-kodierte Anhangsnamen standen unlesbar in der Akte. */
    public function test_mime_kodierter_anhangsname_wird_lesbar(): void
    {
        $this->assertSame(
            'CamScanner ١٤-٠٧-٢٠٢٦ ٠١.٥٣.pdf',
            Dateiname::dekodiere('=?UTF-8?B?Q2FtU2Nhbm5lciDZodmk4oCPLdmg2afigI8t2aLZoNmi2aYg2aDZoS7ZpdmjLnBkZg==?=')
        );
        $this->assertSame('Police.pdf', Dateiname::dekodiere('Police.pdf'));
    }
}
