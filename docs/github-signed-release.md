# Gjej Portier — signed release build, upload, and inactive installation

## Scope and fixed boundaries

Only a push to `main` triggers release automation. A feature branch push does not start this workflow. Configure `main` to require an approved pull request and required validation checks so normal release runs follow a reviewed merge. This workflow intentionally has no manual dispatch trigger, which prevents write-access users from requesting a signing run on an arbitrary SHA.

The workflow builds the exact commit, creates the existing v1 archive and manifest, signs the manifest with the Gjej release key, verifies that signature against the pinned production public key and identity, sends the three files by SFTP to a unique `/incoming/run-<run-id>-<attempt>` directory, and publishes only those three signed release files as a 14-day GitHub Actions artifact. The unsigned archive and manifest cross from a secret-free build job to a fresh signing job as a one-day Actions artifact; the signing and SFTP credentials never enter the build job. The workflow does not use SSH shell commands or production Artisan/migration commands, install a release, change `current`, restart services, or activate production. An administrator must separately invoke the root-owned receiver with `sudo` to install an inactive candidate. Activation remains a separate manual approval and tool.

The SFTP account is fixed to `gjej-deploy`. The workflow does not run `ssh`, `scp`, or a remote command. It uses only `sftp -b`, a pinned `known_hosts` file, strict host checking, and the restricted account. The SFTP key is used only by the upload step. PR workflows remain secret-free.

## Workflow and stable checks

| Workflow/check | Trigger | Purpose |
| --- | --- | --- |
| `PR validation` | Pull request targeting `main` | PHP/Laravel, frontend, TypeScript, build, formatting and release-tool checks; no secrets. |
| `Disposable Ubuntu SFTP jail test` | Pull request targeting `main`, or manual dispatch | Tests a synthetic jail in the GitHub-hosted runner; never connects to production. |
| `Gjej Portier signed release upload` | Push to `main` | Packages, signs, verifies, uploads by restricted SFTP, then stores the signed release bundle as a short-lived Actions artifact. |
| `Verify Gjej release signing key` | Manual dispatch on protected `main` | Signs and verifies only a synthetic message against the v2 key; no SFTP credentials or deployment steps. Requires the protected `gjej-release-key-verification` environment. |

The release job requests `contents: read`; checkout credentials are not persisted; third-party Actions are pinned to full commit SHAs. Configure repository rules to require PR review plus the two stable PR checks. Keep workflow and release-tool changes behind trusted review. Do not expose the deployment or signing secrets to pull-request jobs. The signing-and-upload job runs only on `main` and declares the protected `gjej-production-release` environment; see the next section for the settings that make that protection real.

## GitHub configuration required

GitHub has no setting that restricts a repository secret to one workflow. A repository-level secret is readable by any workflow run in this repository, including a workflow file added on a feature branch by anyone with write access. The release credentials must therefore be stored only as **environment secrets** of the protected `gjej-production-release` environment, which only the `Sign and upload inactive candidate` job declares. Naming the environment in YAML does not protect it; the settings below must be applied and checked.

Create the environment `gjej-production-release` (Settings → Environments) and configure:

1. **Deployment branches and tags:** "Selected branches and tags", allowing only `main`. This is what stops a job on any other branch from receiving the secrets.
2. **Required reviewers (recommended):** add an owner/maintainer. Each merge to `main` then waits for that approval before signing and upload start. If you are the only maintainer, do not enable "Prevent self-review", or the job can never be approved.
3. **Environment secrets:** add `GJEJ_RELEASE_SIGNING_KEY` and `GJEJ_DEPLOY_SSH_KEY` here, as described below.
4. **Remove repository secrets:** delete any repository-level (Settings → Secrets and variables → Actions → Repository secrets) or organization-level copies of `GJEJ_RELEASE_SIGNING_KEY` and `GJEJ_DEPLOY_SSH_KEY`. An environment secret overrides a same-named repository secret for that job, but the repository copy would still be readable by every other workflow.
5. Confirm that environment protection rules are available for this repository's plan and visibility. If they are not, the branch restriction and reviewers do not apply and the secrets must not be added.

The following secrets belong in the `gjej-production-release` environment only:

| Secret | Required value |
| --- | --- |
| `GJEJ_RELEASE_SIGNING_KEY` | A JSON text object with exactly `private_key` and `passphrase` string fields. `private_key` is the complete existing encrypted Minisign secret-key file text; `passphrase` unlocks that key. Keep the JSON only in the GitHub secret UI or an approved secret manager. Do not place it in a repository file, shell command, Actions artifact, or log. |
| `GJEJ_DEPLOY_SSH_KEY` | The private key for the existing restricted `gjej-deploy` SFTP account. It must be usable non-interactively; it must not be an administrator or root SSH key. |

Required variables (public connection metadata, not credentials). Set them as variables of the `gjej-production-release` environment; repository-level variables also work but are visible to every workflow:

| Variable | Required value |
| --- | --- |
| `GJEJ_DEPLOY_HOST` | The verified Gjej Droplet host or IP. Confirm the target in DigitalOcean before setting it. |
| `GJEJ_DEPLOY_KNOWN_HOSTS` | The exact OpenSSH `known_hosts` line for that host, obtained and fingerprint-verified through a trusted channel. The workflow never uses `ssh-keyscan` as a trust source. The entry must use the same hostname/IP as `GJEJ_DEPLOY_HOST` and the default SSH port. |

The repository-tracked Minisign public key is [`config/gjej-release-v2.pub`](../config/gjej-release-v2.pub), key ID `7984D1DCD74103CC`; the fixed signer identity is `gjej-production-release`. The older key at `/etc/gjej-release/minisign.pub` has ID `0B36E1F797FBF71D`; it is a different retained key and is not a receiver fallback. The v2 key at `/etc/gjej/gjej-release-v2.pub` has been confirmed on the Gjej Droplet to match the repository file by key ID and SHA-256. The receiver and bootstrap use only that explicitly configured v2 path. The production release workflow signs and verifies each release against the repository pin before SFTP upload, so a mismatched GitHub secret fails before upload. A successful run of the verification-only workflow described below confirms the current GitHub secret matches the v2 pin without uploading or deploying anything.

The receiver's only trust path is `/etc/gjej/gjej-release-v2.pub`. Bootstrap verifies that this path is a regular non-symlink file, owned by `root:root`, mode `0644`, with the pinned v2 key ID and exact SHA-256. It never creates, copies, replaces, or overwrites a trust file. The legacy `/etc/gjej-release/minisign.pub` is ignored and remains untouched; the receiver cannot fall back to it. A missing, malformed, insecure, or mismatched v2 key stops bootstrap and receiver processing. Release protocol v1 remains unchanged.

The secret bundle format lets the encrypted private key remain encrypted. The signing helper feeds the passphrase to Minisign over a private pseudo-terminal; it does not put the passphrase in command-line arguments or a child-process environment variable. The bundle is decoded only in a mode-0700 temporary directory on the fresh ephemeral signing runner, removed by a shell exit trap, and never uploaded. The SSH credential is exposed only to the separate SFTP step and is removed by that step's exit trap. If the account's current GitHub secret is not in this JSON format, signing intentionally fails closed until an authorized maintainer updates the secret. Do not replace an encrypted key with an unencrypted key just to make CI work.

## Server-side receiver bootstrap — manual, reviewed sudo step

The repository contains a bootstrap script but has not run it on the Droplet. It does not alter SSH configuration, groups, memberships, Nginx/PHP-FPM, services, application code, database, releases, or the `current` symlink. It validates the existing restricted account and directories; verifies the preinstalled v2 public key against the hard-coded key ID and SHA-256; ignores the separate legacy key; installs root-owned copies of the fixed receiver/verifier/installer/trust-check tools; creates a root-only receiver configuration using the live numeric IDs; and installs a root-only audit log rotation rule. It never creates or overwrites a trust anchor and does not install packages. It refuses conflicting pre-existing files/configuration. The bootstrap is not transactional: a later conflict can leave earlier, identical reviewed files/directories installed, so inspect state and rerun only after resolving the reported conflict.

Before running it, an administrator must compare the reviewed branch to the intended source, confirm the selected signer public key and fingerprint out-of-band, and inspect these read-only values on the **Gjej Portier Droplet only**:

```bash
hostname
getent passwd gjej gjej-deploy
getent group gjej-public
stat -c '%A %U:%G %n' /var/lib/gjej-deploy /var/lib/gjej-deploy/incoming /var/www/rezervogk/releases
sudo minisign -v
sha256sum config/gjej-release-v2.pub /etc/gjej/gjej-release-v2.pub
cmp -s config/gjej-release-v2.pub /etc/gjej/gjej-release-v2.pub && echo 'trusted public key files match'
if sudo test -e /etc/gjej-release/minisign.pub; then
  sudo stat -c '%A %U:%G %n' /etc/gjej-release/minisign.pub
  sudo sha256sum /etc/gjej-release/minisign.pub
fi
```

Compare public-key IDs and SHA-256 file digests with the owner-approved values before bootstrap; do not print key material in shared logs. These checks use only public key files; never copy a private key to the server. The confirmed legacy ID `0B36E1F797FBF71D` differs from v2 ID `7984D1DCD74103CC`; this is expected because the receiver is pinned explicitly to v2 and does not consult the legacy path. No bootstrap or deployment is authorized by this document. If the server does not already have Minisign >= 0.12 at `/usr/local/bin/minisign`, stop for a separately reviewed tooling installation. Do not alter `gjej-public` or other groups to make the bootstrap pass.

### Verification-only signing-key workflow

`.github/workflows/verify-release-signing-key.yml` is manually dispatched and refuses to run unless the selected ref is `main`. It uses only `contents: read`, receives only `GJEJ_RELEASE_SIGNING_KEY`, creates a synthetic message in a mode-0700 temporary directory, signs it with the encrypted key, and verifies it using the pinned v2 public key. It does not receive `GJEJ_DEPLOY_SSH_KEY`, connect to a server, upload an artifact, or run deployment/activation/migration commands. The verifier suppresses Minisign output and reports only pass/fail plus the public key ID. Temporary key, passphrase and signature files are removed by the shell exit/signal traps; the GitHub-hosted runner is ephemeral.

GitHub environment protection is configured in repository settings and is not enabled merely by naming an environment in YAML. Before dispatching this workflow, an owner must create the environment `gjej-release-key-verification` and configure:

1. **Required reviewers:** select an owner/maintainer who can independently approve the check; enable prevention of self-review where available.
2. **Deployment branches and tags:** allow only the `main` branch.
3. Confirm that environment approval is available for this repository's plan and that the workflow job is held until approval. Add only `GJEJ_RELEASE_SIGNING_KEY` as an environment secret here; do not add `GJEJ_DEPLOY_SSH_KEY` or other deployment secrets to this environment.

This environment holds its own copy of `GJEJ_RELEASE_SIGNING_KEY`, separate from the copy in `gjej-production-release`. A successful verification run proves only that this copy matches the v2 pin. Update both copies together from the same source. The release workflow independently verifies every signature against the pin before upload, so a mismatched release-environment copy still fails closed.
4. Protect `main` with required PR review and required checks, and restrict who can change workflows/secrets.

After those settings are confirmed, dispatch `Verify Gjej release signing key` with ref `main`. The secret is verified only if that run completes successfully; merely configuring the workflow or environment is not proof.

The bootstrap itself is not yet signed by a separate offline tooling key. Its authenticity therefore depends on an administrator obtaining the exact reviewed commit from authenticated GitHub and reviewing the scripts before using `sudo`. The offline tooling-signing key remains a separate approved design item; it has not been generated or installed here.

After an explicit server-side implementation approval, run from a reviewed checkout of this branch/main on the Gjej Droplet:

```bash
sudo bash scripts/release/bootstrap_receiver.sh
sudo stat -c '%A %U:%G %n' \
  /usr/local/sbin/gjej-receive \
  /usr/local/lib/gjej-release-tools \
  /etc/gjej/release-receiver.json \
  /etc/gjej/gjej-release-v2.pub \
  /var/lib/gjej-release-receiver \
  /var/lib/gjej-release-receiver/quarantine \
  /etc/logrotate.d/gjej-release-receiver
sudo /usr/local/bin/minisign -v
```

Do not run these commands as part of this code change. They modify only Gjej receiver/tooling paths when separately approved.

## Manual inactive candidate receive

After the GitHub workflow succeeds, find `SUBMISSION_ID` in the workflow log. That identifier is non-secret. The SFTP-uploaded directory contains exactly one archive, its manifest, and its detached signature; interrupted `.part` files or any unexpected entries cause refusal. The SFTP user cannot run the receiver.

An authorized administrator uses a separate admin SSH session and manually runs:

```bash
sudo /usr/local/sbin/gjej-receive --submission-id run-<run-id>-<attempt>
```

The receiver accepts only the fixed ID syntax and fixed incoming/quarantine/releases/key paths. It verifies the uploader UID, private non-symlink directory and file ownership/modes; snapshots the exact three regular files into a root-only quarantine; rejects duplicates and partial/unexpected uploads; records root-only JSONL audit events; verifies the existing v1 signature, fixed public key and exact signer identity; and calls the existing `install_signed_release` function with only fixed paths and configured numeric IDs. Release code is installed `root:gjej-public` with read/execute access for the application and web-server group, so the runtime user cannot edit deployed code. The receiver makes only the new release's empty `bootstrap/cache` directory writable by `gjej` for Laravel-generated caches. The existing installer re-snapshots, verifies again, validates the archive, rejects overwrite, and creates only a new release directory. The receiver confirms `installed-inactive` and `activated: false`. It has no activation, rollback, Artisan, migration, shell, or environment-override option. Failed submissions stay quarantined for review; no cleanup is automatic.

The server evidence supplied earlier showed `gjej` and `www-data` are members of `gjej-public`, but did not establish the supplementary groups of an actual `php-fpm: pool gjej` worker. Candidate validation must verify that runtime access before any activation. This change does not alter memberships or PHP-FPM settings.

Audit data is `/var/log/gjej-release-receiver.jsonl`, root-owned mode `0600`; the bootstrap installs daily log rotation for 400 rotations with mode-0600 root-owned files. Quarantine copies are under `/var/lib/gjej-release-receiver/quarantine/<submission-id>` mode `0700`; there is no automatic quarantine deletion. Include tool/config/key/audit state in approved off-host recovery planning; the application backup/restore gate remains separate.

The administrator must independently run the candidate readiness checks from the production runbook. Passing candidate checks does not grant activation approval. This receiver never switches `/var/www/rezervogk/current`, runs migrations, or changes services. Activation and rollback remain separate root-controlled operations requiring their own explicit owner approval, approval-document verification, replay protection, and backup/restore evidence. If those mechanisms or the recovery gate are not ready, keep the candidate inactive.

The receiver does not attach `.env` or `storage` automatically. If separately approved for candidate preparation, an administrator must add links to the existing Gjej-only paths on the Droplet (never any Lidhje path):

```bash
RELEASE=/var/www/rezervogk/releases/<release-id>
sudo ln -s /etc/gjej/rezervogk.env "$RELEASE/.env"
sudo ln -s /var/www/rezervogk/shared/storage "$RELEASE/storage"
```

That environment uses the existing Gjej production database and storage. Keep validation read-only; do not run migrations or checks that write application data. The release remains inactive and not publicly routed until the separate activation approval.

## Failure handling and recovery

- A workflow fails closed on an absent secret/variable, bad key bundle, signature mismatch, unpinned SSH host, failed SFTP operation, selected SHA not on `main`, or missing build output. It does not fall back to shell SSH, password auth, a different host key, or an unsigned upload.
- Each workflow attempt uses a unique remote submission ID. A retried submission cannot overwrite an existing directory. Start a new workflow attempt for a new submission; do not delete an old submission to retry.
- The receiver serializes invocations with a root-owned lock. It preserves the quarantine copy and logs `snapshot-started`, `signature-verified`, `install-started`, and a terminal event. If interrupted, there may be no terminal event; reconcile the last audit event, quarantine, and release directory before any retry. The submission ID remains consumed and cannot be replayed. It never removes or overwrites an existing release.
- A successful receive only adds a new inactive directory. Existing `current` and previous release directories stay unchanged. If installation fails, inspect the retained quarantine and audit record; do not manually edit release files or change `current` as a recovery shortcut.
- Activation rollback instructions remain in the separately approved production activation runbook. This upload/receiver workflow cannot activate or roll back.

## Current automation limits and manual actions

The new workflow automates build, signing, verification, restricted upload, and a signed GitHub artifact. The sudo bootstrap and the manual root receiver invocation remain operator actions. Candidate validation, activation approval, rollback, backups and restore drills remain separate gates. No production server has been modified by this repository change, and no deployment or activation has been performed.
