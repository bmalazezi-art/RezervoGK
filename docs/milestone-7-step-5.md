# M7 Step 5 — Production deployment readiness audit

22 October 2026. **Audit, checklist and runbook prepared; deployment remains blocked.** Only documentation changed. No deployment, production-target connection, DNS/certificate change, retained migration, data/role change, server repair, payment activation or external email sending occurred. Existing navbar/UI, application code/configuration, `.env` and stored assets were preserved.

Operational artifact: [production deployment checklist and runbook](production-deployment-runbook.md). Evidence: [m7-step5-verification.json](m7-step5-verification.json).

## Critical findings and remaining gates

| Area | Observed state / remaining requirement |
| --- | --- |
| PHP runtime | Local CLI is **8.4.12**. It passes project platform constraints, but predates the **21 October critical PHP web-request security fixes**. Require patched production web/CLI packages and target regression testing; no runtime update was made. |
| Database lifecycle/catalog | Local XAMPP **MariaDB 10.4.28** has the confirmed 1558 mismatch below. MariaDB 10.4 maintenance ended **18 June 2024**. Do not adopt this installation as the public production DB baseline. |
| Production target | No host, region, domain, deployment identity or target-specific web/FPM configuration supplied. The supplied Compose file is a local MySQL 8.4 service with development defaults, not a production stack. Docker and Oracle MySQL 8.4 verification remain unavailable here. |
| HTTPS / environment | Current local configuration is local/debug/HTTP with nonsecure file-session cookies. Production hostname, certificate/renewal, proxy trust, HTTPS redirects, secure cookies and real web-SAPI limits remain unverified. No target-specific trusted proxy/host setup is configured in the repository. |
| Release process | No Git repository/deployment pipeline, production release identifier, immutable artifact workflow or retained compatible rollback release is configured. The runbook defines these handover requirements; no remote repository or deployment was created. |
| Storage | Public photos and private receipts have separate configured roots; source-file hashes remained unchanged. Durable volumes, permissions, cross-release persistence, old-asset retention, capacity and cloud storage topology need target verification. No cloud/object-store migration was performed. |
| Backups | Step 4 SQL/asset backup checksums and owner-only permissions still pass. Those local backups have no additional workflow encryption; encrypted off-host backup, secret/key custody, agreed RPO/RTO and target restore timing remain open. Local binary logging is disabled, so no point-in-time recovery was established. |
| Jobs / scheduler | Local queue is sync, cache/file sessions are file-based. Outbox task registration is verified; actual production cron/supervision, shared-lock strategy, backlog-age checks and alerts are unconfigured/unverified. A generic Laravel worker does not process the custom email outbox. |
| Schema / email | Nine retained migrations applied; the additive email-outbox migration remains pending. Transactional email is disabled. External authenticated TLS SMTP, verified sender/DNS and inbox evidence remain open. |
| Activation / operations | Zero retained admins; bank submissions disabled/unconfigured. Named-admin authorization and secure access controls, real bank verification and activation remain separate approvals. Policies are drafts and official support details still need owner approval. |

PHP's advisory identifies CVE-2026-109618 and CVE-2026-109619, with the 8.4 branch fixed in **8.4.27**. A verified vendor backport is an alternative patch source; the installed CLI's vendor patch status was not established. This is a production gate, not a claim that an exploit was attempted or that a production host was inspected. [Official PHP advisory](https://www.php.net/archive/2026.php), [PHP maintenance status](https://www.php.net/supported-versions.php).

MariaDB's published lifecycle confirms the 10.4 end-of-maintenance date. A catalog repair would not restore security maintenance to that old branch. A clean supported target and migration rehearsal are separate from local server repair. [MariaDB maintenance policy](https://mariadb.org/about/#maintenance-policy).

## MariaDB 1558 — read-only diagnosis

Reproduced the failure using the application's existing authenticated connection and a scoped `information_schema.ROUTINES` SELECT. Captured only numeric diagnostic fields, not credentials or raw exceptions:

- Native code **1558**, SQLSTATE **HY000**.
- Server expects **21** columns; actual `mysql.proc` has **20** columns.
- Error reports catalog version **100108 (10.1.8)** versus running **100428 (10.4.28)**.
- Existing local administrative access was used privately for scoped SELECT/SHOW metadata inside a read-only transaction. App-schema stored-routine and SQL-event counts are both **zero**.
- The upgrade marker was not readable as the current OS user. No privilege escalation or inference that the marker is absent was made.
- SQL event scheduling is OFF; it does not run Laravel's OS cron task. Local access succeeds and **1045 did not recur**.

These facts strongly support an incomplete system-table upgrade, matching [MariaDB's explanation of 1558](https://mariadb.com/docs/server/reference/error-codes/mariadb-error-codes-1500-to-1599/e1558). They do not establish the machine's historical upgrade path or the health of every other system table. No `mariadb-upgrade`/`mysql_upgrade`, repair/check, ALTER, grant/password changes, schema creation, service restart or system-table replacement was run. The upgrade utility is mutating; repair requires separately approved full-instance recovery and maintenance, as detailed in the runbook and [MariaDB's upgrade utility documentation](https://mariadb.com/docs/server/clients-and-utilities/deployment-tools/mariadb-upgrade).

## Project-specific deployment findings

The Laravel/PHP application requires a PHP-capable host with persistent storage and a scheduler. React/Inertia assets are built through Vite; no Node SSR service is configured. The local CLI has required framework extensions plus GD and PDO MySQL, and `composer check-platform-reqs --no-dev` passes. This checks package requirements, not runtime vulnerability coverage or a remote FPM installation. Node 22.23.2 satisfies the installed Vite/plugin engine constraint; target build/runtime patch verification remains necessary.

`composer setup` generates an APP_KEY and must not be used for an existing deployment. The runbook uses locked dependency installs, a separate test/build workspace, persistent shared storage, protected target secrets, reviewed additive migrations and a compatible prior release. It forbids destructive resets, seeding demos into retained data, blanket cache flushes and stale source restores.

Password resets send during the web request. Bank decisions create an atomic `payment_email_deliveries` outbox row and the scheduled `mail:deliver-transfers` command drains it. The application has no queued application jobs/dispatch calls. Delivery has leases, five automatic attempts and an explicit mass failed-retry option; monitoring must cover actual delivery outcome/backlog, not only cron execution. Approval idempotency and email at-least-once delivery remain distinct.

An isolated production-shaped boot returned **200 from `/up` despite an empty in-memory database**. No custom DB health listener exists. Therefore, `/up` is liveness only; target launch gating also needs protected DB/schema, storage and outbox checks. `/up` is exempted from framework maintenance mode, so maintenance must not be inferred from its status.

The runbook also requires redacting both reset-token path segments and email queries in proxy/web/APM logs, preserving receipt privacy across deployment volumes, verifying upload limits in the actual web SAPI and rehearsing existing date/time values on the target. Local SQL session timezone is SYSTEM/CEST, application time is UTC and marketplace dates use Europe/Belgrade; no stored timestamps or server timezone were adjusted.

## Checks performed

| Check | Result / limit |
| --- | --- |
| Database / application / UI / `.env` / asset preservation | Fresh private baseline captured before inspection; final table rows and DDL, source/config/UI hashes and stored-file hashes match |
| Existing Step 4 backup | Both SQL checksums, asset checksums, private modes and removal of temporary credentials verified; no new restore or source overwrite this step |
| MariaDB connection / catalog inspection | Access succeeds; 1558 reproduced and scoped metadata collected read-only |
| Migration/status inspection | Outbox pending; zero admins; bank/mail disabled; no retained migration executed |
| Isolated cache rehearsal | `optimize` compiled config, events, routes and views; 32 cached routes, scheduler task and protected production flags verified |
| Cache/boot isolation | Separate private storage and all cache paths; synthetic key, SQLite :memory:, mail/bank disabled; source DB not used. Temporary candidate/cache files removed |
| Empty-schema health check | `/up` 200 confirms it does not prove database readiness |
| Focused PHP regression | **47 tests / 447 assertions, 6.51s**, SQLite :memory:; readiness, manual payments, private receipts, SMTP sink, retries and failure handling passed |
| Production PHP platform requirements | Passed on local CLI 8.4.12; remote patched web/CLI verification pending |
| Dependency audits | Composer production dependencies: zero advisories/abandoned packages; npm production dependencies: zero reported vulnerabilities; registry checks succeeded. These are not whole-system or dev-toolchain audits |
| Composer validation / Pint | Passed |
| Frontend / browser | No source/UI changes; TypeScript/build and desktop/mobile navbar QA passed in the preceding navbar task. No new build or browser session mutation was needed for this documentation audit |

Full regression and exact-runtime/database staging checks remain required before deployment. Local cache success, liveness and advisory-free application dependencies do not authorize launch or establish remote infrastructure security. No production endpoint was supplied or probed.

## Next handoff

Complete the owner/target/backup/incident fields in the runbook, select patched infrastructure, rehearse on that exact PHP/MySQL target and obtain an explicit deployment decision. Keep bank/mail off and roles unchanged while preparing it. Step 4 still governs any later administrator, SMTP and real-bank activation; this audit does not perform or authorize those actions.
