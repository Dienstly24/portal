# Cookie-Einwilligung, Matomo und Datenschutz - Umsetzungsbericht

Betreiber-Auftrag vom 19.09.2026. Dieses Dokument ist der Abschlussbericht
zum Code-Teil und die Arbeitsanweisung fuer den Server-Teil.

> **WAS ICH NICHT TUN KONNTE, UND WARUM ES HIER TROTZDEM STEHT.**
> Diese Arbeitsumgebung hat **keinen SSH-Zugang zum Hostinger-VPS und
> keinen Netzzugang zu dienstly24.de**. Alles, was auf dem Server
> passiert - Matomo installieren, Subdomain anlegen, Zertifikat holen,
> `.env` fuellen - konnte ich deshalb **nicht ausfuehren**. Es steht als
> fertige Befehlsfolge in `docs/ANLEITUNG_MATOMO_AR.md` und ist in
> weniger als einer Stunde abgearbeitet.
> Eine geschaetzte Zahl in einem Umsetzungsbericht sieht aus wie eine
> gemessene. Was nicht geprueft werden konnte, steht deshalb
> ausdruecklich als **nicht geprueft** da, nicht als Erfolg.

---

## 1. Was jetzt gilt (Kurzfassung)

| Frage | Antwort |
| --- | --- |
| Wird ohne Einwilligung gemessen? | **Nein.** `matomo.js` wird vor der Entscheidung gar nicht erst angefordert - im Browser nachgemessen, siehe Abschnitt 6. |
| Gibt es zwei Banner? | Nein. **Ein** Banner fuer Website und Portal, kein eigener Matomo-Hinweis. |
| Fremddienst fuer die Einwilligung? | Nein. Kein Consent-Anbieter, kein externes Skript, kein neuer Host in der Inhaltsrichtlinie. |
| Blockiert eine Ablehnung irgendetwas? | Nein. Website, Portal, Login, Formulare, Kontaktwege funktionieren unveraendert. |
| Wo steht die Entscheidung? | In einem Cookie auf der gemeinsamen Domain `dienstly24.de` - Website und Portal fragen zusammen nur einmal. |
| Widerruf? | Link "Cookie-Einstellungen" im Fuss jeder Seite, Art. 7 Abs. 3 DSGVO. |
| Ist Matomo schon installiert? | **Nein** - Server-Teil, siehe oben. Solange `MATOMO_URL` leer ist, gibt es weder Skript noch Banner. |

---

## 2. Die eine Quelle: `App\Support\Consent`

Drei Entscheidungen, die dort begruendet stehen und jede fuer sich
haltbar sein muss:

1. **Nur Kategorien, die es wirklich gibt.** Der Banner kennt
   `notwendig` und `statistik` - keine Werbung, kein Marketing, keine
   externen Medien, weil nichts davon eingebunden ist. Eine Kategorie
   aufzufuehren, die leer ist, waere eine falsche Aussage ueber das
   eigene Unternehmen.
2. **Kein Banner ohne einwilligungspflichtige Technik**
   (`Consent::optionaleDiensteVorhanden()`). Ist Matomo nicht
   eingerichtet, erscheint **kein** Banner - eine Abfrage ohne Gegenstand
   waere ein Klick ins Leere, und ihr Text beschriebe einen Dienst, den es
   nicht gibt. Sobald `MATOMO_URL` gesetzt wird, erscheint der Banner von
   selbst. Niemand muss daran denken.
3. **Der Altbestand wird geerbt.** Das alte Cookie `cookie_consent`
   (`all` / `essential`) aus der Zwei-Knopf-Fassung wird weiter gelesen;
   sein Text nannte bereits "Statistik". Wer damals zugestimmt hat, wird
   nicht erneut gefragt, und wer abgelehnt hat, bleibt abgelehnt.

Format des Cookies: `v1:statistik` bzw. `v1:` (abgelehnt). Die
Versionsmarke `v1` ist Absicht - kommt je eine Kategorie hinzu, ist jede
alte Einwilligung automatisch ungueltig, statt stillschweigend auf etwas
Neues ausgedehnt zu werden.

---

## 3. Cookie-Inventar (im Browser ausgelesen, nicht angenommen)

Gemessen mit Chromium auf der ausgelieferten Seite, vor und nach der
Zustimmung.

### Vorher / Nachher

| Cookie | Kategorie | Zweck | Laufzeit | Gesetzt von | Vor Entscheidung? |
| --- | --- | --- | --- | --- | --- |
| `<app>-session` | notwendig | Sitzung; darin auch die Sprachwahl DE/AR | Sitzung, httpOnly | Server | ja |
| `XSRF-TOKEN` | notwendig | Schutz vor Formularmissbrauch (CSRF) | 120 Min | Server | ja |
| `dienstly24_consent` | notwendig | speichert AUSSCHLIESSLICH die Auswahl | 365 Tage | Banner | nein - erst mit der Entscheidung |
| `remember_web_*` | notwendig | "Angemeldet bleiben", nur im Portal und nur auf eigene Wahl | 5 Jahre, httpOnly | Server | nein |
| **Matomo** | statistik | **setzt KEIN Cookie** (`disableCookies`) | - | - | nein |

`localStorage`/`sessionStorage` der oeffentlichen Website: **leer** (vor
und nach der Zustimmung gemessen). Im angemeldeten Bereich gibt es
`d24-ki-panel` und `email_onboarding_dismissed` - reine
Bedienzustaende eines Mitarbeiters, keine Besuchermerkmale.

### Fremde Hosts auf der oeffentlichen Website

| Vor der Entscheidung | Nach Zustimmung | Nach Ablehnung |
| --- | --- | --- |
| **keiner** | `analytics.<domain>/matomo.js` (unser eigener Server) | **keiner** |

Schriftarten, Bilder, Skripte und Stile kommen ausnahmslos vom eigenen
Server. Es gibt keine Google Fonts, keine CDN-Einbindung, kein
Werbenetzwerk und keinen Tag-Manager.

---

## 4. Was gebaut wurde

| Datei | Aenderung |
| --- | --- |
| `app/Support/Consent.php` | **neu** - die eine Quelle fuer Kategorien, Cookie-Format, Domain, Einwilligungspflicht |
| `resources/views/partials/cookie_consent.blade.php` | vollstaendig neu: drei Knoepfe, Einstellungen, selbsttragendes CSS/JS mit Nonce, `window.d24Consent` |
| `resources/views/partials/matomo.blade.php` | startet erst im Rueckruf von `d24Consent`; fehlt der Banner, wird **nicht** gemessen (fail-closed) |
| `config/analytics.php` | `requires_consent`, `log_retention_days` |
| `resources/views/website/legal/datenschutz.blade.php` | Abschnitt "Cookies und Einwilligung", Matomo-Block haengt an `Matomo::aktiv()` |
| `resources/views/website/legal/cookie_richtlinie.blade.php` | vollstaendiges Inventar in fuenf Abschnitten; der alte Satz "kein Cookie-Banner erforderlich" ist raus |
| `website/layout`, `legal-layout`, `services/index`, `services/show` | Banner **vor** der Messung eingebunden, Fusszeilen-Link "Cookie-Einstellungen" |
| `resources/views/website/hamburg.blade.php` + Route + Sitemap | die lokale Hamburg-Seite (Abschnitt 8) |

**Nicht angefasst**: URLs, SEO-Struktur, Formulare, Kundenprozesse,
Versicherungsprozesse, Portal-Logik, Anmeldung, Datenbankstruktur. Es
gibt in diesem Stand **keine Migration**.

---

## 5. Warum die Messung nicht einfach "spaeter abschaltbar" ist, sondern gar nicht erst startet

Der uebliche Fehler ist, das Messskript zu laden und ihm danach zu
sagen, es solle nichts senden. Dann ist die Verbindung zum Messserver
aber schon aufgebaut, die IP-Adresse schon uebertragen und der Besuch je
nach Konfiguration schon gezaehlt.

Hier haengt der **Ladevorgang selbst** im Rueckruf:
`window.d24Consent.beiFreigabe('statistik', starten)`. Vor der
Zustimmung existiert kein `<script>`-Element, keine Verbindung, kein
Eintrag. Und fehlt der Banner auf einer Seite (Einbindungsfehler), ist
`window.d24Consent` schlicht nicht da - dann wird **nicht** gemessen.
Die sichere Richtung ist immer "nicht messen".

---

## 6. Nachweis im Browser (Chromium, ausgelieferte Seite)

| Zustand | Anfragen an den Matomo-Host | Cookie | Banner |
| --- | --- | --- | --- |
| vor der Entscheidung | **0** | keins | sichtbar |
| nach "Alle akzeptieren" | 1 (`matomo.js`) | `v1:statistik`, 365 Tage | weg |
| nach "Ablehnen" | **0** | `v1:` | weg |
| zweite Seite nach Zustimmung | misst weiter | unveraendert | erscheint nicht erneut |
| nach Widerruf ueber den Fusszeilen-Link | **0** | `v1:` | weg |

Ebenfalls im Browser gefunden und behoben (kein Test hatte es gemeldet):
der Einstellungen-Block stand auf dem Telefon dauerhaft offen, weil
`[hidden]` gegen eine Klasse mit eigenem `display:grid` verliert - genau
die Falle aus SEC-4. Das Partial traegt sein CSS selbst, die Regel aus
`app.css` gilt dort nicht; sie steht jetzt im Partial.

Geprueft in DE und AR, am Desktop und am simulierten iPhone: kein
waagerechter Bildlauf, keine JavaScript-Fehler, arabische Fassung RTL
mit den Knoepfen **[قبول الكل] [رفض] [الإعدادات]**.

### Nachtrag 19.09.2026: die Anmeldeseite war blockiert

Der erste Durchgang hatte nur die oeffentliche Website im Browser
angesehen - die Anmeldeseite und das Portal nicht, obwohl der Banner
auch dort steht. Genau dort lag ein Verstoss gegen die Bedingung
"eine Ablehnung darf niemanden am Anmelden hindern":

Auf dem iPhone lag die Leiste ueber dem **gesamten** Anmeldeformular
samt Knopf, und zwar unerreichbar. Nachgerechnet: die Seite ist 808 px
hoch, das Fenster 664 px - es waren also 144 px Bildlauf moeglich, der
Knopf bei 522 px blieb danach immer noch unter der Leiste. Anmelden war
ohne Beantworten der Cookie-Frage **nicht moeglich**. Am Desktop war
alles in Ordnung; nur deshalb fiel es nicht auf.

Drei Aenderungen, alle im Partial selbst - keine Vorlage musste
angefasst werden:

1. **Der Banner reserviert seinen Platz selbst.** Er misst seine Hoehe
   und haengt sie unten an den Seiteninhalt; beim Entscheiden gibt er
   sie wieder frei. GEMESSEN, nicht geschaetzt: die Hoehe haengt an
   Sprache, Schriftgroesse und daran, ob die Einstellungen offen sind -
   deshalb auch bei `resize` erneut. Ohne diesen Schritt war das untere
   Ende jeder Seite dauerhaft unerreichbar.
2. **Nur der TEXT scrollt, nie die Knoepfe.** Vorher lag der ganze
   Kasten im Bildlauf; auf dem Telefon konnte damit ausgerechnet
   "Ablehnen" unter die Kante rutschen. Eine Ablehnung, die schwerer zu
   erreichen ist als eine Zustimmung, ist keine freiwillige
   Einwilligung (Art. 4 Nr. 11 DSGVO).
3. **Zwei Knopfreihen statt drei** auf schmalen Bildschirmen: die
   Leiste ist damit 48 % statt 57 % des Telefonbildschirms hoch. Dieser
   Platz fehlt der Seite darunter, solange die Frage offen ist.

**Ehrlich zur Grenze:** eine Leiste am unteren Rand verdeckt auf einem
Telefon zwangslaeufig den unteren Teil des Sichtfensters, solange sie
steht. Was jetzt gilt und gemessen ist: **nichts wird unerreichbar**,
und mit einer Antwort - auch "Ablehnen" - ist die Leiste sofort weg.
Zugesagt wird genau das, nicht mehr.

Tests: `test_der_banner_reserviert_seinen_platz`,
`test_nur_der_text_scrollt_nie_die_knoepfe`,
`test_auf_der_anmeldeseite_wird_nie_gemessen` - alle drei sind ohne die
Behebung rot.

---

## 7. Verarbeitungsverzeichnis - fertige Angaben zum Uebernehmen

Zum Einsetzen in das Verzeichnis nach Art. 30 DSGVO, sobald Matomo
laeuft:

- **Bezeichnung der Verarbeitung:** Reichweitenmessung der oeffentlichen
  Website mit selbst betriebener Analyse-Software (Matomo)
- **Verantwortlicher:** Dienstly24, Ahmad Albhre, Furtweg 51a,
  22523 Hamburg
- **Zweck:** statistische Auswertung der Websitenutzung zur Verbesserung
  der Inhalte und der Kontaktwege
- **Rechtsgrundlage:** Art. 6 Abs. 1 lit. a DSGVO (Einwilligung),
  § 25 Abs. 1 TTDSG
- **Betroffene:** Besucher der oeffentlichen Website
  (`www.dienstly24.de`). **Nicht** Nutzer des Kundenportals, der
  Beraterwelt oder des Partnerportals - dort wird nicht gemessen
- **Datenkategorien:** aufgerufene Seiten, Zeitpunkt und Verweildauer,
  gekuerzte IP-Adresse (2 Bytes entfernt), ungefaehre Region,
  Geraetetyp, Browser, Betriebssystem, verweisende Seite bzw.
  Suchmaschine, Klicks auf Kontaktwege (Telefon, WhatsApp,
  Kontaktformular, Portal-Uebergang), Sprache der Fassung (DE/AR).
  **Keine** Formularinhalte, **keine** Namen, **keine**
  Vertragsdaten, **keine** Kennung im Browser
- **Empfaenger:** keine. Die Software laeuft auf dem eigenen Server;
  es findet keine Uebermittlung an Dritte statt
- **Drittlandtransfer:** keiner
- **Loeschfrist:** Rohdaten nach `MATOMO_LOG_RETENTION_DAYS` Tagen
  (Voreinstellung 180); danach bleiben nur aggregierte Statistiken ohne
  Personenbezug
- **Technisch-organisatorische Massnahmen:** TLS-Verschluesselung,
  Zugriff nur fuer Administratoren mit Zwei-Faktor-Anmeldung,
  IP-Kuerzung vor der Speicherung, "Do Not Track" des Browsers wird
  beachtet, keine Cookies, keine Profilbildung, kein Zusammenfuehren mit
  anderen Datenbestaenden
- **Auftragsverarbeiter:** fuer Matomo keiner. Fuer den Betrieb des
  Servers und der Datenbank ist der Hoster Auftragsverarbeiter -
  siehe Abschnitt 9

---

## 8. Hamburg-Seite (lokale Landingpage)

`/versicherungsmakler-hamburg` und `/ar/versicherungsmakler-hamburg`.
Sie war im SEO-Auftrag bewusst zurueckgestellt worden; der Betreiber hat
sie am 19.09.2026 angefordert.

- **Keine Doorway Page**: eigene Inhalte (Buero, Anfahrt,
  Oeffnungszeiten, was ein Termin vor Ort bringt, fuenf lokale Fragen),
  **kein** kopierter Text von den Spartenseiten - ein Test haelt das
  fest. Es entsteht **eine** Ortsseite, weil es **einen** Standort gibt.
  Keine kuenstlichen Stadtseiten.
- **Sie bleibt bei der Wahrheit**: laut `/erstinformation` haelt
  Dienstly24 die Erlaubnis nach § 34d GewO **nicht selbst**, sondern
  vermittelt als vertraglich gebundener Vermittler unter der Haftung von
  NESA Versicherung und Finanzen. Die Seite sagt das ausdruecklich und
  verlinkt die Erstinformation - auch das ein Test.
- Nur echte Angaben: Anschrift und Telefon aus `config/website.php`,
  Oeffnungszeiten wie im Betrieb. Keine Bewertungen, keine Zahlen ueber
  Kundenmengen, keine erfundenen Auszeichnungen.
- Strukturierte Daten `InsuranceAgency` hier **zu Recht** (dies IST die
  Standortseite) plus `BreadcrumbList`. Auf allen uebrigen Unterseiten
  bleibt es bei `Organization` - sonst behauptete jede Seite eine eigene
  Filiale.
- In `sitemap.xml` DE und AR, `hreflang` wechselseitig.

**Wirksam wird sie erst mit dem Google-Unternehmensprofil.** Eine
Ortsseite ohne vollstaendiges Profil (Name, Anschrift, Telefon,
Oeffnungszeiten, Kategorie, Fotos, Bestaetigung per Post) ist eine Seite
ohne Signal dahinter. Die Reihenfolge ist: Profil vervollstaendigen,
dann Profil und Seite wechselseitig verlinken.

---

## 9. Rechtliche Pruefung erforderlich - hier entscheide ich nichts

Technisch ist alles ausgefuehrt. Diese fuenf Punkte sind
Rechtsentscheidungen und gehoeren zu Rechtsanwalt bzw.
Datenschutzbeauftragtem:

1. **Braucht cookieloses Matomo ueberhaupt eine Einwilligung?** Dazu gibt
   es in Deutschland zwei vertretbare Auffassungen. Der Code nimmt die
   **strengere** an (Einwilligung noetig). Faellt die Pruefung anders
   aus, genuegt `ANALYTICS_REQUIRES_CONSENT=false` in der Server-`.env`;
   die Messung laeuft dann ohne Abfrage. **Diese Zeile bitte nur mit
   schriftlicher Einschaetzung aendern.**
2. **Gestaltung der Knoepfe.** "Alle akzeptieren" ist hervorgehoben,
   "Ablehnen" steht gleich gross und gleich erreichbar daneben, beide auf
   der ersten Ebene, ein Klick. Das entspricht der gaengigen
   Aufsichtspraxis - beurteilen muss es Ihr Berater.
3. **Speicherdauer** der Rohdaten (Voreinstellung 180 Tage): muss in der
   `.env` **und** in Matomo selbst denselben Wert haben, sonst
   beschreibt die Datenschutzerklaerung etwas anderes als die Technik.
4. **AVV mit dem Hoster.** Fuer Matomo braucht es keinen
   Auftragsverarbeitungsvertrag mit einem Dritten - es laeuft auf dem
   eigenen Server. Fuer Server und Datenbank ist **Hostinger** jedoch
   Auftragsverarbeiter nach Art. 28 DSGVO; liegt dafuer noch kein
   Vertrag vor, ist er nachzuholen (ueblicherweise im hPanel unter
   Legal / Data Processing Agreement abrufbar). **Ich schliesse und
   akzeptiere keinen Vertrag im Namen von Dienstly24.**
5. **Aufnahme in Datenschutzerklaerung und Verzeichnis**: die Texte sind
   geschrieben und veroeffentlicht (sie richten sich automatisch danach,
   ob Matomo eingerichtet ist), die Verzeichnis-Angaben stehen in
   Abschnitt 7 - die juristische Abnahme bleibt beim Betreiber.

---

## 10. Nicht geprueft (Netzzugang fehlt)

- Matomo-Installation, Subdomain, TLS-Zertifikat, Cron-Archivierung
- Verhalten des Einwilligungs-Cookies ueber die **echten** Hosts hinweg
  (`www.dienstly24.de` -> `portal.dienstly24.de`). Lokal ist die
  gemeinsame Domain nicht nachstellbar; die Logik ist getestet, die
  Uebertragung ueber die Domaingrenze ist nach dem Livegang **einmal**
  nachzusehen: Banner auf der Website beantworten, dann das Portal
  aufrufen - es darf nicht erneut fragen.
- Ladezeiten unter echten Bedingungen (Core Web Vitals als Feldwerte)
- Bestehende Cookies auf dem Produktionsstand, die nicht aus diesem
  Code stammen (z.B. vom Hoster gesetzte)

---

## 11. Nach dem Livegang: kurze Pruefliste

1. Matomo installieren (`docs/ANLEITUNG_MATOMO_AR.md`, Abschnitte 2-4).
2. `MATOMO_URL` und `MATOMO_SITE_ID` in die Server-`.env`, dann
   `php artisan config:cache`. **Kein Passwort und kein API-Token
   gehoert in das Repository, in den Browser oder in eine Nachricht.**
3. Website in einem privaten Fenster oeffnen: Banner erscheint,
   Netzwerk-Reiter zeigt **keine** Anfrage an den Matomo-Host.
4. "Ablehnen": weiterhin keine Anfrage, Seite voll funktionsfaehig.
5. Neu laden, "Alle akzeptieren": `matomo.js` wird geladen, der Besuch
   steht in Matomo unter Visitors -> Visits Log.
6. Telefon- und WhatsApp-Knopf druecken: Behaviour -> Events zeigt
   `Kontakt / telefon / <seite>`.
7. Portal aufrufen: Banner darf **nicht** erneut fragen, und im Portal
   darf **nichts** gemessen werden (kein `_paq` im Quelltext).
8. Fusszeile -> "Cookie-Einstellungen": Banner oeffnet sich erneut.
