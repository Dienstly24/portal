<?php

/*
 * Oeffentliche Marketing-Website (Merge-Entscheidung 30.07.2026):
 * Die Website wird nicht mehr statisch auf Hostinger gehostet, sondern
 * direkt von dieser Laravel-Anwendung auf dem Haupt-Domain ausgeliefert.
 * www.dienstly24.de ist der kanonische Host; alle anderen Hosts
 * (ohne www, .com, http) werden per 301 dorthin umgeleitet.
 */
return [

    // Kanonischer Host der Website. Canonical-Links, hreflang und die
    // Sitemap verwenden IMMER diesen Host.
    'canonical_host' => env('WEBSITE_CANONICAL_HOST', 'www.dienstly24.de'),

    // Hosts, die per 301 auf den kanonischen Host umgeleitet werden
    // (Domain-Strategie aus dem Arbeitsauftrag: .de ohne www, .com mit/ohne www).
    'redirect_hosts' => [
        'dienstly24.de',
        'dienstly24.com',
        'www.dienstly24.com',
    ],

    // Zusaetzliche Hosts, auf denen '/' die Marketing-Startseite zeigt
    // (z. B. eine Staging-Domain). Kommagetrennt in der .env pflegbar.
    'extra_hosts' => array_filter(array_map('trim', explode(',', (string) env('WEBSITE_EXTRA_HOSTS', '')))),

    // Kontaktdaten der Website (eine Quelle fuer Header, Footer, Schema.org).
    'phone_display' => '+49 179 9673909',
    'phone_e164' => '+491799673909',
    'email' => 'info@dienstly24.de',
    'address' => ['street' => 'Furtweg 51a', 'zip' => '22523', 'city' => 'Hamburg'],
    'facebook' => 'https://www.facebook.com/Dienstly24',

    // WhatsApp-Nummer fuer den Float-Button (wa.me, ohne '+').
    'whatsapp' => env('WEBSITE_WHATSAPP', '491799673909'),

    /*
     * Bild-Slots der Website: Jeder Platz hat einen festen Namen; die
     * Redaktion laedt unter /admin/medien ein Bild hoch und waehlt den
     * Slot aus einer Liste - kein Dateiname, kein FTP, kein Code.
     * Logo-/Favicon-Dateien laufen bewusst NICHT ueber Slots, sondern
     * ueber die bestehende Logo-Pipeline (public/images, aus logo.png
     * generiert) - siehe CLAUDE.md "Logo-Assets".
     */
    'slots' => [
        'hero-startseite' => [
            'label' => 'Startseite: Hero-Bild (Schild)',
            'hint' => 'PNG mit transparentem Hintergrund, ca. 1024x1024. Fehlt das Bild, zeigt die Seite die eingebaute Schild-Grafik.',
        ],
        'service-kfz-versicherung' => [
            'label' => 'Leistungskarte: Kfz-Versicherung',
            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund. Keine Marken-Logos (z. B. BMW) verwenden.',
        ],
        'service-krankenversicherung' => [
            'label' => 'Leistungskarte: Krankenversicherung',
            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund.',
        ],
        'service-zahnzusatzversicherung' => [
            'label' => 'Leistungskarte: Zahnzusatzversicherung',
            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund.',
        ],
        'service-kfz-zulassung' => [
            'label' => 'Leistungskarte: Kfz-Zulassungsservice',
            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund.',
        ],
        'service-kennzeichen-per-post' => [
            'label' => 'Leistungskarte: Kennzeichen per Post',
            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund.',
        ],
        'service-strom-gas' => [
            'label' => 'Leistungskarte: Strom & Gas',
            'hint' => 'Querformat ca. 800x600, weisser/transparenter Hintergrund.',
        ],
        'ueber-uns-hamburg' => [
            'label' => 'Warum Dienstly24: Hamburg-Foto',
            'hint' => 'JPG Querformat ca. 1200x900. Fehlt das Bild, zeigt die Karte das Logo-Panel.',
        ],
        'og-image-social' => [
            'label' => 'Social-Media-Vorschaubild (og:image)',
            'hint' => 'Exakt 1200x630, JPG/PNG. Wird beim Teilen (WhatsApp/Facebook) angezeigt.',
        ],
    ],

    // Upload-Regeln der Medienverwaltung.
    'media' => [
        'max_upload_kb' => 10240,           // 10 MB je Datei
        'variant_widths' => [480, 960, 1600],
        'variant_max_bytes' => 200 * 1024,  // jede ausgelieferte Variante < 200 KB
        'trash_days' => 30,                 // Papierkorb-Aufbewahrung
    ],
];
