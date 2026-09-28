<?php

namespace Tests\Feature\Security;

use App\Models\ChangeRequestDocument;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerMessageAttachment;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Inline-Auslieferung von Dateien (System-Audit 28.09.2026).
 *
 * Der Befund: Dateien, deren Inhalt von AUSSEN kommt (Anhang einer
 * E-Mail eines beliebigen Absenders, Dokument aus WhatsApp), landen als
 * Dokument in der Kundenakte. "Anzeigen" lieferte sie mit dem Typ aus,
 * den das Dateisystem aus dem INHALT errät - bei einer SVG-Datei also
 * `image/svg+xml`. Die Inhaltsrichtlinie (CSP) setzt die Anwendung nur
 * auf HTML-Antworten; eine SVG lief OHNE sie im Ursprung der
 * Beraterwelt, und ein <script> darin mit der Sitzung des Mitarbeiters.
 *
 * Die Regel jetzt: inline nur, was der Browser nachweislich nicht
 * ausfuehrt (PDF und die ueblichen Rasterbilder). Alles andere wird
 * heruntergeladen - es ist nicht verloren, es wird nur nicht im Ursprung
 * der Anwendung GEOEFFNET.
 */
class InlineDateiauslieferungTest extends TestCase
{
    use RefreshDatabase;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

    private function kundeMitDokument(string $name, string $inhalt, string $visibility = 'internal'): array
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'customer', 'email' => uniqid().'@example.de']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'T-'.Str::random(6)]);
        $pfad = 'customers/'.$customer->id.'/documents/'.Str::random(8).'_'.$name;
        Storage::disk('local')->put($pfad, $inhalt);
        $doc = Document::create([
            'customer_id' => $customer->id,
            'category' => 'other',
            'file_name' => $name,
            'file_path' => $pfad,
            'disk' => 'local',
            'visibility' => $visibility,
        ]);

        return [$user, $customer, $doc];
    }

    public function test_svg_aus_fremder_quelle_wird_in_der_beraterwelt_nie_inline_ausgeliefert(): void
    {
        [, , $doc] = $this->kundeMitDokument('rechnung.svg', self::SVG);
        $admin = User::factory()->create(['role' => 'admin']);

        $antwort = $this->actingAs($admin)->get(route('admin.documents.download', $doc->id).'?view=1');

        $antwort->assertOk();
        $this->assertStringNotContainsString('svg', strtolower((string) $antwort->headers->get('Content-Type')));
        $this->assertStringStartsWith('attachment', (string) $antwort->headers->get('Content-Disposition'));
    }

    public function test_html_anhang_wird_in_der_beraterwelt_nie_inline_ausgeliefert(): void
    {
        [, , $doc] = $this->kundeMitDokument('angebot.html', '<meta http-equiv="refresh" content="0;url=https://example.org">');
        $admin = User::factory()->create(['role' => 'admin']);

        $antwort = $this->actingAs($admin)->get(route('admin.documents.download', $doc->id).'?view=1');

        $antwort->assertOk();
        $this->assertStringNotContainsString('text/html', (string) $antwort->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $antwort->headers->get('Content-Disposition'));
    }

    public function test_svg_wird_im_kundenportal_nie_inline_ausgeliefert(): void
    {
        [$user, , $doc] = $this->kundeMitDokument('bild.svg', self::SVG, 'customer');

        $antwort = $this->actingAs($user)->get(route('portal.documents.view', $doc->id));

        $antwort->assertOk();
        $this->assertStringNotContainsString('svg', strtolower((string) $antwort->headers->get('Content-Type')));
        $this->assertStringStartsWith('attachment', (string) $antwort->headers->get('Content-Disposition'));
    }

    public function test_pdf_bleibt_eine_vorschau(): void
    {
        [, , $doc] = $this->kundeMitDokument('police.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $admin = User::factory()->create(['role' => 'admin']);

        $antwort = $this->actingAs($admin)->get(route('admin.documents.download', $doc->id).'?view=1');

        $antwort->assertOk();
        $this->assertSame('application/pdf', $antwort->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $antwort->headers->get('Content-Disposition'));
    }

    /**
     * Chat-Anhang (z.B. aus WhatsApp): die Typangabe der Plattform ist die
     * Behauptung des Absenders - vorher wurde sie unbesehen als
     * Content-Type gesendet.
     */
    public function test_chat_anhang_folgt_dem_inhalt_nicht_der_typangabe(): void
    {
        [, $customer] = $this->kundeMitDokument('x.pdf', '%PDF-1.4');
        Storage::disk('local')->put('customers/'.$customer->id.'/messages/foto.png', self::SVG);
        $nachricht = CustomerMessage::create([
            'customer_id' => $customer->id,
            'body' => 'Anbei',
            'from_staff' => false,
        ]);
        $anhang = CustomerMessageAttachment::create([
            'message_id' => $nachricht->id,
            'file_name' => 'foto.png',
            'file_path' => 'customers/'.$customer->id.'/messages/foto.png',
            'disk' => 'local',
            'mime_type' => 'image/svg+xml',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $antwort = $this->actingAs($admin)->get(route('admin.messages.attachment.view', $anhang->id));

        $this->assertStringNotContainsString('svg', strtolower((string) $antwort->headers->get('Content-Type')));
        $this->assertStringStartsWith('attachment', (string) $antwort->headers->get('Content-Disposition'));
    }

    public function test_bild_mit_falscher_endung_richtet_sich_nach_dem_inhalt(): void
    {
        // Eine SVG, die sich ".png" nennt: entschieden wird am INHALT.
        [, , $doc] = $this->kundeMitDokument('foto.png', self::SVG);
        $admin = User::factory()->create(['role' => 'admin']);

        $antwort = $this->actingAs($admin)->get(route('admin.documents.download', $doc->id).'?view=1');

        $this->assertStringNotContainsString('svg', strtolower((string) $antwort->headers->get('Content-Type')));
        $this->assertStringStartsWith('attachment', (string) $antwort->headers->get('Content-Disposition'));
    }

    /**
     * Gegenprobe der Nachpruefung vor dem Merge (kein Fund, als Waechter
     * behalten): der Nachweis einer Kundenaenderung speichert den vom
     * BROWSER gemeldeten Typ. Eine echte PNG mit angehaengtem HTML, als
     * `text/html` hochgeladen, darf nie als HTML ausgeliefert werden -
     * `ChangeRequestDocument::isViewable()` laesst nur sichere Typen
     * inline zu, alles andere wird heruntergeladen.
     */
    public function test_nachweis_folgt_dem_inhalt_nicht_der_typangabe_des_browsers(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Erika Muster', 'email' => uniqid().'@example.de']);
        $customer = Customer::create(['user_id' => $user->id, 'customer_number' => 'T-'.Str::random(6), 'iban' => 'DE00ALTALTALTALTALT00']);

        $bild = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($bild);
        $png = (string) ob_get_clean().'<meta http-equiv="refresh" content="0;url=https://example.org/falle">';
        $pfad = tempnam(sys_get_temp_dir(), 'nw').'.png';
        file_put_contents($pfad, $png);
        $datei = new UploadedFile($pfad, 'karte.png', 'text/html', null, true);

        $this->actingAs($user)->post(route('portal.bank.store'), [
            'iban' => 'DE89370400440532013000',
            'account_holder' => 'Erika Muster',
            'bank_proof' => $datei,
        ]);
        $dokument = ChangeRequestDocument::latest()->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);

        $antwort = $this->actingAs($admin)->get(route('admin.change_requests.proof', $dokument->id));

        $this->assertStringNotContainsString('text/html', (string) $antwort->headers->get('Content-Type'));
    }
}
