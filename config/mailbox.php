<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kundenseitige E-Mail-Verbindung (Weiterleitung, Variante A)
    |--------------------------------------------------------------------------
    |
    | Kunden leiten VERTRAGSBEZOGENE Post per Auto-Weiterleitung an ihre
    | persoenliche Import-Adresse import+<token>@<domain>. Das Sammelpostfach
    | wird unter Admin -> E-Mail-Postfaecher als "is_customer_import" markiert.
    */

    'import_domain' => env('MAILBOX_IMPORT_DOMAIN', 'dienstly24.de'),
    'import_local_part' => env('MAILBOX_IMPORT_LOCALPART', 'import'),

    /*
    | Data Minimization / Zweckbindung: Aus dem Import-Postfach werden NUR
    | Mails verarbeitet, deren Absenderdomain hier (oder in der ueber die
    | Admin-Einstellung 'email_import_allowed_domains' gepflegten Liste)
    | steht. Alles andere (private/fremde Weiterleitungen) wird verworfen und
    | nie gespeichert. Die Liste ist bewusst erweiterbar - Startwerte decken
    | Fonds Finanz sowie gaengige Versicherer/Energieversorger ab.
    */
    'import_allowed_domains' => [
        'fondsfinanz.de',
        'allianz.de',
        'huk.de',
        'huk-coburg.de',
        'ergo.de',
        'axa.de',
        'generali.de',
        'gothaer.de',
        'dvag.de',
        'eon.de',
        'enbw.com',
        'vattenfall.de',
        'lichtblick.de',
    ],
];
