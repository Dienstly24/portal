# Integrations - externe Dienste

Stand: 23.09.2026. Alle Schluessel NUR in der Server-`.env` (nie Repo/Chat).
Ob ein Dienst in Produktion konfiguriert ist, steht NICHT im Repository ->
**UNKNOWN**, pruefbar auf `/admin/systemzustand` (zeigt nur "gesetzt"/"fehlt").

| Dienst | Zweck | Konfiguration | Code | Ausfallverhalten |
|---|---|---|---|---|
| **Anthropic (Claude)** | Dokumentanalyse (Vision/Text), KI-Kunden-/Verkaufs-/Website-Assistent, E-Mail-Klassifikation | `ANTHROPIC_API_KEY`, `ANTHROPIC_*_MODEL`, `AI_DOCUMENT_PROVIDER`, `AI_TEXT_PROVIDER`, `AI_ASSISTANT_PROVIDER` | `ClaudeDocumentAiProvider`, `ClaudeTextProvider`, `ClaudeAssistantProvider` | Dokument: kostenloses Ergebnis bleibt; Assistent: ehrliche Nachricht + Uebergabe + Glocke. Diagnose `ki:pruefen --live` |
| OpenAI (optional) | alternativer Assistent-Anbieter | `OPENAI_*` | `OpenAiAssistantProvider` | wie oben |
| **Meta Graph API** | FB-Seite/IG posten, Insights, Werbeanzeigen, **WhatsApp Cloud API** (Webhook + Versand + Embedded Signup) | `META_*` | `Social/*`, `Messaging/Channels/WhatsAppAdapter`, `Onboarding/*` | Post: Job `tries=1`, Marker `publish_started_at`, Glocke; WhatsApp: Webhook idempotent. Einrichtung `meta:einrichten` |
| **Lexoffice** | Kontakte/Rechnungen/Belege | `LEXOFFICE_API_KEY`, Timeouts 10/5 s | `LexofficeService` | Fallback "nicht erreichbar" |
| Google Places (New) | Bewertungen fuer Vertrauenskarte | `GOOGLE_PLACES_API_KEY`, `GOOGLE_PLACE_ID` | `GoogleBewertungAbruf` (Planer 04:45), `GoogleBewertung` liest nur Cache | Stand bleibt bis 7 Tage, danach keine Zahl |
| Google OAuth / Gmail API | Postfach-Anbindung | `GOOGLE_CLIENT_*` | `GmailApiMailboxProvider`, `OAuthTokenService` | Sync meldet Fehler, naechster Lauf in 2 Min |
| Microsoft Graph | Postfach-Anbindung | `MICROSOFT_*` | `GraphApiMailboxProvider` | wie oben |
| IMAP | Postfach-Anbindung | je `email_accounts`-Zeile (verschluesselt) | `ImapMailboxProvider` (webklex/php-imap) | wie oben |
| SMTP (Hostinger) | Versand aller Mails | `MAIL_*` | 21 Mailables, EIN Mailer | Einladungen/Signatur bewusst nicht queued |
| **Cloudflare Turnstile** | Bot-Schutz Registrierung | `TURNSTILE_*` | `TurnstileVerifier` | **fail-closed** (ohne Schluessel lehnt Registrierung in Produktion ab) |
| HaveIBeenPwned | Passwort-Leck-Abgleich (k-Anonymity) | `PASSWORD_BREACH_CHECK` | `PasswordPolicy` | faellt auf "bestanden" zurueck |
| Matomo (selbst gehostet) | Website-Statistik, cookielos, nach Einwilligung | `MATOMO_URL`, `MATOMO_SITE_ID` | `App\Support\Matomo`, `partials/matomo` | ohne Konfiguration kein Skript, kein Banner |
| Tesseract / poppler | OCR, PDF-Textebene, Seitenbilder | `OCR_*` | `TesseractTextExtractor`, `PdfTextLayerExtractor`, `SignaturePageRenderer` | ohne Pakete: Eskalation an KI bzw. leere Vorschau statt Fehler |
| GD (PHP-Erweiterung) | Bildvarianten, Unterschriften | - | `App\Support\Bildverarbeitung` | Pruefung beim Hochladen + Systemzustand |
| Fonds Finanz / Vergleichsportale | Datenimporte (Dateien, keine API) | - | `FondsFinanz/*`, `CommissionImport/*`, `Vermittler/*` | Import zweistufig |
| DNS (SPF/DKIM/DMARC/BIMI) | Zustellbarkeit | ausserhalb | `bimi:pruefen`, `bimi:testmail` | - |
| Postmark/Resend/SES/Slack | Laravel-Standardeintraege, **nicht benutzt** | - | - | - |

Regeln (CLAUDE.md "Kein Web-Request wartet minutenlang"):
- Jeder HTTP-Aufruf mit `timeout` + `connectTimeout`.
- Lange Aktionen als Job; nicht-idempotente Jobs `tries = 1`.
- Tokens immer als Header (Bearer / x-api-key), nie in Query/Body.
- Kostenpflichtige Aufrufe nie beim Seitenaufbau.
- Browser des Besuchers spricht nie mit Google/Meta (Ausnahme: Links).
