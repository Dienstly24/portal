<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use App\Support\ChatFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Chat-Feed holt nur noch das NEUE (Audit 15.09.2026).
 *
 * BEFUND: `feed` ist ein Polling-Endpunkt - alle 10 Sekunden, Drossel
 * 120 Abrufe je Minute - und lieferte jedes Mal den KOMPLETTEN Verlauf
 * samt Anhaengen und Absendern. Eine Unterhaltung, die ueber Monate
 * waechst (der KI-Assistent antwortet mit), wurde damit hundertfach am
 * Tag vollstaendig gelesen und uebertragen. Das faellt heute nicht auf
 * und wird mit dem Bestand zwangslaeufig zum Problem.
 *
 * Diese Tests halten beides fest: es kommt nur das Neue - UND es geht
 * nichts verloren.
 */
class ChatFeedTest extends TestCase
{
    use RefreshDatabase;

    private Customer $kunde;

    private User $mitarbeiter;

    protected function setUp(): void
    {
        parent::setUp();

        $nutzer = User::factory()->create(['role' => 'customer', 'must_change_password' => false]);
        $this->kunde = Customer::create([
            'user_id' => $nutzer->id,
            'customer_number' => '2600001',
            'preferred_lang' => 'de',
        ]);
        $this->mitarbeiter = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
    }

    private function nachricht(bool $vomTeam, string $text, ?\DateTimeInterface $zeit = null): CustomerMessage
    {
        $m = CustomerMessage::create([
            'customer_id' => $this->kunde->id,
            'sender_id' => $vomTeam ? $this->mitarbeiter->id : $this->kunde->user_id,
            'body' => $text,
            'from_staff' => $vomTeam,
        ]);

        if ($zeit) {
            $m->forceFill(['created_at' => $zeit, 'updated_at' => $zeit])->save();
        }

        return $m->refresh();
    }

    // ================================================================
    // Nur das Neue
    // ================================================================

    public function test_ohne_stand_kommt_die_letzte_seite(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->nachricht(false, 'Nachricht '.$i, now()->subMinutes(200 - $i));
        }

        $antwort = $this->actingAs($this->kunde->user)
            ->getJson(route('portal.messages.feed'))->assertOk();

        $this->assertCount(ChatFeed::SEITENGROESSE, $antwort->json('messages'),
            'Der erste Aufbau liefert die juengste Seite, nicht den ganzen Verlauf.');
        $this->assertNotNull($antwort->json('cursor'));
    }

    public function test_mit_stand_kommt_nur_das_neue(): void
    {
        $this->nachricht(true, 'Alt', now()->subHour());

        $erste = $this->actingAs($this->kunde->user)
            ->getJson(route('portal.messages.feed'))->assertOk();
        $stand = $erste->json('cursor');
        $this->assertCount(1, $erste->json('messages'));

        $this->nachricht(true, 'Ganz neu');

        $zweite = $this->actingAs($this->kunde->user)
            ->getJson(route('portal.messages.feed').'?seit='.urlencode($stand))->assertOk();

        $nachrichten = $zweite->json('messages');
        $this->assertCount(1, $nachrichten, 'Nur die neue Nachricht darf kommen.');
        $this->assertSame('Ganz neu', $nachrichten[0]['body']);
    }

    public function test_ohne_neues_kommt_eine_leere_liste(): void
    {
        $this->nachricht(true, 'Alt', now()->subHour());

        $erste = $this->actingAs($this->kunde->user)->getJson(route('portal.messages.feed'));
        $stand = $erste->json('cursor');

        $zweite = $this->actingAs($this->kunde->user)
            ->getJson(route('portal.messages.feed').'?seit='.urlencode($stand))->assertOk();

        $this->assertSame([], $zweite->json('messages'));
    }

    /**
     * Der LESEHAKEN muss auch ohne neue Nachricht durchkommen - er
     * aendert read_at, nicht created_at. Wer nur nach neuen Nachrichten
     * fragt, saehe ihn nie.
     */
    public function test_gelesen_markierte_nachricht_kommt_nachtraeglich_mit(): void
    {
        $eigene = $this->nachricht(false, 'Frage des Kunden', now()->subHour());

        $erste = $this->actingAs($this->kunde->user)->getJson(route('portal.messages.feed'));
        $stand = $erste->json('cursor');

        // Das Team liest die Nachricht - danach darf der Kunde den Haken sehen.
        $this->travel(10)->seconds();
        CustomerMessage::where('id', $eigene->id)->update(['read_at' => now()]);

        $zweite = $this->actingAs($this->kunde->user)
            ->getJson(route('portal.messages.feed').'?seit='.urlencode($stand))->assertOk();

        $ids = array_column($zweite->json('messages'), 'id');
        $this->assertContains((string) $eigene->id, $ids,
            'Der Lesehaken muss den Client erreichen, auch ohne neue Nachricht.');
    }

    public function test_ungueltiger_oder_zukuenftiger_stand_liefert_wieder_alles(): void
    {
        $this->nachricht(true, 'Eine Nachricht', now()->subHour());

        foreach (['kein-datum', now()->addYear()->toIso8601String()] as $stand) {
            $antwort = $this->actingAs($this->kunde->user)
                ->getJson(route('portal.messages.feed').'?seit='.urlencode($stand))->assertOk();

            $this->assertCount(1, $antwort->json('messages'),
                "Stand '{$stand}': im Zweifel muss der Verlauf kommen, nicht nichts.");
        }
    }

    // ================================================================
    // Es geht nichts verloren
    // ================================================================

    public function test_alles_laden_liefert_den_ganzen_verlauf(): void
    {
        for ($i = 1; $i <= 70; $i++) {
            $this->nachricht(false, 'Nachricht '.$i, now()->subMinutes(200 - $i));
        }

        $antwort = $this->actingAs($this->kunde->user)
            ->getJson(route('portal.messages.feed').'?alle=1')->assertOk();

        $this->assertCount(70, $antwort->json('messages'));
    }

    public function test_seite_zeigt_knopf_fuer_frueheres_nur_wenn_es_frueheres_gibt(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->nachricht(false, 'Nachricht '.$i, now()->subMinutes(200 - $i));
        }

        // Auf die KNOPF-Kennung pruefen, nicht auf den Text: derselbe
        // Text steht auch im JavaScript (Rueckfall bei einem Fehler) und
        // waere damit kein Beleg fuer den sichtbaren Knopf.
        $this->actingAs($this->kunde->user)->get(route('portal.messages'))
            ->assertOk()->assertSee('id="chat-mehr"', false);
    }

    public function test_kurzer_verlauf_zeigt_keinen_knopf(): void
    {
        $this->nachricht(false, 'Nur eine Nachricht');

        $this->actingAs($this->kunde->user)->get(route('portal.messages'))
            ->assertOk()->assertDontSee('id="chat-mehr"', false);
    }

    // ================================================================
    // Zaehler und Reihenfolge
    // ================================================================

    public function test_ungelesen_zaehlt_ueber_den_ganzen_verlauf(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->nachricht(true, 'Team '.$i, now()->subMinutes(200 - $i));
        }

        $antwort = $this->actingAs($this->kunde->user)
            ->getJson(route('portal.messages.feed'))->assertOk();

        $this->assertSame(60, $antwort->json('unread'),
            'Der Zaehler darf sich nicht auf die geladene Seite beschraenken.');
    }

    public function test_reihenfolge_bleibt_aufsteigend(): void
    {
        $zeit = now()->subHour();
        $a = $this->nachricht(false, 'Erste', $zeit);
        $b = $this->nachricht(true, 'Zweite gleiche Sekunde', $zeit);

        $antwort = $this->actingAs($this->kunde->user)
            ->getJson(route('portal.messages.feed'))->assertOk();

        $ids = array_column($antwort->json('messages'), 'id');
        $this->assertSame(
            array_values(array_intersect($ids, [(string) $a->id, (string) $b->id])),
            $ids,
            'Auch bei gleichem Zeitstempel muss die Reihenfolge stabil sein.'
        );
        $this->assertCount(2, $ids);
    }

    // ================================================================
    // Beraterwelt: dieselbe Mechanik, dieselbe Absicherung
    // ================================================================

    public function test_beraterwelt_feed_ist_ebenfalls_inkrementell(): void
    {
        $this->nachricht(false, 'Alt', now()->subHour());

        $erste = $this->actingAs($this->mitarbeiter)
            ->getJson(route('admin.customer_chat.feed', (string) $this->kunde->id))->assertOk();
        $stand = $erste->json('cursor');
        $this->assertNotNull($stand);

        $this->nachricht(false, 'Neu vom Kunden');

        $zweite = $this->actingAs($this->mitarbeiter)
            ->getJson(route('admin.customer_chat.feed', (string) $this->kunde->id)
                .'?seit='.urlencode($stand))->assertOk();

        $this->assertCount(1, $zweite->json('messages'));
        $this->assertSame('Neu vom Kunden', $zweite->json('messages.0.body'));
    }

    public function test_fremder_mitarbeiter_kommt_nicht_an_den_feed(): void
    {
        $fremd = User::factory()->create(['role' => 'employee', 'can_see_all_customers' => false]);
        $this->nachricht(false, 'Vertraulich');

        $this->actingAs($fremd)
            ->getJson(route('admin.customer_chat.feed', (string) $this->kunde->id))
            ->assertForbidden();
    }

    public function test_kunde_sieht_nur_den_eigenen_verlauf(): void
    {
        $andererNutzer = User::factory()->create(['role' => 'customer', 'must_change_password' => false]);
        $andererKunde = Customer::create([
            'user_id' => $andererNutzer->id,
            'customer_number' => '2600002',
            'preferred_lang' => 'de',
        ]);
        CustomerMessage::create([
            'customer_id' => $andererKunde->id,
            'sender_id' => $andererNutzer->id,
            'body' => 'Fremde Nachricht',
            'from_staff' => false,
        ]);
        $this->nachricht(false, 'Eigene Nachricht');

        $antwort = $this->actingAs($this->kunde->user)
            ->getJson(route('portal.messages.feed'))->assertOk();

        $texte = array_column($antwort->json('messages'), 'body');
        $this->assertContains('Eigene Nachricht', $texte);
        $this->assertNotContains('Fremde Nachricht', $texte);
    }
}
