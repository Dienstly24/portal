<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiConversationEvent;
use App\Models\AiKnowledgeEntry;
use App\Models\AiLead;
use App\Models\AiOffer;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\SystemSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\Assistant\CustomerAssistantService;
use App\Services\Ai\Assistant\EmployeeAssistantService;
use App\Services\Ai\Assistant\Sales\AcceptanceDetector;
use App\Services\Ai\Assistant\Sales\ConversationContext;
use App\Services\Ai\Assistant\Sales\ConversationState;
use App\Services\Ai\Assistant\Sales\InternalVerificationService;
use App\Services\Ai\Assistant\Sales\IntentClassifier;
use App\Services\Ai\Assistant\Sales\RequirementProfile;
use App\Services\Ai\Assistant\Sales\SlotExtractor;
use App\Services\Ai\Assistant\Tools\AssistantToolContext;
use App\Services\Ai\Assistant\Tools\AssistantToolRegistry;
use App\Services\Ai\Assistant\Website\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Der KI-Verkaufs- und Serviceassistent (Betreiber-Auftrag 18.08.2026).
 *
 * Geprueft werden die Zusagen, auf die sich der Betrieb verlassen muss:
 * kein Zustandssprung, keine doppelte Frage, keine erfundenen Angebote,
 * keine sensiblen Werte im Modellkontext, keine Preisgabe von
 * Pruefergebnissen und ein Website-Besucher, der strukturell nicht an
 * Kundendaten kommt.
 */
class SalesAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('ai_assistant_enabled', '1');
        SystemSetting::set('ai_assistant_auto_reply', '1');
        SystemSetting::set('ai_assistant_auto_document_request', '1');
        SystemSetting::set('ai_assistant_auto_ticket', '1');
        SystemSetting::set('ai_assistant_auto_handover', '1');
        SystemSetting::set('ai_assistant_max_replies_per_case', '10');

        config([
            'services.openai.key' => 'sk-test-nur-fuer-tests',
            'services.openai.model' => 'gpt-5',
            'services.ai_assistant_provider' => 'openai',
        ]);

        RateLimiter::clear('ai-assistant:');
    }

    // ------------------------------------------------------------- Helfer

    private function makeCustomer(array $attributes = []): Customer
    {
        $user = User::factory()->create([
            'role' => 'customer',
            'email' => 'kunde' . uniqid() . '@example.de',
            'name' => 'Abdulwahab Ibrahim',
        ]);

        return Customer::create(array_merge([
            'user_id' => $user->id,
            'customer_number' => '26' . str_pad((string) $user->id, 5, '0', STR_PAD_LEFT),
            'preferred_lang' => 'de',
        ], $attributes));
    }

    private function message(Customer $customer, string $body): CustomerMessage
    {
        return CustomerMessage::create([
            'customer_id' => $customer->id,
            'sender_id' => $customer->user_id,
            'body' => $body,
            'from_staff' => false,
        ]);
    }

    private function fakeTextResponse(string $text): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'gpt-5',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => $text]],
                ]],
                'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
            ]),
        ]);
    }

    /**
     * Erst eine Stoerung, dann eine gelungene Antwort.
     *
     * Bewusst als SEQUENZ in EINEM Http::fake: mehrere fake()-Aufrufe
     * werden zusammengefuehrt, und der ZUERST registrierte Stub gewinnt -
     * ein spaeterer fake() haette den Fehler also nie abgeloest.
     */
    private function fakeErrorThenText(string $text): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'kaputt']], 500)
                ->push([
                    'model' => 'gpt-5',
                    'output' => [[
                        'type' => 'message',
                        'content' => [['type' => 'output_text', 'text' => $text]],
                    ]],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ]),
        ]);
    }

    private function context(Customer $customer): AssistantToolContext
    {
        return new AssistantToolContext($customer, AiConversation::forCustomer($customer->id), 'de');
    }

    private function tool(string $name, array $arguments, AssistantToolContext $context): array
    {
        return app(AssistantToolRegistry::class)->execute($name, $arguments, $context);
    }

    // ------------------------------------------- Abschnitt 12: Zustaende

    public function test_zustand_springt_nie_ueber_stufen_hinweg(): void
    {
        $conversation = AiConversation::forCustomer($this->makeCustomer()->id);

        $this->assertSame(ConversationState::NEW, $conversation->state);

        // Der teuerste denkbare Fehler: vom Start direkt zu "fertig".
        $this->assertFalse($conversation->moveTo(ConversationState::CONTRACT_READY));
        $this->assertSame(ConversationState::NEW, $conversation->fresh()->state);

        // Der erlaubte Weg funktioniert.
        $this->assertTrue($conversation->moveTo(ConversationState::COLLECTING_REQUIREMENTS));
        $this->assertSame(ConversationState::COLLECTING_REQUIREMENTS, $conversation->fresh()->state);
    }

    public function test_uebergabe_an_den_menschen_ist_aus_jedem_zustand_erlaubt(): void
    {
        foreach (ConversationState::all() as $state) {
            $this->assertTrue(
                ConversationState::allows($state, ConversationState::HUMAN_REQUIRED),
                'Uebergabe muss aus ' . $state . ' moeglich sein.'
            );
        }
    }

    // --------------------------------- Abschnitte 9-11: sensible Angaben

    public function test_iban_und_geburtsdatum_werden_vor_dem_modell_herausgeloest(): void
    {
        $extractor = new SlotExtractor();
        $ergebnis = $extractor->extract(
            'Meine IBAN ist DE02120300000000202051, geboren bin ich am 12.05.1990, '
            . 'E-Mail max@example.de'
        );

        $this->assertSame('DE02120300000000202051', $ergebnis['found']['iban']);
        $this->assertSame('12.05.1990', $ergebnis['found']['birthdate']);
        $this->assertSame('max@example.de', $ergebnis['found']['email']);

        // Der Text, der zum Modell geht, enthaelt die Werte NICHT mehr.
        $this->assertStringNotContainsString('DE02120300000000202051', $ergebnis['text']);
        $this->assertStringNotContainsString('12.05.1990', $ergebnis['text']);
        $this->assertStringNotContainsString('max@example.de', $ergebnis['text']);
        $this->assertStringContainsString('[IBAN erfasst]', $ergebnis['text']);
    }

    public function test_kaputte_iban_wird_nicht_uebernommen(): void
    {
        // Eine Ziffer verdreht -> Pruefziffer stimmt nicht mehr.
        $ergebnis = (new SlotExtractor())->extract('IBAN DE02120300000000202052');

        $this->assertArrayNotHasKey('iban', $ergebnis['found']);
        $this->assertStringContainsString('DE02120300000000202052', $ergebnis['text']);
    }

    public function test_wunschtermin_wird_nicht_als_geburtsdatum_gelesen(): void
    {
        $ergebnis = (new SlotExtractor())->extract('Der Anschluss soll am 01.09.2026 starten.');

        $this->assertArrayNotHasKey('birthdate', $ergebnis['found']);
    }

    public function test_kundennachricht_mit_iban_erreicht_das_modell_nicht(): void
    {
        $customer = $this->makeCustomer();
        $this->fakeTextResponse('Vielen Dank, Ihre Angaben sind eingegangen.');

        app(CustomerAssistantService::class)->handleCustomerMessage(
            $this->message($customer, 'Meine IBAN lautet DE02120300000000202051 fuer den Vertrag.')
        );

        Http::assertSent(function ($request) {
            $inhalt = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return !str_contains((string) $inhalt, 'DE02120300000000202051');
        });

        // Gespeichert ist sie trotzdem - nur eben serverseitig.
        $this->assertSame(
            'DE02120300000000202051',
            AiConversation::forCustomer($customer->id)->collectedData()['iban']
        );
    }

    public function test_modell_kann_sensible_felder_nicht_zurueckschreiben(): void
    {
        $customer = $this->makeCustomer();
        $context = $this->context($customer);
        $context->conversation->forceFill(['intent' => RequirementProfile::INTENT_NEW_INTERNET])->save();

        $ergebnis = $this->tool('saveCollectedInformation', [
            'angaben' => ['iban' => 'DE02120300000000202051', 'installation_address' => 'Musterweg 5'],
        ], $context);

        $this->assertArrayHasKey('iban', $ergebnis['abgelehnt']);
        $this->assertNotContains('iban', $ergebnis['gespeichert']);
        $this->assertArrayNotHasKey('iban', $context->conversation->fresh()->collectedData());
    }

    public function test_fantasiefelder_werden_abgelehnt(): void
    {
        $customer = $this->makeCustomer();
        $context = $this->context($customer);
        $context->conversation->forceFill(['intent' => RequirementProfile::INTENT_NEW_INTERNET])->save();

        $ergebnis = $this->tool('saveCollectedInformation', [
            'angaben' => ['lieblingsfarbe' => 'blau'],
        ], $context);

        $this->assertArrayHasKey('lieblingsfarbe', $ergebnis['abgelehnt']);
    }

    // ------------------------------------- Abschnitt 3: nie doppelt fragen

    public function test_bekannte_angaben_aus_der_kundenakte_gelten_als_erfasst(): void
    {
        $customer = $this->makeCustomer([
            'address_street' => 'Musterweg',
            'address_house_number' => '5',
            'address_zip' => '71522',
            'address_city' => 'Backnang',
        ]);

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->forceFill(['intent' => RequirementProfile::INTENT_NEW_INTERNET])->save();

        $sicht = new ConversationContext($conversation, $customer);
        $offen = array_column($sicht->missing('bedarf'), 'key');

        $this->assertNotContains('installation_address', $offen);
        $this->assertContains('situation', $offen);
        $this->assertStringContainsString('Musterweg 5', $sicht->forPrompt());
    }

    public function test_prompt_zeigt_sensible_angaben_nur_als_liegt_vor(): void
    {
        $customer = $this->makeCustomer();
        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->forceFill(['intent' => RequirementProfile::INTENT_NEW_INTERNET])->save();
        $conversation->remember(['iban' => 'DE02120300000000202051']);

        $prompt = (new ConversationContext($conversation->fresh(), $customer))->forPrompt();

        $this->assertStringNotContainsString('DE02120300000000202051', $prompt);
        $this->assertStringContainsString('liegt vor', $prompt);
    }

    // --------------------------------------- Abschnitte 5/7: Angebote

    public function test_ohne_hinterlegtes_angebot_gibt_es_keine_angebotsdaten(): void
    {
        $customer = $this->makeCustomer();
        $ergebnis = $this->tool('getOffers', [], $this->context($customer));

        $this->assertSame([], $ergebnis['angebote']);
        $this->assertStringContainsString('kein Angebot', $ergebnis['hinweis']);
    }

    public function test_angebot_des_mitarbeiters_wird_vorgestellt_und_auswaehlbar(): void
    {
        $customer = $this->makeCustomer();
        $context = $this->context($customer);
        $conversation = $context->conversation;
        $conversation->forceFill([
            'intent' => RequirementProfile::INTENT_NEW_INTERNET,
            'state' => ConversationState::WAITING_FOR_OFFER,
        ])->save();

        AiOffer::create([
            'conversation_id' => $conversation->id,
            'label' => 'A',
            'provider' => 'Vodafone',
            'product' => 'Kabel 250',
            'speed' => '250 MBit/s',
            'price' => 39.99,
        ]);

        $angebote = $this->tool('getOffers', [], $context);
        $this->assertCount(1, $angebote['angebote']);
        $this->assertSame(ConversationState::OFFER_PRESENTED, $conversation->fresh()->state);

        $auswahl = $this->tool('recordOfferSelection', ['kennung' => 'A'], $context);
        $this->assertTrue($auswahl['gespeichert']);
        $this->assertSame(ConversationState::COLLECTING_CONTRACT_DATA, $conversation->fresh()->state);
    }

    public function test_auswahl_einer_unbekannten_kennung_wird_abgelehnt(): void
    {
        $customer = $this->makeCustomer();
        $ergebnis = $this->tool('recordOfferSelection', ['kennung' => 'Z'], $this->context($customer));

        $this->assertArrayHasKey('fehler', $ergebnis);
    }

    // ------------------------------------ Abschnitt 4: Zustimmung erkennen

    public function test_zustimmung_wird_auch_ohne_das_wort_ja_erkannt(): void
    {
        $detector = new AcceptanceDetector();

        foreach (['Passt so.', 'Das nehme ich', 'einverstanden', 'خلاص نكمل', 'sounds good'] as $satz) {
            $this->assertTrue($detector->check($satz, ['A'])['accepted'], $satz);
        }
    }

    public function test_absage_wird_nie_als_zustimmung_gelesen(): void
    {
        $detector = new AcceptanceDetector();

        foreach (['Nein, doch nicht', 'Das ist mir zu teuer', 'ich moechte es mir ueberlegen'] as $satz) {
            $ergebnis = $detector->check($satz, ['A']);
            $this->assertFalse($ergebnis['accepted'], $satz);
            $this->assertTrue($ergebnis['rejected'], $satz);
        }
    }

    public function test_bei_zwei_angeboten_wird_ohne_benennung_nichts_gewaehlt(): void
    {
        $customer = $this->makeCustomer();
        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->forceFill(['state' => ConversationState::OFFER_PRESENTED])->save();

        foreach (['A', 'B'] as $label) {
            AiOffer::create([
                'conversation_id' => $conversation->id,
                'label' => $label,
                'product' => 'Tarif ' . $label,
            ]);
        }

        $this->fakeTextResponse('Welches der beiden Angebote möchten Sie?');
        app(CustomerAssistantService::class)->handleCustomerMessage(
            $this->message($customer, 'Passt, machen wir.')
        );

        // Kein geratenes Angebot - der Zustand bleibt bei der Entscheidung.
        $this->assertNull($conversation->fresh()->selected_offer_id);
    }

    // -------------------------------- Abschnitte 10/11: stille Verifikation

    public function test_pruefung_meldet_nur_das_gesamtergebnis(): void
    {
        $customer = $this->makeCustomer([
            'iban' => 'DE02120300000000202051',
            'birth_date' => '1990-05-12',
        ]);

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->remember([
            'iban' => 'DE02120300000000202051',
            'birthdate' => '12.05.1990',
        ]);

        $ergebnis = app(InternalVerificationService::class)->verify($conversation->fresh(), $customer);

        $this->assertSame(InternalVerificationService::PASSED, $ergebnis['status']);
        $this->assertSame('passt', $ergebnis['checks']['iban']);
    }

    public function test_abweichung_fuehrt_zu_failed_ohne_grund_fuer_den_kunden(): void
    {
        $customer = $this->makeCustomer(['iban' => 'DE02120300000000202051']);

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->remember(['iban' => 'DE89370400440532013000']);

        $ergebnis = app(InternalVerificationService::class)->verify($conversation->fresh(), $customer);
        $this->assertSame(InternalVerificationService::FAILED, $ergebnis['status']);

        // Der Kundentext nennt weder Feld noch Grund.
        $hinweis = InternalVerificationService::customerHint($ergebnis['status'], 'de');
        $this->assertStringNotContainsString('IBAN', $hinweis);
        $this->assertStringNotContainsString('nicht', mb_strtolower($hinweis));
    }

    public function test_werkzeug_gibt_keine_pruefpunkte_an_das_modell(): void
    {
        $customer = $this->makeCustomer([
            'iban' => 'DE02120300000000202051',
            'birth_date' => '1990-05-12',
            'email' => 'kunde@example.de',
        ]);

        $context = $this->context($customer);
        $context->conversation->forceFill([
            'intent' => RequirementProfile::INTENT_NEW_INTERNET,
            'state' => ConversationState::COLLECTING_CONTRACT_DATA,
        ])->save();
        $context->conversation->remember([
            'full_name' => 'Abdulwahab Ibrahim',
            'iban' => 'DE02120300000000202051',
            'birthdate' => '12.05.1990',
            'email' => 'kunde@example.de',
        ]);

        $ergebnis = $this->tool('submitContractData', [], $context);

        $this->assertTrue($ergebnis['eingereicht']);
        $this->assertSame(InternalVerificationService::PASSED, $ergebnis['ergebnis']);
        // Entscheidend: keine Einzelheiten im Ergebnis.
        $this->assertArrayNotHasKey('checks', $ergebnis);
        $this->assertArrayNotHasKey('punkte', $ergebnis);
    }

    // ---------------------------------- Abschnitt 13: Stoerung ist sichtbar

    public function test_stoerung_wird_am_gespraech_vermerkt(): void
    {
        $customer = $this->makeCustomer();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'kaputt']], 500)]);

        app(CustomerAssistantService::class)->handleCustomerMessage(
            $this->message($customer, 'Ich haette gern ein Internetangebot.')
        );

        $conversation = AiConversation::forCustomer($customer->id)->fresh();

        $this->assertTrue($conversation->isPaused());
        $this->assertNotEmpty($conversation->paused_reason);
        $this->assertSame('Antwort erstellen', $conversation->current_step);
        $this->assertNotNull($conversation->last_error_at);

        $this->assertDatabaseHas('ai_conversation_events', [
            'conversation_id' => $conversation->id,
            'event' => AiConversationEvent::EVENT_ERROR,
        ]);
    }

    public function test_gelungene_runde_raeumt_die_stoerung_ab(): void
    {
        $customer = $this->makeCustomer();
        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->pause('alter Fehler', 'Antwort erstellen');

        $this->fakeTextResponse('Guten Tag, gerne helfe ich Ihnen weiter.');
        app(CustomerAssistantService::class)->handleCustomerMessage(
            $this->message($customer, 'Wie ist der Stand meines Vorgangs?')
        );

        $conversation = $conversation->fresh();
        $this->assertFalse($conversation->isPaused());
        $this->assertNull($conversation->paused_reason);
        $this->assertSame('Antwort an den Kunden', $conversation->last_successful_step);
    }

    // ------------------------------ Abschnitt 14: Kontext ueberlebt Fehler

    public function test_gesammelte_angaben_ueberleben_eine_stoerung(): void
    {
        $customer = $this->makeCustomer();

        // Erste Runde gelingt, zweite faellt aus.
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push([
                    'model' => 'gpt-5',
                    'output' => [[
                        'type' => 'message',
                        'content' => [['type' => 'output_text', 'text' => 'Danke, notiert.']],
                    ]],
                ])
                ->push(['error' => ['message' => 'kaputt']], 500),
        ]);

        app(CustomerAssistantService::class)->handleCustomerMessage(
            $this->message($customer, 'Meine E-Mail ist kunde@example.de')
        );
        app(CustomerAssistantService::class)->handleCustomerMessage(
            $this->message($customer, 'Und weiter?')
        );

        $this->assertTrue(AiConversation::forCustomer($customer->id)->fresh()->isPaused());

        $this->assertSame(
            'kunde@example.de',
            AiConversation::forCustomer($customer->id)->fresh()->collectedData()['email']
        );
    }

    // -------------------------------- Abschnitte 15/16: Mitarbeiter-Hilfen

    public function test_briefing_zeigt_stand_ohne_sensible_werte(): void
    {
        $customer = $this->makeCustomer();
        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->forceFill([
            'intent' => RequirementProfile::INTENT_NEW_INTERNET,
            'state' => ConversationState::WAITING_FOR_OFFER,
        ])->save();
        $conversation->remember([
            'installation_address' => 'Musterweg 5, 71522 Backnang',
            'situation' => 'Umzug',
            'iban' => 'DE02120300000000202051',
        ]);

        $briefing = app(EmployeeAssistantService::class)->briefing($customer, $conversation->fresh());

        $this->assertSame('Neuer Internetanschluss', $briefing['anliegen']);
        $this->assertTrue($briefing['wartet_auf_mitarbeiter']);
        $this->assertSame('Angebot hinterlegen (Mitarbeiter)', $briefing['naechster_schritt']);
        $this->assertContains('Musterweg 5, 71522 Backnang', $briefing['bekannt']);
        $this->assertNotContains('DE02120300000000202051', $briefing['bekannt']);
    }

    public function test_antwortvorschlag_wird_nie_automatisch_gesendet(): void
    {
        $customer = $this->makeCustomer();
        $this->message($customer, 'Wann kommt mein Anschluss?');
        $this->fakeTextResponse('Ihr Anliegen liegt uns vor, ein Mitarbeiter meldet sich.');

        $vorher = CustomerMessage::where('customer_id', $customer->id)->count();

        $ergebnis = app(EmployeeAssistantService::class)
            ->suggestReply($customer, AiConversation::forCustomer($customer->id));

        $this->assertNotNull($ergebnis['vorschlag']);
        $this->assertSame($vorher, CustomerMessage::where('customer_id', $customer->id)->count());
    }

    // ---------------------------- Abschnitte 19/20: Website und Interessent

    public function test_website_assistent_hat_keinen_zugriff_auf_kundendaten(): void
    {
        $werkzeuge = app(\App\Services\Ai\Assistant\Website\LeadToolRegistry::class)->names();

        $this->assertSame(['searchKnowledge', 'saveLeadInformation', 'requestHumanContact'], $werkzeuge);

        foreach (['getCustomerProfile', 'getCustomerContracts', 'getMissingDocuments', 'getOpenTickets'] as $verboten) {
            $this->assertNotContains($verboten, $werkzeuge);
        }
    }

    public function test_website_wissensbasis_zeigt_keine_internen_eintraege(): void
    {
        AiKnowledgeEntry::create([
            'title' => 'Interner Ablauf Eskalation',
            'category' => 'prozess',
            'content' => 'Interne Anweisung fuer Mitarbeiter zur Eskalation.',
            'active' => true,
        ]);
        AiKnowledgeEntry::create([
            'title' => 'Benoetigte Unterlagen Internet',
            'category' => 'dokumente',
            'content' => 'Fuer einen Internetvertrag benoetigen wir Ausweis und Adresse.',
            'active' => true,
        ]);

        $lead = app(LeadService::class)->forSession((string) \Illuminate\Support\Str::uuid());
        $ergebnis = app(\App\Services\Ai\Assistant\Website\Tools\SearchPublicKnowledgeTool::class)
            ->run(['suchbegriff' => 'Eskalation Unterlagen Internet'],
                new \App\Services\Ai\Assistant\Website\LeadContext($lead, 'de'));

        $titel = array_column($ergebnis['eintraege'], 'titel');
        $this->assertContains('Benoetigte Unterlagen Internet', $titel);
        $this->assertNotContains('Interner Ablauf Eskalation', $titel);
    }

    public function test_website_assistent_erfasst_keine_sensiblen_angaben(): void
    {
        $lead = app(LeadService::class)->forSession((string) \Illuminate\Support\Str::uuid());
        $context = new \App\Services\Ai\Assistant\Website\LeadContext($lead, 'de');

        app(\App\Services\Ai\Assistant\Website\Tools\SaveLeadInformationTool::class)->run([
            'angaben' => ['name' => 'Max Muster', 'iban' => 'DE02120300000000202051', 'birthdate' => '12.05.1990'],
        ], $context);

        $daten = $lead->fresh()->collectedData();
        $this->assertSame('Max Muster', $daten['name']);
        $this->assertArrayNotHasKey('iban', $daten);
        $this->assertArrayNotHasKey('birthdate', $daten);
    }

    public function test_lead_wird_mit_vorgang_an_das_team_uebergeben(): void
    {
        $lead = app(LeadService::class)->forSession((string) \Illuminate\Support\Str::uuid(), 'Ich brauche neues Internet');
        $lead->remember(['installation_address' => 'Musterweg 5', 'situation' => 'Umzug']);
        $lead->forceFill(['contact' => ['name' => 'Max Muster', 'email' => 'max@example.de']])->save();

        $ticket = app(LeadService::class)->handOver($lead->fresh(), 'angebot');

        $this->assertNotNull($ticket);
        $this->assertSame('website', $ticket->source);
        $this->assertSame('max@example.de', $ticket->guest_email);
        $this->assertStringContainsString('Musterweg 5', $ticket->description);

        // Zweite Uebergabe erzeugt keinen zweiten Vorgang.
        app(LeadService::class)->handOver($lead->fresh(), 'frage');
        $this->assertSame(1, Ticket::where('source', 'website')->count());
    }

    public function test_website_chat_nimmt_keine_lead_kennung_aus_dem_request(): void
    {
        $fremd = AiLead::create(['source' => AiLead::SOURCE_WEBSITE]);
        $fremd->remember(['installation_address' => 'Geheime Strasse 1']);

        $this->fakeTextResponse('Gerne helfe ich Ihnen weiter.');

        $antwort = $this->postJson('/api/website-assistent', [
            'nachricht' => 'Ich interessiere mich fuer Internet.',
            'lead_id' => $fremd->id,
        ]);

        $antwort->assertOk();

        // Der fremde Lead wurde nicht angefasst.
        $this->assertSame([], array_diff_key(
            $fremd->fresh()->transcriptData(),
            []
        ));
        $this->assertSame(2, AiLead::count());
    }

    // ---------------------------------- Abschnitt 21: Klassifizierung

    public function test_anliegen_wird_grob_erkannt(): void
    {
        $classifier = new IntentClassifier();

        $this->assertSame(RequirementProfile::INTENT_NEW_INTERNET, $classifier->classify('Ich brauche einen neuen Internetanschluss.'));
        $this->assertSame(RequirementProfile::INTENT_CONTRACT_CHANGE, $classifier->classify('Ich moechte meinen Vertrag wechseln.'));
        $this->assertSame(RequirementProfile::INTENT_TECHNICAL_SUPPORT, $classifier->classify('Mein Internet geht nicht.'));
        $this->assertSame(RequirementProfile::INTENT_GENERAL_QUESTION, $classifier->classify('Guten Tag, eine kurze Frage.'));
    }

    // ------------------------------------- Abschnitt 23: Ereignisprotokoll

    public function test_protokoll_haelt_zustandswechsel_ohne_werte_fest(): void
    {
        $customer = $this->makeCustomer();
        $context = $this->context($customer);

        $this->tool('setConversationIntent', ['intent' => RequirementProfile::INTENT_NEW_INTERNET], $context);
        $this->tool('saveCollectedInformation', [
            'angaben' => ['installation_address' => 'Musterweg 5, 71522 Backnang'],
        ], $context);

        $this->assertDatabaseHas('ai_conversation_events', [
            'conversation_id' => $context->conversation->id,
            'event' => AiConversationEvent::EVENT_STATE,
            'to_state' => ConversationState::COLLECTING_REQUIREMENTS,
        ]);

        $erfasst = AiConversationEvent::where('event', AiConversationEvent::EVENT_COLLECTED)->first();
        $this->assertNotNull($erfasst);
        // Nur Feldnamen, nie Werte.
        $inhalt = json_encode($erfasst->detail, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Musterweg', (string) $inhalt);
        $this->assertStringContainsString('Anschlussadresse', (string) $inhalt);
    }

    // -------------------------------- Abschnitt 5: Mitarbeiter-Aktionen

    public function test_mitarbeiter_hinterlegt_angebot_und_ki_darf_weiterfuehren(): void
    {
        $customer = $this->makeCustomer();
        $staff = User::factory()->create(['role' => 'admin']);

        $conversation = AiConversation::forCustomer($customer->id);
        $conversation->forceFill([
            'intent' => RequirementProfile::INTENT_NEW_INTERNET,
            'state' => ConversationState::WAITING_FOR_OFFER,
        ])->save();

        $this->actingAs($staff)
            ->post(route('admin.ai_assistant.offer.store', $customer->id), [
                'label' => 'A',
                'provider' => 'Vodafone',
                'product' => 'Kabel 250',
                'speed' => '250 MBit/s',
                'price' => '39.99',
                'price_period' => 'Monat',
                'duration_months' => '24',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('ai_offers', ['label' => 'A', 'product' => 'Kabel 250']);
        $this->assertSame(ConversationState::OFFER_PRESENTED, $conversation->fresh()->state);
    }

    public function test_gewaehltes_angebot_kann_nicht_geloescht_werden(): void
    {
        $customer = $this->makeCustomer();
        $staff = User::factory()->create(['role' => 'admin']);
        $conversation = AiConversation::forCustomer($customer->id);

        $angebot = AiOffer::create([
            'conversation_id' => $conversation->id,
            'label' => 'A',
            'product' => 'Kabel 250',
            'selected_at' => now(),
        ]);

        $this->actingAs($staff)
            ->delete(route('admin.ai_assistant.offer.destroy', [$customer->id, $angebot->id]))
            ->assertRedirect();

        $this->assertDatabaseHas('ai_offers', ['id' => $angebot->id]);
    }

    // ---------------------------------- Abschnitt 17: Stil lernen

    public function test_leitfaden_entwurf_wird_nie_automatisch_aktiv(): void
    {
        $customer = $this->makeCustomer();
        $staff = User::factory()->create(['role' => 'support']);

        for ($i = 0; $i < 25; $i++) {
            CustomerMessage::create([
                'customer_id' => $customer->id,
                'sender_id' => $staff->id,
                'body' => 'Guten Tag, vielen Dank für Ihre Nachricht. '
                    . 'Wir haben Ihren Vorgang geprüft und melden uns mit dem Ergebnis. '
                    . 'Können Sie uns noch die Vertragsnummer nennen?',
                'from_staff' => true,
            ]);
        }

        $this->artisan('ki:leitfaden-entwurf', ['--schreiben' => true])
            ->assertExitCode(0);

        $entwurf = AiKnowledgeEntry::where('category', 'leitfaden')->first();

        $this->assertNotNull($entwurf);
        // Entscheidend: der Entwurf ist INAKTIV, bis ein Mensch ihn freigibt.
        $this->assertFalse((bool) $entwurf->active);
    }

    public function test_leitfaden_braucht_genug_material(): void
    {
        $this->artisan('ki:leitfaden-entwurf')->assertExitCode(1);
    }

    public function test_erneut_versuchen_stoesst_die_letzte_nachricht_neu_an(): void
    {
        $customer = $this->makeCustomer();
        $staff = User::factory()->create(['role' => 'admin']);

        $this->fakeErrorThenText('Gerne, dafür brauche ich noch Ihre Anschlussadresse.');

        app(CustomerAssistantService::class)->handleCustomerMessage(
            $this->message($customer, 'Ich haette gern ein Angebot.')
        );

        $this->assertTrue(AiConversation::forCustomer($customer->id)->fresh()->isPaused());

        $this->actingAs($staff)
            ->post(route('admin.ai_assistant.retry', $customer->id))
            ->assertRedirect();

        $conversation = AiConversation::forCustomer($customer->id)->fresh();
        $this->assertFalse($conversation->isPaused());
    }
}
