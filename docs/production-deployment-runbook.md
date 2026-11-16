# Gjej portier — Production deployment checklist and runbook

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
| Persistent storage locations / application Unix identity | Gjej isolation layout is established as recorded below; reverify the live server before a release |
| Current and previous release identifiers / artifact checksums | Record the selected clean Git commit and validated artifact manifest for each approved release; transfer/promotion is not yet production-ready |
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

### Local release packaging and artifact validation

The local repository contains release packaging and validation tools under `scripts/release/`. They do not connect to a server. Packaging requires the checkout root to have no tracked or untracked Git changes and an explicit existing commit SHA or tag; `HEAD`, branch names and uncommitted content are refused. The selected commit and caller-supplied ref are recorded in a JSON manifest. Git system/global configuration and environment overrides are disabled for the source clone; submodules and Git LFS pointer files are refused. The builder checks out that committed tree in a temporary clone, installs from `composer.lock` with `--no-dev`, `--no-scripts` and `--no-plugins`, checks PHP platform requirements, runs `npm ci`, and builds Vite assets. It never packages the current worktree.

After these tools are reviewed and committed, run them from a clean checkout:

```sh
python3 -m scripts.release.package_release --ref <tag-or-commit-sha> --output-dir <empty-local-output-directory>
python3 -m scripts.release.validate_artifact <artifact.tar.gz> --manifest <artifact.tar.gz.manifest.json>
```

The output directory must be outside the repository and empty. The archive includes production `vendor/`, Laravel application files, lockfiles, and the Vite manifest plus exactly its referenced build assets. The USTAR archive and metadata are staged in the output directory, validated, then published without overwriting existing names. The manifest records the SHA-256 of both lockfiles and the archive, release ID, commit, selected ref and UTC build time; the validator checks their internal consistency. Digest verification alone proves only that bytes match the supplied manifest; it does **not** prove trusted origin or authenticate the selected ref.

Signing support uses the official Minisign detached signature format (`.minisig`) through the Minisign CLI, not the retired project-specific OpenSSL JSON envelope. The wrapper requires Minisign **0.12 or newer** and uses `-V -Q` to authenticate the Minisign signature and its signed trusted comment. Install Minisign from an approved package source and pin the version in CI; on macOS the upstream project documents `brew install minisign`. Linux builds from source require the upstream documented build toolchain/dependencies. No Minisign binary was installed on the production server as part of this work.

The tool signs the exact manifest bytes with Minisign. Its Minisign trusted comment carries a compact versioned metadata payload binding signer identity, manifest SHA-256, exact artifact filename and SHA-256, commit SHA and release ID. The identity is a signed claim checked against an operator-configured expected identity; Minisign itself does not attest to a CI repository/workflow identity. Verification first runs Minisign against locally configured public trust roots and only parses the trusted comment after `minisign -V -Q` succeeds. It then checks the identity and metadata against the signed manifest, and runs archive SHA-256/structure validation. The signature and manifest delivered with an artifact do not supply their own trusted public key. The wrapper has no fallback for the retired custom JSON format or unsigned artifacts.

Example local commands (replace paths and identity with reviewed values; no real keys are included here):

```sh
python3 -m scripts.release.sign_artifact sign \
  --archive <artifact.tar.gz> \
  --manifest <artifact.tar.gz.manifest.json> \
  --private-key <protected-minisign.key> \
  --signer-identity <approved-build-identity> \
  --signature <artifact.tar.gz.manifest.minisig>

python3 -m scripts.release.sign_artifact verify \
  --archive <artifact.tar.gz> \
  --manifest <artifact.tar.gz.manifest.json> \
  --signature <artifact.tar.gz.manifest.minisig> \
  --trusted-public-key <root-owned-minisign.pub> \
  --expected-identity <approved-build-identity>
```

For key rotation, specify each currently trusted key explicitly by repeating `--trusted-public-key`. During an approved short overlap, provide both old and new locally pinned keys; after the cutover remove the old key from the trust configuration. A public key supplied alongside an artifact is never accepted automatically. Revocation removes the compromised key from the trust set immediately; artifacts that cannot verify against a remaining key fail. Production trust roots and allowed signer identity must be root-owned, reviewed configuration, unavailable for modification by `gjej`, `gjej-deploy`, the artifact submitter, or Lidhje. A local verifier/installer now exists, but its production trust configuration and full candidate gate are not approved or implemented end to end.

Keep the Minisign secret key in a protected signing service or narrowly scoped CI secret; never store it in Git, an artifact, the Droplet, or the general deployment account. For unattended CI, arrange a protected signer that can read the key without putting its contents or passphrase in command-line arguments or logs. Minisign's normal generated key is password protected; the test suite uses only synthetic `-W` keys in temporary directories. Production key format, custody, recovery access and named approvers remain owner decisions. Prefer a separately protected signing job after build/test approval, and record the selected commit and artifact digest. The server needs only pinned public key(s), the expected identity policy and a compatible Minisign verifier; private signing material must never reach it.

Minisign is an established offline signature format, but the signed identity payload remains a claim under a key and provides no independent CI OIDC attestation or transparency log. If repository/workflow provenance is required, evaluate Sigstore separately; do not allow an unreviewed alternate format or unsigned fallback.

This tooling does not create keys, install verification software, establish a CI trust boundary, sign a real release or make promotion ready. It does not authorize creating a real production release.

Artifacts must never contain `.env` or `.env.*`, `.git`, `node_modules`, `public/hot`, `storage`, `public/storage`, user uploads, private receipts, logs, sessions, caches, compiled runtime state, SQLite databases, nested archives, dumps, backups, test output, temporary files, dependency credentials or other secrets. Only the public server configuration files `public/.htaccess` and `public/.user.ini` are permitted dotfiles. The validator checks normalized paths, traversal, duplicates, links/special files, PAX/GNU tar metadata, hidden files, forbidden paths and file extensions, bounded compressed/decompressed sizes and member count, required Laravel/Vite files, lock digests and exact Vite asset references. It performs a bounded raw tar preflight before the standard parser and does not extract the archive. Do not use a generic `tar -x` on an unvalidated artifact.

The local build runs Composer/npm commands as the invoking OS user and does not provide an OS-level filesystem/network sandbox. Composer plugins and scripts are disabled, but npm lifecycle/build code can still access files and network resources available to that user. Its subprocess environment and temporary home are isolated; they do not restrict filesystem access. Package only a reviewed, trusted commit in an appropriately isolated account or CI runner; do not use this tool to inspect untrusted repositories or refs.

### Current Gjej isolation and transfer boundary

The approved same-Droplet layout is:

```text
/var/www/rezervogk/releases/<release-id>/   release code, production vendor and built assets
/var/www/rezervogk/current                  atomic pointer to the selected release
/var/www/rezervogk/shared/storage/           persistent application storage
/etc/gjej/rezervogk.env                      root-managed environment file
/run/php/php8.4-gjej.sock                    dedicated Gjej PHP-FPM socket
```

The intended identities are `gjej` for PHP-FPM/runtime, `gjej-nginx` for Nginx workers, and `gjej-deploy` for artifact submission. The root-owned `/usr/local/sbin/gjej-promote` is the promotion boundary. This architecture was established in prior infrastructure work; this local packaging task did not connect to or re-inspect the server. Reconfirm actual paths, ownership and service state during any separately approved deployment window. Lidhje Social remains on its existing `www-data` pool and configuration.

No transfer key or secure transfer mechanism is configured. No public Gjej virtual host or DNS was enabled by this milestone. Do not use interactive shell access, general-purpose SSH/SCP access, or CI credentials that can execute arbitrary commands. The preferred future path is a restricted SFTP-only/chroot or forced-command receiver that can submit an artifact and manifest into a non-active incoming directory, with no PTY, forwarding, shell or sudo. A manual operator transfer to a controlled incoming directory is an interim alternative only if the same root-side sealing and validation steps are followed. A CI key should be transfer-only and held behind protected-environment review; it must never have root credentials.

### Restricted SFTP transfer preparation — not enabled

This is the proposed design only. No production SSH configuration, account, key, directory permission, daemon or service was inspected or changed during this local milestone. The production account home, current contents/ownership of `/var/lib/gjej-deploy`, OpenSSH version/config includes and account key sources remain to be verified on an approved maintenance window. Do not assume the existing incoming directory is empty or safe as a chroot.

The preferred jail root is `/var/lib/gjej-deploy`, with only a dedicated `/incoming` subdirectory accessible inside it:

| Host path | Intended owner/mode | Purpose |
| --- | --- | --- |
| `/var`, `/var/lib` and `/var/lib/gjej-deploy` | `root:root`, directories not writable by group/others; `/var/lib/gjej-deploy` mode `0711` | Chroot path. Every path component must be root-owned and not writable by group/others. The jail root itself must not be writable or listable by `gjej-deploy`; execute/search is required to reach `/incoming`. |
| `/var/lib/gjej-deploy/incoming` | `gjej-deploy:gjej-deploy`, mode `0700` | Only upload destination. The client sees it as `/incoming`; root can read submissions for quarantine. The SFTP umask is `0077`. |
| `/etc/ssh/authorized_keys/gjej-deploy` | `root:root`, mode `0644` (parent directory root-owned, not writable by others) | The approved upload public key. Keep it outside the jail and user-writable paths. |

Keep the jail dedicated and nearly empty: no bind mounts or symlinks to releases, `.env`, Laravel source, receipts, Lidhje paths, host files or sockets. `ChrootDirectory` contains the root-owned jail, while the user-writable `incoming` child is the only writable path. The account's passwd home must resolve inside the jail (proposed `/incoming`); retain its existing non-login shell and no-sudo status. `internal-sftp` is in-process, so the jail does not need shell binaries. If any chroot component is not root-owned/non-writable, if the account home cannot be safely mapped, if the parent has unrelated content, or if incoming contains unreviewed files, **stop** and obtain an owner-approved relocation/cleanup plan. Do not recursively chown or chmod files to force the design.

Append a user-scoped block at the very end of `/etc/ssh/sshd_config` after inspecting the entire active configuration and includes. Preserve the existing global `Subsystem sftp` line and every Lidhje/admin setting; do not add a duplicate subsystem declaration. Validate that the production OpenSSH release supports every directive before use:

```sshconfig
Match User gjej-deploy
    AuthenticationMethods publickey
    PubkeyAuthentication yes
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    AuthorizedKeysFile /etc/ssh/authorized_keys/%u
    AuthorizedKeysCommand none
    TrustedUserCAKeys none
    ChrootDirectory /var/lib/gjej-deploy
    ForceCommand internal-sftp -d /incoming -u 0077 -P symlink,hardlink
    PermitTTY no
    PermitUserRC no
    DisableForwarding yes
    AllowTcpForwarding no
    AllowAgentForwarding no
    AllowStreamLocalForwarding no
    X11Forwarding no
    PermitTunnel no
```

`ForceCommand internal-sftp` prevents client-requested shells and commands; `DisableForwarding` blocks forwarding classes, with the individual directives retained for clarity and defense in depth. The jail prevents path traversal into host paths; `-P symlink,hardlink` rejects link creation inside the incoming area. `-u 0077` removes group/other access from newly created files/directories. The key line should use the OpenSSH `restrict` authorized-key option as another guard, for example `restrict ssh-ed25519 <approved-public-key> gjej artifact upload`. Add a `from="<approved-static-runner-CIDR>"` restriction only if the runner's stable egress range is confirmed. Do not put a private key on the server. OpenSSH documents root-owned chroot path requirements, in-process SFTP, forced-command behavior and the forwarding implications in its [sshd_config manual](https://man.openbsd.org/sshd_config); SFTP denied requests and umask options are in the [sftp-server manual](https://man.openbsd.org/sftp-server).

#### Future production change sequence — requires separate approval

1. **Preflight and preserve rollback state.** Keep the current admin SSH session open. Record the server OpenSSH version, active config and include files, effective settings for `gjej-deploy` and an admin user, account UID/GID/home/shell/groups/sudo status, and metadata for `/var/lib/gjej-deploy` and `incoming`. Use metadata-only inspection (`namei -l`, `stat`, and a shallow filename/type listing); do not read artifact contents. Check for symlinks, mounts and other service dependencies. Require owner confirmation if the existing path has unexpected contents. Save a root-only snapshot of `/etc/ssh/sshd_config` and every include that will be changed, preserving owner/mode, in a new `/root/ssh-gjej-prechange-<UTC timestamp>/` directory mode `0700`. Record prior account home and directory metadata separately. If the chroot ownership/path assumptions fail, stop before edits.
2. **Prepare only reviewed paths.** After confirming no conflicting use and an empty or separately handled incoming directory, the approved operator can apply these narrowly scoped changes; do not recurse and do not run them before the metadata/path preflight:

   ```sh
   chown root:root /var/lib/gjej-deploy
   chmod 0711 /var/lib/gjej-deploy
   install -d -o gjej-deploy -g gjej-deploy -m 0700 /var/lib/gjej-deploy/incoming
   usermod --home /incoming gjej-deploy
   install -d -o root -g root -m 0755 /etc/ssh/authorized_keys
   install -o root -g root -m 0644 /root/approved-key-staging/gjej-deploy.pub /etc/ssh/authorized_keys/gjej-deploy
   ```

   If any target is a symlink, a mount point, contains unreviewed files, or is used by another service, stop; these commands are not a substitute for investigating that condition. Keep the existing non-login shell, UID, groups and sudo state unchanged. Record the account's old home for rollback. Verify the installed public key fingerprint with its owner through a separate channel. Do not generate or expose a private key on the server.
3. **Apply the Match block and validate before any reload.** Make only the user-scoped SSH config addition. Run `sshd -t` (using the target daemon binary and active config) and stop without reload on any error. Run `sshd -T -C user=gjej-deploy,host=<server-host>,addr=<approved-client-address>` and verify the effective chroot, forced SFTP command, key-only authentication, no TTY, no forwarding, no CA/authorized-keys-command bypass and correct key file. Also compare `sshd -T -C` for existing admin and other SSH users against the saved baseline. Check chroot ownership and mode on every path component; `sshd -t` alone does not prove that runtime chroot access will pass.
4. **Controlled reload and acceptance.** Keep the original session open and first establish a second independent admin SSH session. Only after all pre-reload checks pass, reload the SSH service using the confirmed service unit (for example `systemctl reload ssh` or `sshd`; do not guess). From a controlled client, verify SFTP public-key login and upload a synthetic file to `/incoming`; verify checksum and restrictive mode as root, then remove only that synthetic file. Verify `cd ..` and host paths such as `/etc`, `/var/www`, and `/var/lib` cannot expose host content; SFTP has no view outside the jail. Verify shell/exec requests are not run, PTY is unavailable, and local/remote TCP, agent, X11, stream-local forwarding and tunnel requests fail. Confirm unrelated admin SSH access still works. Any failure triggers rollback; do not declare completion with CLI config checks alone.
5. **Rollback if needed.** Keep the admin session open. Restore the saved SSH config/include files byte-for-byte with their prior owner/mode; remove only the newly installed Gjej public-key file; restore the recorded passwd home and the exact prior directory owner/modes (never delete incoming contents). Run `sshd -t` against the restored active config; reload only if it passes. Recheck admin SSH from a second session. If restored config does not validate, do not reload; use the still-open administrative session and out-of-band console to repair. Preserve uploads for root review; never recursively delete or move user submissions as rollback.

The local macOS rehearsal validated a synthetic configuration using the host OpenSSH 9.0 `sshd -t` and `sshd -T -C`, and tested restoring a synthetic config snapshot byte-for-byte followed by `sshd -t`; it did not start a daemon or create a login. The repository contains an integration harness at `scripts/release/test_restricted_sftp.sh` and a secret-free Ubuntu 24.04 workflow at `.github/workflows/restricted-sftp-integration.yml`, triggered by pull requests targeting `main` and manual dispatch. The harness creates only synthetic accounts/passwords/keys/files, uses its own temporary `sshd_config`, verifies the effective policy and kernel listener are loopback-only, and removes the daemon, accounts, groups and temporary tree on exit. Its deploy key has no `authorized_keys` restrictions, and synthetic host fixtures are accessible to that account if the chroot is removed, so operation-level denials exercise the server policy. A hosted run passed on commit `4931542be402d614965b6296181297b67f2f70ae`, including SFTP transfer/isolation, prohibited forwarding requests, loopback-only listening and cleanup. The agent-forwarding check verifies the client requested it and sshd's effective policy disables it; it does not independently observe rejection of an agent channel. Run it only as root inside a disposable Ubuntu VM/runner:

```sh
sudo bash scripts/release/test_restricted_sftp.sh
```

The workflow installs OpenSSH server without allowing package hooks to start a system SSH service, then runs that same harness in its disposable runner. The successful hosted run verifies the harness there only; it does not verify production configuration, upload access, deployment or rollback. No production key/configuration is involved or created. Production implementation and reload still require separate approval.

Incoming files are writable by the submitter and are not trusted. Before promotion, root must copy the artifact, manifest and `.minisig` file into a root-owned, non-writable quarantine area and prevent replacement between verification and extraction. The signed verification step must use only root-configured Minisign public key(s), validate the exact expected signer identity from the signed trusted comment **before trusting any manifest metadata**, then verify the signed manifest digest, exact artifact filename/commit/release binding, artifact SHA-256 and archive structure. Missing, malformed, invalid, revoked-key or mismatched signatures fail closed; there is no custom-format or unsigned fallback. A checksum and manifest transferred together do not authenticate their origin. Promotion must also verify release ID/name consistency, expected files, owner/group/modes, environment and shared-storage links, platform requirements, absence of forbidden content, and no path/symlink escape. The existing promotion helper is **not approved as production-ready**: it has not been shown to enforce this signature contract or the full validation gate and pre-switch candidate smoke workflow described here. Do not use it to promote until a hardened version has passed review and tests.

The local repository now contains `scripts/release/install_release.py`. It snapshots bounded regular inputs into a private directory, verifies the Minisign signature against explicitly supplied trust roots and expected identity, extracts files through directory file descriptors without following archive links, refuses an existing release ID, and publishes only a new inactive release directory. Its CLI requires root and explicit target owner/group IDs; it does not change `current`, attach production environment/storage, run Laravel commands, apply migrations or perform candidate smoke checks. Synthetic tests cover successful extraction, existing-release refusal, untrusted-key refusal and symlink-input refusal. This local implementation has not been reviewed or exercised on the production host and does **not** authorize deployment or replace the existing promotion helper.

### Future candidate promotion gate

1. Build only from a clean, reviewed tag/commit with the local tool; retain its archive and manifest unchanged. Review the commit and lockfile diffs. Run isolated application tests, dependency audits, TypeScript checks, frontend build and `composer check-platform-reqs` before transfer.
2. Transfer only through the future restricted receiver. On the server, root seals a copy in quarantine, verifies the Minisign `.minisig` using only pinned trust policy before trusting the manifest, then verifies SHA-256 and archive structure without extraction. The local `scripts/release/install_release.py` is a candidate for this stage after independent code review and target-specific rehearsal; until then, use no production extractor. The reviewed installation flow must extract into a fresh staging directory, reject links/special files, prevent traversal, and never overwrite an existing release.
3. Install/verify production dependencies from the exact lock file if they were not built into the artifact. Create a protected release-local `bootstrap/cache`; link `/etc/gjej/rezervogk.env` and `/var/www/rezervogk/shared/storage` with reviewed ownership/modes. Keep private receipts outside the public root. Build Laravel caches as `gjej` only after environment and shared storage are attached; never package runtime caches or replace APP_KEY.
4. Run candidate checks against the candidate path before switching `current`: Laravel boots as `gjej`; expected routes and configuration load; PHP-FPM socket and permissions are correct; read-only DB/schema checks succeed; the Vite manifest references available assets; public assets load; private receipts remain denied to Nginx and `www-data`; safe application smoke tests pass. Any write test must use approved synthetic data. Do not call CLI-only checks a browser/user-flow test.
5. After separate release approval, and separate migration approval if a migration is needed, create a temporary `current.new` symlink and atomically rename it over `current`. Reload only the required service after config validation, run post-switch smoke checks, and record release ID, digest, operator, checks and time. Keep the prior release and shared storage intact. Exposing the service publicly still requires a separate launch approval.
6. If a candidate fails before switching, leave `current` untouched. If post-switch checks fail, atomically point `current` to the previous schema-compatible release, reload as approved and repeat checks. Never roll back shared storage, APP_KEY or database state as part of a code rollback.

Database migrations require separate explicit approval, target-specific backup/restore evidence, a reviewed migration list and an execution window; packaging and promotion must never run migrations automatically. Public launch, DNS/TLS changes, mail enablement, admin access, payments and data activation also require separate approvals. The local package process authorizes none of them.

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

A production release layout (reconfirm it on the target before deployment):

```text
/var/www/rezervogk/releases/<release-id>/ immutable code, vendor and build artifact
/var/www/rezervogk/current                pointer to the selected release
/var/www/rezervogk/shared/storage/         persistent application storage
/etc/gjej/rezervogk.env                   root-managed secrets; outside the public root
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
* * * * * cd /var/www/rezervogk/current && /usr/bin/php artisan schedule:run >> /var/www/rezervogk/shared/storage/logs/scheduler.log 2>&1
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

1. **Build an identified immutable candidate away from the active checkout.** Record source/lockfile/artifact checksums and the previous release. In an isolated test workspace run tests, dependency/security checks and TypeScript. Package a reviewed clean tag/commit using the local release tooling documented above; it performs the production Composer install from `composer.lock`, platform checks, `npm ci` and Vite build in a temporary clone. Never remove dev dependencies from this shared development checkout. Do not run Composer's setup/key-generation script.

   ```sh
   composer install --no-interaction
   composer audit --locked
   npm ci
   npm audit
   npm run typecheck
   python3 -m scripts.release.package_release --ref <tag-or-commit-sha> --output-dir <empty-local-output-directory>
   python3 -m scripts.release.validate_artifact <artifact.tar.gz> --manifest <artifact.tar.gz.manifest.json>
   ```

   PHP tests require dev dependencies in a separate test workspace and an explicitly isolated test DB. The package tool refuses dirty tracked/untracked worktrees, never uses ignored/uncommitted files as its source, and refuses an output directory inside the checkout. It also excludes `node_modules`, local `.env`, development/runtime caches, QA SQLite databases, backups, logs, user files and `public/hot`; validation fails closed if forbidden content remains. Keep `public/build/manifest.json` and all referenced assets together. Retain old hashed assets long enough for existing browser clients; changing a release pointer must not immediately invalidate their asset URLs. The checksum is not a signature and does not authenticate an artifact when its manifest is supplied by the same untrusted party.
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
