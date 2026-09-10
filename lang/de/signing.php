<?php

/**
 * Die Sprache des UNTERZEICHNERS - der einzigen Person im ganzen System,
 * die kein Konto hat, die Oberflaeche nicht kennt und den Vorgang nicht
 * wiederholen kann, wenn sie ihn nicht versteht. Ein deutscher Satz auf
 * einer arabischen Seite ist deshalb hier teurer als anderswo.
 *
 * Deutsch ist die Rueckfallsprache: fehlt ein Schluessel in ar/en, erscheint
 * der deutsche Satz - nie der rohe Schluessel.
 */
return [
    'title' => 'Dokument unterschreiben',
    'brand_subtitle' => 'Elektronische Unterschrift',

    // Seite
    'sign_document' => 'Unterschrift bestätigen',
    'document' => 'Dokument',
    'pages' => 'Seiten',
    'open_original' => 'Original-PDF öffnen',
    'your_fields' => 'Ihre Eingaben',
    'draw_here' => 'Hier unterschreiben',
    'redraw' => 'Neu zeichnen',
    'empty' => 'noch leer',
    'signed_mark' => 'unterschrieben',
    'progress' => ':done von :total unterschrieben',
    'saving' => 'Wird gespeichert …',
    'required' => 'Pflichtfeld',
    'optional' => 'freiwillig',
    'missing_signature' => 'Bitte zeichnen Sie Ihre Unterschrift in das dafür vorgesehene Feld.',
    'preview_unavailable' => 'Die Seitenvorschau steht gerade nicht zur Verfügung. Bitte öffnen Sie das Original-PDF.',

    // Zustimmung
    'consent_default' => 'Mit dem Klick auf "Unterschrift bestätigen" geben Sie eine elektronische Unterschrift ab. Datum, Uhrzeit, IP-Adresse und Geraeteangaben werden zum Nachweis gespeichert. Sie erhalten das unterschriebene Dokument per E-Mail.',
    'consent_required' => 'Bitte bestätigen Sie den Hinweis zur elektronischen Unterschrift.',

    // Ablehnen
    'decline' => 'Unterschrift ablehnen',
    'decline_reason' => 'Grund (freiwillig)',
    'decline_confirm' => 'Möchten Sie die Unterschrift wirklich ablehnen?',

    // E-Mail-Bestätigung
    'verify_title' => 'Bestätigen Sie Ihre E-Mail-Adresse',
    'verify_lead' => 'Zu Ihrer Sicherheit senden wir einen sechsstelligen Code an :email. Erst danach wird das Dokument angezeigt.',
    'verify_send' => 'Code senden',
    'verify_code' => 'Bestätigungscode',
    'verify_submit' => 'Weiter',
    'verify_sent' => 'Wir haben Ihnen einen Bestätigungscode an :email gesendet.',
    'verify_wrong' => 'Der Code stimmt nicht oder ist abgelaufen. Bitte fordern Sie einen neuen an.',
    'verify_spam' => 'Keine E-Mail erhalten? Bitte sehen Sie auch im Spam-Ordner nach.',
    'verify_send_failed' => 'Der Code konnte nicht versendet werden. Bitte später erneut versuchen.',
    'verify_first' => 'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse.',

    // Zusaetzliche Identitaetspruefung
    'dob_title' => 'Bitte bestätigen Sie Ihr Geburtsdatum',
    'dob_lead' => 'Zum Schutz Ihrer Daten fragen wir vor der Anzeige des Dokuments Ihr Geburtsdatum ab.',
    'dob_label' => 'Geburtsdatum',
    'dob_submit' => 'Weiter',
    'dob_wrong' => 'Die Angabe stimmt nicht. Bitte versuchen Sie es erneut.',
    'dob_blocked' => 'Zu viele Versuche. Bitte versuchen Sie es später erneut.',

    // Abschluss
    'done_title' => 'Vielen Dank - Ihre Unterschrift ist eingegangen.',
    'done_lead' => 'Eine Kopie des unterschriebenen Dokuments geht an :email.',
    'done_waiting' => 'Das fertige Dokument wird erstellt, sobald alle Beteiligten unterschrieben haben.',
    'done_declined' => 'Sie haben die Unterschrift abgelehnt. Wir haben das vermerkt.',
    'download' => 'Unterschriebenes PDF herunterladen',

    // Gesperrt
    'blocked_title' => 'Dieses Dokument steht Ihnen gerade nicht zur Verfügung.',
    'blocked_link_dead' => 'Dieser Link ist nicht mehr gültig.',
    'blocked_already_signed' => 'Sie haben dieses Dokument bereits unterschrieben.',
    'blocked_already_declined' => 'Sie haben die Unterschrift zu diesem Dokument abgelehnt.',
    'blocked_cancelled' => 'Diese Signaturanfrage wurde zurückgezogen.',
    'blocked_expired' => 'Die Frist für dieses Dokument ist abgelaufen.',
    'blocked_not_open' => 'Dieses Dokument steht nicht (mehr) zur Unterschrift bereit.',
    'blocked_wait_turn' => 'Dieses Dokument wird nacheinander unterschrieben. Sie erhalten eine E-Mail, sobald Sie an der Reihe sind.',
    'blocked_help' => 'Bei Fragen wenden Sie sich bitte an Ihren Ansprechpartner bei Dienstly24.',

    // Fehler - NIE technisch
    'error_generic' => 'Ihre Unterschrift konnte gerade nicht gespeichert werden. Bitte versuchen Sie es in einem Moment erneut.',
    'error_no_field' => 'Für Sie ist in diesem Dokument kein Feld hinterlegt.',
    'error_field_missing' => 'Bitte füllen Sie das Feld ":field" aus.',
    'error_signature_missing' => 'Bitte unterschreiben Sie im Feld ":field".',

    // Rechtlicher Hinweis - bewusst zurueckhaltend
    'legal_note' => 'Dies ist eine einfache elektronische Signatur mit technischem Nachweis (eIDAS Art. 3 Nr. 10). Sie ist keine qualifizierte elektronische Signatur.',

    // Weitere Seitentexte
    'blocked_done_title' => 'Dieses Dokument ist fertig ✅',
    'blocked_nothing_title' => 'Hier ist gerade nichts zu tun',
    'blocked_copy_sent' => 'Ihre Kopie haben wir Ihnen per E-Mail an :email gesendet.',
    'view_signed_pdf' => 'Unterschriebenes PDF ansehen',
    'questions' => 'Fragen dazu? Schreiben Sie uns über :link - bitte mit dem Titel des Dokuments.',
    'contact_form' => 'unser Kontaktformular',
    'done_head_declined' => 'Abgelehnt',
    'done_head_signed' => 'Erfolgreich unterschrieben',
    'done_declined_title' => 'Sie haben die Unterschrift abgelehnt',
    'done_declined_body' => 'Wir haben Ihre Rückmeldung zu „:title" erhalten und an Ihren Ansprechpartner weitergeleitet. Sie brauchen nichts weiter zu tun.',
    'done_completed_body' => 'Vielen Dank. Das Dokument „:title" ist vollständig unterschrieben. Ihre Kopie geht an :email.',
    'done_partial_body' => 'Vielen Dank - Ihre Unterschrift ist gespeichert. Sobald auch die übrigen Unterzeichner unterschrieben haben, senden wir Ihnen das fertige Dokument an :email.',
    'checksum' => 'Prüfsumme (SHA-256): :hash',
    'close_window' => 'Sie können dieses Fenster jetzt schließen.',
    'verify_head' => 'E-Mail bestätigen',
    'verify_heading' => 'Kurze Bestätigung 🔐',
    'verify_intro' => 'Guten Tag :name, bevor wir Ihnen das Dokument „:title" zeigen, bestätigen wir kurz, dass Sie Zugriff auf :email haben.',
    'verify_send_code' => 'Bestätigungscode senden',
    'verify_code_label' => 'Sechsstelliger Code aus der E-Mail',
    'verify_continue' => 'Weiter zum Dokument',
    'verify_new_code' => 'Neuen Code anfordern',
    'verify_never_share' => 'Der Code gilt 30 Minuten. Bitte geben Sie ihn niemals weiter - auch nicht an Mitarbeitende von Dienstly24.',

    // E-Mails
    'intro' => 'Guten Tag :name, bitte prüfen Sie das Dokument und unterschreiben Sie unten. Es hat :pages Seite(n); für Sie sind :fields Feld(er) vorgesehen.',
    'valid_until' => 'Gültig bis :date.',
    'page_of' => 'Seite :page von :total',
    'page_n' => 'Seite :page',
    'draw_hint' => 'Seite :page · Mit dem Finger, dem Stift oder der Maus in das Feld zeichnen.',
    'preview_missing' => 'Die Seitenansicht ist gerade nicht verfügbar. Bitte öffnen Sie das Original-PDF und unterschreiben Sie anschließend unten.',
    'consent_heading' => 'Hinweis zur elektronischen Unterschrift',
    'consent_checkbox' => 'Ich habe das Dokument gelesen und unterschreibe es elektronisch.',
    'checkbox_default' => 'Hiermit bestätige ich diesen Punkt',
    'decline_heading' => 'Nicht unterschreiben?',
    'decline_lead' => 'Wenn Sie das Dokument nicht unterschreiben möchten, teilen Sie uns das bitte hier mit.',
    'decline_confirm_long' => 'Möchten Sie die Unterschrift wirklich ablehnen? Der Vorgang wird damit beendet.',
    'canvas_label' => 'Zeichenfläche für :field',

    'mail_invitation_subject' => 'Bitte unterschreiben: :title',
    'mail_invitation_greeting' => 'Guten Tag :name,',
    'mail_invitation_body' => ':sender bittet Sie, das folgende Dokument elektronisch zu unterschreiben.',
    'mail_invitation_button' => 'Dokument öffnen und unterschreiben',
    'mail_expires' => 'Der Link ist gültig bis :date.',
    'mail_reminder_subject' => 'Erinnerung: :title wartet auf Ihre Unterschrift',
    'mail_completed_subject' => 'Unterschrieben: :title',
    'mail_completed_body' => 'Das Dokument ist vollständig unterschrieben. Ihre Kopie liegt dieser E-Mail bei.',
    'mail_code_subject' => 'Ihr Bestätigungscode',
    'mail_code_body' => 'Ihr Bestätigungscode lautet:',
    'mail_code_validity' => 'Der Code ist :minutes Minuten gültig.',
    'mail_ignore' => 'Wenn Sie dieses Dokument nicht erwarten, ignorieren Sie diese E-Mail bitte.',
    'mail_greeting' => 'Guten Tag :name,',
    'mail_invitation_head' => 'Dokument zur Unterschrift ✍️',
    'mail_reminder_head' => 'Erinnerung: Ihre Unterschrift fehlt noch ✍️',
    'mail_invitation_intro' => 'Dienstly24 bittet Sie, das folgende Dokument elektronisch zu unterschreiben:',
    'mail_reminder_intro' => 'wir möchten Sie freundlich daran erinnern, dass folgendes Dokument noch auf Ihre Unterschrift wartet:',
    'mail_pages' => ':count Seite(n)',
    'mail_reference' => 'Referenz: :ref',
    'mail_deadline' => 'Bitte bis zum :date unterschreiben.',
    'mail_no_account' => 'Sie brauchen dafür kein Benutzerkonto. Der folgende Button führt Sie direkt zum Dokument - unterschreiben können Sie mit dem Finger auf dem Handy oder mit der Maus am Computer.',
    'mail_code_hint' => 'Zu Ihrer Sicherheit senden wir Ihnen beim Öffnen zusätzlich einen sechsstelligen Bestätigungscode an diese E-Mail-Adresse.',
    'mail_link_personal' => 'Dieser Link ist persönlich und gilt nur für Sie. Bitte geben Sie ihn nicht weiter. Falls der Button nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser:',
    'mail_not_expected' => 'Sie erwarten kein Dokument von uns? Dann ignorieren Sie diese E-Mail bitte - ohne Ihre Bestätigung passiert nichts.',
    'mail_regards' => 'Mit freundlichen Grüßen',
    'mail_team' => 'Ihr Dienstly24 Team',
    'mail_completed_head' => 'Erfolgreich unterschrieben ✅',
    'mail_completed_intro' => 'vielen Dank - das Dokument ist vollständig unterschrieben. Ihre Kopie finden Sie im Anhang dieser E-Mail.',
    'mail_completed_at' => 'Abgeschlossen am :date',
    'mail_checksum_label' => 'Prüfsumme (SHA-256) des unterschriebenen Dokuments:',
    'mail_protocol_note' => 'Die letzte Seite des PDF enthält das Signaturprotokoll mit allen Angaben zum Ablauf. Bewahren Sie die Datei bitte auf - die Prüfsumme belegt, dass das Dokument seitdem nicht verändert wurde.',
    'mail_code_head' => 'Ihr Bestätigungscode 🔐',
    'mail_code_intro' => 'bitte geben Sie diesen Code ein, um das Dokument „:title" zu öffnen:',
    'mail_code_note' => 'Der Code gilt 30 Minuten. Geben Sie ihn niemals an Dritte weiter - auch nicht an Mitarbeitende von Dienstly24. Haben Sie den Code nicht angefordert, ignorieren Sie diese E-Mail bitte.',
    'mail_subject_invitation' => 'Dokument zur Unterschrift – Dienstly24',
    'mail_subject_reminder' => 'Erinnerung: Dokument zur Unterschrift – Dienstly24',
    'mail_subject_completed' => 'Unterschrieben: :title – Dienstly24',
    'mail_subject_code' => 'Ihr Bestätigungscode – Dienstly24',
];
