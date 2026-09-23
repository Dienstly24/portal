# Auth System - Authentifizierung und Autorisierung

Stand: 23.09.2026. Ausfuehrliche Begruendungen: `CLAUDE.md` (Abschnitte
Passwoerter, Zwei-Faktor, SEC-1..5, Sichtbarkeit).

## 1. Anmeldung (Authentication)

| Weg | Dateien | Regeln |
|---|---|---|
| Login E-Mail + Passwort | `Auth\AuthenticatedSessionController`, Limiter `anmeldung` (je IP + je Adresse) | deaktivierte Konten (`is_active=false`) werden auch in laufender Sitzung abgemeldet (`EnsureUserRole`) |
| Registrierung (nur Kunden) | `Auth\RegisteredUserController`, `pending_registrations`, `TurnstileVerifier` | ZWEISTUFIG: erst Vormerkung, Konto+Kundennummer erst nach Bestaetigungslink; Turnstile fail-closed; `MAX_SENDS` |
| Magic-Login | `Auth\MagicLoginController`, `/magic-login/{user}` signiert, 90 Tage | nur Kundenkonten; nie in QR-Codes |
| Passwort vergessen | `PasswordResetLinkController`, Limiter `passwort-reset` | Kennung E-Mail ODER Kundennummer ODER email2; Antwort immer gleich (keine Enumeration); Broker 60 Min |
| Einladung / Passwort setzen | `PasswordSetupController::invitationUrl`, relativ signiert, 14 Tage | Mitarbeiter bekommen nie ein Klartext-Passwort |
| Erzwungener Wechsel | `EnsurePasswordChanged` -> `/passwort-festlegen` | Startpasswort = Geburtsdatum haelt nur bis zum ersten Login |
| 2FA (TOTP) | `Auth\TwoFactorController`, `EnsureTwoFactor`, `App\Support\Totp`, `QrCode` | Pflicht fuer Staff + Partner (`User::requiresTwoFactor`), Schalter `two_factor_required` (AN); 8 Ersatzcodes; Sitzungsschluessel `2fa_ok:<id>`; 5 Fehlversuche/300 s |
| Unterschreiben ohne Konto | `SignatureSigningController`, Limiter `signatur` | 40-Zeichen-Token (sha256), Einmalcode an Mail, optional Geburtsdatum (verschluesselt, 5 Versuche) |
| Webhooks | `webhooks/*` ohne CSRF | Echtheit per HMAC-Signatur im Adapter |
| Externe Ueberwachung | `/gesundheit` + `HealthToken` | 404 ohne `HEALTH_TOKEN` |

Passwort-Regel: `App\Support\PasswordPolicy` (Kunden >= 12, Staff/Partner >= 14,
HIBP-Abgleich k-Anonymity), `Password::defaults()` verdrahtet. Alle Schreibwege
ueber `User::setPassword()`; danach `SessionPasswordHash::refresh()`
(`AuthenticateSession` + `logoutOtherDevices`).

Sitzung: Treiber `database`, Lebensdauer 120 Min (`SESSION_LIFETIME`),
`secure` in Produktion, `http_only`, `same_site=lax`, nicht verschluesselt.

Zusatzschicht: `ExtraBasicAuth` (`ADMIN_BASIC_AUTH` vor /admin,
`STAGING_BASIC_AUTH` fuer Staging-Hosts) - No-Op ohne Variablen.
Produktionswert: **UNKNOWN**.

## 2. Rollen (Autorisierung Stufe 1: Route)

`users.role` + Middleware `role:<liste>` (`EnsureUserRole`, leitet falsche Rolle
in den eigenen Bereich um).

| Bereich | Middleware |
|---|---|
| `/portal/*` | `auth, role:customer` |
| `/partner/*` | `auth, role:partner` |
| `/admin/*` | `auth, role:admin,manager,support,employee` + je Untergruppe enger |

Engere Gruppen in `/admin` (aus `route:list`, 23.09.2026):
- **nur admin**: Kanaele, KI-Anbieter, E-Mail-Konten, Einstellungen, Kunden-Loeschen/Purge-Aktionen (8 Kundenrouten), einzelne Mitarbeiter-/Aktivitaets-/Werbe-Aktionen
- **admin, manager**: Mitarbeiter, Partner, Banner, Leistungsseiten, Berichte (teilw.), Fehler, Systemzustand, Lexoffice, Vermittler-Abrechnung, Ausgangs-Provisionen, Gutschriften, KI-Wissensbasis/-Luecken/-Training, Tarifrechner, Team, Werbung, Import/Export, Aktivitaet
- **admin, manager, support**: E-Mail-Posteingang, Anfragen
- **Recht `can:provisionen-verwalten`**: Provisionsmanagement, Interne Provisionen (admin ODER `users.can_manage_commissions`)
- alle Staff: Kunden (Portfolio!), Vertraege, Dokumente, Postfach, Chat, Tasks, Termine, Signaturen, Aenderungsantraege

## 3. Einzelrechte (`users.can_*`)

`can_see_all_customers`, `can_manage_contracts`, `can_manage_tickets`,
`can_approve_changes`, `can_send_emails`, `can_import_export`,
`can_manage_commissions`. Vergabe nur in `EmployeeController`
(`vergebbareRechte`): niemand vergibt/entzieht ein Recht, das er selbst nicht hat
(Admins ausgenommen). **Default der Spalte `can_see_all_customers` = true** (KI-001).

## 4. Gates und Policies

Gates (`AppServiceProvider`): `provisionen-verwalten`, `firmensignatur-verwalten`
(admin/manager), `firmensignatur-benutzen` (Staff).
Policies: `CustomerChangeRequestPolicy`, `InternalConversationPolicy`,
`InternalMessagePolicy`, `SignatureRequestPolicy` (Kundenvorgang -> Portfolio,
eigenstaendiger Vorgang -> Ersteller + Leitung). Bewusst KEINE Policy je Modell
(siehe Audit 15.09.2026, P3-19).

## 5. Sichtbarkeit von Kunden (Autorisierung Stufe 2: Datensatz)

EINE Quelle:
- `User::canSeeAllCustomers()` = admin | manager | `can_see_all_customers`
- `User::visibleOwnerIds()` = selbst + aktiv vertretene Kollegen (`substitutions`)
- `User::canAccessCustomer($id)` = Staff UND (alle ODER EXISTS in `employee_customers`)
- `Customer::scopeVisibleTo($user)` = deckungsgleicher Query-Scope (`whereExists`)
- Controller-Trait `ScopesCustomerAccess` (`scopeCustomers`, `visibleCustomerIds`)
- Postfach: `ConversationInbox::scope()` / `canAccessMessage()` (Unterhaltungen
  ohne Akte fuer alle Staff sichtbar - gewollt, zum Zuordnen)
- Partner: ausschliesslich ueber `$partner->customers()` / `->commissions()`
- Kunde: ausschliesslich ueber `auth()->user()->customer`

Nachweis: `ZugriffspruefungTest` prueft jede Staff-Route mit Datensatz-ID im
Pfad auf eine erkennbare Pruefung; Sofort-Suchen ohne ID sind am 23.09.2026
manuell geprueft (alle gescoped oder nur admin/manager).

## 6. Weitere Schutzmechanismen

- CSRF in der Web-Gruppe (Ausnahmen: `api/website-inquiry`, `api/website-contact`, `abmelden/*`, `webhooks/*`).
- Benannte Limiter: `registrierung`, `anmeldung`, `passwort-reset`, `signatur` + zahlreiche `throttle:n,m` an Routen.
- `TrustProxies` mit expliziter Liste (`config/trustedproxy.php`).
- CSP mit Nonce (`SecurityHeaders`, `CspNonce`).

## 7. Offene Punkte

KI-001 (Default `can_see_all_customers`), KI-006 (Rechte-Liste in der
Einladungsmail), Breeze-Reste `verify-email`/`confirm-password` (TD-05).
