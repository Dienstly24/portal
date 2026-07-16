<?php

namespace App\Providers;

use App\Services\Activity\ActivityCatalog;
use App\Services\Activity\ActivityTracker;
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
