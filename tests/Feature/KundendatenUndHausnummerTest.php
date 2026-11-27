<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\CustomerCreation\CustomerAutoCreationService;
use App\Services\DocumentIntake\DocumentIntakeService;
use App\Support\Kundendatenblatt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Meldung 27.11.2026: nach dem Hochladen stand in der Kundenakte
 * "Koetzer Weg" und das Feld "Nr." blieb leer, obwohl das Dokument
 * "Koetzer Weg 12" nennt - und die Kundendaten waren nur ueber
 * "Bearbeiten" zu sehen.
 *
 * Ursache (an der Kette gemessen, nicht vermutet): die Erkennung trennte die
 * Nummer richtig und die Kundenanlage schrieb sie in address_house_number.
 * Das BEARBEITEN-FORMULAR las aber nur die alte Sammelspalte `address`, und
 * die Anlage setzte diese "Strasse, PLZ Ort" OHNE Nummer. Wer danach
 * speicherte, schrieb die leere Nummer zurueck - erst dann war sie weg.
 */
class KundendatenUndHausnummerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kunde(array $felder = []): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Loay Alzaher', 'email' => 'loay'.Str::random(6).'@example.com']);

        return Customer::create($felder + ['user_id' => $user->id, 'customer_number' => '26'.random_int(10000, 99999)]);
    }

    /** Genau der gemeldete Zustand: strukturiert MIT Nummer, Alt-Spalte OHNE. */
    private function gemeldeterKunde(): Customer
    {
        return $this->kunde([
            'address' => 'Kötzer Weg, 89312 Günzburg',
            'address_street' => 'Kötzer Weg', 'address_house_number' => '12',
            'address_zip' => '89312', 'address_city' => 'Günzburg',
            'mobile' => '01793934074',
        ]);
    }

    public function test_bearbeiten_formular_zeigt_die_hausnummer_aus_den_strukturierten_feldern(): void
    {
        $kunde = $this->gemeldeterKunde();

        $html = $this->actingAs($this->admin())->get(route('admin.customer.edit', $kunde->id))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="street_nr" value="12"/', $html);
        $this->assertMatchesRegularExpression('/name="street" value="Kötzer Weg"/', $html);
        $this->assertMatchesRegularExpression('/name="plz" value="89312"/', $html);
    }

    public function test_speichern_ohne_aenderung_verliert_die_hausnummer_nicht(): void
    {
        $kunde = $this->gemeldeterKunde();
        $admin = $this->admin();
        $html = $this->actingAs($admin)->get(route('admin.customer.edit', $kunde->id))->getContent();
        preg_match('/name="street_nr" value="([^"]*)"/', $html, $nr);

        $this->actingAs($admin)->put(route('admin.customer.update', $kunde->id), [
            'first_name' => 'Loay', 'last_name' => 'Alzaher', 'nationality' => 'Deutsch',
            'street' => 'Kötzer Weg', 'street_nr' => $nr[1] ?? '', 'plz' => '89312', 'city' => 'Günzburg',
            'preferred_lang' => 'de', 'customer_type' => 'privat', 'mobile' => '01793934074',
        ])->assertSessionHasNoErrors();

        $kunde->refresh();
        $this->assertSame('12', $kunde->address_house_number);
        $this->assertSame('Kötzer Weg 12, 89312 Günzburg', $kunde->address);
    }

    public function test_nummer_im_strassenfeld_wird_beim_speichern_getrennt(): void
    {
        $kunde = $this->kunde();

        $this->actingAs($this->admin())->put(route('admin.customer.update', $kunde->id), [
            'first_name' => 'Loay', 'last_name' => 'Alzaher', 'nationality' => 'Deutsch',
            'street' => 'Hauptstraße 7/2', 'street_nr' => '', 'plz' => '89312', 'city' => 'Günzburg',
            'preferred_lang' => 'de', 'customer_type' => 'privat',
        ])->assertSessionHasNoErrors();

        $kunde->refresh();
        $this->assertSame('Hauptstraße', $kunde->address_street);
        $this->assertSame('7/2', $kunde->address_house_number);
    }

    public function test_altbestand_ohne_strukturierte_anschrift_bleibt_lesbar(): void
    {
        $kunde = $this->kunde(['address' => 'Musterstraße 25a, 20095 Hamburg, Deutschland']);

        $html = $this->actingAs($this->admin())->get(route('admin.customer.edit', $kunde->id))->getContent();

        $this->assertMatchesRegularExpression('/name="street_nr" value="25a"/', $html);
        $this->assertMatchesRegularExpression('/name="country" value="Deutschland"/', $html);
    }

    public function test_automatische_anlage_schreibt_die_nummer_auch_in_die_alt_spalte(): void
    {
        $kunde = app(CustomerAutoCreationService::class)->createFromUnmatched([
            'first_name' => 'Loay', 'last_name' => 'Alzaher',
            'street' => 'Kötzer Weg 12', 'zip' => '89312', 'city' => 'Günzburg',
        ], 'manual');

        $this->assertSame('Kötzer Weg', $kunde->address_street);
        $this->assertSame('12', $kunde->address_house_number);
        $this->assertSame('Kötzer Weg 12, 89312 Günzburg', $kunde->address);
    }

    public function test_uebernahme_haengt_keine_fremde_hausnummer_an_eine_andere_strasse(): void
    {
        $kunde = $this->kunde(['address_street' => 'Lindenweg', 'address_zip' => '20095', 'address_city' => 'Hamburg']);
        $doc = $this->dokument($kunde, ['person' => ['street' => 'Kötzer Weg', 'house_number' => '12', 'zip' => '89312', 'city' => 'Günzburg']]);

        app(DocumentIntakeService::class)->applyExtractedToCustomer($doc, $kunde, ['address'], null);

        $kunde->refresh();
        $this->assertSame('Lindenweg', $kunde->address_street);
        $this->assertNull($kunde->address_house_number);
    }

    public function test_uebernahme_ergaenzt_die_nummer_bei_derselben_strasse(): void
    {
        $kunde = $this->kunde(['address_street' => 'Kötzer Weg', 'address_zip' => '89312', 'address_city' => 'Günzburg']);
        $doc = $this->dokument($kunde, ['person' => ['street' => 'Kötzer Weg', 'house_number' => '12', 'zip' => '89312', 'city' => 'Günzburg']]);

        app(DocumentIntakeService::class)->applyExtractedToCustomer($doc, $kunde, ['address'], null);

        $this->assertSame('12', $kunde->refresh()->address_house_number);
    }

    public function test_vorgang_mischt_keine_anschriften_aus_zwei_dokumenten(): void
    {
        $ausweis = $this->dokument(null, ['person' => ['street' => 'Lindenweg', 'zip' => '20095', 'city' => 'Hamburg']], 'personalausweis');
        $antrag = $this->dokument(null, ['person' => ['street' => 'Kötzer Weg', 'house_number' => '12', 'zip' => '89312', 'city' => 'Günzburg']]);

        $merged = app(DocumentIntakeService::class)->mergeExtractions([$ausweis, $antrag]);

        $this->assertSame('Lindenweg', $merged['person']['street']);
        $this->assertArrayNotHasKey('house_number', $merged['person']);
        $this->assertSame('20095', $merged['person']['zip']);
    }

    public function test_vorgang_ergaenzt_die_nummer_aus_einem_dokument_mit_derselben_strasse(): void
    {
        $ausweis = $this->dokument(null, ['person' => ['street' => 'Kötzer Weg', 'zip' => '89312', 'city' => 'Günzburg']], 'personalausweis');
        $antrag = $this->dokument(null, ['person' => ['street' => 'Kötzer Weg', 'house_number' => '12']]);

        $merged = app(DocumentIntakeService::class)->mergeExtractions([$ausweis, $antrag]);

        $this->assertSame('12', $merged['person']['house_number']);
        $this->assertSame('89312', $merged['person']['zip']);
    }

    public function test_kundenakte_zeigt_alle_daten_ohne_bearbeiten_modus(): void
    {
        $kunde = $this->gemeldeterKunde();
        $kunde->forceFill([
            'birth_date' => '1990-03-12', 'email2' => 'alternativ@example.com', 'phone' => '040 123456',
            'address2' => 'Postfach 12, 89312 Günzburg',
            'account_holder' => 'Loay Alzaher', 'iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX',
            'health_insurance_type' => 'gesetzlich', 'health_insurance_company' => 'TK',
        ])->save();

        $html = $this->actingAs($this->admin())->get(route('admin.customer', $kunde->id))->assertOk()->getContent();

        $this->assertStringContainsString('Alle Kundendaten kopieren', $html);
        foreach (['Persönliche Daten', 'Adresse', 'Bankverbindung', 'Weitere Kundendaten'] as $bereich) {
            $this->assertStringContainsString($bereich, $html);
        }
        foreach (['Loay', 'Alzaher', '12.03.1990', $kunde->customer_number, $kunde->user->email, 'alternativ@example.com',
            '040 123456', '01793934074', 'Kötzer Weg 12, 89312 Günzburg', 'Postfach 12, 89312 Günzburg', 'TK', 'Gesetzlich'] as $wert) {
            $this->assertStringContainsString(e($wert), $html, $wert);
        }
        // Kopie = ORIGINALWERT (ohne Leerzeichen), Anzeige bleibt verdeckt.
        $this->assertStringContainsString('data-kopie="DE89370400440532013000"', $html);
        $this->assertStringContainsString('•••• 3000', $html);
        $this->assertStringContainsString('data-kopie="12"', $html);
    }

    public function test_alle_kopieren_enthaelt_die_gefuellten_werte_und_keine_leeren(): void
    {
        $kunde = $this->gemeldeterKunde();
        $kunde->forceFill(['iban' => 'DE89370400440532013000', 'birth_date' => '1990-03-12'])->save();

        $text = Kundendatenblatt::alsText($kunde->refresh());

        $this->assertStringContainsString('Vorname: Loay', $text);
        $this->assertStringContainsString('Geburtsdatum: 12.03.1990', $text);
        $this->assertStringContainsString('Straße: Kötzer Weg', $text);
        $this->assertStringContainsString('Hausnummer: 12', $text);
        $this->assertStringContainsString('PLZ: 89312', $text);
        $this->assertStringContainsString('IBAN: DE89370400440532013000', $text);
        $this->assertStringNotContainsString('BIC:', $text);
        $this->assertStringNotContainsString('—', $text);
    }

    public function test_kopieren_wird_ohne_wert_protokolliert(): void
    {
        $kunde = $this->gemeldeterKunde();

        $this->actingAs($this->admin())->postJson(route('admin.customer.data_copied', $kunde->id), ['feld' => 'iban'])->assertOk();

        $log = ActivityLog::where('action', 'customer_data_copied')->firstOrFail();
        $this->assertSame((string) $kunde->id, (string) $log->entity_id);
        $this->assertStringNotContainsString('DE89', json_encode($log->meta));
    }

    public function test_fremder_mitarbeiter_darf_nicht_protokollieren_und_sieht_keine_daten(): void
    {
        $kunde = $this->gemeldeterKunde();
        $fremd = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);

        $this->actingAs($fremd)->postJson(route('admin.customer.data_copied', $kunde->id), ['feld' => 'iban'])->assertForbidden();
        $this->actingAs($fremd)->get(route('admin.customer', $kunde->id))->assertForbidden();
    }

    public function test_nachtragen_ergaenzt_nur_belegte_nummern(): void
    {
        $ausAlt = $this->kunde(['address' => 'Kötzer Weg 12, 89312 Günzburg', 'address_street' => 'Kötzer Weg', 'address_zip' => '89312']);
        $ausDok = $this->kunde(['address_street' => 'Lindenweg', 'address_zip' => '20095']);
        $this->dokument($ausDok, ['person' => ['street' => 'Lindenweg', 'house_number' => '21 b', 'zip' => '20095']]);
        $mehrdeutig = $this->kunde(['address_street' => 'Ringstraße']);
        $this->dokument($mehrdeutig, ['person' => ['street' => 'Ringstraße', 'house_number' => '3']]);
        $this->dokument($mehrdeutig, ['person' => ['street' => 'Ringstraße', 'house_number' => '5']]);
        $fremdeStrasse = $this->kunde(['address_street' => 'Am Hang']);
        $this->dokument($fremdeStrasse, ['person' => ['street' => 'Bergweg', 'house_number' => '9']]);

        $this->artisan('kunden:hausnummer-nachtragen')->assertSuccessful();
        $this->assertNull($ausAlt->refresh()->address_house_number, 'Probelauf darf nichts speichern');

        $this->artisan('kunden:hausnummer-nachtragen', ['--ausfuehren' => true])->assertSuccessful();
        $this->assertSame('12', $ausAlt->refresh()->address_house_number);
        $this->assertSame('21 b', $ausDok->refresh()->address_house_number);
        $this->assertNull($mehrdeutig->refresh()->address_house_number);
        $this->assertNull($fremdeStrasse->refresh()->address_house_number);
    }

    private function dokument(?Customer $kunde, array $extracted, string $typ = 'versicherungsvertrag'): Document
    {
        return Document::create([
            'customer_id' => $kunde?->id, 'category' => 'other', 'file_name' => 'x.pdf',
            'file_path' => 'documents/eingang/'.Str::random(8).'.pdf', 'disk' => 'local',
            'ai_status' => 'done', 'ai_type' => $typ, 'ai_extracted' => $extracted,
        ]);
    }
}
