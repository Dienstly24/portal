<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\SupportFormController;
use App\Mail\GuestTicketReplyMail;
use App\Mail\TicketReplyMail;
use App\Models\ActivityLog;
use App\Models\AiConversation;
use App\Models\Customer;
use App\Models\InternalNotification;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\Assistant\Tools\AssistantToolContext;
use App\Services\Ai\Assistant\Tools\GetOpenTicketsTool;
use App\Services\Ai\Assistant\Tools\GetProcessStatusTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * KI-033: oeffentliche Formulare ordneten eine Anfrage allein ueber die
 * E-Mail-Adresse einer Kundenakte zu - als waere sie vom Kunden.
 *
 * Der Angriff: wer die Adresse eines Kunden kennt, schrieb ueber /kontakt
 * einen Text ("Rufen Sie uns unter 0151... an, Ihr Vertrag wird
 * gekuendigt"), und dieser stand danach im KUNDENPORTAL des Opfers als
 * dessen eigene Anfrage, im KI-Assistenten als offener Vorgang und in der
 * Glocke des Kunden. Dem Team erschien er als "Anfrage von Frau X".
 *
 * Jetzt: die Zuordnung bleibt (sie spart dem Team die Suche), der Vorgang
 * ist aber "Absender ungeprueft" und fuer den Kunden unsichtbar, bis ein
 * Mitarbeiter bestaetigt oder loest. Die Faelle unten scheitern ohne die
 * Aenderung (ausser den ausdruecklich als Gegenprobe benannten).
 */
class FormularAbsenderUngeprueftTest extends TestCase
{
    use RefreshDatabase;

    private const FALLE = 'Rufen Sie sofort 0151-FREMD an, sonst wird Ihr Vertrag gekuendigt';

    private function opfer(): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => 'Erika Opfer', 'email' => 'erika@kunde.de']);

        return Customer::create(['user_id' => $user->id, 'customer_number' => 'K-'.Str::random(6)]);
    }

    /** Der Angreifer schreibt ueber das Website-Kontaktformular mit der Adresse des Opfers. */
    private function angriffUeberKontakt(): Ticket
    {
        $this->post(route('website.contact.submit'), [
            'name' => 'Angreifer',
            'kontakt' => 'erika@kunde.de',
            'leistung' => 'Kfz-Versicherung',
            'nachricht' => self::FALLE,
            'consent' => '1',
        ])->assertRedirect();

        return Ticket::latest()->firstOrFail();
    }

    private function alsKunde(Customer $kunde): static
    {
        return $this->actingAs($kunde->user);
    }

    public function test_formularanfrage_wird_zugeordnet_aber_als_ungeprueft_markiert(): void
    {
        $opfer = $this->opfer();
        $ticket = $this->angriffUeberKontakt();

        $this->assertSame((string) $opfer->id, (string) $ticket->customer_id, 'Die Zuordnung fuer das Team bleibt.');
        $this->assertTrue($ticket->absenderUngeprueft());
        $this->assertSame('Angreifer', $ticket->guest_name, 'Was wirklich eingegeben wurde, bleibt sichtbar.');
    }

    public function test_kunde_sieht_die_fremde_anfrage_nirgends_im_portal(): void
    {
        $opfer = $this->opfer();
        $ticket = $this->angriffUeberKontakt();

        $this->alsKunde($opfer)->get(route('portal.tickets'))->assertOk()->assertDontSee(self::FALLE)->assertDontSee($ticket->subject);
        $this->alsKunde($opfer)->get(route('portal.dashboard'))->assertOk()->assertDontSee($ticket->subject);
        $this->alsKunde($opfer)->get(route('portal.tickets.show', $ticket->id))->assertNotFound();
        $this->alsKunde($opfer)->post(route('portal.tickets.reply', $ticket->id), ['body' => 'x'])->assertNotFound();
        $this->alsKunde($opfer)->post(route('portal.tickets.close', $ticket->id))->assertNotFound();
    }

    public function test_ki_assistent_kennt_die_fremde_anfrage_nicht(): void
    {
        $opfer = $this->opfer();
        $ticket = $this->angriffUeberKontakt();
        $context = new AssistantToolContext($opfer, AiConversation::forCustomer($opfer->id), 'de');

        $this->assertSame(0, app(GetOpenTicketsTool::class)->run([], $context)['anzahl_offene_vorgaenge']);
        $this->assertFalse(app(GetProcessStatusTool::class)->run(['vorgangsnummer' => $ticket->ticket_number], $context)['gefunden']);
    }

    public function test_antwort_und_statuswechsel_erreichen_das_kundenkonto_nicht(): void
    {
        Mail::fake();
        $opfer = $this->opfer();
        $ticket = $this->angriffUeberKontakt();
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.ticket.reply', $ticket->id), ['body' => 'Danke fuer Ihre Nachricht', 'status' => 'waiting'])
            ->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.ticket.status', $ticket->id), ['status' => 'in_progress']);

        $this->assertSame(0, InternalNotification::where('user_id', $opfer->user_id)->count(), 'Keine Glocke im Konto des Kunden.');
        Mail::assertNotSent(TicketReplyMail::class);
        Mail::assertNotQueued(TicketReplyMail::class);
        // Die Antwort geht an die Adresse aus dem Formular - wie bei einer Gast-Anfrage.
        Mail::assertQueued(GuestTicketReplyMail::class, fn ($m) => $m->hasTo('erika@kunde.de'));
    }

    public function test_hilfeformular_ohne_token_ist_ungeprueft_und_wird_dem_konto_nicht_zugeschrieben(): void
    {
        $opfer = $this->opfer();

        $this->post('/hilfe', [
            'name' => 'Angreifer', 'email' => 'erika@kunde.de',
            'leistung' => 'datenaenderung', 'message' => 'Neue IBAN: DE00 FREMD',
        ])->assertOk();

        $ticket = Ticket::latest()->firstOrFail();
        $this->assertTrue($ticket->absenderUngeprueft());
        $this->assertSame('Angreifer', $ticket->guest_name);
        $this->assertNull(
            ActivityLog::where('action', 'support_request_created')->value('user_id'),
            'Das Protokoll schrieb die Anfrage vorher dem Konto des Opfers zu.'
        );
        $this->assertNotSame((string) $opfer->user_id, (string) ActivityLog::where('action', 'support_request_created')->value('user_id'));
    }

    /** Gegenprobe: mit dem Token aus der Willkommensmail ist der Absender belegt. */
    public function test_hilfeformular_mit_token_bleibt_eine_normale_kundenanfrage(): void
    {
        $kunde = $this->opfer();

        $this->post('/hilfe', [
            't' => SupportFormController::tokenFor($kunde),
            'leistung' => 'login', 'message' => 'Ich komme nicht ins Portal.',
        ])->assertOk();

        $ticket = Ticket::latest()->firstOrFail();
        $this->assertNull($ticket->absender_status);
        $this->alsKunde($kunde)->get(route('portal.tickets.show', $ticket->id))->assertOk();
    }

    public function test_wordpress_formular_ist_ebenfalls_ungeprueft(): void
    {
        config(['services.inquiry.token' => 'geheim123']);
        $this->opfer();

        $this->postJson('/api/website-inquiry', [
            'name' => 'Angreifer', 'email' => 'erika@kunde.de', 'message' => self::FALLE,
        ], ['X-Inquiry-Token' => 'geheim123'])->assertOk();

        $this->assertTrue(Ticket::latest()->firstOrFail()->absenderUngeprueft());
    }

    public function test_mitarbeiter_sieht_den_hinweis_mit_den_formularangaben(): void
    {
        $this->opfer();
        $ticket = $this->angriffUeberKontakt();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.ticket', $ticket->id))->assertOk()
            ->assertSee('Absender nicht verifiziert')
            ->assertSee('Angreifer')
            ->assertSee(route('admin.ticket.absender_bestaetigen', $ticket->id), false);
    }

    public function test_bestaetigen_macht_den_vorgang_fuer_den_kunden_sichtbar(): void
    {
        $opfer = $this->opfer();
        $ticket = $this->angriffUeberKontakt();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.ticket.absender_bestaetigen', $ticket->id))->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame(Ticket::ABSENDER_BESTAETIGT, $ticket->absender_status);
        $this->assertSame($admin->id, $ticket->absender_geprueft_von);
        $this->assertTrue($ticket->events()->where('event', 'sender_confirmed')->exists());
        auth()->logout();
        $this->flushSession();
        $this->alsKunde($opfer)->get(route('portal.tickets.show', $ticket->id))->assertOk();
    }

    public function test_loesen_macht_eine_gastanfrage_und_loescht_nichts(): void
    {
        $opfer = $this->opfer();
        $ticket = $this->angriffUeberKontakt();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.ticket.absender_loesen', $ticket->id))->assertRedirect();

        $ticket->refresh();
        $this->assertNull($ticket->customer_id);
        $this->assertSame(self::FALLE, $ticket->description);
        $this->assertSame('Angreifer', $ticket->guest_name);
        $this->assertSame(0, Ticket::where('customer_id', $opfer->id)->count());
    }

    /** Wer das Ticket sonst nicht bearbeiten darf, darf auch nicht bestaetigen. */
    public function test_bestaetigen_nur_mit_zugriff_und_bearbeiten_recht(): void
    {
        $this->opfer();
        $ticket = $this->angriffUeberKontakt();
        $ohneRecht = User::factory()->create(['role' => 'employee']);
        $ohneRecht->forceFill(['can_manage_tickets' => false, 'can_see_all_customers' => true])->save();
        $fremdesPortfolio = User::factory()->create(['role' => 'employee']);
        $fremdesPortfolio->forceFill(['can_manage_tickets' => true, 'can_see_all_customers' => false])->save();

        $this->actingAs($ohneRecht)->post(route('admin.ticket.absender_bestaetigen', $ticket->id))->assertForbidden();
        $this->actingAs($fremdesPortfolio)->post(route('admin.ticket.absender_bestaetigen', $ticket->id))->assertForbidden();
        $this->assertTrue($ticket->fresh()->absenderUngeprueft());
    }

    /** Gegenprobe: eine Anfrage aus dem Portal selbst ist nie "ungeprueft". */
    public function test_portalanfrage_bleibt_unveraendert_sichtbar(): void
    {
        $kunde = $this->opfer();

        $this->alsKunde($kunde)->post(route('portal.tickets.store'), [
            'type' => 'other', 'subject' => 'Meine Frage', 'description' => 'Text', 'priority' => 'mittel',
        ])->assertRedirect();

        $ticket = Ticket::latest()->firstOrFail();
        $this->assertNull($ticket->absender_status);
        $this->alsKunde($kunde)->get(route('portal.tickets'))->assertSee('Meine Frage');
    }

    /**
     * Waechter: jede kundenseitige Ticket-Abfrage (Portal, KI-Werkzeuge)
     * laeuft ueber `kundenSichtbar()`. Die naechste neue Abfrage, die es
     * vergisst, oeffnet die Luecke sonst wieder - ohne jede Fehlermeldung.
     */
    public function test_jede_kundenseitige_ticketabfrage_filtert_ungepruefte(): void
    {
        $funde = [];
        $dateien = (new Finder)->files()->name('*.php')->in([
            app_path('Services/Ai/Assistant/Tools'),
        ])->append([app_path('Http/Controllers/PortalController.php')]);
        foreach ($dateien as $datei) {
            foreach (preg_split('/\R/', $datei->getContents()) as $nr => $zeile) {
                $liest = preg_match("/Ticket::where\\('(customer_id|id)'|->tickets\\(\\)/", $zeile);
                if ($liest && ! str_contains($zeile, 'kundenSichtbar()')) {
                    $funde[] = $datei->getFilename().':'.($nr + 1);
                }
            }
        }

        $this->assertSame([], $funde, 'Kundenseitige Ticket-Abfrage ohne kundenSichtbar(): '.implode(', ', $funde));
    }
}
