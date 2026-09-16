<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        /*
        | Die PRIVATE Platte: Kundendokumente, Nachweise zu Aenderungen
        | (Kontoauszug, Ausweis, Meldebescheinigung), unterschriebene
        | PDFs, Chat-Anhaenge, Medien-Originale.
        |
        | 'serve' => false (Audit 15.09.2026). Mit `true` registriert
        | Laravel von sich aus GET und PUT auf /storage/{path} - ganz
        | ohne Middleware. Diese Routen verlangen zwar eine gueltige
        | Signatur, aber die Anwendung BRAUCHT sie gar nicht: jeder
        | Download laeuft ueber einen Controller, der das Portfolio
        | prueft und den Zugriff protokolliert (nachgemessen: kein
        | einziger Aufruf von disk('local')->url()/temporaryUrl()).
        |
        | Eine Tuer, die niemand benutzt, aber jeder Schluessel oeffnet,
        | gehoert zugemauert: waere der APP_KEY je aus einer Sicherung
        | oder einem Log zu holen, liessen sich damit signierte Links
        | auf JEDE private Datei bauen - an Portfolio-Pruefung UND
        | Protokoll vorbei.
        */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
