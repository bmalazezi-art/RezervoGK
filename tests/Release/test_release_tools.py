from __future__ import annotations

import hashlib
import json
import os
import pty
import re
import shutil
import selectors
import subprocess
import tarfile
import termios
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts.release.common import ReleaseError, isolated_git_environment, require_clean_checkout, resolve_release_ref
from scripts.release import validate_artifact as validator
from scripts.release import package_release as packager
from scripts.release import install_release as installer
from scripts.release.package_release import PROJECT_DIRS, ROOT_FILES, copy_committed_project, reject_lfs_pointers, reject_submodules
from scripts.release.sign_artifact import sign_artifact, verify_signed_artifact
from scripts.release.install_release import install_signed_release
from scripts.release import receive_release as receiver
from scripts.release import trusted_key as trusted_key_tools
from scripts.release import verify_signing_key as signing_key_check
from scripts.release.validate_artifact import REQUIRED_FILES, validate_archive


ROOT = Path(__file__).resolve().parents[2]
REQUIRED_FILES = (
    "artisan",
    "bootstrap/app.php",
    "bootstrap/providers.php",
    "config/app.php",
    "config/filesystems.php",
    "config/session.php",
    "composer.json",
    "composer.lock",
    "database/migrations/placeholder.php",
    "lang/en.json",
    "lang/sq.json",
    "package-lock.json",
    "package.json",
    "public/index.php",
    "public/build/manifest.json",
    "public/build/assets/app-a1b2.js",
    "resources/views/app.blade.php",
    "routes/web.php",
    "routes/console.php",
    "vendor/autoload.php",
    "vendor/composer/installed.php",
)


def git(repo: Path, *args: str) -> str:
    return subprocess.run(
        ["git", "-C", str(repo), *args], check=True, text=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
    ).stdout.strip()


def make_valid_artifact(directory: Path, *, marker: str = "synthetic", commit_prefix: str = "0123456789ab", timestamp: str = "20261105T120000Z") -> tuple[Path, Path]:
    release_id = f"{commit_prefix}-{timestamp}"
    archive_path = directory / f"gjej-{release_id}.tar.gz"
    manifest_path = directory / f"{archive_path.name}.manifest.json"
    files = {
        name: f"{marker} {name}\n".encode()
        for name in REQUIRED_FILES
        if name not in {"public/build/manifest.json", "public/build/assets/app-a1b2.js"}
    }
    files["public/build/manifest.json"] = json.dumps({"entry": {"file": "assets/app-synthetic.js"}}).encode()
    files["public/build/assets/app-synthetic.js"] = f"{marker} asset\n".encode()
    directories = {
        "bootstrap", "config", "database", "database/migrations", "lang", "public", "public/build",
        "public/build/assets", "resources", "resources/views", "routes", "vendor", "vendor/composer",
    }
    with tarfile.open(archive_path, "w:gz", format=tarfile.USTAR_FORMAT) as tar:
        for name in sorted(directories):
            info = tarfile.TarInfo(name)
            info.type = tarfile.DIRTYPE
            info.mode = 0o750
            info.uid = info.gid = 0
            tar.addfile(info)
        import io
        for name, data in files.items():
            info = tarfile.TarInfo(name)
            info.size = len(data)
            info.mode = 0o640
            info.uid = info.gid = 0
            tar.addfile(info, io.BytesIO(data))
    manifest = {
        "schema_version": 1,
        "release_id": release_id,
        "git_ref": "synthetic-release",
        "commit_sha": commit_prefix + "1" * (40 - len(commit_prefix)),
        "build_timestamp_utc": f"{timestamp[:4]}-{timestamp[4:6]}-{timestamp[6:8]}T{timestamp[9:11]}:{timestamp[11:13]}:{timestamp[13:15]}Z",
        "composer_lock_sha256": hashlib.sha256(files["composer.lock"]).hexdigest(),
        "npm_lock_sha256": hashlib.sha256(files["package-lock.json"]).hexdigest(),
        "archive": {
            "file": archive_path.name,
            "size_bytes": archive_path.stat().st_size,
            "sha256": hashlib.sha256(archive_path.read_bytes()).hexdigest(),
        },
    }
    manifest_path.write_text(json.dumps(manifest, sort_keys=True), encoding="utf-8")
    return archive_path, manifest_path


class GitSourceTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.repo = Path(self.temp.name) / "repo"
        self.repo.mkdir()
        git(self.repo, "init", "-q")
        git(self.repo, "config", "user.name", "Synthetic Test")
        git(self.repo, "config", "user.email", "synthetic@example.invalid")
        (self.repo / "tracked.txt").write_text("fixture\n")
        git(self.repo, "add", "tracked.txt")
        git(self.repo, "commit", "-q", "-m", "synthetic")
        self.commit = git(self.repo, "rev-parse", "HEAD")
        git(self.repo, "tag", "synthetic-v1")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def test_clean_checkout_and_selected_tag_or_commit(self) -> None:
        require_clean_checkout(self.repo)
        self.assertEqual(resolve_release_ref(self.repo, "synthetic-v1").commit, self.commit)
        self.assertEqual(resolve_release_ref(self.repo, self.commit).commit, self.commit)

    def test_dirty_tracked_and_untracked_checkout_refused(self) -> None:
        (self.repo / "tracked.txt").write_text("changed\n")
        with self.assertRaisesRegex(ReleaseError, "dirty"):
            require_clean_checkout(self.repo)
        git(self.repo, "checkout", "--", "tracked.txt")
        (self.repo / "untracked.txt").write_text("synthetic\n")
        with self.assertRaisesRegex(ReleaseError, "dirty"):
            require_clean_checkout(self.repo)

    def test_missing_tag_and_head_ref_refused(self) -> None:
        with self.assertRaises(ReleaseError):
            resolve_release_ref(self.repo, "missing-tag")
        with self.assertRaises(ReleaseError):
            resolve_release_ref(self.repo, "HEAD")

    def test_git_environment_ignores_external_repository_and_filter_overrides(self) -> None:
        with patch.dict("os.environ", {"GIT_DIR": "/synthetic/wrong-repo", "GIT_CONFIG_COUNT": "1", "GIT_CONFIG_KEY_0": "filter.lfs.smudge", "GIT_CONFIG_VALUE_0": "synthetic-smudge"}):
            env = isolated_git_environment()
        self.assertNotIn("GIT_DIR", env)
        self.assertNotIn("GIT_CONFIG_COUNT", env)
        self.assertEqual(env["GIT_CONFIG_NOSYSTEM"], "1")

    def test_lfs_pointer_is_rejected_without_hydration(self) -> None:
        source = self.repo / "public"
        source.mkdir()
        (source / "synthetic-photo.png").write_text(
            "version https://git-lfs.github.com/spec/v1\n"
            "oid sha256:" + "a" * 64 + "\nsize 123\n",
            encoding="ascii",
        )
        with self.assertRaisesRegex(ReleaseError, "Git LFS pointer"):
            reject_lfs_pointers(self.repo)

    def test_submodule_gitlink_is_rejected(self) -> None:
        git(self.repo, "update-index", "--add", "--cacheinfo", f"160000,{self.commit},synthetic-submodule")
        git(self.repo, "commit", "-q", "-m", "synthetic gitlink")
        selected = git(self.repo, "rev-parse", "HEAD")
        with self.assertRaisesRegex(ReleaseError, "Git submodules"):
            reject_submodules(self.repo, selected)

    def test_committed_tree_copy_creates_a_fresh_destination(self) -> None:
        source = Path(self.temp.name) / "committed-tree"
        source.mkdir()
        for name in ROOT_FILES:
            (source / name).write_text("synthetic\n", encoding="utf-8")
        for name in PROJECT_DIRS:
            (source / name).mkdir()
            (source / name / "synthetic.txt").write_text("committed\n", encoding="utf-8")
        destination = Path(self.temp.name) / "build-tree"
        copy_committed_project(source, destination)
        self.assertEqual((destination / "composer.lock").read_text(), "synthetic\n")
        self.assertFalse((destination / "vendor").exists())


class ReceiverBootstrapPolicyTests(unittest.TestCase):
    def test_bootstrap_requires_a_preinstalled_pin_and_never_installs_one(self) -> None:
        bootstrap = (ROOT / "scripts/release/bootstrap_receiver.sh").read_text(encoding="utf-8")
        self.assertIn("TRUSTED_KEY=/etc/gjej/gjej-release-v2.pub", bootstrap)
        self.assertNotIn("LEGACY_TRUSTED_KEY", bootstrap)
        self.assertIn("trusted_key.py", bootstrap)
        self.assertIn('PYTHONPATH="$SOURCE_ROOT" /usr/bin/python3 -m scripts.release.trusted_key', bootstrap)
        self.assertNotIn('"$SOURCE_ROOT/config/gjej-release-v2.pub" "$TRUSTED_KEY"', bootstrap)
        receiver_source = (ROOT / "scripts/release/receive_release.py").read_text(encoding="utf-8")
        self.assertIn('"trusted_public_key": "/etc/gjej/gjej-release-v2.pub"', receiver_source)
        self.assertNotIn("/etc/gjej-release/minisign.pub", receiver_source)

    def test_bootstrap_minisign_version_check_requires_0_12_or_newer(self) -> None:
        bootstrap = (ROOT / "scripts/release/bootstrap_receiver.sh").read_text(encoding="utf-8")
        match = re.search(
            r"# BEGIN minisign-version-check\n(.*?)# END minisign-version-check", bootstrap, re.DOTALL
        )
        self.assertIsNotNone(match)
        check = match.group(1)
        cases = {
            "minisign 0.12": True,
            "minisign 0.12\n": True,
            "minisign 0.13": True,
            "minisign 0.20": True,
            "minisign 0.100": True,
            "minisign 1.0": True,
            "minisign 0.09": False,
            "minisign 0.10": False,
            "minisign 0.11": False,
            "minisign 0.11.1": False,
            "minisign 0.1": False,
            "minisign 0.2": False,
            "notminisign 0.12": False,
            "minisign": False,
            "": False,
        }
        for output, accepted in cases.items():
            with self.subTest(output=output):
                result = subprocess.run(
                    ["bash", "-c", 'set -Eeuo pipefail\nMINISIGN_VERSION="$1"\n' + check + "echo accepted\n", "check", output],
                    stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False, timeout=10,
                )
                self.assertEqual(result.returncode == 0 and result.stdout == b"accepted\n", accepted, result.stderr)

    def test_release_credentials_are_only_referenced_by_the_protected_environment_job(self) -> None:
        workflow = (ROOT / ".github/workflows/release-build.yml").read_text(encoding="utf-8")
        build_start = workflow.index("\n  build-release:\n")
        sign_start = workflow.index("\n  sign-and-upload:\n")
        self.assertLess(build_start, sign_start)
        preamble, build_job, sign_job = workflow[:build_start], workflow[build_start:sign_start], workflow[sign_start:]
        for section in (preamble, build_job):
            self.assertNotIn("secrets.", section)
            self.assertNotIn("environment:", section)
        self.assertIn("    if: github.ref == 'refs/heads/main'\n", sign_job)
        self.assertIn("    environment:\n      name: gjej-production-release\n", sign_job)
        self.assertEqual(
            sorted(set(re.findall(r"secrets\.([A-Z0-9_]+)", sign_job))),
            ["GJEJ_DEPLOY_SSH_KEY", "GJEJ_RELEASE_SIGNING_KEY"],
        )
        self.assertNotIn("workflow_dispatch", workflow)
        self.assertNotIn("pull_request", workflow)

    def test_verification_workflow_is_manual_protected_and_has_no_deployment_capability(self) -> None:
        workflow = (ROOT / ".github/workflows/verify-release-signing-key.yml").read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch:", workflow)
        self.assertIn("if: github.ref == 'refs/heads/main'", workflow)
        self.assertIn("name: gjej-release-key-verification", workflow)
        self.assertIn("contents: read", workflow)
        self.assertIn("GJEJ_RELEASE_SIGNING_KEY", workflow)
        self.assertNotIn("GJEJ_DEPLOY_SSH_KEY", workflow)
        self.assertNotIn("sftp ", workflow.lower())
        self.assertNotIn("ssh ", workflow.lower())
        self.assertNotIn("pull_request_target", workflow)
        self.assertIn('content.replace(b"\\r\\n", b"\\n").rstrip(b"\\n") + b"\\n"', workflow)
        self.assertIn('len(content.splitlines()) != 2', workflow)


class TrustedV2KeyTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        os.chmod(self.root, 0o700)
        self.repository_key = ROOT / "config/gjej-release-v2.pub"
        self.installed_key = self.root / "gjej-release-v2.pub"
        shutil.copyfile(self.repository_key, self.installed_key)
        self.installed_key.chmod(0o644)
        self.owner_uid = os.getuid()
        self.group_gid = os.getgid()

    def tearDown(self) -> None:
        self.temp.cleanup()

    def validate(self) -> str:
        return trusted_key_tools.validate_trust_pair(
            self.repository_key,
            self.installed_key,
            owner_uid=self.owner_uid,
            group_gid=self.group_gid,
        )

    def test_matching_v2_key_succeeds_with_a_distinct_legacy_key_present(self) -> None:
        legacy = self.root / "legacy-minisign.pub"
        legacy.write_text("legacy key fixture\n", encoding="ascii")
        self.assertEqual(self.validate(), trusted_key_tools.V2_KEY_ID)

    def test_matching_v2_key_succeeds_without_a_legacy_key(self) -> None:
        self.assertEqual(self.validate(), trusted_key_tools.V2_KEY_ID)

    def test_missing_v2_key_fails_closed(self) -> None:
        self.installed_key.unlink()
        with self.assertRaisesRegex(ReleaseError, "unavailable"):
            self.validate()

    def test_v2_fingerprint_mismatch_fails_closed(self) -> None:
        lines = self.installed_key.read_bytes().splitlines()
        lines[1] = lines[1][:-1] + (b"A" if lines[1][-1:] != b"A" else b"B")
        self.installed_key.write_bytes(b"\n".join(lines) + b"\n")
        with self.assertRaisesRegex(ReleaseError, "does not match"):
            self.validate()

    def test_malformed_v2_key_fails_closed(self) -> None:
        self.installed_key.write_text("not a Minisign public key\n", encoding="ascii")
        with self.assertRaisesRegex(ReleaseError, "format is invalid"):
            self.validate()

    def test_v2_symlink_fails_closed(self) -> None:
        replacement = self.root / "replacement.pub"
        replacement.write_bytes(self.installed_key.read_bytes())
        replacement.chmod(0o644)
        self.installed_key.unlink()
        self.installed_key.symlink_to(replacement)
        with self.assertRaisesRegex(ReleaseError, "regular non-symlink"):
            self.validate()

    def test_unsafe_ownership_or_permissions_fails_closed(self) -> None:
        with self.assertRaisesRegex(ReleaseError, "ownership"):
            trusted_key_tools.validate_trust_pair(
                self.repository_key, self.installed_key,
                owner_uid=self.owner_uid + 1, group_gid=self.group_gid,
            )
        self.installed_key.chmod(0o664)
        with self.assertRaisesRegex(ReleaseError, "permissions"):
            self.validate()

    def test_legacy_key_does_not_create_a_fallback_path(self) -> None:
        legacy = self.root / "legacy-minisign.pub"
        legacy.write_text("different legacy key fixture\n", encoding="ascii")
        self.assertEqual(self.validate(), trusted_key_tools.V2_KEY_ID)
        self.assertEqual(trusted_key_tools.validate_repository_pin(self.repository_key), trusted_key_tools.V2_KEY_ID)


class SigningKeyVerificationWorkflowTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        os.chmod(self.root, 0o700)
        self.private_key = self.root / "minisign.key"
        self.private_key.write_text("synthetic encrypted key fixture\n", encoding="ascii")
        self.private_key.chmod(0o600)
        self.passphrase = self.root / "minisign.passphrase"
        self.passphrase.write_text("synthetic passphrase\n", encoding="ascii")
        self.passphrase.chmod(0o600)
        self.message = self.root / "challenge.txt"
        self.message.write_text("synthetic challenge\n", encoding="ascii")
        self.signature = self.root / "challenge.txt.minisig"
        self.public_key = ROOT / "config/gjej-release-v2.pub"

    def tearDown(self) -> None:
        self.temp.cleanup()

    def test_synthetic_sign_and_verify_returns_only_the_public_key_id(self) -> None:
        completed = subprocess.CompletedProcess(args=["minisign"], returncode=0, stdout=b"", stderr=b"")
        def create_synthetic_signature(_executable, args, _passphrase):
            destination = Path(args[args.index("-x") + 1])
            destination.write_text("synthetic signature fixture\n", encoding="ascii")
            destination.chmod(0o600)
            return 0

        with patch.object(signing_key_check, "_minisign_executable", return_value="minisign"), \
                patch.object(signing_key_check, "_run_minisign_with_passphrase", side_effect=create_synthetic_signature), \
                patch.object(signing_key_check, "_run_minisign", return_value=completed):
            key_id = signing_key_check.verify_signing_key(
                self.private_key, self.passphrase, self.public_key, self.message, self.signature,
            )
        self.assertEqual(key_id, trusted_key_tools.V2_KEY_ID)

    def test_mismatched_signing_key_fails_without_printing_verifier_output(self) -> None:
        completed = subprocess.CompletedProcess(args=["minisign"], returncode=1, stdout=b"secret-looking data", stderr=b"secret-looking error")
        def create_synthetic_signature(_executable, args, _passphrase):
            destination = Path(args[args.index("-x") + 1])
            destination.write_text("synthetic signature fixture\n", encoding="ascii")
            destination.chmod(0o600)
            return 0

        with patch.object(signing_key_check, "_minisign_executable", return_value="minisign"), \
                patch.object(signing_key_check, "_run_minisign_with_passphrase", side_effect=create_synthetic_signature), \
                patch.object(signing_key_check, "_run_minisign", return_value=completed):
            with self.assertRaisesRegex(ReleaseError, "does not match") as raised:
                signing_key_check.verify_signing_key(
                    self.private_key, self.passphrase, self.public_key, self.message, self.signature,
                )
        self.assertNotIn("secret-looking", str(raised.exception))


@unittest.skipUnless(shutil.which("minisign"), "Minisign is required for synthetic signing integration tests")
class SigningKeyVerificationIntegrationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="gjej-signing-key-test-")
        self.root = Path(self.temp.name)
        os.chmod(self.root, 0o700)
        self.passphrase = self.root / "synthetic.passphrase"
        self.passphrase.write_bytes(b"synthetic-only-passphrase-98!\n")
        self.passphrase.chmod(0o600)
        self.message = self.root / "synthetic-message.txt"
        self.message.write_bytes(b"Synthetic Gjej release-key integration test.\n")
        self.first_private = self.root / "first-synthetic.key"
        self.first_public = self.root / "first-synthetic.pub"
        self.second_private = self.root / "second-synthetic.key"
        self.second_public = self.root / "second-synthetic.pub"
        self._generate_encrypted_keypair(self.first_private, self.first_public)
        self._generate_encrypted_keypair(self.second_private, self.second_public)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def _generate_encrypted_keypair(self, private_key: Path, public_key: Path) -> None:
        executable = shutil.which("minisign")
        if executable is None:
            self.skipTest("Minisign is unavailable")
        master_fd, slave_fd = pty.openpty()
        attributes = termios.tcgetattr(slave_fd)
        attributes[3] &= ~termios.ECHO
        termios.tcsetattr(slave_fd, termios.TCSANOW, attributes)
        process: subprocess.Popen[bytes] | None = None
        try:
            process = subprocess.Popen(
                [executable, "-G", "-s", str(private_key), "-p", str(public_key)],
                stdin=slave_fd, stdout=slave_fd, stderr=slave_fd, close_fds=True,
                start_new_session=True,
            )
            os.close(slave_fd)
            slave_fd = -1
            os.set_blocking(master_fd, False)
            selector = selectors.DefaultSelector()
            selector.register(master_fd, selectors.EVENT_READ)
            output = bytearray()
            sent_prompts = 0
            prompt = re.compile(rb"(?i)(?:password|passphrase)[^\r\n]{0,160}:")
            deadline = time.monotonic() + 30
            while time.monotonic() < deadline:
                if process.poll() is not None:
                    events = selector.select(timeout=0.1)
                    if not events:
                        break
                else:
                    events = selector.select(timeout=0.1)
                for _, _ in events:
                    try:
                        block = os.read(master_fd, 4096)
                    except OSError:
                        block = b""
                    if block:
                        output.extend(block)
                        if len(output) > 32768:
                            self.fail("Synthetic Minisign key generation produced excessive output.")
                        prompts = list(prompt.finditer(output))
                        while sent_prompts < len(prompts):
                            os.write(master_fd, b"synthetic-only-passphrase-98!\n")
                            sent_prompts += 1
                if process.poll() is not None:
                    break
            else:
                self.fail("Synthetic Minisign key generation timed out.")
            returncode = process.wait(timeout=2)
            self.assertEqual(returncode, 0, "Synthetic encrypted Minisign key generation failed.")
            self.assertGreaterEqual(sent_prompts, 2, "Minisign did not request and receive the key passphrase twice.")
            self.assertTrue(private_key.is_file())
            self.assertTrue(public_key.is_file())
            private_key.chmod(0o600)
            self.assertFalse(private_key.read_bytes().endswith(b"\n\n"))
        finally:
            if process is not None and process.poll() is None:
                process.kill()
                process.wait()
            if slave_fd >= 0:
                os.close(slave_fd)
            os.close(master_fd)

    def test_real_minisign_signing_key_verification_and_mismatch(self) -> None:
        matching_signature = self.root / "matching.txt.minisig"
        mismatched_signature = self.root / "mismatched.txt.minisig"
        extracted_private = self.root / "json-extracted-synthetic.key"
        serialized_secret_value = self.first_private.read_text(encoding="utf-8")
        normalized_key = serialized_secret_value.encode("utf-8").replace(b"\r\n", b"\n").rstrip(b"\n") + b"\n"
        self.assertEqual(normalized_key, self.first_private.read_bytes())
        extracted_private.write_bytes(normalized_key)
        extracted_private.chmod(0o600)

        original_umask = os.umask(0o077)
        try:
            with patch.object(signing_key_check, "validate_repository_pin", return_value="synthetic-key-id"):
                self.assertEqual(
                    signing_key_check.verify_signing_key(
                        extracted_private, self.passphrase, self.first_public,
                        self.message, matching_signature,
                    ),
                    "synthetic-key-id",
                )

            with patch.object(signing_key_check, "validate_repository_pin", return_value="synthetic-key-id"):
                with self.assertRaisesRegex(ReleaseError, "does not match the pinned"):
                    signing_key_check.verify_signing_key(
                        extracted_private, self.passphrase, self.second_public,
                        self.message, mismatched_signature,
                    )
        finally:
            os.umask(original_umask)


class ArtifactValidationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.archive = self.root / "gjej-0123456789ab-20261105T120000Z.tar.gz"
        self.manifest_path = self.root / "gjej-0123456789ab-20261105T120000Z.tar.gz.manifest.json"
        self.files = {
            name: (b"synthetic\n" if name != "public/build/manifest.json" else b"")
            for name in REQUIRED_FILES
        }
        self.files["public/build/manifest.json"] = json.dumps({
            "resources/js/app.tsx": {"file": "assets/app-a1b2.js", "isEntry": True}
        }).encode()

    def tearDown(self) -> None:
        self.temp.cleanup()

    def build(self, extra: tuple[str, bytes] | None = None, *, special: str | None = None, special_type: bytes | None = None, omit: str | None = None, pax_metadata: bool = False, file_mode: tuple[str, int] | None = None) -> None:
        contents = dict(self.files)
        if extra:
            contents[extra[0]] = extra[1]
        if omit:
            contents.pop(omit, None)
        temporary_archive = self.root / "content.tar.gz"
        archive_format = tarfile.PAX_FORMAT if pax_metadata else tarfile.USTAR_FORMAT
        with tarfile.open(temporary_archive, "w:gz", format=archive_format) as tar:
            directories = {"database", "database/migrations", "public", "public/build", "public/build/assets", "vendor", "vendor/composer", "bootstrap", "config", "lang", "resources", "resources/views", "routes"}
            for directory in sorted(directories):
                info = tarfile.TarInfo(directory)
                info.type = tarfile.DIRTYPE
                info.mode = 0o750
                info.uid = info.gid = 0
                tar.addfile(info)
            for name, data in contents.items():
                info = tarfile.TarInfo(name)
                if pax_metadata and name == "artisan":
                    info.pax_headers = {"comment": "synthetic metadata"}
                info.size = len(data)
                info.mode = file_mode[1] if file_mode and name == file_mode[0] else 0o640
                info.uid = info.gid = 0
                import io
                tar.addfile(info, io.BytesIO(data))
            if special and special_type == tarfile.SYMTYPE:
                info = tarfile.TarInfo(special)
                info.type = special_type
                info.linkname = "artisan"
                info.uid = info.gid = 0
                tar.addfile(info)
            elif special and special_type:
                info = tarfile.TarInfo(special)
                info.type = special_type
                info.uid = info.gid = 0
                tar.addfile(info)
        temporary_archive.replace(self.archive)
        manifest = {
            "schema_version": 1,
            "release_id": "0123456789ab-20261105T120000Z",
            "git_ref": "synthetic-v1",
            "commit_sha": "0123456789ab" + "1" * 28,
            "build_timestamp_utc": "2026-11-05T12:00:00Z",
            "composer_lock_sha256": hashlib.sha256(contents["composer.lock"]).hexdigest(),
            "npm_lock_sha256": hashlib.sha256(contents["package-lock.json"]).hexdigest(),
            "archive": {
                "file": self.archive.name,
                "size_bytes": self.archive.stat().st_size,
                "sha256": hashlib.sha256(self.archive.read_bytes()).hexdigest(),
            },
        }
        self.manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

    def assert_rejected(self, reason: str | None = None) -> None:
        with self.assertRaises(ReleaseError) as caught:
            validate_archive(self.archive, self.manifest_path)
        if reason:
            self.assertIn(reason, str(caught.exception))

    def test_valid_required_files_and_vite_manifest(self) -> None:
        self.build()
        result = validate_archive(self.archive, self.manifest_path)
        self.assertEqual(result["member_count"] > 0, True)

    def test_environment_file_including_uppercase_is_rejected(self) -> None:
        self.build(("config/.ENV.production", b"synthetic"))
        self.assert_rejected("Environment files")
        self.build(("config/CREDENTIALS.JSON", b"synthetic"))
        self.assert_rejected("Credential")

    def test_runtime_storage_path_and_lock_digest_are_rejected(self) -> None:
        self.build(("storage/app/receipts-private/synthetic.pdf", b"synthetic"))
        self.assert_rejected("Unexpected top-level")
        self.build()
        manifest = json.loads(self.manifest_path.read_text())
        manifest["composer_lock_sha256"] = "0" * 64
        self.manifest_path.write_text(json.dumps(manifest))
        with self.assertRaisesRegex(ReleaseError, "Composer lock digest"):
            validate_archive(self.archive, self.manifest_path)

    def test_absolute_and_traversal_paths_are_rejected(self) -> None:
        for name in ("/tmp/escape", "app/../escape", r"app\..\escape", "C:/escape"):
            with self.subTest(name=name):
                self.build((name, b"synthetic"))
                self.assert_rejected()

    def test_duplicate_normalized_paths_are_rejected(self) -> None:
        self.build(("./artisan", b"duplicate"))
        self.assert_rejected("Duplicate normalized")

    def test_symlink_and_special_files_are_rejected(self) -> None:
        self.build(special="public/build/link", special_type=tarfile.SYMTYPE)
        self.assert_rejected("links and special")
        self.build(special="public/build/hardlink", special_type=tarfile.LNKTYPE)
        self.assert_rejected("links and special")
        self.build(special="public/build/fifo", special_type=tarfile.FIFOTYPE)
        self.assert_rejected("links and special")

    def test_unexpected_hidden_paths_and_required_files_are_rejected(self) -> None:
        self.build(("vendor/.secret", b"synthetic"))
        self.assert_rejected("Unexpected hidden")
        self.build(omit="artisan")
        self.assert_rejected("missing required files")
        self.build(file_mode=("artisan", 0o777))
        self.assert_rejected("unexpected permissions")

    def test_pax_metadata_and_nested_archive_are_rejected(self) -> None:
        self.build(pax_metadata=True)
        self.assert_rejected("PAX/GNU metadata")
        self.build(("resources/backup.tar.gz", b"synthetic"))
        self.assert_rejected("Nested archive")
        self.build(("public/build/assets/unreferenced.js", b"synthetic"))
        self.assert_rejected("unexpected=")

    def test_invalid_vite_references_and_digest_mismatch_are_rejected(self) -> None:
        self.files["public/build/manifest.json"] = b'{"entry":{"file":"assets/app-a1b2.js"},"entry":{"file":"assets/app-a1b2.js"}}'
        self.build()
        with self.assertRaisesRegex(ReleaseError, "duplicate key"):
            validate_archive(self.archive, self.manifest_path)
        self.files["public/build/manifest.json"] = b"{not json}"
        self.build()
        with self.assertRaisesRegex(ReleaseError, "not valid UTF-8 JSON"):
            validate_archive(self.archive, self.manifest_path)
        self.files["public/build/manifest.json"] = json.dumps({"entry": {"file": "../escape.js"}}).encode()
        self.build()
        with self.assertRaisesRegex(ReleaseError, "unsafe asset"):
            validate_archive(self.archive, self.manifest_path)
        self.files["public/build/manifest.json"] = json.dumps({"entry": {"file": "assets/missing.js"}}).encode()
        self.build()
        with self.assertRaisesRegex(ReleaseError, "missing asset"):
            validate_archive(self.archive, self.manifest_path)
        self.files["public/build/manifest.json"] = json.dumps({"entry": {"file": "assets/app-a1b2.js"}}).encode()
        self.build()
        manifest = json.loads(self.manifest_path.read_text())
        manifest["archive"]["sha256"] = "0" * 64
        self.manifest_path.write_text(json.dumps(manifest))
        with self.assertRaisesRegex(ReleaseError, "digest mismatch"):
            validate_archive(self.archive, self.manifest_path)

    def test_archive_size_member_count_and_expanded_size_limits(self) -> None:
        self.build()
        with patch.object(validator, "MAX_ARCHIVE_BYTES", 1):
            with self.assertRaisesRegex(ReleaseError, "(?i)compressed"):
                validate_archive(self.archive, self.manifest_path)
        with patch.object(validator, "MAX_MEMBERS", 1):
            with self.assertRaisesRegex(ReleaseError, "too many members"):
                validate_archive(self.archive, self.manifest_path)
        with patch.object(validator, "MAX_EXPANDED_BYTES", 1):
            with self.assertRaisesRegex(ReleaseError, "Expanded"):
                validate_archive(self.archive, self.manifest_path)
        with patch.object(validator, "MAX_TAR_STREAM_BYTES", 512):
            with self.assertRaisesRegex(ReleaseError, "stream exceeds"):
                validate_archive(self.archive, self.manifest_path)

    def test_inconsistent_manifest_metadata_is_rejected(self) -> None:
        self.build()
        manifest = json.loads(self.manifest_path.read_text())
        manifest["commit_sha"] = "2" * 40
        self.manifest_path.write_text(json.dumps(manifest))
        with self.assertRaisesRegex(ReleaseError, "Release ID does not match"):
            validate_archive(self.archive, self.manifest_path)

    def test_manifest_filename_and_timestamp_mismatches_are_rejected(self) -> None:
        self.build()
        manifest = json.loads(self.manifest_path.read_text())
        manifest["archive"]["file"] = "other.tar.gz"
        self.manifest_path.write_text(json.dumps(manifest))
        with self.assertRaisesRegex(ReleaseError, "filename"):
            validate_archive(self.archive, self.manifest_path)
        self.build()
        manifest = json.loads(self.manifest_path.read_text())
        manifest["build_timestamp_utc"] = "2026-11-05T12:01:00Z"
        self.manifest_path.write_text(json.dumps(manifest))
        with self.assertRaisesRegex(ReleaseError, "timestamp"):
            validate_archive(self.archive, self.manifest_path)

    def test_unsigned_ref_metadata_can_be_rewritten_without_changing_archive(self) -> None:
        self.build()
        manifest = json.loads(self.manifest_path.read_text())
        manifest["git_ref"] = "different-but-well-formed-tag"
        self.manifest_path.write_text(json.dumps(manifest))
        self.assertEqual(validate_archive(self.archive, self.manifest_path)["commit_sha"], manifest["commit_sha"])


@unittest.skipUnless(shutil.which("minisign"), "Minisign >= 0.12 is required for synthetic signing tests")
class SigningTests(unittest.TestCase):
    IDENTITY = "https://github.com/example/gjej-portier/.github/workflows/release.yml@refs/tags/synthetic-release"

    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.archive, self.manifest = make_valid_artifact(self.root)
        self.private_key = self.root / "signing-test.key"
        self.public_key = self.root / "signing-test.pub"
        self.wrong_private_key = self.root / "wrong-test.key"
        self.wrong_public_key = self.root / "wrong-test.pub"
        self._generate_keypair(self.private_key, self.public_key)
        self._generate_keypair(self.wrong_private_key, self.wrong_public_key)
        self.signature = self.root / "release.manifest.minisig"
        sign_artifact(self.archive, self.manifest, self.private_key, self.IDENTITY, self.signature)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def _generate_keypair(self, private: Path, public: Path) -> None:
        subprocess.run(
            ["minisign", "-G", "-W", "-s", str(private), "-p", str(public)],
            check=True, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )
        private.chmod(0o600)

    def verify(self, *, archive: Path | None = None, manifest: Path | None = None, signature: Path | None = None, public_keys: list[Path] | None = None, identity: str | None = None) -> dict[str, Any]:
        return verify_signed_artifact(
            archive or self.archive,
            manifest or self.manifest,
            signature or self.signature,
            public_keys or [self.public_key],
            identity or self.IDENTITY,
        )

    def test_valid_minisign_signature_binds_identity_manifest_artifact_and_commit(self) -> None:
        result = self.verify()
        self.assertEqual(result["status"], "verified")
        self.assertEqual(result["signer_identity"], self.IDENTITY)
        manifest = json.loads(self.manifest.read_text())
        self.assertEqual(result["commit_sha"], manifest["commit_sha"])

    def test_wrong_public_key_and_untrusted_key_are_rejected(self) -> None:
        with self.assertRaisesRegex(ReleaseError, "all configured trust roots"):
            self.verify(public_keys=[self.wrong_public_key])

    def test_modified_artifact_is_rejected_by_sha256_validation(self) -> None:
        changed = bytearray(self.archive.read_bytes())
        changed[-1] ^= 0x01
        self.archive.write_bytes(changed)
        with self.assertRaisesRegex(ReleaseError, "digest|SHA-256"):
            self.verify()

    def test_modified_manifest_is_rejected_by_minisign_before_manifest_trust(self) -> None:
        self.manifest.write_bytes(self.manifest.read_bytes() + b" ")
        with self.assertRaisesRegex(ReleaseError, "all configured trust roots"):
            self.verify()

    def test_missing_signature_is_rejected(self) -> None:
        self.signature.unlink()
        with self.assertRaisesRegex(ReleaseError, "Minisign detached signature is unavailable"):
            self.verify()

    def test_invalid_signature_is_rejected(self) -> None:
        self.signature.write_text("not a Minisign signature\n", encoding="ascii")
        with self.assertRaisesRegex(ReleaseError, "all configured trust roots"):
            self.verify()

    def test_unexpected_signer_identity_is_rejected(self) -> None:
        with self.assertRaisesRegex(ReleaseError, "identity"):
            self.verify(identity="https://attacker.invalid/workflow")

    def test_mismatched_artifact_filename_is_rejected(self) -> None:
        renamed_archive = self.root / "gjej-renamed-artifact.tar.gz"
        renamed_archive.write_bytes(self.archive.read_bytes())
        with self.assertRaisesRegex(ReleaseError, "different artifact filename"):
            self.verify(archive=renamed_archive)

    def test_mismatched_commit_and_release_metadata_are_rejected(self) -> None:
        second_root = self.root / "second"
        second_root.mkdir()
        archive, manifest = make_valid_artifact(second_root, marker="another-release", timestamp="20261105T120001Z")
        with self.assertRaisesRegex(ReleaseError, "all configured trust roots"):
            self.verify(archive=archive, manifest=manifest)

        modified = json.loads(self.manifest.read_text())
        modified["commit_sha"] = "f" * 40
        self.manifest.write_text(json.dumps(modified), encoding="utf-8")
        with self.assertRaisesRegex(ReleaseError, "all configured trust roots"):
            self.verify()

    def test_key_rotation_accepts_only_explicitly_configured_roots(self) -> None:
        next_private = self.root / "next-test.key"
        next_public = self.root / "next-test.pub"
        self._generate_keypair(next_private, next_public)
        next_signature = self.root / "next-release.manifest.minisig"
        sign_artifact(self.archive, self.manifest, next_private, self.IDENTITY, next_signature)

        with self.assertRaisesRegex(ReleaseError, "all configured trust roots"):
            self.verify(signature=next_signature, public_keys=[self.public_key])
        result = self.verify(signature=next_signature, public_keys=[self.public_key, next_public])
        self.assertEqual(result["status"], "verified")
        self.assertEqual(Path(result["verified_key"]), next_public.resolve())

    def test_legacy_custom_json_signature_does_not_fall_back(self) -> None:
        self.signature.write_text(json.dumps({"schema_version": 1, "signature": "legacy"}), encoding="utf-8")
        with self.assertRaisesRegex(ReleaseError, "all configured trust roots"):
            self.verify()


@unittest.skipUnless(shutil.which("minisign"), "Minisign >= 0.12 is required for synthetic install tests")
class SafeInstallTests(unittest.TestCase):
    IDENTITY = "https://github.com/example/gjej-portier/.github/workflows/release.yml@refs/tags/synthetic-release"

    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.archive, self.manifest = make_valid_artifact(self.root)
        self.private_key = self.root / "install-test.key"
        self.public_key = self.root / "install-test.pub"
        subprocess.run(
            ["minisign", "-G", "-W", "-s", str(self.private_key), "-p", str(self.public_key)],
            check=True, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )
        self.private_key.chmod(0o600)
        self.signature = self.root / "release.manifest.minisig"
        sign_artifact(self.archive, self.manifest, self.private_key, self.IDENTITY, self.signature)
        self.releases = self.root / "releases"
        self.releases.mkdir(mode=0o750)
        self.release_id = json.loads(self.manifest.read_text())["release_id"]

    def tearDown(self) -> None:
        self.temp.cleanup()

    def install(self, *, keys: list[Path] | None = None) -> dict[str, Any]:
        return install_signed_release(
            self.archive, self.manifest, self.signature, keys or [self.public_key],
            self.IDENTITY, self.releases, owner_uid=os.geteuid(), group_gid=os.getegid(),
        )

    def test_signed_release_is_extracted_to_new_inactive_directory(self) -> None:
        result = self.install()
        installed = self.releases / self.release_id
        self.assertEqual(result["status"], "installed-inactive")
        self.assertFalse(result["activated"])
        self.assertTrue((installed / "artisan").is_file())
        self.assertEqual((installed / "artisan").read_bytes(), b"synthetic artisan\n")
        self.assertEqual((installed / "artisan").stat().st_mode & 0o777, 0o640)
        self.assertFalse((self.releases / "current").exists())
        self.assertFalse(any(path.name.startswith(".gjej-install-") for path in self.releases.iterdir()))

    def test_existing_release_directory_is_never_overwritten(self) -> None:
        existing = self.releases / self.release_id
        existing.mkdir()
        marker = existing / "operator-data"
        marker.write_text("keep\n")
        with self.assertRaisesRegex(ReleaseError, "Refusing to overwrite"):
            self.install()
        self.assertEqual(marker.read_text(), "keep\n")

    def test_destination_created_after_precheck_is_not_replaced(self) -> None:
        real_publish = installer._rename_directory_noreplace
        destination = self.releases / self.release_id
        marker = destination / "operator-data"

        def create_destination_before_publish(source: Path, target: Path) -> None:
            destination.mkdir()
            marker.write_text("keep\n")
            real_publish(source, target)

        with patch.object(installer, "_rename_directory_noreplace", side_effect=create_destination_before_publish):
            with self.assertRaisesRegex(ReleaseError, "Refusing to overwrite"):
                self.install()
        self.assertEqual(marker.read_text(), "keep\n")

    def test_untrusted_signature_creates_no_release_directory(self) -> None:
        wrong_key = self.root / "wrong.pub"
        wrong_private = self.root / "wrong.key"
        subprocess.run(
            ["minisign", "-G", "-W", "-s", str(wrong_private), "-p", str(wrong_key)],
            check=True, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )
        wrong_private.chmod(0o600)
        with self.assertRaisesRegex(ReleaseError, "all configured trust roots"):
            self.install(keys=[wrong_key])
        self.assertFalse((self.releases / self.release_id).exists())
        self.assertFalse(any(path.name.startswith(".gjej-install-") for path in self.releases.iterdir()))

    def test_symlink_input_is_rejected_before_verification(self) -> None:
        link = self.root / "archive-link.tar.gz"
        link.symlink_to(self.archive)
        with self.assertRaisesRegex(ReleaseError, "opened safely"):
            install_signed_release(
                link, self.manifest, self.signature, [self.public_key], self.IDENTITY,
                self.releases, owner_uid=os.geteuid(), group_gid=os.getegid(),
            )
        self.assertFalse((self.releases / self.release_id).exists())


class AtomicReleasePublicationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.source = self.root / "staging-release"
        self.source.mkdir()
        (self.source / "marker").write_text("candidate\n")

    def tearDown(self) -> None:
        self.temp.cleanup()

    def test_new_release_directory_is_published_atomically(self) -> None:
        destination = self.root / "release-id"
        installer._rename_directory_noreplace(self.source, destination)
        self.assertEqual((destination / "marker").read_text(), "candidate\n")
        self.assertFalse(self.source.exists())

    def test_existing_empty_release_directory_is_not_replaced(self) -> None:
        destination = self.root / "release-id"
        destination.mkdir()
        with self.assertRaisesRegex(ReleaseError, "Refusing to overwrite"):
            installer._rename_directory_noreplace(self.source, destination)
        self.assertTrue(destination.is_dir())
        self.assertEqual(list(destination.iterdir()), [])
        self.assertEqual((self.source / "marker").read_text(), "candidate\n")


class SyntheticPackagePipelineTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.repo = self.base / "repo"
        self.repo.mkdir()
        git(self.repo, "init", "-q")
        git(self.repo, "config", "user.name", "Synthetic Test")
        git(self.repo, "config", "user.email", "synthetic@example.invalid")
        for name in ROOT_FILES:
            (self.repo / name).write_text(f"synthetic {name}\n", encoding="utf-8")
        for name in PROJECT_DIRS:
            (self.repo / name).mkdir()
        required = (
            "bootstrap/app.php", "bootstrap/providers.php", "config/app.php", "config/filesystems.php", "config/session.php",
            "database/migrations/synthetic.php", "lang/en.json", "lang/sq.json", "public/index.php",
            "resources/views/app.blade.php", "routes/web.php", "routes/console.php", "app/release-marker.txt",
        )
        for relative in required:
            path = self.repo / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("first committed tree\n", encoding="utf-8")
        (self.repo / "config/.env.production").write_text("synthetic secret marker\n", encoding="utf-8")
        git(self.repo, "add", "--all")
        git(self.repo, "commit", "-q", "-m", "synthetic first release")
        self.release_commit = git(self.repo, "rev-parse", "HEAD")
        git(self.repo, "tag", "synthetic-release")
        marker = self.repo / "app/release-marker.txt"
        marker.write_text("later unselected commit\n", encoding="utf-8")
        git(self.repo, "add", "app/release-marker.txt")
        git(self.repo, "commit", "-q", "-m", "synthetic later commit")
        self.output = self.base / "artifacts"
        self.output.mkdir()

    def tearDown(self) -> None:
        self.temp.cleanup()

    def test_package_uses_exact_selected_commit_and_publishes_valid_artifact(self) -> None:
        commands: list[list[str]] = []

        def synthetic_command(command: list[str], *, cwd: Path, env: dict[str, str]) -> None:
            commands.append(command)
            if command[:2] == ["composer", "install"]:
                (cwd / "vendor/composer").mkdir(parents=True, exist_ok=True)
                (cwd / "vendor/autoload.php").write_text("synthetic autoload\n")
                (cwd / "vendor/composer/installed.php").write_text("<?php return [];\n")
            elif command[:2] == ["npm", "run"] and command[-1] == "build":
                assets = cwd / "public/build/assets"
                assets.mkdir(parents=True, exist_ok=True)
                (assets / "app-synthetic.js").write_text("synthetic asset\n")
                (cwd / "public/build/manifest.json").write_text(json.dumps({"entry": {"file": "assets/app-synthetic.js"}}))

        with patch.object(packager, "ROOT", self.repo), patch.object(packager, "run", side_effect=synthetic_command):
            archive, manifest_path = packager.package("synthetic-release", self.output)

        manifest = json.loads(manifest_path.read_text())
        self.assertEqual(manifest["commit_sha"], self.release_commit)
        self.assertEqual(validate_archive(archive, manifest_path)["release_id"], manifest["release_id"])
        self.assertIn("--no-dev", commands[0])
        self.assertIn("--no-scripts", commands[0])
        self.assertIn("--no-plugins", commands[0])
        self.assertIn(["npm", "ci", "--no-audit", "--no-fund"], commands)
        with tarfile.open(archive, "r:gz") as tar:
            marker = tar.extractfile("app/release-marker.txt")
            self.assertEqual(marker.read(), b"first committed tree\n")
            names = {member.name for member in tar}
        self.assertNotIn("config/.env.production", names)

    def test_dirty_source_and_build_failure_publish_nothing(self) -> None:
        marker = self.repo / "app/release-marker.txt"
        marker.write_text("uncommitted synthetic edit\n", encoding="utf-8")
        with patch.object(packager, "ROOT", self.repo), patch.object(packager, "run") as run_mock:
            with self.assertRaisesRegex(ReleaseError, "dirty"):
                packager.package("synthetic-release", self.output)
            run_mock.assert_not_called()
        self.assertEqual(list(self.output.iterdir()), [])

        git(self.repo, "checkout", "--", "app/release-marker.txt")
        with patch.object(packager, "ROOT", self.repo), patch.object(
            packager, "run", side_effect=subprocess.CalledProcessError(1, ["composer", "install"])
        ):
            with self.assertRaises(subprocess.CalledProcessError):
                packager.package("synthetic-release", self.output)
        self.assertEqual(list(self.output.iterdir()), [])


class RestrictedReceiverTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        os.chmod(self.root, 0o700)
        self.incoming_parent = self.root / "var-lib"
        self.incoming_parent.mkdir(mode=0o700)
        self.incoming = self.incoming_parent / "incoming"
        self.incoming.mkdir(mode=0o700)
        self.quarantine = self.root / "quarantine"
        self.quarantine.mkdir(mode=0o700)
        self.releases = self.root / "releases"
        self.releases.mkdir(mode=0o700)
        self.trusted_key = self.root / "trusted.pub"
        self.trusted_key.write_text("synthetic public key\n", encoding="ascii")
        self.trusted_key.chmod(0o600)
        self.audit = self.root / "receiver.jsonl"
        self.settings = receiver.ReceiverSettings(
            incoming_root=self.incoming,
            quarantine_root=self.quarantine,
            releases_root=self.releases,
            trusted_public_key=self.trusted_key,
            expected_identity="gjej-production-release",
            uploader_uid=os.getuid(),
            owner_uid=os.getuid(),
            group_gid=os.getgid(),
        )
        self.submission_id = "run-123456-1"
        self.release_id = "0123456789ab-20261107T120000Z"
        self.upload = self.incoming / self.submission_id
        self.upload.mkdir(mode=0o700)
        self.archive_name = f"gjej-{self.release_id}.tar.gz"
        for name, contents in (
            (self.archive_name, b"synthetic archive"),
            (self.archive_name + ".manifest.json", b"synthetic manifest"),
            (self.archive_name + ".manifest.json.minisig", b"synthetic signature"),
        ):
            path = self.upload / name
            path.write_bytes(contents)
            path.chmod(0o600)
        self.verified = {
            "status": "verified", "release_id": self.release_id,
            "commit_sha": "0123456789ab" + "1" * 28, "sha256": "a" * 64,
        }
        self.candidate = self.releases.resolve() / self.release_id
        (self.candidate / "bootstrap").mkdir(parents=True, mode=0o700)
        self.installed = {
            "status": "installed-inactive", "activated": False,
            "release_id": self.release_id, "commit_sha": self.verified["commit_sha"],
            "sha256": self.verified["sha256"], "path": str(self.candidate),
        }

    def tearDown(self) -> None:
        self.temp.cleanup()

    def receive(self, *, verify=None, install=None):
        return receiver.receive_submission(
            self.submission_id,
            self.settings,
            audit_path=self.audit,
            verify=verify or (lambda *args: self.verified),
            install=install or (lambda *args, **kwargs: self.installed),
            require_root=False,
        )

    def test_only_exact_signed_bundle_is_sealed_and_installed_inactive(self) -> None:
        calls = []
        result = self.receive(install=lambda *args, **kwargs: (calls.append((args, kwargs)) or self.installed))
        self.assertEqual(result["status"], "installed-inactive")
        self.assertFalse(result["activated"])
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0][1]["owner_uid"], os.getuid())
        self.assertEqual(calls[0][1]["group_gid"], os.getgid())
        runtime_cache = self.candidate / "bootstrap/cache"
        self.assertTrue(runtime_cache.is_dir())
        self.assertEqual(runtime_cache.stat().st_mode & 0o777, 0o750)
        self.assertEqual(runtime_cache.stat().st_uid, os.getuid())
        sealed = self.quarantine / self.submission_id
        self.assertEqual({path.name for path in sealed.iterdir()}, {
            self.archive_name,
            self.archive_name + ".manifest.json",
            self.archive_name + ".manifest.json.minisig",
        })
        self.assertTrue(all(path.stat().st_mode & 0o777 == 0o600 for path in sealed.iterdir()))
        events = [json.loads(line)["event"] for line in self.audit.read_text().splitlines()]
        self.assertEqual(events, ["snapshot-started", "signature-verified", "install-started", "installed-inactive"])

    def test_partial_or_unexpected_upload_is_rejected(self) -> None:
        (self.upload / "archive.tar.gz.part").write_text("partial", encoding="ascii")
        verify = unittest.mock.Mock()
        with self.assertRaisesRegex(ReleaseError, "only the archive|too many entries"):
            self.receive(verify=verify)
        verify.assert_not_called()

    def test_duplicate_submission_is_rejected_without_second_install(self) -> None:
        self.receive()
        install = unittest.mock.Mock()
        with self.assertRaisesRegex(ReleaseError, "already received"):
            self.receive(install=install)
        install.assert_not_called()

    def test_bad_signature_never_calls_inactive_installer_and_retains_quarantine(self) -> None:
        install = unittest.mock.Mock()

        def reject_signature(*_args):
            raise ReleaseError("synthetic signature rejection")

        with self.assertRaisesRegex(ReleaseError, "synthetic signature rejection"):
            self.receive(verify=reject_signature, install=install)
        install.assert_not_called()
        self.assertTrue((self.quarantine / self.submission_id / self.archive_name).is_file())
        self.assertEqual(json.loads(self.audit.read_text().splitlines()[-1])["event"], "failed")

    def test_invalid_submission_id_is_rejected_before_filesystem_access(self) -> None:
        with self.assertRaisesRegex(ReleaseError, "format is invalid"):
            receiver.receive_submission(
                "../etc", self.settings, audit_path=self.audit, require_root=False,
            )


class SigningPtyTests(unittest.TestCase):
    def test_minisign_passphrase_is_sent_through_pty_without_becoming_output(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            fake = Path(directory) / "fake-minisign"
            fake.write_text(
                "#!/usr/bin/env python3\n"
                "import sys\n"
                "sys.stderr.write('Password: ')\n"
                "sys.stderr.flush()\n"
                "value = sys.stdin.readline().rstrip('\\r\\n')\n"
                "raise SystemExit(0 if value == 'correct horse battery staple' else 1)\n",
                encoding="utf-8",
            )
            fake.chmod(0o700)
            from scripts.release.sign_artifact import _run_minisign_with_passphrase

            self.assertEqual(
                _run_minisign_with_passphrase(str(fake), ["-S"], b"correct horse battery staple"),
                0,
            )

    def test_private_passphrase_file_requires_restricted_permissions(self) -> None:
        from scripts.release.sign_artifact import _read_private_passphrase

        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "passphrase"
            path.write_text("one-line-secret\n", encoding="utf-8")
            path.chmod(0o600)
            self.assertEqual(_read_private_passphrase(path), b"one-line-secret")
            path.chmod(0o644)
            with self.assertRaisesRegex(ReleaseError, "private and bounded"):
                _read_private_passphrase(path)


if __name__ == "__main__":
    unittest.main()
