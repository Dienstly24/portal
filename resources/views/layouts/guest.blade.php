<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        {{--
            KEINE externen Schriften (DSGVO, Audit 18.08.2026).
            Hier lag die EINZIGE externe Ressource im ganzen System: zwei
            Links auf fonts.bunny.net. Jeder Aufruf dieser Seite haette die
            IP-Adresse des Besuchers an einen Dritten uebertragen - genau
            der Tatbestand, fuer den es in Deutschland bereits Abmahnungen
            wegen Google Fonts gab. Die Seite nutzt jetzt die
            System-Schriftfamilie (font-sans, siehe body).
            Diese Vorlage traegt confirm-password und verify-email und wird
            deshalb NICHT geloescht, sondern bereinigt.
        --}}

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.favicon')
</head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100">
            <div>
                <a href="/">
                    <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
                </a>
            </div>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
        @include('partials.cookie_consent')
    
{{-- Ereignis-Verdrahtung der Seite (Audit SEC-4). Die Bloecke landen
     hier am Ende des Body, damit sie auch aus Partials heraus (etwa
     einer Tabellenzeile) gueltiges HTML ergeben - ein <script @cspNonce> mitten
     in einer <table> wuerde der Browser herausloesen. --}}
@stack('cspScripts')
</body>
</html>
