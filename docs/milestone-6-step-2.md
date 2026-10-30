# M6 Step 2 — Manual bank transfers and paid access

Completed 21 October 2026. The keeper dashboard supports manual €7 EUR bank transfers for exactly 30 days. Admins review submissions only after checking receipt of funds in the bank. Public access is an active trial **OR** an approved, currently active subscription period. Pending/rejected payments do not grant access. No gateway, automatic charging or automatic renewal was added. M7 was not started.

**Live operation remains disabled.** Real bank details have not been configured, and no existing account has been promoted to admin, as requested. The working QA preview at http://127.0.0.1:8007 uses a separate new MariaDB schema, dummy bank details and newly created test-only accounts. No actual transfer was made or real money verified during QA.

## Configuration and roles

`config/payments.php` reads these local environment settings, also documented in `.env.example`:

```dotenv
BANK_TRANSFER_ENABLED=false
BANK_TRANSFER_BENEFICIARY=
BANK_TRANSFER_BANK_NAME=
BANK_TRANSFER_ACCOUNT=
BANK_TRANSFER_BIC=
```

Issuing a reference and submitting a claim both require the enabled flag plus non-empty beneficiary, bank name and IBAN/account. BIC is configurable and displayed when provided. Missing configuration is shown in the keeper dashboard; the create button is disabled and both POST endpoints return 503. This also blocks submission against an already-issued draft while configuration is incomplete. Pending records can still be reviewed by an authorized admin. Configuration changes do not rewrite previously issued bank instructions: each payment preserves its own bank snapshot.

New `users.is_admin` defaults to false. It is outside mass assignment; registration/profile requests reject attempts to set it. The `review-payments` gate protects all admin list/review routes inside the existing authentication, session-invalidation, CSRF and private/no-store middleware. Admin login defaults to the review screen. A reviewer cannot approve or reject their own payment. There is no public role-assignment endpoint. Existing users/roles and local `.env` credentials/configuration were not changed during this continuation; real admin assignment is reserved for a later explicitly authorized operational step.

## Payment lifecycle

1. `POST /payments` issues an invoice-like draft with a unique `RGK-` ULID reference, frozen €7/700-cent amount, EUR currency, 2,592,000-second duration and bank snapshot. It does not move money. Issuance locks the owner row; repeat calls return the same outstanding draft/pending record.
2. The keeper performs a transfer in their own bank using that reference, then submits `paid_on`, an optional note and an optional private receipt to `POST /payments/{payment}/submit`. Owner checks apply before access to another account's record. The pending state preserves existing entitlement only; it never creates a new entitlement. Duplicate submission does not overwrite receipts or duplicate its audit event.
3. An authenticated admin checks the actual bank statement outside the app. Approval requires an explicit bank-verification attestation, exactly €7 received, a received date and a unique bank transaction identifier. A receipt or keeper claim is not accepted as proof of settled funds. Transaction identifiers are trimmed/normalized to uppercase and have a database unique constraint to prevent the same verified transaction being reused for another approval.
4. Approval creates one subscription period with a unique payment foreign key. It starts at the latest of approval time, remaining trial expiry or the latest approved subscription expiry, so early payment preserves existing time. Exactly 30 × 24 hours are added, independent of daylight saving changes. A period queued behind a trial/paid period is shown as scheduled; it does not independently grant access before its start.
5. Rejection requires a reason shown to the keeper and grants no entitlement. Approved/rejected decisions are terminal. Repeating the same final decision is idempotent; trying to reverse it returns 409. A rejected keeper can issue a new reference. No refund, reopening or reconciliation automation was added.

Approval locks the owner and payment in a consistent order, rechecks state after acquiring the locks and writes the decision, subscription and audit within one transaction. Unique payment/period and bank-transaction constraints provide additional protection. A failed audit/write rolls approval back. No paid period is created by receipt upload, pending status, or a keeper-supplied field.

## Central public eligibility and UI

`GoalkeeperEligibility` combines the trial query with approved subscription periods (`starts_at <= now < ends_at`). Home featured cards, browse and combined city/price/format/date search, plus direct URLs all continue using `GoalkeeperProfile::publiclyVisible()`. Direct URLs return 404 when neither access path is valid. Owner login, profile/availability editing and payment history remain accessible after expiry.

The dashboard reuses existing panels, form controls and buttons. It shows trial status separately from paid active/scheduled/expired status, approved expiry and payment history/rejection reasons. Public-profile buttons follow combined eligibility. Amounts and trial/admin/subscription fields cannot be supplied by the keeper to activate access. Existing data and layout are retained; the original Figma CSS file is byte-identical.

## Private receipt isolation

- A dedicated `receipts` disk uses `storage/app/receipts-private`, outside both the public web/storage roots and Laravel's generic served local disk (`storage/app/private`). Its automatic serving is disabled and file/directory visibility is private.
- Optional PDF/JPEG/PNG receipts are limited to 5MB. Images are limited to 3000 × 3000 and decoded/re-encoded to strip appended payloads/metadata. PDFs require matching MIME/type and a PDF header; the application does not execute or render their contents.
- Server-generated UUID filenames replace uploaded names. File paths are not serialized to the keeper/admin UI; only an authorized download route is supplied. Failed submission/database transactions clean up new files. Pending retries do not replace existing receipts.
- `GET /payments/{payment}/receipt` permits only the owner or an authorized admin. Others receive 403; guests must log in. Downloads are attachments with a fixed safe filename, no-store/private caching, `nosniff` and a restrictive sandbox CSP. Each authorized download is audited.
- Unsigned generic storage requests cannot serve the receipt. A regression test also verifies that a valid signed URL for generic local storage cannot expose the separate receipt disk. Configuration-root checks cover the production receipt path, before test faking changes disk roots. The actual uploaded QA receipt was verified outside all served roots with owner-only file permissions.
- Receipts remain untrusted documents; there is no automatic receipt interpretation, funds verification or malware-scanning service. Approval requires an independent bank check.

## Audit and preservation

The additive eighth migration introduces `manual_payments`, `subscriptions`, `payment_audits`, and the default-false admin flag. Financial records use restrictive foreign keys to retain decision history. Issuance, submission, approval/rejection and authorized receipt downloads record the actor and server time. Approval audit metadata includes verification attestation, received amount/currency/date, bank transaction reference, subscription ID and exact period. Audit mutation through model update/delete is rejected; no mutation endpoints exist. Admin views are authorized and private.

Before/after fingerprints verified that pre-existing application rows were preserved when the migration ran. A separate final-QA baseline comparison verified every existing application table and all roles unchanged while QA used its own new schema. The configured application database still has zero admin accounts and no payment/subscription/audit rows. No existing keeper/account was used as a test admin, no demo seeder was rerun on existing work, and no existing database/user grants or passwords were altered.

## Verification

| Check | Result |
| --- | --- |
| Full PHP regression suite | **114 passed, 1,588 assertions**; forced SQLite `:memory:`, 13.08 seconds |
| Bank disabled/incomplete | Creation and submission blocked, including each missing required bank field with the enable switch on |
| Ownership/admin authorization | Cross-owner access, guest routes, keeper review, role spoofing and self-review rejected |
| Private receipts | Authorized attachments, audited download, invalid/oversized/disguised files rejected, image re-encoding, PDF/no-receipt claims, rollback cleanup, signed generic-storage isolation |
| Approval | Verification attestation, €7 amount, unique transaction, terminal/replayed decisions, exactly-once grant |
| Expiry/extension | Exact expiry boundary, last microsecond, queued renewal, trial-to-paid transition and renewal after a gap |
| Audit | Approval rollback on audit failure; append-only model enforcement |
| Local MariaDB | All 8 migrations and seeders passed in the new isolated QA schema |
| Native concurrency | **Two simultaneous approval workers passed: one period, one approval audit, exactly 30 days appended** |
| `npm run typecheck` | Passed |
| `npm run build` | Passed, 795 modules |
| `vendor/bin/pint --test` | Passed |
| `composer validate --strict` | Passed |
| Existing application data/roles | Fingerprints unchanged during final QA; live admin count 0 |
| Original Figma CSS | Byte-identical; SHA-256 `b7947b500d3c8fad440f8e3a6015b82e373decd01339c032732a6112e20ea6bf` |

The full HTTP suite remains isolated to SQLite. Native MariaDB tests separately exercise real row locks/concurrent approval; the production/default database is not used for simulated approvals. Machine-readable results: [m6-step2-verification.json](m6-step2-verification.json).

## Browser QA and confirmed fixes

Used dummy bank configuration and test-only keeper/admin accounts on the separate MariaDB preview at port 8007:

- Keeper resumed a pending claim with a private uploaded receipt and an elapsed trial: no public access was granted. Reference and bank instructions persisted.
- Keeper navigation to `/admin/payments` returned 403. Admin login opened the protected review screen; its receipt download completed successfully.
- Admin simulated a verified transfer, recorded the unique QA statement identifier and approved it. The approved view showed the period and complete actor/decision audit. No real funds were transferred or verified.
- The two-worker native test extended that account once for a second QA payment. Keeper UI showed active paid access and both periods. Its public profile loaded successfully despite the expired trial.
- A further claim without a receipt was submitted and rejected through the browser. Entering a partial amount first, then switching to rejection, succeeded after the confirmed fix. The keeper saw the rejection reason; previously approved access remained intact.
- Checked desktop 1280 × 720 and mobile 390 × 844. Keeper/admin panels measured 354px at mobile width and wrapped references/bank details without widening the layout. Restored the viewport after QA.

Two confirmed continuation fixes were made: approval-only hidden fields are excluded from rejection validation (a stale €6.99 amount had incorrectly blocked rejection), and payment/audit dates use explicit `dd.mm.yyyy HH:mm` formatting in Europe/Belgrade (the browser's unsupported locale had fallen back to ambiguous month/day dates). No redesign or stylesheet edit was needed.

Screenshots: [keeper pending](qa/m6-step2/keeper-pending.png), [admin approval/audit](qa/m6-step2/admin-approved.png), [keeper approved](qa/m6-step2/keeper-approved.png), [admin mobile rejection](qa/m6-step2/admin-mobile-rejected.png), [keeper mobile](qa/m6-step2/keeper-mobile.png). These contain synthetic QA information only.

## Operational status

Real bank values and an explicitly provisioned administrator are still required before live use. The existing `.env` remains unchanged and live submissions remain disabled, as requested. There is no gateway, automatic charging, automatic renewal or M7 work.

Local error 1045 remains resolved on **MariaDB 10.4.28**. **Oracle MySQL 8.4 remains unverified** because no separate instance/Docker CLI is available. No production deployment or real bank verification took place.
