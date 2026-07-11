# Dienstly24 – Production Readiness Report

**Datum:** 2026-07-11 · **Branch/PR:** `claude/dienstly-system-audit-akcmkd` (PR #3) · **Validiert von:** automatisierte Vollprüfung
**Testsuite:** 205 Tests / 643 Assertions – **alle grün** · zusätzlich 16 temporäre E2E-/Smoke-/Security-Prüfungen (nach Lauf entfernt)

**Gesamturteil: 🟢 Produktionsreif für den IMAP-Betrieb** – mit klar benannten Betriebsvoraussetzungen und zwei nicht-blockierenden Hinweisen. Es wurde ein sicherheits-/deployment-relevanter Punkt gefunden und **sofort behoben** (fehlende Env-Dokumentation); danach keine kritischen Findings mehr offen.

---

## 0. Wichtiger Vorbehalt zum Merge-Status (zuerst lesen)

Die Aufgabe nannte „PR #3 in main gemerged". **Git widerspricht dem:** `origin/main` enthält **keinen** der 24 Commits dieses Branches (kein `Services/Mailbox`, keine Partner-/Provisions-/AI-Module). `main` verfolgt eine **parallele, abweichende Entwicklungslinie** (u. a. „banner system", „family member detail fields", „unified Meine Daten"). Der gemeinsame Vorfahr ist alt (`c6fbc07`).

**Konsequenz:** Validiert wurde der **Inhalt von PR #3 (= dieser Branch)** – das ist die Codebasis, die produktionsreif gemacht werden sollte. Ein späterer echter Merge in das aktuelle `main` wird nicht-trivial (parallele Features, überlappende Bereiche wie Familien-/Adressverwaltung). Das ist **kein Code-Fehler dieses Branches**, aber ein Release-Risiko, das vor dem echten Merge bewertet werden muss. Empfehlung: bewusste Merge-/Rebase-Strategie festlegen, bevor beide Linien zusammengeführt werden.

---

## 1. Validierungsmethodik

| Ebene | Durchgeführt |
|---|---|
| Deployment-Simulation | `migrate:fresh` auf leerer DB (alle Migrationen sauber), `route:cache` / `config:cache` / `view:cache` erfolgreich, Scheduler registriert alle 7 Jobs |
| Regression | Vollständige Suite 205/205 grün |
| Seiten-Render (E2E) | **33 Admin-Seiten + 11 Portal-Seiten** über echtes HTTP mit realen Datensätzen gerendert – **kein 5xx** |
| Geschäfts-E2E | Voller Erstkunden-Lebenszyklus (Mail→Ticket→Vorschlag→Bestätigung→Anhang→Gast-Ticket-Relink→Portal), Fonds-Finanz-Import, Provisionsbuchung mit Lexoffice |
| Integrations-Degradation | IMAP-Ausfall, Lexoffice-500, fehlender KI-Key – alle sauber isoliert |
| Sicherheit/Rechte | Rollen- und Portfolio-Grenzen über HTTP verifiziert |
| Performance | Query-Counts gemessen (kein N+1) |

Nicht möglich in der Sandbox: **echte** Verbindungen zu Hostinger-IMAP, Google/Microsoft und Lexoffice (keine Zugangsdaten / kein ausgehender Mail-Port). Diese Pfade sind gegen Fakes verifiziert; der Erfolgsfall braucht je einen einmaligen Live-Test auf dem Produktivserver.

---

## 2. Bereichsweise Bewertung

| Bereich | Status | Nachweis / Anmerkung |
|---|---|---|
| **Email Management** | 🟢 | Konten-CRUD, Sync (dedupe über Unique-Constraint), Rohspeicherung, Kategorisierung, Prune – alle Pfade getestet; Zugangsdaten verschlüsselt & `$hidden` |
| **Customer Portal** | 🟢 | Alle 11 Portal-Seiten rendern; Dokumentenanfrage-Upload/Status, Self-Service, Vollständigkeits-Widget funktional; interne Dokumente für Kunden unsichtbar (verifiziert) |
| **Partner Management** | 🟢 | CRUD, Domain-Erkennung, Historie/Summe; `role:admin,manager`-gated |
| **Tickets** | 🟢 | Erstellung aus E-Mail (Gast/verknüpft), Nachverknüpfung bei Bestätigung; Portal- und Admin-Ansicht rendern |
| **Tasks** | 🟢 | Workflow-Aufgaben mit Betreuer-Zuweisung/Fallback; Fristen-Watchdog aktiv |
| **Documents** | 🟢 | Privater Storage, Sichtbarkeit, autorisierte Downloads, Kategorisierung; H1 (erst nach Bestätigung) & H3 (Datei-Löschung) verifiziert |
| **Workflows** | 🟢 | Kategorie→Aktion deterministisch, idempotent; Score-Matching mit HITL-Stufen |
| **Lexoffice** | 🟡 | Belegbuchung code-seitig korrekt, Fehlerpfad lässt Gutschrift offen; **echte API nie live getestet**, Kategorie-GUID hartkodiert |
| **Fonds Finanz** | 🟢 | Parser + Import + externe Referenz + PDF-Fallback + Vertrags-Dokumentzuordnung E2E getestet |
| **OAuth (Gmail/M365)** | 🟡 | Token-Flow/Refresh/Fehlerpfad getestet; **App-Registrierung + Live-Consent stehen aus** (extern) |
| **AI** | 🟢 | Nur bei `sonstige` + gesetztem Key; Injection-/Halluzinations-Ausgaben verworfen; nie Auto-Anwendung (HITL-Gateway); Ausfall unschädlich |
| **Security** | 🟢 | Verschlüsselung, private Storage, Audit-Log, DSGVO-Löschkonzept; keine offene Fremdzugriffslücke |
| **Permissions** | 🟢 | Rollen- & Portfolio-Grenzen HTTP-verifiziert (employee/support/customer je geblockt) |
| **Performance** | 🟢 | Kundenliste 13 Queries/30 Kunden (kein N+1); Posteingang bounded; Dashboard 33 Queries (konstant, O(1)) |

---

## 3. Gefunden & sofort behoben

| Fund | Schweregrad | Maßnahme |
|---|---|---|
| Neue Integrations-Env-Vars (`GOOGLE_*`, `MICROSOFT_*`, `ANTHROPIC_*`, `LEXOFFICE_API_KEY`) fehlten in `.env.example` → Deployer könnte Optionen übersehen | Deployment-Doku | **Behoben** (Commit `9b01921`): dokumentiert inkl. Redirect-URI-Hinweis; nur Doku, keine Verhaltensänderung; Suite danach weiter 205/205 grün |

Kein weiterer kritischer Fund. Die im vorigen Prüfbericht als H1–H3/T1/M2–M4 gemeldeten Punkte wurden bereits in den Phasen 1–3 behoben und sind hier erneut per E2E bestätigt.

---

## 4. Verbleibende Risiken (nicht-blockierend, bewusst offen)

### 🟠 Betriebsvoraussetzungen (müssen beim Deployment sichergestellt sein)
1. **Zwei Worker Pflicht:** `php artisan schedule:work` (Sync/Prune/Reminder + Bestands-Cronjobs) **und** `php artisan queue:work` (queued Mails, `QUEUE_CONNECTION=database`). Ohne Queue-Worker bleiben Dokumentenanfrage-Mails liegen.
2. **Live-Erstinbetriebnahme externer Dienste:** je ein manueller Test für Hostinger-IMAP-Login, Google-/MS-App-Registrierung (Redirect-URI `…/admin/email-accounts/oauth/callback`, Google-Scope-Verifizierung für `gmail.readonly`) und einen Lexoffice-Testbeleg.

### 🟡 Funktionale Grenzen (dokumentiert, kein Fehler)
3. Gescannte PDFs ohne Textebene → keine Auswertung (kein OCR) → manuelle Prüfaufgabe.
4. Lexoffice-Kategorie-GUID hartkodiert; bei geänderter Standardkategorie bricht die Buchung sichtbar (Gutschrift bleibt offen).
5. KI-Modell-/Prompt-Drift: `ai_decisions` protokolliert alles, echte Regressionsfälle gegen das Live-Modell fehlen; **AVV mit dem KI-Anbieter vor Produktivnutzung** prüfen (DSGVO).
6. Regelbasierte Kategorisierung ist keyword-präzedenzbasiert (z. B. „Unterlagen" im Text → `dokumente` vor `kundenanfrage`). Beabsichtigt; die KI-Stufe fängt echte Zweifelsfälle (`sonstige`) ab.
7. Gast-Ticket-Relink erfolgt über die Absenderadresse – eine gemeinsam genutzte Familienadresse ordnet deren Gast-Tickets demselben Kunden zu.

### 🟢 Optimierungen (niedrig)
8. Admin-Dashboard 33 Queries pro Aufruf (konstant, nicht datenabhängig) – kurzer Badge-Cache möglich.
9. Alt-Befunde aus dem Bestand unverändert: Klartext-Passwörter in Willkommens-Mails, Lexoffice-Key im Settings-Formular sichtbar, `AdminController`-Größe, fehlende **Mandantenfähigkeit** (Instanz-pro-Firma statt Multi-Tenant).

---

## 5. Fazit & Freigabeempfehlung

Der PR-#3-Stand ist **funktional vollständig, sicher und stabil**: saubere Migrationen, produktionstaugliche Caches, keine N+1-Hotspots, verifizierte Rollen-/Portfolio-Grenzen, DSGVO-Löschkonzept, durchgängiges Human-in-the-Loop für alle automatisierten Aktionen, und alle externen Ausfälle sind isoliert.

**Freigabe für Produktion: JA**, unter drei Bedingungen:
1. `schedule:work` **und** `queue:work` als Dienste einrichten.
2. Merge-Strategie gegen das divergierte `main` bewusst festlegen (siehe Abschnitt 0) – oder diesen Branch als eigenständige Release-Linie deployen.
3. Einmalige Live-Verifikation der externen Dienste (IMAP-Login, OAuth-Consent, ein Lexoffice-Testbeleg) direkt nach dem ersten Deployment.

Ohne Punkt 2/3 ist der **Kernbetrieb (IMAP-Postfächer, Kunden-/Vertrags-/Dokument-/Ticket-/Aufgaben-/Partner-/Provisions-Verwaltung, Portal)** sofort produktiv nutzbar; OAuth und KI sind additive Ausbaustufen, die sich ohne Risiko später scharfschalten lassen.

---

*Validierung ohne dauerhafte Code-Änderungen außer der Env-Dokumentations-Korrektur. Alle temporären Prüf-Tests wurden nach dem Lauf wieder entfernt; die reguläre Suite bleibt bei 205/205.*
