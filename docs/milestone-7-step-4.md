# M7 Step 4 — Database verification and activation preparation

22 October 2026. **Preparation and isolated migration verification complete; live activation remains blocked.** No retained database rows, users, roles, bank settings, mail settings, UI or CSS were changed. No live messages or payments were sent. The email-outbox migration remains pending on the retained database.

## Concurrent database changes

The older M7/dashboard fingerprints differ in `goalkeeper_profiles` and `goalkeeper_availabilities` only. Users/roles, weekly hours, contacts, format links, payments, subscriptions and audits still match those historical fingerprints. The older evidence contains table hashes, not recoverable row snapshots: it cannot establish which fields changed or attribute an edit to a person. No stale snapshot was restored and no legitimate edits were reverted.

A new consistent snapshot established the current state: 20 tables, one user, five profiles, ten dated entries, five weekly entries, zero admins, zero payments and zero subscriptions. All stored profile-photo files exist; country/currency pairs, nonnegative prices and weekly weekday/interval checks passed. The current rows and schema matched this fresh snapshot both during backup verification and after focused QA. This establishes preservation during this work, not a claim that the older concurrent edits never happened.

## Private backup and native migration rehearsal

Backup location on this machine:

`storage/app/backups/m7-step4-20261022_162333_2d341c0f/`

The directory contains the retained-schema SQL backup, five stored assets, a checksum/table-fingerprint manifest, and a second SQL backup of the migrated disposable clone. This is a local application backup, not a backup of the entire database server or its user accounts. It excludes `.env`, APP_KEY and server/SMTP credentials. Preserve deployment secrets separately in the owner's secret store.

- Directories have owner-only permissions (0700), files 0600; the location is ignored and outside served public/private-local roots. Temporary native-client credential files were removed. Credentials were never passed as command arguments or printed.
- Every source table uses InnoDB. The app schema has no views, triggers, stored routines or events. The dump used a consistent transaction; pre/post source fingerprints matched, so no concurrent data/schema change was observed during this backup.
- Restored into an exclusively new, empty MariaDB schema. All restored table definitions and row hashes matched the source snapshot. No existing schema was used as a restore target.
- Applied only `2026_10_22_000001_create_payment_email_deliveries.php`. Existing table rows and old migration records were preserved; the new outbox was empty, with one additional migration record.
- A second migration run made no changes. Empty-outbox rollback/reapply passed on the disposable clone only.
- Native MariaDB rejected duplicate payment deliveries (1062) and orphan payment references (1452); pending/zero-attempt defaults and cascade deletion passed. Synthetic constraint fixtures were rolled back; no test admin was created in either restored schema.
- Backed up the migrated clone and restored it into a second new schema. All table definitions and row hashes matched again, including the outbox. The new outbox was empty; populated delivery/retry behavior is covered separately by the isolated HTTP tests.
- Both disposable schemas were removed. Original schema and database-account inventories were preserved. No user, password or grant changes were issued.
- Final SQL and asset checksums passed; source rows still match the fresh backup. No retained schema migration was applied.

These local backups are permission-protected, **not additionally encrypted by this workflow**. Encrypted off-host backup retention, recovery access, APP_KEY custody and production restore drills remain deployment responsibilities. Do not attach SQL backups, private assets or manifests with operational metadata to public tickets.

## Database limitations

Local PDO/MySQL access succeeds on **XAMPP MariaDB 10.4.28**; error 1045 did not recur. Oracle MySQL 8.4 remains unverified, and Docker is unavailable here. The full HTTP suite remains isolated to SQLite; the native checks above verify backup/restore, additive migration and database constraints on MariaDB.

Read-only inspection of `information_schema.ROUTINES` returned **1558**. Direct, scoped reads of `mysql.proc` and `mysql.event` confirmed no app-schema objects would be omitted from this backup. No system-table upgrade or repair was attempted. This error is consistent with an incomplete server/system-table upgrade, as described in [MariaDB's error 1558 documentation](https://mariadb.com/docs/server/reference/error-codes/mariadb-error-codes-1500-to-1599/e1558). Its cause/history and any server-wide repair need a separately approved maintenance plan and full-instance backup; this application-only backup is insufficient for that operation.

## Read-only activation preflight

Added `php artisan launch:status` and `php artisan launch:status --json`. Both only read configuration/schema/counts. They never migrate, assign roles, display bank/SMTP values, deliver email or enable a feature. Exit 1 means a check is blocked; exit 0 means configuration checks passed, **not authorization to activate**. JSON always includes `activation_authorized: false`.

Checks cover production environment, debug, canonical HTTPS URL, secure/HTTP-only cookies, bank-field presence, the existing strict mail configuration guard, private receipt roots/visibility, database access, admin count and installed outbox migration. Counts identify pending/processing and failed deliveries when the table exists. Receipt checks resolve paths and reject placement beneath public storage or the generic served local root.

Current retained configuration remains local/debug/HTTP without secure session cookies, required bank details, SMTP sending, an admin or the outbox migration. Bank submissions and transactional mail are both disabled. The report distinguishes the raw bank-enable switch from actual submission eligibility, so a switch without required details is still reported inactive. Presence/count checks do not verify an administrator's identity, account ownership, real bank details, external delivery, DNS or financial reconciliation.

## Remaining activation procedure — not performed

1. **Choose and verify the production target.** Confirm its exact engine/version, schema and restricted application grants. Rehearse on that version. Resolve the local 1558/system-table issue separately if this server will be retained; do not run an automatic server upgrade from the app. Configure production HTTPS, canonical APP_URL, APP_ENV=production, APP_DEBUG=false, secure cookies and protected secret/file permissions. Verify private receipt URLs and public photo storage on that deployment.
2. **Take a fresh verified deployment backup.** Coordinate a write/DDL maintenance window or another proven consistent deployment procedure. Inventory all pending migrations with `php artisan migrate:status`; stop if anything unexpected appears. Restore the fresh backup into a new empty isolated schema and compare it. Today's backup must not overwrite later owner edits.
3. **Deploy the additive outbox migration while bank/mail flags remain false.** After checking the target and fresh backup, the operator can run the narrowly scoped command below. No `migrate:fresh`, destructive rollback, seeders, backfill of old decisions or source restore is required. Verify the table, migration record and unchanged existing rows/counts. A code/schema mismatch currently prevents payment review from completing; transaction rollback prevents partial activation, but migration must precede real administration.
4. **Assign a named administrator only after explicit owner authorization.** Record the approved account ID, verified identity/address, purpose, operator and timestamp. Recheck the exact account and current role in a transaction through a protected server console; do not infer identity from profile name, signup inputs, a screenshot or a guessed email. Use a separately reviewed role-assignment change and retain its operational audit. No provisioning/promotion command or automatic first-user promotion was added. The app has no native admin MFA yet: implement/test the approved MFA/SSO or equivalent restricted administrative access before launch. Verify an ordinary keeper still receives 403 and that the administrator cannot review their own transfer.
5. **Verify real bank details privately.** The owner must independently confirm beneficiary, bank name, IBAN/account and any required BIC. Enter approved values through local `.env`/deployment secrets while `BANK_TRANSFER_ENABLED=false`; never paste them into reports or screenshots. BIC is optional in the current code and may be required by the chosen bank. Configure the bank-statement reconciliation process, exact €7 EUR amount, unique transaction identifier and rejection/support procedure. A submitted receipt alone is never verification of funds.
6. **Configure and verify SMTP separately.** Use provider-issued credentials, authenticated TLS, a verified sender, canonical HTTPS URL and production DNS authentication. Keep local-SMTP QA bypass false. Apply configuration caches through the deployment process, run `php artisan mail:status` and `php artisan launch:status --json`, then conduct an explicitly approved controlled inbox test. Real provider acceptance, inbox delivery and client rendering remain unverified. Approve enabling transactional mail separately; clearing a readiness check is not approval to send.
7. **Enable operations, then request final bank activation.** Install/test the every-minute Laravel scheduler, shared overlap/lease storage, failed-outbox monitoring and an incident owner. Review pending/failed messages before starting delivery; `--retry-failed` is an explicit operator action. SMTP can duplicate a message after acceptance/crash, although subscription activation remains idempotent. Finalize owner-reviewed policies and official support details. Only after these checks and explicit approval should the operator enable bank submissions. Verify a controlled transfer against the actual statement before any approval; no bank activation was performed here.

Future scoped migration command (documented only; **not run against the retained database**):

```sh
php artisan migrate --path=database/migrations/2026_10_22_000001_create_payment_email_deliveries.php --force
php artisan migrate:status
php artisan mail:status
php artisan launch:status --json
```

On failure, keep submissions/sending disabled, retain pending/outbox/audit data and fix forward. The rehearsed empty-table rollback is not safe once real deliveries exist: the migration's `down()` drops that table. Restoring a stale source backup would lose later edits and is not an incident shortcut. Disabling submissions does not revoke an existing administrator's review authority, so administrative access and workers must be controlled separately during an incident.

## Focused verification

| Check | Result |
| --- | --- |
| LaunchStatus + ManualBankPayment + TransactionalEmail | 47 tests / 447 assertions; SQLite :memory:, 6.24s |
| SMTP QA | Real loopback-only sink; approval/rejection/reset, TLS refusal, failures and cleanup passed; no external sending |
| Admin/payment/private-receipt regression | Ownership, admin gate, no self-review, exact-once periods, required bank configuration and private downloads passed |
| Native MariaDB | Backup/restore twice; additive migration, rerun, rollback/reapply, unique/FK/default/cascade checks passed |
| TypeScript | Passed |
| Vite | Passed, 800 modules, 6.08s |
| Pint / Composer validate --strict | Passed |
| Browser, isolated SQLite preview on 8008 | Ordinary keeper admin access returns 403; reference button disabled without config; fixed €7/30 days visible |
| Mobile regression | Dashboard 390px and public profile 320px have no horizontal overflow; ALL price, photo, dated availability and overnight indicative hours remain present |
| Retained configuration/data | .env and original CSS hashes unchanged; all retained rows match fresh backup; admins 0; bank/mail disabled |

No form edits, new payment claims or real-role changes were made through browser QA. The browser preview is an existing isolated synthetic SQLite environment, not the retained MariaDB schema. Full browser administration on a production identity, exact production MySQL version and external inbox delivery remain activation checks.

Machine-readable evidence: [m7-step4-verification.json](m7-step4-verification.json). Mobile evidence: [disabled bank dashboard](qa/m7-step4/bank-disabled-mobile.jpg). Earlier milestone reports are historical and should not be treated as current database snapshots.
