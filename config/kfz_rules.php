<?php

/*
|--------------------------------------------------------------------------
| KFZ-Fachregeln (Betreiber-Auftrag 01.10.2026)
|--------------------------------------------------------------------------
|
| EINE Stelle fuer jede fachliche KFZ-Regel. Jede Regel traegt ihre Quelle
| und ihren Pruefstatus - "zu_pruefen" heisst: Standardwert, der gegen die
| GDV-Musterbedingungen (AKB) bzw. die AKB des jeweiligen Versicherers
| noch abgeglichen werden muss. Eine Regel wird NIE geraten; die AKB des
| Versicherers gehen jedem Standardwert hier vor.
|
| Die Liste fuer Menschen steht in docs/project-knowledge/KFZ_RULES.md -
| ein Test haelt beide deckungsgleich (jede Regel-ID hier steht dort).
|
*/

return [

    'rules' => [

        // Vorschlag fuer die "tatsaechliche" (uebertragbare) SF-Klasse bei
        // einer Sondereinstufung. NUR ein Vorschlag im Formular - die Klasse
        // bleibt frei waehlbar und wird nie automatisch gespeichert.
        'SF-VORSCHLAG-FUEHRERSCHEIN' => [
            'titel' => 'Vorschlag tatsächliche SF-Klasse nach Führerscheindauer',
            'werte' => [
                'mindestjahre' => 3,
                'klasse_ab_mindestjahre' => '1/2',
                'klasse_darunter' => '0',
            ],
            'quelle' => 'GDV-Musterbedingungen AKB, Anhang "Einstufung in Schadenfreiheitsklassen" (Ersteinstufung SF 1/2 bzw. SF 0) – genaue Ziffer zu prüfen; AKB des Versicherers vorrangig',
            'status' => 'zu_pruefen',
            'konfiguration' => 'config/kfz_rules.php (rules.SF-VORSCHLAG-FUEHRERSCHEIN.werte)',
        ],

        // Welche Gruende einer Sondereinstufung beruhen auf einem ANDEREN
        // Vertrag (Bezugsfahrzeug)? Interne Fachlogik, keine Rechtsregel.
        'SF-BEZUG-GRUENDE' => [
            'titel' => 'Sondereinstufungen mit Bezugsfahrzeug',
            'werte' => ['gruende' => ['zweitwagen', 'drittwagen', 'familie']],
            'quelle' => 'Interne Fachlogik (Betreiber-Auftrag 01.10.2026) – Zweit-/Drittwagenregelung und Übernahme innerhalb der Familie setzen einen Bezugsvertrag voraus',
            'status' => 'intern',
            'konfiguration' => 'config/kfz_rules.php (rules.SF-BEZUG-GRUENDE.werte.gruende)',
        ],

        // Fehlender Bezug: Warnung, blockierend nur auf Wunsch des Betreibers.
        'SF-BEZUG-PFLICHT' => [
            'titel' => 'Bezugsfahrzeug bei Zweit-/Drittwagen verpflichtend',
            'werte' => ['setting' => 'sf_reference_required_on_submit', 'standard' => false, 'stufen' => ['antrag', 'vertrag']],
            'quelle' => 'Interne Fachlogik (Betreiber-Auftrag 01.10.2026)',
            'status' => 'intern',
            'konfiguration' => 'Einstellungen → KFZ ("Bezugsfahrzeug verpflichtend"), SystemSetting sf_reference_required_on_submit',
        ],

        // Wann der Betreuer des Zweitwagens benachrichtigt wird.
        'SF-BEZUG-BENACHRICHTIGUNG' => [
            'titel' => 'Benachrichtigung bei Änderung am Erstwagen',
            'werte' => ['ereignisse' => ['rueckstufung', 'nicht_mehr_aktiv', 'geloescht']],
            'quelle' => 'Interne Fachlogik (Betreiber-Auftrag 01.10.2026) – die jährliche Höherstufung löst bewusst nichts aus',
            'status' => 'intern',
            'konfiguration' => 'config/kfz_rules.php (rules.SF-BEZUG-BENACHRICHTIGUNG)',
        ],
    ],

];
