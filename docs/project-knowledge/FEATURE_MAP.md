# Feature Registry

Stand: 23.09.2026 (`main` @ 04c0823). "Zuletzt verifiziert" = volle Testsuite
in dieser Sitzung (siehe [TESTING_STATUS.md](TESTING_STATUS.md)); ein
Browser-/Produktionsnachweis ist gesondert vermerkt.

**Status**: ACTIVE (gebaut, getestet, in Betrieb) · PARTIAL (gebaut, aber ein
Teil fehlt oder wartet auf Betreiber) · BROKEN · DEPRECATED · UNUSED · UNKNOWN
(Betriebszustand nicht aus dem Repo feststellbar).

Abkuerzungen: C = Controller, S = Service, V = Views, T = Tabellen, R = Rechte.
Fachliche Begruendungen stehen in `CLAUDE.md` unter dem genannten Abschnitt.

---

## A. Kern-CRM (Beraterwelt)

### F-001 Kundenakte
- **Beschreibung**: Anlage, Suche, Liste mit Filtern, Akte mit Registerkarten (Vertraege, Dokumente, Timeline, Notizen, Familie, Fahrzeuge, Chat), Betreuer, Werber.
- C `AdminController`, `CustomerFamilyRelationController`; S `CustomerNumberGenerator`, `CustomerDeletionService`; V `admin/customers*`, `customer_show`, `customer_edit`, `customer_create`
- Routen `admin/customers*`, `admin/kunden-suche`, `admin/search`; T `customers`, `customer_*`, `employee_customers`
- R alle Staff (Portfolio); Loeschen nur admin (max. 30/Bulk), Purge nur CLI
- Tests `CustomerSearchTest`, `CustomerListFilterTest`, `CustomerVisibilityTest`, `CustomerBulkDeleteTest`, `EmployeeCustomerManagementTest`, `CustomerEncryptedColumnsTest`
- Status **ACTIVE** · Issues KI-001

### F-002 Dubletten, Zusammenfuehren, Beziehungen
- C `Admin\DuplicateController`; S `DuplicateDetectionService`, `CustomerMergeService`, `CustomerMatchingService`, `Relationships\CustomerRelationshipService`; T `customer_relationships` (Art + `parent_customer_id`, UNIQUE a/b/type)
- V `admin/customer_duplicates`, `admin/customer_relationships` (Filter "Ehepaar (unbestaetigt)"), `admin/partials/beziehung_festlegen`, `admin/partials/linked_customers` (Kundenakte "Verknuepfte Kunden")
- R Merge/Sammel-Merge admin/manager; Beziehungen alle Personalrollen im Portfolio
- Gleichlauf mit F-003 (Familienrollen) in einer Transaktion, seit 01.10.2026
- Klassifikation `DuplicateDetectionService::classify()` (sicher / moeglich / moegliche Familie) seit 03.10.2026 - EINE Quelle fuer Anzeige und alle Merge-Wege (KI-063)
- Merge-Sperren `CustomerMergeService::mergeBlockers()` (Widerspruch, zwei aktive Portalzugaenge) - `merge()` verweigert sich ohne Begruendung; Sammel-Merge nur `sicher` (KI-065/068)
- Zusammenfuehren ARCHIVIERT das Duplikat (`merged_into_id`/`archived_at`, Alias der alten Kundennummer, Weiterleitung alter Links) und schreibt ein Protokoll `customer_merges` (KI-064, PR-3a)
- Rueckgaengig innerhalb von 30 Tagen (`CustomerMergeUndoService`, Route `admin.customer.merge.undo`, nur admin) und Abschluss nach Fristablauf (`kunden:zusammenfuehrungen-abschliessen`, 05:45) (KI-064, PR-3b)
- Merge-Vorschau mit Feldwahl: abweichende Stammdaten je Gruppe waehlbar, Anschrift nur als Ganzes (`CustomerMergeService::abweichendeFelder`) (KI-094, PR-3c)
- Tests `DublettenFamilieTest`, `MergeSperrenTest`, `MergeArchivTest`, `MergeRueckgaengigTest`, `MergeFeldwahlTest`, `CustomerMergeDataPreservationTest`, `DuplicateBulkMergeTest`, `DuplicateDetection*Test`, `CustomerMergeServiceTest`, `CustomerRelationshipTest`, `KundenbeziehungenTest`
- Status **ACTIVE**

### F-003 Familie und Kundenbeziehungen (inkl. Kinder unter 16, Uebergang mit 16)
- S `Family/FamilyRelationService`, `Family/AbhaengigesKindService`; Support `FamilienAlter` (Einstellung 15/16); C `CustomerFamilyRelationController` (inkl. `kundennummerVergeben`); V `admin/partials/family_relations`, `admin/family_transitions`, Karte "Familie / Kinder" in `admin/settings`; T `customer_family_relations`, `customer_family`, `archivierte_kundennummern`, `customers.portal_vorbereitung_erinnert_at`; Planer `familie:portal-vorbereitung-erinnern` (05:35), `familie:uebergaenge-anwenden` (05:40); Befehle `kunden:kinder-pruefen` (lesend), `kunden:kind-umstellen` (Probelauf)
- Kinder unter 16: keine Kundennummer, kein Vertrag, kein Portal (KI-095, 07.10.2026)
- Tests `CustomerFamilyRelationTest`, `AdminFamilyDisplayTest`, `AbhaengigeKinderTest`
- Status **ACTIVE**

### F-004 Vertraege (alle Sparten) + Status-Logik
- C `Admin\ContractController`; M `Contract` (`TYPES`, `STATUS_OPTIONS`, `isCurrentlyActive`, `statusGroup`, `displayStatus`, `scopeSearch`); T `contracts`, `contract_*_details`, `contract_revisions`
- S `ContractSwitchService`, `VehicleOverlapGuard`, `ContractRevisionRecorder`; Planer `contracts:apply-endings`
- Tests `ContractStatusLogicTest`, `ContractDisplayStatusTest`, `VehicleOverlapGuardTest`, `ContractEndingsCommandTest`, `ContractManagementTest`, `KfzContractRedesignTest`, `GewerblicheSpartenTest`, `LargeListPerformanceTest`
- Status **ACTIVE**

### F-005 Aufgaben & Wiedervorlagen (inkl. Auto-E-Mail)
- C `TaskController`; T `tasks`; Planer `tasks:remind`, `tasks:send-auto-emails`
- Tests `TaskSystemTest` · Status **ACTIVE**

### F-006 Termine, Ankuendigungen
- C `AppointmentController`, AdminController (announcements); T `appointments`, `announcements`
- Tests `TermineAnkuendigungenTarifrechnerTest` (seit 23.09.2026; dabei KI-019/KI-020 behoben: Ankuendigung loeschen nur Ersteller/Leitung, `assigned_to` validiert) · Status **ACTIVE**

### F-007 Aenderungsantraege mit Nachweis + Mitteilungen an Gesellschaften
- C `SelfServiceController` (Portal), `ChangeRequestReviewController`, `ChangeNotificationController`; S `ChangeRequestService`, `ChangeRequest/*`; Job `VerifyChangeRequestProofJob`; T `customer_change_requests`, `change_request_documents`, `change_notifications`
- Tests `ChangeRequestVerificationTest`, `SelfServiceTest`, `UnifiedApprovalSystemTest` · Status **ACTIVE**

### F-008 Mitarbeiterverwaltung, Rechte, Vertretungen, Team
- C `EmployeeController`; T `users`, `substitutions`; V `admin/employee*`, `team_verwaltung`
- Tests `RechteEskalationTest`, `CustomerBetreuerAssignmentTest`, `PartnerAssignmentRestrictionTest`, `EinladungsmailRechteTest`
- Status **ACTIVE** · Issues KI-001 (offen); KI-006 behoben 23.09.2026

### F-009 Aktivitaetserfassung und -bericht
- Middleware `TrackStaffActivity`; S `Activity/*`; C `ActivityReportController`; T `work_sessions`, `activity_logs`; Planer `activity:close-stale`, `activity:prune`
- Tests `ActivityTrackingTest`, `ActivityReportTest` · Status **ACTIVE**

### F-010 Berichte & Auswertungs-Dashboard, Neukunden-Bericht
- C `ReportController`; S `Reporting/*`; V `admin/reports*`, `partials/analytics/*`; `App\Support\Bundesland`
- Tests `ReportsDashboardTest`, `NewCustomerReportTest`, `LeistungsmessungTest` · Status **ACTIVE**

## B. Dokumente

### F-011 Vertragsherkunft (Eigen-/Fremdvertrag/uebernommen)
- **Beschreibung**: jeder Vertrag sagt, ob WIR ihn vermittelt haben (`brokered`), ob er nur zur Dokumentation erfasst ist (`external`, kein Mandat) oder per Maklervollmacht uebernommen wurde (`transferred`). Pflichtwahl ganz oben im Formular, Vorvertrag in einem Zug als Fremdvertrag anlegen, Kette Vorgaenger/Nachfolger, Kennzahlen nur Eigenbestand, Filter Eigen-/Fremdbestand, Uebernahmepotenzial, Warnleiste + Pflicht-Grund bei Kuendigung/Schaden/Aenderungsantrag an Fremdvertraegen, Pruefliste "ungepruefte Herkunft", Portal-Einstellung getrennt/ausblenden, Herkunft im KI-Kontext.
- C `Admin\ContractController` (`validateOrigin`, `syncOriginLinks`, `guardExternalAction`, `contractsFremdbestand`, `originReview*`), `ChangeRequestReviewController`, `DocumentRequestController`; M `Contract` (`ORIGIN_*`, `scopeOwnPortfolio`, `predecessor`/`successor`, `assistantOriginHint`); V `partials/contract_origin_fields`, `partials/contract_origin_badge`, `partials/fremdvertrag_warnung`, `contracts_fremdbestand`, `contracts_origin_review`
- Routen `admin/vertragsherkunft/fremdbestand`, `admin/vertragsherkunft/pruefen` (GET/POST); T `contracts` (+ `origin`, `origin_verified`, `previous_broker`, `origin_note`, `transfer_date`, `cancellation_submitted_by_us`, `replaces_contract_id`); Einstellung `portal_fremdvertraege`
- R Herkunft aendern nur admin/manager (mit Bestaetigung, protokolliert); Pruefliste bestaetigen alle Staff (Portfolio)
- Tests `VertragsherkunftTest` · Status **ACTIVE** (Stand 28.09.2026, lokal ungetestet - siehe CHANGELOG)

### F-012 KFZ: SF-Sondereinstufung mit Bezugsfahrzeug (Erstwagen)
- **Beschreibung**: Zweitwagen-/Drittwagen-/Familien-Einstufungen tragen ihren Bezugsvertrag je Sparte (HP/VK) - im Bestand (Kunde + verknuepfte Familie, Sofort-Suche) oder extern (optional als Fremdvertrag angelegt). Halter, Snapshot der Erstwagen-SF bei Gewaehrung, Nachweis + Pruefvermerk (admin/manager); uebrige Gruende nur ihre Angaben (Fuehrerscheindatum, Aktion, Freitext). Vorschlag der tatsaechlichen SF aus dem Fuehrerscheindatum. Anzeige in beide Richtungen ("Zweitwagen zu ADAC ... (SF 5)" / "🔗 Erstwagen fuer ..."), SF-Verlauf mit Grund/Bezug, Warnungen, Benachrichtigung bei Rueckstufung/Kuendigung/Loeschung des Erstwagens. Vorversicherung: "Keine Vorversicherung" + Hinweis bei Zweitwagen-Text.
- C `Admin\ContractController` (`sfReferenceSearch`, `precheckSfReferences`, `syncVehicleDetail`, `syncSfHistory`); S `Kfz\SfReferenceService`, `Kfz\SfReferenceValidator`, `Kfz\SfReferenceNotifier`; M `VehicleSfReference`, `ContractVehicleDetail` (`sfRank`, `isDowngrade`, `suggestRealClass`, `sfReasonSummary`), `Contract::sfDependents`; V `partials/contract_kfz_sf_reference`, `partials/contract_sf_erstwagen_badge`; Befehl `kfz:zweitwagen-pruefen`
- Route `admin/sf-bezug/{customerId}/suche`; T `vehicle_sf_references`, `vehicle_sf_history` (+ `special_reason`, `reference_label`, `reference_contract_id`), `contract_vehicle_details.no_previous_insurance`; Einstellung `sf_reference_required_on_submit`; Regeln [KFZ_RULES.md](KFZ_RULES.md)
- Tests `SfBezugsfahrzeugTest`, `KfzSfRegelnTest` · Status **ACTIVE** (01.10.2026) · Issues KI-046, KI-047

### F-020 Smart Document Upload / Dokumenten-Eingang
- C `SmartDocumentUploadController` (Admin + Portal); S `Ai/DocumentAnalyzer`, `HeuristicDocumentClassifier`, `Ocr/*`, `DocumentIntake/DocumentIntakeService`; Job `AnalyzeDocumentJob`; Planer `documents:analyze-pending`, `documents:prune-unassigned`
- V `admin/documents_inbox` (1636 Z.), `portal/documents`; T `documents`, `ai_decisions`
- Tests `SmartDocumentUploadTest`, `DocumentIntake/*`, `DuplicateDetectionTest`, `DocumentCostOptimizationTest`, `HausnummerTrennungTest`, `OcrCheckCommandTest`
- Status **ACTIVE**

### F-021 Vorlagen-Parser (~44)
- `app/Services/Ai/TemplateParsers/*`, Reihenfolge in `AppServiceProvider` (spezialisiert vor generisch)
- Tests je Parser unter `tests/Feature/Ai/*ParserTest`, `ParserPolicyTest`
- Ausweiskarten: `PersonalausweisParser` + `AufenthaltstitelParser` teilen den Baustein `Concerns/LiestDeutscheAusweiskarte` (MRZ + Anschrift der Rueckseite); Parser-Ergebnis darf `pflichtangaben` tragen -> `DocumentAnalyzer` eskaliert zur KI, wenn sie fehlen. Tests `AusweiskartenRueckseiteTest`, `AufenthaltstitelParserTest`
- Briefkopf spaltenweise lesen: gemeinsamer Baustein `Concerns/LiestSpalten` (`GehaltsabrechnungParser`, `MitgliedsbescheinigungParser`, `FamilienversicherungParser`) - "auf Spaltenabstaende ist kein Verlass"
- Krankenkassen-Bestaetigung (`MitgliedsbescheinigungParser`): Empfaenger ist der ARBEITGEBER, der Kunde steht im Fliesstext - die UMKEHRUNG der sonstigen Regel. Test `MitgliedsbescheinigungParserTest`
- Familienversicherung (`FamilienversicherungParser`): ZWEI Bauformen in EINEM Parser (aelterer Fragebogen + KKH-Vordruck 0765 mit dreispaltiger Angehoerigen-Tabelle). Die Rolle kommt aus der Spalten-Kopfzeile, nie aus dem Nachnamen; Verknuepfung ueber `DocumentIntakeService::linkFamilienversicherungAngehoerige()`. Tests `FamilienversicherungFaveParserTest`, `FamilienversicherungAnlageTest`
- Erkennung braucht mehr als EIN Stichwort: `GeburtsurkundeParser` verlangt zusaetzlich einen Standesamts-Beleg - das Wort "Geburtsurkunde" steht im Kleingedruckten vieler Formulare (KI-054)
- Status **ACTIVE** · Regel `docs/ARCHITEKTUR_PARSER_STRATEGIE.md`

### F-022 Dokumenten-Anforderungen
- C `DocumentRequestController`; T `document_requests`; Planer `document-requests:remind`
- Tests `DocumentRequestTest`, `DocumentRequestMailLocalizationTest` · Status **ACTIVE**

### F-023 Zaehlerstand + Verbrauchshistorie
- S `Energy/*`; T `meter_readings`; Tests `MeterReadingTest` · Status **ACTIVE**

### F-024 E-Signatur
- C `Admin\SignatureController`, `SignatureSigningController`, `Admin\CompanySignatureAssetController`; S `Signature/*`, `Pdf/*`; V `admin/signatures/*`, `signature/*`; T `signature_*`, `company_signature_assets`; Planer `signaturen:ablaufen`, `signaturen:qualitaet-pruefen` (04:25, Qualitaetsgate + Zusammenfassung an Admins); Diagnose `signaturen:diagnose` (nur lesend), Reparatur `signaturen:neu-erzeugen`; Qualitaetsliste `Admin\SignatureQualityController` (`/admin/signaturen/qualitaet`, nur admin); Eingangspruefung `Pdf\PdfEingangspruefung` (qpdf)
- Interne Unterschrift (Teil B, 07.10.2026): `Admin\InternalSignatureController` (`/admin/meine-unterschrift`, `/admin/signaturen/{id}/intern-unterschreiben`), `SignatureHandoffController` (`/unterschrift-handy/{token}`), S `InternalSigningService`, `UserSignatureService`, `InterneFreigabe`, `SignatureHandoffService`; T `user_signatures`, `signature_handoffs`, `signature_internal_signings`; Recht `users.can_sign_for_company` (Admin vergibt)
- R Policy `SignatureRequestPolicy` (+ `signInternal`, `resetInternal`), Gates `firmensignatur-*`
- Tests `SignaturQualitaetsmatrixTest`, `SignaturQualitaetsgateTest`, `ProtokollUmbruchTest`, `SignatureModuleTest`, `SignatureSecurityTest`, `SignaturGruppeTest`, `SignaturWorkflowTest`, `SignaturBenachrichtigungTest`, `UnternehmenssignaturTest`, `FaultInjectionSignatureTest`, `SignerIdentityTest`, `SignatureLocalizationTest`, `SignatureCreateFlowTest`, `CompanySignatureAssetTest`, `PdfStamperTest`, `BildverarbeitungFehltTest`, `SignaturDiagnoseTest`, `SignaturPdfSichtbarkeitTest`, `BildfreistellungTest`, `InterneUnterschriftTest`
- Status **ACTIVE** · rechtliche Einordnung je Geschaeftsfall offen (KI-009)

## C. Kommunikation

### F-030 Tickets / Vorgaenge
- C `TicketController`, `PortalController` (Kundensicht); S `TicketNotifier`; T `tickets`, `ticket_*`; Planer `tickets:auto-close`, `tickets:purge-website-leads`
- Tests `TicketSystemTest`, `TicketHardeningTest`, `TicketStatsTest`, `TicketTrashAndBulkTest`, `ConversationTicketTest` · Status **ACTIVE**

### F-031 Omnichannel-Postfach (Unterhaltungen, Zuweisung, Notizen, Mehrkanal)
- C `Admin\PostfachController`, `AdminCustomerChatController`, `PortalMessageController`; S `Messaging/*`; T `conversations`, `conversation_*`, `customer_messages`, `customer_channel_identities`
- Tests `tests/Feature/Messaging/*` (19 Dateien), `ChatFeedTest`, `PortalChatUiTest`, `CustomerMessagingTest`
- Status **ACTIVE** · Schalter `messaging_kanaluebergreifend` Default AUS · Phase 8 (Haertung) offen

### F-032 WhatsApp (Cloud API, Coexistence, Onboarding)
- C `Webhooks\WhatsAppWebhookController`, `Admin\ChannelController`, `Admin\WhatsAppOnboardingController`; S `Messaging/Channels/WhatsAppAdapter`, `Onboarding/*`; Jobs `Messaging/*`; T `channels`, `channel_accounts`, `channel_events`
- Tests `WhatsApp*Test`, `CoexistenceEchoTest`, `ChannelAdminTest`
- Status **PARTIAL/UNKNOWN** - Code fertig; ob ein Konto produktiv verbunden ist: UNKNOWN

### F-033 E-Mail-Postfaecher + Eingang + Composer
- C `EmailAccountController`, `EmailInboxController`, `ComposeEmailController`; S `Mailbox/*`, `Workflow/*`; Planer `mailboxes:sync`, `emails:prune-unmatched`; T `email_accounts`, `email_messages`, `email_logs`
- Tests `MailboxSyncServiceTest`, `OAuthMailboxTest`, `EmailInboxTest`, `EmailAccountManagementTest`, `EmailWorkflowServiceTest`, `SmartComposeTest`, `CustomerEmailImportTest`
- Status **ACTIVE**

### F-034 Newsletter / Kampagnen, Vorlagen, Abmeldung
- C `EmailMarketingController`, `MessageTemplateController`, `UnsubscribeController`; Job `SendCampaignJob`; T `email_campaigns`, `message_templates`
- Tests `EmailMarketingImprovementsTest`, `MessageTemplateTest` · Status **ACTIVE**

### F-035 Team-Chat, interne Nachrichten, Glocke
- C `InternalChatController`, `InternalMessageController`, `InternalNotificationController`; S `NotificationService`; T `internal_*`
- Tests `InternalChatTest`, `InternalChatConversationTest`, `NotificationCenterTest`, `NotificationServiceTest`, `NavBadgeScopeTest` · Status **ACTIVE**

## D. KI

### F-040 KI-Kundenassistent + Verkaufsassistent (Portal-Chat)
- S `Ai/Assistant/*` (Scope-Guard, Tools-Whitelist, Handover, Resume, Sales); C `AiAssistantController`; Job `AnswerCustomerMessageJob`; Planer `ai:answer-pending`; T `ai_conversations`, `ai_assistant_logs`, `ai_conversation_events`, `ai_offers`
- Tests `CustomerAssistantTest`, `SalesAssistantTest`, `AssistantResumeTest`, `AssistantDiagnosisCommandTest`, `AiChannelIntegrationTest`
- Status **PARTIAL** - fertig, Hauptschalter Default AUS; Livegang wartet auf Wissensbasis + DPA-Pruefung (KI-008)

### F-041 Website-KI-Assistent + Interessenten
- C `WebsiteAssistantController`; S `Ai/Assistant/Website/*`, `AssistantBudget`; T `ai_leads`; V `website/partials/assistant`
- Tests `AssistantBudgetTest` u.a. · Status **ACTIVE** (Betriebsschalter: UNKNOWN)

### F-042 Wissensbasis, Wissensluecken, Entwuerfe
- T `ai_knowledge_entries`, `ai_knowledge_gaps`; Befehle `ki:wissensbasis-vorschlag`, `ki:leitfaden-entwurf`
- Tests `KnowledgeBaseDraftTest`, `KnowledgeGapTest` · Status **ACTIVE**

### F-043 KI-Training aus Verlaeufen
- C `Admin\KiTrainingController`; S `Ai/Training/*` (`WhatsAppExportParser`, `PiiRedactor`); T `training_imports`, `training_import_messages`, `ai_training_examples`
- Tests `KiTrainingImportTest`, `WhatsAppHistoryImportTest` · Status **PARTIAL** - Nutzung mit echten Daten erst nach DSGVO-Klaerung (KI-008)

### F-044 KI-Anbieter-Verwaltung
- C `Admin\AiProviderController`; T `ai_provider_accounts` · Tests `AiProviderAdminTest`, `AiProviderTest` · Status **ACTIVE**

## E. Provisionen und Abrechnung

### F-050 Provisionsmanagement (Pools, Importe, Status-Engine, fehlende Provisionen)
- C `ProvisionsmanagementController`, `ContractCommissionController`; S `CommissionImport/*`, `Provisionsmanagement/*`; T `contract_commissions`, `commission_*`; Planer `provisionen:status-aktualisieren`
- R `can:provisionen-verwalten`
- Tests `ProvisionsmanagementTest`, `ContractCommissionImportTest`, `CommissionReadServiceTest` · Status **ACTIVE**

### F-051 Vermittler-Abrechnung TARIFCHECK24 + Vorgangsliste + Rechnungsabgleich
- C `VermittlerAbrechnungController`; S `Vermittler/*` (neu 23.09.2026: `VermittlerRechnungAbgleich`); T `vermittler_*` (neu: `vermittler_invoices`) · R Recht `provisionen-verwalten`
- Status-Codes 1 offen / 2 storniert / 3 verifiziert / 4 bezahlt; "bezahlt" BELEGT erst die hochgeladene Rechnung (PDF/Bild/Text)
- Tests `VermittlerAbrechnungTest`, `VermittlerVorgangslisteTest`, `VermittlerRechnungTest` · Status **ACTIVE**

### F-052 Ausgangs-Provisionen an Mitarbeiter/Partner
- C `ProvisionController`; S `Provision/*`; T `provisions`, `provision_rates`, `provision_audit_logs` · R admin/manager
- Tests `ProvisionManagementTest` · Status **ACTIVE**

### F-053 Gutschriften (Commissions, alt)
- C `CommissionController`; S `Commission/CommissionWorkflowService`; T `commissions`
- Tests `CommissionWorkflowTest` · Status **ACTIVE**

### F-054 Lexoffice
- C `LexofficeController`; S `LexofficeService`, `Lexoffice/*`; Befehl `lexoffice:import`
- Tests `LexofficeImportTest`, `LexofficeServiceFallbackTest` · Status **ACTIVE** (Schluessel in Produktion: UNKNOWN)

### F-055 Fonds-Finanz-Import, Energie-Import, CSV-Kundenimport/-Export
- S `FondsFinanz/*`, `Import/CustomerCsvImporter`; C `ImportExportController`; Job `ImportCustomersJob` (Queue `lang`); Befehl `energie:import`
- Tests `FondsFinanzImportTest`, `EnergyContractImportTest`, `SmartNumberingAndImportTest`, `ImportNotificationTest` · Status **ACTIVE**

## F. Kundenportal und Partnerportal

### F-060 Kundenportal
- C `PortalController` (1008 Z.), `PortalMessageController`, `SelfServiceController`; V `portal/*`, `layouts/portal`
- Tests `PortalUxTest`, `PortalReviewTest`, `PortalAuthArabicTest`, `PortalChatUiTest`, `ResponsiveLayoutTest`, `MobileInteractionTest`, `ArabischeUebersetzungVollstaendigTest`, `PortalOhneInterneKennungenTest`
- Status **ACTIVE** · KI-007/KI-010 behoben 23.09.2026

### F-061 Portal-Zugang, Einladungen, Willkommens-Mail
- S `Portal/PortalAccessService`; C `PortalAccessController`; Mail `CustomerWelcomeMail`; Planer `portal:send-invitations`, Portal-Erinnerung 09:00
- Tests `PortalAccountManagementTest`, `PortalInviteBatchTest`, `WelcomeEmailRedesignTest` · Status **ACTIVE**

### F-062 Partnerportal (lesend)
- C `PartnerPortalController`, `PartnerController`; V `partner/*`; Befehl `partner:create-login`
- Tests `PartnerPortalTest` · Status **PARTIAL** - lesend produktiv; Vollausbau wartet auf Betreiber

## G. Authentifizierung und Sicherheit

### F-070 Login, Registrierung (zweistufig), Passwort-Wege, Magic-Login
- `app/Http/Controllers/Auth/*`, `routes/auth.php`, `PasswordPolicy`
- Tests `Auth/*` (inkl. `BreezeResteEntferntTest`), `PasswordSecurityTest`, `PasswordFlowHardeningTest`, `Security/RegistrationHardeningTest`, `DatenschutzTurnstileTest` · Status **ACTIVE** (Turnstile-Schluessel: UNKNOWN, KI-002)

### F-071 Zwei-Faktor-Anmeldung
- `TwoFactorController`, `EnsureTwoFactor`, `Totp`, `QrCode`; Befehl `2fa:zuruecksetzen`
- Tests `TwoFactorTest`, `TotpTest`, `QrCodeTest` · Status **ACTIVE**

### F-072 Sicherheitsheader/CSP, Proxy-Kette, Einstellungs-Validierung, Dateizugriff
- `SecurityHeaders`, `CspNonce`, `TrustedProxies`, `UpdateSettingsRequest`, private Disk
- Tests `tests/Feature/Security/*` · Status **ACTIVE** · Netzseite offen (KI-003)

## H. Website, Marketing, SEO

### F-080 Marketing-Website (DE/AR), Rechtsseiten, Kontakt, Hamburg-Seite
- C `WebsiteController`, `LegalPageController`, `ServicePageController`, `SeoController`; Middleware `RedirectWebsiteHost`; V `website/*`, `services/*`
- Tests `WebsiteMergeTest`, `LegalPagesTest`, `SeoSichtbarkeitTest`, `StructuredDataTest`, `EinwilligungUndHamburgTest`, `ServicePageTest`, `ServiceInquiryFlowTest`
- Status **ACTIVE (Code)** · DNS-Go-Live auf den VPS: UNKNOWN (BIMI-Messung 22.09. deutet auf getrennte Hosts) · Rechtstexte mit TODO (KI-012)

### F-081 Cookie-Einwilligung + Matomo
- `App\Support\Consent`, `Matomo`, `partials/cookie_consent`, `partials/matomo`
- Tests `MatomoMessungTest`, `EinwilligungUndHamburgTest` · Status **ACTIVE (inaktiv bis Matomo konfiguriert)**

### F-082 Google-Vertrauenskarte (Bewertungen)
- `GoogleBewertungAbruf`, `GoogleBewertung`, Befehle `google:*`
- Tests `GoogleBewertungTest` · Status **PARTIAL** (Profil/Schluessel: UNKNOWN)

### F-083 Leistungsseiten-Admin + Medienverwaltung + Markenbilder
- C `ServicePageAdminController`, `MediaLibraryController`; S `Media/*`; Job `ProcessMediaAssetJob`; T `service_pages`, `media_assets`
- Tests `MediaLibraryTest`, `BrandAssetsTest` · Status **ACTIVE**

### F-084 Banner + Social-Publishing + Werbeanzeigen (Meta)
- C `BannerController`, `BannerSocialController`, `SocialLinkController`, `MetaAdsController`; S `Social/*`; Job `PublishSocialChannelJob`; Planer `social:*`
- Tests `BannerManagementTest`, `BannerSocialPublishingTest`, `MetaSocialPublishingTest`, `MetaAdsManagementTest`, `MetaSetupCommandTest` · Status **ACTIVE** (Meta-Zugang: UNKNOWN)

### F-085 Website-Anfragen / Spam-Schutz (statische Uebergangsseite)
- C `WebsiteContactController`, `WebsiteInquiryController`, `SupportFormController`; S `SpamFilter`
- Tests `WebsiteContactFormTest`, `WebsiteInquiryTest`, `SupportFormTest`, `SpamFilterTest` · Status **ACTIVE** (Rueckbau nach DNS-Umzug geplant)

### F-086 BIMI (Markenlogo in Gmail)
- `App\Support\BimiLogo`, Befehle `bimi:pruefen`, `bimi:testmail`, `public/dienstly-bimi-logo.svg`
- Tests `BimiLogoTest` · Status **PARTIAL** - Zertifikat (CMC/VMC) + Auslieferungsort offen (KI-005)

### F-087 Vergleichsportale (Tarifrechner-Links)
- C `TarifrechnerController`; T `tarifrechner_links` · Tests `TermineAnkuendigungenTarifrechnerTest` · Status **ACTIVE**

## I. Betrieb

### F-090 Systemzustand, Fehlerliste, externe Ampel
- S `SystemHealthService`, `ErrorRecorder`; C `SystemHealthController`, `ErrorEventController`; T `scheduled_task_runs`, `error_events`
- Tests `SystemHealthTest`, `ErrorVisibilityTest`, `HealthEndpointTest`, `BackupHealthTest`, `QueueTimeoutTest` · Status **ACTIVE**

### F-091 Einstellungen
- C `SettingsController`, `UpdateSettingsRequest`; T `system_settings` · R admin
- Tests `Security/SettingsValidationTest` · Status **ACTIVE**

### F-092 Backup/Restore
- `scripts/backup.sh`, `scripts/restore.sh` · Status **PARTIAL** - bewiesen 16.09.2026, Server-Inbetriebnahme UNKNOWN (KI-004)

### F-093 Kranken-/Gesundheits-Wechsel (Familien-Buendel)
- S `Health/*`; Planer `health:apply-due-switches`; Tests `tests/Feature/Health/*`, `tests/Unit/Health` · Status **ACTIVE**

### F-094 Erinnerungen (E-Scooter, Schutzbrief, Wechsel, Geburtstag)
- S `*ReminderService`; Befehle `escooter:*`, `schutzbrief:*`
- Tests `EscooterRenewalReminderTest`, `SchutzbriefRenewalReminderTest`, `BatchResilienceTest` · Status **ACTIVE**

## J. Entfernt / tot

### F-099 AI-Workflow-Engine
- entfernt 20.08.2026 (5 Tabellen gedroppt, Code weg) · Status **DEPRECATED (entfernt)**
- Breeze-Reste (`verify-email`, `confirm-password`, `layouts/guest`, 10 Komponenten) am 23.09.2026 entfernt (KI-016) · Status **DEPRECATED (entfernt)**
