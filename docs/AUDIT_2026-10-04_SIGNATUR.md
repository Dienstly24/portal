# Audit E-Signatur, 04.10.2026: Qualitaetsgate, Testmatrix, Befunde

Betreiber-Auftrag 04.10.2026, Teil A: *"Keine Unterschrift darf je wieder
erfasst, aber unsichtbar sein, ohne dass wir es wissen."* Ausgangslage:
PR #371 (03.10.2026) hat die Ursachen KI-055/056/062 behoben und die acht
betroffenen abgeschlossenen Vorgaenge neu erzeugt (Server-Lauf 04.10.2026:
8 x `neu_erzeugt`, danach kein `U11` mehr).

Befunde stehen im Issue-Register als **KI-080 bis KI-091** (Nummernblock
fuer den Signatur-Strang, damit parallele Zweige nicht dieselbe Nummer
vergeben).

## 1. Was jetzt gilt (A1, A3, A5)

| Zeitpunkt | Pruefung | Bei Befund |
|---|---|---|
| Hochladen | `PdfEingangspruefung`: verschluesselt -> klare Meldung; `qpdf --check`; beschaedigt -> von qpdf neu geschrieben und erneut geprueft | Ablehnung mit Handlungsanweisung, oder reparierte Basis + hochgeladene Datei mit Hash aufbewahrt (`upload_original_*`, Ereignis `document_repaired`) |
| Firmenbild hochladen | sichtbarer Anteil nach dem Freistellen >= 0,5 % | Ablehnung "zu hell oder fast leer" |
| Versand | bereits gesetzte Felder (Unternehmenssignatur) im Speicher stempeln + Bildvergleich | Versand wird abgelehnt, Vermerk an der Anfrage |
| Abschluss | Selbsttest: Fortschreibung, Bild-Ressourcen, **jedes** ausgefuellte Feld sichtbar, keine neue poppler-Meldung, `qpdf --check` | Status "Fehler bei Fertigstellung", keine Abschluss-Mail, Glocke an Ersteller + Admins |
| Neu erzeugen | derselbe Selbsttest | nichts gespeichert |
| Jede Nacht 04:25 | `signaturen:qualitaet-pruefen` ueber alle nicht abgebrochenen/abgelehnten/abgelaufenen Vorgaenge (fertige am PDF, offene an der Vorschau) | Vermerk, Glocke an Admins (einmal je Befund), **eine** Zusammenfassung per Mail - nur wenn etwas betroffen ist |

Sichtbar in der Beraterwelt: **Signaturen -> Qualitaet** (nur admin) mit
Befund, Pruefzeit, Dauer und den Knoepfen "Neu erzeugen" / "Jetzt pruefen";
auf `/admin/systemzustand` die Zeilen "PDF-Pruefung (qpdf)" und
"Signatur-Qualitaet" (rot bei Befund).

## 2. Testmatrix (A2)

`tests/Support/SignaturPdfFixtures.php` erzeugt 13 Bauformen (Drehung
90/180/270, CropBox kleiner als MediaBox, MediaBox mit Versatz, indirektes
/Contents + Ressourcen per Referenz, AcroForm, PDF/A-Kennzeichnung,
fortgeschriebene Datei, reiner Scan, 12 Seiten, Querverweis-Strom mit
Objekt-Stroemen) plus verschluesselt und beschaedigt.
`SignaturQualitaetsmatrixTest`: jede Bauform x jede Feldart (Unterschrift,
Initialen, Name, Datum, Text, Kreuz, Unternehmenssignatur); Firmenbilder
als PNG mit Alpha, deckendes PNG, JPEG mit weissem Grund, helles und
farbiges Stempelbild, 4200 px grosses Bild; Zeichnungen mit
Geraeteverhaeltnis 1/2/3. Je Feld: sichtbar im Bildvergleich UND nicht
einfarbig; poppler meldet nichts Neues; `qpdf --check` fehlerfrei.

## 3. Befunde (A4)

| ID | Schwere | Bereich | Befund | Massnahme |
|---|---|---|---|---|
| KI-080 | MEDIUM | Darstellung | Feldanteile bezogen sich auf die MediaBox, Betrachter zeigen die CropBox: ein Feld im abgeschnittenen Rand war im Editor sichtbar, im Dokument nicht | Neue Anfragen: `feld_bezug = cropbox` (Vorschau, Stempler, Selbsttest, Diagnose); Bestand bleibt `mediabox` - keine Umrechnung alter Daten |
| KI-081 | MEDIUM | Protokoll | Zeilen liefen ueber den Rand, alles nach Seite 1 fiel still weg, Zustimmungstext nach 160 Zeichen, Bild-Hash nach 32 Zeichen abgeschnitten | Umbruch nach Helvetica-Breiten, weitere Protokollseiten, volle Texte und Hashes |
| KI-082 | LOW | Protokoll | ASCII-Umschreibungen ("Geraet", "bestaetigt") obwohl die Schrift Umlaute kann | echte Umlaute; alte Zustimmungs-Vorgabe wird weiter als Vorgabe erkannt (Uebersetzung bleibt) |
| KI-083 | MEDIUM | Token | Nach dem Abschluss blieb der Link bis zu 30 Tage ein Lesezugang zum unterschriebenen Vertrag (CLAUDE.md behauptete "widerrufen") | Frist beim Abschluss auf 7 Tage verkuerzt; danach HTTP 410 |
| KI-084 | MEDIUM | Eingang | Keine Strukturpruefung beim Hochladen; Verschluesselung nur an den letzten 3000 Bytes erkannt | `PdfEingangspruefung` (qpdf + Trailer) |
| KI-085 | MEDIUM | Qualitaet | Selbsttest pruefte nur Bildfelder, keine poppler-Meldungen, keine Struktur; kein Nachtlauf, keine Anzeige | Qualitaetsgate wie in Abschnitt 1 |
| KI-086 | LOW | Diagnose | Offene Vorgaenge mit deckendem Logo standen als "betroffen" da (Bildanalyse des ALTEN Stemplers) | Vorschau im Speicher + Bildvergleich; deckendes Bild ist nur noch Risiko |
| KI-087 | LOW | Protokoll | Download des Originals durch Mitarbeiter wurde nicht protokolliert | wird protokolliert |
| KI-088 | MEDIUM | Eingang | Ein sehr helles Firmenbild waere erst NACH dem Unterschreiben gescheitert | Ablehnung beim Hochladen |
| KI-089 | MEDIUM | Zustaende | Keine formale Zustandsmaschine: der Status wird an 9 Stellen direkt gesetzt, Uebergaenge werden nicht geprueft | **offen** - Teil C (Ablehnen/Reaktivieren) |
| KI-090 | LOW | Leistung | Abschluss laeuft synchron im Unterschreiben-Request: gemessen 108 ms (1 Seite) bzw. 1,1 s (12 Seiten mit je einer Unterschrift); hochgerechnet ~18 s bei 200 Seiten | **offen** - Auslagerung in einen Job erst, wenn reale Dokumente es noetig machen (Dauer steht jetzt je Vorgang in `render_ms`) |
| KI-091 | LOW | Format | Das fertige PDF ist nicht mehr PDF/A-konform (Transparenz der Unterschrift, Helvetica nicht eingebettet); Zeichen ausserhalb WinAnsi werden umschrieben (Bestand: 0 von 1424 Namen betroffen) | **offen** - eingebettete Unicode-Schrift erst bei Bedarf |

Ohne Befund geprueft:

- **Welche Datei geht wohin**: Mitarbeiter-Download "Unterschrieben" =
  `signed_path` (nach Neu-Erzeugen die neue Datei, die alte bleibt liegen);
  "Original" = die geprueft(-reparierte) Basis. Unterzeichner-Link vor dem
  Abschluss: Original, danach das fertige PDF. Abschluss-Mail: genau das
  PDF, das den Selbsttest bestanden hat (Anhang, nicht Link). Vorschau nach
  Abschluss: das fertige PDF (KI-057).
- **Warteschlange**: das Modul nutzt keine Jobs; Abschluss-/Code-/
  Einladungsmails sind bewusst nicht queued. Gleichzeitiges Abschliessen ist
  per `Cache::lock` (120 s, 30 s Warten) gesperrt (KI-030). pdftoppm/qpdf
  haben je 60 s Zeitlimit.
- **Token**: 40 Zeichen, nur als SHA-256 gespeichert, 30 Tage Frist,
  Widerruf bei Storno/Ablauf; unbekanntes Token sieht aus wie abgelaufenes.
  Ratenbegrenzung `throttle:signatur` (60/min je IP, 120/min je Token),
  Identitaetspruefung 10/10 min, Code-Versand gedeckelt (KI-027).
- **Berechtigung**: `SignatureRequestPolicy` - Kundenvorgaenge nach
  Portfolio, eigenstaendige nach Ersteller + Leitung; geprueft in
  `ZugriffspruefungTest`. Die neue Qualitaetsseite ist nur admin (Route UND
  Controller). Es gibt EINE Firma - "nur die eigene Firma" ist damit die
  Portfolio-/Ersteller-Regel.
- **Protokoll**: jedes Ereignis in `signature_events` (append-only), neu:
  `document_repaired`, `quality_failed`, `quality_passed`; `pdf_generated`
  traegt SHA-256 von Basis, hochgeladener Datei und Ergebnis sowie die Dauer.

## 4. Messung

Gleiche Maschine, Median aus 3 Laeufen, Abschluss inkl. PDF-Erzeugung:

| | 1 Seite | 12 Seiten (je 1 Unterschrift) |
|---|---|---|
| ohne Pruefung | 28 ms | 108 ms |
| mit Qualitaetsgate | 108 ms | 1079 ms |

Die Mehrkosten sind das Rendern je bestempelter Seite (Original +
Ergebnis) und `qpdf --check` - der Preis dafuer, dass "Abgeschlossen" eine
gemessene Aussage ist.

## 5. Betrieb

1. `apt install qpdf` auf dem Server (ohne qpdf laeuft alles, nur die
   Strukturpruefung entfaellt - Systemzustand zeigt es gelb).
2. Nach dem Deploy einmal `php artisan signaturen:qualitaet-pruefen
   --ohne-mail` laufen lassen und `/admin/signaturen/qualitaet` ansehen.
