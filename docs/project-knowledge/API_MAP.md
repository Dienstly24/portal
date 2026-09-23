# API Map - Routen und Endpunkte

Stand: 23.09.2026, **504 Routen** (nach Entfernen der Breeze-Reste, KI-016) (`php artisan route:list`). Vollstaendige
Liste mit Schutz je Route: [ROUTES_INVENTORY.md](ROUTES_INVENTORY.md)
(generiert, nie von Hand pflegen).

Es gibt KEINE separate REST-API und keine Token-Authentifizierung (kein Sanctum/
Passport). Alle Routen stehen in `routes/web.php` (+ `routes/auth.php`) und
laufen durch die Web-Middleware-Gruppe. `api/*` liefert bei Fehlern JSON
(`shouldRenderJsonWhen`).

## Verteilung

| Praefix | Routen | Zugang |
|---|---|---|
| `admin/*` | 376 | auth + role:staff (+ engere Gruppen, siehe [AUTH_SYSTEM.md](AUTH_SYSTEM.md)) |
| `portal/*` | 52 | auth + role:customer |
| `partner/*` | 6 | auth + role:partner |
| `unterschreiben/*` | 10 | oeffentlich, Token + Limiter `signatur` |
| Auth (`login`, `register*`, `forgot-password*`, `reset-password*`, `zugang/*`, `passwort-festlegen`, `sicherheit/*`, `password`, `logout`) | ~25 | guest bzw. auth |
| Website (`/`, `website`, `leistungen*`, `{page}`, `ar/*`, `versicherungsmakler-hamburg`, `kontakt*`, `robots.txt`, `sitemap.xml`, `{page}.html`, `index.html`) | ~20 | oeffentlich |
| `api/*` | 6 | oeffentlich, gedrosselt, CSRF-Ausnahme fuer 2 POSTs |
| `webhooks/*` | 2 | oeffentlich, HMAC im Adapter, throttle 300/min |
| Sonstige oeffentlich: `hilfe`, `abmelden/{token}`, `magic-login/{user}` (signed), `s/{code}`, `sprache/{locale}`, `gesundheit` (HealthToken), `up` | 9 | |

## Oeffentliche JSON-Endpunkte

| Methode | Pfad | Zweck | Schutz |
|---|---|---|---|
| POST | `api/website-inquiry` | Anfrage aus alter statischer Site | throttle, Spamfilter, CSRF-Ausnahme |
| GET | `api/website-contact/token` | JS-Einmal-Token (Mindest-Ausfuellzeit) | throttle |
| POST | `api/website-contact` | Kontaktformular der statischen Site -> Ticket | Token, Honeypot, Spamfilter, CSRF-Ausnahme |
| GET | `api/website-assistent/status` | Assistent verfuegbar? | throttle |
| GET | `api/website-assistent/verlauf` | Verlauf der Sitzung | Sitzung |
| POST | `api/website-assistent` | Nachricht an Website-KI | CSRF, `AssistantBudget` (IP/Sitzung/Tag) |
| GET | `gesundheit` | Ampel fuer externe Ueberwachung (503 = handeln) | `HEALTH_TOKEN` |
| GET/POST | `webhooks/whatsapp` | Meta-Verifizierung / Ereignisse | Verify-Token / HMAC, Idempotenz `channel_events` |

## Interne JSON-Endpunkte (Sitzung + Rolle)

Sofort-Suchen (alle portfolio-gescoped oder admin/manager):
`admin/kunden-suche`, `admin/search`, `admin/documents/customer-search`,
`admin/email/kunden-suche`, `admin/tasks/kunden-suche`,
`admin/signaturen/kunden-suche`, `admin/customers/{id}/familie/kunden-suche`,
`admin/employees/customer-search` (admin/manager),
`admin/interne-provisionen/vertrag-suche`, `admin/vermittler-abrechnung/vertrag-suche`.

Status/Feeds: `admin/documents/{id}/analyse-status`,
`admin/documents/{id}/kunden-vorschlaege`, `admin/kundenchat/{id}/feed`,
`portal/nachrichten/feed` (seitenweise + `?seit=`), `portal/documents/{id}/analyse-status`,
`admin/ki-assistent/{id}/antwortvorschlag`, `admin/systemzustand.json` (503 bei rot).

## Ausgehende Integrationen

Siehe [INTEGRATIONS.md](INTEGRATIONS.md).

## Konventionen fuer neue Endpunkte

1. In die passende Rollen-Gruppe in `routes/web.php`; statische Pfade VOR
   `{id}`-Routen desselben Praefixes (z.B. `customers/duplicates`).
2. Datensatz-ID im Pfad -> sichtbare Pruefung im Controller
   (`ScopesCustomerAccess`, Policy oder Relation des Eigentuemers);
   `ZugriffspruefungTest` schlaegt sonst an.
3. Sofort-Suche -> `scopeCustomers()` / `visibleCustomerIds()`, max. wenige
   Treffer, Ausgabe ohne `@dienstly24.internal`-Platzhalter.
4. Oeffentlich -> `throttle`, keine DB-IDs in der URL, keine Enumeration.
5. Danach Inventar neu erzeugen:
   ```
   php artisan route:list --json > /tmp/routes.json
   php scripts/wissensbasis-routen.php /tmp/routes.json
   ```
