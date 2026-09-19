# SEO & Online-Sichtbarkeit: Bestandsaufnahme und Massnahmen

Auftrag des Betreibers vom 02.10.2026 ("Master SEO & Online Visibility
Project"). Dieses Dokument haelt fest, **was tatsaechlich geprueft werden
konnte**, was geaendert wurde und was offen bleibt.

---

## 0. Was diese Bestandsaufnahme ist - und was sie NICHT ist

Die Analyse konnte **nur am Quelltext** durchgefuehrt werden, nicht an der
laufenden Seite: die Arbeitsumgebung dieser Sitzung hat keinen Netzzugang
zu `www.dienstly24.de` und `portal.dienstly24.de` (der Egress-Proxy lehnt
beide Hosts ab).

**Damit ist folgendes AUSDRUECKLICH NICHT geprueft** - und darf auch nicht
als geprueft dargestellt werden:

| Punkt des Auftrags | Warum nicht pruefbar |
| --- | --- |
| Bestehende Google-Indexierung, Rankings, Impressions/Clicks | Braucht Zugang zur Search Console des Betriebs |
| Bestehende Backlinks | Braucht ein Backlink-Werkzeug mit Zugangsdaten |
| Core Web Vitals / LCP / INP / CLS in der Praxis | Feldwerte kommen aus dem Chrome-Bericht, nicht aus dem Code |
| 404-Fehler und Weiterleitungen im Bestand | Braucht einen echten Crawl der Live-Domain |
| Google Business Profile (Kategorien, Fotos, Bewertungen) | Braucht Anmeldung am Profil |
| Verzeichnis-Eintraege / NAP-Konsistenz extern | Liegt ausserhalb des Repositories |

Was der Code sagt, ist dagegen belastbar: er ist die Quelle dessen, was der
Server ausliefert. Alles unten Genannte ist an den Vorlagen, Routen und
Controllern nachgelesen.

---

## 1. Ausgangslage (Befunde)

### Was bereits gut war

Der Bestand ist deutlich besser als bei den meisten Projekten dieser
Groesse - das gehoert zur Ehrlichkeit dazu:

- `robots.txt` und `sitemap.xml` sind **dynamisch** (`SeoController`) und
  werden aus den echten Inhalten erzeugt, nicht von Hand gepflegt.
  Nur der kanonische Host ist offen; Staging- und Vorschau-Hosts liefern
  `Disallow: /`.
- Canonical und `hreflang` (de/ar/x-default) stehen auf **jeder**
  oeffentlichen Seite und zeigen immer auf den kanonischen Host.
- Die arabische Fassung liegt unter **echten URLs** (`/ar/...`), nicht
  hinter einem Sitzungsschalter.
- Strukturierte Daten (`InsuranceAgency`, `FAQPage`) sind vorhanden und
  seit dem Audit 15.09.2026 durch zwei Waechter-Tests gesichert.
- Keine externen Ressourcen auf den Website-Seiten (Schriften lokal) -
  gut fuer Ladezeit, Datenschutz und CSP.
- Die 21 Leistungsseiten tragen **echten, eigenstaendigen Fachtext** in
  beiden Sprachen; keine Keyword-Kopien.

### Die Luecken, die gefunden wurden

| # | Befund | Wirkung |
| --- | --- | --- |
| B1 | Titel der Leistungsseiten lautete `Dienstly24 — <Leistung>` | Google kuerzt von rechts; 21-mal stand der Markenname vor dem Suchwort |
| B2 | `/leistungen` hatte **gar keine** Meta-Beschreibung | Google baut sich einen Auszug aus Ueberschriften ohne Satzbau |
| B3 | Kein `og:image`, kein Twitter-Card auf den Leistungsseiten | Jedes Teilen bei WhatsApp/Facebook zeigte einen grauen Kasten |
| B4 | Keine Brotkrumen, kein `BreadcrumbList` | In der Trefferliste stand die nackte URL statt des Pfads |
| B5 | Leistungsseiten waren **Sackgassen** (einziger Ausgang: "Alle Leistungen") | Kfz-Versicherung erreichte Kfz-Zulassung nie; flache interne Verlinkung |
| B6 | Auf `/ar/leistungen` verlinkten alle Kacheln auf die **deutschen** Adressen (`route()` liefert immer den DE-Namen) | Der arabische Besucher verliess seine Sprachversion; Google sah eine AR-Seite, die nur DE-Seiten verlinkt |
| B7 | Der Sprachumschalter fuehrte auf `/sprache/ar` (Sitzung) statt auf die Adresse aus dem `hreflang` | Zwei Inhalte unter einer Adresse; die AR-Fassung war ueber die Oberflaeche nicht als eigene Seite erreichbar |
| B8 | Fuss der Leistungsseiten: nur Impressum, Datenschutz, Portal | Bei Versicherungsvermittlung erwarten Nutzer **und** Google Erstinformation, AGB, Widerruf |
| B9 | Kein Telefon-/WhatsApp-Weg auf den Leistungsseiten, nur ein Formular mit bis zu acht Feldern | Auf dem Telefon der laengste statt des kuerzesten Wegs zur Anfrage |
| B10 | **Keine Seite fuer "Versicherungsmakler"** | Das wichtigste kommerzielle Suchwort des Betriebs hatte keine Landingpage |
| B11 | `sameAs` nur mit Facebook, fest verdrahtet | Keine Entity-Verbindung zu weiteren Profilen |
| B12 | Kein `WebSite`-Schema, keine `Organization` auf Unterseiten | Auf 21 Seiten war nicht ausgezeichnet, wer spricht |

**Kein Befund war ein Fehler im Sinne einer Stoerung.** Keiner erzeugte
einen 500er, eine Konsolenmeldung oder ein kaputtes Layout. Genau deshalb
standen sie monatelang unbemerkt da - und genau deshalb braucht diese
Klasse einen Test, nicht eine Sichtpruefung.

---

## 2. Portal und Hauptdomain: die Verbindung (Auftrag Abschnitt 2)

Vollstaendig erfasst, **nichts entfernt**:

| Von | Nach | Zweck |
| --- | --- | --- |
| `layout.blade.php` Kopfzeile | `https://portal.dienstly24.de/login` | Login-Knopf |
| `layout.blade.php` Fuss | `https://portal.dienstly24.de/login` | "Kundenportal" |
| `services/show.blade.php` Fuss | `route('login')` | "Kundenportal" |
| `services/index.blade.php` Fuss | `route('login')` | "Kundenportal" |

**Wichtig fuer das Verstaendnis der Architektur:** Die Marketing-Website
und das Portal sind **dieselbe Laravel-Anwendung** auf zwei Hosts
(Website-Merge 30.07.2026). Es gibt deshalb **keinen Duplicate Content
zwischen beiden**: `RedirectWebsiteHost` und die Host-Logik in
`WebsiteHosts` entscheiden pro Host, was ausgeliefert wird, und
`robots.txt` liefert auf dem Portal-Host `Disallow: /`. Die Behauptung aus
dem Auftrag, Leistungen wuerden "aktuell zum Portal fuehren", trifft in
dieser Form **nicht** zu: die Zahnzusatzversicherung liegt als
`/leistungen/zahnzusatzversicherung` auf der Hauptdomain; ins Portal
fuehren ausschliesslich die Login-Links oben.

**Zielarchitektur ist damit bereits erfuellt** und wurde nicht angetastet:
Inhalt und Suchintention auf `www`, Prozess und Kundenkonto auf `portal`.

---

## 3. Umgesetzte Aenderungen (Vorher/Nachher)

### 3.1 Leistungsseiten `/leistungen/{slug}` (21 Seiten + 1 neue)

| Merkmal | Vorher | Nachher |
| --- | --- | --- |
| Title | `Dienstly24 — Kfz-Versicherung` | `Kfz-Versicherung \| Dienstly24` |
| Beschreibung | vorhanden | unveraendert (bereits gut) |
| Open Graph | Typ, Titel, Beschreibung, URL | zusaetzlich `og:site_name`, `og:image` (+Masse), `og:locale` (+alternate) |
| Twitter | fehlte | `summary_large_image` mit Titel, Beschreibung, Bild |
| Brotkrumen | fehlten | sichtbar **und** als `BreadcrumbList` |
| Schema | `Service`, `FAQPage` | zusaetzlich `Organization`, `BreadcrumbList` |
| Interne Links | 1 (Alle Leistungen) | + 4 verwandte Leistungen (gleiche Kategorie zuerst) |
| Kontakt | nur Formular | Telefon, WhatsApp, Formular-Sprung als Knoepfe |
| Sprachwechsel | `/sprache/ar` (Sitzung) | echte Adresse `/ar/leistungen/{slug}` |
| Fuss | 3 Links | + Erstinformation, AGB, Widerruf, Alle Leistungen, Vermittler-Hinweis |

### 3.2 Uebersicht `/leistungen`

Meta-Beschreibung (DE+AR) ergaenzt, Titelformat angeglichen, Open
Graph/Twitter, Brotkrumen, `Organization` + `BreadcrumbList`, vollstaendiger
Rechts-Fuss, und die Kacheln verlinken in der arabischen Fassung auf die
arabischen Adressen (Befund B6).

### 3.3 Startseite

`WebSite`-Schema ergaenzt - **nur dort**. Eine Website gibt es einmal, nicht
22-mal. Die Organisation steckt bereits im `InsuranceAgency`-Block.

### 3.4 Neue Landingpage `/leistungen/versicherungsmakler`

Eigenstaendiger Inhalt in DE und AR: Unterschied Makler/Vertreter/Portal,
Bedeutung der Maklervollmacht, Ablauf der Beratung, benoetigte Unterlagen,
Kosten (Courtage), deutschlandweit mit Sitz in Hamburg, 5 echte FAQ.

> **Diese Seite bleibt bewusst hinter der bequemen Formulierung zurueck.**
> Laut `/erstinformation` haelt **Dienstly24 die Erlaubnis nach § 34d GewO
> nicht selbst**, sondern vermittelt als vertraglich gebundener Vermittler
> unter der Haftung von NESA Versicherung und Finanzen. Die Seite sagt das
> ausdruecklich und verlinkt die Erstinformation. "Wir sind Ihr
> Versicherungsmakler mit Erlaubnis nach § 34d" waere die staerkere
> Ueberschrift - und eine falsche Angabe ueber das eigene Unternehmen.
> Ein Test haelt das fest.

### 3.5 Zwei Fehler, die erst der Browser gezeigt hat

Beide betrafen ausschliesslich die **arabische** Fassung, beide erzeugten
keinen Fehler - deshalb hat sie kein Test gefunden, sondern erst die
Ansicht im simulierten iPhone:

1. **Die Brotkrumen standen auf Deutsch** ("Startseite › Leistungen ›
   وسيط تأمين"). `lang/ar.json` kannte die Woerter schlicht nicht, und
   `__()` gibt dann den Schluessel zurueck - ohne Warnung. Dieselbe Luecke
   wie bei `lang/ar/validation.php` (18.08.2026) und
   `lang/de/validation.php` (10.09.2026). Fuenf Schluessel ergaenzt.
2. **Die Telefonnummer wurde verdreht angezeigt**: im arabischen
   Fliesstext dreht die Zweirichtungs-Regel die Zifferngruppen, aus
   `+49 179 9673909` wurde `9673909 179 49+`. Das ist keine
   Schoenheitsfrage - eine so abgetippte Nummer erreicht niemanden. Die
   Nummer steht jetzt ausdruecklich `dir="ltr"` (dieselbe Regel wie bei
   den Signaturseiten).

Beide sind als Test festgehalten.

### 3.6 Konfiguration

`config/website.php` bekommt `social` als **eine** Quelle fuer `sameAs`.
Weitere Profile kommen aus der `.env` (`WEBSITE_INSTAGRAM`,
`WEBSITE_LINKEDIN`, `WEBSITE_YOUTUBE`, `WEBSITE_GOOGLE_BUSINESS`) und
**ohne Standardwert**: ein erfundenes Profil waere eine falsche
Unternehmensangabe, und Google prueft `sameAs`.

---

## 4. Abschlussbericht: Seitentabelle

| URL | Zweck | Hauptkeyword | Suchintention | Title | H1 | Schema | Status |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `/` | Einstieg, Marke, Ueberblick | dienstly24 / versicherung beratung arabisch | Navigational + kommerziell | Dienstly24 – Versicherung, Kfz-Zulassung & Energie | Alle Versicherungen. Ein Ansprechpartner. | InsuranceAgency, FAQPage, **WebSite** | live |
| `/leistungen` | Verteiler auf alle Sparten | versicherungen leistungen | Informativ | Unsere Leistungen \| Dienstly24 | Unsere Leistungen | **Organization, BreadcrumbList** | **neu ausgezeichnet** |
| `/leistungen/versicherungsmakler` | Beratung, Vertrauen, Abschluss | versicherungsmakler | Kommerziell | Versicherungsmakler \| Dienstly24 | Versicherungsmakler | Service, FAQPage, Organization, BreadcrumbList | **NEU** |
| `/leistungen/kfz-versicherung` | Sparte | kfz-versicherung | Kommerziell + informativ | Kfz-Versicherung \| Dienstly24 | Kfz-Versicherung | Service, FAQPage, Organization, BreadcrumbList | optimiert |
| `/leistungen/krankenversicherung` | Sparte | krankenversicherung | Informativ + kommerziell | Krankenversicherung \| Dienstly24 | Krankenversicherung | dito | optimiert |
| `/leistungen/zahnzusatzversicherung` | Sparte | zahnzusatzversicherung | Kommerziell | Zahnzusatzversicherung \| Dienstly24 | Zahnzusatzversicherung | dito | optimiert |
| `/leistungen/kfz-zulassung` | Dienstleistung | kfz-zulassung service | Transaktional | Kfz-Zulassung \| Dienstly24 | Kfz-Zulassung | dito | optimiert |
| `/leistungen/kennzeichen-per-post` | Dienstleistung | kennzeichen per post | Transaktional | Kennzeichen per Post \| Dienstly24 | Kennzeichen per Post | dito | optimiert |
| `/leistungen/strom-gas` | Energie | stromanbieter wechseln | Kommerziell | Strom & Gas \| Dienstly24 | Strom & Gas | dito | optimiert |
| `/leistungen/*` (14 weitere) | Sparten | je Sparte | gemischt | `<Sparte> \| Dienstly24` | `<Sparte>` | dito | optimiert |
| `/ar/...` | arabische Fassung aller obigen | je Sparte (AR) | gemischt | dito (AR) | dito (AR) | dito | optimiert |
| `/impressum`, `/datenschutz`, `/agb`, `/widerruf`, `/erstinformation`, `/cookie-richtlinie`, `/bildnachweise` | Pflichtangaben, E-E-A-T | - | - | je Seite | je Seite | - | unveraendert |

---

## 5. Keyword-Zuordnung (eine Seite, eine Absicht)

Grundregel: **eine Suchabsicht = eine Seite.** Zwei Seiten fuer dasselbe
Wort nehmen sich gegenseitig die Sichtbarkeit.

| Seite | Hauptkeyword | Sekundaer / Long-Tail | Absicht |
| --- | --- | --- | --- |
| `/leistungen/versicherungsmakler` | versicherungsmakler | unabhaengiger versicherungsmakler, maklervollmacht, versicherungsmakler arabisch, versicherungsberatung kostenlos | kommerziell |
| `/leistungen/kfz-versicherung` | kfz-versicherung | haftpflicht teilkasko vollkasko unterschied, kfz versicherung wechseln, sf-klasse, evb nummer | kommerziell + informativ |
| `/leistungen/krankenversicherung` | krankenversicherung | gesetzlich oder privat, pkv gkv unterschied, krankenversicherung wechseln | informativ |
| `/leistungen/zahnzusatzversicherung` | zahnzusatzversicherung | zahnersatz erstattung, zahnzusatzversicherung sinnvoll, wartezeit | kommerziell |
| `/leistungen/kfz-zulassung` | kfz-zulassung | auto anmelden unterlagen, ummeldung, abmeldung, zulassungsdienst | transaktional |
| `/leistungen/kennzeichen-per-post` | kennzeichen per post | wunschkennzeichen, kennzeichen bestellen | transaktional |
| `/leistungen/strom-gas` | strom und gas wechseln | stromanbieter wechseln sparen, gasanbieter vergleich, grundversorgung | kommerziell |
| Startseite | dienstly24 | versicherung beratung arabisch, versicherungsmakler hamburg arabisch | navigational |

**Bewusst NICHT belegt:** Suchvolumina. Sie liegen ohne Zugang zu einem
Keyword-Werkzeug nicht vor, und geschaetzte Zahlen in einer Tabelle sehen
aus wie gemessene. Die Zuordnung oben beruht auf Suchabsicht und
Geschaeftsrelevanz - beides ist aus dem Bestand belegbar.

---

## 6. Offen - und warum bewusst nicht jetzt gebaut

### 6.1 Lokale Seite Hamburg

Der Auftrag verlangt sie (Abschnitt 5) und sie hat echten lokalen Bezug
(das Buero existiert). Sie ist trotzdem **noch nicht gebaut**, weil eine
Local-Landingpage ohne die Grundlagen wirkungslos bleibt: Google zieht
lokale Sichtbarkeit ueberwiegend aus dem **Business-Profil** und den
Bewertungen, nicht aus einer weiteren Unterseite. Die richtige Reihenfolge
ist deshalb: Business-Profil vollstaendig (Prioritaet 3), dann die lokale
Seite. Sonst entsteht eine Seite ohne Signal dahinter - und das ist die
Vorstufe einer Doorway Page.

**Wenn sie gebaut wird**, dann als eigene Route
`/versicherungsmakler-hamburg` mit echtem lokalem Inhalt: Anfahrt, Termine
vor Ort, Bezug zur Zulassungsstelle Hamburg, arabischsprachige Beratung im
Stadtteil - **nicht** als Textkopie der nationalen Seite mit eingesetztem
Stadtnamen.

### 6.2 Ratgeber / Content Hub (Abschnitt 27)

Vorbereitet, aber nicht angefangen: ein Ratgeber mit drei duennen Artikeln
schadet mehr als er nutzt. Empfohlener Start mit je einem vollstaendigen
Artikel zu den drei Fragen, die der Betrieb im Alltag am haeufigsten
beantwortet - die Antworten liegen bereits in der KI-Wissensbasis und in
den Tickets.

### 6.3 Tracking (Abschnitt 30/31) - ERLEDIGT

**Entscheidung des Betreibers vom 18.09.2026: Matomo auf dem EIGENEN
Server**, nicht Google Analytics 4. Begruendung in `config/analytics.php`,
Einrichtung in `docs/ANLEITUNG_MATOMO_AR.md`.

Der Ausschlag gab die Inhaltsrichtlinie: GA4 braucht ein Skript von
`googletagmanager.com`, also genau die Fremdhost-Freigabe, die Audit SEC-4
am 03.09.2026 unter erheblichem Aufwand entfernt hat. Dazu kommt, dass GA4
Kennungen setzt und damit eine Einwilligung braucht - wer ablehnt, wird
nicht gemessen. Matomo laeuft hier ohne Kennungen (`disableCookies`) und
misst deshalb alle Besucher gleich.

Gemessen wird (`resources/views/partials/matomo.blade.php`):

- Seitenaufrufe der oeffentlichen Website
- die Kontaktwege als Ereignisse: ein einziger Zuhoerer am Dokument wertet
  `data-cta`/`data-cta-seite` aus, neue Knoepfe zaehlen ohne Codeaenderung
  mit
- der Uebergang ins Portal ueber `enableLinkTracking` - **ohne dass im
  Portal selbst irgendetwas gemessen wird**

**Nie gemessen werden Beraterwelt, Kundenportal und Partnerportal.** Dort
stehen Kundendaten in Seitentiteln und Adressen (`/admin/customers/4711`),
und ein Messwerkzeug schreibt beides mit. Das ist keine Einstellung, die
jemand vergessen kann: das Partial steht ausschliesslich in den vier
Vorlagen der Website, und ein Test prueft beides - dass die
Anwendungsbereiche nichts ausliefern und dass die Vorlage dort nicht
eingebunden ist.

**Ohne Einrichtung wird kein Byte ausgeliefert** und kein Host in die
Richtlinie geschrieben. `App\Support\Matomo` prueft die Adresse so streng
wie SEC-5 den `legal_external_base`: nur https, ohne Zugangsdaten, ohne
Abfrage, ohne Fragment, Seitennummer nur als Zahl. Ein Tippfehler in der
`.env` darf keinen fremden Skript-Host in `script-src` schreiben - im
Zweifel bleibt die Messung aus. Lieber keine Zahlen als ein fremder
Skript-Host.

Tests: `MatomoMessungTest` (12 Faelle).

### 6.4 Alles ausserhalb des Repositories

Business-Profil, Search Console, Verzeichnisse, Bewertungen, Backlinks:
siehe `docs/ANLEITUNG_SEO_AR.md` (arabische Schritt-fuer-Schritt-Anleitung
fuer den Betreiber).

---

## 7. Die naechsten 10 Massnahmen nach Prioritaet

1. **Google Search Console einrichten** und `sitemap.xml` einreichen -
   ohne sie ist jede weitere Aussage ueber Rankings geraten.
2. **Google Business Profile vervollstaendigen**: Hauptkategorie
   "Versicherungsmakler", Leistungen, Beschreibung, echte Fotos,
   Oeffnungszeiten.
3. **Live-Crawl der Domain** (Screaming Frog o. ae.) fuer 404, Ketten,
   Ladezeiten - die einzigen Punkte des Auftrags, die im Code nicht
   sichtbar sind.
4. **NAP-Konsistenz** herstellen: Name, Anschrift, Telefon identisch auf
   Website, Profil und allen Verzeichnissen.
5. **Bewertungsprozess** aufsetzen: nach jeder abgeschlossenen Leistung
   einmalig um eine Bewertung bitten - nie Gating, nie gekauft.
6. **Lokale Seite Hamburg** bauen (nach Punkt 2).
7. **Matomo auf dem VPS installieren** (entschieden am 18.09.2026, Code
   ist fertig) - `docs/ANLEITUNG_MATOMO_AR.md`.
8. **Seriöse Verzeichnis-Eintraege**: Handelsregister-nahe Portale,
   Hamburger Branchenverzeichnisse, thematische Fachportale.
9. **Ratgeber** mit drei vollstaendigen Artikeln starten.
10. **Monatliche Auswertung** in der Search Console: Queries, CTR,
    Positionen - und daraus die naechsten Titel/Beschreibungen ableiten.

---

## 8. Was ausdruecklich nicht gemacht wurde

Keine kuenstlichen Stadtseiten, keine Doorway Pages, kein Keyword-Stuffing,
keine erfundenen Bewertungen, Standorte oder Registernummern, keine
gekauften Links, keine Ranking-Zusage. Der Auftrag verlangt das so, und es
ist im Versicherungsbereich ausserdem die einzige Variante, die eine
Aufsichtspruefung ueberlebt.

Tests: `SeoSichtbarkeitTest`, ergaenzend `StructuredDataTest`,
`WebsiteMergeTest`.
