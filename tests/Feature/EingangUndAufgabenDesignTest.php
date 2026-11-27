<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Angleichung von Aufgaben und Dokumenten-Eingang an das gemeinsame Design
 * (27.11.2026): Kennzahl-Kacheln zaehlen dasselbe wie die Liste dahinter,
 * und die beiden Seiten tragen keine festen Farbwerte mehr in den Listen.
 */
class EingangUndAufgabenDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_kachel_heute_faellig_zaehlt_wie_der_filter(): void
    {
        $ich = User::factory()->create(['role' => 'employee']);
        $anderer = User::factory()->create(['role' => 'employee']);
        $basis = ['type' => 'call', 'priority' => 'medium', 'created_by' => $ich->id];

        Task::create($basis + ['title' => 'Heute A', 'assigned_to' => $ich->id, 'status' => 'open', 'due_date' => today()]);
        Task::create($basis + ['title' => 'Heute B', 'assigned_to' => $ich->id, 'status' => 'in_progress', 'due_date' => today()]);
        Task::create($basis + ['title' => 'Heute erledigt', 'assigned_to' => $ich->id, 'status' => 'done', 'due_date' => today()]);
        Task::create($basis + ['title' => 'Heute fremd', 'assigned_to' => $anderer->id, 'status' => 'open', 'due_date' => today()]);
        Task::create($basis + ['title' => 'Morgen', 'assigned_to' => $ich->id, 'status' => 'open', 'due_date' => today()->addDay()]);

        $res = $this->actingAs($ich)->get(route('admin.tasks'))->assertOk();
        $this->assertSame(2, $res->viewData('counts')['today']);
        $res->assertSee(route('admin.tasks', ['tab' => 'mine', 'due' => 'today']));

        $liste = $this->actingAs($ich)->get(route('admin.tasks', ['tab' => 'mine', 'due' => 'today']))->assertOk();
        $this->assertSame(2, $liste->viewData('tasks')->total());
    }

    public function test_aufgabenkarte_zeigt_ueberfaellig_und_prioritaet_als_baustein(): void
    {
        $ich = User::factory()->create(['role' => 'employee']);
        Task::create(['title' => 'Alt', 'type' => 'call', 'priority' => 'high', 'status' => 'open',
            'assigned_to' => $ich->id, 'created_by' => $ich->id, 'due_date' => today()->subDays(3)]);

        $html = $this->actingAs($ich)->get(route('admin.tasks'))->assertOk()->getContent();
        $this->assertStringContainsString('aufgabe-ueberfaellig', $html);
        $this->assertStringContainsString('badge badge-danger', $html);
        $this->assertStringContainsString('Überfällig · 3 Tg.', $html);
    }

    public function test_eingang_kacheln_zaehlen_die_liste_der_seite(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['a.pdf' => ['ai_status' => 'done', 'ki_freigabe_offen' => true], 'b.pdf' => ['ai_status' => 'pending'], 'c.pdf' => ['ai_status' => 'done']] as $name => $attr) {
            Document::create(array_merge([
                'file_name' => $name, 'file_path' => 'inbox/'.$name, 'category' => 'sonstiges', 'uploaded_by' => $admin->id,
            ], $attr));
        }
        $kunde = Customer::create(['user_id' => User::factory()->create(['role' => 'customer'])->id, 'customer_number' => 'K-1']);
        Document::create(['file_name' => 'zugeordnet.pdf', 'file_path' => 'x/z.pdf', 'category' => 'sonstiges', 'customer_id' => $kunde->id, 'ai_status' => 'done']);

        $res = $this->actingAs($admin)->get(route('admin.documents.inbox'))->assertOk();
        $html = $res->getContent();
        $this->assertSame(3, $res->viewData('inboxDocuments')->count());
        $this->assertMatchesRegularExpression('/Nicht zugeordnet<\/span>\s*<\/div>\s*<div class="kpi-wert">3</s', $html);
        $this->assertMatchesRegularExpression('/KI-Freigabe offen<\/span>\s*<\/div>\s*<div class="kpi-wert">1</s', $html);
        $this->assertMatchesRegularExpression('/Wird analysiert<\/span>\s*<\/div>\s*<div class="kpi-wert">1</s', $html);
        $this->assertStringContainsString('id="eingang"', $html);
    }

    /**
     * Waechter: die Listenbereiche beider Seiten tragen keine festen
     * Farbwerte mehr - Farben kommen aus brand.css/components.css. (Dialoge
     * weiter unten in den Vorlagen sind nicht Teil dieser Angleichung.)
     */
    public function test_listen_ohne_feste_farbwerte(): void
    {
        $zeile = (string) file_get_contents(resource_path('views/admin/partials/inbox_doc_row.blade.php'));
        $kopf = mb_substr($zeile, 0, (int) mb_strpos($zeile, '@if($dupOrig)'));
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}/', $kopf);

        $aufgaben = (string) file_get_contents(resource_path('views/admin/tasks.blade.php'));
        $liste = mb_substr($aufgaben, (int) mb_strpos($aufgaben, '@foreach($tasks as $t)'),
            (int) mb_strpos($aufgaben, '{{ $tasks->links() }}') - (int) mb_strpos($aufgaben, '@foreach($tasks as $t)'));
        $this->assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{6}/', $liste);
    }
}
