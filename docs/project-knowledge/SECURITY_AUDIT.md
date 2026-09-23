# Security Audit

Stand: 23.09.2026. Baut auf SEC-1..5 (03.09.), Audit 15.09. und Nachlauf 16.09.
auf (`docs/SICHERHEIT_SEC_1_BIS_5.md`, `docs/AUDIT_2026-09-15_BEHEBUNG.md`).
Offene Befunde stehen ausschliesslich in [KNOWN_ISSUES.md](KNOWN_ISSUES.md).

## Ergebnis dieser Pruefung

**Kein CRITICAL- oder HIGH-Befund im Code.** Neu gefunden: KI-006, KI-010
(beide LOW), KI-012 (Turnstile fehlt in der Datenschutzerklaerung).
Offen bleiben die bekannten Betriebs-/Rechtspunkte KI-001..004, KI-008, KI-009.

## Gepruefte Schutzschichten (23.09.2026)

| Bereich | Stand | Nachweis |
|---|---|---|
| Abhaengigkeiten | `composer audit`: 0 Hinweise (installierte Pakete, ohne phpstan/larastan - siehe KI-018); `npm audit --omit=dev --audit-level=high`: 0 | lokal 23.09. + CI-Job `audit` |
| Rollen an Routen | 509 Routen ausgewertet; jede `/admin`-Route hat `auth` + `role:staff`, Provisionsbereiche zusaetzlich `can:provisionen-verwalten` | [ROUTES_INVENTORY.md](ROUTES_INVENTORY.md) |
| Datensatz-Zugriff | `ZugriffspruefungTest` (Routen mit ID im Pfad); **zusaetzlich manuell**: alle 8 Kunden-Sofortsuchen gescoped (`scopeCustomers`/`visibleCustomerIds`/`canSeeAllCustomers`) oder nur admin/manager | Code-Durchsicht |
| SQL-Injection | alle `whereRaw/selectRaw/DB::raw` mit Variablen geprueft: Spaltennamen aus Whitelists (`CommissionAnalytics::groupedBy`, `Bundesland::filterQuery` wirft bei fremder Spalte), Werte gebunden | grep + Durchsicht |
| XSS | 42 `{!! !!}`: JSON-LD aus PHP, QR-SVG (selbst erzeugt), `ServicePage::bodyHtml()` (escapet jede Zeile mit `e()`), `InternalMessage::renderedMessage()` (escapet, dann nur @-Hervorhebung), Text-Mails. Kein ungefilterter Nutzerinhalt gefunden | Durchsicht |
| CSP | Nonce, kein `unsafe-inline`/`unsafe-eval` in `script-src`, `script-src-attr 'none'`; `style-src 'unsafe-inline'` bewusst | `ContentSecurityPolicyTest` |
| CSRF | Web-Gruppe; 4 begruendete Ausnahmen | `bootstrap/app.php` |
| Mass Assignment | `$request->all()` nirgends; Rechte nur ueber `vergebbareRechte()` | grep, `RechteEskalationTest` |
| Codeausfuehrung | kein `eval`, `unserialize`, `shell_exec`/`exec` im App-Code gefunden (externe Tools ueber Prozess-Aufrufe in OCR/PDF) | grep |
| Dateien | Dokumente, Nachweise, Signaturen, Ticketanhaenge auf PRIVATER Platte (`serve => false`), Zugriff nur ueber Controller; `public`-Platte nur fuer Marketing-Medien/Banner/Partnerlogos | `DateizugriffTest`, `DocumentSecurityTest` |
| Passwoerter/2FA | PasswordPolicy + HIBP, 2FA Pflicht Staff/Partner, Sitzungs-Rauswurf bei Wechsel | `PasswordSecurityTest`, `TwoFactorTest` |
| Registrierung | zweistufig, Turnstile fail-closed | `RegistrationHardeningTest` |
| Proxy/IP | explizite Proxy-Liste; Client-IP am 05.09. gemessen korrekt | `ProxySpoofingTest`, `ClientIpIntegrityTest` |
| Geheimnisse | nie ausgegeben (Systemzustand nur "gesetzt/fehlt"), Tokens als Header | `SystemHealthTest` |
| Oeffentliche Token-Seiten | Signatur: sha256, befristet, widerrufbar, Limiter je IP+Token, Geburtsdatum verschluesselt + 5 Versuche | `SignatureSecurityTest`, `SignerIdentityTest` |
| Webhooks | HMAC-Pruefung vor Speicherung, Idempotenz ueber `dedupe_key` | `WhatsAppChannelTest` |
| Personenbezug in Logs | `ErrorRecorder` ohne Formulardaten/IP; KI-Protokoll ohne Nachrichtentext; KI-Training nur geschwaerzt | `ErrorVisibilityTest`, `KiTrainingImportTest` |
| Tracking | Website erst nach Einwilligung; Anwendungsbereiche nie | `MatomoMessungTest` |

## Angreifer-Gegenproben (Stichprobe dieser Sitzung)

- Employee ruft `admin/employees/customer-search` (liefert ungescoped) -> Route
  ist `role:admin,manager`, Employee wird umgeleitet. OK.
- Portal-Kunde versucht, Provisionsdaten zu sehen -> keine Relation von
  `Customer`, Portal-Views serialisieren keine Vertragsmodelle. OK, aber
  strukturell nur fuer `Customer` abgesichert (KI-010).
- Manager versucht, per manipuliertem Formular ein Recht zu vergeben, das er
  nicht hat -> wird nicht gespeichert (`vergebbareRechte`), Mail behauptet es
  aber (KI-006).

## Nicht aus dem Repo pruefbar (UNKNOWN)

Server-`.env` (Turnstile, Basic-Auth, `APP_DEBUG=false`?, `SESSION_SECURE_COOKIE`),
Firewall/offene Ports, SSH-Konfiguration, Worker-Benutzer, Backup-Verschluesselung
auf dem Server. Pruefwerkzeuge: `scripts/netz-pruefen.sh`, `/admin/systemzustand`.

## Regeln fuer neue Arbeit

Siehe `CLAUDE.md` - insbesondere: EINE Sichtbarkeitsquelle, nie raten, keine
Geheimnisse ausgeben, private Platte, Webhooks per Signatur, oeffentliche
Endpunkte gedrosselt, Sicherheitstests duerfen sich nie selbst ueberspringen,
bei Sicherheitsfragen eine Gegenprobe aus der Rolle des Angreifers.
