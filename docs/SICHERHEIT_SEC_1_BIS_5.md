# Sicherheits-Haertung SEC-1 bis SEC-5

Stand: 03.09.2026

> **Fuer den Betreiber gibt es dieselben Schritte auf Arabisch:**
> `docs/ANLEITUNG_SICHERHEIT_AR.md` - Turnstile einrichten,
> Datenschutzerklaerung ergaenzen, Origin pruefen.

Zusammenfassung der fuenf Audit-Punkte: was war der eigentliche Fehler,
was wurde geaendert, wie ist es abgesichert - und was ausdruecklich
offen bleibt.

---

## SEC-1 - Oeffentliche Selbst-Registrierung

### Ursache

`POST /register` war oeffentlich und legte in EINEM Schritt an:

* einen `User` mit `role = customer`,
* eine vollstaendige Kundenakte,
* eine **laufende Kundennummer** (`CustomerNumberGenerator::generate()`,
  Schema JJ + 5-stellig),
* und meldete den Absender sofort an.

Geprueft wurde dabei nur ein Honeypot-Feld und ein Route-Throttle. Es gab
**keinen Beweis, dass die E-Mail-Adresse dem Absender gehoert** - und
keinen serverseitigen Bot-Schutz. Ein Skript konnte damit in Serie echte
Kundenakten erzeugen.

Zur Kundennummer eine Praezisierung, weil sie im Auftrag als
"auto-increment" beschrieben war: `generate()` bildet die naechste Nummer
aus dem MAXIMUM der vorhandenen Nummern des Jahres, nicht aus einer
Datenbank-Sequenz. Eine geloeschte Karteileiche gibt ihre Nummer also
theoretisch wieder frei. Das aendert am Befund nichts: solange die
Datensaetze stehen, sind die Nummern belegt, der Bestand ist mit
Karteileichen durchsetzt, und **loeschen muesste jemand von Hand** - im
Zweifel jemand, der erst durch einen Kundenanruf davon erfaehrt.

### Aenderung

Die Registrierung ist jetzt **zweistufig**:

| Schritt | Was passiert | Was NICHT passiert |
|---|---|---|
| `POST /register` | Bot-Schutz, Validierung, Eintrag in `pending_registrations`, Bestaetigungsmail | kein User, keine Kundenakte, **keine Kundennummer**, keine Sitzung |
| `GET /register/bestaetigen/{token}` | User + Kundenakte + Kundennummer, Anmeldung | — |

**Warum nicht `User implements MustVerifyEmail`?** Das ist der
naheliegende Laravel-Weg, waere hier aber falsch gewesen:

1. Das Konto existierte weiterhin vor der Bestaetigung - also ein
   Login-Ziel und ein Enumerationsziel.
2. Die Kundennummer waere weiterhin sofort verbraucht - der eigentliche
   Punkt des Befunds.
3. Alle ueber die Beraterwelt **eingeladenen Bestandskunden**, die sich
   nie selbst registriert haben, waeren schlagartig "unbestaetigt"
   gewesen. Ein Massenausfall im Kundenportal fuer ein Problem, das nur
   die Selbst-Registrierung hat.

Weitere Punkte:

* **Cloudflare Turnstile**, `App\Services\Security\TurnstileVerifier`.
  Geprueft wird **serverseitig** gegen `siteverify` - ein Bot spricht
  ohnehin nicht mit dem Browser-JavaScript, sondern direkt mit dem
  Endpunkt. **Bei Ausfall wird ABGELEHNT**, nicht durchgewunken (anders
  als beim HaveIBeenPwned-Abgleich, der niemanden aussperren darf): ein
  Bot-Schutz, der bei Netzproblemen durchlaesst, laesst sich durch
  Provozieren eines Ausfalls abschalten. In Produktion ohne Secret wird
  ebenfalls abgelehnt - eine sichtbar kaputte Registrierung faellt auf,
  eine stillschweigend ungeschuetzte nicht.
* **Token** 64 Zeichen Zufall, gespeichert nur als `sha256`-Hash,
  **24 Stunden** gueltig, genau einmal nutzbar. Ein erneuter Versand
  erzeugt ein neues Token und entwertet das alte.
* **"Erneut senden" ist doppelt gedeckelt**: Route-Bremse plus
  `PendingRegistration::MAX_SENDS` (5) und eine Wartezeit. Der ZAEHLER
  ist die wichtigere Schicht - ein reiner Zeit-Throttle gibt nach Ablauf
  wieder Luft und taugt nicht gegen das Zuspammen einer FREMDEN Adresse.
  Die Antwort ist immer dieselbe, ob die Adresse bekannt ist oder nicht.
* **Das Passwort** wird sofort gehasht; ein Klartext-Passwort liegt zu
  keinem Zeitpunkt in der Datenbank.
* **Die Einwilligung** (`CustomerConsent`) entsteht erst bei der
  Bestaetigung - mit der IP DIESES Aufrufs. Das ist der Zeitpunkt, zu dem
  die Person nachweislich Zugriff auf das Postfach hatte.
* `registrierungen:aufraeumen` (taeglich 03:40) loescht abgelaufene
  Vormerkungen: Datenminimierung UND Freigabe der blockierten Adresse -
  sonst koennte man fremde Adressen dauerhaft "reservieren".

### Nebenbefund

`portal_password_set_at` steht nicht in `User::$fillable`. Der alte
Registrierungscode uebergab es an `User::create()`, wo es still
verschluckt wurde - die Kundenakte zeigte "Passwort eingerichtet"
also nie an. Wird jetzt per `forceFill()` gesetzt.

### Tests

`tests/Feature/Security/RegistrationHardeningTest.php` (17),
`tests/Feature/Auth/RegistrationTest.php` (9),
`tests/Feature/Security/ClientIpIntegrityTest.php`.

### Offen fuer den Betreiber

1. `TURNSTILE_SITE_KEY` und `TURNSTILE_SECRET_KEY` in der Server-`.env`
   setzen (Cloudflare Dashboard → Turnstile → Widget anlegen).
   **Ohne diese Werte lehnt die Registrierung in Produktion ab.**
2. Turnstile in die **Datenschutzerklaerung** aufnehmen (Empfaenger
   Cloudflare, Zweck Schutz vor missbraeuchlicher Anmeldung,
   Rechtsgrundlage Art. 6 Abs. 1 lit. f). Cloudflare ist als Edge-Proxy
   ohnehin Empfaenger jeder Besucher-IP - es kommt kein neuer
   Empfaenger hinzu, genannt werden muss es trotzdem.

---

## SEC-2 - Vertrauenswuerdige Proxys

Ausfuehrlich in **`docs/SICHERHEIT_NETZWERK_ORIGIN.md`**. Kurz:

* `trustProxies(at: '*')` glaubte JEDER Anfrage ihren
  `X-Forwarded-For`-Header. Folge: frischer Rate-Limit-Eimer je
  erfundener IP (Login-, Reset- und Registrierungs-Bremse wirkungslos)
  und eine frei gewaehlte IP in ActivityLog und in den
  DSGVO-Einwilligungsnachweisen.
* Jetzt eine **explizite Liste** (`config/trustedproxy.php`:
  Cloudflare-Ranges + Loopback, per `TRUSTED_PROXIES` ueberschreibbar).
* **Benannte Limiter** (`registrierung`, `anmeldung`, `passwort-reset`)
  zaehlen je IP **und** je Adresse - ein Botnetz mit vielen IPs kann
  dieselbe Adresse trotzdem nicht zuspammen. Die Adresse geht gehasht in
  den Schluessel, damit keine E-Mail im Klartext in Cache-Keys landet.

**Das Abnahmekriterium ist damit erfuellt** und durch
`tests/Feature/Security/ProxySpoofingTest.php` abgesichert.

**Was NICHT erledigt ist:** ob der Origin direkt aus dem Internet
erreichbar ist. Das steht nicht im Repository, und der Versuch, es aus
der Entwicklungsumgebung zu klaeren, scheitert nachweisbar an ihr
(synthetisches DNS mit wechselnden Antworten, gesperrte ausgehende
Verbindungen, kein SSH-Zugang). Die Pruef- und Firewall-Schritte fuer
DevOps stehen im Netzwerk-Dokument; die Ergebnistabelle dort ist
auszufuellen.

---

## SEC-3 - Abhaengigkeiten

### Ursache

16 offene Sicherheitshinweise in `guzzlehttp/guzzle` (6) und
`league/commonmark` (10) - beides **transitive** Abhaengigkeiten von
Laravel, die in keiner `composer.json` stehen. Genau solche Pakete
fallen ohne Automatik durch. Ausserdem lief in der CI kein
`composer audit`, und Dependabot war nicht eingerichtet.

### Aenderung

| Paket | vorher | nachher |
|---|---|---|
| guzzlehttp/guzzle | 7.13.2 | 7.15.5 |
| guzzlehttp/psr7 | 2.12.3 | 2.13.1 |
| league/commonmark | 2.8.2 | 2.10.0 |

* `composer audit`: **16 → 0**
* `npm audit`: **0** (auch der Dev-Baum; `concurrently`/`shell-quote`
  wurden mit aktualisiert)
* Kein `--ignore-platform-reqs`, keine Ausnahmen, keine unterdrueckten
  Hinweise. Es bleibt **kein Advisory offen** - der im Auftrag
  vorgesehene Abschnitt "verbleibende Ausnahmen mit Begruendung" ist
  daher leer.
* CI: eigener Job **`audit`** (`composer audit` + `npm audit`), und der
  Deploy haengt jetzt an `needs: [test, audit]`. Ein eigener Job statt
  eines Schritts im Test-Job, damit im Actions-Ueberblick sofort
  sichtbar ist, woran es lag.
* `.github/dependabot.yml` fuer **composer**, **npm** und
  **github-actions**, woechentlich, Patch/Minor gebuendelt (30 einzelne
  PRs liest niemand, und was niemand liest, wird nicht gemergt).
* Zusaetzlicher, **nicht blockierender** Job `proxy-liste`: meldet, wenn
  die hinterlegten Cloudflare-Ranges von den veroeffentlichten
  abweichen (`scripts/pruefe-cloudflare-ips.sh`).

### Nachweis, dass die Integrationen weiterlaufen

241 Tests der genannten Integrationen (Anthropic/KI-Assistent, Meta
Graph, Lexoffice, HaveIBeenPwned/Passwortpruefung, Markdown) laufen
gruen; der Vite-Build ebenfalls.

### Tests

`tests/Feature/Security/DependencyAuditTest.php` haelt die MECHANIK
fest (Gate vorhanden, Deploy haengt daran, Ergebnis wird nicht
geschluckt, Dependabot ueberwacht beide Oekosysteme). Der eigentliche
Abgleich braucht Netz und gehoert in die CI, nicht in die Testsuite.

---

## SEC-4 - Content-Security-Policy

### Ursache

```
script-src 'self' 'unsafe-inline' 'unsafe-eval'
```

`'unsafe-inline'` erlaubt **jedes** eingebettete Skript - also genau das,
was ein XSS-Angriff einschleust. Eine CSP mit `'unsafe-inline'` im
`script-src` schuetzt vor XSS praktisch nicht mehr; sie sieht nur so aus.
`'unsafe-eval'` erlaubt zusaetzlich, beliebige Zeichenketten als Code
auszufuehren.

Beides stand dort nicht ohne Grund: die Anwendung benutzte
**310 Inline-Handler** (`onclick="…"`), **142 eingebettete
`<script>`-Bloecke** und **Alpine.js**, das seine Direktiven per
`Function()` auswertet.

### Aenderung

**1. `'unsafe-eval'` → Alpine.js entfernt.**
Alpine wurde an genau zwei Stellen benutzt (Zeilenmenue der Kunden- und
der Ticketliste, Sammelauswahl der Tickets); zwei weitere Komponenten
(`modal`, `dropdown`, `dropdown-link` aus dem Breeze-Geruest) waren
**toter Code** - von keiner Vorlage referenziert - und sind geloescht.
Ersatz ist `resources/js/ui.js` mit gewoehnlicher Ereignis-Delegation.
Nebenwirkung: das JS-Bundle schrumpfte von 45,3 kB auf 4,4 kB.

**2. `'unsafe-inline'` → Nonce.**
`App\Support\CspNonce` erzeugt je Anfrage einen Zufallswert; die
Blade-Direktive `@cspNonce` setzt ihn auf jedes eingebettete `<script>`,
und `SecurityHeaders` nennt ihn im Header. Ein per XSS eingeschleustes
`<script>` kennt den Wert nicht - der Angreifer sieht die Antwort ja
nicht, in der er steht. Der Nonce wird **je Anfrage zurueckgesetzt**
(ein wiederverwendeter Nonce schuetzt nicht) und an Laravels
Vite-Helfer durchgereicht.

**3. Alle 310 Inline-Handler entfernt.**
Ein Attribut-Handler kann keinen Nonce tragen - solange es welche gibt,
braucht die Richtlinie `'unsafe-inline'`. Umgestellt wurde nach Muster:

| Muster | Anzahl | Loesung |
|---|---|---|
| Handler ohne Blade-Ausdruck | 252 | `data-h-<ereignis>="key"` + Registrierung in `@pushOnce('cspScripts')` |
| `return confirm('…')` | 22 | `data-confirm="…"` (Text als Attributwert, nie als Code) |
| `rowNav(event, 'ZIEL')` | 9 | `data-row-nav="ZIEL"` |
| `funktion('{{ $wert }}')` | 12 | Wert nach `data-a0`, Code fuer alle Zeilen gleich |
| Sonderfaelle | 8 | eigene Datenwerte bzw. `data-toggle` / `data-show` / `data-fill-target` |
| in JS-Zeichenketten erzeugt | 7 | Datenwerte; ein Hover-Paar wurde zu einer CSS-Regel |

Die Registrierungen landen ueber `@pushOnce('cspScripts')` am Ende des
`<body>`. Das ist wichtig fuer Partials: ein `<script>` mitten in einer
`<table>` wuerde der Browser herausloesen, und ein Partial, das je Zeile
gerendert wird, haette seinen Block sonst hundertfach ausgegeben.

Die Semantik der alten Attribute ist exakt nachgebildet: `this` ist das
Element, `event` ist verfuegbar, und ein Rueckgabewert `false` verhindert
die Standardaktion - genau so wirkte `onsubmit="return confirm(...)"`.

**4. `script-src-attr 'none'`** schliesst Attribut-Handler zusaetzlich
ausdruecklich aus, damit ein neu eingefuegtes `onclick` nicht doch wirkt.

### Ergebnis

```
default-src 'self'; base-uri 'self'; object-src 'none';
frame-ancestors 'self'; form-action 'self';
frame-src 'self' https://challenges.cloudflare.com;
img-src 'self' data: blob: https:; font-src 'self' data:;
style-src 'self' 'unsafe-inline';
script-src 'self' 'nonce-<zufall>' https://challenges.cloudflare.com;
script-src-attr 'none'; connect-src 'self'
```

### Bewusst NICHT geaendert: `style-src 'unsafe-inline'`

Die Anwendung traegt rund 4.800 `style="…"`-Attribute. Ein Nonce hilft
dort nicht (ein Attribut kann keinen tragen), die Alternative waere eine
vollstaendige Neufassung der Oberflaeche. Das Risiko ist deutlich
kleiner als bei Skripten: aus einem Inline-Style laesst sich kein Code
ausfuehren, hoechstens die Darstellung veraendern. Das
Abnahmekriterium nennt ausdruecklich `script-src`.

Weg dahin, wenn es einmal angegangen wird: die wiederkehrenden Muster
(Kachel, Badge, Zeilenmenue, Formularfeld) in CSS-Klassen ziehen -
`resources/css/app.css` und die Layouts sind der Ort dafuer -, dann
`style-src` auf Nonce umstellen. Sinnvoll als eigener Auftrag mit
Sichtpruefung, nicht als Beifang einer Sicherheitsrunde.

### Umstellung ohne Blindflug

`CSP_REPORT_ONLY=true` in der `.env` schaltet die Richtlinie auf
**melden statt blockieren** - fuer den Fall, dass nach einer groesseren
Aenderung an der Oberflaeche erst beobachtet werden soll. Standard ist
`false`: eine Richtlinie, die nur meldet, schuetzt nicht.

### Tests

`tests/Feature/Security/ContentSecurityPolicyTest.php` - darunter zwei
Waechter, die den Rueckfall verhindern: **keine** Vorlage darf wieder
einen Inline-Handler bekommen, und **jedes** eingebettete Skript muss
einen Nonce tragen. Ohne sie faellt ein neues `onclick` erst auf, wenn
ein Mitarbeiter meldet "der Knopf tut nichts".

---

## SEC-5 - Validierung der Systemeinstellungen

### Ursache

`SettingsController::update()` schrieb jeden Wert einer festen Feldliste
**ungeprueft** in `system_settings`. Der gefaehrlichste Fall war
`legal_external_base`: der Wert landet in `LegalPageController::show()`
in `redirect()->away()`, also in einem Location-Header, der jeden
Besucher der oeffentlichen Rechtsseiten weiterschickt.

### Aenderung

`App\Http\Requests\Admin\UpdateSettingsRequest`: **jede** Einstellung hat
Typ, Laengenobergrenze und - wo es eine gibt - eine Wertemenge.
Geschrieben wird nur noch, was die Validierung durchgelassen hat
(`$request->validated()` statt `$request->input()`).

Fuer `legal_external_base` zusaetzlich:

* nur **https** (kein `javascript:`, `data:`, `http:`, `ftp:`),
* keine Zugangsdaten (`https://angreifer.example@dienstly24.de` ist der
  klassische Weg, einen Host-Check zu taeuschen),
* keine Parameter/Anker, keine Steuerzeichen (`\r\n` waere im
  Location-Header Response-Splitting),
* **Host-Allowlist**, abgeleitet aus der bestehenden
  `config/website.php` (kanonischer Host, Redirect-Hosts, Extra-Hosts)
  statt einer zweiten, handgepflegten Liste - die waere beim naechsten
  Domainwechsel vergessen worden.

**Dieselbe Pruefung greift beim LESEN** in `LegalPageController`. Die
Validierung im Formular allein genuegt nicht: in `system_settings` kann
ein Wert aus der Zeit VOR der Validierung stehen oder per CLI/Seeder
gesetzt worden sein. Ist er nicht sauber, wird nicht weitergeleitet,
sondern die eigene Portalseite gerendert - der Betrieb laeuft weiter,
nur ohne unkontrolliertes Ziel.

Die Autorisierung steht jetzt **an der Route UND im FormRequest**. Eine
Autorisierung, die nur an der Route haengt, faellt weg, sobald die Route
einmal umgehaengt wird.

### Tests

`tests/Feature/Security/SettingsValidationTest.php` - Autorisierung fuer
jede Nicht-Admin-Rolle, zehn unzulaessige Quellen (jeweils zweimal
geprueft: beim Schreiben abgelehnt UND beim Lesen nicht weitergeleitet),
Suffix-Schmuggel, sowie die uebrigen Einstellungen.

---

## Uebersicht der Regressionstests

| Datei | Deckt ab |
|---|---|
| `Security/RegistrationHardeningTest.php` | Turnstile, Kundennummer, kein Zustand vor Bestaetigung, Versand-Deckel, Rate-Limit |
| `Security/ProxySpoofingTest.php` | Trusted Proxies, Spoofing gegen Login-/Reset-/Registrierungs-Bremse |
| `Security/ClientIpIntegrityTest.php` | IP in ActivityLog und CustomerConsent |
| `Security/ContentSecurityPolicyTest.php` | CSP-Direktiven, Nonce, Rueckfall-Waechter fuer Handler und Skripte |
| `Security/SettingsValidationTest.php` | Autorisierung, Validierung, externe Weiterleitung |
| `Security/DependencyAuditTest.php` | CI-Gate und Dependabot |
| `Auth/RegistrationTest.php` | Der zweistufige Ablauf im Gutfall |
| `OperationsHardeningTest.php` | Keine fremden Ressourcen in Vorlagen (mit benannter Turnstile-Ausnahme) |
