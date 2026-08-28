# Dienstly24 Portal — Projektkontext für Claude

Dies ist das CRM/Kundenportal von **Dienstly24**, einem Versicherungs- und
Energie-Makler. Laravel 13 / PHP 8.3+, gehostet auf einem Hostinger-VPS.
Domains: `admin.dienstly24.de` (Beraterwelt), `portal.dienstly24.de`
(Kundenportal). Die Kommunikation mit dem Betreiber läuft überwiegend auf
Arabisch; **antworte dem Nutzer auf Arabisch**, aber halte allen Code,
Commits, UI-Texte und Kommentare auf **Deutsch/ASCII**.

## Arbeitsweise (WICHTIG — immer so vorgehen)

1. **Nie direkt deployen.** Für jede Änderung einen Feature-Branch anlegen,
   committen, pushen und einen **Pull Request mit `base=main`** öffnen.
2. Der **Nutzer reviewt und merged selbst.** Merge auf `main` löst über
   GitHub Actions automatisch den Deploy aus.
3. **Prüfe bei jedem PR, dass `base=main` ist** (ein früherer PR wurde
   versehentlich gegen einen toten Branch geöffnet und lief ins Leere).
4. Nach einem Merge für Folgearbeit **immer `git fetch origin main` und neu
   branchen** — sonst arbeitet man auf veraltetem Stand.
5. Vor jedem Push **die volle Testsuite grün** halten: `php artisan test`.
6. UI-/E-Mail-Änderungen möglichst **real verifizieren** (Headless-Chromium
   unter `/opt/pw-browsers/…`, `playwright-core`), nicht nur Tests.

## Deploy

- CI/CD: `.github/workflows/deploy.yml` — Tests bei Push & PR; Deploy nur bei
  Push auf `main`.
- **Bekanntes Problem:** Der SSH-Deploy schlägt teils mit `i/o timeout` fehl
  (VPS-Erreichbarkeit/Firewall Port 22). Das ist **kein Code-Fehler**.
  Manueller Deploy auf dem Server:
  ```
  cd /var/www/dienstly24/portal && git fetch --all --prune \
    && git reset --hard origin/main && bash scripts/deploy.sh
  ```

## Feste Regeln (Sicherheit / DSGVO)

- **Löschen von Kunden:** Admin per UI **max. 30 pro Bulk-Aktion**;
  **Mitarbeiter dürfen NIE löschen**. Voll-Purge nur per CLI
  (`php artisan customers:purge --force`).
- `CustomerDeletionService` darf **niemals Staff-/Partner-Accounts** löschen
  (Guard: nur `role === 'customer'`).
- **Keine Geheimnisse im Chat/Repo** (SSH-Keys, Tokens, Passwörter) — nur
  GitHub Secrets / Server-`.env`.
- **Keine erfundenen Daten**: keine falschen Impressum-Angaben, USt-IdNr.
  oder Fake-Statistiken (z. B. „15.000 Kunden") in der UI.
- Magic-Login-Link nie in QR-Codes oder geteilten Assets einbetten.
- Terminal-Befehle für den Nutzer immer **Deutsch/ASCII**.

## Passwoerter & Zugang (Betreiber-Vorgabe 18.08.2026 - "Sicherheit sehr hoch")

- **EINE Regel fuer alle Pfade**: `App\Support\PasswordPolicy`. Kunden
  mind. **12** Zeichen, Personal/Partner mind. **14** (sie sehen fremde
  personenbezogene Daten). KEIN Zeichenklassen-Zwang (BSI/NIST: Laenge
  schlaegt Komplexitaet - erzwungene Sonderzeichen erzeugen "Passwort1!").
  Stattdessen **Abgleich gegen bekannte Datenlecks** (HaveIBeenPwned,
  k-Anonymity; in Tests aus, per `PASSWORD_BREACH_CHECK` abschaltbar,
  faellt bei Netzfehler auf "bestanden" zurueck). `Password::defaults()`
  ist in `AppServiceProvider` auf diese Quelle verdrahtet - neue Pfade
  bekommen die Regel automatisch.
- **NIE ein Klartext-Passwort verschicken oder anzeigen.** Die
  Mitarbeiter-Anlage hat kein Passwort-Feld mehr; es geht eine Einladung
  mit **signiertem Link** raus (`PasswordSetupController::invitationUrl`,
  **14 Tage**, `EmployeeWelcomeMail` bewusst NICHT queued - sie ist der
  einzige Weg ins Konto). Zugang wiederherstellen = "Einladung erneut
  senden" (`employees.resend_invitation`), nie ein vergebenes Passwort.
- **Signatur RELATIV** (`absolute: false` + `signed:relative`):
  `CustomerWelcomeMail` schreibt jeden Kundenlink auf die Portal-Domain
  um - eine ueber den HOST mitsignierte Adresse waere danach ungueltig.
- **Zwei Fristen, zwei Wege**: Selbst angeforderter Reset-Link = Broker,
  **60 Minuten** (`AUTH_PASSWORD_RESET_EXPIRE`). Einladung = signierter
  Link, 14 Tage. Frueher teilten sie sich einen Broker mit 3 Tagen - eine
  Frist, die fuer das eine zu lang und fuer das andere zu kurz war.
- **Startpasswort = Geburtsdatum bleibt**, aber es haelt nicht mehr
  dauerhaft: `users.must_change_password` + Middleware
  `EnsurePasswordChanged` fuehren beim naechsten Aufruf auf
  `/passwort-festlegen`. Das Geburtsdatum steht auf jedem Ausweis und in
  jedem Versicherungsschein - als Dauer-Passwort ist es oeffentlich.
  Erreichbar bleiben Abmelden, Sprachwechsel, Rechtsseiten und die
  regulaeren Passwort-Formulare (sonst Sackgasse). `resetPortal()` stellt
  den Zwang wieder scharf. Das bisherige Passwort erneut zu setzen wird
  abgelehnt.
- **Passwortwechsel wirft fremde Sitzungen raus**:
  `AuthenticateSession` liegt in der Web-Gruppe, jeder Wechsel ruft
  `logoutOtherDevices()`. Danach IMMER
  `App\Support\SessionPasswordHash::refresh($request)` - sonst fliegt der
  Nutzer aus seiner EIGENEN Sitzung und denkt, es habe nicht geklappt.
  Alle Schreibwege laufen ueber `User::setPassword()` (Zeitstempel,
  `portal_password_set_at`, Zwang aufheben an EINER Stelle).
- **"Passwort vergessen" ist auf Kunden zugeschnitten** (gemeldetes
  Problem: Kunden fanden den Weg nicht): Kennung ist E-Mail **oder
  KUNDENNUMMER** (steht auf jedem Schreiben) oder die Zweitadresse
  `email2`; Ergebnis ist eine **eigene Seite** mit Schritt-fuer-Schritt-
  Erklaerung, Spam-Hinweis, Gueltigkeitsdauer und Hilfe-Link. Die Antwort
  ist IMMER dieselbe, ob das Konto existiert oder nicht (keine
  Enumeration, DSGVO Art. 32) - einzige Ausnahme sind die internen
  `@dienstly24.internal`-Platzhalter, die technisch keine Mail empfangen
  koennen. Das Feld `email` wird weiterhin akzeptiert (alte Lesezeichen).
- **Arabische Formularfehler**: `lang/ar/validation.php` existiert jetzt.
  Ohne sie kamen alle Validierungsmeldungen auf Englisch - ausgerechnet
  in den Passwort-Formularen.
- Tests: `PasswordSecurityTest`, `PasswordFlowHardeningTest`.

## Zwei-Faktor-Anmeldung (Betreiber-Vorgabe 18.08.2026)

- **Pflicht fuer alle internen Rollen** (admin/manager/support/employee)
  UND partner - sie sehen fremde personenbezogene Daten. Kundenkonten
  bewusst NICHT: ein Kunde sieht nur die eigenen Daten, die Huerde waere
  groesser als der Gewinn. Steuerung: `User::requiresTwoFactor()`.
- **Voreinstellung AN** (`SystemSetting two_factor_required`, Schalter
  unter Einstellungen -> Sicherheit). Anders als beim KI-Assistenten ist
  AUS hier die Ausnahme: eine Schutzschicht, die man erst einschalten
  muss, ist in der Praxis meistens aus.
- **Niemand kann sich aussperren** - das war die Bedingung fuer die
  Pflicht: Einrichtung fuehrt das System beim naechsten Login selbst
  durch (`EnsureTwoFactor` -> `/sicherheit/zwei-faktor`), es gibt
  8 Ersatzcodes (gehasht wie Passwoerter, je genau EINMAL nutzbar),
  Admin-Reset in der Mitarbeiterakte (**nur admin**) und als letzte
  Rettung `php artisan 2fa:zuruecksetzen <email>` auf dem Server
  (`--alle-anzeigen` listet, wer eingerichtet hat). Abmelden,
  Sprachwechsel und Rechtsseiten bleiben in jedem Zustand erreichbar.
- **Erst bestaetigen, dann scharf**: `two_factor_confirmed_at` wird nur
  gesetzt, wenn ein gueltiger Code eingegeben wurde. Wer die Seite nur
  geoeffnet hat, ist nicht ausgesperrt. Ein bereits bestaetigtes
  Geheimnis wird NIE still ersetzt (sonst macht ein Seitenaufruf die
  funktionierende App ungueltig).
- **Sitzungsschluessel traegt die Benutzer-ID** (`2fa_ok:<id>`) - ein
  blosses Flag wuerde nach einem Kontowechsel in derselben Sitzung
  weitergelten.
- **Alles selbst gebaut, aber geprueft**: `App\Support\Totp` (RFC 6238,
  gegen die amtlichen Testvektoren getestet) und `App\Support\QrCode`
  (Byte-Modus, Fehlerkorrektur M, Versionen 1-20). Der QR-Code ist ein
  INLINE-SVG - das Geheimnis erreicht nie eine Datei, keinen Cache und
  keinen fremden Dienst. Der Schluessel steht IMMER auch abtippbar da
  (Telefon ohne Kamera). Die Golden-Hashes in `tests/Unit/QrCodeTest.php`
  sichern den geprueften Stand ab; aendert sich einer, wurde die
  Erzeugung veraendert - das faellt sonst erst beim Mitarbeiter auf, der
  seine App nicht einrichten kann.
- **Raten wird teuer**: 5 Fehlversuche je Konto+IP (300 s Sperre) plus
  Route-Throttle; jeder Fehlversuch und jede Aenderung am zweiten Faktor
  steht im ActivityLog (`two_factor_*`) - der Code selbst nie.
- `ExtraBasicAuth` bleibt als zusaetzliche Schicht bestehen, ist aber
  nicht mehr der einzige Schutz.
- Tests: `TwoFactorTest`, `tests/Unit/TotpTest.php`,
  `tests/Unit/QrCodeTest.php`. Arabische Betreiber-Anleitung:
  `docs/ANLEITUNG_ZWEI_FAKTOR_AR.md`.

## Systemzustand-Seite (Betreiber-Auftrag 19.08.2026)

- **Warum**: die riskanten Teile laufen im HINTERGRUND (Warteschlange,
  Planer, externe Dienste). Faellt dort etwas aus, gibt es keine
  Fehlermeldung und keine leere Seite - es passiert einfach nichts mehr.
  Analysen bleiben "in Pruefung", Erinnerungen gehen nicht raus, die KI
  schweigt. Bisher fiel das erst auf, wenn sich ein Kunde beschwert hat.
- `/admin/systemzustand` (`SystemHealthController`, `SystemHealthService`,
  View `admin/system_health.blade.php`), **nur admin/manager**. Die Seite
  ist REIN LESEND - kein Knopf, der etwas ausloest. Vier Abschnitte:
  Warteschlange, geplante Aufgaben, externe Dienste, Anmeldung/Sicherheit.
- **NIE ein Geheimnis ausgeben**, auch nicht teilweise: zu Schluesseln steht
  ausschliesslich "gesetzt" oder "fehlt" (Test sichert das ab). Ebenso
  **keine kostenpflichtigen Aufrufe beim Seitenaufbau** - geprueft wird die
  KONFIGURATION. Den echten Live-Test macht bewusst nur
  `php artisan ki:pruefen --live`.
- **Geplante Aufgaben: Laravel merkt sich den letzten Lauf NICHT.** Deshalb
  Tabelle `scheduled_task_runs` (eine Zeile je Aufgabe, kein Lauf-Archiv)
  plus Listener auf `ScheduledTaskStarting/Finished/Failed` im
  `AppServiceProvider`. Erst damit ist ein fehlender Cron-Eintrag oder eine
  falsche Planer-Zeitzone sichtbar. Das Protokollieren darf den Betrieb NIE
  stoeren - jeder Fehler dort wird geschluckt, die Aufgabe laeuft weiter.
  Ein erfolgreicher Lauf loescht `last_error` (sonst stuende dauerhaft eine
  behobene Meldung da), die Fehler-ZAEHLER bleiben (Historie).
- **Der Planer ist im Web-Aufruf leer**: `routes/console.php` wird nur im
  Console-Kontext geladen. `SystemHealthService::scheduledEvents()` stoesst
  daher den Console-Kernel an (idempotent) - ohne diesen Schritt meldete die
  Seite "keine geplanten Aufgaben", also ausgerechnet dort Fehlalarm, wo sie
  Vertrauen schaffen soll. Die Closures in `routes/console.php` haben
  deshalb jetzt `->name(...)` - ohne Namen faellt jede Closure auf denselben
  Schluessel.
- **Toter Worker**: nicht die Stapelhoehe zaehlt, sondern das ALTER des
  aeltesten Jobs (> 15 Min = niemand holt ihn ab). Ein voller Stapel ist
  normal, ein alter Job nie. Bei leerer Warteschlange ist ein toter Worker
  prinzipiell nicht erkennbar - die Seite sagt das ehrlich dazu.
- `/admin/systemzustand.json` liefert dieselbe Ampel fuer externe
  Ueberwachung (**HTTP 503**, wenn etwas handlungsbeduerftig ist) - bewusst
  nur Titel/Zustand/Kurzfassung, keine Einzelwerte.
- Tests: `SystemHealthTest`.

## Sichtbare Fehler (20.08.2026) - Ergaenzung zur Systemzustand-Seite

- **Warum**: ein 500er trifft einen ECHTEN Nutzer und landet in
  `storage/logs/laravel.log` - einer Datei, die im Alltag niemand oeffnet.
  Der Betreiber erfuhr davon nur, wenn sich jemand beschwert hat.
- `ErrorRecorder::record()` haengt in `bootstrap/app.php` an
  `$exceptions->report()`; die normale Behandlung (Logdatei, Fehlerseite)
  laeuft danach UNVERAENDERT weiter. Die Logdatei bleibt die ausfuehrliche
  Quelle (Stacktrace), die Tabelle ist nur das SIGNAL "etwas ist kaputt".
- **Eine Zeile je FINGERABDRUCK** (`sha1(Klasse|Datei|Zeile)`), nicht je
  Auftreten: ein Fehler, der 5000-mal auftritt, ist EIN Problem - und die
  Tabelle bleibt klein.
- **NUR DEFEKTE.** 404, 403, Validierung, abgelaufenes CSRF-Token,
  ModelNotFound und Throttle sind normales Nutzerverhalten (Liste
  `ErrorRecorder::IGNORED`, dazu jede HTTP-Ausnahme < 500). Wuerden sie
  mitgezaehlt, ginge der eine echte Defekt zwischen tausend
  Rauscheintraegen unter - die Anzeige waere wertlos.
- **KEINE personenbezogenen Inhalte**: Klasse, gekuerzte Meldung (500
  Zeichen), Datei/Zeile, Routenname bzw. Pfad OHNE Query-String, Methode,
  Status, zuletzt betroffener Nutzer. NIE Formularfelder, Query-Parameter,
  Header, Cookies oder IP (Test sichert das ab).
- **Das Aufzeichnen darf nie selbst fehlschlagen** - jeder Fehler darin
  wird geschluckt, sonst wuerde aus einem Fehler ein zweiter und die
  Fehlerseite erreichte den Nutzer nie.
- Anzeige: Abschnitt "Fehler" auf `/admin/systemzustand` + Liste
  `/admin/fehler` (nur admin/manager) mit "Erledigt"/"Wieder oeffnen".
  Ein erneutes Auftreten OEFFNET einen erledigten Fehler wieder - behoben
  ist er erst, wenn er ausbleibt.
- `errors:prune` (taeglich 03:55) loescht nur ERLEDIGTE Eintraege aelter
  als 30 Tage. Ein offener Fehler bleibt stehen, egal wie alt: ein Problem
  verschwindet nicht durch Ignorieren.
- Tests: `ErrorVisibilityTest`.

## Batch-Laeufe: ein kaputter Datensatz stoppt nie den ganzen Lauf (19.08.2026)

- **Lehre**: die geplanten Aufgaben arbeiten Listen ab. Ohne Absicherung
  beendet die ERSTE Ausnahme den gesamten Lauf - ein Kunde mit kaputter
  Adresse verhinderte, dass ALLE weiteren ihre Erinnerung bekamen. Und weil
  das im Hintergrund passiert, merkte es niemand.
- Gemeinsamer Baustein `App\Console\Concerns\ProcessesRecordsSafely`:
  `verarbeiteEinzeln($records, $handler, $label)` faengt je Datensatz,
  protokolliert MIT Kennung (Log + Befehlsausgabe) und macht weiter;
  `ergebnisMitUebersprungenen()` liefert am Ende **Exitcode 1**.
  Reihenfolge ist Absicht: erst alles Machbare erledigen, DANN ehrlich
  melden. Der Exitcode macht die Aufgabe auf `/admin/systemzustand` rot -
  ein sichtbarer Teilausfall ist besser als ein stiller.
- Abgesichert: `documents:analyze-pending` und `ai:answer-pending` (die
  SICHERHEITSNETZE - ausgerechnet sie waren ungeschuetzt; beim ersten ist
  zusaetzlich der Rueckstau-Alarm gekapselt, damit die Meldung "Worker tot"
  nicht an etwas anderem scheitert), `health:apply-due-switches`,
  `tickets:auto-close`, `document-requests:remind`, `tasks:remind`.
- Die Erinnerungs-DIENSTE (`EscooterRenewalReminderService`,
  `SchutzbriefRenewalReminderService`, `ContractSwitchReminderService`)
  waren bereits je Vertrag abgesichert - dort wurde bewusst nichts
  geaendert.
- `reminder_sent_at` wird weiterhin erst NACH erfolgreichem Versand
  gesetzt: ein voruebergehender Mailfehler soll es morgen erneut versuchen.
  Eine dauerhaft kaputte Adresse meldet der Lauf dann taeglich - sichtbar,
  statt dass der Kunde still nie erinnert wird.
- Nebenbei behoben: `health:apply-due-switches` schrieb sein ActivityLog-
  `meta` vor-`json_encode`t, obwohl die Spalte als Array gecastet ist
  (doppelte Kodierung) - laeuft jetzt ueber `ActivityLog::record()`.
- Tests: `BatchResilienceTest`.

## Kein Web-Request wartet minutenlang auf einen fremden Dienst (20.08.2026)

- **Lexoffice** rief ohne ausdrueckliches Zeitlimit auf: Laravel wartet dann
  30 s je Versuch, mit `retry(2)` also bis zu 90 s. Drei Aufrufe (PDF
  rendern, Datei holen, Beleg hochladen) liefen ausserdem an `http()` vorbei
  und hatten gar keine Wiederholung. Jetzt eine gemeinsame Grundlage
  `baseHttp()` mit `timeout` (10 s) und `connectTimeout` (5 s),
  konfigurierbar ueber `LEXOFFICE_TIMEOUT`/`LEXOFFICE_CONNECT_TIMEOUT`. Die
  Aufrufer hatten bereits einen Fallback fuer "nicht erreichbar" - es fehlte
  nur die Bereitschaft, rechtzeitig aufzugeben.
- **Social-Sofortpost laeuft jetzt als Job** (`PublishSocialChannelJob`).
  Der Instagram-Weg (Container anlegen, bis zu 4x auf die Verarbeitung
  warten, veroeffentlichen, Permalink holen) dauert im schlechtesten Fall
  rund DREI MINUTEN - laenger als jede uebliche PHP-Laufzeitgrenze. Riss der
  Request dabei ab, stand der Beitrag womoeglich schon auf Instagram,
  waehrend die App nichts davon wusste: der naechste Klick postete ihn ein
  zweites Mal.
- **Neuer Marker `banner_social_channels.publish_started_at`** = "ein
  Versuch ist unterwegs". Der Controller beansprucht ihn ATOMAR
  (`UPDATE ... WHERE publish_started_at IS NULL ...`) - die bisherige
  Lesen-dann-Pruefen-Folge liess zwischen Klick und Post einen zweiten
  Klick durch. Der geplante Lauf respektiert den Marker ebenfalls.
- **Selbstheilend**: `PUBLISH_STALE_MINUTES` (15) gibt einen Kanal wieder
  frei, wenn der Worker mitten im Versand stirbt - lieber ein spaeterer
  zweiter Versuch als ein Beitrag, den niemand mehr anstossen kann. Dazu
  raeumt `failed()` den Marker ab.
- `tries = 1` wie beim geplanten Versand: ein Retry koennte einen bereits
  abgesetzten Beitrag doppelt veroeffentlichen. Ein erneuter Versuch bleibt
  eine bewusste Mitarbeiter-Aktion. Weil der Versand nicht mehr im Request
  passiert, meldet eine GLOCKE Erfolg oder Fehler; die Oberflaeche zeigt
  solange "Wird veröffentlicht …" statt des Knopfes.
- Tests: `ExternalServiceTimeoutTest`.

## Grosse Listen: nichts mehr unbegrenzt laden (20.08.2026)

- **Lehre**: `/admin/contracts` lud ALLE Vertraege und filterte sie per
  JavaScript ueber die fertigen Tabellenzeilen; `/admin/contracts/new`
  schrieb den KOMPLETTEN Kundenbestand als JSON ins HTML. Das funktioniert
  genau so lange, wie die Zahlen klein sind - danach waechst jeder
  Seitenaufruf linear mit dem Bestand, bis er in ein Speicher- oder
  Zeitlimit laeuft. Und es faellt erst auf, wenn es zu spaet ist.
- **Vertragsliste**: Gruppe UND Suche laufen jetzt in der DATENBANK,
  Ausgabe seitenweise (50). Die Gruppen-Definition bleibt die EINE Quelle -
  `Contract::scopeStatusGroup()` ist der Query-Spiegel von
  `statusGroup()`, Badge/Zaehler/Filter koennen sich nicht widersprechen.
  Die Reiter-Zaehler sind reine COUNT-Abfragen (keine Zeile wird geladen)
  und folgen der Suche, damit die Zahl zum Gezeigten passt. Reiter und
  Suche sind normale Links/GET-Formulare - Stand teilbar und
  zurueck-tauglich. `alle` ist eine bewusste Auswahl, Standard bleibt der
  aktive Bestand.
- **Neuer `Contract::scopeSearch`**: Gesellschaft, Vertragsnummer,
  Kundenname, Kundennummer; mehrere Woerter UND-verknuepft, `%`/`_`
  maskiert (Nutzereingabe erzeugt nie einen LIKE-Platzhalter).
- **Kundenauswahl im Vertragsformular**: Sofort-Suche gegen
  `admin.contract.customer_search` (JSON, portfolio-gescoped, max. 8) -
  derselbe Weg wie im Aufgaben- und E-Mail-Formular. Trefferliste wird per
  `textContent` gebaut, nicht per HTML-String: Kundennamen sind Fremddaten.
  Interne `@dienstly24.internal`-Platzhalter werden NIE als Kontakt
  ausgegeben.
- Tests: `LargeListPerformanceTest`; `ContractStatusLogicTest` Fall 5
  nachgezogen (Historie steht jetzt auf ihrem Reiter, nicht auf jeder
  Seite).
- **Teil 2 (20.08.2026), damit ist die Klasse abgeschlossen**:
  `AdminController::mergeForm` lud den GANZEN Bestand in ein `<select>` -
  jetzt dieselbe Sofort-Suche; der serverseitig ermittelte Vorschlag bleibt
  (er ist der Zweck der Seite). `ImportExportController::export` wird
  GESTREAMT (`streamDownload` + `chunkById(500)`): vorher lagen erst alle
  Kunden als Modelle und dann die komplette CSV als String im Speicher -
  der Bedarf wuchs doppelt. Der Audit-Eintrag entsteht VOR dem Streamen
  (er darf nicht davon abhaengen, dass der Download sauber endet).
  `EmailInboxController::index` (Vorschlaege, 100) und
  `ReportController::index` (bald ablaufend, 50) sind gedeckelt - beide
  nennen die GESAMTZAHL und sagen ausdruecklich, dass gekuerzt wurde:
  eine still gekuerzte Liste laesst den Eingang faelschlich abgearbeitet
  aussehen.
- **Eine Kundensuche fuer beide Formulare**: `admin.customers.search`
  (`AdminController::customerSearch`, Pfad `/admin/kunden-suche` - bewusst
  nicht unter `/contracts/...`, damit keine Route-Reihenfolge ihn als
  Vertrags-ID missdeutet). `exclude` blendet einen Kunden aus (beim
  Zusammenfuehren darf der Hauptkunde nicht sein eigenes Duplikat sein).
  Die Endpunkte in `TaskController` und `ComposeEmailController` bleiben
  eigenstaendig - sie liefern einen reicheren Datensatz (Betreuer usw.).

## Aufraeumen und Betriebswahl (20.08.2026)

- **Toter Workflow-Engine-Bestand entfernt**: Definitionen, Laeufe,
  Schritte, Prompts und `ai_action_logs` (5 Tabellen), Engine, Installer,
  Step-Handler, `workflow:install` und die zugehoerigen Tests. Nachweis:
  KEINE Route, KEIN Controller, KEINE Oberflaeche hat je einen Lauf
  gestartet - geschrieben wurde ausschliesslich aus der Engine selbst und
  ihren Tests. Toter Code ist nicht neutral: er sieht wie ein Feature aus
  und zwingt jeden, der die Kundenakte erweitert, zur Pruefung "redet das
  hier mit?" - Antwort immer nein.
  **NICHT betroffen, nur aehnlich benannt und in Betrieb**:
  `EmailWorkflowService` (E-Mail-Eingang), `CommissionWorkflowService`,
  `EmailClassificationService`, `SystemUserResolver`.
  Die urspruengliche Erstellungs-Migration bleibt liegen (nie eine bereits
  gelaufene Migration loeschen), eine neue Migration wirft die Tabellen weg.
- **Redis ist eine Server-Entscheidung, keine Code-Aenderung**: die App ist
  fertig verdrahtet (`REDIS_CLIENT=phpredis`, kein Composer-Paket noetig).
  Die Systemzustand-Seite zeigt jetzt die Treiber fuer Sitzungen, Cache und
  Warteschlange und macht bei eingestelltem Redis einen ECHTEN PING - ein
  Umzug, der still auf die Datenbank zurueckfaellt, waere sonst monatelang
  unbemerkt. Anleitung: `docs/ANLEITUNG_REDIS_AR.md` (inkl. der zwei
  Fallen: wartende Jobs wandern NICHT mit, und der Worker muss neu
  gestartet werden).
- **CSP**: `fonts.bunny.net` ist raus - die Schriften liegen laengst lokal.
  Ein Fremdhost, der nicht mehr gebraucht wird, gehoert nicht in die
  Freigabe. `unsafe-inline`/`unsafe-eval` bleiben vorerst (grosse
  Blade-Flaeche mit Inline-Styles und `onclick`; `unsafe-eval` haengt an
  Alpine.js).

## Zeitzone: gespeichert UTC, GEZEIGT deutsche Ortszeit (21.08.2026)

- **Lehre**: `app.timezone` steht auf UTC, der Betrieb sitzt in Deutschland.
  Jede Anzeige war damit im Sommer ZWEI STUNDEN zu frueh - inklusive des
  DSGVO-Einwilligungszeitpunkts auf dem Ticket. Zwei Stunden Abweichung
  sehen plausibel aus, deshalb faellt es niemandem auf.
- **`app.timezone` bleibt UTC.** Wuerde man sie auf Europe/Berlin stellen,
  schriebe Laravel neue Zeitstempel in Ortszeit: der Altbestand laege in
  UTC, alles Neue in Ortszeit - und man saehe einer Zeile NICHT an, welche
  von beiden sie ist. Ein solcher Mischbestand ist hinterher nicht mehr
  sauber zu reparieren. Ein Test sichert `app.timezone === 'UTC'` ab.
- **Umgerechnet wird bei der AUSGABE**: `Carbon::lokal()` (Makro im
  `AppServiceProvider`) plus `App\Support\LocalTime`; Zone aus
  `app.display_timezone` (`APP_DISPLAY_TIMEZONE`, Standard Europe/Berlin).
  Echte Zeitzone statt festem Offset - ein Offset waere zweimal im Jahr
  falsch.
- **NICHT im Model-Cast umrechnen.** Dann traegt auch jeder Wert, der in
  eine WHERE-Bedingung geht, Ortszeit und wird gegen eine UTC-Spalte
  verglichen - aus einem sichtbaren Anzeigefehler wuerde ein STILLER
  Abfragefehler. Die Umrechnung gehoert an die Oberflaeche, nicht in die
  Daten.
- **Auch reine Datums-Anzeigen von Zeitpunkt-Spalten** (`..._at`) werden
  umgerechnet: um 23:30 UTC ist in Deutschland schon der naechste Tag - sonst
  stuende der falsche TAG da. Reine Datums-Spalten (`start_date`,
  `birth_date`, `deadline` ...) bleiben unberuehrt: sie tragen keine Uhrzeit
  und damit keine Zeitzone.
- **Bewusst NICHT umgestellt**: `datetime-local`-Formularwerte (sie rechnen
  laengst selbst auf `OPERATOR_TZ` um und gehen zurueck an den Server),
  `diffForHumans()` (eine Differenz ist zeitzonenunabhaengig) sowie
  Gruppierungs-Schluessel fuer Diagramme (`TicketController`,
  `ProvisionController`: `format('Y-m-d')` als Achsen-Schluessel) und
  `SeoController` (sitemap lastmod) - dort muss das Format zur uebrigen
  Berechnung passen, nicht zur Anzeige.
- **Zwei Waechter-Tests** verhindern den Rueckfall: keine View darf eine
  Uhrzeit ohne `->lokal()` ausgeben, und keine `..._at`-Spalte darf ohne
  `->lokal()` formatiert werden. Ohne sie waere die naechste neue View
  wieder in UTC.
- Tests: `DisplayTimezoneTest`.

## Kundennummern

- Neuanlage: `JJ` + 5-stellig laufend (2026 → `2600001`, `2600002` …) via
  `CustomerNumberGenerator::generate()`.
- Import aus Fremdplattform: `25` + Original-Nummer via
  `generateForImport($original)`. Alt-Nummern (`C-…`) bleiben gültig.

## Wichtige Bausteine

- **E-Mails** (`resources/views/emails/`): tabellenbasiert, Inline-Styles,
  **kein SVG** (Gmail/Outlook entfernen es → Emoji nutzen). Bilder als
  CID-Inline via `{{ isset($message) ? $message->embed(public_path(...)) : url(...) }}`.
- **Willkommens-Mail** = `CustomerWelcomeMail` + `customer_welcome.blade.php`
  (kompakt, ein Bildschirm). Enthält Magic-Login (90 Tage) und Hilfe-Button.
- **Portal-Einladung & Startpasswort** (`PortalAccessService`, Lehre
  07.08.2026): Startpasswort = GEBURTSDATUM (TT.MM.JJJJ). OHNE Geburtsdatum
  faellt der Versand auf einen zeitlich begrenzten Passwort-Setzen-Link
  zurueck (`setlink`) - viele Kunden aktivieren so nie. Deshalb warnt das
  System ueberall, wo eingeladen wird, solange das Geburtsdatum fehlt
  (Kundenakte-Box + Confirm, Einladungs-/Reset-Flash als `warning`,
  Neuanlage, E-Mail-Nachtrag, Batch-Bericht `portal:send-invitations`):
  zuerst Geburtsdatum ergaenzen, dann einladen. `sendInvitation()` liefert
  den Modus zurueck. Anzeige EHRLICH halten: `portal_password_set_at` setzt
  das SYSTEM beim Versand (Zeile "Passwort eingerichtet", Badge "Zugang
  eingerichtet - noch kein Login") - aktiv ist ein Kunde erst ab "Erster
  Login". Tests: `PortalAccountManagementTest`.
- **Hilfe-Formular**: `SupportFormController` → `/hilfe`. Aus der Mail mit
  verschlüsseltem Kunden-Token vorbefüllt; Absenden legt automatisch ein
  Ticket an, verknüpft mit der Kundenakte.
- **Marketing-Website IM PORTAL (Merge, Betreiber-Auftrag 30.07.2026)**:
  `www.dienstly24.de` wird von DIESER App ausgeliefert (`WebsiteController`,
  Views `resources/views/website/`, Assets `public/website-assets/` -
  NIE `public/website/`, das verschattet die Vorschau-Route `/website`).
  `config/website.php` = kanonischer Host, Redirect-Hosts, Kontaktdaten,
  Bild-Slots. `RedirectWebsiteHost` leitet non-www/.com/http per 301 um;
  `/` zeigt auf Website-Hosts die Startseite (sonst Portal-Verhalten).
  ECHTE arabische URLs unter `/ar/...` (`forceLocale:ar`, Hocharabisch,
  hreflang de/ar/x-default); Rechtsseiten aus der alten statischen Site
  portiert (`website/legal/`, auf Website-Hosts immer lokal - nie extern
  umleiten, Schleifengefahr). REGEL: Website-Seiten laden NIE externe
  Ressourcen (Google Fonts -> Abmahnung; Schriften liegen lokal in
  `public/fonts/`, Subsets je Sprache). robots.txt/sitemap.xml dynamisch
  (`SeoController`: NUR der kanonische Host offen; portal/admin UND
  Staging-/Extra-Hosts disallow + noindex). Schutzschichten
  (`ExtraBasicAuth`, Betreiber-Bedingung): `ADMIN_BASIC_AUTH=user:pass`
  = zweite Auth-Schicht vor /admin (Pflicht bis 2FA existiert);
  `STAGING_HOSTS`+`STAGING_BASIC_AUTH` (+`WEBSITE_EXTRA_HOSTS`) =
  passwortgeschuetzte Vorschau-Domain. Cutover-Plan (TTL 300s, dienstags
  vormittags, Rollback, statisches Hosting nach 1 Woche abschalten):
  `docs/WEBSITE_MERGE_UMSETZUNG.md`; Backup+Restore-Test: `scripts/backup.sh`.
  Formular `POST /kontakt` -> Ticket (source=website) + Einwilligungs-
  Protokoll (`consent_given_at/ip/text` auf tickets) + Bestaetigungs-Mail
  (`WebsiteInquiryConfirmationMail`, DE/AR) + `/kontakt/danke`;
  unkonvertierte Website-Leads loescht `tickets:purge-website-leads` nach
  6 Monaten. Fehlerseiten 404/500 zweisprachig im Markendesign.
  Go-Live-Schritte (DNS/vHost/Cloudflare/APP_URL): `docs/WEBSITE_MERGE_UMSETZUNG.md`;
  arabische Betreiber-Anleitung: `docs/ANLEITUNG_MEDIEN_UND_ANFRAGEN_AR.md`.
  Tests: `WebsiteMergeTest`.
- **Medienverwaltung `/admin/medien`** (`MediaLibraryController`,
  `MediaAsset`): Bild hochladen -> Slot waehlen (feste Plaetze aus
  `config/website.php`) -> Alt-Texte DE+AR (PFLICHT) -> sofort live.
  Automatisch AVIF/WebP/JPG in 480/960/1600px, jede Variante < 200 KB,
  EXIF weg (`ImageVariantGenerator`); echte MIME-Pruefung + SVG-Sanitizer;
  Original privat (`media-originals/`), nur Varianten oeffentlich, URLs
  IMMER relativ `/storage/...` (P0-6: nie APP_URL/IP-abhaengig, gleiche
  Regel in `ServicePage::imageUrl()`). Slot exklusiv - Vorgaenger wandert
  ins Archiv, nie geloescht; Papierkorb 30 Tage (`media:purge-trash`).
  Loeschen nur admin/manager, Upload alle Staff-Rollen. MARKEN-SLOTS
  (`logo-hell`, `logo-dunkel`, `logo-symbol-hell`, `favicon`, Flag
  `admin_only`): ueberschreiben die Dateien aus `public/images` in der
  GESAMTEN App (Website, Portal, Beraterwelt, Login) ueber
  `App\Support\BrandAssets` - nie wieder Logo-Dateien per FTP tauschen;
  ohne zugewiesenes Bild bleibt der generierte Bestand. Transparenz:
  erkennt der Generator einen Alphakanal, ist die Fallback-Variante PNG
  statt JPG (sonst weisser Kasten hinter der Wortmarke). WICHTIG:
  `MediaAsset::forSlot()` cacht ROHE Spaltenwerte (ein Eintrag, dann
  `newFromBuilder`) - Eloquent-Objekte im Cache kommen aus
  database/file/redis als `__PHP_Incomplete_Class` zurueck (500 auf
  jeder Seite). Reparatur-Befehle:
  `service-pages:fix-umlauts --write` (P0-7, `UmlautRepair`-Wortliste +
  Warnung beim Speichern im Admin) und `website:fix-storage-urls --write`.
  Tests: `MediaLibraryTest`.
- **Website-Kontaktformular der STATISCHEN Uebergangs-Site**
  (`website/index.html`, bis der DNS-Umzug durch ist): sendet per JS an
  `POST /api/website-contact` (`WebsiteContactController`) statt per Mail.
  Mehrstufiger Spam-Schutz: JS-Einmal-Token mit Mindest-Ausfuellzeit,
  Honeypot, Throttle, `SpamFilter` (Spam wird still verworfen). Echte
  Anfragen werden Tickets (Quelle `website`) + Glocke + Support-Mail.
  Details/Inbetriebnahme: `docs/SPAM_SCHUTZ_WEBSITE_ANFRAGE.md`;
  Upload-Hinweise (inkl. `.htaccess`, zip loeschen): `website/LIESMICH.txt`.
- **Rechtsseiten** (`/impressum`, `/agb`, `/datenschutz`,
  `/cookie-richtlinie`, `/kontakt`): leiten standardmäßig auf die offizielle
  Website weiter (`LegalPageController`, Basis-URL unter Einstellungen →
  Rechtliches). Feld leeren = Portal zeigt eigene Fallback-Seiten.
- **Login/Registrierung** (`resources/views/auth/`): Single-Screen (kein
  Scroll), Glas-Karte, `logo-white.png` ohne weißen Kasten, DE/AR-Umschalter.
- **Arabisch/RTL**: `lang/ar.json`, `SetLocale`-Middleware,
  `dir="rtl"`-Layout. Neue UI-Strings mit `__()` wrappen und in `ar.json`
  ergänzen.
- **Banner-Verwaltung**: `BannerController`, Statistik-Dashboard unter
  `/admin/banners/statistik`. Routen auf `role:admin,manager` beschränkt.
  **Social-Publishing (Phase 1, Betreiber-Auftrag 04.08.2026)**: je Banner
  die Seite `/admin/banners/{id}/social` (`BannerSocialController`) mit
  Beitragstext DE/AR, oeffentlichem https-Klick-Ziel und den drei
  tatsaechlich genutzten Plattformen Facebook/Instagram/TikTok
  (`BannerSocialPost::PLATFORMS`). `SocialFormatGenerator` erzeugt aus dem
  Banner-Bild JPGs 1080x1080 (Feed), 1080x1920 (Story/Reel), 1200x630
  (Link-Vorschau): Seitenverhaeltnis nahe am Ziel -> mittiger Zuschnitt,
  sonst KOMPLETT eingepasst auf Gruen-Graphit `#131A17` (breite
  Text-Banner nie zerschneiden); Videos werden nicht umgerechnet (kein
  ffmpeg), GIF nutzt das 1. Bild - Original liegt dem ZIP-Paket bei
  (Formate + Texte + Links, `ZipArchive` mit class_exists-Guard). Je
  Plattform ein Tracking-Kurzlink `/s/{code}` (`SocialLinkController`,
  oeffentlich, throttle): zaehlt den Klick, haengt utm_source/medium/
  campaign an (Fragment-sicher) - Kurzlinks NIE an `Banner::current()`
  koppeln, veroeffentlichte Beitraege bleiben dauerhaft klickbar.
  Social-Klicks sind bewusst GETRENNT von den Portal-Klicks (eigene Karte
  im Statistik-Dashboard, keine gemeinsame CTR). Veroeffentlichung ist in
  Phase 1 eine Mitarbeiter-Aktion: "Als veroeffentlicht markieren"
  protokolliert wer/wann, optionale Wiedervorlage erinnert (faellig am
  Startdatum). Abwaehlen einer Plattform loescht ihren Kanal samt
  Klickzahlen (Hinweis steht im Formular).
  **Phase 2 (Meta Graph API, 04.08.2026)**: `MetaPublisher` postet direkt
  auf die EIGENE FB-Seite (Foto-Beitrag; Video-Banner -> Link-Beitrag)
  und IG-Business (Container-Flow media -> media_publish mit
  status_code-Polling; braucht eine OEFFENTLICH abrufbare Bild-URL ->
  APP_URL muss die echte Domain sein). WICHTIG (Pre-Merge-Review):
  Seiten-Posts/-Insights verlangen das PAGE Access Token
  (`META_PAGE_ACCESS_TOKEN`, holt der Assistent aus /me/accounts;
  Fallback: `MetaGraphClient::pageToken()` leitet es zur Laufzeit ab) -
  das System-User-Token allein reicht NUR fuer IG- und act_...-Endpunkte.
  Tokens gehen IMMER als Bearer-Header raus (nie Query/Body - sonst
  Token in Fehlermeldungen bzw. kaputtes DELETE). Zeitplanung:
  `scheduled_for` wird als DEUTSCHE Zeit erfasst
  (`BannerSocialPost::OPERATOR_TZ` = Europe/Berlin), in UTC gespeichert
  (app.timezone!) und zur Anzeige zurueckgerechnet; Vergangenheit wird
  abgelehnt. Abwaehlen einer Plattform loescht NIE veroeffentlichte
  Kanaele (Kurzlink steht im Live-Beitrag).
  Beitragstext = DE + AR + Tracking-Link; zu langer IG-Text (> 2200) wird
  ABGELEHNT, nie still gekuerzt. Konfiguration `config/services.php`
  'meta' aus `META_PAGE_ID`/`META_IG_USER_ID`/`META_ACCESS_TOKEN`
  (System-User-Token, laeuft nicht ab, NUR Server-`.env`; kein App-Review
  noetig - eigene Assets, Standard Access; arabische Einrichtungs-
  Anleitung: `docs/ANLEITUNG_META_API_AR.md`). Einrichtung fuer den
  Betreiber per Assistent `php artisan meta:einrichten` (fragt NUR das
  Token ab, findet Seite/IG-Konto selbst via /me/accounts, testet die
  Verbindung, schreibt die .env via `EnvFileWriter`; `--pruefen` =
  reiner Verbindungstest) - Token NIE durch den Chat schicken lassen.
  Sofort-Posten per Button
  „Jetzt per API posten"; geplanter Versand ueber `scheduled_for` +
  Command `social:publish-scheduled` (alle 15 Min): genau EIN
  Auto-Versuch je Kanal (`auto_attempted_at` wird VOR dem API-Aufruf
  gesetzt - nie-doppelt-posten schlaegt Retry), Fehler stehen als
  `publish_error` am Kanal + Glocke an den Ersteller, erneuter Versuch
  ist eine bewusste Mitarbeiter-Aktion (Button „Erneut versuchen" bzw.
  neuer Zeitplan setzt den Versuch zurueck). Manuell als veroeffentlicht
  markierte Kanaele und vorhandene `external_post_id` werden NIE
  angefasst; TikTok bewusst ohne API (App-Audit) - nur manuell.
  **Phase 3 (Vollsteuerung ohne Meta zu oeffnen, 04.08.2026)**:
  `MetaGraphClient` (gemeinsamer Graph-Client), `MetaInsightsService`
  (Kennzahlen je Beitrag: Likes/Kommentare/Shares/Reichweite als
  `channels.insights`; Seiten-Ueberblick Follower/Aufrufe im Cache -
  Dashboard liest NUR Cache, nie live-API; Refresh alle 6 h via
  `social:refresh-insights` + Button) und `MetaAdsService`/`/admin/werbung`
  (Marketing API: Kampagnen-Liste mit Ausgaben/Klicks/CPC,
  Start/Pause/Budget/Loeschen, "Banner bewerben" erstellt
  Kampagne+Adset+Creative+Ad aus dem veroeffentlichten FB-Beitrag,
  object_story_id, automatische Platzierungen FB+IG, Sprach-Targeting
  DE/AR via adlocale-Suche - IDs NIE raten). GELD-REGELN: jede neue
  Anzeige entsteht PAUSED (Start = bewusster Klick), Tagesbudget hart
  gedeckelt: Schutzgrenze aendert der ADMIN in der Oberflaeche
  (SystemSetting `meta_ads_max_daily_budget`, Karte unten auf
  /admin/werbung, absolute Obergrenze 10000; Fallback .env
  `META_ADS_MAX_DAILY_BUDGET`, Default 100 EUR; Validierung in
  Controller UND Service), Budgets in EUR angezeigt und erst im
  Service in Cent umgerechnet (Marketing API = Minor Units!),
  halbfertige Kampagnen werden bei Fehlern aufgeraeumt, JEDE Aktion im
  ActivityLog (`meta_ad_*`). `META_AD_ACCOUNT_ID` findet der Assistent
  automatisch (me/adaccounts). Zahlungsmittel sind API-seitig NICHT
  pflegbar (einziger Schritt, der bei Meta bleibt - steht so in der
  Anleitung). Tests: `MetaAdsManagementTest`.
- **Gesundheitskarten einer FAMILIE auf EINER Aufnahme** (Betreiber-Vorgabe
  05.08.2026): `GesundheitskarteParser` liest BEIDE Seiten und MEHRERE Karten
  je Bild (Bloecke ueber die Karten-Ueberschriften; Versalien-Nachnamen werden
  normalisiert, Vorder-/Rueckseite derselben Karte ueber die
  Versichertennummer zusammengefuehrt). Eine Karte zaehlt nur mit Name UND
  Versichertennummer im SELBEN Block - sonst lieber eine Karte weniger als
  eine Fehlzuordnung. Die Traeger-Kennnummer (z.B. 104491707) ist die
  Institutionsnummer der KASSE (bei allen Versicherten gleich) und wird NIE
  als Versichertennummer uebernommen; nur "1 Buchstabe + 9 Ziffern" zaehlt.
  Weitere Karten stehen in `personen`; Button „👪 N Kunden anlegen" im Eingang
  (`SmartDocumentUploadController::createCustomersFromPersons`) legt je Person
  einen Kunden an (bereits erfasste werden gemeldet und uebersprungen),
  schreibt die Kassendaten personenbezogen und verknuepft nur Personen mit
  gleichem Familiennamen (`linkSameFamilyName`). Tests:
  `GesundheitskartenFamilieTest`.
- **Meldebestaetigung + Haushalt** (`MeldebestaetigungParser`,
  `DocumentIntakeService::linkMeldebestaetigungHousehold`, Betreiber-Vorgabe
  04.08.2026): Zwei Bauformen werden gelesen - "Familienname: Najm" UND das
  Spaltenlayout ohne Doppelpunkt (Stadt Backnang, Beschriftung mit
  Klammer-Zusatz "Vorname(n)"); die Ueberschrift steht oft GESPERRT
  ("M e l d e b e s t ä t i g u n g") - die Typ-Erkennung laeuft daher auf
  einer Textfassung ohne jeden Zwischenraum. Neue Anschrift + Einzugs-/
  Anmeldedatum stehen in der Zusammenfassung. Ist die Person MINDERJAEHRIG,
  wird sie automatisch mit den erfassten ERWACHSENEN derselben Anschrift
  verknuepft (`CustomerRelationship` type 'family'); Familienname
  transkriptions-tolerant ueber ein Konsonanten-Skelett ("Najm" = "Al-Najm" =
  "Najim"). Bewusst NICHT behauptet: wer Vater/Mutter ist - die
  Meldebestaetigung belegt nur den Haushalt. Tests:
  `MeldebestaetigungParserTest`, `MeldebestaetigungHaushaltTest`.
- **Auftrags-Uebersicht aus dem VERTRIEBSPORTAL als Screenshot**
  (`EnergiePortalAuftragParser`, 16.08.2026): der Betrieb arbeitet Energie-
  Auftraege im Portal ab und laedt die Uebersichtsseite als BILD hoch
  (z.B. RheinEnergie "Fair Ökostrom 24"). LEHRE aus dem echten Lauf: die
  OCR erhaelt das Spaltenraster NICHT - die drei Spalten stehen mit nur
  EINEM Leerzeichen in derselben Zeile ("Produkt Fair Ökostrom 24 IBAN:
  DE82...", "Herr Max Muster Herr Max Muster"). Deshalb NIE auf
  Spaltenabstaende verlassen, sondern auf das BESCHRIFTUNGS-VOKABULAR
  (`KNOWN_LABELS`): die Beschriftung darf mitten in der Zeile stehen, ihr
  Wert endet an der naechsten bekannten Beschriftung bzw. an einer PLZ der
  Nachbarspalte; doppelt gesetzte Texte ("X X") werden zusammengefasst und
  die zweite Anrede laeuft nie in den Namen. Der Kontoinhaber wird ueber
  ALLE Namen unter seiner Ueberschrift geprueft (fremder Name -> keine
  Bankuebernahme). Adresse nur aus einem VOLLSTAENDIGEN Block (Strasse UND
  PLZ/Ort) mit Strassen-Plausibilitaet, sonst wuerde die Reiterleiste
  ("Übersicht Dokumente 1 Anfrage zum Vertrag") zur Anschrift. Der Grundpreis steht je JAHR, die
  Kundenakte fuehrt ihn je MONAT (`base_price` = EUR/Monat!) - deterministisch
  /12 umgerechnet, BEIDE Werte stehen in der Zusammenfassung. Stufe
  `antrag`, Auftragsnummer NIE als Vertragsnummer; "schnellstmoeglich" ist
  kein Datum -> beim Stadtwerke-Vorversorger greift die 20-Tage-Regel.
  Anbieter + Produkt kommen bevorzugt aus der grossen KOPFZEILE
  ("1672525 - RheinEnergie AG - Fair Ökostrom 24") - die kleine Tarif-Tabelle
  liest die OCR gern verstuemmelt ("Fair ö 24", "Tarityp"); die Sparte
  entscheidet dann der Produktname. Die IBAN wird per PRUEFZIFFER (Mod 97)
  validiert und gegen die separat gedruckte Konto-/BLZ-Angabe geprueft:
  eine kaputte IBAN wird NIE uebernommen, eine Abweichung steht als Hinweis
  in der Zusammenfassung. Tests: `EnergiePortalAuftragParserTest`.
  OCR-VORSTUFE (gleiche Lehre, mit Chromium-Replik + Tesseract nachgestellt):
  kleine Bilder (< `OCR_UPSCALE_BELOW_PX`, Default 2600 px Kantenlaenge)
  werden in `TesseractTextExtractor` vor der Erkennung VERDOPPELT -
  Screenshots kommen mit ~150 dpi, sonst verwechselt Tesseract aehnliche
  Zeichen ("NOLADE21RDB" -> "NOLADE2IRDB", "Tariftyp" -> "Tarityp").
- **Strom-/Gas-AUFTRAEGE (Formularseite)**: Ein Auftrag hat **KEINE
  Vertragsnummer** (Betreiber-Vorgabe 02.08.2026) - die Auftragsnummer steht
  nur in der Zusammenfassung, nie in `contract_number` (falsche Angabe in der
  Kundenakte). Die spaetere Vertragsbestaetigung bringt die echte Nummer und
  findet ihren Vertrag ueber MaLo-ID/Zaehlernummer. Parser:
  `LichtblickAuftragParser` (Werte UEBER der Beschriftung) und
  `PlanBNetZeroAuftragParser` (Beschriftung/Wert NEBENeinander, zweimal je
  Zeile; leeres Feld -> naechste Zelle ist selbst eine Beschriftung -> bleibt
  leer). BRUTTO-Preise uebernehmen. IBAN aus dem SEPA-Mandat nur, wenn der
  KONTOINHABER der Antragsteller ist (sonst landet das Versorger- oder ein
  Fremdkonto in der Akte). Kein geschaetzter Lieferbeginn ausser beim
  Stadtwerke-Wechsel (14 Tage Frist -> 20 Tage).
- **Internet-/DSL-AUFTRAG (CHECK24, z.B. Vodafone Kabel)** (`DslAuftragParser`,
  Vollausbau 10.08.2026): der Auftrag steht KOMPLETT in der Vertragsakte
  (Sparte `internet`, Stufe `antrag`, Detailtabelle
  `contract_internet_details`): Anbieter = die FIRMA (z.B. "Vodafone Kabel
  Deutschland") - NIE der Tarifname; Label-Regexe bleiben per \h auf ihrer
  Zeile, sonst frisst sich der Ausdruck ueber die Ueberschrift "Ihr Tarif"
  in die Folgezeile (genau so entstand die Fehlzuordnung). Gelesen werden
  Tarif, Download/Upload, Grundgebuehr-Stufen (Aktionspreis Monat 1-N ->
  danach regulaer), einmalige Kosten (`setup_fee` Bereitstellung,
  `shipping_fee` Versand), `min_duration_months` (beim Auftrag gibt es
  keinen Beginn - "schnellstmoeglich" wird nie geraten, nur ein ECHTES
  Anschlusstermin-Datum wird start_date), Router inkl. "Vodafone Station"
  (Aufpreis = hoechster Betrag der Router-Zeilen; nie generisch "Router" -
  "Routergutschrift" ist ein Abzug) sowie Bonus/Cashback + Gutschrift.
  Beitrag = "Durchschnitt pro Monat", ersatzweise der regulaere Preis.
  OHNE eigenes Feld und deshalb NUR in der Zusammenfassung:
  Kuendigungsfrist/Verlaengerung, "Kosten ab Monat 25", 0,00-Inklusiv-
  Optionen (TV, Flatrate), Anschlusstermin. Maskierte IBAN/Kreditinstitut
  werden NIE Bankdaten. Anzeige ueberall: Kundenakte-Vertragszeile,
  Portal-Vertragsseite, Review-Modal im Eingang, Vertragsformular.
  SPALTEN-OCR (Lehre 10.08.2026, mit Chromium+Tesseract nachgestellt):
  Tesseract (PSM 3) liest die CHECK24-Karten eines SCREENSHOTS als
  Spalten-Bloecke - erst alle Beschriftungen, dann alle Werte, dann die
  Betraege; kein Label trifft seinen Wert auf einer Zeile (es blieben nur
  Name+Adresse uebrig). `pairColumnLayout()` rekonstruiert die Paare
  KONSERVATIV (MBit/s-Werte als Anker der Tarif-Karte; Preisliste nur bei
  EXAKT gleicher Anzahl Positionsnamen/Betraege; selbst-identifizierende
  Werte Geburtsdatum/Handynummer, Zukunfts-Datum nie Geburtsdatum) und
  haengt sie als synthetische "Label  Wert"-Zeilen an - danach greifen die
  normalen Regexe. Findet der Parser KEINEN Vertragskern, gibt er null
  zurueck, damit die KI-Eskalation das Bild vollstaendig liest, statt mit
  einer fast leeren Akte zu "gewinnen". Zeilen-Regexe (Anbieter/Tarif/
  Durchschnitt/Auftragsnummer) bleiben per \h auf ihrer Zeile - sonst
  frisst das blosse Label die Folgezeile bzw. den ersten Listen-Betrag.
  Tests: `DslAuftragParserTest`, `InternetContractExtractionTest`.
- **Kfz-ANTRAG aus der NAFI-Maklersoftware** (`NafiKfzAntragParser`,
  06.08.2026): ueber ALLE Gesellschaften gleich aufgebaut (Itzehoer, VHV …) -
  der Versicherer steht als Feld im Dokument. Liest Person (inkl.
  Familienstand/Staatsangehoerigkeit/Status), Tarif, Beginn/Hauptfaelligkeit,
  Gesamtbeitrag + Zahlungsperiode, Fahrzeug (Kennzeichen „RD - AS 1212" wird
  normalisiert, FIN, Wagnisart, HSN+Hersteller, Leistung, Kraftstoff,
  Erstzulassung, Zulassung auf Halter, SF, Fahrleistung, Kilometerstand) und
  Zusatzleistungen. Deckung kommt aus dem FELD „Gewuenschte Kaskoart", nie aus
  Stichwoertern. IBAN/BIC nur, wenn „Zahlungspflichtige Person" der
  Versicherungsnehmer ist. Stufe `antrag`: NAFI-Vorgangs-ID und eVB-Nummer
  sind KEINE Vertragsnummern (stehen nur in der Zusammenfassung).
- **Kfz-Versicherungsschein der WGV** (`WgvKfzPoliceParser`, 05.08.2026):
  kommt als HANDYFOTO - die Feldsuche laesst Doppelpunkt, Spaltenabstand UND
  einfaches Leerzeichen zu und schaut notfalls in die Folgezeile (gleiche
  Lehre wie bei der Meldebestaetigung). Liest Schein-/Kundennummer, Tarif,
  Laufzeit, den WIEDERKEHRENDEN Folgebeitrag (nicht den Jahresbeitrag),
  Fahrzeug inkl. FIN/HSN/TSN/Leistung/Erstzulassung/Zulassung auf Halter/
  SF-Klasse/Jahresfahrleistung/Kilometerstand sowie Person + Geburtsdatum.
  Deckung NUR aus dem Abschnitt „Versicherungsumfang" - der Rechtstext der
  Beitragsseite nennt „Kaskoversicherung" bloss beispielhaft. KEINE Bankdaten:
  die Kunden-IBAN ist maskiert, die vollstaendige gehoert der WGV. Neue
  Fahrzeugfelder `acquisition_date`, `initial_mileage`, `vehicle_type` sind
  jetzt extrahierbar (Validierung, Vertragsanlage, Version History).
- **Kfz-Angebot der Sparkassen DirektVersicherung**
  (`SparkasseDirektKfzParser`, 31.07.2026): Spaltenlayout (Beschriftung links,
  Wert rechts), Stufe `antrag`. Bewusst NICHT übernommen: die Empfehlung
  „FahrerSchutzPlus" (nicht gewählt, verfälscht sonst den Beitrag), die
  Service-Adressen des Versicherers und monatsgenaue Angaben
  (Erstzulassung „01.2004") - ein Tag wäre erfunden, die Angabe steht dafür in
  der Zusammenfassung. `power_kw` ist jetzt ein extrahierbares Fahrzeugfeld
  (Validierung, Vertragsanlage, Version History, KI-Prompt Feld P.2).
- **Antrag aus dem Online-Vergleichsportal des Maklerbundes** (Mr-Money /
  www.online-protokoll.de, 09.08.2026): `OnlineProtokollAntragParser` liest
  den Antrag (z.B. "Antrag Rechtsschutzversicherung", BavariaDirekt-OERAG)
  gratis: Sparte aus dem TITEL (unbekannte Sparte bleibt bewusst leer),
  Anbieter/Tarif, Beginn NUR als echtes Datum ("schnellstmoeglich" der
  Beratungsdoku wird nie geraten), "Beitrag gemaess Zahlweise" = BRUTTO.
  Ein Antrag traegt KEINE Vertragsnummer (Stufe `antrag`) - die
  Protokoll-Nr. steht nur in der Zusammenfassung, die spaetere Police
  ergaenzt denselben Vertrag (findApplicationContractForConfirmation).
  Klein/GROSS getippte Namen werden normalisiert ("kadro" -> "Kadro");
  IBAN nur ohne eingetragenen abweichenden Kontoinhaber; die
  Vermittler-Daten (Mr-Money, post@makler-bund.de) werden NIE Kundendaten.
  Tests: `OnlineProtokollAntragParserTest`.
- **Gewerbliche Sparten** (Betreiber-Vorgabe 30.07.2026): `betriebshaftpflicht`
  und `frachtfuehrerhaftpflicht` sind EIGENE Sparten in `Contract::TYPES`
  (Flag `'gewerblich' => true`, Gruppe „Gewerblich" im Vertragsformular,
  `Contract::isCommercial()`/`commercialTypeKeys()`) - sie versichern den
  BETRIEB, nicht die Privatperson, und dürfen nicht in der privaten
  Sammelsparte `haftpflicht` landen. Die Fonds-Finanz-Beratungsdokumentation
  liest sie gratis (`GewerbeBeratungsdokumentationParser`, Sparte aus dem Kopf
  „Vermittlungsauftrags: …"; „Verkehrshaftungsversicherung" = Frachtführer).
  Das Schwesterdokument **„Deckungsauftrag zur <Sparte>"** (Fonds Finanz/
  Thinksurance, 06.08.2026) liest `DeckungsauftragParser`: VN-Block „Daten
  des Versicherungsnehmers" (nie der Vermittler rechts/unten), Versicherer/
  Tarif/Praemie gemaess Zahlweise (brutto), Stufe `antrag` - Vorgangs- und
  RV-Nummer sind KEINE Vertragsnummern (nur Zusammenfassung, die echte
  Nummer bringt die Police via findApplicationContractForConfirmation).
  Beginn aus den RISIKOANGABEN (der Schutz-Abschnitt verweist selbst mit
  „siehe Risikoangaben" darauf); der ISO-Zeitraum „Beginn / Ende" der
  Beitragsberechnung gilt nur ersatzweise. Das Gewerbe-Fahrzeug
  (Kennzeichen) steht NUR in der Zusammenfassung, nie in data.kfz - sonst
  wuerde die Fahrzeug-Identitaet spaetere Kfz-Dokumente desselben Autos
  faelschlich dem Haftpflicht-Vertrag zuordnen. IBAN nur, wenn der
  Kontoinhaber der VN ist. Tests: `DeckungsauftragParserTest`.
  Die POLICE des Online-Gewerbeversicherers **andsafe AG** (Provinzial,
  24.07.2026) liest `AndsafeGewerbePoliceParser`: Sparte AUSSCHLIESSLICH
  aus dem Feld "Versicherung" (der Abschnitt "Optionale Einschluesse" nennt
  zusaetzlich eine Privathaftpflicht - sie ist ein Baustein und darf die
  Sparte nicht kippen; unbekanntes Produkt laesst die Sparte leer),
  Beitrag = "Gesamtforderung" zur "Vereinbarten Zahlungsweise" (der
  wiederkehrende BRUTTO-Betrag; Jahresbeitrag nur ersatzweise, der
  "Nettobeitrag" der Bausteine NIE). KEINE Bankdaten: die Kunden-IBAN ist
  maskiert ("DEXXXX...2807"), die vollstaendige IBAN im Brieffuss gehoert
  der andsafe AG. Mehrzeilige Werte werden ent-silbentrennt
  ("resul-\ntierende" -> "resultierende"). Gewerbe/Umsatz/Versicherungs-
  summe/Selbstbeteiligung stehen in der Zusammenfassung. Tests:
  `AndsafeGewerbePoliceParserTest`.
  Die zugehoerigen POLICEN (Stufe `vertrag`, echte Vertragsnummer,
  06.08.2026): `InterlloydPoliceParser` (Interlloyd/ARAG-Versicherungsschein,
  Sparte aus dem Produktnamen "BHV Business Secure" -> betriebshaftpflicht,
  unbekanntes Produkt laesst die Sparte bewusst leer; "Praemie gemaess
  Zahlungsweise" = wiederkehrender BRUTTO-Betrag; Tag darf einstellig sein
  "1.01.2028"; Kunden-Nr. ist KEINE Vertragsnummer -> Zusammenfassung) und
  `DialogFrachtfuehrerPoliceParser` (Dialog Verkehrshaftungsschutz,
  Jahresbeitrag BRUTTO - die "netto"-Zeile faellt am Regex vorbei; Zahlweise
  vierteljaehrlich nur als Text, der Betrag wird NIE selbst geteilt). Beide:
  Kundenblock aus der LINKEN Spalte (rechts Makler/Service - nie Kundendaten),
  letztes Namenswort = Nachname (wie Allianz), versichertes GEWERBE-Fahrzeug
  nur in der Zusammenfassung (data.kfz bleibt leer - dasselbe Fahrzeug hat
  eine eigene Kfz-Police, hier Allianz DU-KA 684). Tests:
  `InterlloydPoliceParserTest`, `DialogFrachtfuehrerPoliceParserTest`.
  Neue Sparte = eine Zeile in `Contract::TYPES`, keine Migration
  (`type` ist String). Tests: `GewerblicheSpartenTest`.
- **Farbschema „Smaragd & Gold"** (Betreiber-Entscheidung 22.07.2026,
  ersetzt „Graphit + Smaragd"; Richtungswahl dokumentiert in
  `docs/design/design-richtungen.html`): Smaragd bleibt Marken- und
  Aktionsfarbe `#17A65B` (Verlauf `#19b463`->`#128a4b`); dunkle Flaechen
  sind Gruen-Graphit `#131A17`/`#0F1512`/`#0B1310` (passend zum
  Logo-Metall); GOLD `#B8A16B` (hell `#D1C18F`) ist reiner Premium-AKZENT
  fuer Badges, aktive Zustaende, Kicker, Zierlinien - NIE fuer
  Primaer-Buttons; helle Flaechen sind WARM: Canvas `#F1EEE5`
  (Website-Canvas `#F8F6F0`), Surface `#FBFAF6`, Linien `#E0DCD0`,
  Text `#16211C`/`#5F6B62`. Website-Ueberschriften in Serif
  (Playfair Display, AR: Amiri). KEIN Petrol-Gruen, keine kuehlen
  Grautoene (`#DCDEE3`/`#ECEEF1`/`#CDD1D8`) mehr verwenden.
  E-Mail-Templates sind bewusst noch auf altem Stand (separates Thema,
  Outlook-Risiko).
- **Logo-Assets** (alle aus `logo.png` per GD generiert, `public/images/`):
  `logo-white.png` (weisse Wortmarke, für dunkle Flächen: Login, Sidebars),
  `logo-transparent.png` (farbige Wortmarke, für helle Flächen),
  `logo-icon.png` (512px D-Symbol, transparent), `logo-icon-white.png`
  (D-Symbol weiss fuer dunkle Sidebars), `favicon.png` (32px),
  `apple-touch-icon.png` (180px). Favicon zentral via
  `resources/views/partials/favicon.blade.php` (vor jedem `</head>`).
  `logo.png` = Original mit weissem Hintergrund (Quelle der Varianten).
  Willkommens-Mail bewusst OHNE Logo-Bild (Outlook blockiert CID) –
  Textmarke im Hero.
- **KI-Kundenassistent im Portal-Chat** (Betreiber-Auftrag 17.08.2026,
  Ist-Architektur + Integrationsplan:
  `docs/KI_KUNDENASSISTENT_INTEGRATIONSPLAN.md`): Der Assistent ist KEIN
  allgemeiner Chatbot, sondern arbeitet NUR im Dienstly24-Kundenservice
  (Kunden, Vertraege, Vorgaenge/Tickets, Dokumente, fehlende Unterlagen,
  Status) und NUR mit den Daten des ANGEMELDETEN Kunden. Kern:
  `CustomerAssistantService` (`app/Services/Ai/Assistant/`). Angebaut, nicht
  eingebaut: Antworten sind normale `customer_messages` (Flag
  `ai_generated`), Vorgaenge sind `tickets` (`source = 'ai_assistant'`),
  Anforderungen sind `document_requests` - dieselben Tabellen wie beim
  Mitarbeiter, deshalb erscheint der Upload-Bereich im Portal von selbst.
  Neu sind nur Steuer-/Protokolltabellen (`ai_conversations`,
  `ai_assistant_logs`, `ai_knowledge_entries`).
  ABLAUF (Sperren von guenstig nach teuer, jede kann vorher beenden):
  Schalter des Betreibers -> Zustand der Unterhaltung -> Grenzen ->
  **kostenlose deterministische Vorpruefung** (`AssistantScopeGuard`:
  Bereich, Regel-Umgehung, Mitarbeiter-Wunsch; DE/EN/AR) -> erst dann der
  API-Aufruf. Eine Wetter-Frage und ein „Vergiss deine Regeln" kosten damit
  NICHTS und erreichen das Modell nie. Geschaeftswoerter schlagen die
  Ablehnungsliste (sonst faellt eine echte Anfrage durchs Raster).
  ANBIETER: `AssistantProviderInterface` (eigene Schnittstelle, weil
  `AiProviderInterface` kein Tool-Calling kennt - bestehende Nutzer bleiben
  unberuehrt), Auswahl per `AI_ASSISTANT_PROVIDER` ('none' = hart aus, leer
  = Standard `claude`). ZWEI Implementierungen:
  `ClaudeAssistantProvider` (**Standard**, Betreiber-Entscheidung
  17.08.2026) gegen die Anthropic **Messages API** mit Tool Use - nutzt
  DENSELBEN `ANTHROPIC_API_KEY` wie die Dokumentanalyse, also kein zweiter
  Zugang, keine zweite Fakturierung, kein zweiter AV-Vertrag; Schluessel
  als `x-api-key`-Header. Und `OpenAiAssistantProvider` gegen die
  **Responses API** (`/v1/responses`, Bearer-Header, braucht ein eigenes
  `OPENAI_API_KEY`). Ein Anthropic-Schluessel funktioniert NIE bei OpenAI
  und umgekehrt - getrennte Anbieter. Beim Claude-Weg NIE
  `temperature`/`top_p`/`top_k` senden (aktuelle Modelle antworten mit
  HTTP 400) und das Denken NICHT abschalten: ohne Denken schreiben die
  Modelle Funktionsaufrufe gelegentlich als FLIESSTEXT - der Aufruf laeuft
  dann nie, ohne Fehlermeldung. Stattdessen `effort=low` (Kundenservice ist
  Nachschlagen, keine Grundsatzanalyse); `max_tokens` deckelt Denk- UND
  Antwort-Tokens GEMEINSAM, daher grosszuegig (4096) trotz kurzer Antwort.
  Werkzeug-Runden gehen als ERST alle Aufrufe, DANN alle Ergebnisse in den
  Verlauf (Anthropic verlangt alle `tool_use` in EINER Assistenten- und
  alle `tool_result` in der EINEN Folge-Nachricht; aufgeteilt lernt das
  Modell, keine parallelen Aufrufe mehr zu machen). Key NUR aus der
  Server-`.env` - nie Repo/HTML/JS/Logs.
  TOOLS = die einzige Handlungsmoeglichkeit (`AssistantToolRegistry` ist die
  Whitelist): lesend `getCustomerProfile`, `getCustomerContracts`,
  `getRelevantContractInformation`, `getOpenTickets`, `getProcessStatus`,
  `getRequiredDocuments`, `getMissingDocuments`, `getDocumentStatus`,
  `searchKnowledge`; schreibend `createTicket`, `requestDocument`,
  `escalateToTeam`. Es gibt bewusst KEIN Tool fuer SQL, Vertragsaenderung,
  Kuendigung, Zahlung, Dokumentfreigabe oder andere Kunden. **KEIN
  Tool-Schema enthaelt eine Kunden-ID** - die Akte kommt aus der Sitzung
  (`AssistantToolContext`, readonly); Tools mit Bezugsobjekt pruefen die
  Zugehoerigkeit und melden sonst „nicht gefunden".
  NICHTS ERFINDEN: fehlt eine Angabe in den Kundendaten ODER in der
  Wissensbasis (`AiKnowledgeEntry`, Pflege `/admin/ki-wissensbasis`, nur
  admin/manager), wird UEBERGEBEN statt geraten. Verbindliches
  (Kuendigung, Genehmigung, Geld, Deckung, Dokumentabnahme) ist immer
  Mitarbeiter-Sache. Ein eingegangenes Dokument gilt nur als
  „eingegangen/in Pruefung", nie als anerkannt.
  UEBERGABE (`HandoverService`) erzeugt IMMER drei Dinge: Uebergabe-Status
  (KI schweigt), Vorgang (bestehender offener wird wiederverwendet) und
  Glocke ans Team mit Zusammenfassung aus ECHTEN Daten (nie vom Modell
  formuliert). Die Glocke geht auch raus, wenn die Uebergabe-Automatik AUS
  ist - dann entsteht nur kein Vorgang.
  MENSCHLICHE KONTROLLE: `ai_conversations` fuehrt je Kunde `ai_active` /
  `handover_required` / `assigned_employee_id`; `canAutoReply()` ist die EINE
  Bedingung. Panel im Kunden-Chat der Beraterwelt (Zustand, Grund,
  Zusammenfassung, fehlende Dokumente, letzte Aktion) mit „Übernehmen",
  „KI deaktivieren", „KI wieder aktivieren".
  WIEDERAUFNAHME (Betreiber-Vorgabe 20.08.2026, ersetzt „nach einer
  Uebernahme antwortet die KI nie mehr von selbst"): eine Uebernahme gilt
  dem VORGANG, nicht dem Kunden - sonst blieb ein Kunde nach EINER
  Uebergabe dauerhaft ohne automatische Antwort, auch bei einem voellig
  neuen Anliegen Tage spaeter (genau so gemeldet). `ConversationResumeService`
  holt die KI deterministisch zurueck, sobald (1) der bei der Uebernahme
  vermerkte Vorgang `resolved/closed` ist oder (2) die Ruhefrist ohne
  Mitarbeiter-Nachricht abgelaufen ist (`resume_not_before`, Standard 24 h,
  Einstellung `ai_assistant_resume_quiet_hours`). JEDE echte
  Mitarbeiter-Nachricht schiebt die Frist vor (Model-Hook in
  `CustomerMessage::created` - gilt damit fuer jeden Schreibweg); eine
  KI-Antwort nie. NIE automatisch zurueck kommt sie bei einer BESCHWERDE
  (`AiConversation::NO_AUTO_RESUME_REASONS`) und nach „KI deaktivieren"
  (`auto_resume = false` = bewusst dauerhaft beim Team). Schalter
  `ai_assistant_auto_resume` (AN) stellt das alte Verhalten wieder her.
  Das Panel nennt IMMER den Zeitpunkt der Rueckkehr - „KI deaktiviert"
  ohne Datum war die eigentliche Ursache der Meldung; die Wiederaufnahme
  steht als `ai_resumed` im Ereignisprotokoll, nie im Chattext.
  Tests: `AssistantResumeTest`.
  DUPLIKAT-SCHUTZ: genau EINE Antwort je Kundennachricht (Sperre ueber
  `ai_assistant_logs.customer_message_id` - deshalb ist ein zweiter Anlauf
  durch `ai:answer-pending` gefahrlos); offener Vorgang gleicher Art/aehnlichem
  Betreff wird ergaenzt statt dupliziert; dasselbe Dokument wird nie zweimal
  angefordert (umlaut-/teiltreffer-toleranter Titelvergleich).
  GRENZEN (`config/services.php` 'ai_assistant'): Tool-Runden und
  Gesamtaufrufe hart gedeckelt (keine Endlosschleife), Antworten je Vorgang
  (Einstellung), Rate je Kunde/Stunde, Tageslimit, Timeout; lange
  Kundennachrichten werden gekuerzt. Job `AnswerCustomerMessageJob` laeuft
  asynchron mit `tries = 1` (ein Retry wuerde doppelt antworten); Nachlauf
  `ai:answer-pending` alle 10 Min als Sicherheitsnetz bei totem Worker.
  FALLBACK: jeder Fehler/Ausfall -> ehrliche Nachricht an den Kunden +
  Uebergabe + Glocke „KI-Service nicht verfuegbar". Der Kundenservice faellt
  nie aus.
  SCHALTER (Beraterwelt -> Einstellungen, `AssistantSettings`): Assistent
  an/aus (Notbremse), automatische Antworten, Dokumentenanforderung,
  Ticket-Erstellung, Uebergabe, max. Antworten je Vorgang. **Voreinstellung
  AUS** - eine Integration schaltet sich nicht selbst live.
  DATENSCHUTZ: an das Modell gehen nur die Felder der jeweiligen Frage -
  NIE IBAN/BIC/Kontoinhaber, Steuer-ID, Gesundheits-/Ausweisdaten; das
  Protokoll speichert KEINEN Nachrichtentext und keinen Prompt (nur Absicht,
  Tools, Aktionen, Ergebnis; Details verschluesselt). Kennzeichnung „🤖
  KI-Assistent" im Portal-Chat (Transparenzpflicht).
  STOERUNGSSUCHE (Lehre 18.08.2026 - "die KI antwortet nicht"): der
  Assistent ist eine KETTE (Schalter -> Anbieter/Schluessel/Endpunkt ->
  Migrationen -> Queue-Worker -> Wissensbasis). Reisst ein Glied, sieht der
  Betreiber IMMER dasselbe (keine Antwort), obwohl jede Ursache eine andere
  Loesung hat. Deshalb `php artisan ki:pruefen` (`CheckAiAssistant`, nur
  lesend): prueft die Kette in Betriebsreihenfolge, zeigt die Ergebnisse der
  letzten 7 Tage aus `ai_assistant_logs` (kein Protokoll = nie angestossen
  -> Schalter/Worker; `fallback` = Dienst gestoert) und nennt die naechsten
  Schritte; Exitcode 1 = handlungsbeduerftig. `--live` sendet einen echten
  Mini-Aufruf - die EINZIGE Pruefung, die Schluessel, Endpunkt UND
  Modellfreigabe beweist (401 falscher Schluessel, 404 Modell/Endpunkt,
  Timeout Netz/Firewall). Der Schluessel wird NIE ausgegeben, auch nicht
  teilweise. Arabische Betreiber-Anleitung:
  `docs/ANLEITUNG_KI_ASSISTENT_AR.md`. Tests: `CustomerAssistantTest`
  (Abnahmefaelle 1-17 der Spezifikation), `AssistantDiagnosisCommandTest`.
  WISSENSBASIS FUELLEN (Betreiber-Auftrag 18.08.2026): die leere
  Wissensbasis war die eigentliche Huerde vor dem Livegang - fertiger
  Assistent, der fast alles ans Team uebergibt. Die Antworten stehen aber
  laengst im System: die Leistungsseiten (`ServicePage`) tragen je Sparte
  Einleitung, Leistungspunkte, Anbieterliste und zweisprachige haeufige
  Fragen. `ki:wissensbasis-vorschlag` (`DraftKnowledgeBaseEntries`)
  uebertraegt genau diese Texte WOERTLICH als Eintraege - bewusst OHNE
  Umformulieren/Zusammenfassen/Ergaenzen (sonst stuende in der Wissensbasis
  eine Aussage, die niemand geprueft hat, und der Assistent gaebe sie
  weiter); der ausfuehrliche `body` bleibt draussen (Website-Fliesstext
  verwaessert die Stichwortsuche). Jeder Entwurf entsteht INAKTIV, traegt
  seine Herkunft (`ai_knowledge_entries.source_key`, z.B.
  `servicepage:kfz-versicherung:faq:0:de`) und wird erst durch die
  Freigabe unter `/admin/ki-wissensbasis` zur Auskunft. Der source_key ist
  auch der Duplikat-Schutz: ein zweiter Lauf legt nichts doppelt an und
  ueberschreibt NIE einen Eintrag, den ein Mensch geaendert hat; von Hand
  angelegte Eintraege haben keine Quelle und werden von keinem Befehl
  angefasst. In der Pflegeseite: Filter „Nur Entwürfe", Herkunft je Zeile
  und Sammelaktion (freigeben/deaktivieren/loeschen) ueber die
  ANGEKREUZTEN Eintraege - bewusst kein „alles freigeben" ueber ungelesene
  Texte hinweg; das Sammel-Formular liegt wegen der Bearbeiten-Formulare je
  Zeile ausserhalb der Liste (`form="bulkForm"`, verschachtelte Formulare
  waeren ungueltig). `ki:pruefen` meldet wartende Entwuerfe getrennt von
  aktiven Eintraegen. Tests: `KnowledgeBaseDraftTest`.
  WISSENSLUECKEN + SAMMELERFASSUNG (Betreiber-Auftrag 18.08.2026, Frage
  „lernt das System aus unseren Antworten?"): NEIN - es gibt kein
  Nachtrainieren und kein selbsttaetiges Lernen; der Assistent wiederholt
  ausschliesslich, was ein Mensch freigegeben hat (`ki:leitfaden-entwurf`
  misst nur den STIL, nie den Inhalt). Was fehlte, war die Rueckmeldung:
  fand `searchKnowledge` nichts, uebergab der Assistent stumm ans Team und
  niemand erfuhr, dass eine Frage keine Antwort hat. Neu: jede erfolglose
  Suche landet in `ai_knowledge_gaps` (`AiKnowledgeGap::record`), Seite
  `/admin/ki-wissensluecken` nach HAEUFIGKEIT sortiert - „einmal
  beantworten, ab dann beantwortet es der Assistent selbst" (Formular je
  Luecke legt den Eintrag an UND schliesst sie). Gespeichert wird NUR der
  Suchbegriff (die Stichworte des Modells, nicht der Nachrichtentext) plus
  Zaehler - KEIN Kundenbezug, keine Nachricht: die Luecke ist eine Aussage
  ueber UNSERE Wissensbasis, nicht ueber einen Kunden. Dedupliziert ueber
  ein normalisiertes `topic_key` (klein, umlaut-neutral, Woerter sortiert:
  „Angebote Strom" = „strom angebote"), Portal- und Website-Assistent
  getrennt gezaehlt (`scope`, Website durchsucht nur `PUBLIC_CATEGORIES`).
  Eine erledigte Luecke wird bei erneutem Fehlschlag WIEDER GEOEFFNET (dann
  findet die Suche den Eintrag nicht - Titel/Stichwoerter stimmen nicht);
  ignoriert bleibt ignoriert, der Zaehler laeuft weiter. Massstab fuers
  Schliessen ist die ECHTE Suche (`closeCoveredGaps` nach Anlegen/Freigabe;
  ein ENTWURF schliesst nie eine Luecke - der Assistent findet ihn ja
  nicht). Dazu Sammelerfassung `POST /admin/ki-wissensbasis/import`:
  Frage/Antwort-Bloecke als Fliesstext (`F:`/`A:`, arabisch `س:`/`ج:`,
  Leerzeile trennt, mehrzeilige Antworten erlaubt) - ein Block ohne beides
  wird UEBERSPRUNGEN statt halb angelegt, unlesbarer Text wird abgelehnt.
  Tests: `KnowledgeGapTest`.
- **KI-VERKAUFSASSISTENT (Ausbau, Betreiber-Auftrag 18.08.2026, 28
  Abschnitte; Plan: `docs/KI_VERKAUFSASSISTENT_PLAN.md`)**: aus dem
  reaktiven Frage-Antwort-Assistenten wird ein FUEHRENDES Gespraech
  (Bedarf erfassen -> Angebot -> Zusage -> Vertragsdaten -> Pruefung ->
  Abschluss durch den Mitarbeiter). Angebaut wie zuvor: keine Aenderung an
  `customers`, `contracts`, `tickets`, `document_requests`,
  `customer_messages`.
  ZUSTAND ist ein FELD, nicht der Verlauf (`ai_conversations.state`,
  `ConversationState`): NEW -> IDENTIFYING_CUSTOMER ->
  COLLECTING_REQUIREMENTS -> COLLECTING_ADDRESS -> WAITING_FOR_OFFER ->
  OFFER_PRESENTED -> WAITING_FOR_CUSTOMER_DECISION -> CUSTOMER_ACCEPTED ->
  COLLECTING_CONTRACT_DATA -> VERIFYING_DATA -> VERIFICATION_PASSED ->
  CONTRACT_READY -> COMPLETED, quer dazu HUMAN_REQUIRED (aus JEDEM Zustand
  erlaubt). Gewechselt wird NUR ueber `moveTo()` und nur ueber erlaubte
  Uebergaenge - ein Modell kann nicht per Fliesstext von "gerade begonnen"
  auf "Vertrag fertig" springen.
  NIE ZWEIMAL FRAGEN (`RequirementProfile` je Anliegen +
  `ConversationContext`): der Prompt bekommt "bereits bekannt" und "noch
  offen"; Angaben aus der KUNDENAKTE zaehlen als bekannt (ein
  Bestandskunde diktiert seine Anschrift nicht noch einmal).
  SENSIBLE WERTE ERREICHEN DAS MODELL NIE (`SlotExtractor`, Abschnitte
  9/10/11): IBAN (Mod-97-Pruefziffer), E-Mail, Geburtsdatum und
  Telefonnummer werden VOR dem Modellkontakt aus der Nachricht geloest,
  verschluesselt gespeichert und durch Platzhalter ersetzt; das Modell
  sieht "liegt vor". `saveCollectedInformation` LEHNT sensible Felder ab -
  kaeme so ein Wert vom Modell zurueck, waere er geraten.
  STILLE PRUEFUNG (`InternalVerificationService`): nach aussen nur
  VERIFICATION_PASSED/FAILED/PENDING - kein Grund, kein Bestandswert,
  weder an das Modell noch an den Kunden (sonst waere der Chat ein Orakel
  zum Erraten gespeicherter Daten). Pruefpunkte sieht nur der Mitarbeiter.
  ANGEBOTE (Phase 1): der MITARBEITER hinterlegt sie im KI-Panel
  (`ai_offers`); die KI sucht nichts und nennt keinen Preis, der nicht aus
  `getOffers` stammt. `OfferSourceInterface`/`ManualOfferSource` - Phase 2
  (Angebotssuche) tauscht NUR die Bindung in `AppServiceProvider`.
  ZUSTIMMUNG (Abschnitt 4): das MODELL entscheidet (Zusammenhang),
  `AcceptanceDetector` ist das Netz fuer eindeutige Faelle; bei ZWEI
  Angeboten ohne Benennung wird NICHTS gewaehlt (nie raten), eine
  Verneinung schlaegt jede Zustimmung.
  STOERUNG IST SICHTBAR (Abschnitt 13): `status`/`paused_reason`/
  `last_successful_step`/`current_step`/`next_action` am Gespraech, rote
  Karte im KI-Panel mit "Erneut versuchen" (loescht die Antwort-Sperre der
  letzten Nachricht und stoesst sie neu an). Nie wieder "es passiert
  einfach nichts".
  WEBSITE-ASSISTENT (19/20): `/api/website-assistent` + Chatfenster in
  `website/partials/assistant.blade.php` (DE/AR, RTL, KEINE externen
  Ressourcen). Trennung ist STRUKTURELL: eigener `LeadContext`, eigene
  Schnittstelle `LeadTool`, eigene Whitelist mit genau drei Funktionen
  (searchKnowledge nur `PUBLIC_CATEGORIES`, saveLeadInformation,
  requestHumanContact) - ein Kunden-Werkzeug passt typmaessig gar nicht
  hinein. Die Lead-Kennung kommt IMMER aus der Server-Sitzung, nie aus dem
  Request. Interessenten stehen in `ai_leads` (`/admin/interessenten`),
  die Uebergabe erzeugt genau EINEN Vorgang mit Gastdaten.
  MITARBEITER-ASSISTENT (15/16): `EmployeeAssistantService` trennt FAKTEN
  (Zusammenfassung, bekannt/fehlend, Fortschritt, Angebot, Pruefstand,
  naechster Schritt - deterministisch) von FORMULIERUNG (Antwortvorschlag,
  einziger Modellaufruf, wird NIE automatisch gesendet).
  STIL LERNEN (17): `ki:leitfaden-entwurf` misst Laenge/Ansprache/
  Begruessung/Rueckfragen an echten Mitarbeiter-Antworten und legt einen
  INAKTIVEN Entwurf in der Wissensbasis an (Kategorie `leitfaden`).
  Bewusst KEIN Nachtrainieren und kein woertliches Nachahmen.
  AUDIT (23): `ai_conversation_events` getrennt vom Chattext - Feldnamen
  und Ergebnisse, NIE Werte. Arabische Betreiber-Anleitung:
  `docs/ANLEITUNG_KI_VERKAUFSASSISTENT_AR.md`. Tests: `SalesAssistantTest`.
- **Smart Document Upload** (`SmartDocumentUploadController`,
  `DocumentAnalyzer`): Analyse laeuft **„kostenlos zuerst"** (Betreiber-
  Entscheidung) und der KI-Anbieter ist austauschbar
  (`DocumentAiProviderInterface`, Registrierung in `AppServiceProvider`,
  Auswahl per `AI_DOCUMENT_PROVIDER`). Ablauf in `DocumentAnalyzer::analyze`
  (kostenaufsteigend):
  0) **PDF-Textebene zuerst** (`PdfTextLayerExtractor`, `pdftotext`) - viele
  hochgeladene Dokumente (CHECK24-Beratungsprotokolle, Antraege/Policen aus
  Versicherer-Portalen, alles aus einer Software) sind DIGITALE PDFs mit
  perfekter Textebene: gratis, fehlerfrei, sofort. Nur wenn keine Textebene
  da ist (echter Scan), 1) **OCR** - `TesseractTextExtractor` liest den Text.
  Auf dem gewonnenen Text bestimmt `HeuristicDocumentClassifier` Typ +
  Basisfelder (Stichwort-Erkennung + konservative Regex-Extraktion:
  IBAN/FIN/Kennzeichen/E-Mail nur aus eindeutig abgegrenzten Zeilen, keine
  Namen/Adressen aus Freitext; `ai_source = 'ocr'`, Konfidenz 20/40).
  2) **Reicht das kostenlose Ergebnis** (`ocrResultSufficient()`: Text KURZ
  genug fuer die einfache Heuristik - lange, mehrseitige Dokumente haben zu
  viele Abschnitte und erzeugen Falschtreffer, daher Eskalation - UND Typ
  erkannt UND mind. ein Feld), wird es OHNE KI-Aufruf uebernommen.
  3) **Sonst Eskalation an den KI-Anbieter**: bei vorhandener Textebene
  bekommt Claude den (auf `OCR_AI_TEXT_MAX_CHARS`, Default 12000, gekuerzten)
  **TEXT** statt der teuren Bild-/PDF-Seiten (ein 19-seitiges Protokoll als
  Vision kostet schnell 20+ Cent, als Text nur Bruchteile), sonst Vision;
  `ai_source = 'ai'`. 4) Ohne KI-Anbieter bleibt es beim kostenlosen
  Ergebnis. Mitarbeiter koennen die KI ueber den Button
  **„🤖 Mit KI analysieren"** bewusst erzwingen (`reanalyze` ->
  `AnalyzeDocumentJob(forceAi: true)`, Vision). Die kostenlose Basisebene
  (Textebene + OCR) ist standardmäßig **AUS** (`OCR_ENABLED=false`, die
  Textebene folgt per Default `OCR_TEXT_LAYER=OCR_ENABLED`) und muss erst nach
  Installation der Systempakete freigeschaltet werden: `apt install
  tesseract-ocr tesseract-ocr-deu poppler-utils` auf dem VPS, danach
  `OCR_ENABLED=true` in der `.env`. Rohtext wird bewusst NICHT gespeichert
  (Datenminimierung) - nur das validierte Extraktionsergebnis.
  DUPLIKAT-REGEL (Lehre 31.07. + 06.08.2026): die Wiederverwendung des
  Zwillings-Ergebnisses (identischer Content-Hash) greift erst NACH den
  Vorlagen-Parsern - auf der Textebene UND auf dem OCR-Text - und spart
  nur noch Heuristik + KI-Eskalation. Sonst kopiert ein erneut
  hochgeladenes FOTO (z.B. Meldebestaetigung) fuer immer das alte
  Fehl-Ergebnis von vor der Parser-Verbesserung. Tests:
  `DuplicateDetectionTest`.
- **Der Kontakt-Screenshot darf nicht an seiner Darstellung scheitern**
  (Lehre 21.08.2026, gemeldet): Derselbe Kontaktzettel (Name + zwei Daten,
  Anschrift, Handynummer, E-Mail, IBAN) wurde als Screenshot einmal erkannt
  und einmal nicht - der einzige Unterschied war die DARSTELLUNG (heller
  gruener Hintergrund mit farbigen Links gegenueber schwarz auf weiss).
  MIT CHROMIUM + TESSERACT NACHGESTELLT (340 px breiter, JPEG-komprimierter
  Chat-Screenshot beider Fassungen): die OCR liest den Text fast perfekt,
  verliest aber je Fassung EIN ANDERES Zeichen - weiss "DE89" -> "DEB9",
  gruen "@" -> "@®". Fuer den alten `KontaktdatenBlockParser`, der E-Mail UND
  IBAN UND PLZ+Ort verlangte, war damit BEIDE Male gar nichts da: das
  Dokument landete als "Sonstiges Dokument / Kein Kunde gefunden" im Eingang.
  Nicht die Farbe war das Problem, sondern die Alles-oder-nichts-Regel.
  **Bewusst NICHT geaendert wurde die Bildvorstufe.** Der Versuch, kleine
  Aufnahmen staerker (3x/4x statt 2x) oder in Graustufen mit angehobenem
  Kontrast zu lesen, wurde gemessen und VERWORFEN: er repariert die IBAN und
  zerlegt dafuer die Postleitzahl ("88284" -> "85284") und das "@". Mehr
  Vorverarbeitung verschiebt die Fehler nur - die Robustheit gehoert in den
  Parser.
  **Geaendert wurde der Parser** (`KontaktdatenBlockParser` + neuer
  gemeinsamer Baustein `App\Services\Ai\Concerns\RepairsOcrText`): es
  genuegen ZWEI von {E-Mail, IBAN, PLZ+Ort, Telefonnummer}, davon mindestens
  eines PERSOENLICH (E-Mail oder IBAN) - ein blosser Briefkopf (nur Anschrift
  + Telefon) loest also weiterhin nicht aus, ebenso wenig ein kurzer Text mit
  Dokumentwoertern (Rechnung, Police, Antrag ...). Typische
  OCR-Verwechslungen in der IBAN (B/8, O/0, I/1, S/5 ...) werden
  zurueckgesetzt, uebernommen wird der Wert aber NUR mit gueltiger
  PRUEFZIFFER (Mod 97) - eine geratene Bankverbindung kann so nicht
  entstehen; ohne gueltige IBAN bleibt `bank` leer und die Zusammenfassung
  sagt es ausdruecklich. Bei der E-Mail werden nur eindeutige
  Erkennungsfehler repariert ("©"/"®" statt "@", verdoppeltes "@", "(at)",
  fehlender Punkt vor der Endung). Die Zusammenfassung nennt nur noch die
  Felder, die WIRKLICH gelesen wurden, und die Konfidenz sinkt (58 statt 70),
  wenn ein Signal fehlt - der Mitarbeiter sieht im Review, dass er
  hinschauen soll. Tests: `KontaktdatenBlockParserTest`.
- **eAT-Rueckseite + Arbeitsvertrag im Dokumenten-Eingang** (Betreiber-
  Vorgabe 29.07.2026): Der `AufenthaltstitelParser` liest jetzt auch die
  RUECKSEITE der Aufenthaltstitel-Karte - sie traegt keine Vorderseiten-
  Beschriftungen, dafuer die TD1-MRZ (drei Zeilen, beginnend "AR...").
  Dekodierung deterministisch MIT Pruefziffern-Validierung (kaputte
  Pruefziffer -> Feld wird verworfen, nie falsch uebernommen): Name,
  Geburtsdatum, Geschlecht, Staatsangehoerigkeit, Dokumentennummer, Ablauf;
  zusaetzlich Anschrift-Aufkleber (-> strukturierte Adresse) und
  GEBURTSORT. Der eAT zaehlt beim Batch-Merge als Ausweis-Dokument
  (Personendaten-Hoheit). Neuer Dokumenttyp `arbeitsvertrag`
  (`ArbeitsvertragParser`): liest aus dem Vertragskopf den ARBEITGEBER
  (Firmenname per Rechtsform-Erkennung + Anschrift; "vertreten durch" wird
  uebersprungen), den Arbeitnehmer (Name/Anschrift/Anrede->Geschlecht)
  sowie Taetigkeit ("als Bauhelfer eingestellt") und Beginn. Kundenakte
  hat dafuer die Felder `employer_name`/`employer_address` (Bearbeiten-
  Formular neben Beruf, Merge-faehig); Uebernahme im Review-Modal ueber
  die neuen apply-Gruppen `occupation`/`employer` - Beruf ist damit
  bewusst wieder auf der Whitelist (der Arbeitsvertrag nennt ihn
  woertlich; die Uebernahme bleibt eine Mitarbeiter-Auswahl,
  Fuehrerscheindatum/weitere Fahrer bleiben ausgeschlossen). Tests:
  `AufenthaltstitelParserTest`, `ArbeitsvertragParserTest`,
  `SmartDocumentUploadTest`.
- **Zuordnungs-Vorschlaege im Dokumenten-Eingang** (Betreiber-Vorgabe
  29.07.2026): Beim Oeffnen von „Kunden zuordnen…" / „Neuen Kunden
  erstellen" laedt der Dialog SOFORT die naechstliegenden Kunden
  (`SmartDocumentUploadController::customerSuggestions` ->
  `DocumentIntakeService::findSuggestions`) - der Mitarbeiter soll nicht
  selbst suchen muessen, auch wenn die automatische Erkennung („Kein Kunde
  gefunden", Score < 40) nichts lieferte. Zwei Quellen: HARTE
  Identitaetsmerkmale aus dem Dokument (Vertrags-/Mitgliedsnummer,
  FIN/Kennzeichen normalisiert, MaLo-ID, Zaehlernummer -> Score 100) und
  WEICHE Personendaten ueber `CustomerMatchingService::topMatches()` mit
  bewusst breiterem Kandidatenpool als `match()` (jedes Namenswort ab 3
  Zeichen, Firmenname, PLZ) - so taucht ein Kunde auch bei abweichender
  Schreibweise auf. Jeder Vorschlag nennt seinen GRUND, ausgewaehlt wird
  immer per Klick (nie automatisch). Im Neuanlage-Modus dient dieselbe
  Liste als Dubletten-Warnung und wechselt per Klick in die Zuordnung.
  Portfolio-Scope wie ueberall: fremde Kunden werden nur als Anzahl
  gemeldet, nie mit Namen. Tests in `SmartDocumentUploadTest`.
- **Vertrags-Duplikat-Schutz + Version History** (`DocumentIntakeService`,
  Betreiber-Vorgabe 23.07.2026): Ein neu importiertes Dokument fuer ein
  bereits erfasstes Fahrzeug/eine Police erzeugt KEIN Duplikat mehr.
  `findExistingContractByIdentity()` sucht den Bestandsvertrag streng ueber
  die Identitaet (Vertragsnummer -> FIN/VIN -> Kennzeichen -> Energie
  MaLo-ID/Zaehlernummer). Trifft einer zu, aktualisiert
  `updateContractFromExtraction()` nur ihn und schreibt jede geaenderte
  Angabe feldgenau in die Version History (`contract_revisions`,
  `ContractRevision`, `ContractRevisionRecorder`): Feld, alter/neuer Wert,
  Zeitpunkt, Quelle, Bearbeiter (null = System). Regeln: leere neue Werte
  ueberschreiben nie einen Bestand (kein Datenverlust); Zusatzleistungen
  werden ergaenzt, nie entfernt; feste Identitaetsfelder (Kennzeichen, FIN,
  MaLo-ID ...) werden nur ergaenzt, wenn leer. Anzeige des Verlaufs auf der
  Vertrags-Bearbeiten-Seite (`partials/contract_revisions.blade.php`). Nur
  wenn kein passender Vertrag existiert, wird ein neuer angelegt -> genau EIN
  Vertrag je Fahrzeug UND Versicherer. Praezisierung 26.07.2026: die
  Fahrzeug-Identitaet (FIN/Kennzeichen) ordnet nur beim SELBEN Versicherer
  zu (`insurersLookAlike`, "ADAC" = "ADAC Autoversicherung AG"); die Police
  eines ANDEREN Versicherers fuer dasselbe Auto ist ein WECHSEL und wird ein
  eigener Vertrag. Kennzeichen-Vergleich zentral + umlaut-tolerant
  (`ContractVehicleDetail::normalizePlate`, "LUEN-G 1110" = "LUN-G1110").
- **Referenz-/Vorgangsnummer am Vertrag** (`contracts.reference_number`,
  Betreiber-Vorgabe 17.08.2026): Ein ANTRAG hat KEINE Vertragsnummer - aber
  jedes Portal vergibt eine eigene Kennung (Referenznummer der
  Antragsstrecke, Auftragsnummer des Energieportals, Vorgangsnummer des
  Maklerpools, Protokoll-Nr. des Vergleichsrechners, NAFI-Vorgang). Genau
  diese Nummer teilt der Betrieb mit anderen Systemen und findet damit
  spaeter wieder, WELCHER Vertrag bestaetigt wurde. Sie steht im eigenen
  Feld (nie in `contract_number`), ist im Vertragsformular pflegbar, in der
  Kundenakte sichtbar ("Ref. …") und in der globalen Suche findbar. Die
  Parser fuellen sie automatisch (`AntragBestaetigungParser`,
  Deckungsauftrag, Online-Protokoll, Energie-Portal, LichtBlick, PLAN-B,
  NAFI). Zuordnung: `findExistingContractByIdentity` und
  `sharesDistinctiveDetail` erkennen den Vorgang daran wieder,
  `identityHits` liefert den KUNDEN (Score 100) - eine hochgeladene
  Abrechnung, die nur die Referenz nennt, landet damit am richtigen Kunden
  und Vertrag. Regeln: nur ERGAENZEN, nie ueberschreiben (der urspruengliche
  Vorgangsschluessel bleibt); nicht unique (ein Vorgang kann Strom + Gas
  tragen); ab 5 Zeichen (kurze Nummern treffen halbe Bestaende). Der
  `AntragBestaetigungParser` liest die Abschluss-Seite einer Antragsstrecke
  ("Vielen Dank, Ihr Antrag ist bei uns eingegangen"): Referenznummer,
  Gesellschaft, Kunden-E-Mail, eVB-Nummer (nur Zusammenfassung, KEINE
  Vertragsnummer); "Tag der Zulassung" ist kein Datum und wird nie geraten.
  **EIN Vorgang = EIN Vertrag, auch bei zwei Dokumenten** (Lehre
  21.08.2026): Zu einem Antrag laedt der Betrieb ZWEI Dinge hoch - das
  Beratungsprotokoll des Vergleichsportals (Tarif, Beitrag, Beginn, aber
  keine Kennung) und den Screenshot der Abschluss-Seite mit der
  REFERENZNUMMER. Beide sind Stufe `antrag`, keines nennt eine
  Vertragsnummer, sie teilen kein hartes Merkmal - es entstanden zwei
  Vertraege fuer denselben Vorgang, am selben Tag, beim selben Kunden.
  `findApplicationContractForSameProcess` fuehrt sie zusammen: die
  Referenznummer wandert an den vorhandenen Vertrag, es entsteht kein
  zweiter. Beide Reihenfolgen (Protokoll zuerst / Screenshot zuerst)
  fuehren zum selben Ergebnis. Zusammengefuehrt wird NUR, wenn eine der
  beiden Seiten nichts als die Referenz mitbringt (`bringsOnlyProcessReference`
  bzw. `isProcessReferenceShell`) - zwei Antraege mit jeweils eigenen
  Sachdaten (zwei Fahrzeuge bei derselben Gesellschaft) bleiben getrennt.
  Dazu immer: gleiche Sparte, gleiche und BEIDSEITS GENANNTE Gesellschaft
  (fehlt sie, wird nicht zugeordnet - anders als im uebrigen Abgleich, wo
  eine fehlende Angabe als "passt" gilt), kein Widerspruch in den harten
  Merkmalen, hoechstens 60 Tage alt (enger als die 12 Monate der
  Bestaetigungs-Suche: dort belegt eine POLICE den Zusammenhang, hier
  stehen sich zwei gleichrangige Antraege gegenueber), und ein Vertrag mit
  bereits vergebener ANDERER Referenz ist ein eigener Vorgang. Bleiben
  mehrere Kandidaten, wird NICHT geraten - dann entsteht wie bisher ein
  eigener Vertrag und der Mitarbeiter sieht beide.
  Tests: `ContractReferenceNumberTest`, `AntragBestaetigungParserTest`.
- **Vermittler-Abrechnung: Vertrag und Abrechnung zusammenfuehren**
  (Betreiber-Auftrag 20.08.2026, Details in
  `docs/VERMITTLER_ABRECHNUNG_ABGLEICH.md`): Zwischen Abschluss und Geld
  liegen bis zu 90 Tage und ZWEI Nummern - beim Abschluss die
  **Referenz-Nr.** der Antragsbestaetigung (`1477-6741-9200-53`, oft die
  einzige Kennung, eine Vertragsnummer gibt es noch nicht), spaeter in der
  Abrechnungsdatei die **`Id` des Vermittlers** (`9753224`). Die Bruecke
  `Referenz-Nr. -> Vertrag -> Vermittler-ID -> Abrechnung -> Provision`
  wird DAUERHAFT gespeichert (`contracts.reference_number` +
  `contracts.vermittler_id`); ist sie einmal hergestellt, genuegt in jeder
  spaeteren Datei die `Id` - die Spalte `Referenz-Nr.` DARF FEHLEN.
  VIER GRUNDREGELN (als Test festgehalten): **nie raten** (abweichende
  Referenz bei gleicher ID, doppelte Referenz-Nr., unbekannter Status-Code,
  Stornogrund an nicht storniertem Datensatz -> `Prüfung erforderlich`,
  nie eine automatische Zuordnung); **nie Vertragsdaten aendern** (der
  Import schreibt NUR die `vermittler_*`-Spalten, einzige Ausnahme ist das
  ERGAENZEN einer leeren Referenz-Nr.); **nie loeschen** (fehlt ein Vertrag
  in der Datei, heisst das `Nicht in Abrechnung gefunden` - nie
  "storniert", nie "geloescht"; storniert wird nur, was die Abrechnung als
  storniert ausweist); **nie doppelt** (natuerlicher Schluessel ist die
  `Id`, unique - ein erneuter Import derselben Datei aktualisiert und
  meldet "Bereits importiert"). Abrechnungsstatus
  (`Contract::VERMITTLER_STATUSES`) und Vertragsstatus (`status`/`stage`)
  sind GETRENNTE Wahrheiten und ueberschreiben sich nie: der Vermittler kann
  stornieren, waehrend der Vertrag laeuft. Status-Codes des Vermittlers an
  EINER Stelle (`VermittlerStatusMap`, TARIFCHECK24: 1 bestaetigt,
  2 storniert, 4 abgerechnet); ein UNBEKANNTER Code wird nie geraten.
  Der Abgleich in die Gegenrichtung (Vertraege, die in der Datei fehlen)
  ist bewusst eng gefasst - nur Vertraege ohne bisherigen Treffer, ohne
  Vermittler-ID, mit einer Referenz-Nr. im FORMAT DIESER DATEI (Massstab
  kommt aus der Datei selbst) und aelter als ihr juengster Datensatz;
  sonst stuenden fremde Vorgaenge (Energieportal, Maklerpool) faelschlich
  als "nicht abgerechnet" da. HISTORIE UEBERLEBT DAS LOESCHEN:
  `vermittler_settlements` (eine Zeile je Abrechnungs-Datensatz) und
  `vermittler_match_events` haengen mit `nullOnDelete` am Vertrag und
  tragen Referenz-Nr., Vermittler-ID und eine Klartext-Kopie von
  Vertrag/Kunde - bei einer Rueckfrage zu einem Storno ist belegbar, dass
  der Vertrag existierte. Oberflaeche `/admin/vermittler-abrechnung`
  (**nur admin/manager** - hier stehen Provisionsbetraege): Import mit
  sofortigem Ergebnis, Prüfliste (unklare Datensaetze werden per
  Sofort-Suche von Hand zugeordnet, nie automatisch), Auswertung je
  Produkt/Kunde plus Bestaetigungsquote des Vermittlers. In der
  Vertragsakte die Box "🤝 Vermittler / Abrechnung" mit beiden Kennungen,
  Stand, Provision, Stornogrund und der Historie der Zuordnung. Beide
  Kennungen sind in der globalen Suche und in `Contract::scopeSearch`
  findbar. Tests: `VermittlerAbrechnungTest`.
  **VORGANGSLISTE - der Schritt VOR der Abrechnung (Lehre 21.08.2026)**:
  Der Betreiber lud die Uebersicht der OFFENEN Vorgaenge als Screenshot in
  den DOKUMENTEN-EINGANG, um jede `Id` mit ihrer Referenz-Nr. zu verbinden;
  Ergebnis war "Sonstiges Dokument / Kein Kunde gefunden". Das ist kein
  Fehler, sondern der falsche Weg: der Eingang ordnet IMMER ein Dokument
  EINEM Kunden zu - eine Liste mit den Vorgaengen VIELER Kunden kann er
  strukturell nicht verarbeiten. Richtig ist
  `/admin/vermittler-abrechnung` -> "Vorgangsliste einlesen"
  (`VermittlerListeReader` + `VermittlerVorgangslisteImporter`): CSV/TXT
  (**exakt**, Spalten beschriftet - immer vorzuziehen), PDF-Textebene oder
  Screenshot per OCR. Sie stellt NUR die Bruecke her: `vermittler_id` an den
  ueber die Referenz-Nr. gefundenen Vertrag, Status `id_zugeordnet`,
  Historien-Eintrag. Eine Liste offener Posten ist KEINE Abrechnung - nie
  eine Provision, nie ein Storno, nie ein "Nicht in Abrechnung gefunden"
  (aus dem Fehlen in einer OFFENEN-Liste folgt nichts); und weil sie immer
  aelter ist als eine Abrechnung, stuft sie einen abgerechneten/stornierten
  Vertrag nie zurueck (`VermittlerStatusMap::mayAdvance`, Rangfolge).
  Dieselbe `Id` bekommt spaeter die echte Abrechnung - die Zeile in
  `vermittler_settlements` wird ERGAENZT, nie doppelt angelegt.
  OCR-LEHRE (wie Energieportal/CHECK24): auf Spaltenabstaende ist kein
  Verlass - `VermittlerVorgangslisteParser` liest ueber ANKER (Vorgangs-Id =
  allein stehende 6-10-stellige Zahl; Referenz-Nr. haengt an ihrer
  Beschriftung und gehoert zum zuletzt gesehenen Vorgang; Datum und
  gruppierte Nummern werden vorher entfernt, damit "2026" nie eine Id wird).
  Faellt eine ZWEITE Referenz-Nr. auf denselben Vorgang, hat die Erkennung
  die Tabelle spaltenweise gelesen -> `ambiguous`, und dann wird in dieser
  Datei GAR NICHTS verknuepft, alles geht in die Pruefliste (eine falsch
  gepaarte Zahl haengt die Abrechnung eines FREMDEN Kunden an den Vertrag).
  Der Eingang selbst erkennt die Liste jetzt gratis
  (`VermittlerVorgangslisteHinweisParser`, Dokumenttyp
  `vermittler_vorgangsliste`) und verlinkt auf die richtige Seite, statt in
  der Sackgasse "Kein Kunde gefunden" zu enden - Erkennung bewusst STRENG
  (mind. 3 Vorgaenge UND 2 verschiedene Referenz-Nummern), denn ein
  Fehlalarm wuerde ein echtes Kundendokument von seiner Akte fernhalten.
  BEDIENT WIRD SIE IM EINGANG (Betreiber-Wunsch 21.08.2026 - "besser als in
  den Admin-Bereich gehen und nicht wissen was"): Knopf "Vorgangsliste
  einlesen" an der Dokumentzeile (`admin.vermittler.from_document`, liest
  die Datei erneut von der Storage-Disk - Rohtext wird weiterhin nie
  gespeichert). Danach traegt das Dokument `vermittler_import_id`, verlaesst
  "Nicht zugeordnet" (sonst stuende dort dauerhaft eine Aufgabe, die keine
  ist) und steht im Abschnitt "Eingelesene Vermittler-Vorgangslisten" -
  GELOESCHT wird nie etwas. Der Knopf erscheint zusaetzlich bei
  "Sonstiges Dokument" (mit Rueckfrage) als Rueckfallebene, falls die
  Erkennung die Tabelle einmal nicht als Liste einstuft. Verarbeitung bleibt
  admin/manager (sie fuehrt auf die Seite mit den Provisionsbetraegen).
  Tests: `VermittlerVorgangslisteTest`.
- **Interne Provisionen: Fremd-Abrechnungen an den eigenen Vertrag binden**
  (Betreiber-Auftrag 26.08.2026, Anleitung
  `docs/ANLEITUNG_PROVISIONEN_IMPORT_AR.md`): Ein DRITTER Provisions-Strang
  neben den beiden bestehenden - und bewusst nicht mit ihnen verschmolzen:
  `provisions` = AUSGANG an eigene Mitarbeiter/Partner, `vermittler_settlements`
  = der EINE Vermittler TARIFCHECK24 mit festem Format und EINER Kennung,
  `contract_commissions` = EINGANG aus BELIEBIG vielen Quellen (Maklerpool,
  Vergleichsportal, Energieportal) mit fremden Spalten, mehreren Kennungen,
  mehreren Provisionen je Vertrag, Waehrungen und Teilzahlungen. Die drei zu
  vereinen hiesse, eine der drei Wahrheiten zu verbiegen.
  **DIE BRUECKE**: `contracts.internal_contract_number` (Maklerpool
  "V19613073") kommt NEBEN `contract_number` (Nummer der Gesellschaft) und
  `reference_number` (Vorgangsnummer der Antragsstrecke) - drei Nummern aus
  drei Systemen; eine davon zu ueberschreiben kappt eine Bruecke, die spaeter
  gebraucht wird. Zugeordnet wird nach TRENNSCHAERFE: interne Vertragsnummer
  -> Vermittler-Id -> Referenz-Nr. -> Auftr.-Nr. -> Vertragsnummer der
  Gesellschaft (`CommissionMatcher`). Fehlende Kennungen werden am Vertrag
  ERGAENZT, vorhandene NIE ueberschrieben - danach genuegt in jeder weiteren
  Datei die eine Nummer.
  **ZWEISTUFIGER IMPORT** ist die eigentliche Anforderung, nicht Komfort: ein
  einstufiger Import zeigt sein Ergebnis erst, NACHDEM er geschrieben hat -
  wer dann "3 Vertraege nicht gefunden" liest, hat keine Wahl mehr.
  `analyze()` legt einen ENTWURF ab (`commission_imports` +
  `commission_import_rows`, Rohzellen UND Deutung je Zeile), `confirm()`
  schreibt. Dazwischen: Erkennung, Spaltenzuordnung (`ColumnMap`, aenderbar
  ohne erneuten Upload via `remap()`), fuenf Zahlen (neu/aktualisiert/
  duplikat/nicht zugeordnet/fehlerhaft) und ein CSV-Export der Fehlerzeilen.
  **DATEIEN WERDEN AM INHALT ERKANNT, nicht an der Endung** - genau daran
  scheiterte der bisherige Weg: `mimes` reicht bei CSV nicht (Excel-CSV kommt
  als text/plain, application/vnd.ms-excel oder octet-stream an), und eine als
  ".csv" gespeicherte XLSX ist Alltag. Geprueft wird die ENDUNG, gelesen wird
  nach den ersten Bytes (`TableReader::detectFormat`). CSV:
  BOM/UTF-16/Windows-1252 erkannt (Reihenfolge ist Absicht - wer zuerst nach
  Latin-1 fragt, zerstoert gute UTF-8-Dateien), Trennzeichen `;`/`,`/Tab/`|`
  NUR AUSSERHALB von Anfuehrungszeichen gezaehlt (sonst gewinnt das Komma aus
  "Alte Kieler Landstr. 141, 24768 Rendsburg"). XLSX/XLS ohne Fremdpaket
  (`XlsxTableReader` per ZipArchive+XMLReader im Strom, `XlsTableReader` +
  `OleCompoundFile` fuer BIFF8) - ein Tabellen-Framework nur zum Lesen waere
  ein grosses Paket im Sicherheitsupdate-Pfad einer Anwendung mit Kundendaten.
  Datumsformate werden erkannt, Formeln mit ihrem GESPEICHERTEN Ergebnis
  gelesen, Makros nie angefasst. Mehrere Tabellenblaetter werden ALLE genannt
  und sind waehlbar - "das erste Blatt ist das richtige" stimmt nicht.
  **VIER GRUNDREGELN** wie beim Vermittler-Abgleich: nie raten (zwei Treffer,
  ein Widerspruch oder eine zu kurze Kennung -> "nicht zugeordnet"; der NAME
  zaehlt NIE), nie einen Vertrag anlegen, nie Vertragsdaten aendern (Ausnahme:
  leere Kennung ergaenzen), nie doppelt (`dedupe_key` unique aus Kennung +
  Provisionsart + Datum + Datensatz-Nr. + BETRAG - der Betrag gehoert dazu,
  weil echte Abrechnungen zwei Positionen desselben Vertrags am selben Tag
  fuehren). `row_hash` trennt "unveraendert" (Duplikat) von "geaendert"
  (Aktualisierung). Eine erfasste ZAHLUNG nimmt keine Datei zurueck.
  **STATUS** in `App\Support\CommissionStatus` (offen/faellig/bezahlt/
  teilweise_bezahlt/storniert/unklar) - ein unbekannter Fremdwert wird
  `unklar`, nie geraten; ein Stornogrund ohne Storno-Status ebenfalls.
  **VERTRAULICH**: Zugriff ueber das RECHT `provisionen-verwalten` (Gate im
  `AppServiceProvider`: admin ODER `users.can_manage_commissions`), geprueft
  an der ROUTE **und** im Controller **und** in der Vertragsakte-Box. Es gibt
  bewusst KEINE Beziehung von `Customer` hierher - so kann ein `with()` im
  Portal die Provisionen nicht versehentlich mitladen. Ein Test sichert ab,
  dass Betrag, Empfaenger und interne Nummer im Kundenportal NIRGENDS im HTML
  stehen.
  **PROTOKOLL** `commission_audit_logs` (eigene Tabelle, nicht der allgemeine
  ActivityLog - hier stehen Betraege): Upload, Import, Aenderung, Status,
  Zahlung, Rechnung, Zuordnung, Aenderung der internen Vertragsnummer, Export
  - mit Nutzer, Zeit, Vorher/Nachher, Datei. KEIN Loeschweg aus der
  Oberflaeche. Das Protokollieren darf nie den Vorgang scheitern lassen
  (Fehler wird geschluckt, wie beim ErrorRecorder).
  **RECHNUNGEN** sind vorbereitet, nicht halb gebaut: `InvoiceCommissionMatcher`
  liest Kennungen aus einem Rechnungstext und zeigt Vertrag, Kunde und
  erwartete Provisionen; verknuepft wird bewusst je Provision und eine
  Rechnung bestaetigt NIE eine Zahlung (sie belegt eine Forderung).
  **NICHTS GEHT VERLOREN + NEUANLAGE (Betreiber-Entscheidung 26.08.2026)**:
  Der erste Lauf auf den echten Dateien zeigte die eigentliche Luecke - in
  der Maklerpool-Abrechnung fand der Abgleich 1867 von 1969 Zeilen keinen
  Vertrag, nicht weil die Zuordnung schlecht waere, sondern weil die
  Vertraege nie im Portal erfasst wurden. Zwei Aenderungen:
  (1) Eine NICHT ZUGEORDNETE Zeile wird trotzdem geschrieben - als Provision
  ohne Vertrag (`contract_id = null`, Liste "Nicht zugeordnet", jederzeit von
  Hand verknuepfbar). Sie stillschweigend zu verwerfen hiess, ueber 90 % der
  Zeilen wegzuwerfen. (2) Auf AUSDRUECKLICHEN Haken entstehen daraus Vertrag
  und - wenn noetig - Kundenakte (`CommissionContractBuilder`). Kein
  Automatismus: ein Lauf legt mehrere hundert Datensaetze an, die Anzahl
  steht deshalb VOR der Entscheidung in der Vorschau (`rows_buildable`).
  GRENZEN: ohne verwertbaren KUNDENNAMEN entsteht nichts (der
  Vergleichsportal-Export hat gar keine Namensspalte - daraus wuerde eine
  leere Akte ohne Menschen darin); der neue Vertrag ist NIE `active`,
  sondern `pending` ("In Bearbeitung") - dass Geld geflossen ist, belegt,
  dass es den Vertrag GAB, nicht dass er heute laeuft; ein VORHANDENER Kunde
  wird nie dupliziert. Herkunft steht an Vertrag und Kunde
  (`commission_import_id`) und in den Notizen ("NICHT geprüft").
  KUNDENZUORDNUNG: zuerst EXAKTER Namenstreffer (normalisiert, im Lauf
  gecacht) - er muss EINDEUTIG sein, zwei gleichnamige Akten heissen "nichts
  anlegen"; erst danach der unscharfe Abgleich, und dort gilt nur Stufe
  `auto` (`confirm` heisst "koennte sein" - daraus darf nichts werden). Der
  unscharfe Abgleich laeuft genau EINMAL je Zeile, naemlich im
  Duplikatsschutz von `CustomerAutoCreationService`.
  NAMEN/ADRESSEN aus Fremdformaten: `PersonNameParser` ("VN RANKO, MOHAMAD
  ADNAN" -> "Mohamad Adnan Ranko", Anrede -> Geschlecht, Firma bleibt
  unangetastet, haengendes Komma dreht nicht) und `ValueParser::address`
  (Anker ist die PLZ, nicht das Komma; ohne PLZ wird NICHTS zerlegt).
  **ZWEI BETRIEBSARTEN** (`commission_imports.mode`): eine ABRECHNUNG traegt
  Betraege (Provisionen entstehen), eine AUFTRAGSLISTE aus dem
  Vertriebsportal traegt Kundendaten OHNE einen einzigen Betrag (nur Kunden
  und Vertraege entstehen, nie eine Provision). Beides in einen Modus zu
  zwingen hiess, eine vollstaendige Datei als "fehlerhaft" abzulehnen - genau
  das passierte mit 584 von 584 Zeilen. Erkannt wird die Art an der
  Betragsspalte, umstellbar in der Vorschau.
  **DREI FEHLER, DIE ERST DIE ECHTEN DATEN ZEIGTEN** (als Test festgehalten):
  `chunkById` blaetterte nach `id`, waehrend ein zusaetzliches
  `orderBy('row_number')` die Reihenfolge bestimmte - dabei fielen Zeilen
  still aus dem Lauf (von 1711 kamen 689 an); jetzt blaettert es nach
  `row_number`. Mehrfach vorkommende POSITIONEN sind keine Duplikate -
  derselbe Vertrag steht mit demselben Betrag am selben Tag bis zu zehnmal
  in der Abrechnung (zehn Faelligkeiten), deshalb geht die Position
  innerhalb der Datei in den `dedupe_key` ein; weil die Reihenfolge einer
  Datei feststeht, bleibt derselbe Upload trotzdem idempotent. Und
  "00.00.0000" ist ein PLATZHALTER fuer "nicht angegeben", kein kaputtes
  Datum - es als Fehler zu werten verwarf die ganze Zeile samt Name,
  Anschrift und Vertrag (`ValueParser::isEmptyDatePlaceholder`).
  **LAUFZEIT**: 86 von 88 Sekunden eines Laufs steckten in `bcrypt` - fuer
  ein Startpasswort, das per Konstruktion NIE jemand benutzt.
  `CustomerAutoCreationService` merkt sich den Platzhalter-Hash jetzt je
  Vorgang (Klartext ist zufaellig, wird nie gespeichert oder verschickt;
  den echten Zugang setzt spaeter `PortalAccessService`). Die drei echten
  Dateien laufen damit in rund 13 Sekunden statt 160.
  **MEHRERE QUELLEN, MEHRERE FORMATE (Betreiber-Meldung 26.08.2026)**: Der
  Betrieb bekommt Abrechnungen aus verschiedenen Systemen, und jedes nennt
  seine Kennung anders - `Id` (Vergleichsportal), `Vertragsnummer intern`
  (Maklerpool), `Auftr.-Nr.` (Energie-Vertriebsportal).
  `CommissionSourceProfile` erkennt die Quelle an der Kopfzeile, benennt sie
  in der Vorschau, stellt die Betriebsart passend ein und wird an Import UND
  Provision gespeichert (`provider`) - erst damit ist "was hat uns welcher
  Vermittler gebracht?" eine Abfrage und nicht eine Suche ueber Dateinamen.
  Filter und Export folgen der Quelle. ERKENNUNG, KEINE VORAUSSETZUNG: eine
  unbekannte Quelle wird NIE abgelehnt, sie laeuft ueber die normale
  Spaltenzuordnung - sonst waere aus "mehrere Quellen" wieder "eine Quelle,
  nur eine andere".
  **KEINE SACKGASSE AUF DER FALSCHEN SEITE**: `/admin/vermittler-abrechnung`
  liest ausschliesslich TARIFCHECK24 (Pflichtspalte `Id`). Wer dort eine
  Maklerpool-Abrechnung hochlud, bekam nur "Die Spalte Id fehlt" - ohne
  jeden Weg weiter. Jetzt steht der Wegweiser VOR dem Upload auf der Seite,
  und schlaegt ein Import fehl, erkennt `wrongImporterHint()` die tatsaechliche
  Quelle und nennt den richtigen Weg samt Link. Eine echte TARIFCHECK24-Datei
  bekommt diesen Hinweis bewusst NICHT - dort ist wirklich die Datei kaputt.
  Tests: `ContractCommissionImportTest`.

- **Familien- und Kundenbeziehungen: bestehende Akten VERKNUEPFEN, nie
  zusammenfuehren** (Betreiber-Auftrag 28.08.2026): Beim Einlesen mehrerer
  Gesundheitskarten EINER Familie ist je Karte eine eigene Kundenakte
  entstanden. Diese Akten sind RICHTIG - an ihnen haengen Dokumente,
  Vertraege, Vorgaenge und Historie. Sie werden deshalb weder geloescht noch
  zusammengefuehrt, sondern zu einer Familie verbunden.
  **EIGENE TABELLE `customer_family_relations`** (`CustomerFamilyRelation`),
  bewusst NEBEN `customer_relationships`: letztere beantwortet genau EINE
  Frage ("kein Duplikat") und speichert das Paar SORTIERT (a<b), hat also
  keine Richtung - "Zania ist Tochter von Jehad" passt da nicht hinein, ohne
  die Bedeutung der Tabelle zu verbiegen. Lesart einer Zeile:
  "`related_customer_id` ist `relationship_type` von `customer_id`". Jede
  Beziehung existiert als PAAR (Hin- UND Rueckrichtung, Gegenrolle ueber
  `inverseRole()` nach dem GESCHLECHT der Bezugsperson, unbekannt ->
  neutrale Form) - sonst kaeme man vom Kind nie zu den Eltern zurueck.
  Einzige Schreibstelle ist `FamilyRelationService`.
  **STATUS UND ROLLE SIND GETRENNT** (Betreiber-Vorgabe 13): die Rolle steht
  an der Beziehung (Vater/Mutter/Ehepartner/Sohn/Tochter/...), der
  KUNDENSTATUS wird ABGELEITET (`Customer::familyStatus()`:
  familienmitglied / hauptkunde / eigenstaendig). Abgeleitet, weil eine
  eigene Statusspalte aus dem Takt laufen wuerde: mit 15 wechselt der Status
  dann auch ohne Cron-Lauf (`dependentNow()` prueft IMMER das Alter). Eine
  16-jaehrige Tochter ist eigenstaendige Kundin UND bleibt Tochter.
  **ABHAENGIG NUR MIT BELEG**: `is_dependent` wird gesetzt, wenn die Rolle
  ein KIND beschreibt UND das Geburtsdatum ein Alter unter 15 belegt. OHNE
  Geburtsdatum entsteht keine Abhaengigkeit - ein Alter wird nie geraten.
  Das Flag steht immer nur an der Zeile der Bezugsperson (ein Elternteil ist
  nie vom Kind abhaengig).
  **STAMMDATEN WERDEN GELESEN, NICHT KOPIERT** (`effectiveContact()`,
  Felder Adresse/E-Mail/Telefon/Mobil): der eigene Wert schlaegt IMMER den
  geerbten; fehlt er, wird der Wert der Bezugsperson ANGEZEIGT (mit Badge
  "vom Elternteil übernommen"). Eine physische Kopie in die Kindakte waere
  ab dem ersten Umzug still falsch. Geerbt wird nur, solange die
  Abhaengigkeit besteht.
  **UEBERGANG MIT 15** (`familie:uebergaenge-anwenden`, taeglich 05:40): es
  wird NICHTS geloescht, NICHTS neu angelegt und KEIN Vertrag angefasst -
  nur `is_dependent` faellt weg (`independent_since` haelt den Tag fest).
  Die Familienbeziehung BLEIBT (Betreiber-Vorgabe 8): aus "Kind, abhaengig"
  wird "eigenstaendige Kundin, Tochter von Jehad". Timeline-Eintrag + Glocke
  an die Betreuer sagen ausdruecklich, dass Vertraege zu pruefen sind.
  **VORSCHAU + VORBEREITUNG**: `/admin/familie/uebergaenge` ("Kinder werden
  15") listet nach verbleibender Zeit sortiert; Vorlaufzeit 3/6/12 Monate
  (`SystemSetting family_transition_lead_months`, Standard 6, aenderbar nur
  admin/manager). "Übergang vorbereiten" legt eine WIEDERVORLAGE an und
  vermerkt `transition_prepared_at` - mehr nicht (Betreiber-Vorgabe 15:
  keine automatische Vertragsaenderung).
  **BEDIENUNG IM KUNDENPROFIL** (`admin/partials/family_relations.blade.php`,
  Registerkarte "Familie"): "Bestehenden Kunden hinzufügen" sucht ueber
  Name, Kundennummer, GEBURTSDATUM (TT.MM.JJJJ / ISO, neu in
  `Customer::scopeSearch` - die Datums-Bedingung steht am ENDE der
  ODER-Kette, als erste haette sie alle anderen Felder zu einem UND
  gemacht), E-Mail, Telefon und Anschrift; Rolle waehlen, fertig. Die
  Trefferliste wird per `textContent` gebaut (Kundennamen sind Fremddaten).
  Bereits als "verwandt/kein Duplikat" markierte Akten - genau der Bestand
  aus dem Gesundheitskarten-Stapel - stehen als VORSCHLAG oben; die Rolle
  vergibt immer ein Mensch, vorgeschlagen wird nie eine. Portfolio-Scope
  gilt fuer BEIDE Seiten (sonst koennte man ueber eine Verknuepfung einen
  fremden Kunden sichtbar machen). Das Verknuepfen traegt das Paar
  zusaetzlich in `customer_relationships` ein (eine Familie ist keine
  Dublette); das LOESEN entfernt nur die Rolle - "kein Duplikat" bleibt
  wahr, beide Akten bleiben vollstaendig bestehen.
  `customer_family` (Personen OHNE eigene Akte) bleibt unveraendert daneben
  bestehen - beide Listen stehen getrennt beschriftet in der Registerkarte.
  Tests: `CustomerFamilyRelationTest` (Abnahmefaelle 1-17).
- **Auftrag zuerst, Vertrag spaeter: ein Vorgang, EIN Vertrag**
  (Betreiber-Vorgabe 29.07.2026, Details in
  `docs/AUFTRAG_UND_VERTRAG_ZUSAMMENFUEHREN.md`): Zuerst wird der
  AUFTRAG/ANTRAG hochgeladen (viele Daten, aber keine Bestaetigung), Wochen
  spaeter die VERTRAGSBESTAETIGUNG/POLICE mit Vertragsnummer, Kundennummer,
  MaLo-ID, Lieferbeginn und Abschlag. Beide teilen oft KEIN hartes Merkmal
  (EWE-Auftrag nennt nur die Zaehlernummer, die Bestaetigung nur die MaLo-ID)
  - frueher entstanden daraus zwei Vertraege. Neu: `contracts.stage`
  (`antrag`/`vertrag`/null=Altbestand) haelt die Stufe fest;
  `Document::contractStageFor()` leitet sie aus `versicherung.document_stage`
  (Parser/KI), dem Dokumenttyp und dem Vorhandensein einer Vertragsnummer ab.
  `DocumentIntakeService::findApplicationContractForConfirmation()` ergaenzt
  den vorhandenen ANTRAGS-Vertrag statt ein Duplikat anzulegen - streng:
  gleiche Sparte (Strom != Gas), gleiche Gesellschaft, kein Widerspruch in
  MaLo/Zaehler/FIN/Kennzeichen, max. 12 Monate alt; bei mehreren offenen
  Antraegen entscheidet ein Indiz (Tarif/Fahrzeug), sonst wird NICHT geraten.
  Uebernahme: endgueltige Vertragsnummer ersetzt eine vorlaeufige
  Auftragsnummer, leere neue Werte loeschen nie Bestand, Stufe wandert nur
  vorwaerts, jede Aenderung in der Version History + Glocke an den Betreuer
  (und KEINE doppelte Provision). Spaetere Post findet ihren Vertrag ueber
  Vertragsnummer, FIN/Kennzeichen, MaLo, NORMALISIERTE Zaehlernummer
  ("1 LOG00 9228 3078" = "1LOG0092283078", Zaehlerfoto traegt den Stand nach)
  und die Kundennummer beim Versorger. Tests: `ContractConfirmationTest`.
- **AKTIV vs. HISTORIE: die eine Definition** (Betreiber-Vorgabe 17.08.2026,
  Lehre aus "Strom-Symbol zeigte 2 obwohl nur 1 Vertrag laeuft"): Fachregel ist
  „**Aktuelle Vertragsstruktur = ausschliesslich aktuell aktive Vertraege**".
  NIE `status === 'active'` vergleichen - immer `Contract::isCurrentlyActive()`
  (PHP) bzw. den deckungsgleichen Scope `currentlyActive()` (Query); Gegenstueck
  `isHistoric()`/`historic()`, „In Bearbeitung" ist `isPendingStatus()`/
  `inProgress()`. `statusGroup()` liefert genau EINE von drei Gruppen
  (`GROUP_ACTIVE`/`GROUP_PENDING`/`GROUP_HISTORY`) - Gruppen sind disjunkt und
  vollstaendig, `displayStatus()` traegt sie als `group`/`historic` mit, damit
  Badge und Struktur nie widersprechen. Aktiv heisst: Status aktiv UND Deckung
  nicht beendet (`hasCoverageEnded()`: cancelled/expired immer beendet;
  Kuendigung ab dem WIRKSAMEN Ende beendet; E-Scooter NACH Saisonende; ein
  blosses Ablaufdatum ist KEIN Ende - stillschweigende Verlaengerung). Ein
  Vertrag mit Beginn in der Zukunft („Aktiv ab") gehoert zum Bestand; in der
  WECHSEL-KETTE sind Altvertrag („Gekündigt zum X", laeuft noch) und
  Folgevertrag („Aktiv ab X") beide aktiv - die Kundenakte weist den
  auslaufenden Vertrag deshalb ausdruecklich aus, damit die Zahl erklaerbar
  bleibt. Historie bleibt sichtbar (Filter „Beendet / Historie", Zeilen
  ausgegraut + Kennzeichen „Historie – nicht aktiv"), zaehlt aber NIE mit:
  Vertragsstruktur/Zaehler-Badges, Beitragsuebersicht, Dashboard-Kennzahl,
  Kundenliste (Sparten-Filter/-Kennzahl, Vertrags-Icons), Berichte,
  Ablauf-Warnungen, Sparten-Kampagnen, Portal (Zaehler + „Laufende" vs.
  „Beendete Verträge"). Der Tages-Job `contracts:apply-endings` zieht nur den
  GESPEICHERTEN Status nach - er darf die Zahl aktiver Vertraege nie aendern
  (die Anzeige fuehrt den Vertrag vorher schon als beendet). Bewusste
  Ausnahme: das Provisions-Management folgt weiter dem ROHEN Status
  (`ContractProvisionService`: 'active'/'pending' beim Anlegen) - die
  Provision entsteht beim Verkauf, nicht beim Bestandszustand. Status-Auswahl
  im Formular kommt aus `Contract::STATUS_OPTIONS` (sprechende Labels
  „Inaktiv / Gekündigt", „Beendet / Abgelaufen", gruppiert nach Wirkung,
  Live-Hinweis) - dieselbe Liste validiert der Controller
  (`Contract::statusKeys()`). Tests: `ContractStatusLogicTest` (Abnahmefaelle
  1-7 + Parität Modell/Query).
- **Vertrags-Lebenszyklus: schlauer Status, Kuendigung, Wechsel-Automatik**
  (Betreiber-Vorgabe 25./26.07.2026): `cancellation_date` ist das
  EINREICHUNGS-Datum der Kuendigung (Formular-Label "eingereicht am"), der
  Vertrag endet zum Ablauf: `Contract::effectiveCancellationDate()` = Ablauf
  (nie frueher als die Einreichung; ohne Ablauf gilt das erfasste Datum).
  Die deutsche KFZ-Frist (EIN Monat zum Ablauf, 31.12.-Vertrag -> letzter
  Kuendigungstag 30.11.) prueft das Formular als LIVE-HINWEIS (gruen Frist
  gewahrt / rot verpasst inkl. regulaerem Folgejahr-Datum); GESPEICHERT
  wird, was der Betreiber erfasst (Fakten, inkl. Sonderkuendigung - nie
  still "korrigieren"). Anzeige ueberall via `Contract::displayStatus()`
  (eine Quelle): "Gekündigt zum <wirksames Ende>" (orange bis dahin, dann
  rot), "Aktiv ab <Beginn>" (blau) fuer Zukunfts-Vertraege. Der
  GESPEICHERTE status wird taeglich 05:15 von `contracts:apply-endings`
  nachgezogen: erreichtes wirksames Ende -> cancelled, E-Scooter nach
  Saisonende -> expired; beides NATUERLICHE Enden OHNE Provisions-Storno
  (`endsWithoutStorno`); laufende Vertraege ohne Kuendigung bleiben aktiv
  (stillschweigende Verlaengerung - ein blosses Ablaufdatum ist KEIN
  Ende). Statuswechsel stehen als System-Eintrag in der Version History.
  **Doppelversicherungs-Schutz + Wechsel-Automatik**
  (`VehicleOverlapGuard` + `ContractSwitchService`): dasselbe Fahrzeug
  (FIN -> Kennzeichen umlaut-tolerant -> HSN+TSN als letzte Stufe) darf
  nie zwei Vertraege mit ueberschneidendem Zeitraum haben. Neuer Vertrag
  fuer dasselbe Fahrzeug bei ANDEREM Versicherer = WECHSEL: der
  Altvertrag bekommt automatisch die Kuendigung erfasst (eingereicht
  heute, Ablauf = Beginn des neuen; ein fruehere Ablauf bleibt) - greift
  im Admin-Formular (Hinweis in der Erfolgsmeldung) UND im
  Dokumenten-Eingang; ohne Beginn keine Automatik (keine erfundenen
  Daten). GLEICHER Versicherer = Duplikat -> Anlegen wird mit
  handlungsleitender Meldung abgelehnt. Bearbeiten blockiert nur (aendert
  nie still Altvertraege). Die Wechsel-Kette "Gekündigt zum X" -> "Aktiv
  ab X" ist erlaubt (halb-offene Intervalle), Zweitwagen sowieso. Tests:
  `ContractDisplayStatusTest`, `VehicleOverlapGuardTest`,
  `ContractEndingsCommandTest`, `ContractDeduplicationTest`.
- **Aufgaben & Wiedervorlagen** (Vollausbau, Betreiber-Vorgabe 26.07.2026):
  `/admin/tasks` (`TaskController`, alle Staff-Rollen). Kundenauswahl im
  Formular per **Sofort-Suche** (`/admin/tasks/kunden-suche`, Portfolio-
  Scope wie ueberall, KEINE Liste aller Kunden mehr). Wiedervorlage
  ueber Faelligkeits-Praesets (Heute ... +10/+20 Tage/+1 Monat), aus der
  Liste verschiebbar (+1 Tag ... +1 Monat) und voll bearbeitbar (Modal).
  Taeglich 07:45 buendelt `tasks:remind` je Mitarbeiter EINEN
  Glocken-Hinweis (heute faellig / ueberfaellig, dedup je Nutzer).
  **Geplante Auto-E-Mail je Aufgabe**: beim Anlegen optional Betreff/Text
  (Vorlagen + {{platzhalter}}) und Stichtag erfassen - Versand stuendlich
  8-18 Uhr durch `tasks:send-auto-emails` via `DirectEmailMail`;
  Platzhalter werden erst BEIM Versand gerendert, Versand steht in
  Kundenakte (Timeline) + Glocke, `last_contact` wird gesetzt. Erledigte
  Aufgaben versenden NIE (Model-Hook -> Status `skipped`); bereits
  gesendete Mails sind unveraenderlich (Historie). Planen erfordert die
  Composer-Berechtigung (`can_send_emails` bzw. admin/manager/support)
  und einen Kunden mit ECHTER E-Mail-Adresse. Statusverlauf:
  `auto_email_status` pending/sent/skipped/failed (failed erst nach 3
  Tagen Retry). `tasks.type` ist jetzt String statt ENUM (Typ 'reminder'
  des Geburtstags-Jobs war im ENUM ungueltig); gueltige Typen zentral in
  `Task::TYPES`. Kundenakte-Header hat Button "Aufgabe / Wiedervorlage"
  (oeffnet das Modal vorbefuellt). Tests: `TaskSystemTest`.
- **Kundenaenderungen: Nachweispflicht + automatische Pruefung +
  Mitteilungen an Gesellschaften** (Betreiber-Vorgabe 29.07.2026, Details
  in `docs/NACHWEIS_KUNDENAENDERUNGEN.md`): Sensible Self-Service-
  Aenderungen (Bankverbindung, Anschrift, Name/Geburtsdatum) werden nur
  MIT Nachweis angenommen - Kontonachweis, Meldebescheinigung oder Ausweis
  (`ChangeProofPolicy` ist die eine Quelle dafuer, wer was braucht); der
  Kunde erfasst zusaetzlich "Gueltig ab" (`effective_from`, nie geraten).
  Nachweise liegen auf der PRIVATEN Disk unter
  `customers/{id}/nachweise` (Kundenloeschung raeumt sie mit weg), Zugriff
  nur ueber `admin.change_requests.proof` + Portfolio-Policy.
  `ChangeProofVerifier` (Job `VerifyChangeRequestProofJob`) liest den Beleg
  "kostenlos zuerst" (PDF-Textebene, sonst OCR - KEIN KI-Aufruf) und prueft
  nur, ob der BEANTRAGTE Wert darin steht: IBAN exakt bzw. OCR-tolerant
  (O/0, I/1, S/5), Adresse umlaut- und "str./strasse"-tolerant, Name
  ueber Namensteile. Rohtext wird NIE gespeichert (Datenminimierung), nur
  das Ergebnis (`proof_status` verified/partial/mismatch/unreadable/
  missing + Pruefpunkte). Automatische Freigabe nur bei `verified` und nur
  nach Einstellung (Einstellungen -> Kundenaenderungen; Standard: Adresse/
  Name automatisch, BANK bleibt Vier-Augen-Prinzip - ein Treffer belegt den
  Inhalt des Dokuments, nicht seine Echtheit); jede Uebernahme meldet die
  Glocke. Nach der Freigabe entsteht je Gesellschaft des Kunden EIN
  fertiger Entwurf (`InsurerNotificationBuilder`, Tabelle
  `change_notifications`, Seite `/admin/change-requests/{id}/mitteilungen`)
  mit alter/neuer Angabe, Vertragsnummern und "gueltig ab"; Versand ist
  IMMER eine bewusste Mitarbeiter-Aktion (Nachweis optional als Anhang,
  Protokoll in der Kundenakte), Alternativen "per Post/Portal erledigt".
  Rueckfragen gehen mit einem Klick als Chat-Nachricht raus und fuehren
  direkt in die Unterhaltung (`admin.change_requests.ask` -> Kundenchat).
  Tests: `ChangeRequestVerificationTest`.
- **Zaehlerstand + Verbrauchshistorie** (Betreiber-Vorgabe 29.07.2026,
  Details in `docs/ZAEHLERSTAND_VERBRAUCHSHISTORIE.md`): Ein Foto des
  Stromzaehlers genuegt - `MeterPhotoReader` liest KOSTENLOS (OCR/Textebene,
  KI nur als Eskalation) Zaehlernummer und Stand; die Nummer ist die Bruecke
  zum erfassten Energievertrag und damit zum Kunden (`MeterReadingService::
  locate()`, exakt oder Teiltreffer "92283078" = "1LOG0092283078", Tier
  `auto` wie eine Vertragsnummer). Jede Ablesung ist eine eigene Zeile
  (`meter_readings`, analog `vehicle_mileage_readings`) mit Wert, Einheit
  (kWh/m³), OBIS-Zaehlwerk, Ablesetag und EXAKTEM Meldezeitpunkt
  (`captured_at` = Upload-Zeitpunkt), Quelle (staff/customer/document) und
  dem Foto als Beleg; `contract_energy_details.meter_reading` bleibt als
  "aktueller Stand" und wird mitgefuehrt. Verbrauch =
  Differenz zweier Staende (`consumptionHistory()`/`consumptionStatus()`)
  inkl. Tagesschnitt und Jahres-Hochrechnung gegen den vereinbarten
  Verbrauch (erst ab 14 Tagen Zeitraum - kuerzer wird NICHT hochgerechnet).
  Bezug (1.8.0) und Einspeisung (2.8.0, Zweirichtungszaehler/PV) strikt
  getrennt. Regeln: mehrere Kunden zur selben Nummer -> KEINE Zuordnung
  (nie raten); ohne lesbaren Stand KEINE Ablesung; ein niedrigerer Stand
  wird gespeichert, markiert und ueberschreibt den Bestand nicht;
  identische Meldung nicht doppelt (idempotent). Anzeige: Portal-Karte
  „Zählerstand & Verbrauch" (Wert und/oder Handy-Foto melden) und
  Energie-Cockpit auf der Vertrags-Bearbeiten-Seite; Loeschen einer
  Ablesung nur admin/manager. Tests: `MeterReadingTest`.
- **Kunden-Zusammenfuehrung** (`CustomerMergeService`, Lehre 06.08.2026 -
  gemeldeter Datenverlust nach Duplikat-Merge): Der Merge haengt JEDE Tabelle
  mit customer_id um (Schema-Abgleich) PLUS die Sonderfaelle, die daran
  vorbeilaufen: `customer_relationships` (customer_a_id/customer_b_id -
  Familie/Haushalt; Paar neu normalisiert a<b, Selbst-Paar entfaellt,
  Kollisionen dedupliziert - sonst reisst die FK-Kaskade die
  Familien-Verknuepfungen mit) und der PORTAL-ZUGANG: Name/Login-E-Mail
  liegen am USER, nicht am Kunden. Der besser gepflegte Account ueberlebt
  IMMER (echte E-Mail schlaegt `import-...@dienstly24.internal`-Platzhalter,
  dann Passwort gesetzt/Logins/Einladung), egal in welcher Richtung
  zusammengefuehrt wird - der Hauptkunde uebernimmt notfalls den User des
  Duplikats (inkl. dessen Portal-Sprache). Die Login-Adresse des
  unterlegenen Accounts wandert nach email2 (Platzhalter nie); geloescht
  wird der unterlegene User nur, wenn KEINE Kundenakte mehr auf ihn zeigt
  (customers.user_id kaskadiert!). Marketing-Abmeldung des Duplikats wirkt
  fort (DSGVO-Opt-out geht nie verloren), `last_contact` nimmt den neueren
  Stand. Bulk-/Auto-Merge vereint weiterhin in den AELTESTEN Datensatz
  (Kundennummern-Kontinuitaet) - der Portal-Account wandert dank Adoption
  trotzdem mit. Tests: `CustomerMergeDataPreservationTest`.
- **Neukunden-Bericht + Vermittler-Provisionen** (Betreiber-Vorgabe
  25.07.2026): `/admin/reports/neukunden` (`ReportController::newCustomers`,
  Tab auf der Berichte-Seite) zeigt die Neukunden des Monats (blaetterbar,
  freier Zeitraum moeglich) mit ANLEGER (`customers.created_by`, wird beim
  Erstellen automatisch auf den angemeldeten Mitarbeiter gesetzt -
  Creating-Hook im Customer-Modell) und WERBER: Mitarbeiter
  (`acquired_by`) ODER Partner (`acquired_by_partner_id`), exklusiv.
  Bewusst NICHT `partner_id` wiederverwendet - das steuert den
  Partner-Portal-Zugriff, Werber-Attribution darf keine Datensicht
  eroeffnen (DSGVO). Jede Zeile fuehrt in die Kundenakte; Vertraege zeigen
  Gesellschaft (insurer), Beginn/Ende, Status mit Link. admin/manager
  setzen Werber + Mitarbeiter-Sichtbarkeit (= Betreuer-Sync
  `employee_customers`) direkt aus der Liste (Popover); Mitarbeiter sehen
  nur ihr Portfolio, ohne Verwaltungs-Controls. Leaderboard „Wer hat wie
  viele gebracht" + Provisions-Vorschau: Saetze je Mitarbeiter/Partner
  (`provision_fixed` EUR je Neuvertrag, `provision_percent` % vom
  Jahresbeitrag; gepflegt in Mitarbeiter-Bearbeiten bzw. Partnerakte).
  Vorschlag wird per Klick als `Provision` erfasst (Tabelle `provisions`,
  AUSGANG an eigene Vermittler - NICHT verwechseln mit `commissions` =
  EINGANG Gutschriften). Verwaltung unter `/admin/provisionen`
  (`ProvisionController`, Tabs mit der Gutschriften-Seite). Tests:
  `NewCustomerReportTest`.
- **Provisions-Management (Vollausbau, Betreiber-Vorgabe 25.07.2026)**:
  Provisionen entstehen AUTOMATISCH bei jeder Vertragsanlage (Formular,
  Dokumenten-Eingang, Imports, CLI - zentraler Hook im Contract-Modell ->
  `ContractProvisionService`). Empfaenger ist der WERBER des Kunden
  (`acquired_by` XOR `acquired_by_partner_id`); der Betrag kommt aus dem
  SPARTEN-Satz (`provision_rates`: je Mitarbeiter/Partner je Sparte fix +
  Prozent vom Jahresbeitrag, Pflege unter `/admin/provisionen/saetze`),
  Fallback globaler Satz am Empfaenger. Ohne Werber oder Satz KEINE Buchung
  (nie Betraege erfinden); Werber nachtraeglich setzen bucht offene
  Vertraege nach (Idempotenz: je Vertrag genau EINE Neuvertrag-Provision).
  Workflow offen -> freigegeben -> ausgezahlt (oder storniert), Statuswege
  begrenzt (`updateStatus`). Storno-Regel (praezisiert 26.07.2026): die
  Provision gibt es EINMALIG je Verkauf - ein NATUERLICHES Vertragsende
  (Wechsel-Kette, Tages-Job `contracts:apply-endings` stellt cancelled;
  Flag `Contract::$endsWithoutStorno`) bucht KEIN Storno, die Provision
  bleibt verdient. Nur MANUELLE Stornierung (Formular status=cancelled)
  oder Loeschung eines Vertrags erzeugt die automatische NEGATIVE
  Gegenbuchung (type=storno, `related_provision_id`) - Originale werden
  NIE geloescht (Finanzhistorie; Kunden-Purge per FK-Kaskade bucht
  bewusst NICHT).
  Betrags-Anpassung/Bonus/Abzug nur mit Grund; JEDE Aenderung steht im
  unveraenderlichen `provision_audit_logs` (wer/wann/alt/neu/Grund).
  Monatsbericht `/admin/provisionen/bericht` (je Empfaenger: Neukunden,
  Vertraege je Sparte, Provision/Abzuege/Netto) mit Export Excel
  (`XlsxWriter`, ohne Fremdpaket, CSV-Fallback) + PDF (Druckansicht);
  Leistungs-Dashboard `/admin/provisionen/dashboard`. ALLES nur
  role:admin,manager - Mitarbeiter/Partner sehen keinerlei Betraege,
  Saetze, Berichte oder Statistiken; KEINE Benachrichtigungen an
  Empfaenger (interner Prozess). Tests: `ProvisionManagementTest`.

## Offene Themen / wartet auf den Betreiber

- **OCR auf dem VPS ist aktiv** (Stand 18.07.2026): `tesseract-ocr`,
  `tesseract-ocr-deu`, `tesseract-ocr-ara`, `poppler-utils` sind installiert,
  `OCR_ENABLED=true` und `OCR_LANGUAGES=deu+eng+ara` in der Produktions-`.env`
  gesetzt. Der Smart Document Upload laeuft damit „kostenlos zuerst" (OCR,
  Eskalation zu Claude nur bei Bedarf). Kein offener Punkt mehr - hier nur als
  Betriebszustand dokumentiert.

- **E-Mail-Zustellbarkeit (Spam bei Outlook):** SPF, DKIM und DMARC sind
  inzwischen **korrekt gesetzt** (geprüft 14.07.2026: SPF `include:_spf.mail.hostinger.com`,
  DKIM `hostingermail1._domainkey` = verifiziert, DMARC `p=none`). Die frühere
  Annahme „DKIM leer (`p=`)" ist damit überholt. Verbleibendes Thema ist die
  **Reputation der neuen Absender-Domain** (v. a. Microsoft/Outlook):
  aufwärmen, „Kein Spam"/Kontakt-Signal, Microsoft SNDS/JMRP. Nächster
  Schritt: Testversand an mail-tester.com. Details + Checkliste in
  `docs/EMAIL_ZUSTELLBARKEIT_SPF_DKIM_DMARC.md`.
- **Rechtsseiten liegen jetzt in der App** (`/impressum` etc., seit dem
  Website-Merge 30.07.2026) - der fruehere Punkt "WordPress-Rechtsseiten
  fuellen" ist damit erledigt. Offen bleibt die finale PRUEFUNG der Texte
  durch Rechtsanwalt/Datenschutzbeauftragten vor dem Livegang (Hinweise
  stehen als Kommentare in den Views).
- **Website-Go-Live** (nach dem Merge): DNS/vHost/Cloudflare-Umzug von
  www.dienstly24.de auf den VPS + `APP_URL` setzen + einmalige
  Reparatur-Befehle - Checkliste in `docs/WEBSITE_MERGE_UMSETZUNG.md`.
  Ausserdem vom Betreiber zu bestaetigen: schriftliche Freigaben der
  zitierten Kundenstimmen + Belegbarkeit "3.000+ Kunden" (UWG). 2FA fuer
  /admin und Cloudflare Turnstile sind bewusst offene Folgepakete.
- **KI-Kundenassistent: Inbetriebnahme** (Code ist fertig, Stand
  17.08.2026). Vom Betreiber zu erledigen, in dieser Reihenfolge:
  1. KEIN neuer Schluessel noetig: der Assistent laeuft im Standard ueber
     Claude und nutzt den bereits gesetzten `ANTHROPIC_API_KEY`. Nur
     pruefen, dass er in der Server-`.env` unter
     `/var/www/dienstly24/portal` steht (NIE ins Repo/den Chat). Nur wer
     stattdessen OpenAI will, braucht Konto + `OPENAI_API_KEY` +
     `AI_ASSISTANT_PROVIDER=openai` + eigenen AV-Vertrag.
  2. Wissensbasis fuellen: `/admin/ki-wissensbasis` - solange sie leer ist,
     beantwortet der Assistent nur, was aus der Kundenakte belegbar ist,
     und uebergibt alles andere an das Team (das ist ein sicherer, aber
     wenig hilfreicher Zustand). Startpunkt auf dem Server:
     `php artisan ki:wissensbasis-vorschlag --schreiben` uebernimmt die
     Texte der Leistungsseiten woertlich als INAKTIVE Entwuerfe; danach
     im Admin lesen, anpassen und freigeben (Filter „Nur Entwürfe").
  3. Erst danach in `/admin/settings` -> „🤖 KI-Kundenassistent" den
     Hauptschalter einschalten (Voreinstellung ist AUS).
  4. Nach jedem Schritt `php artisan ki:pruefen` auf dem Server laufen
     lassen - der Befehl nennt das jeweils naechste fehlende Glied;
     `--live` beweist zusaetzlich Schluessel/Endpunkt/Modell.
  5. Queue-Worker muss laufen (die Antwort ist ein Job); ohne Worker greift
     nach 10 Min das Sicherheitsnetz `ai:answer-pending`.
  Rechtlich noch zu klaeren, BEVOR der Schalter auf produktiv geht:
  Auftragsverarbeitungsvertrag/DPA mit dem genutzten Anbieter (bei Claude
  besteht die Beziehung wegen der Dokumentanalyse bereits - dann ist nur zu
  pruefen, ob der Vertrag die Kundenkommunikation mit abdeckt),
  Aufbewahrungsoptionen der API, Ergaenzung von Datenschutzerklaerung und
  Verarbeitungsverzeichnis (Hinweis im Chat, dass zunaechst ein Assistent
  antwortet, ist technisch umgesetzt).
- **Finale Logo-Dateien** kommen vom Betreiber (bevorzugt SVG, sonst PNG
  transparent ≥320px hoch; Light- und Dark-Variante; optional 512×512 Icon).
- **Partner-Portal** (voller Ausbau) und **E-Mail-Einwilligung des Kunden
  (Variante B)**: Konzepte in `docs/KONZEPT_PARTNER_GESCHAEFTSMODELL.md` und
  `docs/KONZEPT_EMAIL_EINWILLIGUNG_DSGVO.md` — warten auf Entscheidungen des
  Betreibers, noch nicht bauen.

## Weitere Doku

Ausführliche Berichte und Konzepte liegen unter `docs/` (Audit, Phasen,
Production-Readiness, Konzepte). Bei Bedarf dort nachschlagen.
