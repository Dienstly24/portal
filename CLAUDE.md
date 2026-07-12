# Dienstly24 Portal — Projektkontext für Claude

Dies ist das CRM/Kundenportal von **Dienstly24**, einem Versicherungs- und
Energie-Makler. Laravel 13 / PHP 8.3+, gehostet auf einem Hostinger-VPS.
Domains: `admin.dienstly24.de` (Beraterwelt), `portal.dienstly24.de`
(Kundenportal). Die Kommunikation mit dem Betreiber läuft überwiegend auf
Arabisch; **antworte dem Nutzer auf Arabisch**, aber halte allen Code,
Commits, UI-Texte und Kommentare auf **Deutsch/ASCII**.

## Arbeitsweise (WICHTIG — immer so vorgehen)

1. **Nie direkt deployen.** Für jede Änderung einen Feature-Branch anlegen,
   committen, pushen und einen **Pull Request mit `base=main`** öffnen.
2. Der **Nutzer reviewt und merged selbst.** Merge auf `main` löst über
   GitHub Actions automatisch den Deploy aus.
3. **Prüfe bei jedem PR, dass `base=main` ist** (ein früherer PR wurde
   versehentlich gegen einen toten Branch geöffnet und lief ins Leere).
4. Nach einem Merge für Folgearbeit **immer `git fetch origin main` und neu
   branchen** — sonst arbeitet man auf veraltetem Stand.
5. Vor jedem Push **die volle Testsuite grün** halten: `php artisan test`.
6. UI-/E-Mail-Änderungen möglichst **real verifizieren** (Headless-Chromium
   unter `/opt/pw-browsers/…`, `playwright-core`), nicht nur Tests.

## Deploy

- CI/CD: `.github/workflows/deploy.yml` — Tests bei Push & PR; Deploy nur bei
  Push auf `main`.
- **Bekanntes Problem:** Der SSH-Deploy schlägt teils mit `i/o timeout` fehl
  (VPS-Erreichbarkeit/Firewall Port 22). Das ist **kein Code-Fehler**.
  Manueller Deploy auf dem Server:
  ```
  cd /var/www/dienstly24/portal && git fetch --all --prune \
    && git reset --hard origin/main && bash scripts/deploy.sh
  ```

## Feste Regeln (Sicherheit / DSGVO)

- **Löschen von Kunden:** Admin per UI **max. 30 pro Bulk-Aktion**;
  **Mitarbeiter dürfen NIE löschen**. Voll-Purge nur per CLI
  (`php artisan customers:purge --force`).
- `CustomerDeletionService` darf **niemals Staff-/Partner-Accounts** löschen
  (Guard: nur `role === 'customer'`).
- **Keine Geheimnisse im Chat/Repo** (SSH-Keys, Tokens, Passwörter) — nur
  GitHub Secrets / Server-`.env`.
- **Keine erfundenen Daten**: keine falschen Impressum-Angaben, USt-IdNr.
  oder Fake-Statistiken (z. B. „15.000 Kunden") in der UI.
- Magic-Login-Link nie in QR-Codes oder geteilten Assets einbetten.
- Terminal-Befehle für den Nutzer immer **Deutsch/ASCII**.

## Kundennummern

- Neuanlage: `JJ` + 5-stellig laufend (2026 → `2600001`, `2600002` …) via
  `CustomerNumberGenerator::generate()`.
- Import aus Fremdplattform: `25` + Original-Nummer via
  `generateForImport($original)`. Alt-Nummern (`C-…`) bleiben gültig.

## Wichtige Bausteine

- **E-Mails** (`resources/views/emails/`): tabellenbasiert, Inline-Styles,
  **kein SVG** (Gmail/Outlook entfernen es → Emoji nutzen). Bilder als
  CID-Inline via `{{ isset($message) ? $message->embed(public_path(...)) : url(...) }}`.
- **Willkommens-Mail** = `CustomerWelcomeMail` + `customer_welcome.blade.php`
  (kompakt, ein Bildschirm). Enthält Magic-Login (90 Tage) und Hilfe-Button.
- **Hilfe-Formular**: `SupportFormController` → `/hilfe`. Aus der Mail mit
  verschlüsseltem Kunden-Token vorbefüllt; Absenden legt automatisch ein
  Ticket an, verknüpft mit der Kundenakte.
- **Rechtsseiten** (`/impressum`, `/agb`, `/datenschutz`,
  `/cookie-richtlinie`, `/kontakt`): leiten standardmäßig auf die offizielle
  Website weiter (`LegalPageController`, Basis-URL unter Einstellungen →
  Rechtliches). Feld leeren = Portal zeigt eigene Fallback-Seiten.
- **Login/Registrierung** (`resources/views/auth/`): Single-Screen (kein
  Scroll), Glas-Karte, `logo-white.png` ohne weißen Kasten, DE/AR-Umschalter.
- **Arabisch/RTL**: `lang/ar.json`, `SetLocale`-Middleware,
  `dir="rtl"`-Layout. Neue UI-Strings mit `__()` wrappen und in `ar.json`
  ergänzen.
- **Banner-Verwaltung**: `BannerController`, Statistik-Dashboard unter
  `/admin/banners/statistik`. Routen auf `role:admin,manager` beschränkt.
- **Logo-Assets**: `logo-white.png` (helle Variante, für dunkle Flächen),
  `logo-transparent.png` (für helle Flächen), `logo.png` (Original mit
  weißem Hintergrund, nur noch in der E-Mail auf weißem Kopf).

## Offene Themen / wartet auf den Betreiber

- **SPF/DKIM/DMARC** für `dienstly24.de` in Hostinger-DNS: DKIM-Schlüssel ist
  aktuell leer (`p=`) → Willkommens-Mails landen im Spam. DNS-Aufgabe, kein
  Code. (Details siehe frühere Diagnose.)
- **WordPress-Rechtsseiten** (`dienstly24.de/impressum` etc.) sind leer und
  müssen mit Inhalt gefüllt werden.
- **Finale Logo-Dateien** kommen vom Betreiber (bevorzugt SVG, sonst PNG
  transparent ≥320px hoch; Light- und Dark-Variante; optional 512×512 Icon).
- **Partner-Portal** (voller Ausbau) und **E-Mail-Einwilligung des Kunden
  (Variante B)**: Konzepte in `docs/KONZEPT_PARTNER_GESCHAEFTSMODELL.md` und
  `docs/KONZEPT_EMAIL_EINWILLIGUNG_DSGVO.md` — warten auf Entscheidungen des
  Betreibers, noch nicht bauen.

## Weitere Doku

Ausführliche Berichte und Konzepte liegen unter `docs/` (Audit, Phasen,
Production-Readiness, Konzepte). Bei Bedarf dort nachschlagen.
