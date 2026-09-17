<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sicherheits-Nachpruefung nach der Behebung (16.09.2026).
 *
 * Diese Faelle sind aus einem Angriffs-Probelauf entstanden: erst wurde
 * gemessen, was die Anwendung auf boesartige Anfragen WIRKLICH antwortet,
 * dann wurde jede Antwort hier festgeschrieben. Sie decken die Punkte der
 * Auftragsliste ab, fuer die es bisher keinen eigenen Test gab -
 * Fremdzugriff ueber die ID, offene Weiterleitung, Webhook ohne Signatur,
 * Gesundheits-Endpunkt, Adminbereich und CORS.
 *
 * BEWUSST NICHT hier: die Sichtbarkeit von Kunden fuer Mitarbeiter und
 * Support. Das ist eine eigene Aufgabe mit eigener Spezifikation
 * ("Employee & Support Customer Access Architecture") - sie wird hier
 * weder geprueft noch veraendert.
 */
class ReAuditTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(string $name): Customer
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'name' => $name,
            'email' => uniqid('kunde').'@example.de',
        ]);

        return Customer::create([
            'user_id' => $user->id,
            'customer_number' => '26'.substr((string) uniqid(), -5),
            'preferred_lang' => 'de',
        ]);
    }

    // ------------------------------------------------------------- IDOR

    /**
     * Die Unterlage eines FREMDEN Kunden darf ueber ihre ID nicht
     * erreichbar sein - weder die Ansicht noch der Download.
     */
    public function test_kunde_kommt_nicht_an_die_unterlage_eines_anderen_kunden(): void
    {
        Storage::fake('local');

        $angreifer = $this->kunde('Kunde A');
        $opfer = $this->kunde('Kunde B');

        $dokument = Document::create([
            'id' => (string) Str::uuid(),
            'customer_id' => $opfer->id,
            'category' => 'contract',
            'file_name' => 'police.pdf',
            'file_path' => 'customers/'.$opfer->id.'/police.pdf',
            'created_by' => $opfer->user_id,
            'disk' => 'local',
        ]);
        Storage::disk('local')->put($dokument->file_path, 'VERTRAULICH');

        foreach ([
            '/portal/dokumente/'.$dokument->id,
            '/portal/documents/'.$dokument->id.'/download',
        ] as $pfad) {
            $antwort = $this->actingAs($angreifer->user)->get($pfad);

            $this->assertContains($antwort->getStatusCode(), [403, 404],
                $pfad.' hat einem fremden Kunden geantwortet (Status '.$antwort->getStatusCode().').');
            $this->assertStringNotContainsString('VERTRAULICH', $antwort->getContent() ?: '');
        }
    }

    // --------------------------------------------- Offene Weiterleitung

    /**
     * Eine Zieladresse aus dem Request darf nie auf einen fremden Host
     * fuehren. Gemessen wird der Location-Header, nicht die Absicht.
     */
    public function test_keine_weiterleitung_auf_einen_fremden_host(): void
    {
        foreach ([
            '/login?redirect=https://boese.example',
            '/login?next=https://boese.example',
            '/?next=//boese.example',
        ] as $pfad) {
            $ziel = (string) $this->get($pfad)->headers->get('Location');

            if ($ziel === '') {
                continue;
            }

            $this->assertStringNotContainsString('boese.example', $ziel,
                $pfad.' leitet auf einen fremden Host weiter.');
        }
    }

    // --------------------------------------------------------- Webhook

    /** Ohne gueltige Signatur wird ein Webhook nicht angenommen. */
    public function test_webhook_ohne_signatur_wird_abgewiesen(): void
    {
        $antwort = $this->postJson('/webhooks/whatsapp', ['entry' => [['id' => '1']]]);

        $this->assertContains($antwort->getStatusCode(), [401, 403],
            'Ein Webhook ohne Signatur wurde angenommen.');
    }

    // ----------------------------------------------- Gesundheits-Ampel

    /** Ohne Token gibt es den Endpunkt nicht - auch kein 401. */
    public function test_gesundheit_ohne_token_verraet_nichts(): void
    {
        config(['security.health_token' => 'test-health-token-0123456789']);

        $antwort = $this->getJson('/gesundheit');

        $this->assertSame(404, $antwort->getStatusCode());
        $this->assertStringNotContainsString('test-health-token', $antwort->getContent() ?: '');
    }

    /** Die Ampel nennt Zustaende, aber keine Werte und keine Geheimnisse. */
    public function test_gesundheit_gibt_nur_die_ampel_heraus(): void
    {
        config(['security.health_token' => 'test-health-token-0123456789']);

        $inhalt = (string) $this->getJson('/gesundheit?token=test-health-token-0123456789')->getContent();

        foreach (['APP_KEY', 'DB_PASSWORD', 'ANTHROPIC', 'password', 'secret', 'token'] as $verboten) {
            $this->assertStringNotContainsStringIgnoringCase($verboten, $inhalt,
                'Die Gesundheits-Ampel gibt "'.$verboten.'" heraus.');
        }
    }

    // ------------------------------------------------------ Adminwelt

    /** Ohne Anmeldung fuehrt kein Weg in die Beraterwelt. */
    public function test_adminbereich_ist_ohne_anmeldung_zu(): void
    {
        foreach ([
            '/admin', '/admin/kunden', '/admin/systemzustand',
            '/admin/provisionsmanagement', '/admin/postfach', '/admin/signaturen',
        ] as $pfad) {
            $antwort = $this->get($pfad);

            $this->assertNotSame(200, $antwort->getStatusCode(),
                $pfad.' war ohne Anmeldung erreichbar.');
        }
    }

    /** Ein KUNDE kommt nirgends in die Beraterwelt. */
    public function test_kunde_kommt_nicht_in_die_beraterwelt(): void
    {
        $kunde = $this->kunde('Kunde A');

        foreach ([
            '/admin', '/admin/kunden', '/admin/provisionsmanagement',
            '/admin/systemzustand', '/admin/postfach',
        ] as $pfad) {
            $antwort = $this->actingAs($kunde->user)->get($pfad);

            $this->assertNotSame(200, $antwort->getStatusCode(),
                $pfad.' war fuer einen Kunden erreichbar.');
        }
    }

    // ----------------------------------------------------------- CORS

    /**
     * Es gibt keine CORS-Freigabe. Ohne sie kann eine fremde Seite die
     * Antworten des Portals nicht auslesen, selbst wenn sie den Browser
     * eines angemeldeten Mitarbeiters dazu bringt, sie abzurufen.
     */
    public function test_keine_cors_freigabe_fuer_fremde_herkunft(): void
    {
        $antwort = $this->get('/login', ['Origin' => 'https://boese.example']);

        $this->assertNull($antwort->headers->get('Access-Control-Allow-Origin'),
            'Es gibt eine CORS-Freigabe - eine fremde Seite kann Antworten auslesen.');
    }

    // ------------------------------------------------ Sicherheitsheader

    /** Die defensiven Header stehen auf einer normalen Seite. */
    public function test_sicherheitsheader_stehen_auf_jeder_seite(): void
    {
        $header = $this->get('/login')->headers;

        $this->assertSame('nosniff', $header->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $header->get('X-Frame-Options'));
        $this->assertNotEmpty($header->get('Referrer-Policy'));
        $this->assertNotEmpty($header->get('Content-Security-Policy'));
        $this->assertStringNotContainsString("'unsafe-inline'",
            explode('script-src', (string) $header->get('Content-Security-Policy'))[1] ?? '',
            "script-src traegt wieder 'unsafe-inline'.");
    }
}
