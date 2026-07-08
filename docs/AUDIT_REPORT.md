# Dienstly24 Portal — Technical & Security Audit

**Date:** 2026-07-08 · **Branch:** `system-audit-fixes` · **Framework:** Laravel 13.18 (PHP 8.3)

Method: full static review of all controllers, models, middleware, routes, migrations,
mail classes, Blade views and configuration, plus a live verification run
(`composer install`, `php artisan migrate`, `php artisan test`) on a fresh SQLite database.

---

## 1. Critical problems (fixed in this branch)

| # | Issue | Impact |
|---|-------|--------|
| C1 | **Fresh migrations fail.** `2026_07_07_200001_complete_system` guards the contracts block on `premium_amount` but re-adds `pdf_path`, which `create_contracts_table` already created → *duplicate column* error. Every fresh install and the whole test suite break (24 / 25 tests failed). | Blocks deployments, CI, tests |
| C2 | **MySQL-only raw SQL in migration.** `2026_07_08_120000_add_source_to_tickets` runs `ALTER TABLE tickets MODIFY …`, which fails on SQLite (the project's default `DB_CONNECTION`). Migration chain halts; the two migrations after it never run. | Blocks installs on default DB |
| C3 | **Missing class `App\Mail\CampaignMail`.** `EmailMarketingController::send()` imports and instantiates it, but the class and its template do not exist → fatal 500 on every campaign send. | E-mail marketing completely broken |
| C4 | **`users.role` enum too narrow.** Column allows `admin/employee/customer`, but the app assigns and filters `manager` and `support` (EmployeeController, routes, middleware). Promoting a user to manager fails on MySQL strict mode / SQLite check constraint. | Role management broken |
| C5 | **Public inquiry API fails open.** `/api/website-inquiry` (CSRF-exempt) compares `header('X-Inquiry-Token') !== config('services.inquiry.token')`. If `INQUIRY_TOKEN` is unset, both sides are `null` and **any unauthenticated request passes**. No rate limiting either. | Unauthenticated ticket flooding |
| C6 | **Duplicate class definition.** `app/ModelsAnnouncement.php` declares the same FQCN as `app/Models/Announcement.php` (byte-identical). PSR-4 violation; ambiguous autoloading. | Autoloader instability |

## 2. Medium problems (fixed in this branch)

| # | Issue | Impact |
|---|-------|--------|
| M1 | **IDOR / missing authorization in admin area.** The portfolio-scoping system (`visibleCustomerIds`) is applied to lists and `customerShow`, but **not** to `customerEdit/Update`, `destroyCustomer`, `contractCreate/Store`, `ticketShow/Reply`, `storeNote/Document/Family/Vehicle`, `destroyFamily`, `downloadAttachment`, `merge*`, `customerTimeline`, `noteMarkDone`. A restricted employee can read/modify/delete any customer by guessing IDs. | Data exposure / integrity |
| M2 | **Deactivated staff keep working sessions.** `is_active` is only checked at login; an already-logged-in deactivated user retains full access. | Access control gap |
| M3 | **Duplicate side effects.** `AdminController::storeCustomer` sends the welcome mail (containing the plaintext password) twice; `AuthenticatedSessionController::store` saves `last_login_at` twice. | Duplicate mails / writes |
| M4 | **Customer ticket priority silently dropped.** `priority` (and `source`/guest fields) missing from `Ticket::$fillable`; `Ticket::create()` in the portal discards the customer's chosen priority — every ticket becomes "mittel". | Wrong data |
| M5 | **MySQL-only `FIELD()` in TaskController** (`orderByRaw("FIELD(priority,…)")`) crashes the tasks page on SQLite/Postgres. | Tasks page broken on default DB |
| M6 | **`.env` written from a web request.** `SettingsController::update` splices the unvalidated `lexoffice_api_key` into `.env` with `preg_replace` — newline input injects arbitrary environment variables; `$`/backslash break the replacement. Additionally `env()` is called at runtime (LexofficeService, ImportLexoffice), which returns `null` once `config:cache` is used. | Env injection / prod breakage |
| M7 | **Missing validation on state changes.** `TaskController::update` and `AppointmentController::update` accept any `status` string; `EmployeeController::update` accepts an empty name. | Data integrity |
| M8 | **Route closures block `route:cache`.** `/` and `/admin/contracts/new` are closures; `php artisan route:cache` (standard prod optimization) throws. | Perf optimization impossible |

## 3. Minor improvements (documented, mostly not changed)

* Plaintext passwords are e-mailed in welcome mails (customer & employee). Consider a password-set link instead. *(Not changed — behavioral decision for the owner.)*
* The Lexoffice API key is rendered in cleartext in the settings form.
* `family_members` table + `FamilyMember` model are unused duplicates of `customer_family` (kept — removal is a destructive schema change).
* Orphaned Breeze leftovers: `ProfileController`, `dashboard.blade.php`, `profile/*` views and `layouts/navigation.blade.php` reference routes (`dashboard`, `profile.edit`, …) that no longer exist. They are unreachable dead code (kept, documented).
* `routes/console.php` hardcodes `created_by/assigned_to = 1` for scheduled tasks.
* `can_see_all_customers` defaults to `true` for new users at the schema level.
* N+1 patterns: `User::visibleCustomerIdsWithSubstitution()` loops `User::find()`; `mergeForm`/`contract_new` load all customers unpaginated. Acceptable at current data size.
* Campaign/ticket-reply mails are sent synchronously in the request; with many recipients this will hit timeouts — queueing recommended (`QUEUE_CONNECTION=database` is already configured).
* Uploads are stored on the `public` disk; documents/attachments are therefore directly reachable under `/storage/...` for anyone who knows the hashed path. Controller downloads are authorized, but consider moving to the `local` disk.

## 4. Verification

After the fixes in this branch:

* `php artisan migrate:fresh` — all 22 migrations run cleanly on SQLite.
* `php artisan test` — full suite green (existing 25 tests + new tests covering the inquiry-token fail-closed behavior, portfolio scoping, ticket priority, and role changes).
* `php artisan route:cache` — succeeds (closures removed).

Every fix is an isolated commit with an explanatory message.
