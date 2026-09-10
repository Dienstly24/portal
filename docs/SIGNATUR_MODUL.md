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

## 10. Tests

- `tests/Feature/SignatureModuleTest.php` — beide Szenarien der Spezifikation
  (mit und ohne Kundenakte), mehrere Unterzeichner nacheinander und
  gleichzeitig, ungültige/abgelaufene/widerrufene Zugänge, E-Mail-Bestätigung,
  fremde Felder, leere Unterschrift, Ablehnung, Fristlauf, Zuordnung,
  Sichtbarkeit.
- `tests/Unit/PdfStamperTest.php` — Seitenbaum (auch aus Objekt-Strömen),
  Byte-Gleichheit des Originals, Drehung, Protokollseite, Umlaute.

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
