<?php

/*
|--------------------------------------------------------------------------
| Sicherheitsschalter (Audit SEC-4)
|--------------------------------------------------------------------------
|
| Die Content-Security-Policy selbst steht in
| App\Http\Middleware\SecurityHeaders::policy() - hier stehen nur die
| beiden Schalter, die man im Betrieb braucht.
|
*/

return [

    /*
    | Nur MELDEN statt blockieren.
    |
    | Gedacht fuer den Umstieg: laeuft die Anwendung nach einer groesseren
    | Aenderung an der Oberflaeche noch sauber unter der Richtlinie? Im
    | Report-Only-Modus meldet der Browser Verstoesse, blockiert aber
    | nichts - man sieht also, was kaputt WAERE, ohne dass es kaputt IST.
    |
    | Standard AUS: eine Richtlinie, die nur meldet, schuetzt nicht. Der
    | Schalter ist die Ausnahme fuer eine Umstellung, nicht der
    | Normalzustand.
    */
    'csp_report_only' => (bool) env('CSP_REPORT_ONLY', false),

    /*
    | Adresse, an die der Browser Verstoesse meldet. Leer = keine
    | Meldungen (Standard - eine Meldeadresse, die niemand ausliest, ist
    | nur ein weiterer Endpunkt).
    */
    'csp_report_uri' => env('CSP_REPORT_URI'),

    /*
    | Geheimnis fuer die externe Ueberwachung (Audit 15.09.2026).
    |
    | Ist es gesetzt, beantwortet `/gesundheit` (ohne Anmeldung, aber nur
    | mit diesem Token) eine knappe Ampel: 200 wenn alles laeuft, 503
    | wenn etwas handlungsbeduerftig ist. Genau das braucht ein
    | Ueberwachungsdienst - und genau das konnte
    | /admin/systemzustand.json nicht liefern, weil es hinter Anmeldung,
    | Rolle und Zweitem Faktor lag.
    |
    | LEER = ENDPUNKT AUS (404). Eine Installation ohne gesetztes Token
    | oeffnet nichts.
    |
    | Ein langer Zufallswert: `php -r "echo bin2hex(random_bytes(32));"`
    */
    'health_token' => env('HEALTH_TOKEN'),

];
