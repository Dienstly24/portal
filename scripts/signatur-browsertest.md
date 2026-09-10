# Signatur-Modul: Durchlauf im echten Browser

Die Testsuite prüft den Server. Sie kann nicht prüfen, ob sich mit dem
Finger auf einem Telefon unterschreiben lässt, ob Arabisch richtig
gespiegelt wird oder ob der Knopf nach der Zurück-Taste wieder bedienbar
ist. Genau das steht hier — es ist bewusst **kein** blosser Seitenaufruf:
der gemeldete Fehler trat erst beim ABSENDEN auf.

## Vorbereitung

```
php artisan serve --host=127.0.0.1 --port=8123
npm install playwright-core --no-save --prefix /tmp/pw
```

Chromium liegt auf dem Server unter
`/opt/pw-browsers/chromium-1194/chrome-linux/chrome` (Playwright-Bundle,
kein zusätzlicher Download).

## Was geprüft wird

| Fall | Gerät / Sprache | Erwartung |
|---|---|---|
| Voller Durchlauf | Desktop 1440×900 @2x | Zeichnen, Bestätigen, Abschluss-Seite, Prüfsumme sichtbar |
| Voller Durchlauf | iPhone 13 | dito, Zeichnen mit Zeiger/Finger |
| Voller Durchlauf | Pixel 5 | dito |
| Arabisch | Desktop + iPhone | `dir="rtl"`, `lang="ar"`, keine deutsche Zeile, Abschluss auf Arabisch |
| Englisch | Desktop | `dir="ltr"`, `lang="en"` |
| Zwei Reiter | Desktop | Der zweite landet auf der **Abschluss-Seite**, nie auf 403/500 |
| Neuladen der Abschluss-Seite | Desktop | bleibt Abschluss-Seite |
| Zurück-Taste | Desktop | Knopf wieder bedienbar (kein totes Formular) |
| Geburtsdatum | Desktop | Falsche Angabe → neutrale Meldung; richtige → Dokument |

**Bei jedem Fall gilt zusätzlich:** keine Konsolenmeldung vom Typ `error`,
kein `pageerror`, keine Antwort ≥ 500. Der Skriptrahmen sammelt sie und
gibt sie im Ergebnis aus — sonst übersieht man sie.

## Ergebnis 10.09.2026

Alle Fälle grün, `fehler: []` überall. Das erzeugte PDF wurde zusätzlich
gerendert (`pdftoppm`) und gelesen (`pdftotext`):

- Handschrift an der gesetzten Stelle, **durchscheinend** (der
  Vertragstext bleibt darunter lesbar),
- Firmenstempel daneben, ebenfalls transparent,
- Protokollseite **deutsch und von links nach rechts**, obwohl der
  Unterzeichner arabisch bzw. englisch bedient wurde,
- Original-Präfix Byte für Byte erhalten, beide SHA-256 stimmen.

## Nicht mit Chromium prüfbar

Safari (macOS/iOS), Firefox und Edge stehen auf dem Server nicht zur
Verfügung. Die Seite benutzt bewusst nichts, was dort anders liefe:
keine Fremdbibliothek, `pointer`-Ereignisse (in allen genannten Browsern
seit Jahren umgesetzt), Canvas-`toDataURL('image/png')`, normale
Formulare. Der Rest ist server-gerendertes HTML. Ein echter Durchlauf auf
einem iPhone bleibt trotzdem der Prüfstein vor dem Livegang — er dauert
zwei Minuten und ersetzt keine Annahme.
