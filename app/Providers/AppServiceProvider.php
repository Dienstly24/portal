<?php

namespace App\Providers;

use App\Services\Activity\ActivityCatalog;
use App\Services\Activity\ActivityTracker;
use App\Services\Ai\ClaudeDocumentAiProvider;
use App\Services\Ai\ClaudeTextProvider;
use App\Services\Ai\Contracts\AiProviderInterface;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ocr\TesseractTextExtractor;
use App\Services\Ocr\TextExtractorInterface;
use App\Services\Workflow\Handlers\ApplyChangeStepHandler;
use App\Services\Workflow\Handlers\DraftReplyStepHandler;
use App\Services\Workflow\Handlers\ExtractDataStepHandler;
use App\Services\Workflow\Handlers\RequestDocumentStepHandler;
use App\Services\Workflow\Handlers\ReviewStepHandler;
use App\Services\Workflow\StepHandlerRegistry;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton, damit Punkte-Overrides pro Request nur einmal
        // aus den Einstellungen gelesen werden.
        $this->app->singleton(ActivityCatalog::class);

        // Zentraler Notification-Dienst (Glocke): eine Stelle fuer Kuerzen,
        // Duplikat-Vermeidung und Kategorisierung. Facade: App\Support\Facades\Notify.
        $this->app->singleton(\App\Services\Notifications\NotificationService::class);

        // OCR-Basisebene des Smart Document Upload - austauschbar, falls
        // spaeter ein anderer OCR-Dienst als Tesseract eingesetzt wird.
        $this->app->bind(TextExtractorInterface::class, TesseractTextExtractor::class);

        // Gratis-Parser fuer bekannte, immer gleich aufgebaute Formulare
        // (CHECK24-Kfz-Beratungsprotokoll, KKH-Beitrittserklaerung, Familien-
        // versicherungs-Fragebogen). Weitere Templates: einfach in die Liste
        // aufnehmen. Trifft kein Template zu -> null, dann laeuft die normale
        // Analyse (Heuristik/KI).
        $this->app->bind(
            \App\Services\Ai\Contracts\DocumentTemplateParser::class,
            fn ($app) => new \App\Services\Ai\TemplateParsers\CompositeDocumentTemplateParser([
                $app->make(\App\Services\Ai\TemplateParsers\Check24KfzProtocolParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\AdacAutoversicherungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\AdacMitgliedschaftParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\DaDirektKfzPoliceParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\AllianzKfzPoliceParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\AdmiralDirektKfzParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\EuropaGoKfzParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\SparkasseDirektKfzParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\WgvKfzPoliceParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\NafiKfzAntragParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\BayerischeEscooterParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\KkhBeitrittserklaerungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\NovitasBeitrittserklaerungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\BigGesundParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\FamilienversicherungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\ErsatzbescheinigungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\GesundheitskarteParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\GehaltsabrechnungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\ArbeitsvertragParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\GeburtsurkundeParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\ReisepassMrzParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\MeldebestaetigungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\AufenthaltstitelParser::class),
                // Energie-Parser (Strom/Gas) VOR dem DSL-Parser: ein echter
                // Energie-Auftrag wird von seinem spezifischen Parser erkannt;
                // der breitere DSL-Parser kommt erst danach zum Zug (frueher
                // beanspruchte er Energie-Auftraege faelschlich als Internet).
                $app->make(\App\Services\Ai\TemplateParsers\EweVertragsbestaetigungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\LichtblickVertragsbestaetigungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\LichtblickAuftragParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\PlanBNetZeroAuftragParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\EnergieAuftragParser::class),
                // Auftrags-Uebersicht aus dem Vertriebsportal (Screenshot) -
                // nach den PDF-Auftraegen der Versorger, vor dem DSL-Parser.
                $app->make(\App\Services\Ai\TemplateParsers\EnergiePortalAuftragParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\DslAuftragParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\PrivathaftpflichtAntragParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\OnlineProtokollAntragParser::class),
                // Deckungsauftrag VOR der Beratungsdokumentation: beide sind
                // Fonds-Finanz-Schwesterdokumente (Vorgangsnummer). Kommt ein
                // BUENDEL-PDF mit beiden Teilen, gewinnt so der Teil mit den
                // VERTRAGSDATEN (Versicherer/Praemie, Stufe antrag) - die reine
                // Beratungsdoku ohne "Deckungsauftrag"-Wort bleibt unberuehrt
                // (der Deckungsauftrag-Parser weicht ihr nachweislich aus).
                $app->make(\App\Services\Ai\TemplateParsers\DeckungsauftragParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\GewerbeBeratungsdokumentationParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\AndsafeGewerbePoliceParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\InterlloydPoliceParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\DialogFrachtfuehrerPoliceParser::class),
                // Kontakt-/SEPA-Ansicht eines Antragsportals (beschriftete
                // Felder) - VOR dem generischen Kontaktdaten-Block.
                $app->make(\App\Services\Ai\TemplateParsers\KontaktSepaDatenParser::class),
                // Zuletzt: kompakter Kontaktdaten-Block (nur wenn kein echtes
                // Dokument passt - er triggert auf E-Mail+IBAN+PLZ in kurzem Text).
                $app->make(\App\Services\Ai\TemplateParsers\KontaktdatenBlockParser::class),
            ]),
        );

        // KI-Anbieter der Dokumentanalyse: per Konfiguration waehlbar, damit
        // ein weiterer Anbieter spaeter ohne Umbau des restlichen Systems
        // ergaenzt werden kann (siehe DocumentAiProviderInterface).
        $this->app->bind(DocumentAiProviderInterface::class, function ($app) {
            // WICHTIG: LEER/nicht gesetzt bedeutet STANDARD (Claude), NICHT
            // "abgeschaltet" - frueher ergab jeder Wert (auch '') per
            // `default => Claude` den Claude-Provider. Nur AUSDRUECKLICHE
            // Abschalt-Schluessel deaktivieren die KI, damit ein leeres
            // AI_DOCUMENT_PROVIDER in der Produktion die Analyse nicht
            // versehentlich stilllegt.
            $provider = strtolower(trim((string) config('services.ai_document_provider', 'claude')));
            if ($provider === '') {
                $provider = 'claude';
            }
            return match ($provider) {
                'claude' => $app->make(ClaudeDocumentAiProvider::class),
                // Ausdruecklich abgeschaltet: nur die kostenlose Basisebene.
                'none', 'off', 'disabled' => $app->make(\App\Services\Ai\NullDocumentAiProvider::class),
                // Unbekannter Wert: nicht still verschlucken - warnen und auf
                // den Standard (Claude) zurueckfallen (Verhalten wie zuvor).
                default => tap($app->make(ClaudeDocumentAiProvider::class), function () use ($provider) {
                    \Illuminate\Support\Facades\Log::warning(
                        'Unbekannter AI_DOCUMENT_PROVIDER "' . $provider . '" - faellt auf Claude zurueck '
                        . '(gueltig: claude, none).'
                    );
                }),
            };
        });

        // Provider-unabhaengige LLM-Schicht der Workflow-Engine (Saeule 8):
        // ein weiterer Anbieter (OpenAI, Gemini, Azure) braucht nur eine
        // neue Implementierung + einen Zweig hier, keine Engine-Aenderung.
        $this->app->bind(AiProviderInterface::class, function ($app) {
            return match (config('services.ai_text_provider', 'claude')) {
                default => $app->make(ClaudeTextProvider::class),
            };
        });

        // KI-Kundenassistent (Betreiber-Auftrag 17.08.2026): austauschbarer
        // Anbieter mit Tool-Calling. 'none' schaltet ihn hart ab; ein leerer
        // Wert bedeutet STANDARD (openai), nicht "aus" - gleiche Lehre wie
        // beim Dokument-Anbieter oben.
        $this->app->bind(
            \App\Services\Ai\Assistant\Contracts\AssistantProviderInterface::class,
            function ($app) {
                $provider = strtolower(trim((string) config('services.ai_assistant_provider', 'openai')));
                if ($provider === '') {
                    $provider = 'openai';
                }
                return match ($provider) {
                    'openai' => $app->make(\App\Services\Ai\Assistant\OpenAiAssistantProvider::class),
                    'none', 'off', 'disabled' => $app->make(\App\Services\Ai\Assistant\NullAssistantProvider::class),
                    default => tap($app->make(\App\Services\Ai\Assistant\OpenAiAssistantProvider::class), function () use ($provider) {
                        \Illuminate\Support\Facades\Log::warning(
                            'Unbekannter AI_ASSISTANT_PROVIDER "' . $provider . '" - faellt auf OpenAI zurueck '
                            . '(gueltig: openai, none).'
                        );
                    }),
                };
            }
        );

        // WHITELIST der Funktionen, die der Kundenassistent aufrufen darf
        // (Spezifikation Abschnitt 6/7). Was hier NICHT steht, kann die KI
        // nicht ausfuehren - es gibt bewusst kein Tool fuer freie
        // Datenbankabfragen, Vertragsaenderungen, Kuendigungen oder
        // Zahlungen. Neue Faehigkeit = hier eine Zeile, nach Freigabe des
        // Betreibers.
        $this->app->singleton(
            \App\Services\Ai\Assistant\Tools\AssistantToolRegistry::class,
            fn ($app) => new \App\Services\Ai\Assistant\Tools\AssistantToolRegistry([
                // Lesend (immer erlaubt)
                $app->make(\App\Services\Ai\Assistant\Tools\GetCustomerProfileTool::class),
                $app->make(\App\Services\Ai\Assistant\Tools\GetCustomerContractsTool::class),
                $app->make(\App\Services\Ai\Assistant\Tools\GetRelevantContractInformationTool::class),
                $app->make(\App\Services\Ai\Assistant\Tools\GetOpenTicketsTool::class),
                $app->make(\App\Services\Ai\Assistant\Tools\GetProcessStatusTool::class),
                $app->make(\App\Services\Ai\Assistant\Tools\GetRequiredDocumentsTool::class),
                $app->make(\App\Services\Ai\Assistant\Tools\GetMissingDocumentsTool::class),
                $app->make(\App\Services\Ai\Assistant\Tools\GetDocumentStatusTool::class),
                $app->make(\App\Services\Ai\Assistant\Tools\SearchKnowledgeTool::class),
                // Schreibend (jedes prueft zusaetzlich seinen Schalter)
                $app->make(\App\Services\Ai\Assistant\Tools\CreateTicketTool::class),
                $app->make(\App\Services\Ai\Assistant\Tools\RequestDocumentTool::class),
                $app->make(\App\Services\Ai\Assistant\Tools\EscalateToTeamTool::class),
            ])
        );

        // Registry der Workflow-Step-Handler (Blueprint Saeule 1): Typ ->
        // Handler. Neue Schritt-Typen werden hier additiv registriert, der
        // Engine-Kern bleibt unveraendert.
        $this->app->singleton(StepHandlerRegistry::class, function ($app) {
            return (new StepHandlerRegistry())
                ->register($app->make(ReviewStepHandler::class))
                ->register($app->make(RequestDocumentStepHandler::class))
                ->register($app->make(ExtractDataStepHandler::class))
                ->register($app->make(ApplyChangeStepHandler::class))
                ->register($app->make(DraftReplyStepHandler::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fail-fast, falls Produktion versehentlich auf SQLite laeuft (Audit DB-6):
        // der committete Default ist SQLite; ohne korrekte .env faellt die App
        // sonst still auf eine lokale database.sqlite zurueck (Daten-Divergenz,
        // verborgene MySQL-only-Fehler). Konsolenlauf (Migrationen/Tests) bleibt
        // ausgenommen, damit CI/Artisan nicht blockiert wird.
        if ($this->app->environment('production')
            && ! $this->app->runningInConsole()
            && config('database.default') !== 'mysql') {
            throw new \RuntimeException(
                'Ungueltige DB-Konfiguration: In Produktion muss DB_CONNECTION=mysql gesetzt sein '
                . '(aktuell: ' . config('database.default') . ').'
            );
        }

        // Aktivitaetserfassung: Arbeitssitzungen an Login/Logout koppeln.
        // Fehler in der Erfassung duerfen Login/Logout nie blockieren.
        Event::listen(Login::class, function (Login $event): void {
            try {
                if ($event->user instanceof \App\Models\User) {
                    app(ActivityTracker::class)->handleLogin($event->user, request());
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        Event::listen(Logout::class, function (Logout $event): void {
            try {
                if ($event->user instanceof \App\Models\User) {
                    app(ActivityTracker::class)->handleLogout($event->user, request());
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
