# RezervoGk — Proposed DigitalOcean production environment

Verified 22 October 2026. **Plan only:** no resources created, servers changed, Laravel booted, database connections opened or local databases modified. No application/UI/configuration changes or live-payment activation. This is a concrete hosting proposal, not a tested deployment.

## Proposed stack

| Component | Proposal |
| --- | --- |
| Region | FRA1 / Frankfurt, app and DB in the same private VPC; confirm plan capacity before ordering. [MySQL availability](https://docs.digitalocean.com/products/databases/mysql/details/availability/) |
| Application host | One Basic regular-CPU Droplet, 2 vCPU / 4 GiB RAM / 80 GiB boot SSD. Initial sizing estimate; validate upload concurrency, FPM memory and DB connections in staging. |
| Operating system | Debian 13 x64, using signed Debian stable/security repositories. DigitalOcean lists the `debian-13-x64` image. [Available images](https://docs.digitalocean.com/products/droplets/details/images/) |
| Web/PHP | Maintained Nginx + native PHP 8.4-FPM/CLI, **8.4.27 or later security patch** on the 8.4 branch. Debian published `8.4.27-1~deb13u1` in trixie-security. Verify installed binaries and restart all old FPM workers; never infer patching from the base image. [Debian security package](https://tracker.debian.org/news/1813677/accepted-php84-8427-1deb13u1-source-into-stable-security/) |
| Database | DigitalOcean Managed **MySQL 8.4 Standard**, 2 GiB primary + matching standby. Private advertised hostname/port, verified TLS/CA and trusted-source restrictions to the app host; separate restricted runtime/migration credentials. [Supported MySQL version](https://docs.digitalocean.com/products/databases/mysql/how-to/create/), [connection/TLS guidance](https://docs.digitalocean.com/products/databases/mysql/how-to/connect/) |
| Persistent files | A 20 GiB attached Volume mounted at `/srv/rezervogk/shared/storage`; releases link to it. Only `app/public` is exposed through `public/storage`. Receipts remain in `app/receipts-private`, outside both public and generic served local storage. Require the mount before FPM/scheduler startup; no silent fallback onto the boot disk. |
| HTTPS | Owner's domain, Nginx TLS with Let's Encrypt/Certbot renewal and expiry alerts. Public root only `/srv/rezervogk/current/public`; FPM via local Unix socket. Public 80/443, SSH restricted to approved access; no public FPM or database listener. |
| Scheduler | One every-minute Laravel `schedule:run` entry, application user, explicit PHP 8.4 binary, shared persistent cache/lock paths. Monitor actual outbox outcomes and backlog age. |

The database has standby failover, but **the single app Droplet is not highly available**. Accept that recovery limitation explicitly. Adding multiple app servers later requires shared sessions/cache and a separately designed shared file-storage topology; do not attach this single-writer Volume as though it were shared network storage.

## Laravel compatibility and verification limits

- Local `composer.lock` pins Laravel **12.70.0**; app/framework PHP requirements are `^8.2`. Read-only `composer --no-plugins --no-scripts prohibits php 8.4.27 --locked` reported no incompatible PHP requirement. Laravel 12 officially supports PHP 8.4, and its MySQL driver supports the proposed 8.4 engine. Existing config already supports provider CA files through `MYSQL_ATTR_SSL_CA`. [Laravel support policy](https://laravel.com/docs/12.x/releases), [database support](https://laravel.com/docs/12.x/database).
- Install PDO MySQL, GD with JPEG/PNG, mbstring, XML/DOM, cURL, fileinfo, OpenSSL and other locked platform requirements in both web/CLI contexts; EXIF supports camera orientation. Verify real FPM upload limits (5M upload, 8M POST), CA trust, OPcache and a measured memory/worker budget.
- Native Debian security packages avoid adding a third-party PHP repository. An older reported PHP version is acceptable **only with verified vendor evidence covering both October 21 CVEs**, not a generic “supported PHP” claim. [PHP advisory](https://www.php.net/archive/2026.php).
- React/Inertia/TypeScript are built in CI using the lockfile and a patched supported Node LTS version; no Node SSR daemon is configured. Keep production PHP dependencies and Vite manifest/assets together in a versioned release.
- **Not yet verified:** actual DigitalOcean FPM packages, PHP 8.4.27 execution, MySQL 8.4 migrations, provider grants/TLS/failover or restoration on this target. Require isolated staging integration tests before deployment. No local database was used to make compatibility claims.
- Laravel 12's published security-support deadline is **24 February 2027**. Schedule a separately tested framework upgrade before then; no framework upgrade is part of this plan.

## Email constraint specific to DigitalOcean

Droplets block SMTP **25/465/587**. RezervoGk currently permits guarded SMTP, not an interchangeable HTTP mail API. A compatible proposal is authenticated Mailgun SMTP on **2525 with required STARTTLS** (`MAIL_SCHEME=smtp`, `MAIL_REQUIRE_TLS=true`, valid peer verification); Mailgun documents this port. Actual outbound reachability, sender DNS, provider account/region and a controlled inbox test are deployment gates. Do not assume it works merely because the port is not listed as blocked. [DigitalOcean policy](https://docs.digitalocean.com/support/why-is-smtp-blocked/), [Mailgun SMTP ports](https://documentation.mailgun.com/docs/mailgun/user-manual/sending-messages/send-smtp).

Keep `TRANSACTIONAL_MAIL_ENABLED=false`, its local-QA bypass false and `BANK_TRANSFER_ENABLED=false` while preparing the environment. If 2525/TLS is unavailable, select another verified supported SMTP endpoint or separately implement/test an HTTPS transport; do not silently bypass the existing mail guard. No mail-provider account, credentials or live sending were configured.

## Backups and recovery

- Managed MySQL provides daily backups with a documented seven-day point-in-time recovery window. Test a restore/fork to a new cluster and actual recovery time. [Managed MySQL backup features](https://docs.digitalocean.com/products/databases/mysql/details/features/).
- Propose hourly client-encrypted file backups and a daily consistent logical DB export to a **private Spaces bucket**, with checksum verification, restricted credentials and backup encryption/recovery keys held separately. Use a MySQL-8.4-compatible export tool and test it against the managed service. Back up APP_KEY/secrets separately; never print them or store them in Git.
- Maintain an independently recoverable encrypted second copy outside the production DO account. Proposed initial retention: 14 daily, 8 weekly, 3 monthly snapshots; owner approval, sizing and deletion policy remain required. Proposed file RPO 1 hour / service RTO 4 hours are targets to measure in drills, not guarantees or an agreed SLA.
- Droplet backups **exclude attached Volumes** and are documented as unencrypted at rest. Do not treat them as the app's encrypted data backup. No Droplet-image backup charge is assumed below. [Backup limitations](https://docs.digitalocean.com/products/backups/details/limits/).

## Indicative monthly budget

USD list prices checked 22 October 2026; verify the quote/region before purchase.

| Item | Estimate/month |
| --- | ---: |
| Basic 2 vCPU / 4 GiB app Droplet | $24.00 |
| Managed MySQL primary + standby, $30 each | $60.00 |
| 20 GiB Volume at $0.10/GiB | $2.00 |
| Spaces base subscription for encrypted backups | $5.00 |
| **Base estimate** | **$91.00** |

Sources: [Droplets](https://www.digitalocean.com/pricing/droplets), [MySQL](https://docs.digitalocean.com/products/databases/mysql/details/pricing/), [Volumes](https://docs.digitalocean.com/products/volumes/details/pricing/), [Spaces](https://docs.digitalocean.com/products/spaces/details/pricing/). Excludes tax, SMTP/domain, staging, snapshots, monitoring extras, independent second-copy storage, and usage overages. This is an estimate, not an order or capacity guarantee.

## Concise deployment sequence — future approved work

1. **Approve target and recovery:** domain, region, ~$91 base budget, operator, backup custody and recovery objectives. Create a separate staging environment first; do not repair/reuse the old local MariaDB instance as production.
2. **Provision and patch:** Debian 13 Droplet, managed MySQL 8.4/standby, private VPC/trusted sources and persistent Volume. Install signed PHP/Nginx packages; verify `php8.4 -v`, `php-fpm8.4 -v`, package/security provenance and extensions before admitting web traffic.
3. **Prepare secure configuration:** canonical HTTPS URL, production/debug-off settings, secure HTTP-only cookies, owner-approved APP_KEY custody, provider DB hostname/port/CA and least privileges. Mount persistent storage, restrict private files and redact reset tokens—including path segments—from every access/APM log. Keep bank/mail switches off and roles unchanged.
4. **Build and rehearse:** locked Composer/Node installs, PHP tests in an isolated staging database, TypeScript/Vite build and caches. Apply the full reviewed migration set to a new empty target; for retained data, use a fresh approved backup/private restore and apply only reviewed pending migrations, including the outbox. Do not import any old `mysql` system tables, seed demos, run `migrate:fresh`, regenerate an existing APP_KEY or overwrite a retained DB. Verify MySQL constraints, ownership, expiry, currencies, weekly/date availability, uploads and timestamp/timezone round trips.
5. **Configure operations:** Nginx/FPM/HTTPS renewal, persistent public/private storage, the single scheduler, encrypted backup jobs and alerts. Bank-decision email uses `mail:deliver-transfers`; a generic `queue:work` process does not drain that outbox. Test SMTP 2525/TLS only under an approved controlled sending test; admin and real-bank activation remain separate approvals.
6. **Validate and release only after approval:** verify restore drill, exact runtime version, HTTPS/cookies, asset paths, storage privacy and DB/outbox readiness. `/up` alone is insufficient. Review `launch:status`/`mail:status` without interpreting them as activation authorization. Switch a versioned release only after the chosen gates pass.
7. **Retain a safe rollback:** keep the previous compatible code/build, shared uploads, secrets, migration ledger and payment/outbox/audit data. Roll back code or fix forward; never automatically drop populated tables or restore a stale dump over later user/payment changes. Restore into a new isolated target first if disaster recovery is required.

Further operational detail: [production deployment runbook](production-deployment-runbook.md). Remaining decisions are the domain/account/budget, target provisioning permission, staging verification, backup/monitoring ownership and separately approved email/admin/bank activation. Nothing has been provisioned or activated.
