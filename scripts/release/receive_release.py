#!/usr/bin/env python3
"""Root-only, manual receiver for signed Gjej release submissions.

This command is intentionally separate from activation. It accepts one fixed
submission directory from the restricted SFTP jail, seals the three release
files into root-owned quarantine, verifies the pinned Minisign trust root, and
calls only the existing inactive installer. It never runs Laravel commands,
migrations, shell commands from an upload, or activation logic.
"""

from __future__ import annotations

import argparse
import fcntl
import json
import os
import re
import stat
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

from scripts.release.common import ReleaseError
from scripts.release.install_release import install_signed_release
from scripts.release.sign_artifact import verify_signed_artifact
from scripts.release.validate_artifact import MAX_ARCHIVE_BYTES, MAX_MANIFEST_BYTES


MAX_SIGNATURE_BYTES = 1024 * 1024
COPY_CHUNK_BYTES = 1024 * 1024
SUBMISSION_ID_RE = re.compile(r"^run-[0-9]{1,20}-[0-9]{1,6}$")
ARCHIVE_RE = re.compile(r"^gjej-[0-9a-f]{12}-[0-9]{8}T[0-9]{6}Z\.tar\.gz$")
CONFIG_KEYS = {
    "incoming_root", "quarantine_root", "releases_root", "trusted_public_key",
    "expected_identity", "uploader_uid", "owner_uid", "group_gid",
}


@dataclass(frozen=True)
class ReceiverSettings:
    incoming_root: Path
    quarantine_root: Path
    releases_root: Path
    trusted_public_key: Path
    expected_identity: str
    uploader_uid: int
    owner_uid: int
    group_gid: int


def load_settings(path: Path) -> ReceiverSettings:
    try:
        details = path.lstat()
        if not stat.S_ISREG(details.st_mode) or details.st_uid != 0 or details.st_mode & 0o077:
            raise ReleaseError("Receiver configuration must be a root-owned private regular file.")
        raw = path.read_bytes()
        data = json.loads(raw, object_pairs_hook=_unique_object)
    except (OSError, UnicodeDecodeError, json.JSONDecodeError, RecursionError, ValueError) as exc:
        raise ReleaseError("Receiver configuration is unavailable or invalid.") from exc
    if not isinstance(data, dict) or set(data) != CONFIG_KEYS:
        raise ReleaseError("Receiver configuration has unexpected or missing fields.")
    expected_paths = {
        "incoming_root": "/var/lib/gjej-deploy/incoming",
        "quarantine_root": "/var/lib/gjej-release-receiver/quarantine",
        "releases_root": "/var/www/rezervogk/releases",
        "trusted_public_key": "/etc/gjej/gjej-release-v2.pub",
    }
    if any(data.get(key) != value for key, value in expected_paths.items()):
        raise ReleaseError("Receiver paths differ from the fixed Gjej production paths.")
    if data.get("expected_identity") != "gjej-production-release":
        raise ReleaseError("Receiver signer identity differs from the fixed Gjej release identity.")
    for key in ("uploader_uid", "owner_uid", "group_gid"):
        if type(data.get(key)) is not int or data[key] < 1:
            raise ReleaseError("Receiver account IDs must be positive integers.")
    return ReceiverSettings(
        incoming_root=Path(expected_paths["incoming_root"]),
        quarantine_root=Path(expected_paths["quarantine_root"]),
        releases_root=Path(expected_paths["releases_root"]),
        trusted_public_key=Path(expected_paths["trusted_public_key"]),
        expected_identity=data["expected_identity"],
        uploader_uid=data["uploader_uid"],
        owner_uid=data["owner_uid"],
        group_gid=data["group_gid"],
    )


def _unique_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate field")
        result[key] = value
    return result


def _assert_root_directory(path: Path, *, private: bool = False, owner_uid: int = 0, check_ancestors: bool = True) -> Path:
    try:
        if not check_ancestors:
            info = path.lstat()
            if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
                raise ReleaseError("Receiver storage paths must be real directories without symlinks.")
            if info.st_uid != owner_uid or info.st_mode & 0o022:
                raise ReleaseError("Receiver storage directories must be protected and owned by the configured account.")
            if private and info.st_mode & 0o077:
                raise ReleaseError("Quarantine directory must be private to root.")
            return path.resolve(strict=True)
        current = Path(path.anchor)
        for component in path.parts[1:]:
            current = current / component
            info = current.lstat()
            if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
                raise ReleaseError("Receiver storage paths must be real directories without symlinks.")
            if info.st_uid != owner_uid or info.st_mode & 0o022:
                raise ReleaseError("Receiver storage directories must be root-owned and not group/other-writable.")
        info = path.stat()
        if private and info.st_mode & 0o077:
            raise ReleaseError("Quarantine directory must be private to root.")
        return path.resolve(strict=True)
    except OSError as exc:
        raise ReleaseError("A required receiver directory is unavailable.") from exc


def _append_audit(audit_path: Path, *, event: str, submission_id: str, details: dict[str, Any] | None = None, owner_uid: int = 0) -> None:
    record = {
        "at_utc": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "event": event,
        "submission_id": submission_id,
    }
    if details:
        record.update(details)
    payload = (json.dumps(record, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")
    flags = os.O_WRONLY | os.O_APPEND | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0)
    try:
        fd = os.open(audit_path, flags, 0o600)
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != owner_uid or info.st_mode & 0o077:
                raise ReleaseError("Receiver audit log must be a private root-owned regular file.")
            view = memoryview(payload)
            while view:
                written = os.write(fd, view)
                if written <= 0:
                    raise ReleaseError("Receiver audit log write was incomplete.")
                view = view[written:]
            os.fsync(fd)
        finally:
            os.close(fd)
    except OSError as exc:
        raise ReleaseError("Receiver audit log could not be safely updated.") from exc


def _open_upload_directory(path: Path, uploader_uid: int) -> int:
    try:
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_NOFOLLOW", 0))
    except OSError as exc:
        raise ReleaseError("Submission directory is unavailable.") from exc
    info = os.fstat(fd)
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != uploader_uid or info.st_mode & 0o077:
        os.close(fd)
        raise ReleaseError("Submission directory must be a private directory owned by the restricted SFTP user.")
    return fd


def _assert_upload_root(path: Path, uploader_uid: int, *, owner_uid: int = 0, check_ancestors: bool = True) -> Path:
    parent = _assert_root_directory(path.parent, owner_uid=owner_uid, check_ancestors=check_ancestors)
    candidate = parent / path.name
    try:
        info = candidate.lstat()
    except OSError as exc:
        raise ReleaseError("Restricted SFTP incoming directory is unavailable.") from exc
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != uploader_uid or info.st_mode & 0o077:
        raise ReleaseError("Incoming directory must be private and owned by the restricted SFTP account.")
    return candidate.resolve(strict=True)


def _snapshot_file(source: Path | str, destination: Path, limit: int, label: str, expected_uid: int, *, source_dir_fd: int | None = None) -> None:
    try:
        source_fd = os.open(source, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0), dir_fd=source_dir_fd)
    except OSError as exc:
        raise ReleaseError(f"{label} is missing or is not a regular non-symlink file.") from exc
    try:
        before = os.fstat(source_fd)
        if not stat.S_ISREG(before.st_mode) or before.st_uid != expected_uid or before.st_mode & 0o077 or before.st_size < 1 or before.st_size > limit:
            raise ReleaseError(f"{label} must be a private, non-empty regular file owned by the restricted SFTP account.")
        target_fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        try:
            copied = 0
            while True:
                chunk = os.read(source_fd, COPY_CHUNK_BYTES)
                if not chunk:
                    break
                copied += len(chunk)
                if copied > limit:
                    raise ReleaseError(f"{label} exceeds its configured size limit.")
                view = memoryview(chunk)
                while view:
                    written = os.write(target_fd, view)
                    if written <= 0:
                        raise ReleaseError(f"Unable to seal {label} into quarantine.")
                    view = view[written:]
            after = os.fstat(source_fd)
            if (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns) != (
                after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns
            ) or copied != before.st_size:
                raise ReleaseError(f"{label} changed while being sealed.")
            os.fsync(target_fd)
        finally:
            os.close(target_fd)
    finally:
        os.close(source_fd)


def _expected_submission_files(upload_dir: Path | int) -> tuple[str, str, str]:
    try:
        if isinstance(upload_dir, int):
            with os.scandir(upload_dir) as entries:
                names = []
                for entry in entries:
                    names.append(entry.name)
                    if len(names) > 3:
                        raise ReleaseError("Submission directory contains too many entries.")
        else:
            names = []
            with os.scandir(upload_dir) as entries:
                for entry in entries:
                    names.append(entry.name)
                    if len(names) > 3:
                        raise ReleaseError("Submission directory contains too many entries.")
    except OSError as exc:
        raise ReleaseError("Submission directory could not be listed.") from exc
    archives = [name for name in names if ARCHIVE_RE.fullmatch(name)]
    if len(archives) != 1:
        raise ReleaseError("Submission must contain exactly one v1 Gjej release archive.")
    archive = archives[0]
    manifest = archive + ".manifest.json"
    signature = manifest + ".minisig"
    if set(names) != {archive, manifest, signature}:
        raise ReleaseError("Submission must contain only the archive, manifest, and detached signature.")
    return archive, manifest, signature


def _prepare_runtime_cache(release_path: Path, releases_root: Path, *, app_uid: int, group_gid: int, release_owner_uid: int) -> None:
    """Make only Laravel's generated bootstrap cache writable by the app account."""
    release_path = Path(release_path)
    try:
        if release_path != releases_root / release_path.name or not ARCHIVE_RE.fullmatch(f"gjej-{release_path.name}.tar.gz"):
            raise ReleaseError("Installer returned a release path outside the fixed releases directory.")
        release_info = release_path.lstat()
        bootstrap_path = release_path / "bootstrap"
        bootstrap_info = bootstrap_path.lstat()
        if (not stat.S_ISDIR(release_info.st_mode) or release_info.st_uid != release_owner_uid
                or not stat.S_ISDIR(bootstrap_info.st_mode) or bootstrap_info.st_uid != release_owner_uid
                or bootstrap_info.st_mode & 0o022):
            raise ReleaseError("Installed release bootstrap path is not protected as expected.")
        cache_path = bootstrap_path / "cache"
        try:
            cache_info = cache_path.lstat()
        except FileNotFoundError:
            cache_path.mkdir(mode=0o750)
            cache_info = cache_path.lstat()
        if not stat.S_ISDIR(cache_info.st_mode) or cache_info.st_mode & 0o022:
            raise ReleaseError("Laravel bootstrap cache must be a protected real directory.")
        if any(cache_path.iterdir()):
            raise ReleaseError("Fresh release bootstrap cache must be empty before runtime preparation.")
        os.chown(cache_path, app_uid, group_gid)
        os.chmod(cache_path, 0o750)
    except OSError as exc:
        raise ReleaseError("Unable to prepare Laravel's writable runtime cache directory.") from exc


def receive_submission(
    submission_id: str,
    settings: ReceiverSettings,
    *,
    audit_path: Path,
    verify: Callable[..., dict[str, Any]] = verify_signed_artifact,
    install: Callable[..., dict[str, Any]] = install_signed_release,
    require_root: bool = True,
) -> dict[str, Any]:
    if not SUBMISSION_ID_RE.fullmatch(submission_id):
        raise ReleaseError("Submission ID format is invalid.")
    if require_root and os.geteuid() != 0:
        raise ReleaseError("Release receiver must run as root.")
    expected_owner = 0 if require_root else os.geteuid()
    incoming_root = _assert_upload_root(settings.incoming_root, settings.uploader_uid, owner_uid=expected_owner, check_ancestors=require_root)
    quarantine_root = _assert_root_directory(settings.quarantine_root, private=True, owner_uid=expected_owner, check_ancestors=require_root)
    releases_root = _assert_root_directory(settings.releases_root, owner_uid=expected_owner, check_ancestors=require_root)
    trusted_key = settings.trusted_public_key
    if stat.S_ISLNK(trusted_key.lstat().st_mode):
        raise ReleaseError("Trusted release key may not be a symlink.")
    if not trusted_key.is_file() or trusted_key.stat().st_uid != expected_owner or trusted_key.stat().st_mode & 0o022:
        raise ReleaseError("Trusted release key must be a protected root-owned file.")
    upload_dir_fd = _open_upload_directory(incoming_root / submission_id, settings.uploader_uid)
    quarantine: Path | None = None
    quarantine_created = False
    try:
        archive_name, manifest_name, signature_name = _expected_submission_files(upload_dir_fd)
        quarantine = quarantine_root / submission_id
        quarantine.mkdir(mode=0o700)
        quarantine_created = True
        if require_root:
            os.chown(quarantine, 0, 0)
        os.chmod(quarantine, 0o700)
        archive = quarantine / archive_name
        manifest = quarantine / manifest_name
        signature = quarantine / signature_name
        _append_audit(audit_path, event="snapshot-started", submission_id=submission_id, owner_uid=expected_owner)
        _snapshot_file(archive_name, archive, MAX_ARCHIVE_BYTES, "Release archive", settings.uploader_uid, source_dir_fd=upload_dir_fd)
        _snapshot_file(manifest_name, manifest, MAX_MANIFEST_BYTES, "Release manifest", settings.uploader_uid, source_dir_fd=upload_dir_fd)
        _snapshot_file(signature_name, signature, MAX_SIGNATURE_BYTES, "Minisign signature", settings.uploader_uid, source_dir_fd=upload_dir_fd)
        os.chmod(archive, 0o600)
        os.chmod(manifest, 0o600)
        os.chmod(signature, 0o600)
        verified = verify(
            archive, manifest, signature, [trusted_key], settings.expected_identity,
        )
        if verified.get("status") != "verified":
            raise ReleaseError("Signed release verification did not return the expected status.")
        if archive_name != f"gjej-{verified.get('release_id')}.tar.gz":
            raise ReleaseError("Verified release ID does not match the submitted filename.")
        _append_audit(
            audit_path, event="signature-verified", submission_id=submission_id,
            details={"release_id": verified["release_id"], "commit_sha": verified["commit_sha"]},
            owner_uid=expected_owner,
        )
        _append_audit(
            audit_path, event="install-started", submission_id=submission_id,
            details={"release_id": verified["release_id"], "commit_sha": verified["commit_sha"]},
            owner_uid=expected_owner,
        )
        result = install(
            archive, manifest, signature, [trusted_key], settings.expected_identity,
            releases_root, owner_uid=0 if require_root else expected_owner, group_gid=settings.group_gid,
        )
        if result.get("status") != "installed-inactive" or result.get("activated") is not False:
            raise ReleaseError("Installer did not report a verified inactive installation.")
        _prepare_runtime_cache(
            Path(result["path"]), releases_root,
            app_uid=settings.owner_uid, group_gid=settings.group_gid,
            release_owner_uid=0 if require_root else expected_owner,
        )
        safe_result = {
            "status": "installed-inactive",
            "submission_id": submission_id,
            "release_id": result["release_id"],
            "commit_sha": result["commit_sha"],
            "sha256": result["sha256"],
            "activated": False,
        }
        _append_audit(audit_path, event="installed-inactive", submission_id=submission_id, details=safe_result, owner_uid=expected_owner)
        return safe_result
    except Exception as exc:
        if isinstance(exc, FileExistsError) and quarantine is not None and not quarantine_created:
            try:
                _append_audit(audit_path, event="duplicate-refused", submission_id=submission_id, owner_uid=expected_owner)
            except Exception:
                pass
            raise ReleaseError("This submission ID was already received; refusing duplicate processing.") from exc
        try:
            if quarantine is not None and quarantine.exists():
                _append_audit(
                    audit_path, event="failed", submission_id=submission_id,
                    details={"error_type": type(exc).__name__}, owner_uid=expected_owner,
                )
        except Exception:
            pass
        if isinstance(exc, ReleaseError):
            raise
        raise ReleaseError("Release receiver failed; quarantined files were retained for review.") from exc
    finally:
        os.close(upload_dir_fd)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--submission-id", required=True, help="GitHub run submission directory under /incoming")
    parser.add_argument("--config", type=Path, default=Path("/etc/gjej/release-receiver.json"))
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.exit(2, "gjej-receive must be run through sudo by an authorized administrator\n")
    try:
        settings = load_settings(args.config)
        lock_path = settings.quarantine_root.parent / "receiver.lock"
        lock_fd = os.open(lock_path, os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0), 0o600)
        try:
            info = os.fstat(lock_fd)
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
                raise ReleaseError("Receiver lock must be a private root-owned regular file.")
            fcntl.flock(lock_fd, fcntl.LOCK_EX)
            result = receive_submission(
                args.submission_id, settings,
                audit_path=Path("/var/log/gjej-release-receiver.jsonl"),
            )
        finally:
            os.close(lock_fd)
    except (ReleaseError, OSError, ValueError, KeyError) as exc:
        parser.exit(1, f"gjej-receive failed: {exc}\n")
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
