<?php

namespace Tests\Feature;

use App\Models\SignatureRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * FORMULARFEHLER SIND DEUTSCH UND NENNEN DIE STELLE.
 *
 * Gemeldet (10.09.2026): beim Anlegen einer Signaturanfrage erschien
 * "The signers.1.name field must be a string." - englisch, und mit dem
 * technischen Schluessel statt der Stelle im Formular. Zwei Fehler in
 * einem Satz: die Sprache und die Unbrauchbarkeit der Angabe.
 */
class FormularfehlerAufDeutschTest extends TestCase
{
    use RefreshDatabase;

    private function pdf(): UploadedFile
    {
        $o = [1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 595 842] >>',
            3 => '<< /Type /Page /Parent 2 0 R >>'];
        $pdf = "%PDF-1.4\n";
        $off = [];
        foreach ($o as $n => $k) {
            $off[$n] = strlen($pdf);
            $pdf .= $n." 0 obj\n".$k."\nendobj\n";
        }
        $s = strlen($pdf);
        $pdf .= "xref\n0 ".(count($o) + 1)."\n0000000000 65535 f \n";
        foreach ($off as $x) {
            $pdf .= sprintf("%010d %05d n \n", $x, 0);
        }
        $pdf .= "trailer\n<< /Size ".(count($o) + 1)." /Root 1 0 R >>\nstartxref\n".$s."\n%%EOF\n";
        $pfad = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
        file_put_contents($pfad, $pdf);

        return new UploadedFile($pfad, 'v.pdf', 'application/pdf', null, true);
    }

    public function test_die_zweite_leere_unterzeichner_zeile_ist_kein_fehler(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        // GENAU DER GEMELDETE FALL: das Formular zeigt von sich aus zwei
        // Zeilen, die zweite ist ausdruecklich optional - und blieb leer.
        $antwort = $this->actingAs($admin)->post(route('admin.signatures.store'), [
            'document' => $this->pdf(),
            'title' => 'Maklervollmacht',
            'signers' => [
                ['name' => 'Ahmad Albhre', 'email' => 'ahmad@example.com', 'locale' => 'ar'],
                ['name' => null, 'email' => null, 'locale' => 'de', 'date_of_birth' => null],
            ],
        ]);

        $antwort->assertSessionHasNoErrors();
        $this->assertSame(1, SignatureRequest::count());
        $this->assertSame(1, SignatureRequest::first()->signers()->count(),
            'Aus der leeren Zeile darf kein Unterzeichner entstehen.');
    }

    public function test_eine_zeile_mit_mail_aber_ohne_namen_wird_weiterhin_abgelehnt(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        // Die Lockerung darf den echten Fehler nicht mit durchlassen.
        $this->actingAs($admin)->post(route('admin.signatures.store'), [
            'document' => $this->pdf(),
            'title' => 'Maklervollmacht',
            'signers' => [['name' => null, 'email' => 'ohne-name@example.com']],
        ])->assertSessionHasErrors('signers.0.name');
    }

    public function test_die_meldung_ist_deutsch_und_nennt_die_zeile(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $antwort = $this->actingAs($admin)->post(route('admin.signatures.store'), [
            'document' => $this->pdf(),
            'title' => 'Maklervollmacht',
            'signers' => [
                ['name' => 'Erste Person', 'email' => 'a@example.com'],
                ['name' => null, 'email' => 'zweite@example.com'],
            ],
        ]);

        $antwort->assertSessionHasErrors('signers.1.name');
        $fehler = (string) $antwort->baseResponse->getSession()->get('errors')->first('signers.1.name');

        // KEIN Englisch und KEIN technischer Schluessel - der Mitarbeiter
        // soll lesen koennen, WELCHE Zeile gemeint ist.
        $this->assertStringNotContainsString('The ', $fehler);
        $this->assertStringNotContainsString('signers.', $fehler);
        $this->assertStringContainsString('2. Unterzeichners', $fehler);
        $this->assertStringContainsString('ausgefüllt', $fehler);
    }

    public function test_auch_der_fehlende_titel_kommt_auf_deutsch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $antwort = $this->actingAs($admin)->post(route('admin.signatures.store'), [
            'document' => $this->pdf(),
        ]);

        $antwort->assertSessionHasErrors('title');
        $fehler = (string) $antwort->baseResponse->getSession()->get('errors')->first('title');
        $this->assertSame('Der Titel muss ausgefüllt werden.', $fehler);
    }

    public function test_es_gibt_eine_deutsche_validierungsdatei(): void
    {
        // Ohne sie faellt Laravel auf ENGLISCH zurueck - das war die
        // eigentliche Ursache, und sie betraf JEDES Formular der
        // Beraterwelt, nicht nur die Signaturen.
        $this->assertFileExists(base_path('lang/de/validation.php'));

        $de = require base_path('lang/de/validation.php');
        foreach (['required', 'string', 'email', 'max', 'in', 'date', 'mimes'] as $regel) {
            $this->assertArrayHasKey($regel, $de, 'Regel ohne deutsche Meldung: '.$regel);
        }
        $this->assertArrayHasKey('attributes', $de);
        $this->assertNotSame([], $de['attributes']);
    }
}
