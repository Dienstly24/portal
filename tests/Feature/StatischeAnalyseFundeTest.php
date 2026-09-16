<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\Ai\Assistant\Sales\ConversationContext;
use App\Services\Ai\Assistant\Sales\InternalVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Drei ECHTE Fehler, die erst die statische Analyse gezeigt hat
 * (Audit 15.09.2026, P3-17).
 *
 * Alle drei sind derselbe Bautyp: ein Zugriff auf eine Eigenschaft, die
 * es GAR NICHT GIBT. PHP meldet das nicht - der Ausdruck ist dann
 * einfach null. Es gibt keinen Fehler, keine Logzeile, keine kaputte
 * Seite. Es kommt nur ein schlechteres Ergebnis heraus, und zwar immer:
 *
 *  1. `Customer::$email` existiert nicht (die Adresse haengt am
 *     BENUTZER). Der Verkaufsassistent hielt die E-Mail deshalb IMMER
 *     fuer unbekannt und fragte auch den Bestandskunden danach -
 *     ausgerechnet die Regel "NIE ZWEIMAL FRAGEN".
 *  2. Dieselbe Eigenschaft in der stillen Pruefung: die genannte
 *     Adresse wurde nur gegen die ZWEITadresse gehalten. Nannte der
 *     Kunde seine richtige Hauptadresse, galt sie als "weicht ab" -
 *     und der Vorgang ging an einen Mitarbeiter, obwohl alles stimmte.
 *  3. `Document::$mime_type` existiert nicht (die Tabelle fuehrt keine
 *     mime-Spalte). Jedes Dokument galt damit als
 *     application/octet-stream, und die kostenlose, fehlerfreie
 *     PDF-Textebene wurde nie angestossen.
 */
class StatischeAnalyseFundeTest extends TestCase
{
    use RefreshDatabase;

    private function kunde(array $userAttribute = [], array $kundenAttribute = []): Customer
    {
        $user = User::factory()->create(array_merge([
            'role' => 'customer',
            'email' => 'kunde'.uniqid().'@example.de',
            'name' => 'Abdulwahab Ibrahim',
        ], $userAttribute));

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => '26'.str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ], $kundenAttribute));
    }

    // ------------------------------------------------ 1. Verkaufskontext

    public function test_hinterlegte_adresse_gilt_als_bekannt(): void
    {
        $kunde = $this->kunde(['email' => 'echte.adresse@example.de']);

        $bekannt = (new ConversationContext(
            AiConversation::forCustomer($kunde->id),
            $kunde
        ))->known();

        $this->assertSame('liegt vor', $bekannt['email'] ?? null);
    }

    public function test_der_wert_der_adresse_verlaesst_die_akte_nicht(): void
    {
        $kunde = $this->kunde(['email' => 'geheim@example.de']);

        $bekannt = (new ConversationContext(
            AiConversation::forCustomer($kunde->id),
            $kunde
        ))->known();

        // Wie beim Geburtsdatum: das Modell erfaehrt, DASS etwas vorliegt,
        // nie WAS. Sonst stuende die Adresse im Prompt eines fremden
        // Dienstes.
        $this->assertStringNotContainsString('geheim@example.de', json_encode($bekannt));
    }

    public function test_interner_platzhalter_gilt_nicht_als_adresse(): void
    {
        $kunde = $this->kunde(['email' => 'import-123@dienstly24.internal']);

        $bekannt = (new ConversationContext(
            AiConversation::forCustomer($kunde->id),
            $kunde
        ))->known();

        // Eine Adresse, die keine Post empfangen kann, ist keine Adresse -
        // hier muss der Assistent sehr wohl nachfragen.
        $this->assertArrayNotHasKey('email', $bekannt);
    }

    public function test_zweitadresse_zaehlt_ebenfalls(): void
    {
        $kunde = $this->kunde(
            ['email' => 'import-9@dienstly24.internal'],
            ['email2' => 'privat@example.de']
        );

        $bekannt = (new ConversationContext(
            AiConversation::forCustomer($kunde->id),
            $kunde
        ))->known();

        $this->assertSame('liegt vor', $bekannt['email'] ?? null);
    }

    // ------------------------------------------------- 2. Stille Pruefung

    public function test_richtige_hauptadresse_besteht_die_pruefung(): void
    {
        $kunde = $this->kunde(['email' => 'max.muster@example.de']);
        $gespraech = AiConversation::forCustomer($kunde->id);
        $gespraech->forceFill(['collected' => ['email' => 'Max.Muster@example.de']])->save();

        $ergebnis = app(InternalVerificationService::class)->verify($gespraech->fresh(), $kunde);

        $this->assertSame('passt', $ergebnis['checks']['email'] ?? null);
        $this->assertSame(InternalVerificationService::PASSED, $ergebnis['status']);
    }

    public function test_falsche_adresse_weicht_weiterhin_ab(): void
    {
        $kunde = $this->kunde(['email' => 'max.muster@example.de']);
        $gespraech = AiConversation::forCustomer($kunde->id);
        $gespraech->forceFill(['collected' => ['email' => 'jemand.anderes@example.de']])->save();

        $ergebnis = app(InternalVerificationService::class)->verify($gespraech->fresh(), $kunde);

        $this->assertSame('weicht ab', $ergebnis['checks']['email'] ?? null);
        $this->assertSame(InternalVerificationService::FAILED, $ergebnis['status']);
    }

    public function test_platzhalter_adresse_ist_kein_massstab(): void
    {
        $kunde = $this->kunde(['email' => 'import-7@dienstly24.internal']);
        $gespraech = AiConversation::forCustomer($kunde->id);
        $gespraech->forceFill(['collected' => ['email' => 'kunde@example.de']])->save();

        $ergebnis = app(InternalVerificationService::class)->verify($gespraech->fresh(), $kunde);

        // Im Bestand steht nichts Vergleichbares: das ist keine Abweichung,
        // aber auch kein Nachweis.
        $this->assertArrayNotHasKey('email', $ergebnis['checks']);
    }

    // ------------------------------------------------ 3. Inhaltstyp

    public function test_dokument_kennt_seinen_inhaltstyp(): void
    {
        $kunde = $this->kunde();

        $faelle = [
            'police.pdf' => 'application/pdf',
            'Foto.JPG' => 'image/jpeg',
            'scan.png' => 'image/png',
            'karte.webp' => 'image/webp',
            'unterlagen.docx' => 'application/octet-stream',
        ];

        foreach ($faelle as $datei => $erwartet) {
            $dokument = new Document(['customer_id' => $kunde->id, 'file_name' => $datei]);
            $this->assertSame($erwartet, $dokument->mimeType(), $datei);
        }
    }

    public function test_pdf_wird_als_pdf_erkannt_nicht_als_binaerklumpen(): void
    {
        // Der eigentliche Fund: frueher galt JEDES Dokument als
        // application/octet-stream, damit lief der Rohtext-Weg nie ueber
        // die PDF-Textebene, sondern immer ueber die OCR.
        $dokument = new Document(['file_name' => 'Versicherungsschein.pdf']);

        $this->assertStringContainsString('pdf', $dokument->mimeType());
        $this->assertNotSame('application/octet-stream', $dokument->mimeType());
    }
}
