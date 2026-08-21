<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Anzeige-Zeitzone
    |--------------------------------------------------------------------------
    |
    | GESPEICHERT wird weiterhin in UTC (siehe 'timezone' oben) - daran darf
    | sich nichts aendern, sonst laege der Altbestand in UTC und alles Neue in
    | Ortszeit, ohne dass man den Zeilen ansieht, welche welche ist. Diese
    | Einstellung gilt AUSSCHLIESSLICH fuer die ANZEIGE: Carbon::lokal()
    | rechnet einen gespeicherten Zeitpunkt hierher um.
    |
    | Warum das noetig ist: der Betrieb sitzt in Deutschland. Ein Zeitstempel,
    | der 14:30 zeigt, obwohl der Vorgang um 16:30 stattfand, ist schlicht
    | falsch - bei der DSGVO-Einwilligung sogar rechtlich heikel.
    |
    */

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Europe/Berlin'),

    /*
    |--------------------------------------------------------------------------
    | Zeitzone des Aufgabenplaners
    |--------------------------------------------------------------------------
    |
    | Die ANWENDUNG bleibt bewusst auf UTC: alle bereits gespeicherten
    | Zeitstempel sind UTC, und ein Wechsel wuerde neue Werte in Ortszeit
    | daneben schreiben - ein dauerhaft gemischter Datenbestand, der sich
    | nachtraeglich kaum noch sauber trennen laesst.
    |
    | Der PLANER dagegen muss in deutscher Ortszeit denken. Alle Zeiten in
    | routes/console.php sind als solche gemeint und kommentiert ("taeglich
    | 05:15", "Einladungen 8-19 Uhr"), feuerten unter UTC im Sommer aber
    | zwei Stunden spaeter - Kunden-Einladungen und Geburtstagsmails gingen
    | entsprechend verschoben raus. Laravel liest diesen Wert beim Bau des
    | Schedule; damit stimmen alle Zeitangaben wieder mit dem ueberein, was
    | danebensteht - inklusive automatischer Sommerzeit-Umstellung.
    |
    */

    'schedule_timezone' => env('APP_SCHEDULE_TIMEZONE', 'Europe/Berlin'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
