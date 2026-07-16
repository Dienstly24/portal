# Aktivitaetserfassung & Produktivitaet (Mitarbeiter)

Serverseitige Erfassung von Arbeitssitzungen, Aktivitaeten und
Produktivitaetspunkten fuer Staff-Konten (admin/manager/support/employee).
Kunden- und Partner-Konten werden NIE erfasst.

## Was wird erfasst

1. **Arbeitssitzungen** (`work_sessions`): Login, Logout, letzter Request,
   letzte produktive Aktion, aktive Sekunden, Ende-Grund
   (`logout` / `timeout` / `new_login`), IP und Geraet.
2. **Aktivitaetslog** (`activity_logs`, bestehende Tabelle erweitert):
   jede Aktion mit Aktionstyp, Seite/Route, Methode, verknuepftem
   Datensatz, IP, Geraet, Produktiv-Flag, Punkten und gutgeschriebener
   Aktivzeit. Eintraege sind ueber die UI weder aenderbar noch loeschbar.

Hinweis: Fuer Aktionen, die schon vorher manuell auditiert wurden
(z. B. `employee_created`), entstehen zwei Eintraege - der manuelle
Audit-Eintrag und der automatische Tracking-Eintrag (mit IP/Punkten).
Das ist beabsichtigt: das manuelle Audit bleibt unveraendert bestehen.

## Wie aktive Arbeitszeit berechnet wird

- Aktivzeit entsteht AUSSCHLIESSLICH durch **produktive Aktionen**
  (Anlegen, Bearbeiten, Hochladen, Freigeben, ... - siehe Katalog in
  `config/activity.php`). Reines Eingeloggt-Sein, Seitenwechsel,
  fehlgeschlagene Requests (Validierungsfehler, 4xx/5xx) und
  Tab-Wechsel/Minimieren zaehlen nicht.
- Pro produktiver Aktion wird die Luecke seit der letzten produktiven
  Aktion (bzw. seit Login) gutgeschrieben, **gedeckelt auf den
  Leerlauf-Schwellwert** (Default 5 Minuten, einstellbar). Damit zaehlt
  die Zeit des "Formular-Ausfuellens" vor dem Absenden mit, lange Pausen
  aber nicht. Kehrt der Mitarbeiter zurueck, laeuft die Zaehlung mit der
  naechsten produktiven Aktion einfach weiter.
- **Leerlauf** = Anmeldezeit - aktive Zeit.
- Ohne jeglichen Request gilt eine Sitzung nach dem **Sitzungs-Timeout**
  (Default 30 Minuten, einstellbar) als beendet; als Ende zaehlt der
  letzte gesehene Request (stille Zeit blaeht die Anmeldezeit nicht auf).
  Aufraeumen zusaetzlich per Scheduler: `activity:close-stale`
  (alle 15 Minuten).
- Das Notification-Polling des Browsers (alle 60 s) ist vollstaendig
  ausgenommen und haelt Sitzungen nicht kuenstlich offen.

Alles laeuft serverseitig (Middleware `TrackStaffActivity` in der
Web-Gruppe + Login/Logout-Listener im `AppServiceProvider`); Mitarbeiter
koennen die Erfassung weder sehen noch abschalten.

## Produktivitaetspunkte

Jede produktive Aktion hat ein Punkte-Gewicht. Defaults stehen in
`config/activity.php`; Overrides liegen in `system_settings`
(`activity_points` als JSON) und sind unter
**Admin -> Aktivitaet & Zeiten -> Einstellungen** pflegbar - ohne
Code-Aenderung. Ebenso einstellbar: Leerlauf-Schwellwert
(`activity_idle_threshold_minutes`) und Sitzungs-Timeout
(`activity_session_timeout_minutes`).

Unbekannte neue Schreiboperationen werden automatisch als
`aktion_ausgefuehrt` (1 Punkt) gezaehlt; ein praezises Mapping kann
jederzeit im `route_map` der Config ergaenzt werden - so bleibt das
System erweiterbar, ohne Tracker/Reports anzufassen.

## Berichte (nur Verwaltung)

- **Uebersicht** `/admin/aktivitaet` (role: admin, manager): Ranking
  aller Mitarbeiter mit Anmeldezeit, aktiver Zeit, Leerlauf,
  Aktionszahlen (angelegt/bearbeitet/Uploads), Punkten und Punkten je
  aktiver Stunde; Team-Kennzahlen; Vergleichs-Chart; Zeitraeume
  Heute/Woche/Monat/frei; CSV-Export (Semikolon, deutsches Excel).
- **Detail** `/admin/aktivitaet/{user}`: Tagesuebersicht, Aktionen nach
  Typ, Arbeitssitzungen (inkl. IP/Geraet), vollstaendiger
  Aktivitaetsverlauf (paginierbar).
- **Einstellungen** `/admin/aktivitaet/einstellungen` (nur admin).
- Das bestehende rohe **Aktivitaetsprotokoll** `/admin/activity-log`
  bleibt erhalten und kennt jetzt auch die neuen Aktions-Labels.

Mitarbeiter (role employee/support) haben keinen Zugriff auf Berichte,
Einstellungen oder Berechnungsparameter und sehen keinerlei Hinweis auf
die Erfassung.

## Datenschutz-Hinweis (Betreiber)

Die Erfassung von Arbeitszeiten und Aktivitaeten der Mitarbeiter ist
Beschaeftigtendatenverarbeitung (Art. 88 DSGVO / § 26 BDSG). Vor
Inbetriebnahme: Mitarbeiter informieren (Transparenzpflicht) und die
Rechtsgrundlage dokumentieren. Log-Daten enthalten IP-Adressen und
Geraetekennungen.

**Aufbewahrung:** Reine Seitenaufruf-Eintraege (`seite_geoeffnet`)
werden nach Ablauf der Frist automatisch geloescht
(`activity:prune`, taeglich 03:45; Frist per
`activity_navigation_retention_days` in `system_settings`,
Default 90 Tage). Produktive Aktionen (Audit-Trail) und
Arbeitssitzungen bleiben erhalten.

## Grenzen (bewusst)

- "Drucken" existiert im System nicht als Serveraktion und kann daher
  nicht erfasst werden; Downloads/Exporte werden erfasst.
- Aktivzeit misst Aktionen im Portal - Arbeit ausserhalb des Systems
  (Telefonate, E-Mail-Programm) erscheint nicht.
- Es gibt genau EINE aktive Sitzung je Mitarbeiter: eine neue Anmeldung
  (z. B. am Handy) beendet die offene Sitzung des anderen Geraets
  (`new_login`); die Gesamtzeiten bleiben korrekt, nur die Zuordnung
  IP/Geraet wechselt. Seltene Parallel-Duplikate heilen sich selbst
  (`duplicate`).
