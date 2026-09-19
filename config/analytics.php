<?php

/*
|--------------------------------------------------------------------------
| Reichweitenmessung (Betreiber-Entscheidung 18.09.2026)
|--------------------------------------------------------------------------
|
| Gewaehlt wurde MATOMO AUF DEM EIGENEN SERVER, nicht Google Analytics 4.
| Die drei Gruende, in der Reihenfolge ihres Gewichts:
|
| 1. GA4 braucht ein Skript von googletagmanager.com. Dieser Host muesste
|    in die Inhaltsrichtlinie - also genau die Freigabe, die Audit SEC-4
|    am 03.09.2026 unter erheblichem Aufwand entfernt hat (rund 310
|    Inline-Handler umgebaut, Alpine.js ersetzt). Matomo laeuft auf einem
|    eigenen Host des Betriebs; die Freigabe bleibt in eigener Hand.
| 2. GA4 setzt Kennungen und braucht damit eine echte Einwilligung. Wer
|    ablehnt, wird nicht gemessen - in der Praxis ein grosser Teil der
|    Besucher. Matomo laeuft hier OHNE Kennungen ('disableCookies'), misst
|    also alle Besucher gleich.
| 3. Die Daten bleiben auf dem eigenen Server. Das ist dieselbe Haltung
|    wie beim Rest dieser Anwendung (eigener QR-Code, eigener PDF-Stempel,
|    eigener XLSX-Leser): bei einem Betrieb, der fremde personenbezogene
|    Daten verwaltet, ist ein zusaetzlicher Empfaenger eine Entscheidung,
|    keine Kleinigkeit.
|
| Der Preis dafuer steht in der Anleitung und wird nicht verschwiegen:
| Matomo ist eine weitere PHP-Anwendung mit eigener Datenbank auf dem VPS,
| die gepflegt und aktualisiert werden muss.
|
| GEMESSEN WIRD NUR DIE OEFFENTLICHE WEBSITE - nie die Beraterwelt, nie
| das Kundenportal, nie das Partnerportal. Dort stehen Kundendaten in
| Seitentiteln und Adressen ("/admin/customers/4711"), und ein
| Messwerkzeug wuerde sie mitschreiben. Das ist keine Einstellung, die
| jemand vergessen kann: die Einbindung steht ausschliesslich in den
| Vorlagen der Website.
|
*/

return [

    'matomo' => [

        /*
         * Adresse der eigenen Matomo-Installation, z. B.
         * "https://statistik.<eigene-domain>". LEER = Messung ist AUS und
         * es wird kein einziges Byte ausgeliefert; das ist der Standard.
         *
         * Nur in die Server-.env, nie ins Repository.
         */
        'url' => env('MATOMO_URL'),

        // Nummer der Website in Matomo (bei der ersten Einrichtung die 1).
        'site_id' => env('MATOMO_SITE_ID'),
    ],

];
