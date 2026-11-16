#!/usr/bin/env python3
"""Validate Gjej Portier's single pinned v2 Minisign trust root."""

from __future__ import annotations

import argparse
import base64
import binascii
import hashlib
import re
import stat
from pathlib import Path

from scripts.release.common import ReleaseError


V2_KEY_ID = "7984D1DCD74103CC"
V2_KEY_SHA256 = "ee0999b75e37b9aa3fd83515844d43ab236174d36b8e668dafbf5ee68820db5e"
_COMMENT_RE = re.compile(rb"untrusted comment: minisign public key ([0-9A-F]{16})")


def _read_public_key(path: Path) -> tuple[bytes, str]:
    try:
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode):
            raise ReleaseError("Pinned Minisign public key must be a regular non-symlink file.")
        if info.st_size > 4096:
            raise ReleaseError("Pinned Minisign public key file exceeds its size limit.")
        raw = path.read_bytes()
    except OSError as exc:
        raise ReleaseError("Pinned Minisign public key is unavailable.") from exc

    lines = raw.splitlines()
    if len(lines) != 2:
        raise ReleaseError("Pinned Minisign public key format is invalid.")
    match = _COMMENT_RE.fullmatch(lines[0])
    if match is None:
        raise ReleaseError("Pinned Minisign public key ID is malformed.")
    try:
        decoded = base64.b64decode(lines[1], validate=True)
    except (ValueError, binascii.Error) as exc:
        raise ReleaseError("Pinned Minisign public key encoding is malformed.") from exc
    if len(decoded) != 42:
        raise ReleaseError("Pinned Minisign public key payload has an invalid size.")

    key_id = match.group(1).decode("ascii")
    digest = hashlib.sha256(raw).hexdigest()
    if key_id != V2_KEY_ID or digest != V2_KEY_SHA256:
        raise ReleaseError("Minisign public key does not match the pinned Gjej v2 trust root.")
    return raw, key_id


def validate_repository_pin(path: Path) -> str:
    """Confirm the checked-out public-key file is the reviewed v2 pin."""
    _, key_id = _read_public_key(path)
    return key_id


def validate_installed_v2_key(
    path: Path,
    *,
    owner_uid: int = 0,
    group_gid: int = 0,
    expected_mode: int = 0o644,
) -> str:
    """Check the fixed installed v2 key's type, metadata, ID and digest."""
    raw, key_id = _read_public_key(path)
    try:
        info = path.lstat()
    except OSError as exc:
        raise ReleaseError("Installed Gjej v2 trust key is unavailable.") from exc
    if info.st_uid != owner_uid or info.st_gid != group_gid:
        raise ReleaseError("Installed Gjej v2 trust key has unexpected ownership.")
    if stat.S_IMODE(info.st_mode) != expected_mode or info.st_mode & 0o022:
        raise ReleaseError("Installed Gjej v2 trust key has unsafe permissions.")
    if hashlib.sha256(raw).hexdigest() != V2_KEY_SHA256 or key_id != V2_KEY_ID:
        raise ReleaseError("Installed Gjej v2 trust key does not match the pinned trust root.")
    return key_id


def validate_trust_pair(
    repository_key: Path,
    installed_key: Path,
    *,
    owner_uid: int = 0,
    group_gid: int = 0,
    expected_mode: int = 0o644,
) -> str:
    """Require repository and installed v2 pins to match the hard-coded root."""
    repository_raw, repository_id = _read_public_key(repository_key)
    installed_id = validate_installed_v2_key(
        installed_key,
        owner_uid=owner_uid,
        group_gid=group_gid,
        expected_mode=expected_mode,
    )
    if repository_raw != installed_key.read_bytes() or repository_id != installed_id:
        raise ReleaseError("Installed receiver trust key differs from the repository v2 pin.")
    return repository_id


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repository-key", required=True, type=Path)
    parser.add_argument("--installed-key", required=True, type=Path)
    args = parser.parse_args()
    try:
        key_id = validate_trust_pair(args.repository_key, args.installed_key)
    except (OSError, ReleaseError) as exc:
        parser.exit(1, f"Gjej receiver trust validation failed: {exc}\n")
    print(f"Gjej v2 trust pin verified (key ID {key_id}).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
