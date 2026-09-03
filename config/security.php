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

];
