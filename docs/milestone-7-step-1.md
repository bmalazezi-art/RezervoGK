# M7 Step 1 — Pre-launch audit

Audit completed 21 October 2026. **Launch is blocked by the items below.** Reviewed application code, effective local configuration, automated regression coverage and the isolated browser preview. One confirmed account-recovery security bug was fixed. No frontend source, CSS, migrations, existing application rows, credentials, bank configuration or roles were changed. Live transfers remain disabled and the configured application has zero administrators.

## Confirmed critical fix

**Password-reset host-header poisoning / account-takeover risk.** Recovery email links previously inherited the request origin. An isolated HTTP regression sent a recovery request to `http://untrusted.example.test` while `APP_URL` specified `https://keepers.example.test`; the notification used the untrusted host. If a deployment accepted that Host header and the recipient followed the email, the token could be disclosed to that destination.

`AppServiceProvider` now builds reset notification links from the configured `app.url` plus a relative named route. The token and email still use Laravel route encoding; broker hashing, expiry, single-use consumption and password hashing are unchanged. The new regression failed before the fix and passes afterward, checking the configured host, HTTPS scheme, reset path and email query. No email was transmitted and no existing account password was reset. A correct HTTPS production `APP_URL` is now an explicit deployment prerequisite. Host allowlisting at the production web server remains an operational defense to verify.

## Launch blockers

| Priority | Finding / evidence | Required before launch |
| --- | --- | --- |
| P0 deployment | Effective configuration is `local`, debug enabled, HTTP loopback URL, secure cookies disabled in this local environment, debug-level logs. No production deployment was available to inspect. | Configure a separate production environment with debug off, the canonical HTTPS URL, secure cookies, protected secrets and a web root restricted to `public/`. Verify TLS, host allowlisting, proxy configuration and error responses on that deployment. Do not publish this local configuration. |
| P1 recovery | Mailer is `log` and sender uses an example address. Recovery emails are recorded locally rather than delivered. | Configure a real mail transport and sender; verify domain authentication and inbox delivery, correct HTTPS reset links, expiry and reuse rejection in staging. Protect development logs containing reset tokens. No live mail test was performed. |
| P1 paid operation | Required bank configuration is absent and live admin count is zero. This is the requested safe state. | Obtain and independently verify business bank details, approve an admin-provisioning process and reconciliation procedure before explicitly enabling transfers. No live transfer, promotion or bank verification occurred in this audit. |
| P1 content/trust | Home advertises 340+ active keepers, 18 cities, 1,200 matches, 4.9 ratings and verified identities/phones. Profile and authentication screens contain fixed testimonials. Profile testimonial claims a verified review even when the actual account has no reviews. No review or identity/phone-verification system implements those promises. | Approve truthful launch content; remove/label prototype statistics/testimonials and unsupported verification claims in a separately authorized content change. Seeded demo metadata must never be presented as genuine customer history. |
| P1 policies/support | Privacy/terms in the footer are plain text; signup refers to those unavailable documents. FAQ, contact and support buttons have no handlers. No receipt retention/deletion or user account-removal workflow is implemented. | Supply accessible policy/support pages and define contact, retention and deletion procedures for accounts, public contact details and private receipts. This audit does not assess legal compliance. |
| P1 operations | No production backup/restore, private-file recovery, alerting or deployment evidence was supplied. Local MariaDB is the only native engine verified. | Verify production database access/migrations, least privilege, coordinated DB/receipt backups and restore, file ownership, monitoring, log retention and release/rollback procedure. Exclude QA credentials, fixtures and local previews from deployment. |
| Conditional language blocker | SQ/EN changes header labels only. Pages, forms, messages and footer remain Albanian; HTML language remains `sq`. Selection resets on full navigation. | If bilingual launch is intended, finish and QA translations and language persistence. Otherwise approve a clearly Albanian-only launch presentation. |

The configured application's public list currently contains zero eligible profiles: its four unclaimed demo profiles are intentionally hidden. This is correct eligibility behavior and existing data was preserved. Plan real keeper onboarding and seed reference locations/formats separately from demo profiles; do not run the demo seeder against retained or production data.

## Security, authentication and payments

- Registration uses validated fields, transactional account/profile creation and hashed passwords. Owner, admin, verification, trial and paid-access fields cannot be supplied to gain privileges. Login/registration rotate sessions; logout invalidates sessions/CSRF state. Existing protected sessions are invalidated after password changes. Recovery responses are generic, and broker tokens are hashed, expiring and single-use.
- Web writes retain CSRF protection. Authentication/recovery/payment endpoints have throttles. Policies enforce profile/availability ownership; admin payment routes require the server-side gate. Public browse/contact requires no account and returns an explicit resource payload rather than account credentials or internal ownership fields.
- Shared eligibility covers home, browse/date filters and direct profiles. Trial or approved subscription must cover the current instant. Pending/rejected claims never create access. Expired keepers retain login/editing.
- Approval requires an admin other than the payer, explicit bank-verification attestation, exactly €7 EUR and a unique bank transaction identifier. Transactions, consistent row locks and unique constraints enforce one 30-day grant per payment. Replay, expiry boundaries, early extension, rejection and rollback coverage all passed. A receipt alone cannot approve payment. The audit reran HTTP regressions; the prior M6 native two-worker MariaDB concurrency check was not rerun because the payment service did not change.
- Model-level audit events reject update/delete and no audit-edit endpoint exists. This is not tamper-proof storage against an application/database administrator; least-privilege access and external backup/log retention remain operational responsibilities.
- Admin MFA and recent-password confirmation for approvals are not implemented. Establish the admin security standard before exposing financial review; no role or authentication expansion was made here.

## Private uploads

Receipt storage is a separate non-served disk at `storage/app/receipts-private`, outside the public root, public storage symlink and generic served local disk. UUID filenames replace client names; paths are not serialized to UI. Download is owner/admin-only with attachment disposition, no-store caching, `nosniff`, sandbox CSP and an audit event. Tests verified guests/cross-owner denial and that even a valid signed URL for the generic local disk cannot expose receipts.

JPEG/PNG photos and receipt images have size/dimension checks and are decoded/re-encoded. Failure cleanup and ownership tests passed. SVG and disguised uploads are rejected. PDFs are limited by size, detected type and header; they are untrusted attachments, with no malware scanner or content-disarm service. Downloads should be handled as untrusted documents. No new upload was written during browser auditing, and no real receipt was opened.

## Browser and mobile review

Used the existing **isolated MariaDB QA preview on port 8007**, dummy bank details and existing test-only accounts. No existing application database was used for simulated activity. Checked desktop/default viewport and 390 × 844 mobile rendering; restored the viewport and logged the test admin out afterward.

- Home navigation and mobile menu worked. SQ/EN visibly changed navigation but left page content untranslated, confirming the language limitation.
- Browse search/filter controls fit mobile width. The native filter dialog opened; selecting `7v7` updated the query and displayed the correct empty state; dialog closed normally.
- Public profile, contact links and availability rendered; WhatsApp/phone destinations were inspected without sending messages or initiating calls.
- Keeper dashboard editing, trial/paid status and payment-history panels rendered at mobile width, including wrapped references and dummy bank instructions. No save, upload or payment creation was performed.
- Keeper access to `/admin/payments` returned 403. Logout worked; guest dashboard/admin visits redirected to login. The test-only admin signed in to the review screen and filtered approved records. Existing audit/payment data rendered without issuing a decision. Recovery screen was inspected without sending mail.

Evidence: [mobile profile](qa/m7-step1/profile-mobile.jpg), [mobile admin history](qa/m7-step1/admin-mobile.jpg). These show synthetic QA data. No mobile redesign or CSS edit was made. Physical iOS/Android, Safari, screen-reader and load testing remain unverified. Visible prototype limitations include inactive favorites/sorting, incomplete menu accessibility state and faint disabled calendar labels. These were recorded, not changed under the critical-fixes-only scope. Public pricing still says “per month”/“cancel anytime”; approve wording consistent with manual €7 per exactly 30 days and no automatic renewal before paid launch.

## Checks and preservation

| Check | Result |
| --- | --- |
| Full PHP suite after fix | **115 passed, 1,594 assertions**, 30.92 seconds; forced SQLite `:memory:` |
| New hostile-origin recovery test | Failed before fix; passed after fix (6 assertions) |
| TypeScript | `npm run typecheck` passed |
| Production assets | `npm run build` passed, 795 modules |
| PHP formatting | `vendor/bin/pint --test` passed |
| Composer manifest | `composer validate --strict` passed |
| Dependency advisories | `composer audit --format=json`: no advisories or abandoned packages; `npm audit --json`: zero reported vulnerabilities at audit time |
| Existing application data and roles | Read-only all-table fingerprint matches the prior M6 final-QA baseline before and after this audit |
| Configuration | `.env` fingerprint unchanged; permissions remain 0600; bank disabled/unconfigured; admin count 0 |
| Figma CSS | Byte-identical to ZIP `src/index.css`; SHA-256 `b7947b500d3c8fad440f8e3a6015b82e373decd01339c032732a6112e20ea6bf` |

Sanitized configuration evidence: [m7-step1-configuration-audit.json](m7-step1-configuration-audit.json). Dependency scans report known advisories, not proof that every dependency is secure. This was an application audit, not an external penetration test or production certification.

**MySQL status, separately:** error 1045 did not recur on the configured local MariaDB 10.4.28 connection during read-only verification. No database/user/grant changes or migrations were performed for M7. Oracle MySQL 8.4 and the intended production engine/deployment remain unverified. The full HTTP test suite used isolated SQLite; browser QA used the pre-existing separate MariaDB QA schema.
