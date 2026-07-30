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

### SEO / Infrastruktur
- `robots.txt` dynamisch: Website-Host offen (+ Sitemap), portal./admin.
  komplett gesperrt; `X-Robots-Tag: noindex` auf /admin, /login, /portal,
  /partner, /register.
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
   der frueheren IP-Links). Optional `WEBSITE_CANONICAL_HOST`,
   `WEBSITE_EXTRA_HOSTS` (Staging).
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
  Medien). Bis dahin: starke Passwoerter, Zugriff idealerweise per
  Cloudflare Access zusaetzlich schuetzen.
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

`tests/Feature/WebsiteMergeTest.php` (22) + `tests/Feature/MediaLibraryTest.php` (10):
Startseite DE/AR, Redirects, Rechtsseiten, Formular inkl. Einwilligung/
Honeypot/Mails, Purge, robots/sitemap/noindex, hreflang, Umlaut-Reparatur,
Storage-URLs, Medien-Upload/Varianten/Slots/MIME/SVG/Papierkorb/Rollen.
Gesamtsuite: 1315 Tests gruen.
