from __future__ import annotations

import hashlib
import json
import os
import shutil
import subprocess
import tarfile
import tempfile
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


if __name__ == "__main__":
    unittest.main()
