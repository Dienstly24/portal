<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerFamilyRelation;
use App\Models\CustomerRelationship;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\TemplateParsers\FamilienversicherungParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Antrag auf beitragsfreie Familienversicherung: aus EINEM Dokument entstehen
 * Mitglied, Ehe-/Lebenspartner und Kinder - und die Angehoerigen haengen
 * danach als Familie an der Akte des MITGLIEDS.
 *
 * Betreiber-Auftrag 02.10.2026: "wenn ich den Antrag hochlade, soll das System
 * den Antragsteller, die Ehefrau und die Kinder erkennen und sie automatisch
 * unter der Akte des Antragstellers anlegen."
 *
 * WARUM DER NAMENSABGLEICH HIER NICHT REICHT: der Vordruck rechnet
 * ausdruecklich mit abweichenden Familiennamen (er verlangt dafuer eine
 * Urkunde). Im gemessenen Fall hiessen Mitglied, Partnerin und Kinder dreimal
 * verschieden - `linkSameFamilyName` haette NICHTS verknuepft, obwohl das
 * Formular die Verwandtschaft ausdruecklich benennt. Gelesen wird deshalb die
 * ROLLE aus der Kopfzeile der Spalte.
 */
class FamilienversicherungAnlageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Antrag mit drei Spalten (Partner + zwei Kinder), erfunden aber
     * strukturgleich; die Spaltenpositionen stammen aus der gemessenen
     * PDF-Textebene. Die drei Nachnamen sind ABSICHTLICH verschieden.
     */
    private function antragText(): string
    {
        $box = "\u{F0A3}";

        return implode("\n", [
            'Angaben zur beitragsfreien',
            'Familienversicherung',
            '',
            ' Allgemeine Angaben des Mitglieds',
            '',
            'Name, Vorname (Mitglied) Hadir, Samir',
            '',
            'Anschrift                    Deichweg 8 24105 Kiel',
            '',
            'Geburtsdatum                 14.04.1978                             KVNR      A123456789',
            '',
            'Familienstand                '.$box.' ledig             X verheiratet',
            '',
            ' Angaben zum Ehe-/Lebenspartner bei Familienversicherung von Kindern',
            '',
            'Name, Vorname Nadia Kerim                                            ggf. abweichende Anschrift',
            '',
            ' Allgemeine Angaben zu Familienangehörigen',
            '',
            '                                                 Ehe-/Lebenspartner X w '.$box.' m '.$box.' x '.$box.' d2)',
            '                                                                                              Kind '.$box.' w X m '.$box.' x '.$box.' d2)',
            '                                                                                                                                         Kind X w '.$box.' m '.$box.' x '.$box.' d2)',
            '',
            'Familienversicherung wird beantragt ab (Datum)        01.09.2026                                     01.09.2026                           01.09.2026',
            'Nachname                                              Kerim                                   Hadirson                                    Hadirson',
            '',
            'Vorname                                               Nadia                                   Jonas                                      Lina',
            '                                                      09.07.1987                              11.02.2014                                 23.08.2019',
            'Geburtsdatum',
            '',
            'Verwandtschaftsverhältnis zum Mitglied                                                        X leibl. Kind           '.$box.' Stiefkind        X leibl. Kind     '.$box.' Stiefkind',
            '',
            ' Angaben zur Vorversicherung der Familienangehörigen',
            '                                                     Ehe-/Lebenspartner                       Kind                                       Kind',
            '                                                      30.08.2026                                30.08.2026                                30.08.2026',
            'endete am (Datum)',
            '                                                      BIG                                      BIG                                        BIG',
            'bestand bei (Name der Krankenkasse)',
            'Zuletzt familienversichert über die',
        ]);
    }

    private function dokument(): Document
    {
        $r = (new FamilienversicherungParser)->parse($this->antragText());
        $this->assertNotNull($r, 'Der Antrag wird nicht erkannt.');

        return Document::create([
            'id' => (string) Str::uuid(),
            'customer_id' => null,
            'category' => 'contract',
            'file_name' => 'familienversicherung.pdf',
            'file_path' => 'documents/eingang/'.Str::random(8).'.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'familienversicherung',
            'ai_extracted' => $r['data'],
        ]);
    }

    private function anlegen(Document $doc)
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.documents.create_customers_persons', $doc->id), []);
    }

    public function test_aus_einem_antrag_entstehen_mitglied_partner_und_kinder(): void
    {
        $doc = $this->dokument();

        $this->anlegen($doc)->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(4, Customer::count());
        foreach (['Samir Hadir', 'Nadia Kerim', 'Jonas Hadirson', 'Lina Hadirson'] as $name) {
            $this->assertTrue(
                Customer::whereHas('user', fn ($q) => $q->where('name', $name))->exists(),
                $name.' wurde nicht angelegt.'
            );
        }
    }

    public function test_das_dokument_haengt_an_der_akte_des_mitglieds(): void
    {
        $doc = $this->dokument();
        $this->anlegen($doc)->assertOk();

        $mitglied = Customer::whereHas('user', fn ($q) => $q->where('name', 'Samir Hadir'))->firstOrFail();
        $this->assertSame((string) $mitglied->id, (string) $doc->fresh()->customer_id);
    }

    public function test_partner_und_kinder_haengen_mit_ihrer_rolle_am_mitglied(): void
    {
        $doc = $this->dokument();
        $this->anlegen($doc)->assertOk();

        $mitglied = Customer::whereHas('user', fn ($q) => $q->where('name', 'Samir Hadir'))->firstOrFail();
        $rollen = CustomerFamilyRelation::where('customer_id', $mitglied->id)
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->related_customer_id => $r->relationship_type]);

        $this->assertCount(3, $rollen, 'Alle drei Angehoerigen muessen am Mitglied haengen.');

        $partner = Customer::whereHas('user', fn ($q) => $q->where('name', 'Nadia Kerim'))->firstOrFail();
        $sohn = Customer::whereHas('user', fn ($q) => $q->where('name', 'Jonas Hadirson'))->firstOrFail();
        $tochter = Customer::whereHas('user', fn ($q) => $q->where('name', 'Lina Hadirson'))->firstOrFail();

        $this->assertSame('ehepartner', $rollen[(string) $partner->id]);
        // Die Rolle des Kindes folgt dem angekreuzten Geschlecht.
        $this->assertSame('sohn', $rollen[(string) $sohn->id]);
        $this->assertSame('tochter', $rollen[(string) $tochter->id]);
    }

    public function test_die_gegenrichtung_entsteht_mit(): void
    {
        $doc = $this->dokument();
        $this->anlegen($doc)->assertOk();

        $mitglied = Customer::whereHas('user', fn ($q) => $q->where('name', 'Samir Hadir'))->firstOrFail();
        $sohn = Customer::whereHas('user', fn ($q) => $q->where('name', 'Jonas Hadirson'))->firstOrFail();

        // Ohne Rueckrichtung kaeme man vom Kind nie zu seinem Elternteil.
        $rueck = CustomerFamilyRelation::where('customer_id', $sohn->id)
            ->where('related_customer_id', $mitglied->id)
            ->first();
        $this->assertNotNull($rueck);
        // Die Gegenrolle bleibt NEUTRAL: der Vordruck nennt das Geschlecht der
        // Angehoerigen (Kaestchen w/m), aber NICHT das des Mitglieds. "Vater"
        // waere geraten - und eine falsche Rolle in der Akte faellt kaum auf.
        $this->assertSame('elternteil', $rueck->relationship_type);
    }

    public function test_eine_familie_ist_keine_dublette(): void
    {
        $doc = $this->dokument();
        $this->anlegen($doc)->assertOk();

        // Die Angehoerigen tragen verschiedene Nachnamen, aber dieselbe
        // Anschrift kann spaeter dazukommen - sie duerfen nicht als Dublette
        // vorgeschlagen werden.
        $this->assertSame(3, CustomerRelationship::count());
    }

    public function test_abweichende_nachnamen_verhindern_die_verknuepfung_nicht(): void
    {
        // Gegenprobe zur Ursache: der Namensabgleich allein findet hier nichts.
        $doc = $this->dokument();
        $this->anlegen($doc)->assertOk();

        $namen = Customer::with('user')->get()
            ->map(fn ($c) => (string) preg_replace('/.*\s/', '', (string) $c->user?->name))
            ->unique()
            ->values();
        $this->assertGreaterThan(1, $namen->count(), 'Die Vorlage muss verschiedene Nachnamen haben.');
    }

    public function test_ein_stiefkind_bekommt_eine_beziehung_aber_keine_kind_rolle(): void
    {
        $text = str_replace('X leibl. Kind           ', 'X Stiefkind             ', $this->antragText());
        $r = (new FamilienversicherungParser)->parse($text);
        $doc = Document::create([
            'id' => (string) Str::uuid(),
            'customer_id' => null,
            'category' => 'contract',
            'file_name' => 'familienversicherung.pdf',
            'file_path' => 'documents/eingang/'.Str::random(8).'.pdf',
            'disk' => 'local',
            'ai_status' => 'done',
            'ai_type' => 'familienversicherung',
            'ai_extracted' => $r['data'],
        ]);

        $this->anlegen($doc)->assertOk();

        $mitglied = Customer::whereHas('user', fn ($q) => $q->where('name', 'Samir Hadir'))->firstOrFail();
        $stiefkind = Customer::whereHas('user', fn ($q) => $q->where('name', 'Jonas Hadirson'))->firstOrFail();

        // Ein Stiefkind ist KEIN Kind des Mitglieds: die Beziehung entsteht
        // (keine Dublette), die Rolle vergibt ein Mensch.
        $this->assertFalse(
            CustomerFamilyRelation::where('customer_id', $mitglied->id)
                ->where('related_customer_id', $stiefkind->id)->exists()
        );
        $this->assertTrue(
            CustomerRelationship::where(fn ($q) => $q
                ->where('customer_a_id', $stiefkind->id)->orWhere('customer_b_id', $stiefkind->id))
                ->exists()
        );
    }
}
