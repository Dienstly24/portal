# Website-Merge: Marketing-Website im Portal (Arbeitsauftrag 30.07.2026)

Der Betreiber hat entschieden (Arbeitsauftrag "Reparatur & Ausbau Website"):
**Die Marketing-Website zieht komplett in die Laravel-Anwendung um** und wird
auf dem Haupt-Domain `https://www.dienstly24.de` ausgeliefert. Kein
getrenntes statisches Hosting, kein FTP, eine Codebasis, ein Deploy.

## Was umgesetzt ist

### Architektur (Auftrag §1)
- `www.dienstly24.de` = kanonischer Host (`config/website.php`).
  `dienstly24.de`, `dienstly24.com`, `www.dienstly24.com` und `http://`
  werden app-seitig per **301** umgeleitet (`RedirectWebsiteHost`,
  global registriert). `portal.`/`admin.` behalten ihr bisheriges Verhalten.
- `/` zeigt auf Website-Hosts die Startseite (`HomeController` ->
  `WebsiteController`), auf allen anderen Hosts wie bisher Login/Dashboard.
  `/website` = Vorschau der Startseite auf jedem Host (z. B. vor dem
  DNS-Umzug). Lokal: `WEBSITE_EXTRA_HOSTS=127.0.0.1` in der `.env` macht
  den lokalen Host zum Website-Host.
- **Echte arabische URLs** (Auftrag P1-3): `/ar`, `/ar/leistungen`,
  `/ar/leistungen/{slug}`, `/ar/kontakt/danke` - serverseitig gerendert
  (`forceLocale:ar`), Hocharabisch statt Dialekt, `hreflang de/ar/x-default`
  auf allen Sprachseiten, Sprachumschalter = echte Links auf dieselbe Seite.
- Rechtsseiten (`/impressum`, `/datenschutz`, `/agb`, `/widerruf`,
  `/erstinformation`, `/cookie-richtlinie`, `/bildnachweise`) sind aus der
  statischen Website uebernommen (`resources/views/website/legal/`).
  Website-Hosts rendern IMMER lokal (Schleifenschutz gegen die
  "Rechtsseiten-Quelle"-Einstellung); Alt-URLs `*.html` -> 301.

### P0-Fixes
- **P0-1 Kontaktformular**: `POST /kontakt` (CSRF, Honeypot, SpamFilter,
  Throttle) -> Ticket `source=website` (= Lead in der Beraterwelt, Glocke,
  Support-Mail) + **Bestaetigungs-Mail an den Absender** (DE/AR) +
  Danke-Seite `/kontakt/danke`. **Einwilligungs-Protokoll** auf dem Ticket:
  `consent_given_at`, `consent_ip`, `consent_text` (Migration).
  **Loeschfrist**: `tickets:purge-website-leads` loescht unkonvertierte
  Website-Anfragen nach 6 Monaten (Schedule 04:10).
- **P0-2 Bilder**: kein `onerror="this.remove()"` mehr - Slots werden
  serverseitig aufgeloest; ohne Bild zeigt die Seite ihre eingebaute
  Grafik/Icon-Kachel. Bilder kommen aus der Medienverwaltung (unten).
- **P0-3 WhatsApp**: Float-Button (offizielles SVG-Icon) auf Startseite,
  Danke-Seite, Rechtsseiten, Leistungsseiten und Fehlerseiten;
  vorbefuellter Text je Sprache und je Leistung.
- **P0-4 Schriften**: lokal unter `public/fonts/` (woff2, Subsets je
  Sprache, `font-display: swap`, preload). KEINE Requests an Google-Server
  (LG Muenchen I, 3 O 17493/20). Regel fuer die Zukunft: auf
  Website-Seiten niemals externe Ressourcen einbinden.
- **P0-5**: `.htaccess` fuer das statische Uebergangs-Hosting blockiert
  `zip/bak/sql/env/log/...`; Anweisung zum Loeschen der
  `dienstly24websiteupload.zip` steht in `website/LIESMICH.txt`.
- **P0-6 IP-Links**: Bild-URLs werden nur noch RELATIV erzeugt
  (`ServicePage::imageUrl()`, `MediaAsset::publicUrl()`).
  Datenbank-Aufraeumen: `php artisan website:fix-storage-urls --write`.
  Zusaetzlich auf dem Server: `APP_URL=https://www.dienstly24.de` setzen!
- **P0-7 Umlaute**: `php artisan service-pages:fix-umlauts --write`
  (konservative Wortliste, `App\Services\UmlautRepair`); Seeder-Texte
  korrigiert; Warnhinweis beim Speichern in der Leistungsseiten-Verwaltung.

### P1-1 Medienverwaltung (/admin/medien)
- Upload (Drag&Drop, mehrere Dateien, 10 MB, JPG/PNG/WebP/SVG) mit **echter
  MIME-Pruefung** (Inhalt, nicht Endung) und **SVG-Sanitizer** (Skripte,
  on*-Handler, javascript:-URLs, foreignObject werden entfernt).
- Automatische Verarbeitung: **AVIF + WebP + JPG in 480/960/1600 px**,
  jede Variante **< 200 KB**, EXIF entfernt, JPEG-Orientierung angewendet.
  Original liegt PRIVAT (Archiv), nur Varianten sind oeffentlich.
- **Slots** (feste Plaetze, `config/website.php`): Hero, 6 Leistungskarten,
  Hamburg-Foto, og:image. Slot waehlen -> sofort live; das vorherige Bild
  wandert automatisch ins Archiv (nie geloescht).
- **Alt-Texte DE+AR sind Pflicht** (BFSG/SEO) - ohne sie kein Speichern.
  Optional Bildnachweis-Feld.
- Papierkorb: 30 Tage wiederherstellbar, danach `media:purge-trash`
  (Schedule 04:15). Loeschen/Wiederherstellen nur admin/manager;
  Hochladen/Ersetzen/Bearbeiten alle Staff-Rollen ("Redakteur").
- Website bindet Bilder ueber `website/partials/picture.blade.php` ein:
  `<picture>` mit srcset/sizes, width/height (CLS), lazy loading.
- **Marken-Slots (P1-1e)**: `logo-hell`, `logo-dunkel`, `logo-symbol-hell`
  und `favicon` ueberschreiben die generierten Dateien aus
  `public/images` in der GESAMTEN Anwendung (Website DE/AR, Rechtsseiten,
  Login, Kundenportal, Beraterwelt) - aufgeloest ueber
  `App\Support\BrandAssets`, mit Rueckfall auf den Bestand, wenn kein
  Bild zugewiesen ist. Favicon erzeugt automatisch 32/180/512 px.
  Transparenz bleibt erhalten: erkennt der Generator einen Alphakanal,
  ist die universelle Fallback-Variante **PNG statt JPG** (ein JPG legte
  sonst einen weissen Kasten hinter die Wortmarke). Marken-Slots duerfen
  nur admin/manager belegen, ersetzen oder aushaengen - ein falsches Logo
  wirkt auf alle Bereiche, ein Leistungsbild nur auf eine Karte.
- Die Slot-Aufloesung cacht bewusst **rohe Spaltenwerte statt
  Eloquent-Objekte** in EINEM Cache-Eintrag: serialisierte Modelle kommen
  aus einem echten Cache-Store (database/file/redis) als
  `__PHP_Incomplete_Class` zurueck und legten jede Seite mit Bild-Slot
  lahm (im Browsertest aufgefallen, Regressionstest vorhanden).

### SEO / Infrastruktur
- `robots.txt` dynamisch: NUR der kanonische Host (www.dienstly24.de)
  ist offen (+ Sitemap); portal./admin. und Staging-/Vorschau-Hosts
  komplett gesperrt; `X-Robots-Tag: noindex` auf /admin, /login,
  /portal, /partner, /register sowie auf allen Staging-Antworten.
- `sitemap.xml` dynamisch aus echten Inhalten (Startseite DE/AR,
  Leistungsseiten DE/AR mit echtem lastmod aus der DB, Rechtsseiten).
- Fehlerseiten 404/500 im Markendesign, zweisprachig, mit WhatsApp.
- CSS/JS der Website extern und cachebar (`public/website-assets/` -
  Achtung: NICHT `public/website/`, das wuerde die Route `/website`
  verschatten).
- Cookies: Die Website setzt nur das technisch notwendige Session-Cookie,
  laedt keinerlei Drittdienste -> **kein Cookie-Banner noetig** (die
  Rechtstexte wurden entsprechend praezisiert). Sollte je ein Tracking-/
  Analyse-Dienst dazukommen, MUSS vorher ein Consent-Banner her.

### Statisches Uebergangs-Paket (`website/`, Auftrag Phase 1)
Bis DNS/vHost umgezogen sind, kann der Betreiber den Ordner `website/`
sofort auf Hostinger hochladen: Formular sendet per JS an
`https://portal.dienstly24.de/api/website-contact` (Token-Flow),
WhatsApp-Button, lokale Fonts, `.htaccess` (301 www/https/.com,
Blockliste, Caching, Security-Header), canonical auf www. Details:
`website/LIESMICH.txt`.

## Gemessene Ergebnisse (Stand 30.07.2026, lokal)

Lighthouse (Mobil-Emulation, Chromium headless, artisan serve - also OHNE
HTTP/2, Brotli und CDN; Produktionswerte werden eher besser):

| Seite | Performance | Accessibility | Best Practices | SEO | LCP | CLS | Gewicht |
|---|---|---|---|---|---|---|---|
| Startseite DE | **100** | **100** | 96 | 69* | 1,7 s | **0** | **142 KB** |
| Startseite AR | **95** | **100** | 96 | 69* | 2,8 s** | **0** | 310 KB |

\* SEO 69 ist ein LOKALES Artefakt: Der Test-Host ist kein kanonischer
Host, robots.txt sperrt ihn absichtlich (Staging-Verhalten) -> Lighthouse
meldet "blocked from indexing". Auf www.dienstly24.de ist die Seite offen;
der Wert ist nach dem Go-Live mit PageSpeed Insights zu bestaetigen.
Best Practices 96 = fehlendes HTTPS im lokalen Test.
\** AR-LCP liegt lokal knapp ueber dem 2,5-s-Ziel (groessere arabische
Schriften unter simuliertem Slow-4G). CLS wurde von 0,148 auf 0 gebracht
(metrik-angepasste Fallback-Fonts in fonts-ar.css, echte Font-Metriken).

Statische Uebergangs-Site: Bild-Payload von ~4,9 MB (drei PNGs mit
1,4-1,8 MB) auf **188 KB WebP** reduziert - Startseite gesamt jetzt
unter 0,4 MB statt ~5 MB.

Security-Header (Beleg per curl, jede Antwort): X-Content-Type-Options,
X-Frame-Options, Referrer-Policy, Permissions-Policy, CSP; HSTS ab HTTPS.
Die externen Grades (securityheaders.com = erwartbar A, ssllabs.com haengt
von der TLS-Konfiguration des Servers/Cloudflare ab) sind erst nach dem
Go-Live messbar - Teil der Abnahme-Checkliste unten.

## Bild-Slots: Zustand und Empfehlung (P0-2)

- Die Website zeigt fuer JEDEN leeren Slot ihre eingebaute Markengrafik
  (Icon-Kachel/SVG-Schild) - serverseitig entschieden, kein
  onerror-Entfernen mehr, nichts ist "leer" oder kaputt.
- Zwei der sechs Leistungskarten-Bilder existieren (Kfz, Zahnzusatz);
  vier wurden nie erstellt (Kranken, Zulassung, Kennzeichen, Strom&Gas).
  Sie entstehen kuenftig in < 1 Minute je Bild ueber /admin/medien.
- **ACHTUNG Rechtsrisiko**: Das vorhandene Kfz-Bild zeigt ein
  BMW-Fahrzeug MIT ERKENNBAREM EMBLEM - das verstoesst gegen die eigene
  Regel (keine Marken-Logos ohne Freigabe, siehe website/LIESMICH.txt).
  Empfehlung: zeitnah durch neutrales Motiv ersetzen.
- Die vorhandenen Bilder haben SCHWARZE Hintergruende; die Karten sind
  weiss, der Hero gruen. Fuer den Laravel-Auftritt wurden sie deshalb
  bewusst NICHT vorbefuellt - die eingebauten Grafiken passen besser,
  bis saubere Assets (transparenter Hintergrund) hochgeladen werden.

## Backup + Restore-Test (Auftrag "Definition of Done")

- `scripts/backup.sh`: taeglicher DB-Dump (mysqldump
  --single-transaction) + storage/app + .env-Kopie nach
  /var/backups/dienstly24, Rotation 14 Tage. Cron:
  `30 2 * * * cd /var/www/dienstly24/portal && bash scripts/backup.sh >> /var/log/dienstly24-backup.log 2>&1`
- ZWEITER Speicherort ausserhalb des VPS ist Pflicht (z. B. Hetzner
  Storage Box per rclone) - ein Backup auf derselben Maschine schuetzt
  nicht vor Totalausfall.
- **Restore-Test** (mindestens einmal vor Abnahme, danach
  vierteljaehrlich, dauert ~15 Minuten):
  1. `mysql -e "CREATE DATABASE restore_test"`
  2. `gunzip < /var/backups/dienstly24/db-<neuestes>.sql.gz | mysql restore_test`
  3. Stichproben: `mysql restore_test -e "SELECT COUNT(*) FROM customers; SELECT COUNT(*) FROM contracts; SELECT MAX(created_at) FROM tickets;"`
     - Zahlen muessen zur Produktion passen (max. 1 Tag Rueckstand).
  4. `mkdir /tmp/restore-test && tar -xzf /var/backups/dienstly24/storage-<neuestes>.tar.gz -C /tmp/restore-test`
     - Stichprobe: ein bekanntes Kundendokument oeffnen.
  5. Aufraeumen: `mysql -e "DROP DATABASE restore_test"` + Ordner loeschen.
  6. Ergebnis mit Datum in einer Checkliste festhalten (wer, wann, ok?).

## Staging / Vorschau zum Durchklicken (VOR dem DNS-Umzug)

Der Betreiber will die neue Website selbst durchklicken, bevor
www.dienstly24.de umzieht - so geht es OHNE zweite Installation:
1. DNS: `neu.dienstly24.de` als A-Record auf den VPS zeigen lassen
   (beruehrt www/portal/admin nicht) + TLS-Zertifikat.
2. vHost: `neu.dienstly24.de` als Alias auf den Laravel-vHost.
3. Server-`.env`:
   `WEBSITE_EXTRA_HOSTS=neu.dienstly24.de` (Host zeigt die Website),
   `STAGING_HOSTS=neu.dienstly24.de`,
   `STAGING_BASIC_AUTH=vorschau:<starkes-passwort>`
   -> danach `php artisan config:cache`.
4. Ergebnis: `https://neu.dienstly24.de` zeigt die komplette neue
   Website (DE/AR, Formulare, Leistungsseiten) hinter
   Benutzername/Passwort, mit noindex und gesperrter robots.txt.
   Formular-Einsendungen erzeugen ECHTE Tickets (gleiche Datenbank) -
   fuer den Test gewuenscht, Test-Tickets danach schliessen.
5. Nach dem Go-Live die drei Variablen leeren und den DNS-Eintrag
   entfernen.

## Verbindlicher Umschaltplan (Cutover)

Es laufen bewusst ZWEI Auslieferungen parallel (statisch auf Hostinger,
Laravel auf dem VPS) - dieser Zustand ist NUR die Uebergangsphase und
endet mit diesem Plan. Risiko bei Nichtstun: Inhalte laufen auseinander.

- **T-7 bis T-2**: Staging (oben) einrichten; Betreiber klickt alles
  durch und gibt schriftlich frei (Formular DE+AR von echten Geraeten,
  WhatsApp-Button, Rechtsseiten, Sprachumschalter).
- **T-1 (Montag)**: TTL der DNS-Eintraege von dienstly24.de/www/.com auf
  **300 Sekunden** senken (Rueckweg wird dadurch schnell).
  ADMIN_BASIC_AUTH auf dem VPS setzen (Bedingung, siehe unten).
  Backup-Lauf + Restore-Test dokumentiert OK.
- **Arabische Schritt-fuer-Schritt-Fassung fuer den Betreiber**:
  `docs/UMZUG_SCHRITT_FUER_SCHRITT_AR.md` (alle Befehle, beide
  Webserver-Varianten, Verifikationsliste, Rollback).
- **T-0 (DIENSTAG, 09:00-11:00 Uhr - nie freitags/abends)**:
  1. A-Records dienstly24.de + www (+ .com) auf den VPS/Cloudflare.
  2. Verifikation (Reihenfolge, alles dokumentieren):
     `curl -I https://www.dienstly24.de/` (200, Security-Header),
     `curl -I http://dienstly24.de/` und `https://dienstly24.com/`
     (301 auf https://www.dienstly24.de), Formular-Testeintrag
     (Ticket + 2 Mails), /ar, /leistungen/kfz-versicherung,
     /impressum, /sitemap.xml, /robots.txt, Portal-Login unveraendert
     (portal./admin. wurden nicht angefasst).
  3. Search Console: Sitemap einreichen, Adressaenderung von
     dienstly24.de -> www.dienstly24.de anstossen.
- **Rollback** (falls irgendetwas Kritisches nicht laeuft): A-Records
  zurueck auf Hostinger - bei TTL 300 weltweit in Minuten wirksam. Der
  statische Hotfix-Stand bleibt dort bis T+7 UNANGETASTET liegen.
- **T+7**: Wenn eine Woche stabil: statisches Hosting stilllegen
  (Dateien vom Hostinger-Webroot entfernen), TTL wieder auf 3600+,
  Staging-Zugang abbauen. AB JETZT gibt es nur noch EINE Quelle.
- **Verantwortlich**: DNS/Server-Schritte = Betreiber bzw. Server-Admin;
  Verifikations-Checkliste kann Claude im Anschluss per Browser-Test
  gegen die Live-Domain abarbeiten und dokumentieren.

## Go-Live-Checkliste (Server, vom Betreiber/Admin auszufuehren)

1. **Sofort (Phase 1)**: `website/`-Ordner inkl. `.htaccess` +
   `assets/fonts/` auf Hostinger hochladen; `dienstly24websiteupload.zip`
   auf dem Server loeschen.
2. **DNS**: `dienstly24.de`, `www.dienstly24.de`, `dienstly24.com`,
   `www.dienstly24.com` auf den VPS zeigen lassen - am besten ueber
   **Cloudflare (Proxy an, SSL "Full strict")**; Server-Firewall fuer
   80/443 auf Cloudflare-IPs beschraenken (P0-6: direkte IP-Zugriffe weg).
3. **vHost**: die vier Hostnamen als ServerAlias/server_name auf den
   bestehenden Laravel-vHost legen; TLS-Zertifikate ausstellen.
4. **.env auf dem Server**: `APP_URL=https://www.dienstly24.de` (Ursache
   der frueheren IP-Links) und `ADMIN_BASIC_AUTH=benutzer:passwort`
   (Pflicht, s. u.). Optional `WEBSITE_CANONICAL_HOST`,
   `WEBSITE_EXTRA_HOSTS`/`STAGING_HOSTS`/`STAGING_BASIC_AUTH` (Staging).
   Danach `php artisan config:cache`.
4b. **Nach erfolgreichem Umzug**: `WEBSITE_MARKETING_REDIRECT=true`
   setzen (+ `config:cache`). Erst dann leitet der Portal-Host die alten
   Marketing-URLs (`portal.dienstly24.de/leistungen/...`, `/ar/...`) per
   301 auf den kanonischen Host um (Auftrag P1-4) - vorher liefe die
   Umleitung auf die statische Site, die diese Seiten nicht hat. Der
   Login-/Portalbereich ist von der Regel ausgenommen.
5. Nach dem Deploy einmalig:
   `php artisan storage:link` (falls noch nicht vorhanden),
   `php artisan service-pages:fix-umlauts --write`,
   `php artisan website:fix-storage-urls --write`.
6. **Search Console + Bing**: Property fuer `https://www.dienstly24.de`,
   Sitemap `https://www.dienstly24.de/sitemap.xml` einreichen; alte
   Property (dienstly24.de ohne www) per Adressaenderung umziehen.
7. Testlauf gemaess Auftrag §5: Formular von iPhone+Android absenden
   (Lead in /admin + 2 Mails), DevTools-Netzwerk ohne google.com/gstatic,
   securityheaders.com, PageSpeed.

## Bewusst offen / naechste Schritte

- **2FA fuer /admin** (Auftrag P1-1a): Die Anwendung hat noch kein
  Zwei-Faktor-Login - eigenes Arbeitspaket (alle Admin-Bereiche, nicht nur
  Medien). **BEDINGUNG bis dahin (Betreiber-Vorgabe): /admin geht NICHT
  oeffentlich ohne zweite Schicht online.** Umgesetzt als
  `ADMIN_BASIC_AUTH=benutzer:passwort` in der Server-`.env` (+
  `php artisan config:cache`): zusaetzliche HTTP-Basic-Auth VOR dem
  gesamten /admin-Bereich (`ExtraBasicAuth`-Middleware; ergaenzt das
  normale Login, ersetzt es nicht). Alternative/zusaetzlich: IP-Allowlist
  oder Cloudflare Access auf admin.dienstly24.de. Apache/FPM-Hinweis:
  Authorization-Header durchreichen
  (`SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` bzw.
  `CGIPassAuth On`); bei Nginx/FPM Standard ok.
- **Cloudflare Turnstile** (Auftrag P0-1): optionale zusaetzliche
  Spam-Schicht; aktuell Honeypot + SpamFilter + Throttle + (statisch)
  Token-Flow. Nachruestbar im `WebsiteController::submitContact`.
- **P1-4 Inhaltsausbau**: Die Leistungsseiten kommen aus der DB und sind
  gut, aber noch nicht auf 800-1200 Woerter ausgebaut; Texte erweitert der
  Betreiber bequem unter /admin/service-pages (Warnung bei fehlenden
  Umlauten inklusive). Eigenes og:image je Leistungsseite folgt mit dem
  Inhaltsausbau.
- **AVIF** setzt `imageavif` im Server-PHP voraus; fehlt es, liefert die
  Verarbeitung automatisch nur WebP+JPG (funktional identisch).
- **Matomo, Google Business Profile, Ratgeber, PWA, Uptime-Monitoring**
  (Auftrag P2) - nach dem Go-Live.
- **Kundenstimmen/Zahlen**: Vor dem Livegang muss der Betreiber die
  schriftlichen Freigaben der zitierten Kunden und die Belegbarkeit von
  "3.000+ Kunden" bestaetigen (UWG); Texte stammen unveraendert von der
  bisherigen Website.
- E-Mail-Templates bleiben bewusst im alten Design (CLAUDE.md, Outlook).

## Tests

`tests/Feature/WebsiteMergeTest.php` (22) + `tests/Feature/MediaLibraryTest.php`
(10) + `tests/Feature/ExtraBasicAuthTest.php` (5):
Startseite DE/AR, Redirects, Rechtsseiten, Formular inkl. Einwilligung/
Honeypot/Mails, Purge, robots/sitemap/noindex, hreflang, Umlaut-Reparatur,
Storage-URLs, Medien-Upload/Varianten/Slots/MIME/SVG/Papierkorb/Rollen,
Admin-Basic-Auth, Staging-Gate. Gesamtsuite: 1320 Tests gruen.
