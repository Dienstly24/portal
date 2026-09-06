<?php

namespace Tests\Feature\Messaging;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Der Nachtrag des Bestands - der einzige Schritt dieses Umbaus, bei
 * dem Daten verloren gehen KOENNTEN.
 *
 * Deshalb wird hier nicht geprueft, ob er funktioniert, sondern ob er
 * die drei Eigenschaften hat, ohne die man ihn nicht laufen lassen
 * darf: wiederholbar, nichts loeschend, nichts ueberschreibend.
 */
class ConversationBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function kundeMitNachrichten(int $anzahl = 3): Customer
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'k'.uniqid().'@example.de']);
        $kunde = Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ]);

        for ($i = 0; $i < $anzahl; $i++) {
            CustomerMessage::create([
                'customer_id' => $kunde->id,
                'body' => 'Nachricht '.$i,
                'from_staff' => $i % 2 === 0,
            ]);
        }

        return $kunde;
    }

    /** Fall 1: Jeder Kunde mit Nachrichten bekommt genau EINE Unterhaltung. */
    public function test_bestand_bekommt_je_kunde_eine_unterhaltung(): void
    {
        $a = $this->kundeMitNachrichten(3);
        $b = $this->kundeMitNachrichten(2);

        $this->artisan('messaging:unterhaltungen-nachtragen')->assertExitCode(0);

        $this->assertSame(2, Conversation::count());
        $this->assertSame(0, CustomerMessage::whereNull('conversation_id')->count());
        $this->assertSame(3, Conversation::where('customer_id', $a->id)->firstOrFail()->messages()->count());
        $this->assertSame(2, Conversation::where('customer_id', $b->id)->firstOrFail()->messages()->count());
    }

    /**
     * Fall 2: WIEDERHOLBAR. Ein Nachtrag, den man nach einem Abbruch
     * nicht erneut starten darf, ist wertlos.
     */
    public function test_zweiter_lauf_legt_nichts_doppelt_an(): void
    {
        $this->kundeMitNachrichten(3);

        $this->artisan('messaging:unterhaltungen-nachtragen')->assertExitCode(0);
        $this->artisan('messaging:unterhaltungen-nachtragen')->assertExitCode(0);
        $this->artisan('messaging:unterhaltungen-nachtragen')->assertExitCode(0);

        $this->assertSame(1, Conversation::count());
        $this->assertSame(3, CustomerMessage::count());
    }

    /** Fall 3: Der Probelauf schreibt nichts. */
    public function test_probelauf_schreibt_nichts(): void
    {
        $this->kundeMitNachrichten(2);

        $this->artisan('messaging:unterhaltungen-nachtragen --probelauf')->assertExitCode(0);

        $this->assertSame(0, Conversation::count());
        $this->assertSame(2, CustomerMessage::whereNull('conversation_id')->count());
    }

    /** Fall 4: Der Zeitpunkt der letzten Nachricht steht an der Unterhaltung. */
    public function test_letzte_aktivitaet_wird_uebernommen(): void
    {
        $kunde = $this->kundeMitNachrichten(1);
        $spaet = CustomerMessage::create([
            'customer_id' => $kunde->id, 'body' => 'zuletzt', 'from_staff' => false,
        ]);
        $spaet->forceFill(['created_at' => now()->addMinutes(5)])->save();

        $this->artisan('messaging:unterhaltungen-nachtragen')->assertExitCode(0);

        $this->assertTrue(
            Conversation::firstOrFail()->last_message_at->equalTo($spaet->fresh()->created_at)
        );
    }

    /**
     * Fall 5: Die neuen Lesarten werden abgeleitet - es entsteht keine
     * neue Aussage, nur dieselbe in der neuen Schreibweise.
     */
    public function test_richtung_und_absenderart_werden_abgeleitet(): void
    {
        $kunde = $this->kundeMitNachrichten(0);
        // Bewusst am Modell vorbei, damit der Altbestand mit leeren
        // Omnichannel-Spalten nachgestellt wird.
        \DB::table('customer_messages')->insert([
            ['id' => (string) \Str::uuid(), 'customer_id' => $kunde->id, 'body' => 'vom Team',
                'from_staff' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) \Str::uuid(), 'customer_id' => $kunde->id, 'body' => 'vom Kunden',
                'from_staff' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('messaging:unterhaltungen-nachtragen')->assertExitCode(0);

        $team = CustomerMessage::where('body', 'vom Team')->firstOrFail();
        $kundeNachricht = CustomerMessage::where('body', 'vom Kunden')->firstOrFail();

        $this->assertSame(CustomerMessage::DIRECTION_OUTGOING, $team->direction);
        $this->assertSame(CustomerMessage::SENDER_EMPLOYEE, $team->sender_type);
        $this->assertSame(CustomerMessage::DIRECTION_INCOMING, $kundeNachricht->direction);
        $this->assertSame(CustomerMessage::SENDER_CUSTOMER, $kundeNachricht->sender_type);
    }

    /**
     * Fall 6: Eine bereits bestehende Unterhaltung wird WIEDERVERWENDET,
     * nicht verdoppelt - der Nachtrag laeuft auch nach dem Livegang noch.
     */
    public function test_bestehende_unterhaltung_wird_wiederverwendet(): void
    {
        $kunde = $this->kundeMitNachrichten(2);
        $vorhanden = Conversation::create([
            'customer_id' => $kunde->id,
            'channel_id' => Channel::idFor(Channel::PORTAL),
            'status' => Conversation::STATUS_OPEN,
        ]);

        $this->artisan('messaging:unterhaltungen-nachtragen')->assertExitCode(0);

        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, $vorhanden->fresh()->messages()->count());
    }

    /** Fall 7: Ohne Nachrichten entsteht nichts - kein leerer Bestand. */
    public function test_ohne_nachrichten_entsteht_keine_unterhaltung(): void
    {
        $user = User::factory()->create(['role' => 'customer', 'email' => 'still@example.de']);
        Customer::create([
            'user_id' => $user->id, 'customer_number' => '2600999', 'preferred_lang' => 'de',
        ]);

        $this->artisan('messaging:unterhaltungen-nachtragen')->assertExitCode(0);

        $this->assertSame(0, Conversation::count());
    }
}
