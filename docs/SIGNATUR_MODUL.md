# Natives E-Signatur-Modul

Betreiber-Auftrag 09.09.2026. Dokumente zur Unterschrift versenden, ohne
DocuSign, Adobe Sign oder einen anderen fremden Dienst - und ohne dass ein
Kundenvertrag das Haus verlässt.

Dieses Dokument beschreibt die Entscheidungen, die man dem Code nicht ansieht.
Was wo steht, sagt der Code selbst.

---

## 1. Der Kern: Signatur ist ein eigenständiges Geschäftsobjekt

`signature_requests.customer_id` und `.contract_id` sind **nullbar**. Das ist
keine Bequemlichkeit, sondern die eigentliche Anforderung.

Der Betrieb schickt regelmäßig etwas zur Unterschrift, **bevor** es einen
Kunden gibt: einem Interessenten, einem Vermittler, einem Zeugen, einem
Arbeitgeber. Wer die Zuordnung zur Bedingung des Versands macht, zwingt den
Mitarbeiter, vorher eine Kundenakte anzulegen — und erzeugt damit genau die
Karteileichen, die das Portal an anderer Stelle mühsam wieder zusammenführt
(`CustomerMergeService`, Lehre 06.08.2026).

Deshalb: **zugeordnet wird NACH dem Unterschreiben, und immer von einem
Menschen.** Vier Wege, alle auf der Detailseite:

| Weg | Was passiert |
|---|---|
| Bestehendem Kunden zuordnen | Dokument wandert in die Kundenakte |
| Neuen Kunden erstellen | Kundenakte aus den Angaben des Unterzeichners, Duplikatsschutz gilt |
| Vertrag zuordnen | zusätzlich an den Vertrag gebunden |
| Nicht zuordnen | bleibt unter Signaturen |

**Eine übereinstimmende E-Mail-Adresse ordnet NIE von selbst zu.** Eine Adresse
ist kein Identitätsnachweis (Familienpostfach, Firmenpostfach, Namensvetter),
und ein unterschriebener Vertrag in der falschen Akte ist ein
Datenschutzvorfall, kein Schönheitsfehler. Das System schlägt vor **und nennt
den Grund**; entschieden wird per Klick. Dieselbe Haltung wie im
Dokumenten-Eingang und beim Provisions-Abgleich.

---

## 2. Warum das unterschriebene PDF eine *Fortschreibung* ist

`PdfStamper` schreibt die Unterschriften als **incremental update** an die
Originaldatei an. Das Ergebnis ist wörtlich „Original + Anhang": die ersten N
Bytes der unterschriebenen Datei sind byteweise das Original.

Das ist der Kern der Beweiskraft. Wer beide Dateien nebeneinanderlegt, kann
**belegen**, dass am Vertragstext nichts geändert wurde — statt es zu
behaupten. Ein Test hält das fest
(`PdfStamperTest::test_das_original_bleibt_byte_fuer_byte_erhalten`).

Der einfachere Weg wäre gewesen, die Seiten zu Bildern zu rendern, zu
bestempeln und ein neues PDF zu bauen. Er hätte Textebene, Struktur und genau
diesen Nachweis gekostet.

**Kein Fremdpaket.** Gebraucht wird ein schmaler Ausschnitt (Seitenbaum,
MediaBox, Objekt-Rohtext); die Alternative wäre ein großes PDF-Framework im
Sicherheitsupdate-Pfad einer Anwendung mit Kundendaten. Dieselbe Abwägung wie
beim XLSX-Leser des Provisions-Imports. `smalot/pdfparser` liegt zwar vor,
liest aber Text — es kennt keine Objektnummern, keine Seitengeometrie, und
schreiben kann es gar nicht.

Drei Dinge, die dabei leicht zu übersehen sind und deshalb je einen Test haben:

1. **Komprimierte Objekt-Ströme** (`/Type /ObjStm`). Seit PDF 1.5 liegt der
   Seitenbaum regelmäßig dort. Wer nur den Klartext liest, findet bei genau
   diesen Dateien — also den meisten modernen — keine einzige Seite.
2. **Seitendrehung** (`/Rotate`). Ohne Transformationsmatrix steht die
   Unterschrift auf jedem quer eingescannten Vertrag um 90 Grad verdreht am
   Rand — und zwar erst im fertigen Dokument, nie im Editor.
3. **Umlaute.** Helvetica/WinAnsi kennt kein UTF-8. Ohne Umsetzung steht
   „Müller" als Kauderwelsch im Dokument.

**Die Unterschrift wird als 1×1-Pixel-Farbfläche mit dem Alphakanal der
Handschrift als `/SMask` eingebettet.** Der Alphakanal trägt die volle
Auflösung, die Farbe braucht drei Bytes — und die Schrift bleibt
durchscheinend. Ein weiß hinterlegtes JPEG wäre ein weißer Kasten über dem
Vertragstext.

**Geprüft wird beim Hochladen, nicht beim Erzeugen.** Ein PDF, das sich nicht
sicher bestempeln lässt (verschlüsselt, defekt), wird sofort abgelehnt. Ein
Fehlschlag *nach* dem Unterschreiben wäre dem Unterzeichner nicht zu erklären
und der Vorgang nicht zu retten.

---

## 3. Warum die Anzeige Bilder sind und kein PDF-Betrachter

Die Inhaltsrichtlinie erlaubt seit SEC-4 nur Skripte von der eigenen Domain
(`script-src 'self'` + Nonce). Eine PDF-Bibliothek aus einem fremden CDN ist
damit ausgeschlossen, und rund 1,5 MB mitgeliefertes JavaScript wären
ausgerechnet auf dem Telefon des Unterzeichners der schlechteste Ort dafür.

Gerendert wird serverseitig mit `pdftoppm`. poppler-utils läuft für die OCR
ohnehin auf dem Server (seit 18.07.2026) — es kommt **keine neue Abhängigkeit**
dazu.

**Ehrlich bleiben:** der Unterzeichner sieht ein *Bild* des Dokuments. Deshalb
steht auf der Unterschriftsseite immer auch der Weg zum echten PDF, und das
Protokoll hält den SHA-256 der Datei fest, die gerendert wurde. „Was du gesehen
hast" ist damit belegbar dieselbe Datei.

Fehlt poppler, bleibt die Seite **leer statt kaputt**: der Editor zeigt eine
maßhaltige leere Fläche (die Größe kennt der PDF-Leser auch ohne poppler), die
Unterschriftsseite den Hinweis samt PDF-Link.

---

## 4. Zugang ohne Konto — was ihn trägt

Der Unterzeichner ist regelmäßig jemand, der **kein** Portal-Konto hat und
keines bekommen soll. Der Schutz ist deshalb das Token selbst:

- 40 Zeichen aus dem kryptografischen Generator. In der Datenbank steht
  **nur der SHA-256**. Ein Datenbankleck gibt keinen einzigen Vertrag preis.
- Bewusst SHA-256 und nicht bcrypt: der Wert muss *nachschlagbar* sein (Index
  auf `token_hash`). Das ist unbedenklich, weil ein Token nicht geraten werden
  kann — anders als ein Passwort hat es keine geringe Entropie.
- **Befristet** (Ablaufdatum der Anfrage) und **widerrufbar**
  (`token_revoked_at`). Ein Abbruch macht den Link sofort tot; ein Zustand
  allein an der Anfrage reichte nicht, sobald irgendwann ein zweiter Lesepfad
  entsteht.
- In der URL steht **nie** eine Datenbank-ID und nie eine Angabe zum Dokument.
- Ein unbekanntes Token sieht aus wie ein abgelaufenes. Aus der Fehlermeldung
  darf nicht hervorgehen, ob es diesen Vorgang gibt.

**Zweiter Schritt: Einmalcode** an dieselbe Adresse, an die eingeladen wurde
(sechsstellig, 30 Minuten, bcrypt, Versuchszähler). Er belegt, dass die Person
Zugriff auf *das Postfach* hat — ein weitergeleiteter Link allein belegt das
nicht. Abschaltbar je Anfrage.

**Dem Browser wird nichts geglaubt.** Welche Felder jemand ausfüllen darf,
entscheidet der Server aus der Zugehörigkeit (Feld gehört zu *diesem*
Unterzeichner in *dieser* Anfrage); ob überhaupt unterschrieben werden darf,
aus Zustand, Frist und Reihenfolge. Ein manipuliertes Formular erreicht nichts,
was der Vorgang nicht ohnehin erlaubt — ein Test schickt genau das durch.

**Eine leere Zeichenfläche zählt nie als Unterschrift.** Ohne diese Prüfung
genügte ein Klick auf „Bestätigen".

---

## 5. Reihenfolge

`sequential` (Vorgabe): nur der Erste bekommt die Einladung; der Nächste erst
nach der Unterschrift des Vorgängers. Ein Zweiter, der seinen Link schon
kennt, kommt bis dahin nicht durch und sieht warum.

`parallel`: alle sofort.

---

## 6. Das Protokoll

`signature_events` ist **append-only**: kein `updated_at`, kein Bearbeiten-Weg,
kein Löschen-Knopf. Eine Spalte, die es nicht gibt, kann auch nicht still
gepflegt werden. Ein Protokoll, das der Protokollierte ändern kann, belegt
nichts.

Festgehalten werden Zeitpunkt, Ereignis, Handelnder, IP-Adresse und
Geräte-Kennstring — vom Anlegen über jede Einladung, Öffnung und Bestätigung
bis zum erzeugten PDF und jedem Download.

**Das Protokollieren darf den Vorgang nie scheitern lassen.** Fällt das
Schreiben aus, wird es geloggt und der Unterzeichner unterschreibt trotzdem
(dieselbe Regel wie beim `ErrorRecorder` und beim Provisions-Protokoll).

Die letzte Seite des unterschriebenen PDF trägt zusätzlich ein
**Signaturprotokoll**: Dokument, Prüfsumme, je Unterzeichner Zeitpunkt, IP und
Gerät, dazu der rechtliche Hinweis. Damit ist die Datei für sich allein
aussagekräftig — wer sie weitergibt, gibt den Nachweis mit.

---

## 7. Rechtliche Einordnung — was das Modul NICHT behauptet

Das Modul erzeugt eine **einfache elektronische Signatur** (eIDAS Art. 3
Nr. 10) mit technischem Nachweis. Es ist **keine** qualifizierte elektronische
Signatur, und es findet keine Prüfung durch einen Vertrauensdienst statt. Genau
so steht es auf der Protokollseite.

Der Zustimmungstext über dem Bestätigen-Knopf ist **je Anfrage änderbar** und
behauptet in der Voreinstellung nichts über die Gleichstellung mit einer
handschriftlichen Unterschrift. Welche Signaturstufe ein Geschäftsfall braucht
(§ 126a BGB, Schriftformerfordernisse, Vollmachten), ist eine Rechtsfrage und
gehört in die Rechtsprüfung — nicht in eine Software-Voreinstellung.

---

## 8. Berechtigungen

`SignatureRequestPolicy`, zwei Achsen, beide nötig:

- **Portfolio.** Sobald eine Anfrage einem Kunden gehört, gilt dieselbe
  Sichtbarkeit wie überall sonst.
- **Urheberschaft.** Eine Anfrage *ohne* Kunden hat kein Portfolio, an dem sie
  hängen könnte — sie gehört ihrem Ersteller und der Leitung. Ohne diesen Teil
  wäre die Nullbarkeit von `customer_id` ein Loch in der Zugriffskontrolle
  statt einer Erleichterung.

Abgeschlossene Vorgänge lassen sich nicht löschen: an ihnen hängt der Nachweis
für eine Unterschrift, die jemand geleistet hat.

Alle Dateien liegen auf der **privaten** Platte (`storage/app/private/
signaturen/…`). Jeder Zugriff läuft durch einen Controller, der vorher prüft,
wer fragt — beim Mitarbeiter die Rolle, beim Unterzeichner sein Token. Kein
Pfad im öffentlichen Verzeichnis.

---

## 9. Betrieb

- `signaturen:ablaufen` — täglich 04:05. Zieht den gespeicherten Zustand
  abgelaufener Anfragen nach, widerruft die Zugänge und meldet dem Ersteller,
  dass sein Dokument nicht unterschrieben wurde.
  **Die Anzeige wartet nicht darauf:** die Frist wird bei jedem Aufruf
  gerechnet, und nach Fristablauf kommt auch ohne Cron niemand mehr durch
  (dieselbe Lehre wie beim Vertragsstatus, 17.08.2026).
- Der Nav-Zähler bei „Signaturen" zählt **nur abgeschlossene Vorgänge ohne
  Zuordnung** — dort wartet Arbeit. Laufende Anfragen warten auf den *Kunden*;
  sie mitzuzählen machte aus dem Abzeichen eine Zahl, die man nicht kleiner
  bekommt, und damit eine, die man ignoriert.
- Einladung, Erinnerung, Code und Abschluss-Mail sind **bewusst nicht queued**.
  Sie sind der einzige Weg zum Dokument; bleibt eine davon mangels Worker
  liegen, sieht der Mitarbeiter „gesendet" und der Kunde bekommt nichts.
- Die Abschluss-Mail trägt das PDF **im Anhang**, nicht als Link: nach dem
  Abschluss wird der Zugang widerrufen, und ein dauerhaft offener Link auf ein
  unterschriebenes Dokument ist genau das, was dieses Modul vermeiden soll.

---

## 9a. Der HTTP 500 beim Unterschreiben (10.09.2026)

Gemeldet: beim Unterschreiben gelegentlich HTTP 500, das Protokoll endete
bei „Unterschrift begonnen". Per Fehlereinspeisung nachgestellt
(`tests/Feature/FaultInjectionSignatureTest.php`).

**Die Ursache war nicht ein Fehler, sondern eine fehlende Schranke.** Der
Unterschreiben-Pfad lief von der Prüfung bis zur Glocke in einem Stück:
jede Störung dahinter — volle Platte, gestörte Glocke, PDF-Erzeugung —
schlug ungefiltert bis zum Unterzeichner durch. Und je nachdem, *wo* sie
auftrat, war die Unterschrift entweder verloren (vor dem Ereignis
`signed`, genau das Bild aus der Meldung) oder längst sicher gespeichert,
während der Unterzeichner eine Fehlerseite sah.

Behoben:

1. **Schleuse im Controller.** Kein technischer Fehler erreicht den
   Unterzeichner mehr; Ursache in Log *und* Protokoll (neues Ereignis
   `signing_failed` — der Gegenpol zu `signing_started`, der bisher
   fehlte), Glocke an den Ersteller, verständlicher Satz auf der Seite.
2. **Geprüfte Schreibvorgänge.** `put()` meldet einen Fehlschlag auch ohne
   Ausnahme mit `false`. Bilder werden vor der Transaktion geschrieben und
   einzeln geprüft — eine verwaiste Datei ist harmlos, eine Datenbankzeile
   ohne Datei wäre eine Unterschrift, die es nicht gibt.
3. **Nachlauf hinter der Schranke.** Protokoll, Glocke, Einladung des
   Nächsten und fertiges PDF können die gespeicherte Unterschrift nicht
   mehr in Frage stellen.
4. **Idempotenz.** Wer schon unterschrieben hat, landet auf der
   Abschluss-Seite statt auf einer 403-Seite.
5. **Große Bildschirme.** Eine Unterschrift wird *verkleinert* statt
   verworfen: ein 820-px-Feld auf einem Gerät mit Verhältnis 2 liefert
   1640 px und fiel bisher durch die Obergrenze — der Unterzeichner sah
   nur die Aufforderung, doch bitte zu unterschreiben.
6. **Die Zeichnung überlebt einen Fehler** (sessionStorage, nicht die
   Sitzung: als data:-URL wären es 40 kB und mehr pro Feld).
7. **Editor-Fehler.** Ein Feld, dessen Unterzeichner im selben
   Speichervorgang neu entstand, verlor seinen Besitzer — der Vorgang
   ließ sich danach nicht versenden. Die Behelfs-Kennung des Editors wird
   jetzt serverseitig auf die echte abgebildet.

---

## 9b. Sprache des Unterzeichners (de/ar/en)

`signature_signers.locale` — am **Unterzeichner**, nicht an der Anfrage:
ein Vorgang kann einen deutschen Kunden und einen arabisch sprechenden
Zeugen tragen. Sie ist **nicht** die Portal-Sprache des Kunden; die wird
vorgeschlagen und nie geändert.

`lang/{de,ar,en}/signing.php`, deckungsgleiche Schlüssel (Test). Seite,
Sperrseite, Abschluss, Bestätigung und alle drei E-Mails laufen darüber,
auch die Texte im JavaScript — sonst entstünde genau dort die
Mischsprache. `dir="rtl"` am `<html>`, lokal gehostete arabische Schrift;
Ziffernfolgen (Code, URL, SHA-256) tragen `dir="ltr"`.

**Das PDF wird nie gespiegelt.** Die Protokollseite bleibt deutsch und
LTR, unabhängig von der Sprache des Unterzeichners — ein gespiegelter
Vertrag wäre kein übersetzter, sondern ein unlesbarer.

Der **Zustimmungstext** ist der Sonderfall: die Voreinstellung gibt es
übersetzt, ein vom Mitarbeiter selbst geschriebener Text wird wörtlich
gezeigt. Einen eigenen rechtlichen Hinweis maschinell zu übersetzen hieße,
dem Unterzeichner eine Erklärung vorzulegen, die niemand geprüft hat.

Nebenbefund behoben: der Bestätigungscode stand im **Betreff** der E-Mail
— also in der Sperrbildschirm-Vorschau und in jedem Weiterleitungs-Kopf.

---

## 9c. Unternehmenssignatur, Firmenstempel, Firmenlogo

Einstellungen → Signaturen → Unternehmenssignaturen.

**Ein Firmenbild ist kein Unterzeichner.** Es hat keine E-Mail, kein
Zugangstoken, keine Zustimmung, keinen Zeitpunkt und keine IP; es ist eine
Grafik, die ein berechtigter Mitarbeiter aufbringt. Beides in
`signature_signers` zu zwingen hätte eine Grafik im Protokoll wie eine
abgegebene Willenserklärung aussehen lassen — genau diese Verwechslung ist
im Streitfall teuer. Deshalb eigene Tabelle
(`company_signature_assets`), eigene Feldart `firma`, eigener Abschnitt im
Protokoll mit „eingesetzt von <Mitarbeiter>" und dem ausdrücklichen Satz,
dass Firmenbilder keine Unterschrift einer Person sind.

Ein Firmenfeld wartet auf niemanden: es blockiert keinen Versand. Ohne
zugewiesenes Bild entsteht es gar nicht erst — es könnte niemand mehr
füllen.

Rechte: `firmensignatur-verwalten` (admin/manager) zum Anlegen,
`firmensignatur-benutzen` zum Setzen; geprüft an Route **und** Controller
**und** beim Speichern der Felder. Bilder werden neu gerendert statt
durchgereicht, der Alphakanal bleibt erhalten (ein weiß hinterlegter
Stempel wäre ein weißer Kasten über dem Vertragstext). Ein Bild, das in
einem fertigen Dokument steckt, wird **stillgelegt statt gelöscht** — ein
Beleg, dem nachträglich das Bild fehlt, ist kein Beleg.

---

## 9d. Zusätzliche Identitätsprüfung: keine / Geburtsdatum / E-Mail

Aus dem Ja-Nein-Schalter wird **ein** Wahlfeld
(`signature_requests.identity_check`). Zwei Spalten für dieselbe Frage
wären zwei Wahrheiten.

**Ehrlich bleiben, was das Geburtsdatum leistet:** es steht auf jedem
Ausweis und in jedem Versicherungsschein — es ist kein Geheimnis. Es hält
den zufälligen Empfänger eines weitergeleiteten Links auf, mehr nicht. Die
E-Mail-Bestätigung bleibt deshalb die Voreinstellung.

Regeln: der Wert kommt ausschließlich als POST-Feld (nie URL, Token,
E-Mail, Query-String); er steht nie im Protokoll (dort steht nur, *dass*
geprüft wurde); in der Spalte liegt er **verschlüsselt, nicht gehasht** —
ein Geburtsdatum hat rund 40.000 plausible Werte, ein Hash davon ist
offline in Sekunden geraten. Die Antwort ist grob („stimmt nicht"), der
Vergleich zeitkonstant, fünf Versuche und dann 30 Minuten Sperre je
Unterzeichner plus eigener Route-Throttle. Ohne hinterlegtes Datum wird
**nicht gefragt** — eine Frage, die niemand richtig beantworten kann, wäre
eine Sackgasse. **Kein SMS-Weg** (Betreiber-Vorgabe).

---

## 9e. Anlegen: bestehender Kunde oder externe Person

Zwei gleichberechtigte Wege im Formular. Der externe Weg steht bewusst
nicht im Kleingedruckten: wer ihn nicht findet, legt sich eine Kundenakte
auf Vorrat an — und genau die Karteileichen führt der
`CustomerMergeService` später mühsam zusammen.

Die Sofort-Suche (`admin.signatures.customer_search`, portfolio-gescoped,
max. 8) füllt Name, E-Mail, Sprache und Geburtsdatum des ersten
Unterzeichners vor — in die **Formularfelder**, sichtbar und änderbar. Der
Kunde wird dabei nie verändert. Interne `@dienstly24.internal`-Platzhalter
werden nie als Kontakt geliefert.

Ein eigener Endpunkt statt `admin.customers.search`: nur hier werden
Geburtsdatum und Portal-Sprache gebraucht, und dasselbe an alle
Kundensuchen zu hängen hieße, sie überall mitzuliefern (dieselbe Abwägung
wie bei `TaskController` und `ComposeEmailController`).

---

## 10. Tests

- `tests/Feature/SignatureModuleTest.php` — beide Szenarien der Spezifikation
  (mit und ohne Kundenakte), mehrere Unterzeichner nacheinander und
  gleichzeitig, ungültige/abgelaufene/widerrufene Zugänge, E-Mail-Bestätigung,
  fremde Felder, leere Unterschrift, Ablehnung, Fristlauf, Zuordnung,
  Sichtbarkeit.
- `tests/Unit/PdfStamperTest.php` — Seitenbaum (auch aus Objekt-Strömen),
  Byte-Gleichheit des Originals, Drehung, Protokollseite, Umlaute.
- `tests/Feature/FaultInjectionSignatureTest.php` — Fehlereinspeisung im
  Unterschreiben-Pfad: Platte, Glocke, PDF, doppeltes Absenden, zu große
  Unterschrift, Editor-Zuordnung.
- `tests/Feature/SignatureLocalizationTest.php` — de/ar/en, RTL,
  Schlüssel-Parität, „das PDF wird nicht gespiegelt".
- `tests/Feature/CompanySignatureAssetTest.php` — Firmenbilder, Rechte,
  Transparenz, Protokoll, Stilllegung statt Löschung.
- `tests/Feature/SignerIdentityTest.php` — Geburtsdatum, Sperre,
  Datensparsamkeit.
- `tests/Feature/SignatureCreateFlowTest.php` — beide Anlage-Wege,
  Portfolio-Scope der Sofort-Suche.

Zusätzlich real geprüft (Chromium, 09.09.2026): Feld-Editor mit
Seitenvorschau, Ziehen und Speichern; Unterschriftsseite auf einem
iPhone-Viewport mit gezeichneter Unterschrift; erzeugtes PDF mit beiden
Unterschriften an der gesetzten Stelle und unverändertem Original-Präfix.

---

## 11. Bewusst nicht gebaut

SMS-Bestätigung, Ausweis-/Video-Identifizierung, qualifizierte Signaturen,
Zahlungsabwicklung, Organisationsverwaltung, Vorlagenbibliothek,
Massenversand. Der Auftrag nennt sie ausdrücklich als Nicht-Ziele — und jedes
davon wäre eine eigene Fach- und Rechtsfrage.
