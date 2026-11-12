#!/usr/bin/env python3
"""Verify a signed release and safely publish it into a new release directory.

This tool never changes a `current` pointer, runs application commands, or applies
database migrations. Production use requires root-owned quarantine inputs and a
root-owned releases directory on the target host.
"""

from __future__ import annotations

import argparse
import ctypes
import errno
import fcntl
import json
import os
import shutil
import stat
import sys
import tarfile
import tempfile
from pathlib import Path
from typing import Any, Sequence

from scripts.release.common import ReleaseError
from scripts.release.sign_artifact import verify_signed_artifact
from scripts.release.validate_artifact import MAX_ARCHIVE_BYTES, MAX_MANIFEST_BYTES


MAX_SIGNATURE_BYTES = 1024 * 1024
COPY_CHUNK_BYTES = 1024 * 1024


def _regular_snapshot(source: Path, destination: Path, limit: int, label: str) -> None:
    """Copy a regular file without following its final symlink into private staging."""
    nofollow = getattr(os, "O_NOFOLLOW", 0)
    flags = os.O_RDONLY | nofollow
    try:
        source_fd = os.open(source, flags)
    except OSError as exc:
        raise ReleaseError(f"{label} could not be opened safely.") from exc
    try:
        before = os.fstat(source_fd)
        if not stat.S_ISREG(before.st_mode) or before.st_size > limit:
            raise ReleaseError(f"{label} must be a bounded regular file.")
        dest_fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        try:
            copied = 0
            while True:
                block = os.read(source_fd, COPY_CHUNK_BYTES)
                if not block:
                    break
                copied += len(block)
                if copied > limit:
                    raise ReleaseError(f"{label} exceeds its configured size bound.")
                view = memoryview(block)
                while view:
                    written = os.write(dest_fd, view)
                    if written <= 0:
                        raise ReleaseError(f"Unable to complete the private snapshot of {label}.")
                    view = view[written:]
            after = os.fstat(source_fd)
            if (before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns) != (
                after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns
            ) or copied != before.st_size:
                raise ReleaseError(f"{label} changed while it was being sealed.")
            os.fsync(dest_fd)
        finally:
            os.close(dest_fd)
    finally:
        os.close(source_fd)


def _assert_secure_releases_root(path: Path) -> Path:
    if not path.is_absolute():
        raise ReleaseError("The releases directory must be an absolute path.")
    try:
        if stat.S_ISLNK(path.lstat().st_mode):
            raise ReleaseError("The releases directory itself may not be a symlink.")
    except OSError as exc:
        raise ReleaseError("The releases directory path must already exist.") from exc
    try:
        # Resolve platform aliases (for example macOS /var -> /private/var),
        # then perform all subsequent access through the canonical path.
        path = path.resolve(strict=True)
    except OSError as exc:
        raise ReleaseError("The releases directory path must already exist.") from exc
    current = Path(path.anchor)
    for part in path.parts[1:]:
        current = current / part
        try:
            details = current.lstat()
        except OSError as exc:
            raise ReleaseError("The releases directory path must already exist.") from exc
        if stat.S_ISLNK(details.st_mode):
            raise ReleaseError("The releases directory path may not contain symlinks.")
        if not stat.S_ISDIR(details.st_mode) or details.st_uid not in (0, os.geteuid()) or details.st_mode & 0o022:
            raise ReleaseError("Every releases path component must be a protected directory owned by root or the installer.")
        if current == path and not stat.S_ISDIR(details.st_mode):
            raise ReleaseError("The releases path must be a real directory.")
    root = path.resolve(strict=True)
    details = root.stat()
    if details.st_uid != os.geteuid() or details.st_mode & 0o022:
        raise ReleaseError("The releases directory must belong to the installer and not be group/other-writable.")
    return root


def _assert_trusted_keys(trusted_keys: Sequence[Path]) -> list[Path]:
    if not trusted_keys:
        raise ReleaseError("At least one locally configured trusted Minisign public key is required.")
    resolved: list[Path] = []
    for key in trusted_keys:
        try:
            original = Path(key)
            if stat.S_ISLNK(original.lstat().st_mode):
                raise ReleaseError("Trusted public keys may not be symlinks.")
            trusted_path = original.resolve(strict=True)
            details = trusted_path.lstat()
        except OSError as exc:
            raise ReleaseError("A configured trusted public key is unavailable.") from exc
        if not stat.S_ISREG(details.st_mode) or details.st_uid != os.geteuid() or details.st_mode & 0o022:
            raise ReleaseError("Trusted public keys must be regular, non-writable files owned by the installer.")
        parent = trusted_path.parent
        while True:
            try:
                parent_details = parent.stat()
            except OSError as exc:
                raise ReleaseError("A trusted public key parent directory is unavailable.") from exc
            if not stat.S_ISDIR(parent_details.st_mode) or parent_details.st_uid not in (0, os.geteuid()) or parent_details.st_mode & 0o022:
                raise ReleaseError("Trusted public key directories must be protected and owned by root or the installer.")
            if parent == parent.parent:
                break
            parent = parent.parent
        resolved.append(trusted_path)
    return resolved


def _locked_install_root(root: Path) -> int:
    lock_path = root / ".gjej-release-install.lock"
    flags = os.O_CREAT | os.O_RDWR | getattr(os, "O_NOFOLLOW", 0)
    try:
        fd = os.open(lock_path, flags, 0o600)
        details = os.fstat(fd)
        if not stat.S_ISREG(details.st_mode) or details.st_nlink != 1 or details.st_uid != os.geteuid() or details.st_mode & 0o077:
            os.close(fd)
            raise ReleaseError("The install lock is not a private regular file owned by the installer.")
        fcntl.flock(fd, fcntl.LOCK_EX)
        return fd
    except OSError as exc:
        raise ReleaseError("Unable to lock the releases directory for installation.") from exc


def _open_directory(root_fd: int, components: Sequence[str], uid: int, gid: int, *, create: bool) -> int:
    fd = os.dup(root_fd)
    try:
        for component in components:
            if create:
                try:
                    os.mkdir(component, 0o750, dir_fd=fd)
                    os.fsync(fd)
                except FileExistsError:
                    pass
            flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_NOFOLLOW", 0)
            child_fd = os.open(component, flags, dir_fd=fd)
            os.close(fd)
            fd = child_fd
            if create:
                os.fchown(fd, uid, gid)
                os.fchmod(fd, 0o750)
        return fd
    except Exception:
        os.close(fd)
        raise


def _extract_into_new_tree(archive_path: Path, tree_path: Path, uid: int, gid: int) -> int:
    os.mkdir(tree_path, 0o700)
    root_fd = os.open(tree_path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_NOFOLLOW", 0))
    os.fchown(root_fd, uid, gid)
    os.fchmod(root_fd, 0o750)
    member_count = 0
    try:
        with tarfile.open(archive_path, mode="r:gz") as archive:
            for member in archive:
                member_count += 1
                # The validator has already rejected links, special files, unsafe
                # names, duplicate paths, non-root ownership and non-normal modes.
                parts = member.name.rstrip("/").split("/")
                if not parts or any(part in ("", ".", "..") or "/" in part or "\\" in part for part in parts):
                    raise ReleaseError("Archive path changed or became unsafe during extraction.")
                parent_fd = _open_directory(root_fd, parts[:-1], uid, gid, create=True)
                try:
                    name = parts[-1]
                    if member.isdir():
                        try:
                            os.mkdir(name, 0o750, dir_fd=parent_fd)
                        except FileExistsError:
                            pass
                        directory_fd = os.open(
                            name,
                            os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_NOFOLLOW", 0),
                            dir_fd=parent_fd,
                        )
                        try:
                            os.fchown(directory_fd, uid, gid)
                            os.fchmod(directory_fd, 0o750)
                            os.fsync(directory_fd)
                            os.fsync(parent_fd)
                        finally:
                            os.close(directory_fd)
                    elif member.isfile():
                        file_fd = os.open(
                            name,
                            os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0),
                            0o640,
                            dir_fd=parent_fd,
                        )
                        try:
                            stream = archive.extractfile(member)
                            if stream is None:
                                raise ReleaseError("Unable to read an archive member during extraction.")
                            remaining = member.size
                            while remaining:
                                block = stream.read(min(COPY_CHUNK_BYTES, remaining))
                                if not block:
                                    raise ReleaseError("Archive member ended before its declared size.")
                                view = memoryview(block)
                                while view:
                                    written = os.write(file_fd, view)
                                    if written <= 0:
                                        raise ReleaseError("Unable to complete an extracted archive member.")
                                    view = view[written:]
                                remaining -= len(block)
                            if stream.read(1):
                                raise ReleaseError("Archive member exceeded its declared size.")
                            os.fchown(file_fd, uid, gid)
                            os.fchmod(file_fd, 0o640)
                            os.fsync(file_fd)
                            os.fsync(parent_fd)
                        finally:
                            os.close(file_fd)
                    else:
                        raise ReleaseError("Links and special files are forbidden during extraction.")
                finally:
                    os.close(parent_fd)
        os.fsync(root_fd)
    except (OSError, tarfile.TarError) as exc:
        raise ReleaseError("Unable to safely extract the validated release archive.") from exc
    finally:
        os.close(root_fd)
    return member_count


def _rename_directory_noreplace(source: Path, destination: Path) -> None:
    """Atomically publish a directory only if its destination is still absent."""
    libc = ctypes.CDLL(None, use_errno=True)
    if sys.platform.startswith("linux"):
        renameat2 = getattr(libc, "renameat2", None)
        if renameat2 is None:
            raise ReleaseError("This Linux system does not provide atomic no-replace directory rename.")
        renameat2.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
        renameat2.restype = ctypes.c_int
        result = renameat2(-100, os.fsencode(source), -100, os.fsencode(destination), 1)  # RENAME_NOREPLACE
    elif sys.platform == "darwin":
        renamex_np = getattr(libc, "renamex_np", None)
        if renamex_np is None:
            raise ReleaseError("This macOS system does not provide atomic exclusive directory rename.")
        renamex_np.argtypes = [ctypes.c_char_p, ctypes.c_char_p, ctypes.c_uint]
        renamex_np.restype = ctypes.c_int
        result = renamex_np(os.fsencode(source), os.fsencode(destination), 0x00000004)  # RENAME_EXCL
    else:
        raise ReleaseError("Atomic no-replace directory publication is unsupported on this platform.")

    if result == 0:
        return
    error = ctypes.get_errno()
    if error == errno.EEXIST:
        raise ReleaseError("Refusing to overwrite an existing release directory.")
    raise OSError(error, os.strerror(error), str(destination))


def install_signed_release(
    archive_path: Path,
    manifest_path: Path,
    signature_path: Path,
    trusted_public_key_paths: Sequence[Path],
    expected_identity: str,
    releases_root: Path,
    *,
    owner_uid: int,
    group_gid: int,
) -> dict[str, Any]:
    """Verify sealed input snapshots, extract without links, then publish a new directory."""
    releases_root = _assert_secure_releases_root(releases_root)
    if owner_uid < 0 or group_gid < 0:
        raise ReleaseError("Release owner and group IDs must be non-negative integers.")
    trusted_public_key_paths = _assert_trusted_keys(trusted_public_key_paths)
    lock_fd = _locked_install_root(releases_root)
    workspace: Path | None = None
    try:
        workspace = Path(tempfile.mkdtemp(prefix=".gjej-install-", dir=releases_root))
        os.chmod(workspace, 0o700)
        sealed_archive = workspace / Path(archive_path).name
        sealed_manifest = workspace / "release.manifest.json"
        sealed_signature = workspace / "release.manifest.minisig"
        _regular_snapshot(Path(archive_path), sealed_archive, MAX_ARCHIVE_BYTES, "Release archive")
        _regular_snapshot(Path(manifest_path), sealed_manifest, MAX_MANIFEST_BYTES, "Release manifest")
        _regular_snapshot(Path(signature_path), sealed_signature, MAX_SIGNATURE_BYTES, "Minisign signature")

        verified = verify_signed_artifact(
            sealed_archive, sealed_manifest, sealed_signature,
            trusted_public_key_paths, expected_identity,
        )
        release_id = verified["release_id"]
        destination = releases_root / release_id
        if destination.exists() or destination.is_symlink():
            raise ReleaseError("Refusing to overwrite an existing release directory.")

        tree = workspace / "tree"
        extracted_members = _extract_into_new_tree(sealed_archive, tree, owner_uid, group_gid)
        if extracted_members != verified["member_count"]:
            raise ReleaseError("Extracted archive member count differs from the validated artifact.")
        # A second structural/digest check catches any unexpected staged-file mutation
        # before the inactive candidate becomes visible under its final release ID.
        verified_again = verify_signed_artifact(
            sealed_archive, sealed_manifest, sealed_signature,
            trusted_public_key_paths, expected_identity,
        )
        if verified_again["sha256"] != verified["sha256"]:
            raise ReleaseError("Release artifact changed during installation.")
        _rename_directory_noreplace(tree, destination)
        parent_fd = os.open(releases_root, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_NOFOLLOW", 0))
        try:
            os.fsync(parent_fd)
        finally:
            os.close(parent_fd)
        return {
            "status": "installed-inactive",
            "release_id": release_id,
            "commit_sha": verified["commit_sha"],
            "sha256": verified["sha256"],
            "signer_identity": verified["signer_identity"],
            "verified_key": verified["verified_key"],
            "path": str(destination),
            "member_count": extracted_members,
            "activated": False,
        }
    finally:
        if workspace is not None:
            shutil.rmtree(workspace, ignore_errors=True)
        os.close(lock_fd)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--archive", required=True, type=Path)
    parser.add_argument("--manifest", required=True, type=Path)
    parser.add_argument("--signature", required=True, type=Path)
    parser.add_argument("--trusted-public-key", required=True, action="append", type=Path)
    parser.add_argument("--expected-identity", required=True)
    parser.add_argument("--releases-root", required=True, type=Path)
    parser.add_argument("--owner-uid", required=True, type=int)
    parser.add_argument("--group-gid", required=True, type=int)
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.exit(2, "release installation must run as root on the production host\n")
    try:
        result = install_signed_release(
            args.archive, args.manifest, args.signature,
            args.trusted_public_key, args.expected_identity, args.releases_root,
            owner_uid=args.owner_uid, group_gid=args.group_gid,
        )
    except (ReleaseError, OSError, ValueError) as exc:
        parser.exit(1, f"release installation failed: {exc}\n")
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
