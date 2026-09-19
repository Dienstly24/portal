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

    /*
     * Alt-URLs des Portal-Hosts auf den Website-Host umleiten (Auftrag
     * P1-4: "301 von den alten portal.-Links auf die neuen").
     *
     * Bewusst ein SCHALTER, kein Automatismus: Vor dem DNS-Umzug liegt
     * auf www.dienstly24.de noch die statische Uebergangs-Site OHNE
     * /leistungen - eine Umleitung dorthin liefe ins Leere. Erst am
     * Umzugstag, wenn www von dieser App bedient wird, wird
     * WEBSITE_MARKETING_REDIRECT=true gesetzt (Schritt in der
     * Cutover-Checkliste).
     */
    'marketing_redirect' => (bool) env('WEBSITE_MARKETING_REDIRECT', false),

    // Pfade, die dann vom Portal-Host auf den kanonischen Host wandern.
    'marketing_paths' => ['leistungen', 'leistungen/*', 'ar', 'ar/*'],

    // Kontaktdaten der Website (eine Quelle fuer Header, Footer, Schema.org).
    'phone_display' => '+49 179 9673909',
    'phone_e164' => '+491799673909',
    'email' => 'info@dienstly24.de',
    'address' => ['street' => 'Furtweg 51a', 'zip' => '22523', 'city' => 'Hamburg'],

    /*
     * ERREICHBARKEITS-ZEITEN - EINE Quelle fuer Seite UND strukturierte
     * Daten.
     *
     * KEIN BESUCHSBETRIEB (Betreiber-Klarstellung 19.09.2026): der
     * Betrieb arbeitet ausschliesslich online, niemand kommt ins Buero.
     * Das hier sind die Zeiten, in denen jemand ans Telefon geht - nicht
     * die Zeiten, zu denen man vorbeikommen kann. Die Hamburg-Seite sagt
     * das ausdruecklich dazu; im Schema heisst das Feld trotzdem
     * `openingHoursSpecification`, weil schema.org kein anderes kennt.
     *
     * Sie standen bis 19.09.2026 doppelt: als Text auf der Hamburg-Seite
     * und noch einmal fest verdrahtet im `InsuranceAgency`-Schema. Wer
     * die eine aendert und die andere vergisst, erzeugt genau den
     * Widerspruch, den Google beim Abgleich mit dem
     * Unternehmensprofil bemaengelt - und niemand sieht ihn, weil beide
     * Stellen fuer sich richtig aussehen.
     *
     * `von`/`bis` in 24-Stunden-Schreibweise (schema.org verlangt das),
     * `tage` in der Benennung von schema.org. Die ANZEIGE wird daraus
     * gebildet, nicht umgekehrt.
     */
    'opening_hours' => [
        'tage' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'von' => '09:00',
        'bis' => '18:00',
    ],
    'facebook' => 'https://www.facebook.com/Dienstly24',

    /*
     * Weitere oeffentliche Profile des Betriebs (Entity-Signale, SEO-Auftrag
     * Abschnitt 20/33): sie gehen als `sameAs` in die strukturierten Daten.
     *
     * BEWUSST AUS DER .env, ohne Standardwert: ein erfundenes Profil waere
     * eine falsche Unternehmensangabe - und Google prueft `sameAs`. Erst
     * wenn ein Profil WIRKLICH existiert, wird es hier eingetragen. Leere
     * Werte fallen weg, ein Betrieb ohne Instagram hat dann eben nur
     * Facebook - das ist ehrlich und richtig.
     */
    'social' => array_values(array_filter([
        'https://www.facebook.com/Dienstly24',
        env('WEBSITE_INSTAGRAM'),
        env('WEBSITE_LINKEDIN'),
        env('WEBSITE_YOUTUBE'),
        env('WEBSITE_GOOGLE_BUSINESS'),
    ])),

    /*
     * Dasselbe Profil noch einmal einzeln - die Hamburg-Seite verlinkt
     * es SICHTBAR (Zwei-Wege-Link: Profil -> Website, Website -> Profil).
     * Ohne Eintrag erscheint dort kein Knopf; ein Link auf ein Profil,
     * das es nicht gibt, waere eine falsche Angabe ueber das eigene
     * Unternehmen.
     */
    'google_business' => env('WEBSITE_GOOGLE_BUSINESS'),

    // WhatsApp-Nummer fuer den Float-Button (wa.me, ohne '+').
    'whatsapp' => env('WEBSITE_WHATSAPP', '491799673909'),

    /*
     * Zusaetzliche Schutzschichten (ExtraBasicAuth-Middleware).
     * WICHTIG: Werte hier ueber config() lesen, nie env() in Middleware -
     * in Produktion laeuft config:cache und env() waere leer.
     *
     * ADMIN_BASIC_AUTH="benutzer:passwort" legt eine zweite
     * Authentifizierungs-Schicht VOR den kompletten /admin-Bereich
     * (Bedingung des Betreibers: Upload-Panel nie nur mit einem einzigen
     * Passwort oeffentlich, solange kein 2FA existiert).
     *
     * STAGING_HOSTS="neu.dienstly24.de" + STAGING_BASIC_AUTH="user:pass"
     * schuetzen eine Staging-/Vorschau-Domain komplett per Basic-Auth
     * und setzen noindex (zusammen mit WEBSITE_EXTRA_HOSTS zeigt so eine
     * Domain die Website zum Durchklicken VOR dem DNS-Umzug).
     */
    'admin_basic_auth' => env('ADMIN_BASIC_AUTH'),
    'staging_basic_auth' => env('STAGING_BASIC_AUTH'),
    'staging_hosts' => array_filter(array_map('trim', explode(',', (string) env('STAGING_HOSTS', '')))),

    /*
     * Bild-Slots der Website: Jeder Platz hat einen festen Namen; die
     * Redaktion laedt unter /admin/medien ein Bild hoch und waehlt den
     * Slot aus einer Liste - kein Dateiname, kein FTP, kein Code.
     *
     * Optionale Zusatzangaben je Slot (sonst gelten die Standardwerte
     * aus 'media' weiter unten):
     *   'widths'  => Zielbreiten der erzeugten Varianten
     *   'formats' => erzeugte Formate (Standard: avif+webp+jpg, bzw.
     *                avif+webp+png sobald das Original Transparenz hat)
     *
     * Marken-Slots (logo-*, favicon) UEBERSCHREIBEN die mitgelieferten
     * Dateien unter public/images - ist kein Bild zugewiesen, bleibt
     * alles beim generierten Bestand (siehe CLAUDE.md "Logo-Assets").
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

        /*
         * Marken-Slots (Auftrag P1-1e). Ueberschreiben die generierten
         * Dateien aus public/images auf der GESAMTEN Anwendung - Website,
         * Kundenportal, Beraterwelt und Login. Bewusst nur PNG/WebP:
         * Wortmarken brauchen Transparenz und scharfe Kanten, JPG wuerde
         * einen weissen Kasten hinter das Logo legen.
         */
        'logo-hell' => [
            'label' => 'Logo hell (weisse Wortmarke fuer dunkle Flaechen)',
            'hint' => 'PNG/WebP mit TRANSPARENTEM Hintergrund, mind. 320px hoch. Ersetzt logo-white.png in Website-Kopfzeile, Fusszeile, Login und Portal.',
            'widths' => [240, 480, 720],
            'formats' => ['webp', 'png'],
            'admin_only' => true,
        ],
        'logo-dunkel' => [
            'label' => 'Logo dunkel (farbige Wortmarke fuer helle Flaechen)',
            'hint' => 'PNG/WebP mit TRANSPARENTEM Hintergrund, mind. 320px hoch. Ersetzt logo-transparent.png in der Beraterwelt-Kopfzeile.',
            'widths' => [240, 480, 720],
            'formats' => ['webp', 'png'],
            'admin_only' => true,
        ],
        'logo-symbol-hell' => [
            'label' => 'Logo-Symbol hell (nur das D-Zeichen, weiss)',
            'hint' => 'Quadratisches PNG/WebP mit transparentem Hintergrund. Ersetzt logo-icon-white.png in den dunklen Seitenleisten.',
            'widths' => [96, 192, 512],
            'formats' => ['webp', 'png'],
            'admin_only' => true,
        ],
        'favicon' => [
            'label' => 'Favicon / App-Symbol (Browser-Tab und Handy-Startbildschirm)',
            'hint' => 'QUADRATISCHES PNG, mind. 512x512, transparenter Hintergrund. Erzeugt automatisch 32px, 180px (Apple) und 512px.',
            'widths' => [32, 180, 512],
            'formats' => ['png'],
            'admin_only' => true,
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
