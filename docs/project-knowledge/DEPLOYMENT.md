# Deployment Knowledge

Stand: 23.09.2026. Einrichtung im Detail: `docs/DEPLOYMENT.md`; Backup:
`docs/BACKUP_UND_WIEDERHERSTELLUNG.md`; Umzug: `docs/UMZUG_SCHRITT_FUER_SCHRITT_AR.md`.

## Arbeitsweise (verbindlich, CLAUDE.md)

1. Feature-Branch -> Commit -> Push -> **PR mit `base=main`** (immer pruefen!).
2. Der Betreiber reviewt und merged selbst.
3. Merge auf `main` = automatischer Deploy.
4. Folgearbeit nach Merge: `git fetch origin main` und neu branchen.

## CI (`.github/workflows/deploy.yml`)

Ausloeser: Push auf `main` und PRs gegen `main`.

| Job | Inhalt | Gate fuer Deploy |
|---|---|---|
| `test` | PHP 8.3, SQLite, `npm ci && npm run build`, tesseract+poppler, `migrate:fresh`, `php artisan test` | ja |
| `test-mysql` | wie oben gegen MySQL 8.0 (Service-Container) | ja |
| `qualitaet` | `composer lint` (Pint `--test`), `composer stan` (PHPStan 5 + Baseline, 290 Eintraege) | ja |
| `audit` | `composer audit`, `npm audit --omit=dev --audit-level=high` | ja |
| `proxy-liste` | Cloudflare-Ranges vergleichen (nur Hinweis in der Zusammenfassung) | nein |
| `deploy` | `needs: [test, test-mysql, qualitaet, audit]`, nur Push auf main, Environment `production`, SSH (`appleboy/ssh-action`) | - |

Dependabot: composer, npm, github-actions (`.github/dependabot.yml`).
Wichtig: ein roter Test auf `main` heisst **nicht ausgeliefert** (Deploy
uebersprungen) - Lehre 18.09.2026.

## Server-Ablauf (`scripts/deploy.sh`)

1. `php artisan down --retry=15` (trap: IMMER wieder `up`)
2. `composer install --no-dev --optimize-autoloader`
3. `npm ci && npm run build` mit Sicherung/Wiederherstellung von `public/build`
4. `php artisan migrate --force` (automatisch - jede Migration muss produktionstauglich sein)
5. `tickets:attachments-private`, `db:seed --class=ServicePageSeeder` (idempotent), `storage:link`
6. `config:cache`, `route:cache`, `view:cache`, `event:cache`
7. `chown` von `storage` + `bootstrap/cache` an den PHP-FPM-Nutzer (Lehre 10.09.2026)
8. `queue:restart`, PHP-FPM reload (OPcache), `up`

Manueller Deploy bei SSH-Timeout (bekanntes Netzproblem, kein Code-Fehler):
```
cd /var/www/dienstly24/portal && git fetch --all --prune \
  && git reset --hard origin/main && bash scripts/deploy.sh
```

## Server-Voraussetzungen

| Punkt | Stand |
|---|---|
| PHP 8.3 + FPM, Erweiterungen inkl. **gd**, intl, zip, mysql | gd nachinstalliert 10.09.2026 |
| MySQL 8 | ja (Produktion) |
| tesseract-ocr (+deu, ara), poppler-utils | installiert, `OCR_ENABLED=true` (18.07.2026) |
| Cron `* * * * * php artisan schedule:run` | **UNKNOWN** (Systemzustand zeigt Laeufe) |
| Queue-Worker `default` + `lang` (supervisor/systemd) | **UNKNOWN** |
| Redis | nicht aktiv (Empfehlung, `docs/ANLEITUNG_REDIS_AR.md`) |
| Host-Firewall (ufw) | `inactive` (gemessen 05.09.2026) -> KI-003 |
| Edge | Hoster-CDN `hcdn`, nicht Cloudflare (05.09.2026) |
| Backup (`scripts/backup.sh`, GPG, zweiter Ort, Cron) | Skript bewiesen 16.09.2026; Inbetriebnahme auf dem Server **UNKNOWN** -> KI-004 |

## Sprache (seit 23.09.2026, KI-014)

`APP_LOCALE` soll auf dem Server `de` sein. Steht dort noch `en` aus der alten
Vorlage, ist das unschaedlich: `App\Support\Sprache` stellt beim Start jede
nicht unterstuetzte Sprache auf `de`.

## Betriebs-/Diagnosebefehle (auf dem Server)

`php artisan ki:pruefen [--live]` · `queue:health` · `ocr:check` ·
`bimi:pruefen --domain=dienstly24.de` · `netz:client-ip-pruefen` ·
`bash scripts/netz-pruefen.sh [--vorschlag]` (lesend) ·
`bash scripts/restore.sh --pruefen` · `2fa:zuruecksetzen <email>`.

## Lokale Entwicklung / Arbeitsumgebung

`bash scripts/testumgebung-pruefen.sh` zeigt Fehlendes. In netzbeschraenkten
Umgebungen scheitert `composer install` an `api.github.com` (403):
`composer install --prefer-source`. **phpstan/phpstan ist nur als Zip
verfuegbar** - dort ist `composer stan` lokal nicht moeglich; die CI ist der
Massstab (Stand 23.09.2026 in dieser Umgebung so erlebt).
