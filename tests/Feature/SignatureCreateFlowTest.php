<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SignatureRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * DER ANLAGE-WEG: bestehender Kunde ODER externe Person.
 *
 * Der externe Weg ist kein Sonderfall, sondern die eigentliche
 * Anforderung: der Betrieb schickt regelmaessig etwas zur Unterschrift,
 * BEVOR es einen Kunden gibt. Wer die Kundenakte zur Bedingung macht,
 * erzeugt genau die Karteileichen, die spaeter muehsam zusammengefuehrt
 * werden.
 */
class SignatureCreateFlowTest extends TestCase
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

    private function kunde(string $name, string $email, ?string $geburt = null, string $sprache = 'de'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => $email]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'K-'.strtoupper(substr(md5($email), 0, 6)),
            'birth_date' => $geburt,
            'preferred_lang' => $sprache,
        ]);
    }

    public function test_die_kundensuche_liefert_die_felder_fuer_die_uebernahme(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->kunde('Max Mustermann', 'max@example.com', '1980-05-01', 'ar');

        $antwort = $this->actingAs($admin)
            ->getJson(route('admin.signatures.customer_search', ['q' => 'Mustermann']));

        $antwort->assertOk();
        $treffer = $antwort->json('customers.0');
        $this->assertSame('Max Mustermann', $treffer['name']);
        $this->assertSame('Max', $treffer['vorname']);
        $this->assertSame('Mustermann', $treffer['nachname']);
        $this->assertSame('max@example.com', $treffer['email']);
        $this->assertSame('01.05.1980', $treffer['geburtsdatum']);
        $this->assertSame('ar', $treffer['sprache']);
    }

    public function test_interne_platzhalter_adressen_werden_nie_als_kontakt_geliefert(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->kunde('Import Person', 'import-77@dienstly24.internal');

        $antwort = $this->actingAs($admin)
            ->getJson(route('admin.signatures.customer_search', ['q' => 'Import']));

        // Eine Einladung dorthin waere ein stiller Fehlschlag: die Adresse
        // kann technisch keine Mail empfangen.
        $this->assertNull($antwort->json('customers.0.email'));
    }

    public function test_die_suche_bleibt_im_portfolio(): void
    {
        $fremder = $this->kunde('Fremde Person', 'fremd@example.com');
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);

        $antwort = $this->actingAs($mitarbeiter)
            ->getJson(route('admin.signatures.customer_search', ['q' => 'Fremde']));

        $antwort->assertOk();
        $ids = collect($antwort->json('customers'))->pluck('id')->all();
        $this->assertNotContains((string) $fremder->id, $ids,
            'Ein Mitarbeiter findet hier einen Kunden, den er in seiner Liste nicht sieht.');
    }

    public function test_ein_kunde_kommt_nicht_an_die_suche(): void
    {
        $kunde = User::factory()->create(['role' => 'customer']);
        $antwort = $this->actingAs($kunde)->get(route('admin.signatures.customer_search', ['q' => 'a']));
        $this->assertTrue($antwort->isRedirect() || $antwort->getStatusCode() === 403);
    }

    public function test_externe_person_ohne_kundenakte(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $antwort = $this->actingAs($admin)->post(route('admin.signatures.store'), [
            'document' => $this->pdf(),
            'title' => 'Zeugenerklärung',
            'identity_check' => SignatureRequest::IDENTITY_NONE,
            'signers' => [['name' => 'Externer Zeuge', 'email' => 'zeuge@example.com', 'locale' => 'en']],
        ]);

        $antwort->assertSessionHasNoErrors();
        $request = SignatureRequest::firstOrFail();
        $this->assertNull($request->customer_id, 'Ein externer Vorgang braucht keine Kundenakte.');
        $this->assertNull($request->contract_id);
        // Und es ist auch KEINE angelegt worden.
        $this->assertSame(0, Customer::count());
        $this->assertSame('en', $request->signers()->first()->localeCode());
    }

    public function test_mit_kunde_bleibt_die_zuordnung_erhalten(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $kunde = $this->kunde('Max Mustermann', 'max@example.com', '1980-05-01');

        $this->actingAs($admin)->post(route('admin.signatures.store'), [
            'document' => $this->pdf(),
            'title' => 'Maklervollmacht',
            'customer_id' => (string) $kunde->id,
            'identity_check' => SignatureRequest::IDENTITY_DOB,
            'signers' => [[
                'name' => 'Max Mustermann', 'email' => 'max@example.com',
                'locale' => 'de', 'date_of_birth' => '01.05.1980',
            ]],
        ])->assertSessionHasNoErrors();

        $request = SignatureRequest::firstOrFail();
        $this->assertSame((string) $kunde->id, (string) $request->customer_id);
        $signer = $request->signers()->first();
        // Das Geburtsdatum ist hinterlegt - aber NIE im Klartext lesbar
        // ausser ueber den entschluesselnden Cast.
        $this->assertSame('1980-05-01', $signer->dob_check);
        $this->assertNull($signer->dob_verified_at);
        // Der KUNDE wurde durch die Uebernahme nicht veraendert.
        $this->assertSame('de', $kunde->fresh()->preferred_lang);
        $this->assertSame('1980-05-01', (string) $kunde->fresh()->birth_date);
    }

    public function test_das_anlage_formular_zeigt_beide_wege(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.signatures.create'))
            ->assertOk()
            ->assertSee('Bestehender Kunde', false)
            ->assertSee('Externe Person hinzufügen', false)
            // Der externe Weg steht nicht im Kleingedruckten, sondern sagt
            // ausdruecklich, dass nichts angelegt wird.
            ->assertSee('Es wird kein Kunde angelegt', false);
    }
}
