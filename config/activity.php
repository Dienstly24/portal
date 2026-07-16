<?php

/*
|--------------------------------------------------------------------------
| Aktivitaetserfassung & Produktivitaet (Mitarbeiter)
|--------------------------------------------------------------------------
| Zentrale Definition aller erfassten Aktionen. Punkte-Gewichte sind
| DEFAULTS - sie koennen ohne Code-Aenderung in den Einstellungen
| (system_settings: activity_points) ueberschrieben werden.
|
| Kategorien: create, update, delete, upload, download, approve,
| communication, navigation, session, other.
|
| Erweiterbarkeit: neue Routen ohne Mapping werden als
| 'aktion_ausgefuehrt' (Fallback) gezaehlt; ein praezises Mapping kann
| jederzeit hier ergaenzt werden.
*/
return [

    // Rollen, deren Aktivitaet erfasst wird (Kunden/Partner NIE).
    'staff_roles' => ['admin', 'manager', 'support', 'employee'],

    // Max. Luecke (Minuten) zwischen zwei produktiven Aktionen, die noch
    // als durchgehende Arbeit zaehlt. Override: activity_idle_threshold_minutes
    'idle_threshold_minutes' => 5,

    // Ohne jeglichen Request gilt die Sitzung nach X Minuten als beendet.
    // Override: activity_session_timeout_minutes
    'session_timeout_minutes' => 30,

    // Vollstaendig ignoriert (kein Log, KEIN Praesenz-Update): automatische
    // Hintergrund-Requests des Browsers. Wichtig: das Notification-Polling
    // (alle 60s) darf die Sitzung nicht kuenstlich offen halten.
    'ignored_routes' => [
        'admin.notifications',
    ],

    // Nicht protokolliert (Rauschen), zaehlt aber als Praesenz-Signal.
    'unlogged_routes' => [
        'admin.search',
        'admin.employees.customer-search',
        'admin.notifications.read',
        'admin.notifications.read_all',
        'locale.switch',
        'login',
        'logout',
        'register',
        'password.*',
        'verification.*',
    ],

    // Auth-POSTs OHNE Routen-Namen (routes/auth.php): per Pfad ausnehmen,
    // sonst wuerde z. B. der Login-POST als produktive "aktion_ausgefuehrt"
    // mit Punkten gewertet. Login/Logout werden von den Listenern erfasst.
    'unlogged_paths' => [
        'login',
        'register',
        'confirm-password',
    ],

    // Aktionskatalog: key => label (DE), category, points (Default),
    // productive (zaehlt fuer Aktivzeit).
    'actions' => [
        // Sitzung
        'login'  => ['label' => 'Anmeldung', 'category' => 'session', 'points' => 0, 'productive' => false],
        'logout' => ['label' => 'Abmeldung', 'category' => 'session', 'points' => 0, 'productive' => false],
        'seite_geoeffnet' => ['label' => 'Seite geoeffnet', 'category' => 'navigation', 'points' => 0, 'productive' => false],

        // Kunden
        'kunde_angelegt'            => ['label' => 'Kunde angelegt', 'category' => 'create', 'points' => 5, 'productive' => true],
        'kunde_bearbeitet'          => ['label' => 'Kunde bearbeitet', 'category' => 'update', 'points' => 2, 'productive' => true],
        'kunde_geloescht'           => ['label' => 'Kunde geloescht', 'category' => 'delete', 'points' => 0, 'productive' => true],
        'kunden_zugewiesen'         => ['label' => 'Kunden zugewiesen', 'category' => 'update', 'points' => 2, 'productive' => true],
        'kunden_zusammengefuehrt'   => ['label' => 'Kunden zusammengefuehrt', 'category' => 'update', 'points' => 5, 'productive' => true],
        'notiz_angelegt'            => ['label' => 'Notiz angelegt', 'category' => 'create', 'points' => 2, 'productive' => true],
        'notiz_erledigt'            => ['label' => 'Notiz erledigt', 'category' => 'update', 'points' => 1, 'productive' => true],
        'familienmitglied_angelegt' => ['label' => 'Familienmitglied angelegt', 'category' => 'create', 'points' => 2, 'productive' => true],
        'familienmitglied_geloescht'=> ['label' => 'Familienmitglied geloescht', 'category' => 'delete', 'points' => 0, 'productive' => true],
        'fahrzeug_angelegt'         => ['label' => 'Fahrzeug angelegt', 'category' => 'create', 'points' => 2, 'productive' => true],
        'portal_zugang_verwaltet'   => ['label' => 'Portal-Zugang verwaltet', 'category' => 'update', 'points' => 1, 'productive' => true],

        // Vertraege
        'vertrag_angelegt'   => ['label' => 'Vertrag angelegt', 'category' => 'create', 'points' => 5, 'productive' => true],
        'vertrag_bearbeitet' => ['label' => 'Vertrag bearbeitet', 'category' => 'update', 'points' => 2, 'productive' => true],
        'vertrag_geloescht'  => ['label' => 'Vertrag geloescht', 'category' => 'delete', 'points' => 0, 'productive' => true],
        'wechsel_reaktion_erfasst' => ['label' => 'Wechsel-Reaktion erfasst', 'category' => 'update', 'points' => 1, 'productive' => true],

        // Tickets & Kommunikation
        'ticket_beantwortet'      => ['label' => 'Ticket beantwortet', 'category' => 'communication', 'points' => 3, 'productive' => true],
        'ticket_status_geaendert' => ['label' => 'Ticket-Status geaendert', 'category' => 'update', 'points' => 1, 'productive' => true],
        'ticket_bearbeitet'       => ['label' => 'Ticket bearbeitet', 'category' => 'update', 'points' => 1, 'productive' => true],
        'ticket_notiz_angelegt'   => ['label' => 'Ticket-Notiz angelegt', 'category' => 'create', 'points' => 2, 'productive' => true],
        'anfrage_erfasst'         => ['label' => 'Anfrage erfasst', 'category' => 'create', 'points' => 3, 'productive' => true],
        'chat_nachricht'          => ['label' => 'Chat-Nachricht', 'category' => 'communication', 'points' => 1, 'productive' => true],
        'interne_notiz'           => ['label' => 'Interne Notiz', 'category' => 'communication', 'points' => 1, 'productive' => true],
        'interne_notiz_geloescht' => ['label' => 'Interne Notiz geloescht', 'category' => 'delete', 'points' => 0, 'productive' => true],

        // Freigaben / Entscheidungen
        'freigabe_entschieden'        => ['label' => 'Kundenaenderung entschieden', 'category' => 'approve', 'points' => 3, 'productive' => true],
        'dokumentanfrage_angelegt'    => ['label' => 'Dokumentanfrage angelegt', 'category' => 'create', 'points' => 2, 'productive' => true],
        'dokumentanfrage_entschieden' => ['label' => 'Dokumentanfrage entschieden', 'category' => 'approve', 'points' => 2, 'productive' => true],
        'email_zugeordnet'            => ['label' => 'E-Mail zugeordnet', 'category' => 'approve', 'points' => 2, 'productive' => true],
        'email_abgelehnt'             => ['label' => 'E-Mail-Zuordnung abgelehnt', 'category' => 'approve', 'points' => 1, 'productive' => true],
        'ki_vorschlag_entschieden'    => ['label' => 'KI-Vorschlag entschieden', 'category' => 'approve', 'points' => 1, 'productive' => true],

        // Dokumente / Dateien
        'datei_hochgeladen'      => ['label' => 'Datei hochgeladen', 'category' => 'upload', 'points' => 3, 'productive' => true],
        'datei_ersetzt'          => ['label' => 'Datei ersetzt', 'category' => 'upload', 'points' => 2, 'productive' => true],
        'datei_bearbeitet'       => ['label' => 'Datei bearbeitet', 'category' => 'update', 'points' => 1, 'productive' => true],
        'datei_geloescht'        => ['label' => 'Datei geloescht', 'category' => 'delete', 'points' => 0, 'productive' => true],
        'datei_heruntergeladen'  => ['label' => 'Datei heruntergeladen', 'category' => 'download', 'points' => 0, 'productive' => false],

        // Aufgaben & Termine
        'aufgabe_angelegt'   => ['label' => 'Aufgabe angelegt', 'category' => 'create', 'points' => 2, 'productive' => true],
        'aufgabe_bearbeitet' => ['label' => 'Aufgabe bearbeitet', 'category' => 'update', 'points' => 1, 'productive' => true],
        'aufgabe_geloescht'  => ['label' => 'Aufgabe geloescht', 'category' => 'delete', 'points' => 0, 'productive' => true],
        'termin_angelegt'    => ['label' => 'Termin angelegt', 'category' => 'create', 'points' => 3, 'productive' => true],
        'termin_bearbeitet'  => ['label' => 'Termin bearbeitet', 'category' => 'update', 'points' => 1, 'productive' => true],

        // Import / Export
        'daten_importiert' => ['label' => 'Daten importiert', 'category' => 'create', 'points' => 5, 'productive' => true],
        'daten_exportiert' => ['label' => 'Daten exportiert', 'category' => 'other', 'points' => 1, 'productive' => true],

        // Marketing / Inhalte
        'kampagne_versendet'  => ['label' => 'Kampagne versendet', 'category' => 'communication', 'points' => 3, 'productive' => true],
        'kampagne_test'       => ['label' => 'Kampagnen-Test', 'category' => 'other', 'points' => 0, 'productive' => false],
        'kampagne_vorschau'   => ['label' => 'Kampagnen-Vorschau', 'category' => 'navigation', 'points' => 0, 'productive' => false],
        'kampagne_geloescht'  => ['label' => 'Kampagne geloescht', 'category' => 'delete', 'points' => 0, 'productive' => true],
        'banner_verwaltet'    => ['label' => 'Banner verwaltet', 'category' => 'update', 'points' => 2, 'productive' => true],
        'servicepage_verwaltet' => ['label' => 'Leistungsseite verwaltet', 'category' => 'update', 'points' => 2, 'productive' => true],
        'inhalt_verwaltet'    => ['label' => 'Inhalt verwaltet', 'category' => 'update', 'points' => 2, 'productive' => true],

        // Partner / Provisionen / Rechnungen (Lexoffice)
        'partner_angelegt'        => ['label' => 'Partner angelegt', 'category' => 'create', 'points' => 5, 'productive' => true],
        'partner_bearbeitet'      => ['label' => 'Partner bearbeitet', 'category' => 'update', 'points' => 2, 'productive' => true],
        'provision_gebucht'       => ['label' => 'Provision gebucht (Beleg erstellt)', 'category' => 'create', 'points' => 5, 'productive' => true],
        'provision_abgelehnt'     => ['label' => 'Provision abgelehnt', 'category' => 'approve', 'points' => 1, 'productive' => true],
        'rechnung_versendet'      => ['label' => 'Rechnung versendet', 'category' => 'communication', 'points' => 5, 'productive' => true],
        'rechnung_heruntergeladen'=> ['label' => 'Rechnung heruntergeladen', 'category' => 'download', 'points' => 0, 'productive' => false],
        'kontakt_importiert'      => ['label' => 'Kontakt importiert', 'category' => 'create', 'points' => 3, 'productive' => true],

        // Verwaltung
        'mitarbeiter_angelegt'    => ['label' => 'Mitarbeiter angelegt', 'category' => 'create', 'points' => 2, 'productive' => true],
        'mitarbeiter_bearbeitet'  => ['label' => 'Mitarbeiter bearbeitet', 'category' => 'update', 'points' => 1, 'productive' => true],
        'mitarbeiter_geloescht'   => ['label' => 'Mitarbeiter geloescht', 'category' => 'delete', 'points' => 0, 'productive' => true],
        'team_verwaltet'          => ['label' => 'Team verwaltet', 'category' => 'update', 'points' => 2, 'productive' => true],
        'einstellungen_geaendert' => ['label' => 'Einstellungen geaendert', 'category' => 'other', 'points' => 0, 'productive' => true],
        'email_konto_verwaltet'   => ['label' => 'E-Mail-Konto verwaltet', 'category' => 'update', 'points' => 1, 'productive' => true],

        // Fallback fuer neue, noch nicht gemappte Schreiboperationen.
        'aktion_ausgefuehrt' => ['label' => 'Aktion ausgefuehrt', 'category' => 'other', 'points' => 1, 'productive' => true],
    ],

    // Mapping Routen-Name => Aktion. Wird NUR fuer Schreibmethoden
    // (POST/PUT/PATCH/DELETE) konsultiert. '*' am Ende = Prefix-Match.
    'route_map' => [
        'admin.customers.store'       => 'kunde_angelegt',
        'admin.customer.update'       => 'kunde_bearbeitet',
        'admin.customers.delete'      => 'kunde_geloescht',
        'admin.customers.bulk-delete' => 'kunde_geloescht',
        'admin.customers.bulk-assign' => 'kunden_zugewiesen',
        'admin.customer.merge.do'     => 'kunden_zusammengefuehrt',
        'admin.customer.note.store'   => 'notiz_angelegt',
        'admin.customer.note.done'    => 'notiz_erledigt',
        'admin.customer.document.store' => 'datei_hochgeladen',
        'admin.customer.family.store' => 'familienmitglied_angelegt',
        'admin.customer.vehicle.store'=> 'fahrzeug_angelegt',
        'admin.customer.portal.*'     => 'portal_zugang_verwaltet',

        'admin.contract.store'   => 'vertrag_angelegt',
        'admin.contract.update'  => 'vertrag_bearbeitet',
        'admin.contract.destroy' => 'vertrag_geloescht',
        'admin.contracts.switch_responded' => 'wechsel_reaktion_erfasst',

        'admin.ticket.reply'  => 'ticket_beantwortet',
        'admin.ticket.status' => 'ticket_status_geaendert',
        'admin.ticket.update' => 'ticket_bearbeitet',
        'admin.ticket.note'   => 'ticket_notiz_angelegt',
        'admin.inquiries.store' => 'anfrage_erfasst',

        'admin.change_requests.action'    => 'freigabe_entschieden',
        'admin.document_requests.store'   => 'dokumentanfrage_angelegt',
        'admin.document_requests.approve' => 'dokumentanfrage_entschieden',
        'admin.document_requests.reject'  => 'dokumentanfrage_entschieden',

        'admin.email_inbox.confirm'   => 'email_zugeordnet',
        'admin.email_inbox.assign'    => 'email_zugeordnet',
        'admin.email_inbox.reject'    => 'email_abgelehnt',
        'admin.email_inbox.ai_accept' => 'ki_vorschlag_entschieden',
        'admin.email_inbox.ai_reject' => 'ki_vorschlag_entschieden',

        'admin.documents.replace' => 'datei_ersetzt',
        'admin.documents.update'  => 'datei_bearbeitet',
        'admin.documents.destroy' => 'datei_geloescht',

        'admin.chat.store'       => 'chat_nachricht',
        'admin.chat.reply'       => 'chat_nachricht',
        'admin.internal.store'   => 'interne_notiz',
        'admin.internal.destroy' => 'interne_notiz_geloescht',

        'admin.tasks.store'   => 'aufgabe_angelegt',
        'admin.tasks.update'  => 'aufgabe_bearbeitet',
        'admin.tasks.destroy' => 'aufgabe_geloescht',
        'admin.appointments.store'  => 'termin_angelegt',
        'admin.appointments.update' => 'termin_bearbeitet',

        'admin.import' => 'daten_importiert',

        'admin.email_marketing.send'      => 'kampagne_versendet',
        'admin.email_marketing.dispatch'  => 'kampagne_versendet',
        'admin.email_marketing.reminders' => 'kampagne_versendet',
        'admin.email_marketing.test'      => 'kampagne_test',
        'admin.email_marketing.preview'   => 'kampagne_vorschau',
        'admin.email_marketing.destroy'   => 'kampagne_geloescht',

        'admin.banners.*'       => 'banner_verwaltet',
        'admin.service_pages.*' => 'servicepage_verwaltet',
        'admin.tarifrechner.*'  => 'inhalt_verwaltet',
        'admin.announcements.*' => 'inhalt_verwaltet',

        'admin.partners.store'     => 'partner_angelegt',
        'admin.partners.update'    => 'partner_bearbeitet',
        'admin.commissions.book'   => 'provision_gebucht',
        'admin.commissions.reject' => 'provision_abgelehnt',
        'admin.lexoffice.invoice.send' => 'rechnung_versendet',
        'admin.lexoffice.import'       => 'kontakt_importiert',

        'admin.employees.store'   => 'mitarbeiter_angelegt',
        'admin.employees.update'  => 'mitarbeiter_bearbeitet',
        'admin.employees.destroy' => 'mitarbeiter_geloescht',
        'admin.employees.toggle'  => 'mitarbeiter_bearbeitet',
        'admin.team.*'            => 'team_verwaltet',

        'admin.settings.update'          => 'einstellungen_geaendert',
        'admin.activity.settings.update' => 'einstellungen_geaendert',
        'admin.email_accounts.*'         => 'email_konto_verwaltet',
    ],

    // GET-Routen mit eigener Bedeutung (Downloads/Exporte/Sonderfaelle).
    // Alle anderen GETs werden als 'seite_geoeffnet' protokolliert.
    'get_map' => [
        'admin.documents.download'       => 'datei_heruntergeladen',
        'admin.attachment.download'      => 'datei_heruntergeladen',
        'admin.email_inbox.attachment'   => 'datei_heruntergeladen',
        'admin.change_requests.document' => 'datei_heruntergeladen',
        'admin.import.template'          => 'datei_heruntergeladen',
        'admin.lexoffice.invoice.download' => 'rechnung_heruntergeladen',
        'admin.export'                   => 'daten_exportiert',
        'admin.activity.export'          => 'daten_exportiert',
        'admin.activity.user_export'     => 'daten_exportiert',
        // Achtung: dieses Loeschen laeuft (historisch) ueber GET.
        'admin.customer.family.delete'   => 'familienmitglied_geloescht',
    ],
];
