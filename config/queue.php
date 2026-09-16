<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            // WICHTIG: retry_after MUSS groesser sein als das laengste Job-
            // Timeout, sonst nimmt ein zweiter Worker den Job an, waehrend der
            // erste noch laeuft. AnalyzeDocumentJob und SendCampaignJob haben
            // $timeout=300 (Claude-Vision-HTTP bis 180s; Kampagne sendet in
            // Stapeln und setzt sich selbst fort) -> Default 360s haelt
            // sicheren Abstand. Wer diesen Wert SENKT, muss die Job-Timeouts
            // mitsenken - sonst versendet eine Kampagne doppelt.
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 360),
            'after_commit' => false,
        ],

        /*
        | LANGE LAEUFE (Audit 15.09.2026).
        |
        | Der Kommentar oben nennt die Regel - `ImportCustomersJob` hielt
        | sie trotzdem nicht ein: $timeout = 1800 gegen retry_after = 360.
        | Ein Import, der laenger als sechs Minuten dauert, wurde damit
        | ein zweites Mal aus der Warteschlange geholt und (dank
        | tries = 1) als FEHLGESCHLAGEN eingetragen, obwohl der erste
        | Lauf gerade sauber weiterarbeitete. Der Betreiber sah einen
        | roten Eintrag in `failed_jobs` fuer einen Import, der in
        | Wahrheit gelungen ist - und bekam keine Erfolgsmeldung.
        |
        | Statt das Zeitlimit des Imports zu kuerzen (ein grosser Import
        | BRAUCHT die Zeit) bekommt er eine eigene Verbindung mit
        | passendem retry_after. Dieselbe Datenbank, dieselbe Tabelle,
        | nur eine andere Warteschlange - es aendert sich nichts am
        | Ablauf des Imports.
        |
        | AUF DEM SERVER: der bestehende Worker bedient `default`. Fuer
        | diese Schlange braucht es einen ZWEITEN Worker, siehe
        | docs/DEPLOYMENT.md. Laeuft er nicht, bleibt ein Import liegen -
        | er geht NICHT verloren und wird auch nicht doppelt ausgefuehrt.
        |
        | Ein Waechter-Test (QueueTimeoutTest) prueft fuer JEDEN Job,
        | dass sein Zeitlimit unter dem retry_after seiner Verbindung
        | liegt. Die Regel steht damit nicht mehr nur im Kommentar.
        */
        'database-lang' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE_LANG', 'lang'),
            'retry_after' => (int) env('DB_QUEUE_LANG_RETRY_AFTER', 2100),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        /*
        | retry_after: 360 statt der Laravel-Vorgabe 90 (Audit 15.09.2026).
        |
        | GEFUNDEN DURCH DEN WAECHTER-TEST, nicht im Betrieb - und das ist
        | ein Glueck: der in docs/ANLEITUNG_REDIS_AR.md beschriebene
        | Umzug ("nur installieren und drei Zeilen in die .env") haette
        | SIEBEN Jobs auf eine Verbindung gelegt, deren retry_after unter
        | ihrem Zeitlimit liegt:
        |
        |   AnalyzeDocumentJob        300 s   AnswerCustomerMessageJob  120 s
        |   SendCampaignJob           300 s   FetchInboundMediaJob      120 s
        |   PublishSocialChannelJob   300 s   ProcessWhatsAppWebhookJob 120 s
        |   ProcessMediaAssetJob      120 s
        |
        | Jeder davon waere nach 90 Sekunden ein zweites Mal aus der
        | Schlange geholt worden, waehrend der erste Lauf noch arbeitet.
        | Bei PublishSocialChannelJob heisst das woertlich: derselbe
        | Beitrag zweimal auf Instagram - der Job traegt `tries = 1`
        | ausdruecklich, um genau das zu verhindern, und die Schlange
        | haette es trotzdem getan. Bei SendCampaignJob: eine Kampagne
        | doppelt versendet.
        |
        | 360 s ist derselbe Abstand wie bei der Datenbank-Verbindung.
        | Wer ihn SENKT, muss die Job-Timeouts mitsenken - QueueTimeoutTest
        | prueft das fuer beide Treiber.
        */
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 360),
            'block_for' => null,
            'after_commit' => false,
        ],

        /*
        | LANGE LAEUFE auf Redis (Audit 15.09.2026).
        |
        | Das Gegenstueck zu `database-lang`. Es gibt sie, damit der in
        | docs/ANLEITUNG_REDIS_AR.md beschriebene Umzug NICHT am
        | Kundenimport scheitert: `redis` hat retry_after = 90 Sekunden,
        | der Import darf 1800 laufen. Ohne diese Verbindung waere der
        | Umzug auf Redis genau der Fehler, den die Trennung auf der
        | Datenbank gerade behoben hat - nur 20-mal schaerfer.
        |
        | ImportCustomersJob waehlt selbst die passende Verbindung zum
        | eingestellten Treiber; es ist nichts umzustellen.
        */
        'redis-lang' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE_LANG', 'lang'),
            'retry_after' => (int) env('REDIS_QUEUE_LANG_RETRY_AFTER', 2100),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
