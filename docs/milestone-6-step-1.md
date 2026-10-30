# M6 Step 1 — 30-day goalkeeper trial

Completed 21 October 2026. One server-owned 30-day trial starts in the same database transaction as goalkeeper registration. Expired accounts retain login, recovery, dashboard, profile editing and availability management; their profile is unavailable through public entry points. No payment integration, subscription activation or renewal endpoint was added.

## Rules and implementation

- `users.trial_started_at` and `trial_ends_at` are nullable immutable-datetime casts, outside mass-assignable account fields. Both registration and profile requests reject supplied trial timestamps. Registration invokes `GoalkeeperTrial::start()` inside its existing account/profile transaction; a failure rolls everything back.
- The trial lasts **2,592,000 seconds (30 × 24 hours)** from a UTC timestamp at second precision. It does not use calendar-month arithmetic, so daylight saving changes cannot extend or shorten it.
- Starting is a conditional database update requiring BOTH timestamps to be null. Repeated/concurrent calls cannot renew an initialized trial. Login, logout, profile/availability changes and password recovery do not start or extend trials.
- `app/Support/GoalkeeperTrial.php` centralizes the clock, eligible-account query, public-profile query and dashboard status. `GoalkeeperProfile::publiclyVisible()` is an explicit local query scope, not a global scope, so it cannot prevent owner editing.
- Eligibility is `trial_started_at <= now < trial_ends_at`. The exact expiry instant is excluded. Missing, partial or future trials fail closed; profiles without owners fail closed.
- Home featured profiles, browse (including city/price/format/date combinations and the available-today filter), and direct public profile URLs all use this shared eligibility scope. Ineligible direct URLs return 404 even to the signed-in owner. Owner editing stays in the protected dashboard.
- Remaining days are the ceiling of positive remaining seconds divided by 86,400, clamped to zero when ineligible. A fresh trial shows 30, the last partial day shows 1, and expiry shows 0. Values come from the server on requests; no scheduler or browser timer is required to enforce expiry.

## Existing data and approved legacy handling

The user selected: **anchor existing accounts to their original registration date; hide unclaimed demos**.

The additive migration `2026_10_21_000001_add_goalkeeper_trial_to_users.php` adds two timestamps and an expiry index, then backfills existing users from their original UTC `created_at`, preserving all other fields and `updated_at`. It does not grant a fresh trial at migration time. A missing registration timestamp stays uninitialized and hidden. Unclaimed demo profiles, photos, contacts, locations, formats and availability remain stored; they now have no qualifying owner and are intentionally hidden publicly. Demo seeders were not rerun on existing data, and no profiles were claimed or deleted.

The migration was applied to the configured isolated M5 MariaDB schema with its normal restricted application account. That database had zero existing account rows. All four existing profiles, four contacts, eight format links and five dated entries remain present. Earlier six migration records are retained; the new migration is number seven. An initial verification script mistakenly compared the expected migration-history addition as an application-data change because native table names were schema-qualified. That check was corrected; no application-record discrepancy was identified. Backfilling a historical account and preserving its original fields/ownership is exercised by the isolated migration test.

Live MariaDB eligibility checks also covered six timing cases, including one microsecond before expiry, exact expiry and one microsecond afterward. They used a transaction-only synthetic user/profile; rollback restored existing user/profile counts. No credentials, grants or existing user passwords changed.

## Dashboard and responsive UI

The existing status card and trial ring show remaining days, active/expired status and the expiry time in Europe/Belgrade. Expired owners see a clear hidden-profile explanation and can keep editing. Public-profile buttons are disabled while ineligible.

The original Figma stylesheet remains byte-identical, with its breakpoints and colors unchanged. Browser QA found an existing intrinsic grid-width issue: the calendar widened the entire mobile dashboard and clipped the new status text. Adding Tailwind `min-w-0` to the two dashboard grid children confines the existing horizontally scrolling calendar to its panel. No redesign or stylesheet rewrite was required. At 390px the trial panel is now 354px wide and fully readable.

## Checks

| Check | Result |
| --- | --- |
| `php artisan test` | **96 passed, 1,328 assertions**; forced SQLite `:memory:` |
| Trial boundaries | Before start, start, one second later, one day elapsed, last full day, one microsecond before expiry, exact expiry, one microsecond afterward |
| DST interval | March-to-April interval remains exactly 30 × 24 hours |
| Entry-point parity | Home, browse, combined date filters, direct URL, owner dashboard agree at every tested boundary |
| Security/continuity | Trial spoofing rejected; no renewal on login/edit/repeated start; expired owner can edit profile and availability |
| Migration | Additive backfill and original-field/ownership preservation tested; local MariaDB migration passed |
| Native MariaDB boundary query | Six cases passed; fixture rolled back |
| `npm run typecheck` | Passed |
| `npm run build` | Passed, 792 modules (rerun after mobile fix) |
| `vendor/bin/pint --test` | Passed |
| `composer validate --strict` | Passed |
| Original `resources/css/app.css` | Byte-identical to ZIP `src/index.css`; SHA-256 `b7947b500d3c8fad440f8e3a6015b82e373decd01339c032732a6112e20ea6bf` |

Existing M2–M4 public test fixtures now explicitly attach eligible test owners. Production/demo seed behavior remains unchanged: unclaimed seed profiles are not quietly turned into accounts.

## Browser QA

Used a newly created `database/m6-preview.sqlite` at http://127.0.0.1:8006 with a dedicated session cookie and array mailer. A synthetic account/profile was prepared using the same trial-start service; HTTP tests exercised actual registration and atomic initialization. No credentials are included in the report or screenshots.

1. Owner login opened a dashboard showing **30 days remaining**, active status and a localized expiry time.
2. After logout, guest city/date search found only that active keeper and opened its direct profile URL.
3. Only the disposable SQLite QA account was set to an elapsed trial. Reloading the public profile returned **404**; the same date search returned **zero results**.
4. The expired owner signed in successfully. Dashboard showed **0 days**, hidden status and disabled public buttons. Saving the biography succeeded; adding a dated availability entry also succeeded. Reload retained the edits and expired status.
5. Checked desktop 1280 × 720 and mobile 390 × 844. Confirmed the mobile grid correction using actual element widths and a readable status message; restored the viewport afterward. Final browser log inspection contained no captured warnings/errors.

Evidence: [active trial](qa/m6/active-trial.png), [expired owner dashboard](qa/m6/expired-dashboard.png), [expired public 404](qa/m6/expired-public-404.png), [mobile expired trial](qa/m6/mobile-expired-trial.png). Machine-readable evidence: [database verification](m6-database-verification.json).

## Database status and limits

The configured local **MariaDB 10.4.28** connection is working; error 1045 remains resolved from M5. This migration and native expiry checks passed there. **Oracle MySQL 8.4 remains unverified** because no separate instance/Docker CLI is available. The full HTTP suite remains isolated on SQLite, and browser expiry mutations stayed in the new SQLite preview. No production deployment, payments, paid subscriptions, automatic renewal or reactivation workflow is implemented.
