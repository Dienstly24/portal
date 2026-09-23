# Architecture

Stand: 23.09.2026. Dokumentiert, wie das System TATSAECHLICH gebaut ist.

## Grundform

**Klassischer Laravel-Monolith mit serverseitigem Rendering.** Es gibt
keine SPA und keine separate API-Schicht: Controller liefern Blade-Views,
einige Endpunkte liefern JSON fuer Sofort-Suchen, Chat-Feeds und Status-
Abfragen (siehe [API_MAP.md](API_MAP.md)). Eine Codebasis, eine
Datenbank, vier Oberflaechen, unterschieden ueber Host (Website) und
Pfad-Praefix + Rolle (`/admin`, `/portal`, `/partner`).

```
Browser (Website-Besucher | Kunde | Mitarbeiter | Partner | Unterzeichner)
   |                                     Plattformen (Meta/WhatsApp-Webhook)
   v                                            |
Hosting-CDN (hcdn, NICHT Cloudflare - gemessen 05.09.2026) -> nginx -> PHP-FPM
   |
   v
Laravel HTTP-Kernel
   Globale Middleware: RedirectWebsiteHost (vorn), TrustProxies (explizite Liste),
                       SecurityHeaders (CSP mit Nonce), ExtraBasicAuth
   Web-Gruppe: Session, CSRF (Ausnahmen: api/website-*, abmelden/*, webhooks/*),
               SetLocale, TrackStaffActivity, AuthenticateSession,
               EnsurePasswordChanged, EnsureTwoFactor
   Routen-Middleware: auth, role:<liste>, can:<gate>, throttle:<limiter>, signed
   |
   v
Controller (82)  --->  Views (Blade, 255)  --->  Vite-Bundle (app.css + ui.js)
   |
   v
Services (281 Dateien, fachlich gruppiert)  <--->  Support-Helfer (45)
   |            |                    |
   v            v                    v
Eloquent-Modelle (103)   Jobs (Queue: database)   Mailables (21)
   |                          |
   v                          v
MySQL (~110 Tabellen)     Worker (`default` + `lang`)
   |
Storage: private Disk (Dokumente, Nachweise, Signaturen), public Disk (Medien-Varianten)

Console: routes/console.php -> Planer (~35 Aufgaben, Zeitzone Europe/Berlin)
         + 54 Artisan-Befehle (Betrieb, Nachtraege, Diagnose)

Externe Dienste (nur serverseitig, mit Zeitlimits): Anthropic, OpenAI (optional),
Meta Graph (FB/IG/Ads/WhatsApp), Lexoffice, Google Places, Gmail/Graph/IMAP,
Cloudflare Turnstile, HaveIBeenPwned, Matomo (Browser, nach Einwilligung),
Tesseract/poppler (lokale Prozesse), SMTP (Hostinger)
```

## Komponenten und ihre Abhaengigkeiten

| Komponente | Kern-Dateien | Haengt ab von | Wird benutzt von |
|---|---|---|---|
| Kundenakte | `Customer`, `AdminController`, `customer_show.blade.php` | User, Partner, employee_customers | fast alles |
| Sichtbarkeit | `User::canAccessCustomer/visibleOwnerIds`, `Customer::scopeVisibleTo`, Trait `ScopesCustomerAccess` | employee_customers, substitutions | jeder Staff-Controller, Postfach, Suchen |
| Vertraege | `Contract` (+ Energy/Vehicle/Internet-Details), `Admin\ContractController`, `ContractSwitchService`, `VehicleOverlapGuard` | Customer | Portal, Berichte, Provisionen, Dokumenten-Eingang |
| Dokumenten-Eingang | `SmartDocumentUploadController`, `DocumentAnalyzer`, `DocumentIntakeService` (2229 Z.), `TemplateParsers/*` | OCR, KI-Anbieter, Matching, Vertraege | Portal-Upload, Mailbox, Messaging-Anhaenge |
| Matching/Dubletten | `CustomerMatchingService`, `DuplicateDetectionService`, `CustomerMergeService` | Customer + alle customer_id-Tabellen | Eingang, Import, Admin |
| Messaging-Kern | `ConversationEngine`, `ChannelRoutingService`, `AssignmentService`, `CustomerResolver`, `Channels/*Adapter` | customer_messages, conversations, channels | Postfach, Portal-Chat, WhatsApp, KI |
| KI-Assistent | `Ai/Assistant/*`, `AnswerCustomerMessageJob` | Messaging, Tickets, DocumentRequests, Wissensbasis | Portal-Chat, Website |
| Provisionen (Eingang) | `CommissionImport/*`, `Provisionsmanagement/*`, `Vermittler/*` | Contracts | Berichte, Vertragsakte |
| Provisionen (Ausgang) | `ContractProvisionService`, `ProvisionController` | Contract-Hook, Werber des Kunden | Monatsbericht |
| Leseschicht Provisionen | `CommissionReadService` | alle drei Straenge | Vertragsakte |
| E-Signatur | `Signature/*`, `Pdf/*`, `SignatureSigningController` | GD, poppler, private Disk | Kunden/Vertraege (optional) |
| Benachrichtigung | `NotificationService`, `InternalNotification` | - | nahezu alle Fachbereiche |
| Betrieb | `SystemHealthService`, `ErrorRecorder`, `ScheduledTaskRun`-Listener | Queue, Planer, Konfig | Admin, `/gesundheit` |

## Querschnittsregeln (verbindlich, Details in CLAUDE.md)

1. **Zeit**: gespeichert UTC, angezeigt Europe/Berlin (`->lokal()`); Planer in Europe/Berlin.
2. **Sichtbarkeit**: EINE Quelle (User-Methoden + `scopeVisibleTo`), nie eigene Kopien.
3. **Aktiv-Definition**: `Contract::isCurrentlyActive()` / `currentlyActive()`, nie `status === 'active'`.
4. **CSP**: kein Inline-Handler, `@pushOnce('cspScripts')` + `data-h-*`, `@push` VOR `@stack`.
5. **Kein externer Browser-Zugriff** auf der Website (Schriften lokal, keine Karten/Fonts von Google).
6. **Fremddienste nur mit Zeitlimit**, lange Aktionen als Job (`tries = 1` bei Nicht-Idempotenz).
7. **Batch-Laeufe**: `ProcessesRecordsSafely` - ein Datensatz stoppt nie den Lauf.
8. **Nie raten**: Zuordnungen nur bei genau einem eindeutigen Treffer; Name zaehlt nie.
9. **Nichts loeschen, was Nachweis ist** (Signaturen, Protokolle, Provisions-Historie).
10. **Parser-Reihenfolge ist Programmlogik** (`AppServiceProvider`, spezialisiert vor generisch).

## Bindungen im Container (`AppServiceProvider`)

- `TextExtractorInterface` -> `TesseractTextExtractor`
- `DocumentAiProviderInterface` -> per `AI_DOCUMENT_PROVIDER` (Claude / Null)
- `AiProviderInterface` (Text) -> Claude
- Assistent-Anbieter -> `AI_ASSISTANT_PROVIDER` (claude Standard / openai / none)
- `OfferSourceInterface` -> `ManualOfferSource`
- `ChannelManager` (Singleton) registriert Adapter `portal`, `internal`, `whatsapp`
- `CompositeDocumentTemplateParser` mit geordneter Parser-Liste
- Gates: `provisionen-verwalten`, `firmensignatur-verwalten`, `firmensignatur-benutzen`
- Listener: `ScheduledTaskStarting/Finished/Failed` -> `scheduled_task_runs`
- `Password::defaults()` -> `PasswordPolicy`; Carbon-Makro `lokal()`;
  `Model::preventLazyLoading` ausserhalb Produktion; `ProductionDatabaseGuard`

## Warteschlangen

| Anschluss | Warteschlange | retry_after | Jobs |
|---|---|---|---|
| `database` / `redis` | `default` | 360 s | Analyse, KI-Antwort, Medien, Social, Kampagnen, Nachweis-Pruefung, Messaging-Versand |
| `database-lang` / `redis-lang` | `lang` | 2100 s | `ImportCustomersJob` |

Worker-Betrieb auf dem Server: **UNKNOWN** (nicht im Repo; `SystemHealthService`
meldet einen toten Worker am Alter des aeltesten Jobs).

## Bekannte strukturelle Schwachstellen

Siehe [TECHNICAL_DEBT.md](TECHNICAL_DEBT.md): sehr grosse Klassen
(`DocumentIntakeService`, `SmartDocumentUploadController`, `PortalController`,
`AdminController`), ~4900 Inline-Styles, PHPStan-Baseline.
