# Markenlogo statt "D" in Gmail: Bestandsaufnahme und Weg zu BIMI

**Auftrag (22.09.2026):** In Gmail soll neben dem Absendernamen das
Firmenlogo stehen statt des generierten Buchstabens "D". Der offizielle Weg
dafuer heisst **BIMI** (Brand Indicators for Message Identification).

**Bedingung des Betreibers, und die wichtigere Haelfte des Auftrags:** kein
Schritt darf die laufende Zustellung gefaehrden. Eine falsche Aenderung an
SPF, DKIM oder DMARC kostet Posteingang - ein Logo ist das nicht wert.
Deshalb wurde in diesem Durchgang **kein einziger DNS-Eintrag und keine
Mail-Einstellung geaendert**.

**Das Ergebnis vorweg:** die Kette ist weiter fortgeschritten als
angenommen. DMARC steht bereits auf Durchsetzung, ein BIMI-Eintrag ist
sogar schon gesetzt. Was fehlt, sind genau zwei Dinge - ein **Zertifikat**
und eine **gueltige Logodatei am richtigen Ort**. Und beim Logo lag der
Fehler, der alles Uebrige wirkungslos gemacht haette.

---

## 1. Gemessen am 22.09.2026 - und was NICHT gemessen werden konnte

Die DNS-Eintraege liessen sich direkt abfragen. Gemessen wurde:

```
TXT dienstly24.de                = v=spf1 include:_spf.mail.hostinger.com ~all
TXT _dmarc.dienstly24.de         = v=DMARC1; p=quarantine; rua=mailto:kv@dienstly24.de
TXT hostingermail1._domainkey    = Schluessel vorhanden (410 Zeichen)
TXT default._bimi.dienstly24.de  = v=BIMI1; l=https://dienstly24.de/dienstly-bimi-logo.svg;
MX  dienstly24.de                = 5 mx1.hostinger.com / 10 mx2.hostinger.com
A   dienstly24.de                = 88.223.87.161 / 147.79.79.109
A   www.dienstly24.de            = 145.223.124.255 / 147.79.72.233
A   portal.dienstly24.de         = 187.127.70.161
```

Andere DKIM-Selectoren (`default`, `google`, `selector1`, weitere
Hostinger-Selectoren) sind **nicht** gesetzt - es signiert genau ein
Versandweg, und das passt zum Befund aus dem Code (Abschnitt 2).

**Nicht gemessen werden konnte:** was unter
`https://dienstly24.de/dienstly-bimi-logo.svg` tatsaechlich ausgeliefert
wird. Die Arbeitsumgebung hat keinen HTTPS-Zugang zu der Domain (dieselbe
Einschraenkung wie bei der SEO-Bestandsaufnahme). Das ist kein Nebenpunkt,
sondern der offene Kern von Abschnitt 4 - und auf dem Server mit einem
Befehl beantwortet:

```
cd /var/www/dienstly24/portal && php artisan bimi:pruefen --domain=dienstly24.de
```

Er geht die Kette in Betriebsreihenfolge durch (Absender -> SPF -> DKIM ->
DMARC -> BIMI-Eintrag -> Logo-Datei -> Logo ueber HTTPS -> Zertifikat),
nennt zu jedem Fund den naechsten Schritt und liefert Exitcode 1, solange
etwas handlungsbeduerftig ist. **Er aendert nichts.**

---

## 2. Versandwege: wer verschickt Mail unter dieser Domain?

Das liess sich vollstaendig am Code pruefen, und das Ergebnis ist die beste
Nachricht dieses Berichts:

- **Die Anwendung hat GENAU EINEN Versandweg.** `config/mail.php` kennt nur
  den Standard-Mailer; jede der 21 Mailables geht darueber. Der einzige
  Mailable, der die Absenderadresse ueberhaupt anfasst
  (`SupportInquiryMail`), setzt sie ausdruecklich auf
  `config('mail.from.address')` und legt den Fragesteller nur als
  `replyTo` dazu - genau die Bauweise, die DMARC verlangt, und sie war
  schon vorher richtig.
- **Kein zweites System versendet unter der Domain**, soweit der Code
  reicht: Newsletter/Kampagnen (`SendCampaignJob`), Wiedervorlagen,
  Signaturen, Rechnungen (`LexofficeService`) und Ticket-Antworten laufen
  alle ueber denselben Mailer und dieselbe Absenderadresse.
- **Die Postfach-Anbindungen (Gmail-OAuth, Microsoft Graph, IMAP) LESEN
  nur.** Sie holen den Eingang ab und verschicken nichts - fuer
  SPF/DKIM/DMARC ohne Belang.
- Versandweg laut `docs/EMAIL_ZUSTELLBARKEIT_SPF_DKIM_DMARC.md` (auf dem
  Produktivserver geprueft): Hostinger-SMTP, `noreply@dienstly24.de`. Die
  MX-Eintraege (mx1/mx2.hostinger.com) passen dazu, und dass genau EIN
  DKIM-Selector existiert, ebenfalls.

**Was das fuer BIMI heisst:** die Ausrichtung (Alignment) zwischen Von-Feld
und SPF/DKIM ist die haeufigste Huerde bei BIMI, und hier ist sie
strukturell kein Problem - es gibt nur einen Absender. Der eine Punkt, der
sich nicht im Repository beantworten laesst: versendet ausserhalb der
Anwendung noch jemand unter `@dienstly24.de` (ein Mitarbeiter-Postfach ueber
ein anderes Programm, ein Buchhaltungs- oder Newsletter-Dienst)? Das sagen
die DMARC-Berichte, die bereits an `kv@dienstly24.de` gehen.

---

## 3. Die Kette und ihr Stand

| # | Glied | Stand am 22.09.2026 |
|---|-------|---------------------|
| 1 | SPF, genau ein Eintrag | **in Ordnung** |
| 2 | DKIM signiert | **in Ordnung** (Selector `hostingermail1`) |
| 3 | DMARC auf DURCHSETZUNG (`p=quarantine`/`reject`, `pct=100`) | **in Ordnung** - `p=quarantine`, ohne `pct` gilt 100 |
| 4 | Logo als SVG Tiny PS, oeffentlich ueber HTTPS | **war fehlerhaft, Datei jetzt behoben; der AUSLIEFERUNGSORT ist noch zu klaeren** |
| 5 | Zertifikat VMC oder CMC | **fehlt** |
| 6 | DNS-Eintrag `default._bimi` | vorhanden, aber **ohne `a=`** und damit fuer Gmail wirkungslos |

Der Befund aus Zeile 3 korrigiert
`docs/EMAIL_ZUSTELLBARKEIT_SPF_DKIM_DMARC.md`: dort steht noch der Stand
vom 14.07.2026 (`p=none`). Inzwischen steht die Richtlinie auf
`p=quarantine` - **die Voraussetzung fuer BIMI ist damit erfuellt, und der
gefaehrlichste Schritt des ganzen Vorhabens ist bereits getan.**

---

## 4. Das Logo: was da lag, was jetzt da liegt, und wo es hingehoert

Im `public`-Ordner lag bereits `dienstly-bimi-logo.svg` - genau die Datei,
auf die der bestehende BIMI-Eintrag zeigt (ein Test sichert sie seit dem
Website-Merge ab). **Sie haette BIMI nie bestanden.** Sie war ein
gewoehnlicher Nachzeichnungs-Export der Wortmarke:

- 42 KB - erlaubt sind **32 KB**;
- kein `baseProfile="tiny-ps"` - allein das fuehrt zur Ablehnung;
- eine `DOCTYPE`-Zeile, also ein Verweis auf eine externe DTD - genau das,
  was "Portable/**Secure**" ausschliesst;
- kein `<title>` mit dem Firmennamen;
- Masse in `pt` statt in absoluten Pixeln;
- rein schwarz, obwohl die Marke gruen ist.

Das ist die uebliche Falle: die Datei sieht im Browser richtig aus. Kein
Fehler, keine Meldung - nur ein Logo, das nie erscheint.

**Neu erzeugt** (aus `public/images/logo-icon.png`, dem D-Symbol der Marke):
quadratisch, `baseProfile="tiny-ps"`, `<title>Dienstly24</title>`, deckender
Hintergrund, zwei Flaechenfarben aus den Marken-Tokens (Graphit `#131A17`,
Smaragd `#17A65B`), **3,9 KB**. Gerendert und bei 512, 96 und 32 Pixeln
angesehen - bei der Groesse, in der Gmail es zeigt, bleibt die Form lesbar.

**EHRLICH ZUR GRENZE DES FORMATS:** SVG Tiny PS kennt **keine Verlaeufe und
keine eingebetteten Rasterbilder**. Die Metall- und Edelstein-Textur des
Originallogos laesst sich darin nicht abbilden - sie ist keine Form, sie ist
ein Bild. Was hier entstanden ist, ist eine FLAECHIGE Fassung derselben
Marke. Das ist bei BIMI der Normalfall, aber es ist eine Aenderung am
Erscheinungsbild und gehoert dem Betreiber vorgelegt, bevor ein Zertifikat
darauf ausgestellt wird (Abschnitt 5, letzter Absatz).

Geprueft wird die Datei ab jetzt automatisch: `App\Support\BimiLogo` ist die
eine Quelle der Regeln, `BimiLogoTest` faellt um, sobald jemand sie durch
einen beliebigen Grafikprogramm-Export ersetzt.

### Der offene Punkt: wer liefert die Datei aus?

Der Eintrag zeigt auf `https://dienstly24.de/...` - also auf die Domain
**ohne www**. Die gemessenen A-Eintraege zeigen: `dienstly24.de`
(88.223.87.161 / 147.79.79.109), `www.dienstly24.de` (145.223.124.255 /
147.79.72.233) und `portal.dienstly24.de` (187.127.70.161) liegen auf
**verschiedenen** Adressen. Vor dem abgeschlossenen Website-Umzug
(`docs/WEBSITE_MERGE_UMSETZUNG.md`) bedient also sehr wahrscheinlich nicht
diese Anwendung die Adresse `dienstly24.de`.

Daraus folgen zwei Dinge, die auf dem Server zu pruefen sind, bevor ein
Zertifikat beantragt wird:

1. **Was liefert `https://dienstly24.de/dienstly-bimi-logo.svg` heute
   wirklich aus?** Die neue Datei aus diesem Zweig landet dort NICHT von
   selbst, wenn die Adresse von einem anderen Ort bedient wird. Dann muss
   sie dorthin - oder `l=` muss auf den Host zeigen, der die Anwendung
   ausliefert.
2. **Antwortet die Adresse mit 200 oder mit einer Weiterleitung?** Sobald
   die Anwendung den Host bedient, leitet `RedirectWebsiteHost` von
   `dienstly24.de` per 301 auf `www.dienstly24.de` um. **Eine
   Weiterleitung ist fuer BIMI riskant** - die Pruefer holen die Datei
   nicht wie ein Browser, und mehrere folgen keiner Umleitung. Nach dem
   Umzug gehoert in `l=` deshalb die Adresse, die **direkt** mit 200
   antwortet, also `https://www.dienstly24.de/dienstly-bimi-logo.svg`.

`bimi:pruefen` beantwortet beides: er holt die Adresse aus dem Eintrag,
folgt bewusst keiner Weiterleitung und vergleicht das Ausgelieferte mit der
Datei auf dem Server.

---

## 5. Zertifikat: das ist der eigentliche Blocker

Die Recherche zum aktuellen Stand (09/2026) ist eindeutig: **Gmail zeigt das
BIMI-Logo nur mit einem Zertifikat.** Genau das fehlt im bestehenden
Eintrag - er hat kein `a=`. Bei anderen Anbietern (u. a. Yahoo, Fastmail)
wirkt ein Eintrag ohne Zertifikat; bei Gmail nicht - und Gmail ist der
Anlass des Auftrags. Das erklaert den gemeldeten Zustand vollstaendig:
Eintrag da, Logo trotzdem nicht sichtbar.

Zwei Arten:

| | **VMC** (Verified Mark Certificate) | **CMC** (Common Mark Certificate) |
|---|---|---|
| Voraussetzung | **eingetragene Marke** beim Marken- und Patentamt | Logo seit **mindestens 12 Monaten** nachweislich in Benutzung |
| Ergebnis in Gmail | Logo **und** blaues Haken-Zeichen | Logo, **kein** Haken |
| Preis je Jahr | ca. 1.000-1.500 EUR (Listenpreise; Wiederverkaeufer guenstiger) | ca. 600-1.400 EUR |
| Ausstellende Stellen | DigiCert, GlobalSign, Sectigo (Entrust-Geschaeft seit 2025) | dieselben |

**Fuer Dienstly24 ist der CMC der realistische Weg**, sofern keine
eingetragene Wort-/Bildmarke besteht. Ob eine besteht, weiss nur der
Betreiber - im Repository steht nichts dazu, und eine Markenanmeldung
dauert (Recherche: sechs bis zwoelf Monate) und kostet zusaetzlich. Der
Haken ist schoen, aber er ist nicht der Auftrag: der Auftrag ist das Logo.

**Was die Stelle verlangt** (typisch, Details je Anbieter):

- die fertige SVG-Tiny-PS-Datei - **genau die, die dann dauerhaft liegen
  bleibt**;
- Nachweis der Organisation (Handelsregister-/Gewerbeauszug, Anschrift,
  Telefonnummer, die die Stelle zurueckruft);
- beim CMC: Nachweis, dass genau dieses Logo seit 12 Monaten oeffentlich
  benutzt wird (Website, Briefbogen, Archiv-Aufnahmen);
- beim VMC: Markenurkunde und Nachweis der Uebereinstimmung;
- Kontrolle ueber die Domain.

**Hier wird nichts gekauft.** Das ist eine Ausgabe mit Jahresbindung und
gehoert dem Betreiber.

**Die Rueckfrage VOR dem Kauf:** das Zertifikat wird auf die konkrete
Logodatei ausgestellt. Weil die flaechige Fassung aus Abschnitt 4 nicht
Zeichen fuer Zeichen dem texturierten Original entspricht, sollte sie der
ausstellenden Stelle **vorher** vorgelegt werden ("entspricht diese
vereinfachte Fassung unserem seit Jahren benutzten Logo?"). Beim CMC ist
genau diese Uebereinstimmung der Pruefgegenstand. Eine Ablehnung nach der
Zahlung waere teuer, eine Frage vorher kostet nichts.

---

## 6. Der DNS-Eintrag: eine Ergaenzung, kein Neuanfang

Der bestehende Eintrag bleibt, er bekommt das Zertifikat dazu. Fertig
formuliert, mit der echten Domain:

```
Typ:  TXT
Name: default._bimi            (ergibt default._bimi.dienstly24.de)
Wert: v=BIMI1; l=https://www.dienstly24.de/dienstly-bimi-logo.svg; a=https://www.dienstly24.de/dienstly24-bimi.pem
TTL:  3600
```

Was die Teile bedeuten:

- `v=BIMI1` - Version, muss zuerst stehen.
- `l=` - die **Logo-Adresse**. Zwingend `https`, oeffentlich, ohne
  Anmeldung, **ohne Weiterleitung**, und der Server muss den Content-Type
  `image/svg+xml` liefern. Der Host in dieser Zeile ist erst nach dem
  Website-Umzug richtig - solange `dienstly24.de` von woanders bedient
  wird, muss hier der Ort stehen, der die Datei wirklich ausliefert
  (Abschnitt 4).
- `a=` - die **Zertifikatsdatei** (PEM, vom Aussteller). **Ohne sie zeigt
  Gmail nichts.** Der Dateiname oben ist ein Vorschlag; die Datei kommt vom
  Aussteller und wird neben das Logo gelegt.

**Es darf nur EIN BIMI-Eintrag existieren** - der vorhandene wird ersetzt,
nicht ergaenzt. Zwei Eintraege heben sich gegenseitig auf; `bimi:pruefen`
meldet das als Blocker.

---

## 7. Pruefen nach dem Setzen

1. `php artisan bimi:pruefen --domain=dienstly24.de` auf dem Server - jede
   Zeile ohne `[!]`.
2. Testmail an ein **echtes** Gmail-Konto, dort "Original anzeigen":
   `SPF: PASS`, `DKIM: PASS`, `DMARC: PASS`.
3. Wenn mehrere Absenderadressen benutzt werden, aus jeder eine Mail.
4. **Bis zu 48 Stunden warten.** Gmail zieht zusaetzlich den Ruf des
   Absenders heran - ein technisch gueltiger Eintrag ist die Voraussetzung,
   keine Zusage. Bleibt das Logo danach aus, ist es nicht der DNS-Eintrag,
   sondern die Reputation (dazu
   `docs/EMAIL_ZUSTELLBARKEIT_SPF_DKIM_DMARC.md`).

---

## 8. Risiko - kurz und ehrlich

- Was in diesem Durchgang geaendert wurde (Logodatei, ein lesender Befehl,
  Tests, Doku), **beruehrt den Mailversand an keiner Stelle**. Die Datei ist
  von keinem Programmteil und keiner Mail-Vorlage verlinkt.
- **An SPF, DKIM und DMARC ist nichts zu tun** - sie stehen richtig, und
  DMARC steht bereits auf Durchsetzung. Damit entfaellt der einzige Schritt
  dieses Vorhabens, der Zustellbarkeit haette kosten koennen. Wer trotzdem
  etwas verbessern will: `sp=quarantine` und `pct=100` ausdruecklich
  hinschreiben (beides gilt heute schon als Standardwert, es waere
  Klarstellung, keine Aenderung).
- Der BIMI-Eintrag selbst ist ungefaehrlich: kein Mailserver trifft danach
  eine Zustellentscheidung. Faellt er falsch aus, erscheint kein Logo -
  mehr passiert nicht.
- Das einzige verbleibende Risiko ist **Geld**: eine Zertifikatsgebuehr mit
  Jahresbindung fuer eine Anzeige, die Gmail zusaetzlich von der Reputation
  abhaengig macht.
