<?php

/*
|--------------------------------------------------------------------------
| Vertrags-Erinnerungen (Betreiber-Vorgabe 20.11.2026, Rolle TIPPGEBER)
|--------------------------------------------------------------------------
|
| Dienstly24 haelt KEINE Erlaubnis nach Paragraph 34d GewO. Erinnerungen
| duerfen daran erinnern, dass ein Wechsel moeglich ist, und den Kontakt zu
| einem zugelassenen Partner anbieten - nie beraten, empfehlen oder
| Ersparnis versprechen.
|
| JOB-SCHALTER JE SPARTE: nur Sparten in `sparten_aktiv` werden versendet.
| Voreinstellung NUR kfz - Strom, Gas, Internet und GKV sind pausiert, bis
| ihre Texte freigegeben sind (Entwuerfe: docs/ERINNERUNG_TEXTENTWUERFE.md).
| Eine Sparte wird zusaetzlich NUR versendet, wenn es fuer sie eine
| freigegebene Vorlage gibt (App\Support\ErinnerungsSparte) - ein
| versehentlich eingeschalteter Schalter verschickt also nie alten Text.
*/

return [
    'sparten_aktiv' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ERINNERUNG_SPARTEN_AKTIV', 'kfz'))
    ))),

    // Links im Fuss der Mail. Leer = Rechtsseiten auf dem kanonischen
    // Website-Host (config/website.php).
    'impressum_url' => env('IMPRESSUM_URL'),
    'datenschutz_url' => env('DATENSCHUTZ_URL'),
];
