<?php

/*
 * Eingebettete Partner-Vergleichsrechner auf den Leistungsseiten
 * (/leistungen/{slug}). Aus DSGVO-Gruenden wird der Rechner NIE direkt
 * geladen: Die Seite zeigt zuerst eine Zwei-Klick-Einwilligung; erst nach
 * Klick injiziert JS das Widget-Script des Anbieters (Zwei-Klick-Loesung).
 * Der Direktlink funktioniert immer, auch ohne Einwilligung (normaler Link,
 * keine Drittanbieter-Ressourcen auf unserer Seite).
 *
 * Neue Rechner: Eintrag unter 'slugs' ergaenzen und die Hosts in der CSP
 * (App\Http\Middleware\SecurityHeaders) freigeben.
 */
return [
    'slugs' => [
        'kfz-versicherung' => [
            'anbieter' => 'Tarifcheck',
            'container' => 'tcpp-iframe-kfz',
            'script' => 'https://form.partner-versicherung.de/widgets/143360/tcpp-iframe-kfz/kfz-iframe.js',
            'direktlink' => 'https://a.partner-versicherung.de/click.php?partner_id=143360&ad_id=15&deep=kfz-versicherung',
            'datenschutz' => 'https://www.tarifcheck.de/datenschutz/',
        ],
    ],
];
