<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\TemplateParsers\GesundheitskarteParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gesundheitskarten einer FAMILIE auf EINER Aufnahme (Betreiber-Vorgabe
 * 05.08.2026): Der Betrieb fotografiert die Karten zusammen; jede Karte wird
 * eine eigene Person, aus der mit einem Klick je ein Kunde entsteht.
 *
 * Wichtig: die Nummer bei "Kennnummer des Traegers" (104491707) ist die
 * Institutionsnummer der KASSE und bei allen Versicherten gleich - sie darf
 * nie als Versichertennummer eines Kunden landen.
 */
class GesundheitskartenFamilieTest extends TestCase
{
    use RefreshDatabase;

    /** Rueckseiten (EHIC) von fuenf Karten einer Familie, wie das OCR sie liefert. */
    private function kartenText(): string
    {
        $karte = fn (string $name, string $vorname, string $geburt, string $nummer) => implode("\n", [
            'EUROPÄISCHE KRANKENVERSICHERUNGSKARTE',
            'Servicenummer für Anrufe aus dem Ausland:',
            '3. Name',
            $name,
            '4. Vornamen',
            $vorname,
            '5. Geburtsdatum',
            $geburt,
            '6. Persönliche Kennnummer',
            $nummer,
            '7. Kennnummer des Trägers',
            '104491707 - novitas bkk',
            '8. Kennnummer der Karte',
            '80276001820001951731',
            '9. Ablaufdatum',
            '31/05/2030',
        ]);

        return implode("\n", [
            $karte('MUSTERMANN', 'Jana', '15/10/2017', 'I167785345'),
            $karte('MUSTERMANN', 'Abdullah', '05/01/1995', 'X999246731'),
            $karte('MUSTERMANN', 'Shhada', '20/03/2019', 'M355832998'),
            $karte('ALSHMA', 'Amna', '01/01/2002', 'U053543516'),
            $karte('MUSTERMANN', 'Mohamed Mohtar', '23/11/2021', 'N392335015'),
        ]);
    }

    public function test_parser_reads_every_card_on_the_photo(): void
    {
        $r = (new GesundheitskarteParser)->parse($this->kartenText());

        $this->assertNotNull($r);
        $this->assertSame('gesundheitskarte', $r['type']);

        // Erste Karte als Hauptperson, die uebrigen vier als weitere Personen.
        $this->assertSame('Jana', $r['data']['person']['first_name']);
        $this->assertSame('Mustermann', $r['data']['person']['last_name']);
        $this->assertSame('2017-10-15', $r['data']['person']['birth_date']);
        $this->assertSame('I167785345', $r['data']['gesundheit']['health_insurance_number']);
        $this->assertSame('novitas bkk', $r['data']['gesundheit']['health_insurance_company']);
        $this->assertSame('gesetzlich', $r['data']['gesundheit']['health_insurance_type']);
        $this->assertCount(4, $r['data']['personen']);

        // Jede Person traegt IHRE eigene Versichertennummer.
        $nummern = array_column($r['data']['personen'], 'health_insurance_number');
        $this->assertSame(['X999246731', 'M355832998', 'U053543516', 'N392335015'], $nummern);
        $this->assertSame(['Abdullah', 'Shhada', 'Amna', 'Mohamed Mohtar'], array_column($r['data']['personen'], 'first_name'));
        $this->assertSame('2002-01-01', $r['data']['personen'][2]['birth_date']);

        // Die Traeger-Kennnummer der Kasse ist KEINE Versichertennummer.
        $alle = json_encode($r['data'], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('104491707', (string) preg_replace('/"health_insurance_company":"[^"]*"/', '', $alle));

        $this->assertStringContainsString('5 Personen erkannt', $r['summary']);
    }

    public function test_incomplete_card_is_skipped_instead_of_mixing_data(): void
    {
        // Eine Karte ohne lesbare Versichertennummer wird verworfen - sonst
        // koennte die Nummer der naechsten Person an ihr haengen bleiben.
        $text = implode("\n", [
            'EUROPÄISCHE KRANKENVERSICHERUNGSKARTE',
            '3. Name',
            'MUSTERMANN',
            '4. Vornamen',
            'Jana',
            'EUROPÄISCHE KRANKENVERSICHERUNGSKARTE',
            '3. Name',
            'MUSTERMANN',
            '4. Vornamen',
            'Abdullah',
            '6. Persönliche Kennnummer',
            'X999246731',
        ]);

        $r = (new GesundheitskarteParser)->parse($text);

        $this->assertNotNull($r);
        $this->assertSame('Abdullah', $r['data']['person']['first_name']);
        $this->assertSame('X999246731', $r['data']['gesundheit']['health_insurance_number']);
        $this->assertSame([], $r['data']['personen']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kartenDokument(): Document
    {
        $r = (new GesundheitskarteParser)->parse($this->kartenText());

        return Document::create([
            'id' => (string) Str::uuid(),
            'customer_id' => null,
            'category' => 'identity',
            'file_name' => 'gesundheitskarten.jpg',
            'file_path' => 'documents/eingang/'.Str::random(8).'.jpg',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'gesundheitskarte',
            'ai_extracted' => $r['data'],
        ]);
    }

    public function test_one_click_creates_a_customer_per_person(): void
    {
        $doc = $this->kartenDokument();

        $response = $this->actingAs($this->admin())
            ->postJson(route('admin.documents.create_customers_persons', $doc->id), []);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertCount(5, $response->json('created'));
        $this->assertSame(5, Customer::count());

        // Jeder Kunde traegt SEINE Versichertennummer und die Kasse.
        $abdullah = Customer::whereHas('user', fn ($q) => $q->where('name', 'Abdullah Mustermann'))->first();
        $this->assertNotNull($abdullah);
        $this->assertSame('X999246731', $abdullah->health_insurance_number);
        $this->assertSame('novitas bkk', $abdullah->health_insurance_company);
        $this->assertSame('gesetzlich', $abdullah->health_insurance_type);
        $this->assertSame('1995-01-05', (string) $abdullah->birth_date);

        // Das Dokument haengt am ersten Kunden.
        $this->assertNotNull($doc->fresh()->customer_id);

        // Gleicher Familienname -> Familie; die abweichende Alshma bleibt
        // bewusst unverknuepft (entscheidet der Mitarbeiter).
        $amna = Customer::whereHas('user', fn ($q) => $q->where('name', 'Amna Alshma'))->first();
        $this->assertNotNull($amna);
        $this->assertSame(0, CustomerRelationship::where('customer_a_id', $amna->id)
            ->orWhere('customer_b_id', $amna->id)->count());
        // Die vier Mustermann sind untereinander verknuepft (6 Paare).
        $this->assertSame(6, CustomerRelationship::count());
    }

    public function test_existing_person_is_skipped_not_duplicated(): void
    {
        // Abdullah ist bereits Kunde - er darf kein zweites Mal entstehen.
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Abdullah Mustermann']);
        Customer::create([
            'user_id' => $user->id,
            'customer_number' => 'C-EXIST01',
            'birth_date' => '1995-01-05',
        ]);
        $doc = $this->kartenDokument();

        $response = $this->actingAs($this->admin())
            ->postJson(route('admin.documents.create_customers_persons', $doc->id), []);

        $response->assertOk();
        $this->assertCount(4, $response->json('created'));
        $this->assertCount(1, $response->json('skipped'));
        $this->assertSame('Abdullah Mustermann', $response->json('skipped.0.name'));
        // Kein zweiter Abdullah.
        $this->assertSame(1, Customer::whereHas('user', fn ($q) => $q->where('name', 'Abdullah Mustermann'))->count());
        $this->assertSame(5, Customer::count());
    }

    public function test_single_person_document_uses_the_normal_button(): void
    {
        $doc = Document::create([
            'id' => (string) Str::uuid(),
            'customer_id' => null,
            'category' => 'identity',
            'file_name' => 'eine_karte.jpg',
            'file_path' => 'documents/eingang/'.Str::random(8).'.jpg',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'gesundheitskarte',
            'ai_extracted' => ['person' => ['first_name' => 'Jana', 'last_name' => 'Mustermann'], 'personen' => []],
        ]);

        $this->actingAs($this->admin())
            ->postJson(route('admin.documents.create_customers_persons', $doc->id), [])
            ->assertStatus(422);

        $this->assertSame(0, Customer::count());
    }
}
