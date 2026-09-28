<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Suchlisten in der Beraterwelt (System-Audit 28.09.2026, KI-028/KI-029).
 */
class SuchlistenFremddatenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * KI-028: Trefferzeilen wurden per innerHTML aus Kundennamen und
     * Ticket-Betreffen gebaut - Fremddaten aus Registrierung und
     * oeffentlichen Formularen. Die CSP haelt Skripte auf, nicht aber
     * eingeschleuste Links, Formular-Attrappen oder Tracking-Bilder.
     * Waechter: kein View haengt ein solches Feld ROH in innerHTML.
     */
    public function test_kein_view_haengt_fremddaten_roh_in_innerhtml(): void
    {
        $roh = '/innerHTML[^;]*(\+\s*\(?|\$\{)(c|item|n)\.(name|title|sub|email|address|number|url|icon)\b/';
        $funde = [];
        foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $datei) {
            foreach (preg_split('/\R/', $datei->getContents()) as $nr => $zeile) {
                if (preg_match($roh, $zeile)) {
                    $funde[] = $datei->getRelativePathname().':'.($nr + 1);
                }
            }
        }

        $this->assertSame([], $funde, 'Fremddaten roh in innerHTML: '.implode(', ', $funde));
    }

    public function test_kopfzeilen_suche_escaped_titel_und_zusatz(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/admin.blade.php'));

        $this->assertStringContainsString('${escapeHtml(item.title)}', $layout);
        $this->assertStringContainsString("\${escapeHtml(item.sub || '')}", $layout);
    }

    /**
     * KI-029: der E-Mail-Eingang ist fuer Support freigegeben, seine
     * Kundensuche rief aber den Endpunkt der Mitarbeiterverwaltung (nur
     * admin/manager). Fuer Support kam 403 zurueck - die Liste blieb leer.
     */
    public function test_email_eingang_nutzt_eine_suche_die_support_erreicht(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $support->forceFill(['can_see_all_customers' => true])->save();
        $kundeUser = User::factory()->create(['role' => 'customer', 'name' => 'Erika Muster', 'email' => uniqid().'@kunde.de']);
        Customer::create(['user_id' => $kundeUser->id, 'customer_number' => 'T-'.Str::random(6)]);
        $konto = EmailAccount::firstOrCreate(
            ['email_address' => 'info@dienstly24.de'],
            ['name' => 'Test-Postfach', 'provider' => 'imap', 'folders' => ['INBOX'], 'is_active' => true]
        );
        $mail = EmailMessage::create([
            'email_account_id' => $konto->id,
            'message_uid' => 'INBOX:'.uniqid(),
            'from_address' => 'fremd@example.org',
            'subject' => 'Frage',
            'body_text' => 'Hallo',
            'match_status' => 'unmatched',
        ]);

        $html = $this->actingAs($support)->get(route('admin.email_inbox.show', $mail->id))->assertOk()->getContent();
        $this->assertStringContainsString(route('admin.customers.search'), $html);
        $this->assertStringNotContainsString(route('admin.employees.customer-search'), $html);

        $this->actingAs($support)->getJson(route('admin.customers.search', ['q' => 'Erika']))
            ->assertOk()
            ->assertJsonPath('customers.0.name', 'Erika Muster');
    }
}
