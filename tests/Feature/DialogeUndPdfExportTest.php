<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Services\Pdf\TabellenPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 28.11.2026: Dialoge "Aufgabe" und "Dokument zuordnen" im gemeinsamen
 * Design, dazu der PDF-Export der Vertragsliste.
 */
class DialogeUndPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $name): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name, 'email' => 'k-'.uniqid().'@kunde.de']);

        return Customer::create(['user_id' => $user->id, 'customer_number' => 'K-'.uniqid()]);
    }

    private function vertrag(Customer $k, array $attr = []): Contract
    {
        return Contract::create(array_merge([
            'customer_id' => $k->id, 'type' => 'kfz', 'insurer' => 'DA Direkt', 'status' => 'active',
            'start_date' => now()->subMonth()->toDateString(), 'premium_amount' => 10, 'premium_interval' => 'monthly',
        ], $attr));
    }

    /** Text aller (komprimierten) Inhaltsstroeme eines PDF, nach UTF-8 zurueck. */
    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $m);
        $text = '';
        foreach ($m[1] as $strom) {
            $roh = @gzuncompress($strom);
            $text .= ($roh === false ? $strom : $roh)."\n";
        }

        return (string) iconv('CP1252', 'UTF-8', $text);
    }

    public function test_pdf_export_der_auswahl_enthaelt_nur_die_gewaehlten_vertraege(): void
    {
        $k = $this->kunde('Jürgen Müller');
        $a = $this->vertrag($k, ['contract_number' => 'NR-AAA-111']);
        $this->vertrag($k, ['contract_number' => 'NR-BBB-222']);
        $admin = User::factory()->create(['role' => 'admin']);

        $res = $this->actingAs($admin)->post(route('admin.contracts.export'), ['format' => 'pdf', 'vertraege' => [$a->id]])->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('.pdf"', (string) $res->headers->get('Content-Disposition'));

        $pdf = $res->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $text = $this->pdfText($pdf);
        $this->assertStringContainsString('NR-AAA-111', $text);
        $this->assertStringNotContainsString('NR-BBB-222', $text);
        // Umlaute kommen als WinAnsi an, nicht als UTF-8-Kauderwelsch.
        $this->assertStringContainsString('Jürgen Müller', $text);
        // Summe = Summe der gezeigten Zeilen (10 EUR monatlich = 120 EUR).
        $this->assertStringContainsString('120,00 €', $text);

        $log = ActivityLog::where('action', 'contracts_export')->firstOrFail();
        $this->assertSame('pdf', $log->meta['format']);
        $this->assertSame(1, $log->meta['zeilen']);
    }

    public function test_pdf_export_ist_fuer_mitarbeiter_gesperrt(): void
    {
        $a = $this->vertrag($this->kunde('Erika Muster'));
        $mitarbeiter = User::factory()->create(['role' => 'employee']);

        $res = $this->actingAs($mitarbeiter)->post(route('admin.contracts.export'), ['format' => 'pdf', 'vertraege' => [$a->id]]);
        $this->assertNotSame(200, $res->getStatusCode());
        $this->assertStringNotContainsString('%PDF', (string) $res->getContent());
    }

    public function test_viele_zeilen_ergeben_mehrere_seiten_mit_wiederholtem_kopf(): void
    {
        $zeilen = [];
        for ($i = 1; $i <= 90; $i++) {
            $zeilen[] = ['K-'.$i, 'Kunde '.$i, 'Kfz'];
        }
        $pdf = TabellenPdf::erzeuge('Verträge', 'Test', [['Kd.-Nr.', 60], ['Kunde', 120], ['Sparte', 80]], $zeilen, ['Summe', '', '']);
        $seiten = preg_match_all('/\/Type \/Page /', $pdf);

        $this->assertGreaterThan(1, $seiten);
        $text = $this->pdfText($pdf);
        $this->assertSame($seiten, substr_count($text, '(Kd.-Nr.)'));
        $this->assertStringContainsString('Seite 1 von '.$seiten, $text);
        $this->assertStringContainsString('Kunde 90', $text);
        // Die Querverweistabelle zeigt auf echte Objekte.
        preg_match('/startxref\n(\d+)/', $pdf, $m);
        $this->assertStringStartsWith('xref', substr($pdf, (int) $m[1]));
    }

    public function test_zu_lange_zellen_werden_gekuerzt(): void
    {
        $lang = str_repeat('Sehr langer Gesellschaftsname ', 5);
        $kurz = TabellenPdf::kuerzen($lang, 80);
        $this->assertStringEndsWith('…', $kurz);
        $this->assertLessThan(mb_strlen($lang), mb_strlen($kurz));
        $this->assertSame('Allianz', TabellenPdf::kuerzen('Allianz', 80));
    }

    public function test_vertragsliste_bietet_pdf_nur_der_verwaltung_an(): void
    {
        $this->vertrag($this->kunde('Erika Muster'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.contracts'))->assertOk()->assertSee('value="pdf"', false);

        $mitarbeiter = User::factory()->create(['role' => 'employee']);
        $this->actingAs($mitarbeiter)->get(route('admin.contracts'))->assertOk()->assertDontSee('value="pdf"', false);
    }

    public function test_aufgaben_dialog_nutzt_den_gemeinsamen_baustein(): void
    {
        $ich = User::factory()->create(['role' => 'employee']);
        $html = $this->actingAs($ich)->get(route('admin.tasks'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="d24-modal dialog-hinter" id="task-modal"/', $html);
        $this->assertStringContainsString('class="dialog-aktionen"', $html);
        $this->assertStringContainsString('id="tf-heading"', $html);

        $vorlage = (string) file_get_contents(resource_path('views/admin/tasks.blade.php'));
        $dialog = mb_substr($vorlage, (int) mb_strpos($vorlage, 'id="task-modal"'), 12000);
        $dialog = mb_substr($dialog, 0, (int) mb_strpos($dialog, '<script'));
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}/', $dialog);
    }

    public function test_zuordnungs_dialog_nutzt_den_gemeinsamen_baustein(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->get(route('admin.documents.inbox'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="d24-modal dialog-hinter" id="doc-review-modal"/', $html);
        foreach (['id="review-submit"', 'id="review-success"', 'id="review-body"', 'id="review-customer-q"', 'id="review-visibility"'] as $id) {
            $this->assertStringContainsString($id, $html);
        }

        $vorlage = (string) file_get_contents(resource_path('views/admin/documents_inbox.blade.php'));
        $a = (int) mb_strpos($vorlage, 'id="doc-review-modal"');
        $dialog = mb_substr($vorlage, $a, (int) mb_strpos($vorlage, '<script @cspNonce>', $a) - $a);
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}/', $dialog);
    }
}
