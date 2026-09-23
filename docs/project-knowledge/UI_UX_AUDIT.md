# UI/UX Audit

Stand: 23.09.2026. **In dieser Sitzung wurde keine Browser-Pruefung
durchgefuehrt** - die Befunde unten stammen aus Code-Analyse (neu) und den
Browser-Laeufen der letzten Audits (16.09., 19.09.2026). Eine neue
Browser-Runde ist im Fahrplan (R-06).

## Stand laut letzter Browser-Pruefung (16.09.2026)

13 Seiten je Geraet (Desktop 1440, Tablet 820, Telefon 390), oeffentlich
und angemeldet: keine Konsolen-/Netzwerkfehler, kein waagerechter Bildlauf,
`<h1>` auf jeder Seite, jedes Eingabefeld beschriftet, JSON-LD gueltig.
19.09.2026: Cookie-Banner auf dem iPhone ueber dem Anmeldeformular -> behoben.

## Staerken

- Einheitliches Farbsystem aus EINER Quelle (`brand.css`, `components.css`),
  Status-Farben getrennt von Markenfarben (`DesignSystemTest`).
- Portal "Telefon-first" (Topbar/Tabbar, safe-area), echte RTL-Unterstuetzung.
- Navigation der Beraterwelt gruppiert (7 Gruppen) mit Badges, die nur zaehlen,
  wo Arbeit wartet.
- Ehrliche Zustaende: gekuerzte Listen nennen die Gesamtzahl, Systemzustand
  zeigt Ursachen, Signatur-Statuskarte, Postfach zeigt unbekannte Kontakte.
- Barrierefreiheit: `Barrierefreiheit`-Test (h1, Labels), Honeypots bewusst unbenannt.

## Befunde (Code-Analyse 23.09.2026)

| Befund | Wirkung | Issue |
|---|---|---|
| 28 `__()`-Texte in Kundenbereichen ohne arabische Uebersetzung, darunter die komplette Seite "Bitte bestaetigen Sie Ihre E-Mail-Adresse" nach der Registrierung, "Fruehere Nachrichten laden" im Chat und "Cookie-Einstellungen" im Website-Fuss | arabischer Kunde liest mitten im Ablauf Deutsch | KI-007 |
| Breeze-Reste `verify-email`/`confirm-password` auf Englisch, erreichbar per URL | fremdes Aussehen, englisch, tote Funktion | KI-016 |
| ~4900 `style="..."` in Vorlagen, groesste Vorlagen >1500 Zeilen | Konsistenz schwer zu halten, jede Aenderung riskant | TD-02 |
| Mitarbeiter-Anlage: Formular zeigt einem Manager Rechte, die er nicht vergeben kann; Mail nennt sie trotzdem | irrefuehrende Rueckmeldung | KI-006 |

## Pruefregeln (verbindlich)

1. Jede sichtbare Aenderung in BEIDEN Sprachen und auf JEDEM betroffenen
   Layout im Browser ansehen (Headless-Chromium `/opt/pw-browsers`,
   `playwright-core`), Telefon 390 px zuerst.
2. Ziffernfolgen in RTL `dir="ltr"`.
3. Neue Texte: `__()` + `lang/ar.json` im selben Commit.
4. Keine neuen Inline-Handler; `@push` vor `@stack`.
5. `[hidden]` gegen Klassen mit `display` absichern (in selbsttragenden
   Partials selbst definieren).
6. Kein Bedienelement, das nichts tut (lieber ausblenden).
