# RezervoGk

Laravel 12 + React 19 + Inertia.js 2 + TypeScript + Tailwind CSS 4, configured for MySQL.

RezervoGk now uses a completely free launch model. Owned, published, non-suspended goalkeeper profiles remain public regardless of old trial or subscription dates. Unclaimed demos, unpublished profiles and suspended profiles remain hidden. Public browsing and WhatsApp/phone contact remain account-free. Payment, receipt and payment-admin screens are hidden and their endpoints return 404 for every role. Dormant billing code and historical data are preserved; bank/SMTP environment settings cannot reactivate payments. The original Figma stylesheet and responsive breakpoints are unchanged. See the [current free-launch report](docs/free-launch.md); older milestone reports describe historical behavior.

M7 Step 1 completed a pre-launch audit and fixed password-reset host poisoning. M7 Step 2 removes misleading public reviews, ratings, verification claims and usage statistics and adds working help/contact and draft policy pages. Full SQ/EN localization now covers the application. Launch still requires owner-approved policies/contact details, production email/configuration and operational verification. See the historical [M7 Step 2 report](docs/milestone-7-step-2.md) and [audit](docs/milestone-7-step-1.md). M7 Step 3 added opt-in SMTP transactional emails and a durable decision-email outbox. Password reset remains available when SMTP is configured; transfer email delivery is paused for the free launch. Production sending remains disabled. See the historical [M7 Step 3 report](docs/milestone-7-step-3.md).

The goalkeeper dashboard groups overview, recurring availability, profile editing and account settings. Weekly availability repeats in date search over a rolling 30-day window; owners can block dates or add one-off availability. Use `composer dev` or `sh bin/serve` so the project-scoped 5 MB PHP upload limit is loaded; a bare `php artisan serve` may retain PHP's lower host default.

## Run locally

Requirements: PHP 8.4+ with PDO MySQL and GD (EXIF optional for camera orientation), Composer, Node.js 22.12+ and npm. Lockfiles record the installed dependency versions.

```sh
composer install
cp .env.example .env
php artisan key:generate
npm ci
```

The existing workspace already has `.env` and an application key. Preserve that file when resuming this checkout.

Configure MySQL credentials in `.env`. A local MySQL 8.4 Compose service is provided:

```sh
docker compose up -d mysql
php artisan migrate
php artisan storage:link
php artisan db:seed
composer run dev
```

Open http://127.0.0.1:8000. The seeders insert/update four named demo profiles and sample dated availability; they do not create users. Unclaimed profiles are hidden under the free model too. Run seeders only against an explicitly disposable demo database. They are repeatable, but deliberately restore demo values when rerun. Do not rerun setup, key generation or seeders against this retained workspace.

For a production asset preview, use `npm run build` then `sh bin/serve --host=127.0.0.1 --port=8000`.

## Local database access and isolated preview

M5 resolved error 1045: the example-configured application account and schema did not exist on the running XAMPP MariaDB 10.4.28 server. A new isolated schema (`rezervogk_m5_20261021_2a70389a87c5`) and new restricted local account were created; `.env` now points to them. No pre-existing databases/users/grants were modified. M5 verified its six migrations, demo seeders and native constraints. M6 adds trial and manual-transfer migrations (eight total), with native expiry and concurrent approval checks verified on this local server. The application runs at http://127.0.0.1:8000. Credentials remain private in `.env` (0600).

The free-launch publication-control migration was applied on 24 October after a protected backup and isolated MariaDB table migration/rollback verification. Original columns and rows were preserved. The unrelated transfer-email outbox migration remains pending on the retained schema and is not needed for free access. A full restore of the free-launch backup is **unverified**: creation of a separate database was refused with error 1044, and grants were left unchanged. The 25 October read-only recheck encountered error 2002 (local MySQL service unreachable), so current retained-schema state could not be freshly confirmed. No retained migrations were rerun. See the [database evidence](docs/free-launch-database-verification.json) and [current report](docs/free-launch.md).

**Engine limitation:** this verifies MariaDB through Laravel's MySQL driver. Oracle MySQL 8.4 remains unverified; the Docker CLI is unavailable. See [M5 diagnosis and results](docs/milestone-5.md). Historical M3/M4 reports retain the limitations observed at those milestones.

An isolated SQLite preview can be run without changing `.env`:

```sh
python3 -c "open('database/m4-preview-new.sqlite', 'x').close()"
DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/m4-preview-new.sqlite" php artisan migrate --seed --no-interaction
DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/m4-preview-new.sqlite" APP_URL=http://127.0.0.1:8003 MAIL_MAILER=array SESSION_COOKIE=rezervogk_m4_preview php artisan serve --host=127.0.0.1 --port=8003 --no-reload
```

Build the frontend first with `npm run build`. `--no-reload` preserves the explicit database environment in the server child process. The preview database is ignored by version control and is separate from the in-memory test database. A separate `database/m4-preview.sqlite` was created for M4 QA at port 8003; M2/M3 databases were not modified. The commands above create another NEW file and fail if it exists. Stop the existing preview before reusing its port. The array mailer deliberately sends no email. Do not recreate a database that contains work you want to keep.

## Public routes and filters

| Screen | URL |
| --- | --- |
| Home | `/` |
| Browse | `/browse` |
| Profile | `/keepers/{id}` |
| Register (guests) | `/register` |
| Login (guests) | `/login` |
| Password recovery (guests) | `/forgot-password`, `/reset-password/{token}` |
| Dashboard (signed-in owner) | `/dashboard` |

Browse query parameters: `city_id`, `country_id`, `currency` (`EUR`, `ALL`, `MKD`), `max_price` (major units of the selected currency, up to two decimal places), `format` (`5v5`, `6v6`, `7v7` in the demo), and `date` (`YYYY-MM-DD`). Filters combine with AND; maximum price is inclusive. Every numeric price filter is scoped to one currency; no FX conversion is performed. General browse defaults to EUR with a 15 EUR limit. Selecting Albania or North Macedonia defaults to ALL or MKD (initial limit 10000), with an EUR option; Kosovo permits EUR only. The slider is supplemented by a numeric limit for decimal prices. These limits are independent nominal defaults, not exchange-rate equivalents. Example: `/browse?currency=ALL&max_price=1200.50&format=5v5`.

The header search applies the selected city and date; date availability comes from the recurring weekly schedule or an explicit available date, except for blocked dates. The availability window and date search are limited to 30 days. On mobile the existing Filtrat button opens the same controls in a native dialog. Pastro resets all implemented filters. Empty results and unknown profile IDs are handled. Country/city/format options come from the database.

## Structure

- `database/migrations/2026_10_20_000000_create_goalkeeper_marketplace_tables.php`: countries, cities, profiles, contacts, formats and pivot.
- `app/Models/`: Eloquent relationships. Match prices are integer minor units with an ISO currency code on profiles; phone numbers are international strings, separate from WhatsApp digits.
- `app/Http/Controllers/GoalkeeperController.php`: validated filters, eager-loaded queries and public profile lookup.
- `app/Http/Resources/GoalkeeperProfileResource.php`: explicit frontend payload.
- `database/seeders/GoalkeeperDemoSeeder.php`: repeatable demo fixtures.
- `resources/js/pages/`: marketplace and authentication Inertia pages.
- `resources/js/types/goalkeeper.ts`: typed database payloads.
- `resources/css/app.css`: byte-identical original Figma CSS.

## Goalkeeper match currencies

New Kosovo profiles default to EUR only; Albania defaults to ALL with EUR allowed; North Macedonia defaults to MKD with EUR allowed. Owners select a permitted currency for their profile country and enter the amount explicitly. Existing EUR prices are preserved. Integer minor units and an ISO currency code are stored in the existing fields. Cards, profiles, inquiry messages, dashboard and filters display the correct currency. Platform access is free; goalkeeper match prices and cash settlement remain unchanged. See the [currency implementation and QA report](docs/multi-currency-pricing.md).

## Recurring availability

Owners select weekdays and one start/end interval per day. Selected weekdays count as available in date searches over the next 30 days. A date exception can mark a scheduled day unavailable, or add availability on an unscheduled day. Earlier end times continue into the next day; use `00:00` for midnight. Public profiles show the recurring schedule and generated upcoming dates. Apply the weekly-hours and availability-exceptions migrations on new deployments; do not reset existing databases.

## Free access and historical trials

New registrations do not start an expiring trial. The centralized public query requires an owner, `is_published = true` and `is_suspended = false`. Trial/subscription dates do not restrict home, browse/date search or direct profile URLs. Owners retain dashboard editing even when unpublished or suspended; they cannot change these protected moderation fields through registration/profile requests. There is no new moderation UI.

Existing trial dates are retained without rewriting them. The earlier trial implementation and its isolated regression coverage are preserved for possible future review; [M6 Step 1](docs/milestone-6-step-1.md) is historical. Unclaimed demo profiles remain stored and hidden. Do not claim demo profiles or alter roles as a deployment shortcut.

## Dormant manual bank transfers

The former €7/30-day bank-transfer implementation, private receipts, audit records, subscription periods and idempotent approval code remain stored. They are inaccessible during the free launch. Global middleware, controllers and services reject payment operations with 404, including receipt downloads and admin review. Dashboard responses omit financial props and payment-admin navigation is hidden for every role.

`config/marketplace.php` deliberately fixes `free_launch` to true. It is not controlled by `.env`; configuring bank details, enabling a bank flag, assigning an admin or configuring SMTP cannot turn payments back on. The transfer worker is unscheduled and exits without touching pending/failed outbox rows. Future reactivation requires a separately authorized, reviewed release. Do not enable bank settings or provision admins for the free launch.

Historical receipts remain private in `storage/app/receipts-private`, outside public and generic served local storage; all download routes are disabled. See the historical [M6 Step 2 report](docs/milestone-6-step-2.md) for the retained workflow and storage isolation. Free-launch QA uses disposable SQLite data only.

## Goalkeeper authentication

Registration has two steps: the original profile fields, then email/password/confirmation. It creates the user, profile, contact details and format links in one transaction. Passwords use Laravel hashing; passwords require 12+ characters, mixed case and numbers, and fit bcrypt's 72-byte limit. Email uniqueness, phone formatting, location/format selection, prices and optional JPEG/PNG uploads (5 MB maximum) are validated. Failed profile creation rolls back the account and cleans up the uploaded file.

Each user can own one profile through a nullable unique `goalkeeper_profiles.user_id` foreign key. Existing demo profiles remain unclaimed; signup cannot claim a profile by matching its name or phone. `/dashboard` derives its profile exclusively from the session user. `PATCH /keepers/{id}` requires the profile ownership policy. Owner/verification fields are not editable through this endpoint.

Login and registration rotate sessions. Logout is POST-only, invalidates the session/CSRF token and clears encrypted Inertia history. Protected responses use `no-store`; password changes invalidate existing protected sessions. Registration/login/recovery/reset endpoints are rate-limited. Use HTTPS outside localhost; production defaults to secure session cookies, and encrypted history uses the browser's secure-context cryptography.

Recovery uses Laravel's password broker with hashed, expiring, single-use tokens. Known/unknown addresses and transport failures receive the same generic response. Transactional mail is opt-in; log/failover transports are refused, and reset tokens are never intentionally logged or queued. Configure authenticated TLS SMTP, a verified sender and canonical HTTPS APP_URL through the approved deployment process. The retained local `.env` is unchanged and sending remains disabled. `mail:status` reports reset-mail readiness; transfer email delivery is paused and its pending migration is not required for password reset. See the historical [configuration and delivery report](docs/milestone-7-step-3.md).

## Activation preparation

M7 Step 4 previously verified the email-outbox migration on disposable MariaDB restores. Those earlier drills do not verify restoration of the newer free-launch backup. Run `php artisan launch:status --json` for a sanitized read-only preflight when the DB is available. It never authorizes or performs activation. Free launch does not require bank details, a payment admin or an active transfer outbox. The [historical admin/bank procedure](docs/milestone-7-step-4.md) must not be used to activate billing in this release.

M7 Step 5 documents hosting/runtime, HTTPS, storage, scheduler, backup and rollback requirements without deploying or changing retained data. See the [readiness audit and blockers](docs/milestone-7-step-5.md) and [production deployment checklist/runbook](docs/production-deployment-runbook.md). Current runtime security patching and the MariaDB catalog/lifecycle issue remain launch gates.

## Remaining prototype features

Sorting, favorites, review submission and email/phone verification workflows are not implemented. Public testimonials, aggregate reviews/ratings, match counts, verification badges and invented usage statistics have been removed. Legacy seeded metadata remains stored but is excluded from public keeper resources. New accounts are not marked verified. Albanian is the default; the functional SQ/EN switch persists across navigation, refresh, login and logout. Public help pages are available at `/how-it-works`, `/faq` and `/contact`; `/privacy` and `/terms` are clearly marked owner-review drafts. Official support contact details still need to be supplied.

Dashboard editing includes bio, experience, languages, price, formats, phone, WhatsApp, photo upload/removal, and dated availability. A saved date means available for inquiries; missing dates are unconfirmed, and exact times are agreed directly. The calendar uses Europe/Belgrade dates and a one-year publishing horizon. Home featured profiles come from the database. Platform access is free and historical billing is dormant. Match payments stay cash.

## Checks

```sh
npm run typecheck
npm run test:localization
npm run build
php artisan test
vendor/bin/pint --test
composer validate --strict
```

PHPUnit forces an isolated SQLite `:memory:` database through `phpunit.xml`. Tests migrate and seed it and cover authentication, reset tokens, CSRF, session/history behavior, profile ownership, secure photo processing/cleanup, dated availability, timezone boundaries and combined public filters. They do not touch MySQL or the preview database. The full HTTP suite remains isolated to SQLite. M5 separately verified migrations and constraints on local MariaDB; Oracle MySQL 8.4 remains unverified.

See [the current free-launch report](docs/free-launch.md) for final verification, retained-database/restore limitations and launch blockers. Earlier milestone notes remain historical records of the preserved implementations.
