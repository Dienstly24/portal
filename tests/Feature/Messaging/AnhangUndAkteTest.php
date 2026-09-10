<?php

namespace Tests\Feature\Messaging;

use App\Jobs\Messaging\SendOutboundMessageJob;
use App\Models\Channel;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\CustomerMessageAttachment;
use App\Models\Document;
use App\Models\User;
use App\Services\Messaging\AttachmentFilingService;
use App\Services\Messaging\Channels\WhatsAppAdapter;
use App\Services\Messaging\Dto\OutboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Die zwei Wege zwischen Unterhaltung und Kundenakte:
 * HINEIN (ein geschickter Nachweis wird zur Unterlage) und
 * HINAUS (eine Unterlage wird an den Kunden gesendet).
 *
 * Beide fehlten. Ein per Chat geschickter Versicherungsschein war in
 * der Unterhaltung sichtbar und in der Akte unauffindbar, und ein
 * "schicken Sie mir bitte meine Police" liess sich ueber den Kanal gar
 * nicht beantworten.
 */
class AnhangUndAkteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // EINMAL je Test. Im Hilfsaufruf wuerde jeder weitere Anhang die
        // Platte leeren - und der Duplikatstest zaehlte ins Leere.
        Storage::fake('local');
    }

    private function kunde(): Customer
    {
        $user = User::factory()->create(['role' => 'customer']);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ]);
    }

    private function konto(): ChannelAccount
    {
        return ChannelAccount::create([
            'channel_id' => Channel::where('key', 'whatsapp')->value('id'),
            'name' => 'Geschaeftsnummer',
            'is_active' => true,
            'credentials' => ['access_token' => 'TOKEN-X', 'phone_number_id' => '111222333'],
        ]);
    }

    private function unterhaltung(?Customer $kunde = null): Conversation
    {
        return Conversation::create([
            'channel_id' => Channel::where('key', 'whatsapp')->value('id'),
            'channel_account_id' => $this->konto()->id,
            'customer_id' => $kunde?->id,
            'external_user_id' => '491701234567',
            'status' => Conversation::STATUS_OPEN,
        ]);
    }

    private function anhang(Conversation $u, string $inhalt, string $name = 'police.pdf'): CustomerMessageAttachment
    {
        $pfad = 'customers/'.$u->customer_id.'/messages/'.uniqid().'_'.$name;
        Storage::disk('local')->put($pfad, $inhalt);

        $nachricht = CustomerMessage::create([
            'conversation_id' => $u->id,
            'customer_id' => $u->customer_id,
            'body' => 'anbei',
            'from_staff' => false,
        ]);

        return CustomerMessageAttachment::create([
            'message_id' => $nachricht->id,
            'file_name' => $name,
            'file_path' => $pfad,
            'disk' => 'local',
            'file_size' => strlen($inhalt),
            'mime_type' => 'application/pdf',
        ]);
    }

    /** Fall 1: Aus dem Anhang wird eine Unterlage in der Akte. */
    public function test_anhang_wird_zur_unterlage(): void
    {
        $kunde = $this->kunde();
        $anhang = $this->anhang($this->unterhaltung($kunde), 'PDF-INHALT');

        $dokument = app(AttachmentFilingService::class)->uebernehmen($anhang);

        $this->assertSame((string) $kunde->id, (string) $dokument->customer_id);
        $this->assertSame('police.pdf', $dokument->file_name);
        $this->assertTrue(Storage::disk('local')->exists($dokument->file_path));
        $this->assertSame((string) $dokument->id, (string) $anhang->fresh()->document_id);
    }

    /**
     * Fall 2: Die Datei des Chatverlaufs bleibt liegen. Sie zu VERSCHIEBEN
     * wuerde den Verlauf beschaedigen, sobald jemand die Unterlage spaeter
     * loescht - der Anhang zeigte dann ins Leere.
     */
    public function test_die_datei_der_unterhaltung_bleibt_erhalten(): void
    {
        $anhang = $this->anhang($this->unterhaltung($this->kunde()), 'PDF-INHALT');
        $vorher = $anhang->file_path;

        app(AttachmentFilingService::class)->uebernehmen($anhang);

        $this->assertTrue(Storage::disk('local')->exists($vorher));
        $this->assertSame($vorher, $anhang->fresh()->file_path);
    }

    /**
     * Fall 3: DER DUPLIKATSCHUTZ. Derselbe Inhalt beim selben Kunden legt
     * keine zweite Unterlage an und kopiert keine zweite Datei - der
     * Anhang zeigt auf die vorhandene.
     */
    public function test_gleicher_inhalt_erzeugt_keine_zweite_unterlage(): void
    {
        $kunde = $this->kunde();
        $u = $this->unterhaltung($kunde);
        $dienst = app(AttachmentFilingService::class);

        $erste = $dienst->uebernehmen($this->anhang($u, 'IDENTISCH'));
        $dateienNachher = count(Storage::disk('local')->allFiles());

        $zweite = $dienst->uebernehmen($this->anhang($u, 'IDENTISCH', 'nochmal.pdf'));

        $this->assertSame((string) $erste->id, (string) $zweite->id);
        $this->assertSame(1, Document::where('customer_id', $kunde->id)->count());
        // Die zweite Chat-Datei kommt dazu, eine zweite AKTEN-Datei nicht.
        $this->assertSame($dateienNachher + 1, count(Storage::disk('local')->allFiles()));
    }

    /** Fall 4: Zweimal uebernehmen legt nichts doppelt an (idempotent). */
    public function test_uebernahme_ist_idempotent(): void
    {
        $kunde = $this->kunde();
        $anhang = $this->anhang($this->unterhaltung($kunde), 'EINMALIG');
        $dienst = app(AttachmentFilingService::class);

        $a = $dienst->uebernehmen($anhang);
        $b = $dienst->uebernehmen($anhang->fresh());

        $this->assertSame((string) $a->id, (string) $b->id);
        $this->assertSame(1, Document::where('customer_id', $kunde->id)->count());
    }

    /**
     * Fall 5: OHNE KUNDENAKTE keine Unterlage. Genau der Fall einer
     * Nachricht von unbekannter Nummer - erst zuordnen, dann uebernehmen.
     */
    public function test_ohne_kunden_keine_uebernahme(): void
    {
        $anhang = $this->anhang($this->unterhaltung(null), 'INHALT');

        $this->expectException(\RuntimeException::class);
        app(AttachmentFilingService::class)->uebernehmen($anhang);
    }

    /**
     * Fall 6: Die Datei ist noch nicht abgerufen (der Anhang entsteht vor
     * ihr). Dann eine Erklaerung statt einer leeren Unterlage.
     */
    public function test_ohne_datei_keine_leere_unterlage(): void
    {
        $kunde = $this->kunde();
        $anhang = $this->anhang($this->unterhaltung($kunde), 'X');
        $anhang->forceFill(['file_path' => ''])->save();

        $this->expectException(\RuntimeException::class);
        app(AttachmentFilingService::class)->uebernehmen($anhang);

        $this->assertSame(0, Document::count());
    }

    /**
     * Fall 7: DIE REIHENFOLGE-FALLE. Anhaenge entstehen NACH der
     * Nachricht. Wer sofort sendet, schickt "anbei Ihre Police" ohne
     * Police los. Der Versand wartet deshalb, bis die Dateien stehen.
     */
    public function test_versand_wartet_auf_die_anhaenge(): void
    {
        Queue::fake();
        $kunde = $this->kunde();
        $u = $this->unterhaltung($kunde);

        $nachricht = CustomerMessage::mitAnhaengen(
            fn () => CustomerMessage::create([
                'conversation_id' => $u->id,
                'customer_id' => $kunde->id,
                'body' => 'Anbei Ihre Police.',
                'from_staff' => true,
            ]),
            function (CustomerMessage $m) {
                Queue::assertNothingPushed(); // NOCH nicht - die Datei fehlt.
                CustomerMessageAttachment::create([
                    'message_id' => $m->id,
                    'file_name' => 'police.pdf',
                    'file_path' => 'x/police.pdf',
                    'disk' => 'local',
                ]);
            }
        );

        Queue::assertPushed(SendOutboundMessageJob::class);
        $this->assertSame(1, $nachricht->attachments()->count());
    }

    /**
     * Fall 8: Nach der zurueckgehaltenen Nachricht sendet die NAECHSTE
     * wieder normal - der Schalter darf nicht haengen bleiben.
     */
    public function test_der_rueckhalt_gilt_nur_fuer_diese_eine_nachricht(): void
    {
        Queue::fake();
        $u = $this->unterhaltung($this->kunde());

        CustomerMessage::mitAnhaengen(
            fn () => CustomerMessage::create([
                'conversation_id' => $u->id, 'body' => 'Mit Datei', 'from_staff' => true,
            ]),
            fn () => null
        );
        Queue::assertPushed(SendOutboundMessageJob::class, 1);

        CustomerMessage::create([
            'conversation_id' => $u->id, 'body' => 'Ohne Datei', 'from_staff' => true,
        ]);
        Queue::assertPushed(SendOutboundMessageJob::class, 2);
    }

    /**
     * Fall 9: Der Adapter laedt die Datei HOCH und sendet sie ueber ihre
     * Kennung. Ein Link auf unsere private Platte gibt es nicht und soll
     * es nicht geben.
     */
    public function test_datei_wird_hochgeladen_und_als_dokument_gesendet(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'MEDIA-77'], 200),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.9']]], 200),
        ]);

        $ergebnis = app(WhatsAppAdapter::class)->send(
            new OutboundMessage(
                recipientId: '491701234567',
                text: 'Anbei Ihre Police.',
                type: 'media',
                attachments: [[
                    'contents' => 'PDF', 'mime_type' => 'application/pdf', 'file_name' => 'police.pdf',
                ]],
                lastInboundAt: now(),
            ),
            $this->konto()
        );

        $this->assertTrue($ergebnis->ok);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/messages')) {
                return false;
            }
            $d = $request->data();

            return ($d['type'] ?? null) === 'document'
                && ($d['document']['id'] ?? null) === 'MEDIA-77'
                // Der Text wird zur Bildunterschrift, nicht zu einer
                // zweiten, losgeloesten Nachricht.
                && ($d['document']['caption'] ?? null) === 'Anbei Ihre Police.'
                && ($d['document']['filename'] ?? null) === 'police.pdf';
        });
    }

    /** Fall 10: Ein Bild geht als Bild raus, nicht als Dokument. */
    public function test_bild_wird_als_bild_gesendet(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'MEDIA-88'], 200),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.10']]], 200),
        ]);

        app(WhatsAppAdapter::class)->send(
            new OutboundMessage(
                recipientId: '491701234567',
                type: 'media',
                attachments: [['contents' => 'JPG', 'mime_type' => 'image/jpeg', 'file_name' => 'foto.jpg']],
                lastInboundAt: now(),
            ),
            $this->konto()
        );

        Http::assertSent(fn ($r) => ! str_contains($r->url(), '/messages')
            || ($r->data()['type'] ?? null) === 'image');
    }

    /**
     * Fall 11: Scheitert der Upload, wird NICHT gesendet - sonst kaeme
     * eine Nachricht an, die auf einen Anhang verweist, den es nicht gibt.
     */
    public function test_ohne_upload_kein_versand(): void
    {
        Http::fake([
            '*/media' => Http::response(['error' => ['message' => 'x']], 400),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.11']]], 200),
        ]);

        $ergebnis = app(WhatsAppAdapter::class)->send(
            new OutboundMessage(
                recipientId: '491701234567',
                type: 'media',
                attachments: [['contents' => 'X', 'mime_type' => 'application/pdf', 'file_name' => 'a.pdf']],
                lastInboundAt: now(),
            ),
            $this->konto()
        );

        $this->assertFalse($ergebnis->ok);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/messages'));
    }

    /**
     * Fall 12: Eine Unterlage aus der Akte mitschicken erzeugt KEINE
     * zweite Datei - der Anhang zeigt auf dieselbe und traegt die
     * Unterlagen-Kennung, damit im Verlauf belegt ist, WELCHE Unterlage
     * der Kunde bekommen hat.
     */
    public function test_unterlage_aus_der_akte_wird_nicht_kopiert(): void
    {
        Queue::fake();
        $kunde = $this->kunde();
        $u = $this->unterhaltung($kunde);

        Storage::disk('local')->put('customers/'.$kunde->id.'/documents/p.pdf', 'POLICE');
        $dokument = Document::create([
            'customer_id' => $kunde->id, 'category' => 'other',
            'file_name' => 'police.pdf',
            'file_path' => 'customers/'.$kunde->id.'/documents/p.pdf',
            'disk' => 'local', 'visibility' => 'customer',
        ]);

        $mitarbeiter = User::factory()->create(['role' => 'admin']);
        $vorher = count(Storage::disk('local')->allFiles());

        $this->actingAs($mitarbeiter)
            ->post(route('admin.postfach.reply', $u->id), [
                'body' => 'Anbei Ihre Police.',
                'dokumente' => [(string) $dokument->id],
            ])->assertRedirect();

        $anhang = CustomerMessageAttachment::firstOrFail();
        $this->assertSame((string) $dokument->id, (string) $anhang->document_id);
        $this->assertSame($dokument->file_path, $anhang->file_path);
        $this->assertSame($vorher, count(Storage::disk('local')->allFiles()));
    }

    /**
     * Fall 13: Eine FREMDE Unterlagen-Kennung wird nie angehaengt. Die
     * Kennung kommt aus dem Browser - ihr wird nichts geglaubt.
     */
    public function test_fremde_unterlage_wird_nie_angehaengt(): void
    {
        Queue::fake();
        $u = $this->unterhaltung($this->kunde());
        $fremd = Document::create([
            'customer_id' => $this->kunde()->id, 'category' => 'other',
            'file_name' => 'fremd.pdf', 'file_path' => 'x/fremd.pdf',
            'disk' => 'local', 'visibility' => 'customer',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('admin.postfach.reply', $u->id), [
                'body' => 'Test', 'dokumente' => [(string) $fremd->id],
            ])->assertRedirect();

        $this->assertSame(0, CustomerMessageAttachment::count());
    }
}
