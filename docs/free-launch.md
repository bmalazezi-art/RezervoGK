# RezervoGk — free-launch completion report

Implementation verified on 24 October 2026; final continuation verified on **25 October 2026**. This is the current access/billing specification. Older M6/M7 and localization reports describe historical paid behavior and do not authorize activation. The continuation reviewed the existing implementation, reran checks and completed documentation; no application reimplementation or further retained-database mutation was needed.

## Current behavior

- Platform use is completely free for goalkeepers and organisers. Registration creates a securely hashed account and published profile without starting an expiring trial. Organisers still browse and contact without accounts.
- `GoalkeeperEligibility::publicProfiles()` requires an existing owner, `is_published = true` and `is_suspended = false`. Trial and subscription dates do not restrict visibility. Home, browse, combined city/date/format/currency/price searches and direct URLs share this query.
- Expired accounts remain public when those conditions are met and retain login/editing. Unclaimed demos, unpublished profiles and suspended profiles remain stored but hidden; their public URLs return 404. Owners cannot set publication/suspension flags through registration or profile requests. No moderation UI or role promotion was added.
- Match prices, country currency permissions, integer minor-unit storage, cash settlement, photos, contacts, dated availability and indicative weekly/overnight hours remain unchanged. There is no FX conversion.
- SQ/EN public, registration, pricing, help, dashboard, draft policies and password-reset footer copy describe free access. Existing user-generated profile content is unchanged. Historical billing translations/templates remain dormant with their implementation.

## Billing remains disabled

`config/marketplace.php` fixes `free_launch` to true; this is deliberately **not an environment switch**. `LaunchModel` defaults to free even if its setting is missing. Bank configuration, SMTP configuration, old subscriptions and admin roles cannot automatically enable billing.

The global `DisableLaunchPayments` middleware returns 404 before authentication/binding for `/payments`, all descendants and `/admin/payments` and descendants. Tests include encoded paths, write requests, missing IDs, guests, keepers and admins. Controllers and `ManualPaymentService` also reject operations. Receipt downloads remain disabled without reading a receipt or writing a download audit. Existing receipt files remain private.

Dashboard responses omit trial, payment-history and bank-detail props; financial panels and payment-admin navigation are hidden. `BankTransfer::configured()` remains false even under test-only fully configured banking settings. The transfer email command exits without changing old outbox rows, including when `--retry-failed` is supplied; its sender is disabled and no transfer task is registered with the scheduler. `schedule:list` confirms no scheduled tasks in this release. Password reset is independent of the transfer outbox and retains its guarded SMTP configuration.

Payment controllers, services, private storage rules, audits, migrations, models and approval/idempotency tests are preserved. Historical paid-flow tests explicitly select legacy mode only inside isolated test processes. No existing payment, subscription, trial date, role, receipt or bank setting is deleted or rewritten. A future paid model requires separate authorization and a reviewed release covering configuration, data semantics, copy and security; environment values alone cannot activate it.

## Database migration and recovery evidence

The additive `2026_10_24_000000_add_profile_publication_controls.php` migration adds two protected moderation fields, defaulting to published/not suspended to preserve previously implicit publication. Ownership continues to hide demos. It does not rewrite original profile fields or historical billing rows.

**24 October retained MariaDB verification:** a protected full application-schema SQL backup, original-column manifest and all-table fingerprints were recorded before migration in:

`storage/app/backups/free-launch-20261024_105831-2a6bd6/`

Only the publication-control migration was applied to the retained schema. Original columns/rows were compared successfully across all 20 original tables; only the new migration ledger entry and the two additive fields were expected. The schema then contained one account, five profiles, zero admins, zero payments and zero subscriptions; one owned profile was public and no unclaimed demo was public. The unrelated payment-email outbox migration was left pending.

The restricted application user could not create a separate QA database (**MySQL error 1044**). No users, passwords or grants were changed. The fallback verified migration up/down/up on a newly created, uniquely prefixed disposable profile table on the same MariaDB engine, compared original fields and removed only that new QA table. Source-table preservation was checked. This proves the narrow migration/rollback, **not a full database restore**.

**The full free-launch backup restore is unverified.** No complete import into an independently isolated database, all-table restore comparison, restored-file rehearsal or measured recovery time was completed for this backup. Earlier M7 restore drills concern earlier snapshots and do not satisfy this requirement. SQL file existence, checksums and permissions do not prove recoverability.

**25 October continuation:** all three backup files remain present, nonempty and mode 0600 in a mode-0700 directory. Their SHA-256 hashes were recorded and file readability/permissions checked; these checks do not verify restoration. The configured local MySQL endpoint is currently unreachable (**error 2002: connection refused**), so the retained migration ledger, counts and fingerprints could not be freshly revalidated. The 24 October evidence above is historical, not a fresh claim. No retained migrations, seeders, restores, service repairs, system-catalog repairs or grant changes ran during this continuation. The previously recorded MariaDB 1558 catalog/version mismatch remains unresolved separately; this is not a new 1045 credential failure.

Fresh verification applied the complete 11-migration set to a new private disposable SQLite database, seeded only synthetic/demo QA fixtures there, and exercised the app through a loopback-only server. The regression suite additionally verifies publication migration down/up preservation in memory. Oracle MySQL 8.4 and a current full retained-backup restore remain unverified.

Evidence: [database record](free-launch-database-verification.json), [final check summary](qa/free-launch/final-verification.json).

## Checks and browser QA

| Check | Final result |
| --- | --- |
| `php artisan test` | **233 passed, 3,672 assertions**; forced isolated in-memory SQLite |
| Free-launch feature coverage | 16 cases: SQ/EN registration, hashed passwords/no trial, old trial states, 400-day future visibility, protected flags, all public surfaces, currency/date filters, payment/receipt/admin HTTP guards, direct-service guards, unchanged historical payments/outbox/receipts and role preservation |
| Dormant billing regressions | Approval, rejection, idempotency, receipt isolation and email safety retained and passing in explicit test-only legacy mode |
| `npm run test:localization` | **7 passed**; catalog coverage, interpolation, unchanged user content, dates/currencies and missing-SQ-Intl fallback |
| `npm run typecheck` | Passed |
| `npm run build` | Passed; nonblocking 516.05 kB main-chunk advisory above 500 kB |
| `vendor/bin/pint --test` | Passed |
| `composer validate --strict` | Passed |
| Full fresh QA schema | All 11 migrations pass on a separate private SQLite database |
| Source styles/environment/assets | All source CSS, `.env` and pre-existing public upload files unchanged during continuation; original Figma `app.css` matches its recorded original hash |

The existing 24 October browser matrix contains **54 public/auth visits** (9 pages × SQ/EN × 320/390/1280px) plus **6 expired-owner dashboard visits**, without overflow or language failures. Help, policies, pricing and auth copy describe the free model. A scan candidate in English Terms was manually reviewed: it states that bank payment/receipt functions are disabled and is not a paid offer. Registration was completed in isolated QA with an SQ-to-EN step switch; profile, dates and weekly hours persisted after refresh, without trial/payment/subscription creation. Currency/date search and unchanged profile content were checked. Keeper and synthetic-admin payment screens returned 404.

Fresh 25 October browser smoke checks confirmed English free pricing and locale persistence after refresh/navigation, the expired keeper in browse and at its direct URL, and 404s for suspended/unpublished/unclaimed profiles. The expired owner logged in successfully; EN at 390px and SQ at 320px showed the free dashboard with no paid panels or horizontal overflow. Keeper receipt/payment-admin URLs returned localized 404s. SQ pricing still showed free access at 390px. Admin/write-route denial and registration in both languages were rerun in automated tests. No real account was used as a QA admin and no real bank transfer or external email was sent. The temporary QA server was stopped and the browser viewport restored afterward.

Evidence: [original browser matrix](qa/free-launch/browser-checks.json), [final browser smoke checks](qa/free-launch/final-smoke-2026-10-25.json), [English desktop pricing](qa/free-launch/pricing-en-desktop.png), [final SQ pricing at 390px](qa/free-launch/pricing-sq-390-final.png).

## Remaining launch blockers and safe next steps

1. **Database availability and full recovery rehearsal.** Restore access to the intended local service through a separate operational step, then perform read-only migration-ledger/fingerprint checks. On an independently provisioned, approved empty recovery target with outbound email/payment activity blocked, restore the reviewed backup and matching file snapshot; compare every table, original data, constraints and migration ledger, verify photo/private-storage isolation, record elapsed restore time and recovery objectives. Do not import over the retained database, run seeders there, or expand application grants merely for this drill. If no isolated target is available, keep full restore marked unverified.
2. **Production infrastructure/security.** No deployment occurred. Verify the selected supported database engine, patched PHP web and CLI runtimes, Nginx/PHP-FPM, canonical HTTPS/proxy configuration, secure cookies, persistent storage, off-host encrypted backups and monitoring on the actual hosting target. The local PHP CLI is 8.4.12; the prior production requirement for patched PHP 8.4.27+ or a verified vendor backport remains unmet here. Resolve the historical MariaDB lifecycle/catalog issue through a separately planned operation, not an unreviewed local repair.
3. **Password-reset email delivery.** Real SMTP/sender configuration and controlled inbox verification remain outstanding. Transactional sending is still disabled; no credentials were invented or changed. Enabling password-reset SMTP must not enable transfer delivery.
4. **Owner-reviewed content.** Privacy/Terms remain drafts; actual operator/support details and policy approval remain required.

Bank details, payment administrators, live transfers, transfer-email outbox activation and subscription expiry are **not free-launch blockers or activation steps**. Keep them disabled. The historical [production runbook](production-deployment-runbook.md) now carries this free-launch override; its infrastructure guidance still applies, while its billing activation sections remain dormant.
