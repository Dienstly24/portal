# Project Overview

Stand: 23.09.2026, `main` @ 04c0823. Quelle: Code-Durchsicht + `CLAUDE.md`.

## Was ist das System?

Das **Dienstly24 Portal** ist das zentrale Betriebssystem eines kleinen
Versicherungs- und Energie-Vermittlers mit Sitz in Hamburg (Einzelunternehmen,
vertraglich gebundener Vermittler unter der Haftung von NESA - siehe
`/erstinformation`). Der Betrieb arbeitet **ausschliesslich online**
(kein Besuchsbetrieb). Eine einzige Laravel-Anwendung liefert vier
Oberflaechen aus:

| Oberflaeche | Host | Zweck |
|---|---|---|
| **Marketing-Website** | `www.dienstly24.de` (+ `/ar/...`) | Leistungsseiten, Kontakt, Website-KI-Assistent, SEO |
| **Kundenportal** | `portal.dienstly24.de/portal` | Kunde sieht eigene Vertraege, Dokumente, Tickets, Chat, Self-Service |
| **Beraterwelt (CRM)** | `admin.dienstly24.de/admin` | Mitarbeiter verwalten Kunden, Vertraege, Dokumente, Provisionen, Kommunikation |
| **Partnerportal** | `/partner` | Kooperationspartner sehen LESEND ihre Kunden und Provisionen |

Dazu oeffentliche Einzweck-Seiten ohne Konto: Unterschreiben
(`/unterschreiben/{token}`), Hilfeformular (`/hilfe`), Abmeldung
(`/abmelden/{token}`), Magic-Login, Social-Kurzlinks (`/s/{code}`).

## Ziel

Den gesamten Vermittlungsalltag in einem System abbilden: Kunde gewinnen
-> Unterlagen einsammeln und automatisch auslesen -> Vertrag anlegen und
ueber seinen Lebenszyklus fuehren -> mit dem Kunden auf allen Kanaelen
kommunizieren -> Provisionen der Gesellschaften/Pools abgleichen und
fehlende nachverfolgen. Leitlinien: "kostenlos zuerst" (Parser/OCR vor
KI), "nie raten" (keine automatische Zuordnung ohne eindeutigen Beleg),
DSGVO-Datenminimierung, nichts erfinden (keine Fake-Zahlen/Angaben).

## Nutzer und Rollen

`users.role` (String) - siehe [AUTH_SYSTEM.md](AUTH_SYSTEM.md):

| Rolle | Wer | Oberflaeche |
|---|---|---|
| `admin` | Inhaber / Leitung | Beraterwelt, alles |
| `manager` | Leitung Team | Beraterwelt, fast alles (keine Kanaele/KI-Anbieter/E-Mail-Konten) |
| `support` | Kundenservice | Beraterwelt, Portfolio-gescoped |
| `employee` | Berater | Beraterwelt, Portfolio-gescoped, Einzelrechte (`can_*`) |
| `partner` | externe Kooperationsfirma | Partnerportal, nur eigene Kunden, lesend |
| `customer` | Endkunde | Kundenportal, nur eigene Akte |

Ohne Konto: Website-Besucher / Interessenten (`ai_leads`), Unterzeichner
von Signaturanfragen, Absender auf externen Kanaelen (WhatsApp) ohne Akte.

## Hauptfunktionen (Details: [FEATURE_MAP.md](FEATURE_MAP.md))

- Kundenakte (Stammdaten, Familie, Fahrzeuge, Adressen, Timeline, Dubletten/Merge)
- Vertraege aller Sparten (Kfz, Energie, Internet, Kranken, Gewerbe ...) mit
  Status-Logik, Wechsel-Automatik, Version History
- **Smart Document Upload**: PDF-Textebene -> OCR -> ~41 Vorlagen-Parser ->
  Heuristik -> KI (Claude) als Eskalation; Zuordnung zu Kunde/Vertrag
- Kommunikation: Tickets, Portal-Chat, E-Mail-Postfaecher (IMAP/Gmail/Graph),
  WhatsApp, vereinheitlichtes **Postfach**, Team-Chat, Glocke
- **KI**: Kundenassistent + Verkaufsassistent im Portal-Chat, Website-Assistent,
  Wissensbasis, Wissensluecken, KI-Training aus Verlaeufen
- **Provisionen**: Provisionsmanagement (Pools, Importe CSV/XLSX/XLS,
  Status-Engine, fehlende Provisionen), Vermittler-Abrechnung TARIFCHECK24,
  interne Ausgangs-Provisionen an Mitarbeiter/Partner, Gutschriften
- **E-Signatur** (eigenes PDF-Stempeln, Signaturgruppen, Firmensignatur)
- Marketing: Banner, Social-Publishing (Meta), Werbeanzeigen, Newsletter,
  Leistungsseiten, Medienverwaltung, SEO, Matomo
- Betrieb: Systemzustand, Fehlerliste, Aktivitaetserfassung, Backups

## Technologie

| Ebene | Technik |
|---|---|
| Sprache/Framework | PHP 8.3 (Server, CI) / Laravel 13 |
| Datenbank | MySQL 8 (Produktion), SQLite (Tests/lokal; in Produktion gesperrt) |
| Queue/Cache/Session | `database`-Treiber (Redis vorbereitet, Server-Entscheidung) |
| Frontend | Blade (serverseitig), Vite 8, Tailwind 4 (`@tailwindcss/vite`), Vanilla-JS (`resources/js/ui.js`), **kein** Alpine/SPA, Chart.js lokal |
| PDF/Bild | eigener PDF-Leser/Stempler (pur PHP), GD, poppler (`pdftotext`, `pdftoppm`), Tesseract |
| Composer-Pakete | nur 5 Laufzeitpakete: framework, tinker, league/csv, smalot/pdfparser, webklex/php-imap |
| Qualitaet | PHPUnit 12, Pint, PHPStan/Larastan Stufe 5 + Baseline |
| Hosting | Hostinger-VPS, nginx + PHP-FPM, Deploy per GitHub Actions SSH |

Externe Dienste: [INTEGRATIONS.md](INTEGRATIONS.md).

## Ende-zu-Ende (typischer Weg)

1. Interessent findet die Website (SEO / Google-Profil / Social) -> Kontaktformular,
   Leistungsanfrage oder Website-KI-Assistent -> **Ticket/Lead** in der Beraterwelt.
2. Mitarbeiter legt Kunden an (oder Kunde registriert sich zweistufig) ->
   Kundennummer `JJnnnnn` -> Portal-Einladung (Startpasswort = Geburtsdatum,
   Zwangswechsel beim ersten Login).
3. Unterlagen kommen ueber Portal-Upload, Mail-Postfach, WhatsApp oder
   Eingang -> `AnalyzeDocumentJob` liest sie aus -> Mitarbeiter bestaetigt
   im Review -> Kunde/Vertrag werden angelegt oder ergaenzt (Antrag ->
   spaeter Police ergaenzt denselben Vertrag).
4. Laufender Betrieb: Chat/Postfach/Tickets, Aufgaben, Erinnerungen
   (Planer), Aenderungsantraege mit Nachweis, Zaehlerstaende, E-Signatur.
5. Geld: Provisionsdateien der Pools werden importiert, an Vertraege
   gebunden; die Status-Engine meldet fehlende/ueberfaellige Provisionen.
6. Ueberwachung: `/admin/systemzustand`, `/admin/fehler`, `/gesundheit`.

## Wo steht was?

- Regeln/Lehren: `CLAUDE.md`
- Fachberichte: `docs/*.md`
- Arabische Betreiber-Anleitungen: `docs/*_AR.md`
- Diese Wissensbasis: `docs/project-knowledge/`
