<?php

/*
|--------------------------------------------------------------------------
| Kundenportal - globaler Schalter (Betreiber-Vorgabe 20.11.2026)
|--------------------------------------------------------------------------
|
| Solange Dienstly24 KEINE Erlaubnis nach Paragraph 34d GewO haelt, duerfen
| Kunden keinen Portalzugang haben. Der Schalter sperrt SERVERSEITIG alle
| Kunden-Seiten, Kunden-Anmeldung, Registrierung, Passwort-Wege fuer
| Kunden, Magic-Links und bestehende Kundensitzungen
| (App\Http\Middleware\KundenportalSperre). Beraterwelt, Partnerportal,
| Erinnerungslaeufe und gespeicherte Daten bleiben unberuehrt.
|
| CUSTOMER_PORTAL_ENABLED=true stellt das bisherige Verhalten wieder her.
| Voreinstellung ist AUS: ein vergessener Eintrag in der .env darf das
| Portal nicht oeffnen.
*/

return [
    'enabled' => (bool) env('CUSTOMER_PORTAL_ENABLED', false),

    // WhatsApp-Nummer fuer die Kontaktwege: NUR Ziffern mit Laendervorwahl
    // (wa.me-Format, ohne '+'). Ohne eigenen Eintrag gilt die Nummer der
    // Website (config/website.php).
    'whatsapp' => env('CUSTOMER_PORTAL_WHATSAPP'),

    // Basisadresse fuer Chat-Links in E-Mails. Ohne Eintrag gilt die
    // Portal-Adresse aus den Einstellungen: dieser Host wird sicher von
    // dieser Anwendung ausgeliefert, /chat ist dort KEIN Portalbereich.
    'chat_base_url' => env('CUSTOMER_CHAT_BASE_URL'),

    // Gueltigkeit des signierten Chat-Bezugs in Erinnerungs-Links (Tage).
    'chat_ref_days' => (int) env('CUSTOMER_CHAT_REF_DAYS', 30),
];
