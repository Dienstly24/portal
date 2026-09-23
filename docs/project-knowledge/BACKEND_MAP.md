# Backend Map

Stand: 23.09.2026. `app/` umfasst ~102.000 Zeilen PHP.

## Verzeichnisse

| Pfad | Anzahl | Rolle |
|---|---|---|
| `app/Http/Controllers` | 82 | Web-Controller (Blade + einzelne JSON-Endpunkte) |
| `app/Http/Controllers/Admin` | 10 | ausgelagerte Beraterwelt-Teile (Vertraege, Dokumente, Dubletten, Postfach, Signaturen, Kanaele, KI-Training, KI-Anbieter, Firmenbilder, WhatsApp-Onboarding) |
| `app/Http/Controllers/Auth` | 12 | Anmeldung, Registrierung, Passwort, 2FA, Magic-Login |
| `app/Http/Controllers/Webhooks` | 1 | WhatsApp |
| `app/Http/Controllers/Concerns` | 1 | `ScopesCustomerAccess` (Portfolio-Pruefung) |
| `app/Http/Middleware` | 10 | siehe [AUTH_SYSTEM.md](AUTH_SYSTEM.md) |
| `app/Http/Requests` | - | FormRequests (Uploads, Einstellungen) |
| `app/Models` | 103 | Eloquent |
| `app/Services` | 281 | Fachlogik (unten) |
| `app/Support` | 45 | kleine, zustandslose Helfer und "EINE Quelle"-Klassen |
| `app/Jobs` | 10 | Queue-Jobs |
| `app/Mail` | 21 | Mailables |
| `app/Console/Commands` | 54 | Artisan-Befehle |
| `app/Policies` | 4 | CustomerChangeRequest, InternalConversation, InternalMessage, SignatureRequest |

## Groesste Controller (Zeilen)

SmartDocumentUploadController 1397 · AdminController 1012 · PortalController 1008 ·
Admin\ContractController 707 · AiAssistantController 668 · Admin\SignatureController 647 ·
ProvisionController 627 · ContractCommissionController 625 · TicketController 598 ·
EmployeeController 576 · Admin\PostfachController 516.

## Services nach Fachbereich

| Bereich | Wichtigste Klassen |
|---|---|
| `Ai/` | `DocumentAnalyzer` (Kaskade Textebene->OCR->Parser->Heuristik->KI), `HeuristicDocumentClassifier`, `ClaudeDocumentAiProvider`, `ClaudeTextProvider`, `TemplateParsers/*` (~43 Parser + `CompositeDocumentTemplateParser`), `Assistant/*` (Kunden-, Verkaufs-, Mitarbeiter-, Website-Assistent, Tools, Budget, Scope-Guard, Handover, Resume), `Training/*` (WhatsApp-Export, PiiRedactor) |
| `DocumentIntake/` | `DocumentIntakeService` (Kunde/Vertrag aus Extraktion anlegen/ergaenzen, Antrag->Police, Referenz-Nr.), `ContractRevisionRecorder` |
| `Ocr/` | `PdfTextLayerExtractor`, `TesseractTextExtractor` |
| `Matching/` | `CustomerMatchingService`, `DuplicateDetectionService`, `CustomerMergeService` |
| `CustomerCreation/` | `CustomerAutoCreationService` |
| `Messaging/` | `ConversationEngine`, `ChannelRoutingService`, `AssignmentService`, `CustomerResolver`, `AttachmentFilingService`, `Channels/{Portal,InternalChat,WhatsApp}Adapter`, `Channels/Onboarding/*`, `Inbox/{ConversationInbox,InboxFilters}` |
| `Mailbox/` | Provider IMAP/Gmail-API/Graph-API, `MailboxSyncService`, OAuth, Anhangs-Analyse |
| `Workflow/` | `EmailWorkflowService`, `EmailClassificationService`, `SystemUserResolver` (E-Mail-Eingang - in Betrieb, NICHT die entfernte Workflow-Engine) |
| `CommissionImport/` | Tabellenleser CSV/XLSX/XLS(OLE/BIFF8), `ColumnMap`, `CommissionMatcher`, `CommissionImportService` (analyze/remap/confirm), `CommissionContractBuilder`, `CommissionSourceProfile`, `PersonNameParser`, `ValueParser` |
| `Provisionsmanagement/` | `CommissionStatusEngine`, `MissingCommissionService`, `PoolRegistry`, `ReferenceLinkService`, `CommissionAnalytics` |
| `Commission/` | `CommissionReadService` (Leseschicht ueber 3 Straenge), `CommissionWorkflowService` (Gutschriften), Quellen/Parser |
| `Provision/` | `ContractProvisionService` (Ausgang, Hook am Contract), `ProvisionRateResolver` |
| `Vermittler/` | TARIFCHECK24-Abrechnung + Vorgangsliste |
| `Signature/`, `Pdf/` | Anfrage, Token, Identitaet, Seitenbilder, PDF-Stempeln (incremental update), Firmenbilder, Audit |
| `ChangeRequest/` | Nachweispolitik, Beleg-Pruefung, Mitteilungen an Gesellschaften |
| `Energy/` | Zaehlerfoto lesen, Ablesungen/Verbrauch |
| `Family/`, `Health/` | Familienbeziehungen, Krankenkassen-Wechsel |
| `Social/` | Meta Graph/Publish/Insights/Ads, Format-Generator |
| `Reporting/` | `DashboardAnalyticsService`, `AnalyticsFilters` |
| `Seo/` | `StructuredData`, `GoogleBewertungAbruf` |
| `Media/` | Bildvarianten, SVG-Sanitizer |
| `Activity/` | Aktivitaetserfassung/-bericht |
| `Security/` | `TurnstileVerifier` |
| Einzeldateien | `SystemHealthService`, `CustomerDeletionService`, `ContractSwitchService`, `VehicleOverlapGuard`, `LexofficeService`, `CustomerNumberGenerator`, `ChangeRequestService`, Reminder-Services, `SpamFilter`, `UmlautRepair`, `TwoFactorService`, `TicketNotifier`, `CustomerMessageNotifier` |

## Jobs

| Job | Zweck | tries/timeout |
|---|---|---|
| `AnalyzeDocumentJob` | Dokumentanalyse | siehe Klasse |
| `AnswerCustomerMessageJob` | KI-Antwort | 1 (Retry = doppelte Antwort) |
| `ImportCustomersJob` | CSV-Kundenimport | Warteschlange `lang`, 1800 s |
| `ProcessMediaAssetJob` | Bildvarianten | |
| `PublishSocialChannelJob` | Sofort-Post Meta | 1 (nie doppelt posten) |
| `SendCampaignJob` | Newsletter | |
| `VerifyChangeRequestProofJob` | Nachweis-Pruefung | |
| `Messaging/ProcessWhatsAppWebhookJob`, `FetchInboundMediaJob`, `SendOutboundMessageJob` | WhatsApp ein/aus | |

Regel: `retry_after` (360 s bzw. 2100 s) > laengster `timeout` (`QueueTimeoutTest`).

## Artisan-Befehle (54)

Betrieb/Planer: `mailboxes:sync`, `emails:prune-unmatched`, `tickets:auto-close`,
`signaturen:ablaufen`, `tickets:purge-website-leads`, `media:purge-trash`,
`activity:close-stale`, `activity:prune`, `document-requests:remind`, `tasks:remind`,
`familie:uebergaenge-anwenden`, `tasks:send-auto-emails`, `documents:analyze-pending`,
`ai:answer-pending`, `errors:prune`, `google:bewertungen-holen`,
`provisionen:status-aktualisieren`, `documents:prune-unassigned`,
`health:apply-due-switches`, `contracts:apply-endings`, `portal:send-invitations`,
`escooter:renewal-reminders`, `schutzbrief:renewal-reminders`,
`social:publish-scheduled`, `social:refresh-insights`, `registrierungen:aufraeumen`.

Diagnose (lesend): `ki:pruefen [--live]`, `bimi:pruefen`, `ocr:check`, `queue:health`,
`netz:client-ip-pruefen`, `google:place-id-finden`.

Einrichtung/Admin: `meta:einrichten`, `admin:set-password`, `2fa:zuruecksetzen`,
`partner:create-login`, `portal:birthdate-password`, `bimi:testmail`.

Nachtraege/Reparatur (idempotent): `messaging:unterhaltungen-nachtragen`,
`messaging:kanal-nachtragen`, `documents:backfill-hashes`, `documents:move-private`,
`tickets:attachments-private`, `schutzbrief:backfill-terms`, `emails:decode-subjects`,
`service-pages:fix-umlauts`, `website:fix-storage-urls`,
`customers:merge-alternative-email`, `customers:cleanup-import`.

Import: `energie:import`, `lexoffice:import`. KI: `ki:wissensbasis-vorschlag`,
`ki:leitfaden-entwurf`.

**Destruktiv** (nur mit ausdruecklicher Freigabe): `customers:purge --force`.

## Planer (`routes/console.php`, Zeitzone `APP_SCHEDULE_TIMEZONE` = Europe/Berlin)

| Takt | Aufgaben |
|---|---|
| alle 2 Min | `mailboxes:sync` |
| alle 5 Min | Kampagnen-Versand (`kampagnen-versand`) |
| alle 10 Min | `documents:analyze-pending`, `ai:answer-pending` |
| alle 15 Min | `activity:close-stale`, `social:publish-scheduled` |
| stuendlich | `tasks:send-auto-emails` (8-18), `portal:send-invitations` (8-19) |
| alle 6 h | `social:refresh-insights` |
| taeglich | 03:30 emails:prune-unmatched · 03:40 registrierungen:aufraeumen · 03:45 activity:prune · 03:50 documents:prune-unassigned · 03:55 errors:prune · 04:00 tickets:auto-close · 04:05 signaturen:ablaufen · 04:10 tickets:purge-website-leads + provisionen:status-aktualisieren · 04:15 media:purge-trash · 04:45 google:bewertungen-holen · 05:15 contracts:apply-endings · 05:40 familie:uebergaenge-anwenden · 06:30 health:apply-due-switches · 07:30 kind-wird-15-aufgabe · 07:45 tasks:remind · 08:00 geburtstags-mails · 08:15 document-requests:remind · 08:30 wechsel-erinnerungen · 08:40 escooter · 08:45 schutzbrief · 09:00 portal-erinnerung |

Jeder Lauf wird in `scheduled_task_runs` protokolliert (sichtbar auf `/admin/systemzustand`).
Cron-Eintrag `schedule:run` auf dem Server: **UNKNOWN** (nicht im Repo; die
Systemzustand-Seite zeigt fehlende Laeufe).
