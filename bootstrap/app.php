<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['role' => \App\Http\Middleware\EnsureUserRole::class]);
        $middleware->validateCsrfTokens(except: ['api/website-inquiry']);
        // Defensive Sicherheitsheader auf jede Antwort.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Sprache (de/ar) je Kunde bzw. Session – nach StartSession.
        $middleware->appendToGroup('web', \App\Http\Middleware\SetLocale::class);
        // Aktivitaetserfassung fuer Mitarbeiter: global in der Web-Gruppe,
        // damit sie serverseitig laeuft und nicht umgangen werden kann.
        $middleware->appendToGroup('web', \App\Http\Middleware\TrackStaffActivity::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
