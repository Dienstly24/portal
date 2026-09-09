<?php

namespace Tests\Feature\Messaging;

use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use App\Services\Messaging\ConversationEngine;
use App\Services\Messaging\Dto\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DAS vereinheitlichte Postfach (Auftrag Teil A).
 *
 * Der Massstab dieser Datei ist nicht "die Seite laedt", sondern: EINE
 * Liste ueber ALLE Kanaele, mit EINER Suche, EINEM Ungelesen-Begriff und
 * EINER Zuweisung - und ohne dass irgendwo ein Kanalname im Code steht.
 */
class PostfachTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function kunde(string $name = 'Max Muster'): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'name' => $name]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ]);
    }

    private function konto(): ChannelAccount
    {
        return ChannelAccount::create([
            'channel_id' => Channel::where('key', 'whatsapp')->firstOrFail()->id,
            'name' => 'Geschaeftsnummer',
            'is_active' => true,
            'credentials' => ['access_token' => 'T', 'phone_number_id' => '111'],
        ]);
    }

    /** WhatsApp muss aktiv sein, um im Postfach zu erscheinen. */
    private function whatsappAn(): Channel
    {
        $kanal = Channel::where('key', 'whatsapp')->firstOrFail();
        $kanal->update(['is_active' => true]);

        return $kanal;
    }

    private function unterhaltung(
        string $kanalKey = 'whatsapp',
        ?Customer $kunde = null,
        ?string $extern = '491701234567',
    ): Conversation {
        return Conversation::create([
            'channel_id' => Channel::where('key', $kanalKey)->firstOrFail()->id,
            'customer_id' => $kunde?->id,
            'external_user_id' => $extern,
            'status' => Conversation::STATUS_OPEN,
            'last_message_at' => now(),
        ]);
    }

    private function eingang(Conversation $u, string $text = 'Hallo'): CustomerMessage
    {
        return CustomerMessage::create([
            'conversation_id' => $u->id,
            'customer_id' => $u->customer_id,
            'body' => $text,
            'from_staff' => false,
        ]);
    }

    // ---------------------------------------------------------------
    // Prioritaet 1: EINE Liste
    // ---------------------------------------------------------------

    /** Fall 1: Unterhaltungen ALLER Kanaele stehen in derselben Liste. */
    public function test_alle_kanaele_stehen_in_einer_liste(): void
    {
        $this->whatsappAn();
        $kunde = $this->kunde('Anna Beispiel');
        $this->eingang($this->unterhaltung('whatsapp', $kunde));
        $this->eingang($this->unterhaltung('portal', $this->kunde('Bernd Portal'), null));

        $antwort = $this->actingAs($this->admin())->get(route('admin.postfach'));

        $antwort->assertOk()
            ->assertSee('Anna Beispiel')
            ->assertSee('Bernd Portal');
    }

    /** Fall 2: Jede Zeile nennt ihren Kanal. */
    public function test_jede_zeile_nennt_ihren_kanal(): void
    {
        $this->whatsappAn();
        $this->eingang($this->unterhaltung('whatsapp', $this->kunde()));

        $this->actingAs($this->admin())->get(route('admin.postfach'))
            ->assertOk()->assertSee('WhatsApp');
    }

    /** Fall 3: Die Kanal-Auswahl filtert. */
    public function test_kanal_filter_zeigt_nur_diesen_kanal(): void
    {
        $this->whatsappAn();
        $this->eingang($this->unterhaltung('whatsapp', $this->kunde('WhatsApp Kundin')));
        $this->eingang($this->unterhaltung('portal', $this->kunde('Portal Kunde'), null));

        $this->actingAs($this->admin())->get(route('admin.postfach', ['kanal' => 'whatsapp']))
            ->assertOk()
            ->assertSee('WhatsApp Kundin')
            ->assertDontSee('Portal Kunde');
    }

    /** Fall 4: Ein erfundener Kanal wird verworfen, nicht angewendet. */
    public function test_unbekannter_kanal_wird_verworfen(): void
    {
        $this->whatsappAn();
        $this->eingang($this->unterhaltung('whatsapp', $this->kunde('Sichtbar Bleiben')));

        $this->actingAs($this->admin())->get(route('admin.postfach', ['kanal' => 'telegramm']))
            ->assertOk()->assertSee('Sichtbar Bleiben');
    }

    /** Fall 5: "Ungelesen" zeigt nur, worauf jemand wartet. */
    public function test_ungelesen_zeigt_nur_offene_kundenfragen(): void
    {
        $this->whatsappAn();
        $offen = $this->unterhaltung('whatsapp', $this->kunde('Wartet Auf Antwort'));
        $this->eingang($offen);

        $gelesen = $this->unterhaltung('whatsapp', $this->kunde('Schon Gelesen'), '491700000002');
        $this->eingang($gelesen)->forceFill(['read_at' => now()])->save();

        $this->actingAs($this->admin())->get(route('admin.postfach', ['sicht' => 'ungelesen']))
            ->assertOk()
            ->assertSee('Wartet Auf Antwort')
            ->assertDontSee('Schon Gelesen');
    }

    /** Fall 6: "Meine" zeigt nur die eigenen Vorgaenge. */
    public function test_meine_zeigt_nur_eigene_vorgaenge(): void
    {
        $this->whatsappAn();
        $ich = $this->admin();

        $meins = $this->unterhaltung('whatsapp', $this->kunde('Mein Vorgang'));
        $meins->update(['assigned_employee_id' => $ich->id]);
        $this->eingang($meins);

        $fremd = $this->unterhaltung('whatsapp', $this->kunde('Fremder Vorgang'), '491700000003');
        $fremd->update(['assigned_employee_id' => User::factory()->create(['role' => 'employee'])->id]);
        $this->eingang($fremd);

        $this->actingAs($ich)->get(route('admin.postfach', ['sicht' => 'meine']))
            ->assertOk()
            ->assertSee('Mein Vorgang')
            ->assertDontSee('Fremder Vorgang');
    }

    /** Fall 7: EINE Suche - ueber Kunde, Text und externe Kennung. */
    public function test_eine_suche_findet_ueber_alle_felder(): void
    {
        $this->whatsappAn();
        $u = $this->unterhaltung('whatsapp', $this->kunde('Gesuchte Person'));
        $this->eingang($u, 'Meine Vertragsnummer lautet ABC-123');

        $admin = $this->admin();

        // ueber den Kundennamen
        $this->actingAs($admin)->get(route('admin.postfach', ['q' => 'Gesuchte']))
            ->assertOk()->assertSee('Gesuchte Person');

        // ueber den Nachrichtentext
        $this->actingAs($admin)->get(route('admin.postfach', ['q' => 'ABC-123']))
            ->assertOk()->assertSee('Gesuchte Person');

        // ueber die externe Kennung (Telefonnummer)
        $this->actingAs($admin)->get(route('admin.postfach', ['q' => '491701234567']))
            ->assertOk()->assertSee('Gesuchte Person');
    }

    // ---------------------------------------------------------------
    // Prioritaet 3: unbekannte Kontakte
    // ---------------------------------------------------------------

    /**
     * Fall 8: DER FALL, DER DIE LUECKE WAR. Eine Nachricht ohne
     * Kundenakte ist im Postfach SICHTBAR - vorher war sie in der
     * gesamten Oberflaeche unauffindbar.
     */
    public function test_unbekannter_kontakt_ist_sichtbar(): void
    {
        $this->whatsappAn();
        $konto = $this->konto();

        app(ConversationEngine::class)->handleInbound(
            new InboundMessage(
                externalUserId: '491709998877',
                externalMessageId: 'wamid.UNBEKANNT',
                text: 'Guten Tag, ich habe eine Frage',
                senderPhone: '491709998877',
            ),
            $this->whatsappAn(), $konto
        );

        $this->assertNull(Conversation::firstOrFail()->customer_id);

        $this->actingAs($this->admin())->get(route('admin.postfach'))
            ->assertOk()
            ->assertSee('Unbekannter Kontakt')
            ->assertSee('491709998877');
    }

    /** Fall 9: Der Mitarbeiter verknuepft sie - der Verlauf bleibt. */
    public function test_unbekannter_kontakt_kann_verknuepft_werden(): void
    {
        $this->whatsappAn();
        $u = $this->unterhaltung('whatsapp', null, '491709998877');
        $u->update(['channel_account_id' => $this->konto()->id]);
        $this->eingang($u, 'Historische Nachricht');
        $kunde = $this->kunde('Endlich Zugeordnet');

        $this->actingAs($this->admin())
            ->post(route('admin.postfach.link_customer', $u->id), ['customer_id' => $kunde->id])
            ->assertRedirect();

        $frisch = $u->fresh();
        $this->assertSame((string) $kunde->id, (string) $frisch->customer_id);
        // Der Verlauf haengt weiter an DERSELBEN Unterhaltung.
        $this->assertSame(1, $frisch->messages()->count());
        $this->assertSame('Historische Nachricht', $frisch->messages()->first()->body);
        // Und die alte Nachricht traegt jetzt die Kundenakte.
        $this->assertSame((string) $kunde->id, (string) $frisch->messages()->first()->customer_id);
    }

    /**
     * Fall 10: Die Kanal-Identitaet entsteht mit - ab jetzt findet JEDE
     * weitere Nachricht dieser Nummer den Kunden von selbst.
     */
    public function test_verknuepfen_merkt_sich_die_kennung(): void
    {
        $this->whatsappAn();
        $konto = $this->konto();
        $u = $this->unterhaltung('whatsapp', null, '491709998877');
        $u->update(['channel_account_id' => $konto->id]);
        $kunde = $this->kunde();

        $this->actingAs($this->admin())
            ->post(route('admin.postfach.link_customer', $u->id), ['customer_id' => $kunde->id]);

        $this->assertDatabaseHas('customer_channel_identities', [
            'channel_account_id' => $konto->id,
            'external_user_id' => '491709998877',
            'customer_id' => $kunde->id,
        ]);
    }

    /** Fall 11: Eine bereits zugeordnete Unterhaltung wird nie umgehaengt. */
    public function test_zugeordnete_unterhaltung_wird_nicht_umgehaengt(): void
    {
        $this->whatsappAn();
        $u = $this->unterhaltung('whatsapp', $this->kunde('Gehoert Schon Jemandem'));

        $this->actingAs($this->admin())
            ->post(route('admin.postfach.link_customer', $u->id), [
                'customer_id' => $this->kunde('Anderer Kunde')->id,
            ])
            ->assertStatus(422);
    }

    /** Fall 12: Anlegen legt an, verknuepft und setzt den Kontakt. */
    public function test_kunde_kann_aus_der_unterhaltung_angelegt_werden(): void
    {
        $this->whatsappAn();
        $u = $this->unterhaltung('whatsapp', null, '491709998877');
        $u->update(['channel_account_id' => $this->konto()->id]);
        $this->eingang($u, 'Ich moechte ein Angebot');

        $this->actingAs($this->admin())
            ->post(route('admin.postfach.create_customer', $u->id), [
                'first_name' => 'Neue', 'last_name' => 'Kundin',
            ])->assertRedirect();

        $frisch = $u->fresh();
        $this->assertNotNull($frisch->customer_id);
        $this->assertSame(1, $frisch->messages()->count());
    }

    // ---------------------------------------------------------------
    // Betreuer und Zustaendigkeit
    // ---------------------------------------------------------------

    /** Fall 13: Uebernehmen aendert die Zustaendigkeit, NIE den Betreuer. */
    public function test_uebernehmen_laesst_den_betreuer_unberuehrt(): void
    {
        $this->whatsappAn();
        $betreuer = User::factory()->create(['role' => 'employee']);
        $support = User::factory()->create(['role' => 'support', 'can_see_all_customers' => true]);
        $kunde = $this->kunde();
        $kunde->betreuer()->attach($betreuer->id, ['is_primary' => true]);

        $u = $this->unterhaltung('whatsapp', $kunde);
        $u->update(['assigned_employee_id' => $betreuer->id]);

        $this->actingAs($support)->post(route('admin.postfach.take_over', $u->id))->assertRedirect();

        $this->assertSame($support->id, $u->fresh()->assigned_employee_id);
        $this->assertSame($betreuer->id, $kunde->fresh()->betreuerPrimary()?->id);
    }

    // ---------------------------------------------------------------
    // Composer
    // ---------------------------------------------------------------

    /**
     * Fall 14: Der Mitarbeiter waehlt KEINEN Kanal. Die Antwort geht
     * ueber den Kanal der Unterhaltung - hier bis zur echten API.
     */
    public function test_antwort_geht_ueber_den_kanal_der_unterhaltung(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.R']]], 200)]);
        $this->whatsappAn();
        $konto = $this->konto();
        $u = $this->unterhaltung('whatsapp', $this->kunde());
        $u->update(['channel_account_id' => $konto->id]);
        $this->eingang($u);

        $this->actingAs($this->admin())
            ->post(route('admin.postfach.reply', $u->id), ['body' => 'Gerne, ich pruefe das.'])
            ->assertRedirect();

        Http::assertSent(fn ($r) => ($r['text']['body'] ?? null) === 'Gerne, ich pruefe das.');
    }

    /** Fall 15: Zustand setzen - schliessen und wieder oeffnen. */
    public function test_zustand_kann_gesetzt_werden(): void
    {
        $this->whatsappAn();
        $u = $this->unterhaltung('whatsapp', $this->kunde());
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.postfach.status', $u->id), ['status' => 'closed']);
        $this->assertSame(Conversation::STATUS_CLOSED, $u->fresh()->status);

        $this->actingAs($admin)->post(route('admin.postfach.status', $u->id), ['status' => 'open']);
        $this->assertSame(Conversation::STATUS_OPEN, $u->fresh()->status);
    }

    /** Fall 16: Archiviertes erscheint nur, wenn danach gefragt wird. */
    public function test_archiv_erscheint_nur_auf_nachfrage(): void
    {
        $this->whatsappAn();
        $u = $this->unterhaltung('whatsapp', $this->kunde('Archivierte Person'));
        $u->update(['status' => Conversation::STATUS_ARCHIVED, 'archived_at' => now()]);
        $this->eingang($u);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.postfach'))
            ->assertOk()->assertDontSee('Archivierte Person');

        $this->actingAs($admin)->get(route('admin.postfach', ['status' => 'archived']))
            ->assertOk()->assertSee('Archivierte Person');
    }

    // ---------------------------------------------------------------
    // Sichtbarkeit
    // ---------------------------------------------------------------

    /**
     * Fall 17: Der Portfolio-Scope gilt auch hier - ein Mitarbeiter sieht
     * im Postfach nie mehr als in seiner Kundenliste. Unbekannte Kontakte
     * sind die bewusste Ausnahme: sie gehoeren niemandem, und unsichtbar
     * fuer alle war genau der Fehler.
     */
    public function test_portfolio_scope_gilt_auch_im_postfach(): void
    {
        $this->whatsappAn();
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $meiner = $this->kunde('Mein Kunde');
        $mitarbeiter->assignedCustomers()->attach((string) $meiner->id);
        $fremder = $this->kunde('Fremder Kunde');

        $this->eingang($this->unterhaltung('whatsapp', $meiner));
        $this->eingang($this->unterhaltung('whatsapp', $fremder, '491700000009'));
        $this->eingang($this->unterhaltung('whatsapp', null, '491700000010'));

        $this->actingAs($mitarbeiter)->get(route('admin.postfach'))
            ->assertOk()
            ->assertSee('Mein Kunde')
            ->assertDontSee('Fremder Kunde')
            ->assertSee('Unbekannter Kontakt');
    }

    /** Fall 18: Eine fremde Unterhaltung ist auch ueber die Adresse zu. */
    public function test_fremde_unterhaltung_ist_ueber_die_adresse_nicht_erreichbar(): void
    {
        $this->whatsappAn();
        $mitarbeiter = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $fremd = $this->unterhaltung('whatsapp', $this->kunde('Fremder Kunde'));

        $this->actingAs($mitarbeiter)
            ->get(route('admin.postfach', ['unterhaltung' => $fremd->id]))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Prioritaet 4: kanalgetrieben
    // ---------------------------------------------------------------

    /**
     * Fall 19: Die Navigation kommt aus der DATENBANK. Ein Kanal, der
     * ausgeschaltet ist, steht nicht im Menue; einer, der eingeschaltet
     * wird, erscheint - ohne Code-Aenderung.
     */
    public function test_navigation_folgt_den_aktiven_kanaelen(): void
    {
        $admin = $this->admin();

        $aus = $this->actingAs($admin)->get(route('admin.postfach'));
        $aus->assertOk();
        $this->assertStringNotContainsString(
            'kanal=whatsapp',
            $aus->getContent(),
            'Ein ausgeschalteter Kanal darf nicht im Menue stehen.'
        );

        $this->whatsappAn();
        $an = $this->actingAs($admin)->get(route('admin.postfach'));
        $this->assertStringContainsString('kanal=whatsapp', $an->getContent());
    }

    /**
     * Fall 20: Im Postfach steht KEIN Kanalname im Code. Ohne diese
     * Messung haelt die Regel keine sechs Monate - genau wie beim Kern
     * (MessagingArchitectureTest).
     */
    public function test_das_postfach_nennt_keinen_kanal_beim_namen(): void
    {
        $dateien = [
            app_path('Services/Messaging/Inbox/ConversationInbox.php'),
            app_path('Services/Messaging/Inbox/InboxFilters.php'),
            app_path('Http/Controllers/Admin/PostfachController.php'),
        ];

        foreach ($dateien as $datei) {
            $code = $this->ohneKommentare((string) file_get_contents($datei));
            foreach (['whatsapp', 'instagram', 'telegram', 'facebook', 'tiktok'] as $name) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $name, $code,
                    basename($datei).' darf keinen Kanal beim Namen kennen.'
                );
            }
        }
    }

    /**
     * Kommentare entfernen: eine ERLAEUTERUNG darf einen Kanal nennen,
     * der CODE nicht. Dieselbe Messweise wie im Architektur-Test.
     */
    private function ohneKommentare(string $code): string
    {
        $rein = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $rein .= is_array($token) ? $token[1] : $token;
        }

        return $rein;
    }
}
