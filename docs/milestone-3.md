# M3 — Goalkeeper authentication

Completed browser QA on 21 October 2026. M4 has not been started.

## Implemented

- Two-step registration using the existing profile form and styling, with email/password/confirmation in step two.
- Hashed passwords, normalized unique emails/international phones, validated locations/formats/prices, optional JPEG/PNG photo uploads, and transactional account/profile creation.
- A nullable unique profile owner foreign key. Existing demo profiles remain unclaimed; signup creates a new profile and cannot adopt an existing profile by name/phone.
- Login, POST logout, session regeneration/invalidation, remember-me, CSRF protection and rate limits.
- Laravel broker password recovery/reset with hashed expiring single-use tokens, generic email responses, and remember-token rotation.
- Protected owner dashboard and policy-controlled profile updates. Changing URL parameters cannot change the dashboard owner.
- Existing registration/dashboard markup and responsive classes retained. New auth forms reuse those classes. The CSS remains byte-identical to the Figma export.

## Browser QA

All browser actions used disposable fixtures in the separate `database/m3-preview.sqlite` database on port 8002. No existing MySQL or M2 preview data was changed.

| Flow | Observed result |
| --- | --- |
| Registration | Original profile step advances to credential step; successful submission creates the account/profile and opens the owner dashboard. |
| Owner update | Saving a name and price persists; the public profile shows those saved values. |
| Invalid login | Generic credentials error; password input clears. |
| Valid login | Opens the same account's persisted profile. |
| Logout | Returns to the public home page; direct dashboard access redirects to login. |
| Browser Back after logout | Initially restored stale dashboard data; fixed with encrypted Inertia history plus no-store responses. Recheck returns to login. |
| Recovery request | Browser submission generates the expected local-log email and shows a generic confirmation. Raw reset URL/token were withheld from tool output and reports. No external email was sent. |
| Password reset | Confirmation mismatch is rejected. A valid submission returns to login with success status. The old password is rejected; the replacement password opens the original owner dashboard. |
| Account isolation | A second independent fixture logs in to its own name/city/price. Supplying another keeper/user in dashboard URL parameters leaves the second owner's dashboard unchanged. |
| Public access after logout | Browse and contact links remain accessible without an account. No WhatsApp message or phone call was initiated. |

Direct attempts to update another user's or an unclaimed demo profile are covered by HTTP feature tests and return 403. Owner fields cannot be reassigned. Browser QA exercised ownership through the actual dashboard; no browser-side request forgery or test-only application route was added.

## Fixes from QA

1. Enabled encrypted Inertia history and added private/no-store headers for protected routes, so logout cannot restore an authenticated dashboard through browser Back.
2. Password-reset completion also clears history.
3. Added Albanian validation messages after the browser confirmation-error check revealed English fallback text.

Evidence: `screenshots/m3-dashboard.png`, `screenshots/m3-dashboard-owner-b.png`, `screenshots/m3-password-reset.png`. These contain only synthetic QA data and no passwords/reset tokens.

## Verification and safety

- Final result: **61 tests passed, 627 assertions**. PHP feature tests run only against forced SQLite `:memory:`. They include account persistence/hashing, validation, transaction rollback/upload cleanup, login/logout, throttling, CSRF, reset expiry/reuse, session invalidation, encrypted history, ownership denial and M2 regressions.
- TypeScript check, production build, PHP formatting and Composer validation pass.
- Original CSS SHA-256: `b7947b500d3c8fad440f8e3a6015b82e373decd01339c032732a6112e20ea6bf`.
- Browser QA was at desktop size; a full device/browser screenshot matrix was not performed. Existing responsive CSS is unchanged.

## MySQL status

Read-only connection probes still return MySQL error 1045, both with the configured database and without selecting a database. Configuration is not cached; effective settings match `.env`; `pdo_mysql` is available. This narrows the issue to account authentication/host grants rather than an absent migration. The exact account failure requires administrator verification.

No credentials were displayed or changed, no alternative/root credentials were guessed, no grants were modified and no MySQL database was migrated, seeded, cleared or recreated. MySQL-specific migrations and auth flows remain unverified. The main `.env` stays configured for MySQL.

The local mail transport generates recovery emails in a private log. SMTP delivery remains unverified. Configure real email delivery and HTTPS before deployment. Payments, subscriptions, real availability and verification/review workflows remain outside M3.
