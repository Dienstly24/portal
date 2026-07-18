<?php

namespace App\Providers;

use App\Services\Activity\ActivityCatalog;
use App\Services\Activity\ActivityTracker;
use App\Services\Ai\ClaudeDocumentAiProvider;
use App\Services\Ai\Contracts\DocumentAiProviderInterface;
use App\Services\Ocr\TesseractTextExtractor;
use App\Services\Ocr\TextExtractorInterface;
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

        // OCR-Basisebene des Smart Document Upload - austauschbar, falls
        // spaeter ein anderer OCR-Dienst als Tesseract eingesetzt wird.
        $this->app->bind(TextExtractorInterface::class, TesseractTextExtractor::class);

        // KI-Anbieter der Dokumentanalyse: per Konfiguration waehlbar, damit
        // ein weiterer Anbieter spaeter ohne Umbau des restlichen Systems
        // ergaenzt werden kann (siehe DocumentAiProviderInterface).
        $this->app->bind(DocumentAiProviderInterface::class, function ($app) {
            return match (config('services.ai_document_provider', 'claude')) {
                default => $app->make(ClaudeDocumentAiProvider::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
