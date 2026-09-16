# System-Audit 15.09.2026 — Befunde und Behebung

Dieser Bericht gehoert zum Auftrag "vollstaendige Pruefung, danach
vollstaendige Behebung". Er beschreibt JE BEFUND: was war, warum es ein
Problem war, was geaendert wurde und **womit es bewiesen ist**. Ein
"behoben" ohne Beweis steht hier nicht.

## Grundsatz

Kein Rewrite, kein grosser Refactor, keine geaenderte Geschaeftslogik,
keine entfernte Funktion. Routen, APIs und Bedienwege sind unveraendert,
soweit die Behebung es nicht zwingend verlangte. Jede Aenderung ist
klein, abgesichert und einzeln zurueckdrehbar.

## Zahlen

| | |
|---|---|
| Tests | 2852, davon 2845 bestanden, 0 fehlgeschlagen, 7 uebersprungen |
| Zusicherungen | 12 023 |
| PHPStan (Stufe 5) | 0 Fehler, Baseline 307 -> 290 |
| Pint | bestanden |
| `composer audit` | 0 Schwachstellen |
| `npm audit` | 0 Schwachstellen |
| `npm run build` | erfolgreich (CSS 93,83 kB, JS 4,45 kB) |
| Browser (Desktop/Tablet/Handy) | 15 Seitenaufrufe, keine Konsolenfehler, kein Querscrollen, jede Seite mit `<h1>`, jeder JSON-LD-Block gueltig |

## P0

### 1. JSON-LD zerstoerte sich selbst — auf JEDER oeffentlichen Seite

**War:** Laravel 13 kennt eine Blade-Direktive `@context`. Der
Pflichtschluessel eines JSON-LD-Blocks heisst `'@context'`. Blade hat ihn
in jeder betroffenen Vorlage als Direktive uebersetzt; ausgeliefert wurde
roher `<?php`-Text mitten im HTML und ein unlesbares Snippet fuer
Suchmaschinen. Betroffen waren alle oeffentlichen Seiten (Startseite +
Leistungsseiten, 44 Bloecke).

**Warum schlimm:** Das faellt im Alltag nicht auf — die Seite sieht
normal aus. Nur Google sieht Unsinn, und der `<?php`-Text im Quelltext
ist ausserdem ein Informationsleck.

**Fix:** Die Daten stehen jetzt in `App\Services\Seo\StructuredData`
(reines PHP — dort ist `'@context'` schlicht eine Zeichenkette) und
werden als fertiges `<script type="application/ld+json">` mit
CSP-Nonce ausgegeben. In den Vorlagen steht kein `@context` mehr,
auch nicht in Kommentaren.

**Beweis:** `StructuredDataTest` (7 Faelle) prueft im AUSGELIEFERTEN
HTML, dass kein `<?php` vorkommt und jeder JSON-LD-Block gueltiges JSON
ist. Zusaetzlich im echten Browser auf drei Geraetegroessen bestaetigt.

### 2. Sichtbarkeit von Kunden hatte mehrere Wahrheiten

**War:** Dieselbe Frage ("welche Kunden darf dieser Mitarbeiter sehen?")
wurde an mehreren Stellen unterschiedlich beantwortet. Eine Fassung
vergass die **Vertretung** — der Vertreter sah den Kundenchat des
vertretenen Kollegen nicht. `canSeeCustomer()` war toter Code.

**Fix:** EINE Quelle: `User::canSeeAllCustomers()`, `visibleOwnerIds()`,
`canAccessCustomer()` und der dazu deckungsgleiche Query-Scope
`Customer::scopeVisibleTo()`. Der Scope arbeitet mit `whereExists` statt
einer `whereIn`-Liste ueber tausende Kunden-IDs.
`ScopesCustomerAccess` benutzt beide. Toter Code entfernt.

**Bewusst NICHT gecacht:** Ein Versuch, die Sichtbarkeit je Request zu
merken, liess `TaskSystemTest` fallen — nach einer Zuweisung im selben
Request war die gemerkte Antwort falsch. Eine veraltete
Berechtigungsantwort ist schlimmer als eine zusaetzliche Abfrage.

**Beweis:** `CustomerVisibilityTest` (14 Faelle) prueft jede Rolle
(admin, manager, support, employee, Vertretung, Kunde, Partner) und
haelt fest, dass Methode und Query IMMER dasselbe sagen.

### 3. Das Auswertungs-Dashboard rechnete je Datenzeile neu

**Fix:** `DashboardAnalyticsService::verlauf()` berechnet die
Zeitraumgrenzen EINMAL vor der Schleife.

**Messung:** 620,5 ms -> 18,7 ms. Die Ergebnisse sind in allen drei
Granularitaeten (Tag/Woche/Monat) unveraendert — das wurde vor und nach
der Aenderung verglichen, nicht angenommen.

### 4. Der oeffentliche Website-Assistent hatte keine Kostenbremse

**War:** `POST /api/website-assistent` ist oeffentlich, verlangt keine
Anmeldung und ruft je Nachricht das Modell. Geschuetzt war er nur durch
eine Drossel je IP — rechnerisch 28 800 Modellaufrufe am Tag aus einer
Quelle, mit wechselnden Adressen beliebig viele. Ausgerechnet der
oeffentliche Weg hatte keine Grenze, der angemeldete Portal-Assistent
laengst.

**Fix:** `App\Services\Ai\Assistant\AssistantBudget` — Grenzen je IP, je
Sitzung und global je Tag, geprueft **vor** dem Modellaufruf. Eine
erreichte Grenze verhindert den Aufruf, sie verwirft nicht die Antwort.
Der Besucher bekommt die vorhandene Rueckfallebene und eine Uebergabe
an das Team; protokolliert wird nur Bereich und Art, kein Inhalt.

**Beweis:** `AssistantBudgetTest` (12 Faelle), u. a. dass bei erreichter
Grenze KEIN HTTP-Aufruf ans Modell hinausgeht.

### 5. Sicherung war keine Sicherung

**War:** Ein Dump ohne Verschluesselung, ohne zweiten Speicherort, ohne
Pruefung, ohne Alarm. Und ohne je bewiesene Rueckspielbarkeit.

**Fix:**
- `scripts/backup.sh`: GPG (AES-256), Groessen- und gzip-Pruefung,
  inhaltliche Pruefung des Dumps (`CREATE TABLE` gezaehlt), externe Kopie
  per rclone MIT Rueckpruefung und Rotation, Statusdatei ueber ein
  `trap ... EXIT` (auch bei Abbruch). Ohne Passphrase wird eine externe
  Kopie VERWEIGERT.
- `scripts/restore.sh --pruefen` spielt das Archiv in eine ANDERE
  Datenbank ein, zaehlt Tabellen und verlangt, dass Kunden, Vertraege,
  Nutzer, Dokumente und Vorgaenge vorhanden sind. Die Produktions-
  datenbank aus der `.env` lehnt das Skript ausdruecklich ab.
- `/admin/systemzustand` hat den Abschnitt "Sicherung". Ein ALTER Erfolg
  gilt nach 48 Stunden nicht mehr als Erfolg — sonst stuende nach einem
  ausgefallenen Cron fuer immer "ok" da.

**Zwei Fehler in meinem eigenen Skript, beide erst im Lauf gefunden:**
`pipefail` zusammen mit einem frueh beendenden `grep -q` liess die
Pruefung einer GUTEN Sicherung scheitern (SIGPIPE); und `set -e` brach
bei einer bedingten Zuweisung ab, waehrend die Statusdatei noch "ok"
sagte.

**Beweis:** `BackupHealthTest` (12 Faelle) inklusive Gegenproben —
falsches Passwort scheitert, Produktionsdatenbank wird abgelehnt.
Anleitung: `docs/BACKUP_UND_WIEDERHERSTELLUNG.md`.

## P1

### 6. Private Platte lieferte selbst aus
`config/filesystems.php`: `local` bekommt `'serve' => false`. Jeder
Zugriff auf Kundenunterlagen laeuft damit zwingend durch einen
Controller mit Berechtigungspruefung.

### 7. Externe Ueberwachung
`/gesundheit` mit `HealthToken`-Middleware (Token aus der `.env`),
gedrosselt (60/min). Antwort: Ampel und Kurzfassung — nie ein Wert, nie
ein Geheimnis. HTTP 503, wenn etwas handlungsbeduerftig ist.
Beweis: `HealthEndpointTest` (12 Faelle), u. a. dass ohne Token 404
kommt (nicht 401 — ein 401 bestaetigt die Existenz des Endpunkts).

### 8. `retry_after` war kleiner als die Laufzeit der Jobs
**Der gefaehrlichste Fund dieses Audits, und er war latent:** Fuer Redis
stand der Laravel-Standard 90 s, waehrend sieben Jobs bis zu 300 s
brauchen. Der in `docs/ANLEITUNG_REDIS_AR.md` beschriebene Umzug haette
jeden davon nach 90 s ein zweites Mal gestartet, waehrend er noch lief —
**Instagram-Beitraege doppelt veroeffentlicht, E-Mail-Kampagnen zweimal
verschickt.** Jetzt 360 s. Der Kundenimport (bis 30 Minuten) laeuft auf
einem EIGENEN Anschluss (`database-lang` / `redis-lang`, Warteschlange
`lang`, `retry_after` 2100) mit eigenem Worker.
Beweis: `QueueTimeoutTest` (6 Faelle) prueft die Regel fuer BEIDE
Treiber und erzwingt sie fuer jeden Job mit `$timeout`.

### 9. Barrierefreiheit
`<h1>` auf allen Admin-Seiten, `/register` und `/hilfe`; Beschriftung
bzw. `aria-label` fuer alle Felder; Tastaturbedienung und Fokus.
Honeypot-Felder bleiben bewusst `aria-hidden` und unbenannt — eine
Beschriftung waere die Anleitung zum Umgehen.
Beweis: `BarrierefreiheitTest` (39 Faelle) + Browserpruefung auf drei
Geraetegroessen.

**Zwei Fehler auf dem Weg dorthin, beide von der vorhandenen Testsuite
gefangen:** eine Ersetzung mit `<input[^>]*>` zerbrach fuenf Vorlagen,
weil sie am `>` in `{{ $x->y }}` endete; eine zweite an
`@disabled(...)`. Die Ersetzung laeuft jetzt Blade-bewusst, und ein
Waechter-Test prueft die QUELLTEXTE.

### 10. Netz/VPS
`scripts/netz-pruefen.sh` prueft **nur lesend**: Edge/CDN, Erreichbarkeit
des Origin an der CDN vorbei, offene Ports, ufw/nftables, SSH-Konfig,
Client-IP. `--vorschlag` gibt einen sicheren ufw-Regelsatz AUS, aktiviert
aber nichts: SSH zuerst, `--dry-run enable`, und der ausdrueckliche
Hinweis, eine zweite Sitzung offen zu halten. Eine Firewall blind
einzuschalten kann den Zugang zum Server kappen — das bleibt eine
Entscheidung des Betreibers.

## P2

- **11. Chat holte bei jeder Abfrage den ganzen Verlauf.**
  `App\Support\ChatFeed`: seitenweise (50, "aeltere laden") und danach
  nur das Neue ab einem Stand (`?seit=`). Messung: **473,0 kB -> 0,1 kB**
  je Abfrage (Faktor 4037); bei 120 Abfragen/Minute 55,4 MB/min -> 0,01
  MB/min. Beweis: `ChatFeedTest` (13 Faelle), inklusive grossem Verlauf.
- **12. Sichtbarkeit skaliert** — `whereExists` statt `whereIn` ueber
  alle IDs (siehe P0-2). Messung der Zugriffspruefung: 1,2 ms -> 0,7 ms;
  auf SQLite ist das ehrlicherweise wenig, der Gewinn liegt im Wegfall
  der wachsenden IN-Liste.
- **13. Unbegrenzte `->get()`** je Fall entschieden: Grenze, Seiten oder
  eine Begruendung im Code (z. B. Banner: gepflegte, kleine Menge, und
  sortiert wird nach einer Summe, die keine Spalte ist — ein Limit waere
  dort nicht "die besten N", sondern irgendwelche N).
- **14. Einheitliches Muster fuer lange Jobs** (Zeitlimit, `retry_after`,
  Versuche, Fehlerbehandlung) — siehe P1-8.
- **15. Frontend-Material**: Kopfzeilen-Logo 124 kB -> 24 kB mit festen
  Massen (kein Umbruch beim Laden); Chart.js laedt nur noch auf Seiten,
  die ein Diagramm haben (`@stack('charts')`): Admin-Seiten ohne Diagramm
  452 kB -> 152 kB (-66 %). Beweis: `AssetLadungTest` (15 Faelle).
- **16. Redis** bleibt eine Server-Entscheidung. Vorbereitet, dokumentiert
  (`docs/ANLEITUNG_REDIS_AR.md`), umkehrbar — und um den Fund aus P1-8
  ergaenzt, ohne den der Umzug Schaden angerichtet haette.

## P3

- **17. Statische Analyse.** PHPStan Stufe 5 ist gruen; Baseline 307 ->
  290, ausschliesslich durch behobene Ursachen. **Drei echte Fehler**
  derselben Bauart kamen dabei ans Licht — ein Zugriff auf eine
  Eigenschaft, die es gar nicht gibt, was PHP klaglos als `null`
  liefert:
  1. `Customer::$email` existiert nicht (die Adresse haengt am Benutzer).
     Der Verkaufsassistent hielt die E-Mail deshalb IMMER fuer unbekannt
     und fragte auch den Bestandskunden danach — ausgerechnet die Regel
     "NIE ZWEIMAL FRAGEN".
  2. Dieselbe Eigenschaft in der stillen Pruefung: die genannte Adresse
     wurde nur gegen die ZWEITadresse gehalten. Nannte der Kunde seine
     richtige Hauptadresse, galt sie als "weicht ab" — der Vorgang ging
     an einen Mitarbeiter, obwohl alles stimmte.
  3. `Document::$mime_type` existiert nicht (die Tabelle fuehrt keine
     mime-Spalte). Jedes Dokument galt als `application/octet-stream`,
     womit der Rohtext-Weg nie die kostenlose, fehlerfreie PDF-Textebene
     benutzte, sondern immer die OCR.
  Beweis: `StatischeAnalyseFundeTest` (9 Faelle). Gegenprobe gemacht: mit
  zurueckgedrehtem Fix faellt der Test.
  **Verworfen:** die Carbon-Makros (`->lokal()`, 11 Eintraege) ueber eine
  PHPStan-Stub-Datei zu beschreiben. Eine Stub-Datei ERSETZT die Klasse;
  danach fehlten Carbon alle uebrigen Methoden und es entstanden 35
  Folgefehler. Der Verzicht ist gemessen, nicht geraten.
- **18. Fuenf ungenutzte Messaging-Ausnahmen** entfernt — tote
  Architektur sieht wie ein Vertrag aus, den niemand erfuellt.
- **19. Policies.** "4 Policies bei 98 Modellen" war ein Fehlalarm: das
  Projekt prueft ueberwiegend anders (Rolle an der Route, Recht an der
  Route, Portfolio-Scope im Controller, je Bereich ein Helfer). Die vier
  Policies decken genau die Objekte, deren Eigentuemer sich NICHT aus dem
  Kunden ergibt. 98 Policies waeren eine zweite Wahrheit neben der
  vorhandenen Pruefung. Was fehlte, war der NACHWEIS:
  `ZugriffspruefungTest` geht alle Routen durch, die fuer die breite
  Personalrolle offen sind und einen Datensatz ueber den Pfad annehmen,
  und verlangt fuer jede eine erkennbare Pruefung. **Ergebnis: keine
  ungeschuetzte Route.** Ausnahmen (Datensaetze ohne Eigentuemer, etwa
  Medien-Slots) stehen namentlich im Test, damit die Ausnahme eine
  Entscheidung bleibt.
- **20. `laravel/breeze`** entfernt (ungenutzt). Achtung fuer die
  Zukunft: das Paket war zusaetzlich in `bootstrap/cache/packages.php`
  gecacht — ohne das Leeren fielen 39 Tests aus.
- **21. `SESSION_SECURE_COOKIE`** produktionssicher voreingestellt.
- **22. CLAUDE.md** nachgezogen (Postfach und WhatsApp standen dort als
  "noch nicht gebaut", obwohl sie produktiv laufen) und um eine
  Definition of Done ergaenzt.

## Was der Betreiber entscheiden muss

1. **`users.can_see_all_customers` steht in der Migration auf `true`.**
   Jedes NEUE Personalkonto sieht damit den gesamten Kundenbestand, bis
   jemand den Haken entfernt. Das ist keine Luecke im Code, sondern eine
   Voreinstellung — und sie ist bewusst NICHT still geaendert worden: die
   Umstellung wuerde das Portfolio-Verhalten fuer neue Konten aendern und
   ist deshalb eine Entscheidung des Betriebs, keine des Audits.
   Empfehlung: auf `false` stellen und die Sicht ausdruecklich vergeben.
2. **Netz/Firewall (SEC-2)** — siehe P1-10: das Pruefskript liegt bereit,
   das Einschalten bleibt beim Betreiber.
3. **Veraltete Pakete** (keine Sicherheitsfrage, `composer audit` ist
   sauber): larastan 3.12.0 -> 3.12.1, laravel/framework 13.31.0 ->
   13.32.0, phpunit 12 -> 13 (Hauptversion, eigener Vorgang).
