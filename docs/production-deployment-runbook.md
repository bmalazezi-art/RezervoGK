# RezervoGk — Production deployment checklist and runbook

**Free-launch update — 25 October 2026:** use [the current free-launch report](free-launch.md) before this historical runbook. Platform access is free, payment/receipt/admin endpoints are disabled and transfer email delivery is unscheduled. Bank details, payment-admin provisioning and the transfer outbox migration are not free-launch requirements. Do not follow the historical billing activation steps or apply that unrelated migration merely to launch free access. Infrastructure, HTTPS, password-reset SMTP, persistent storage and backup/restore requirements still apply. The latest free-launch backup has not passed a full restore drill; older M7 restores do not establish that evidence.

Prepared 22 October 2026 for M7 Step 5. **Planning document only. No deployment, DNS/TLS changes, server repairs, migrations on retained databases, admin promotion, live sending or bank activation are authorized by this document.** All commands below are future operator steps on an explicitly approved target. Do not paste the entire document into a shell.

Audit/evidence: [M7 Step 5](milestone-7-step-5.md). Administrative and bank activation remains a separate decision governed by [M7 Step 4](milestone-7-step-4.md).

## 1. Operator handover and release gate

Complete before scheduling a release:

| Required decision | Owner-supplied value/evidence |
| --- | --- |
| Deployment operator / incident contact | Pending |
| Hosting account, region, production hostname and TLS termination | Pending |
| Exact PHP web/CLI package and security patch evidence | Pending; local 8.4.12 must not be the public production baseline |
| Exact MySQL target/version, schema and restricted grants | Pending; Oracle MySQL 8.4 not yet tested |
| Persistent storage locations / application Unix identity | Pending |
| Current and previous release identifiers / artifact checksums | Pending; this workspace has no Git repository or deployment pipeline |
| Backup destination, encryption keys, recovery access and retention | Pending |
| Approved recovery point objective (RPO), recovery time objective (RTO), restore-drill schedule | Pending |
| Scheduler host, monitoring destination, log retention and alerts | Pending |
| Explicit deployment / public-launch approval | Pending; readiness checks do not grant approval |

Checklist:

- [ ] Chosen hosting supports PHP-FPM, CLI/SSH or equivalent controlled release jobs, persistent writable volumes and every-minute cron. A static-only host cannot run this application.
- [ ] Patched PHP web **and** CLI runtimes verified; GD JPEG/PNG, PDO MySQL and required extensions tested on target. Dependency-audit success is insufficient evidence of runtime patching.
- [ ] Database target is isolated from other apps, has supported maintenance/security coverage, and passes migrations/constraint tests on its exact engine/version.
- [ ] Canonical DNS, HTTPS certificate/renewal, HTTP redirect, proxy trust and secure cookies tested on staging with the intended topology.
- [ ] Public document root is only `current/public`; source, `.env`, caches, logs, backups and receipts cannot be served.
- [ ] Public photos, private receipts, sessions, cache locks and logs survive release changes; storage permissions tested as the application user.
- [ ] Fresh encrypted off-host DB/file backup and isolated restore comparison passed; secrets/APP_KEY recovery custody verified separately.
- [ ] Release and migration manifests reviewed; previous compatible release retained. No seeders or destructive reset commands appear in deployment jobs.
- [ ] Cache generation, production platform requirements, dependency audits, full isolated tests, TypeScript/build and staging smoke checks passed on the selected patched runtime.
- [ ] Outbox migration applied only after target/backup approval; scheduler monitoring distinguishes boot, data/schema and delivery readiness.
- [ ] SMTP configuration/inbox verification, named-admin authorization, secure administrative access and policy/support approval handled separately before public launch.
- [ ] Bank submissions remain disabled until their separate explicit activation approval. A successful deployment must never toggle this flag automatically.

## 2. Hosting, HTTPS and environment contract

Use the existing PHP/React/Inertia build. There is no configured Inertia SSR server, so Node is a build dependency rather than an application daemon. Installed Vite/plugin packages require Node `^20.19.0 || >=22.12.0`; use a patched supported LTS branch and pin the build version. The locally tested Node is 22.23.2. Do not choose an EOL branch merely because it satisfies that range. [Node release status](https://nodejs.org/en/about/previous-releases).

The project declares PHP `^8.2`, but production must also satisfy current security maintenance. The local PHP 8.4.12 predates the October 21 critical fixes; require upstream 8.4.27 or later on that branch, or documented vendor backports, and rerun tests. Verify FPM independently of CLI. [PHP security advisory](https://www.php.net/archive/2026.php), [supported branches](https://www.php.net/supported-versions.php).

The selected server must provide the framework extensions plus `pdo_mysql` and GD JPEG/PNG; EXIF enables camera-orientation correction. Ensure CLI/FPM have the same required extensions and CA trust. Test the app's 5MB / 3000px image limits under the actual memory/worker budget; capacity and concurrent-upload load have not been measured. Project upload settings are 5M/8M (`upload_max_filesize`/`post_max_size`); `bin/serve` configures development CLI and `public/.user.ini` targets FPM/CGI. Apache module/FPM pool/proxy limits may override these, so verify effective values without publishing phpinfo. Set the web request limit to accommodate the permitted upload and multipart overhead. Keep only `public/index.php` executable, disable directory indexes, deny dotfiles except required certificate challenges, and prevent upload directories executing scripts.

Serve only `public/`, make `storage` and `bootstrap/cache` writable to the correct application identity, and build release-local configuration/route/view caches. These match the framework's [deployment guidance](https://laravel.com/docs/12.x/deployment). Neither `php artisan serve` nor the local XAMPP installation is a production hosting configuration.

HTTPS acceptance must cover certificate chain/hostname/expiry and renewal, canonical HTTP→HTTPS redirect, asset/image/reset URLs, cookies and Inertia login/history behavior. If TLS terminates at a proxy, verify exact trusted proxy addresses/forwarded headers and reject spoofed host/scheme headers. The repository has no target-specific proxy/host trust configuration. Do not solve this by trusting arbitrary Internet proxies. Configure HSTS only after HTTPS and the relevant host/subdomain scope are approved. DB/SSH/service ports should be private or restricted; remote MySQL transport must have verified encryption or an approved private-network equivalent.

Set through the owner's secret manager/protected environment; **no values were installed here**:

| Setting | Required policy |
| --- | --- |
| `APP_ENV`, `APP_DEBUG`, `APP_URL` | production, false, owner's canonical HTTPS URL |
| `APP_KEY` | Preserve the existing deployment key across releases; provision once only for a genuinely new environment and escrow separately. Never regenerate it during deployment/rollback. |
| `DB_*`, optional `DB_URL` / `MYSQL_ATTR_SSL_CA` | Approved exact target and restricted app credentials; review URL overrides, CA/transport and grants. Separate migration privileges from day-to-day application access where supported. No global admin/GRANT OPTION. |
| `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE`, cookie/domain | true, true, lax; host-scoped and uniquely named. Prefer no broad domain scope. Review session encryption and key custody for the chosen store. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | Local values are file/file/sync. A single application node needs persistent file sessions/locks. Multiple nodes need shared compatible session/cache storage and one scheduler; do not silently change stores on launch. |
| `TRANSACTIONAL_MAIL_ENABLED`, `TRANSACTIONAL_MAIL_QA_LOCAL_SMTP` | Both false for initial deployment. Keep the QA bypass false in production; SMTP enablement requires separate approval. |
| `MAIL_*` | Verified provider-issued TLS SMTP configuration/sender; bounded timeout. Never use log/failover to record reset links. See Step 3/4 mail configuration requirements. |
| `BANK_TRANSFER_ENABLED` and bank fields | false; approved real details and activation remain separate. Never invent or copy QA values. |
| `LOG_CHANNEL`, `LOG_LEVEL`, retention | Protected rotating/central logs with controlled access/retention; no request bodies, secrets, tokens or private receipts. |
| `VITE_*` | Public frontend values only; never put DB, SMTP, bank credentials or APP_KEY in a Vite variable. |

Production cached config contains secrets too: protect it at filesystem level. `.env.example` and `compose.yaml` contain development defaults and are not production secret provisioning. Do not use `composer setup`, `key:generate`, `composer update`, `migrate:fresh`, seeders or dependency `--ignore-platform-reqs` in an existing deployment.

Application timezone is UTC; marketplace dates use Europe/Belgrade. The local DB reports session `SYSTEM` / host CEST. Set an explicit target timezone contract and test existing TIMESTAMP/DATETIME round trips, trial/subscription boundaries and DST behavior on a private restore. Do not shift stored dates or change the retained server timezone during readiness work.

Access logs at every layer (proxy/CDN/web server/APM) must redact the **path token and email query** on `/reset-password/{token}`; logging `$uri` without its query still exposes the path token. Use synthetic tokens to verify redaction and error reporting on staging. Do not store full bodies, raw SMTP exceptions or private receipts in diagnostics.

## 3. Durable storage and backups

A proposed host layout (paths must be approved and adapted):

```text
/srv/rezervogk/releases/<release-id>/    immutable code, vendor and build artifact
/srv/rezervogk/current                 pointer to the selected release
/srv/rezervogk/shared/.env              protected secrets; outside the public root
/srv/rezervogk/shared/storage/          persistent application storage
```

Connect each release's `storage` to the shared storage directory **before** Artisan cache generation. The existing configuration then resolves:

- `storage/app/public`: public profile photos; only this root is linked through `public/storage`.
- `storage/app/receipts-private`: private receipts, outside public and generic served local storage; no web alias or public/object-store ACL. Downloads remain controller-authorized owner/admin attachments.
- `storage/app/private`: generic local disk, whose serving option is enabled; never place receipts or backups here.
- `storage/framework/sessions` and `cache/data`: persistent if file stores are retained. Do not delete these as part of a release. Use shared stores if moving beyond one app node.
- `storage/logs`: protected, rotated and monitored. Release-local `bootstrap/cache` must not be overwritten across incompatible releases.

App-owned private files use restrictive permissions; the intended FPM/CLI/backup identities must be able to access only their required roots. Never use blanket 0777. Verify saved photos after refresh/release changes, private receipt owner/admin access, guest/other-owner denial and absence of direct/signed generic storage access. Local-disk configuration does not automatically turn into secure cloud storage by setting an S3 environment key; such a change needs separate implementation and QA.

M7 Step 4 produced two verified local SQL restores plus five stored-asset copies. Their checksums/permissions were rechecked during Step 5. They are local, permission-protected and not additionally encrypted by that workflow; they are not production off-host recovery. Binary logging is disabled on the installed MariaDB server, so current evidence does not provide point-in-time recovery.

For each deployment, coordinate schema/writer stability, create a consistent native DB backup and matching public/private file snapshot, record checksums, source/engine/release/migration ledger and UTC cut-off, encrypt off-host, and test recovery into an exclusively new empty database with file copies. Check DB engines and include any views/triggers/routines/events rather than silently omitting them. Never put a password in process arguments or share SQL dumps; use the approved credential store/private temporary client file and remove it afterward. Back up APP_KEY and required secrets separately with restricted recovery access.

Set RPO/RTO, retention, deletion handling and restore cadence with the owner before launch. Record measured restore time and acceptable data loss; no values have been agreed. System-table repair needs an independently verified full-instance recovery plan, including other schemas/users/grants and the appropriate server version; the app-only backup cannot authorize or safely reverse that operation.

## 4. Queues, scheduler and health monitoring

Current code has no application `ShouldQueue` jobs or dispatch calls. Password-reset mail sends synchronously through the guarded mail service. Bank-decision mail is an atomic custom outbox, drained by `mail:deliver-transfers`; `queue:work` does not drain this table. `QUEUE_CONNECTION=database` alone would not change that. A general queue worker is optional until actual queued work is introduced, then needs supervision, tested timeouts/retries and release restarts.

The account-deletion file cleanup task is registered every minute with a 5-minute overlap lock. The transfer-mail outbox task is also registered every minute with a 15-minute overlap lock when the non-free launch model is enabled. Install cron **only on the approved single scheduler host**, as the application user, with the same secrets/storage/CLI runtime as FPM. This one Laravel scheduler entry is required for failed photo/receipt removals to be retried; successful immediate cleanup does not require cron. Example only; paths/binary/log handling require adaptation:

```cron
* * * * * cd /srv/rezervogk/current && /usr/bin/php artisan schedule:run >> /srv/rezervogk/shared/storage/logs/scheduler.log 2>&1
```

The framework documents this [every-minute scheduler entry](https://laravel.com/docs/12.x/scheduling). No cron, timer or worker was installed during this audit. MariaDB's SQL `event_scheduler=OFF` is unrelated to Laravel's OS-driven scheduler. File overlap locks are per filesystem; do not start a scheduler per node with separate file caches. A future distributed scheduler arrangement requires shared lock storage and separate validation.

The command defaults to at most 50 deliveries per run, takes a 10-minute per-delivery lease and caps automatic attempts at five. Runs can exceed a minute under SMTP delays, so measure duration/capacity and alert on backlog age. Supervise scheduler execution and inspect child-command outcomes; seeing `schedule:run` execute is not proof of successful SMTP delivery. `--retry-failed` requeues **all** failed rows, not one selected message: review the whole failed set and obtain operator approval before using it. SMTP acceptance followed by a crash can duplicate email; subscription activation remains independently idempotent.

Monitor separately:

| Signal | Required evidence |
| --- | --- |
| HTTP liveness | `/up` and application response/latency; `/up` is also exempted from Laravel maintenance mode |
| DB/schema readiness | Protected read-only DB query, expected migration ledger/outbox schema and `launch:status`; never publish credentials/schema introspection |
| Storage | Permission checks, free space/inodes, photo URL and authorized private download on controlled staging fixtures |
| Scheduler/outbox | Last execution and child outcome, oldest due/processing rows, lease age, failed count; `mail:status` is sanitized but does not show backlog age |
| Email | Provider rejection/bounce evidence, controlled inbox test, sanitized failures; configuration readiness does not prove delivery |
| Recovery/TLS | Backup age/integrity/restore result, certificate expiry/renewal, runtime security patch status |

Isolated rehearsal proved `/up` returns 200 with an empty database: it must not be the sole load-balancer release/launch gate. No production DB-aware monitor, scheduler heartbeat or alert integration has been configured. Avoid health probes that send messages, issue payment references or write business data.

## 5. Release sequence — after separate deployment approval

1. **Build an identified immutable candidate away from the active checkout.** Record source/lockfile/artifact checksums and the previous release. In isolated CI/staging run tests, platform/security checks, TypeScript and Vite. Install production PHP dependencies from the lockfile in a separate artifact; never remove dev dependencies from this shared development checkout. Do not run Composer's setup/key-generation script.

   ```sh
   composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
   composer check-platform-reqs --no-dev
   composer audit --no-dev
   npm ci --include=dev
   npm audit
   npm run typecheck
   npm run build
   ```

   PHP tests require dev dependencies in a separate test workspace and an explicitly isolated test DB. Do not copy node_modules, tests' private SMTP captures, local .env, development config/view caches, QA SQLite databases, backups, logs or `public/hot` into the public artifact. Keep `public/build/manifest.json` and hashed assets together. Retain old hashed assets long enough for existing browser clients; changing a release pointer must not immediately invalidate their asset URLs.
2. **Rehearse on the exact target runtime/database engine.** Use synthetic users, dummy bank details and a loopback sink with outbound sending blocked. A restored real-data backup must stay private/network-isolated and must not send mail or expose contact details. Verify schema/data preservation, eligibility expiry, currencies, dated/weekly availability, auth/ownership, upload persistence and private receipts. Test TLS/proxy/cookie behavior through the real staging topology. Oracle MySQL 8.4 and patched PHP web SAPI remain unverified here.
3. **Prepare recovery and quiet writes.** Confirm the exact target database/release, fresh backup/restore result, maintenance owner and rollback compatibility. Pause the scheduler/outbox runner and any workers; block business/admin writes during the planned cut-over. Maintenance mode does not stop arbitrary CLI processes and does not make `/up` fail. Bank disablement alone does not prevent an already-authorized admin reviewing a claim. Keep sending/submissions false and do not promote anyone.
4. **Review the pending migration ledger.** The retained local schema currently lacks only the additive outbox migration. If the actual target differs, stop and review every pending migration. After approval/backup, apply only the reviewed paths; a new empty target needs its complete reviewed migration set. Do not seed demo accounts or roll back populated tables.

   ```sh
   php artisan migrate:status
   php artisan migrate --path=database/migrations/2026_10_22_000001_create_payment_email_deliveries.php --force
   php artisan migrate:status
   ```

   This narrowly scoped migration command was not applied to the retained schema during readiness work. Capture preservation evidence and do not backfill historical decision emails without a separate decision.
5. **Finish candidate storage/caches, then switch.** Mount approved secrets/persistent storage, create the public-photo storage link, compile caches in the candidate's context, verify effective configuration, and perform the platform's atomic release switch. Reload PHP-FPM/OPcache so code and caches match. Do not flush a shared cache indiscriminately; it can discard locks or session/rate-limit state.

   ```sh
   php artisan storage:link
   php artisan optimize
   php artisan route:list
   php artisan schedule:list
   php artisan launch:status --json
   php artisan mail:status
   ```

   The isolated cache rehearsal passed; it is not a deployed server configuration. Readiness/status exit 1 is expected while services/admins are intentionally unconfigured. Record each expected blocker explicitly rather than ignoring every nonzero result. Even exit 0 never grants activation approval.
6. **Smoke-test before restoring traffic.** Confirm the intended release, canonical HTTPS, assets and old-client compatibility, `/up` plus DB/schema readiness, guest browse/contact with no account, secure auth/ownership, file access and source/backup secrecy. Any write tests must use an approved controlled environment/identity; do not mutate real user data as an implicit deployment test. Verify retained record/audit counts against the cut-over baseline. Restore traffic only under the agreed launch decision; restart the single scheduler under separately approved mail configuration. Real SMTP, bank details, admin identity/MFA and payments remain separate activation work.
7. **Observe and record.** Capture deployment time, artifact digest, migration result, operator, smoke evidence, backlog/storage/error metrics and rollback decision. Keep the previous compatible release and fresh backup. Do not prune old assets/storage or automatically enable mail/payments as a final CI step.

## 6. Rollback and incident procedure

| Failure | Safe next action |
| --- | --- |
| Candidate build/cache/TLS validation fails before switch | Keep active release unchanged; discard only the newly created candidate after preserving diagnostic evidence. No DB restore. |
| Additive migration succeeded but candidate fails | Quiet writes/runners; use a previously verified schema-compatible release while leaving the additive table and all its data intact, or fix forward. |
| Migration partially fails | Stop; MySQL-family DDL is not a general rollback transaction. Inspect actual schema/ledger and repair forward under approval; never blindly rerun destructive down/fresh commands. |
| Email provider failure/backlog | Pause sending/runners through the incident process; retain outbox/audits/subscriptions and review retries. No automatic mass retry or subscription revocation. |
| Compromise or genuine DB/file corruption | Restrict traffic/admin access, stop writers/runners, preserve current evidence and invoke the separately approved disaster-recovery plan. |

For a code rollback, identify the previous **compatible** release, confirm schema/auth/outbox/currency/availability compatibility, keep shared data/secrets/APP_KEY, select the previous code/build/caches, reload FPM and repeat smoke checks before restoring traffic. A release predating the outbox workflow is not automatically a safe rollback target for continuing payment reviews. Preserve current uploads and payment decisions; code rollback is not a database rewind.

Never run the outbox migration's `down()` on real deliveries: it drops the table. Never overwrite the retained DB with the Step 4 backup to undo a failed release. If disaster recovery is necessary, restore DB/files to a new isolated target first, verify checksums/constraints/ownership and the accepted recovery cut-off, reconcile all later owner edits, actual bank transactions, decisions and already-sent mail, then seek approval for the connection/cut-over. A stale restore can erase deduplication/audit evidence; bank reconciliation must precede resumed approvals. Keep the damaged/current target recoverable until the owner approves its disposition.

## 7. MariaDB 1558 maintenance handoff — read-only investigation only

The local server reports 10.4.28; `mysql.proc` has 20 columns while the server expects 21, and the error identifies catalog version 100108 (10.1.8) versus running 100428. There are no routines/events in the app schema. The upgrade marker could not be read without elevated access; upgrade history was not inferred from that absence. Error 1045 did not recur.

This is consistent with incomplete system-table upgrade, according to [MariaDB's 1558 guidance](https://mariadb.com/docs/server/reference/error-codes/mariadb-error-codes-1500-to-1599/e1558). [mariadb-upgrade](https://mariadb.com/docs/server/clients-and-utilities/deployment-tools/mariadb-upgrade) changes tables and is not a read-only diagnostic. No upgrade, CHECK/REPAIR/ALTER, system-table replacement, grant/password change, restart or sudo operation was performed.

Before any repair: obtain explicit server-maintenance approval, inventory all affected applications, verify a full-instance backup/restore on the correct server versions, choose a supported target and maintenance path, rehearse on an isolated copy, and assign rollback/recovery ownership. Do not manually add a guessed column or copy another installation's `mysql` tables. A clean supported production MySQL target still needs its own application/data migration rehearsal; repairing this local catalog alone would not establish production readiness.
