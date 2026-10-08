# Database Map

Stand: 23.09.2026. 162 Migrationen, ~110 aktive Tabellen. Produktion MySQL 8,
Tests SQLite (in Produktion durch `ProductionDatabaseGuard` gesperrt); die CI
prueft BEIDE (`test` + `test-mysql`).

Regeln fuer jede Schemaaenderung: siehe Abschnitt "Aenderungsregeln" unten.

## Tabellen nach Fachbereich

### Identitaet & Zugang
| Tabelle | Zweck / Hinweise |
|---|---|
| `users` | alle Konten; `role`, Einzelrechte `can_*`, 2FA-Felder, `must_change_password`, `portal_password_set_at`, `zugangslink_version` (Widerrufsstand aller Zugangslinks, KI-043), Provisionssaetze. **`can_see_all_customers` Default `true`** (-> KI-001) |
| `password_reset_tokens`, `sessions` | Laravel-Standard (Session-Treiber `database`) |
| `pending_registrations` | zweistufige Registrierung (SEC-1), Token als sha256 |
| `substitutions` | Vertretungen (erweitern die Sichtbarkeit) |
| `work_sessions`, `activity_logs` | Aktivitaetserfassung/Protokoll |
| `employee_customers` | Portfolio N:M (User <-> Customer), `is_primary` = Betreuer |
| `favorite_customers` | Stern im Composer |
| `partners` | Partnerfirmen (user_id -> Partnerkonto) |

### Kunde
| Tabelle | Zweck |
|---|---|
| `customers` | Kundenakte (Primaerschluessel UUID), Kundennummer, Stammdaten, `user_id`, `partner_id` (Portal-Zugriff!), `acquired_by`/`acquired_by_partner_id` (Werber), `created_by`, `commission_import_id`, verschluesselte Spalten (Test `CustomerEncryptedColumns`); `merged_into_id`/`archived_at` = archivierte Huelle einer zusammengefuehrten Akte (globaler Scope blendet sie aus, `Customer::mitArchiv()`), ihre Kundennummer bleibt als Alias belegt |
| `customer_merges` | EIN Datensatz je Zusammenfuehrung: Haupt-/Duplikat-Akte, Bearbeiter, alte Nummer, Begruendung, `protokoll` (verschluesselt: umgehaengte Zeilen je Tabelle, verworfene Kollisionszeilen vollstaendig, ergaenzte Felder, Konto) - Grundlage fuers Rueckgaengigmachen |
| `geteilte_kontaktdaten` | Als gemeinsam genutzt markierte Kontaktwerte (Art email/telefon/iban/anschrift, `wert_hash` = HMAC mit APP_KEY, maskierte `anzeige`, Notiz, Ersteller) - bilden kein Dubletten-Signal (PR-4) |
| `customer_addresses`, `customer_contacts`, `customer_vehicles`, `customer_notes`, `customer_timeline`, `customer_views` | Unterlagen der Akte |
| `customer_family` | Familienmitglieder OHNE eigene Akte |
| `customer_family_relations` | gerichtete Beziehung zwischen zwei AKTEN (Paar hin/rueck), `is_dependent` |
| `customer_relationships` | Beziehung zweier Akten (kein Duplikat): Art (`ehepartner`, `lebenspartnerschaft`, `lebensgefaehrten`, `elternteil_kind`, `grosseltern_enkel`, `geschwister`, `sonstige_verwandte`, `gleicher_haushalt`, `nachbar`, `sonstiges`, `not_duplicate`), Paar sortiert a<b, UNIQUE (a, b, type); `parent_customer_id` nur bei den gerichteten Arten `elternteil_kind`/`grosseltern_enkel` = die aeltere Generation (= a oder b, Modell-Guard statt CHECK). Familienarten laufen gleich mit `customer_family_relations` |
| `customer_consents` | DSGVO-Einwilligungen |
| `customer_change_requests`, `change_request_documents`, `change_notifications` | Self-Service-Aenderungen, Nachweise, Mitteilungen an Gesellschaften |
| `customer_channel_identities` | Kanal-Kennung -> Kunde, `match_method`, `verified_*` |
| `external_references` | polymorph (Fonds-Finanz-Nummern u.a.) |

### Vertrag
| Tabelle | Zweck |
|---|---|
| `contracts` | Kern: `type` (Sparte, String; Liste `Contract::TYPES`), `status`, `stage` (antrag/vertrag), `contract_number`, `reference_number`, `internal_contract_number`, `vermittler_id`, `vermittler_*`, `pool`, `commission_status`; Herkunft (28.09.2026): `origin` (brokered/external/transferred), `origin_verified`, `previous_broker`, `origin_note`, `transfer_date`, `cancellation_submitted_by_us`, `replaces_contract_id` (Selbstbezug: Nachfolger -> Vorgaenger, "ersetzt durch" wird abgeleitet) |
| `contract_vehicle_details`, `contract_energy_details`, `contract_internet_details` | Sparten-Details (1:1) |
| `contract_revisions` | Version History feldgenau |
| `contract_histories`, `contract_switch_reminders` | Historie, Wechsel-Erinnerungen |
| `vehicle_mileage_readings`, `vehicle_sf_history`, `vehicle_claims` | Kfz |
| `vehicle_sf_references` | Kfz: Begruendung einer SF-Sondereinstufung je Sparte (Bezugsfahrzeug intern -> `contracts` nullOnDelete, oder extern; Halter, Snapshot, Nachweis -> `documents`). Einziger Schreibweg `SfReferenceService` |
| `meter_readings` | Zaehlerstaende (Energie) |
| `tarifrechner_links` | Vergleichsportal-Links |

### Dokumente & Kommunikation
| Tabelle | Zweck |
|---|---|
| `documents` | Dateien + Analyse-Ergebnis (`ai_extracted_data`, Content-Hash, `vermittler_import_id`) - Rohtext wird NIE gespeichert |
| `document_requests` | Unterlagen-Anforderungen |
| `tickets`, `ticket_messages`, `ticket_attachments`, `ticket_events` | Vorgaenge (inkl. Website-Leads, Einwilligungsnachweis); `tickets.absender_status` (`ungeprueft`/`bestaetigt`/NULL) + `absender_geprueft_von/_am`: Formular-Anfrage nur per E-Mail zugeordnet -> fuer den Kunden unsichtbar bis zur Bestaetigung (KI-033) |
| `conversations`, `conversation_channels`, `conversation_assignments`, `conversation_notes` | Unterhaltungen (Omnichannel) |
| `customer_messages`, `customer_message_attachments` | EINE Nachrichtentabelle fuer alle Kanaele (`customer_id` nullable, `channel_id`, `direction`/`from_staff`, `source`) |
| `channels`, `channel_accounts` (Zugangsdaten verschluesselt), `channel_events` (Idempotenz, `dedupe_key`) | Kanaele |
| `email_accounts`, `email_messages`, `email_logs`, `email_campaigns`, `message_templates` | Postfaecher, Versand, Newsletter, Vorlagen |
| `internal_messages`, `internal_conversations(_participants/_messages)`, `internal_notifications` | Team-Chat, Glocke |
| `announcements`, `appointments`, `tasks` | Ankuendigungen, Termine, Aufgaben (`tasks.type` String) |

### KI
`ai_conversations` (Zustand/Uebergabe), `ai_conversation_events`, `ai_assistant_logs`
(KEIN Nachrichtentext), `ai_decisions`, `ai_knowledge_entries` (`source_key`),
`ai_knowledge_gaps`, `ai_leads`, `ai_offers`, `ai_provider_accounts`,
`ai_training_examples` (nur geschwaerzt), `training_imports`, `training_import_messages`.

### Provisionen (drei getrennte Straenge - nie vermischen)
| Strang | Tabellen |
|---|---|
| AUSGANG an eigene Vermittler | `provisions`, `provision_rates`, `provision_audit_logs` |
| EINGANG Gutschriften (alt) | `commissions` |
| EINGANG Vermittler TARIFCHECK24 | `vermittler_imports`, `vermittler_settlements`, `vermittler_match_events` |
| EINGANG beliebige Quellen / Provisionsmanagement | `contract_commissions`, `commission_imports`, `commission_import_rows`, `commission_audit_logs` (kein Loeschweg), `commission_pools`, `commission_followups`, `commission_reference_links` |

### E-Signatur
`signature_requests` (customer_id/contract_id NULLBAR; seit 04.10.2026
`feld_bezug` mediabox|cropbox, `upload_original_path/_hash` bei reparierter
Datei, `quality_status/_checked_at/_findings`, `render_ms`), `signature_signers`,
`signature_fields`, `signature_events` (append-only, kein updated_at),
`company_signature_assets`. Seit 07.10.2026 (Teil B, interne Unterschrift):
`signature_requests.zwischenstand_path/_hash/send_after_internal`,
`signature_fields.internal_user_id/user_signature_id/intern_beschriftung`,
`user_signatures` (hinterlegte Unterschrift/Paraphe je Mitarbeiter, Ersetzen
archiviert), `signature_handoffs` (Handy-QR, 10 Min, einmal, sha256-Token),
`signature_internal_signings` (append-only Protokoll: Funktion, IP, Geraet,
2FA-Weg, Bild-Hash, Dokument-Hash davor/danach), `users.can_sign_for_company`,
`users.signatur_funktion`.

### Website / Marketing / Medien
`service_pages`, `media_assets`, `banners`, `banner_daily_stats`, `banner_user_views`,
`banner_social_posts`, `banner_social_channels` (`publish_started_at`).

### Betrieb
`system_settings` (Schluessel/Wert), `error_events` (Fingerabdruck), `scheduled_task_runs`,
`jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`.

### Entfernt (Migration vorhanden, Tabelle gedroppt)
`workflow_definitions`, `workflow_runs`, `workflow_step_runs`, `workflow_prompts`,
`ai_action_logs` (tote Workflow-Engine, 20.08.2026), `family_members`,
`approval_requests` (-> `customer_change_requests`).

## Zentrale Beziehungen

```
User 1-1 Customer (Kundenkonto) ; User N-M Customer via employee_customers (Portfolio)
Partner 1-N Customer (partner_id = Datensicht!) ; Partner 1-N Commission
Customer 1-N Contract 1-1 {Vehicle|Energy|Internet}Detail
Contract 1-N ContractCommission / Provision / VermittlerSettlement / ContractRevision / Document
Customer 1-N Conversation 1-N CustomerMessage ; Conversation 1-N ConversationChannel
Customer 1-N Ticket / Document / DocumentRequest / CustomerChangeRequest
SignatureRequest 1-N SignatureSigner / SignatureField / SignatureEvent ; -> Customer? Contract?
```

Bewusst NICHT vorhanden: Beziehung `Customer -> contract_commissions` (Portal
kann Provisionen nicht versehentlich mitladen). `Contract` hat sie dagegen
(`contractCommissions`, `provisions`, `vermittlerSettlements`) - siehe KI-010.

## Aenderungsregeln

1. Nie eine bereits gelaufene Migration aendern/loeschen - neue Migration.
2. Destruktive Schritte (DROP, Spalte entfernen, Typ aendern, Rename) nur mit
   ausdruecklicher Freigabe des Betreibers, Backup vorher (`scripts/backup.sh`).
3. Beide Datenbanken beachten: SQLite-String-Vergleich vs. MySQL (siehe
   `date(COALESCE(...))`-Lehre), MySQL-Indexgrenze 3072 Byte, NULL in UNIQUE
   ist "immer verschieden" (deshalb `dedupe_key`/`link_key`-Spalten).
4. Indexe nach Messung (ARCH-1, `DatabaseIndexTest`); kein Index auf LIKE-'%x%'.
5. Neue customer_id-Tabelle: `CustomerMergeService` haengt per Schema-Abgleich
   um, `CustomerDeletionService` loescht mit - trotzdem Test ergaenzen.
6. Deploy fuehrt `migrate --force` automatisch aus - jede Migration muss auf
   Produktionsdaten laufen koennen.
