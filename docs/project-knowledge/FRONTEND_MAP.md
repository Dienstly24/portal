# Frontend Map

Stand: 23.09.2026. Serverseitiges Rendering mit Blade; kein SPA-Framework.

## Build

- `vite.config.js`: Eingaenge `resources/css/app.css`, `resources/js/app.js`;
  Plugin `@tailwindcss/vite` (Tailwind 4); `cssMinify: false` (Lehre 23.07.2026,
  lightningcss-Binary). `postcss.config.js` ist absichtlich leer.
- `public/build` ist NICHT im Repo; der Deploy baut es (`npm ci && npm run build`)
  und stellt bei Fehlschlag den alten Build wieder her.
- JS-Bundle ~4,4 kB (seit SEC-4 ohne Alpine).
- Nicht gebuendelt, direkt aus `public/`: `js/chart.umd.min.js` (nur Seiten mit
  `@stack('charts')`), `js/brand.js` (Markenfarben fuer Canvas), `fonts/`
  (lokale Schriften inkl. arabischer Subsets), `website-assets/` (Website-CSS/JS),
  `images/` (Logos, Favicon), `dienstly-bimi-logo.svg` (BIMI, Regeln in `BimiLogo`).

## CSS

| Datei | Inhalt |
|---|---|
| `resources/css/brand.css` | Farb-Tokens (`--emerald*`, `--gold*`, `--graphite*`, `--status-*`) - EINE Quelle |
| `resources/css/components.css` | Bausteine `.card/.btn/.badge/.field/...` (einmal definiert) |
| `resources/css/app.css` | `@import 'tailwindcss'`, `@config`, `[hidden]{display:none!important}` |
| `resources/css/responsive.css` | Breakpoint-Regeln |
| `resources/css/analytics.css` | nur Auswertungs-Dashboard |
| `public/website-assets/site.css` | Website (ohne Vite) |

Regeln: Gold = Akzent, Smaragd = Aktion; keine Petrol-/kuehlen Grautoene;
Seiten ohne Vite (Fehlerseiten, Druck, Cookie-Banner, E-Mails) duerfen Hex tragen.
Test: `DesignSystemTest`.

## JavaScript-Muster (CSP ohne unsafe-inline)

- `resources/js/ui.js` (Modul): generische Verhalten ueber data-Attribute:
  `data-confirm`, `data-row-nav`, `data-toggle/-show/-hide`, `data-fill-target`,
  `data-menu*`, `data-bulk*`, `data-h-<ereignis>="<name>"`.
- Seitenspezifische Handler: `@pushOnce('cspScripts')` mit
  `<script @cspNonce>window.__h = window.__h || {}; window.__h["name"] = fn;</script>`.
- **FALLE**: `@push` NACH `@stack` derselben Datei geht still verloren
  (`BladeCompileTest`/CSP-Tests pruefen das).
- Sofort-Suchen bauen Trefferlisten per `textContent` (Fremddaten, kein innerHTML).

## Layouts

| Layout | Benutzt von | Besonderheiten |
|---|---|---|
| `layouts/admin.blade.php` | alle `/admin`-Seiten | Sidebar aus `App\Support\Navigation\AdminNavigation` (Gruppen: Dashboard, Postfach, Mein Tag, Kunden, Dokumente, Vertrieb, Marketing, Administration), Kopfzeilen-Suche, Glocke, Nav-Badges (`NavBadges`) |
| `layouts/portal.blade.php` | `/portal` | Telefon-first (Topbar + Tabbar, safe-area), DE/AR, `dir="rtl"` |
| `layouts/partner.blade.php` | `/partner` | schlank, lesend |
| `website/layout.blade.php`, `website/legal-layout.blade.php` | Website | ohne Vite-Bundle, eigene Assets, Matomo-Partial, Cookie-Banner |
| `signature/_layout.blade.php` | Unterschreiben | eigenstaendig, DE/AR/EN, RTL |

Anmelde-/Registrierseiten (`resources/views/auth/*`) nutzen eigene
Glas-Karten-Styles (`partials/auth_glass_styles`).

## View-Bereiche (`resources/views`, ~242 Dateien)

- `admin/` - Beraterwelt. Groesste Vorlagen: `documents_inbox` (1636 Z.),
  `customer_show` (1594), `tasks` (638), `customer_chat` (532), `compose_email` (526).
  Unterordner: `activity`, `ai_providers`, `channels`, `commissions_internal`,
  `email_accounts`, `internal_chat`, `ki_training`, `partials` (u.a.
  `analytics/*`, `family_relations`, `contract_revisions`), `postfach`,
  `provisionsmanagement`, `signatures`.
- `portal/` - Kundenportal (Dashboard, Vertraege, Dokumente, Tickets, Nachrichten,
  Self-Service: Familie/Adressen/Kontakte/Bank, Aenderungsantraege, Profil).
- `partner/` - 5 Seiten.
- `website/` - Startseite, Hamburg, Danke, `legal/*`, `partials/*` (Assistent,
  Google-Vertrauenskarte ...); `services/` - Leistungsseiten.
- `signature/` - Unterschreiben (verify, identity, sign, done, blocked).
- `emails/` - tabellenbasiert, Inline-Styles, kein SVG.
- `errors/` - 404/500 zweisprachig im Markendesign (ohne Vite).
- `components/admin/*` - Navigation (die Breeze-Komponenten sind seit 23.09.2026 entfernt).
- `partials/` - `cookie_consent` (selbsttragend), `matomo`, `chat_core/_styles`,
  `doc_preview`, `favicon`.

## Sprachen

- `lang/ar.json` (749 Schluessel), `lang/ar/validation.php`, `lang/de/validation.php`,
  `lang/{de,ar,en}/signing.php`.
- UI-Texte in `__()`; Portal/Website/Auth muessen in `ar.json` stehen.
  Waechter `ArabischeUebersetzungVollstaendigTest` verlangt fuer jeden `__()`-Text
  in portal/auth/website/services/support/partials einen Eintrag (KI-007).
- Abstaende, die der Leserichtung folgen sollen: `padding-inline-start` statt
  `padding-left` (KI-021).
- Arabisch = echte URLs `/ar/...` auf der Website, Sitzungs-/Profilsprache im Portal.
- Ziffernfolgen (Telefon, IBAN) in RTL immer `dir="ltr"`.

## Oberflaechen-Pruefung

Nicht nur Tests: Headless-Chromium (`/opt/pw-browsers`, `playwright-core`) auf
Desktop 1440 / Tablet 820 / Telefon 390, BEIDE Sprachen, JEDES betroffene Layout
(Lehren 02.10. und 19.09.2026). Siehe [UI_UX_AUDIT.md](UI_UX_AUDIT.md).
