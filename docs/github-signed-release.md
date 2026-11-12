# Gjej Portier — GitHub release build and signed deployment handoff

## Scope and boundaries

Production release builds are created only from commits reachable from `main`. Pull-request checks run without secrets. The post-merge workflow packages the selected main commit and publishes the archive and manifest as a short-lived GitHub Actions artifact.

The GitHub runner does **not** hold a Minisign private key, receive SSH credentials, connect to the production server, run a shell command on production, install a release, or activate one. The artifact is unsigned until the authorized operator signs it with the protected release key in the operator's trusted environment. The separately controlled activation approval remains outside this workflow.

This is intentional: the repository contains the safe inactive installer, but a production-side root-owned receiver/approval service has not been verified as installed. GitHub Actions therefore cannot safely request server-side inactive installation. No automatic migrations, symlink changes, service reloads, or production-data operations are part of these workflows.

## Workflows

| Workflow | Trigger | Purpose |
| --- | --- | --- |
| `PR validation` | Pull request targeting `main` | PHP/Laravel tests, changed-file Pint check, frontend tests, TypeScript, Vite build, and release-tool tests. Uses GitHub-hosted runners and no secrets. |
| `Disposable Ubuntu SFTP jail test` | Pull request targeting `main`, or manual dispatch | Tests the restricted SFTP configuration in an ephemeral runner. It never connects to production. |
| `Gjej Portier post-merge release build` | Push to `main`, or manual dispatch on `main` for an explicit full commit SHA | Packages that exact commit and publishes the archive and manifest for offline signing. It does not sign or deploy. |

Third-party Actions are pinned to full commit SHAs. Workflows request only `contents: read`; checkout credentials are not persisted. Configure repository rules so PR validation and the SFTP integration check are required before merging, and restrict who can merge to `main`. The repository's branch protection and environment settings are not changed by these files.

## Release candidate process

1. Merge an approved PR into `main`. Feature-branch pushes do not run the release build.
2. Wait for the post-merge release-build workflow to finish. For a manual rebuild, dispatch it on `main` and provide a full 40-character commit SHA that is already reachable from `main`.
3. Download the `gjej-release-<commit-sha>` Actions artifact. It contains one `gjej-<release-id>.tar.gz` archive and its matching `.manifest.json` file. The package tool builds production Composer dependencies and Vite assets from the selected commit, validates the archive, and records the archive and lockfile digests in the manifest.
4. In the operator's trusted signing environment, use the checked-out `main` version of `scripts/release/sign_artifact.py` with the protected release key and the identity `gjej-production-release`. The signer validates the archive/manifest pair and creates the detached `.minisig` file. Do not upload the private key to GitHub or production. Keep the private key out of the repository and terminal transcripts.
5. Transfer the archive, manifest, and signature through the restricted SFTP account into its incoming directory. SFTP has upload-only access and cannot execute commands.
6. An authorized production administrator must independently quarantine the three files as root, verify the signature against the fixed production trust key and expected identity, and run the root-owned inactive installer with the established `gjej` UID and `gjej-public` GID. The installer writes a new release directory only. Follow the production runbook's candidate checks and separately approved activation process; do not treat successful installation as activation approval.

The release ID convention is `<first-12-commit-hex>-<UTC timestamp YYYYMMDDTHHMMSSZ>`, and the archive name is `gjej-<release-id>.tar.gz`. The signed metadata binds that filename, archive digest, manifest digest, full commit SHA, release ID, and signer identity. Protocol v1 remains in force; protocol changes require explicit versioning and approval.

## Required manual repository configuration

- Protect `main`, require PR review, and require the stable checks `PR validation` and `Disposable Ubuntu SFTP jail test`.
- Ensure only trusted maintainers can merge to `main` and change workflow or release-tool files.
- Keep artifact download access restricted to repository collaborators who are authorized to handle production release candidates. The Actions artifact expires after 14 days; download and sign it promptly.
- No GitHub secrets or production environment are required by these workflows. Do not add signing or SSH secrets unless a separately reviewed design explicitly changes the offline signing and SFTP-only boundaries.

## Current automation limits and manual actions

The workflow automates validation, production dependency installation, asset compilation, archive creation, manifest creation, and archive validation. Signing, SFTP transfer, root quarantine, server-side verification, inactive installation, candidate checks, and activation remain operator-controlled. This avoids granting GitHub a key or production command channel and preserves an explicit activation gate.

Before automating server-side inactive installation, separately verify and review a root-owned receiver that accepts only the three expected regular files, invokes only the fixed v1 verifier/installer with fixed paths and IDs, prevents duplicate/concurrent or partial submissions, and records an audit result. It must never activate. Do not use a filesystem watcher or permit SFTP/GitHub to run arbitrary commands. Production activation remains blocked until the separately approved operator-approval key, replay/expiry enforcement, rollback evidence, and fresh encrypted off-host backup/restore gate are implemented and verified.
