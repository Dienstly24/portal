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
                $app->make(\App\Services\Ai\TemplateParsers\DaDirektKfzPoliceParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\BayerischeEscooterParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\KkhBeitrittserklaerungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\NovitasBeitrittserklaerungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\FamilienversicherungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\ErsatzbescheinigungParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\GesundheitskarteParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\DslAuftragParser::class),
                $app->make(\App\Services\Ai\TemplateParsers\EnergieAuftragParser::class),
                // Zuletzt: kompakter Kontaktdaten-Block (nur wenn kein echtes
                // Dokument passt - er triggert auf E-Mail+IBAN+PLZ in kurzem Text).
                $app->make(\App\Services\Ai\TemplateParsers\KontaktdatenBlockParser::class),
            ]),
        );

        // KI-Anbieter der Dokumentanalyse: per Konfiguration waehlbar, damit
        // ein weiterer Anbieter spaeter ohne Umbau des restlichen Systems
        // ergaenzt werden kann (siehe DocumentAiProviderInterface).
        $this->app->bind(DocumentAiProviderInterface::class, function ($app) {
            return match (config('services.ai_document_provider', 'claude')) {
                default => $app->make(ClaudeDocumentAiProvider::class),
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
