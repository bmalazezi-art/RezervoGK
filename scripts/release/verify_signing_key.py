#!/usr/bin/env python3
"""Sign and verify a synthetic message to check the configured release key."""

from __future__ import annotations

import argparse
import stat
from pathlib import Path

from scripts.release.common import ReleaseError
from scripts.release.sign_artifact import (
    _minisign_executable,
    _read_private_passphrase,
    _run_minisign,
    _run_minisign_with_passphrase,
    regular_file,
)
from scripts.release.trusted_key import validate_repository_pin


def verify_signing_key(
    private_key_path: Path,
    passphrase_path: Path,
    public_key_path: Path,
    message_path: Path,
    signature_path: Path,
) -> str:
    key_id = validate_repository_pin(public_key_path)
    private_key_path = regular_file(private_key_path, "Minisign secret key")
    private_info = private_key_path.stat()
    if private_info.st_mode & 0o077 or private_info.st_size > 65536:
        raise ReleaseError("Minisign secret key must be private and bounded.")
    message_path = regular_file(message_path, "Synthetic verification message")
    if signature_path.exists() or signature_path.is_symlink():
        raise ReleaseError("Refusing to overwrite an existing verification signature.")
    passphrase = _read_private_passphrase(passphrase_path)
    executable = _minisign_executable()

    sign_status = _run_minisign_with_passphrase(
        executable,
        ["-S", "-s", str(private_key_path), "-m", str(message_path), "-x", str(signature_path)],
        passphrase,
    )
    if sign_status != 0:
        raise ReleaseError("Configured signing key could not sign the synthetic verification message.")
    if not signature_path.is_file() or signature_path.is_symlink():
        raise ReleaseError("Minisign did not create a regular verification signature.")
    signature_info = signature_path.stat()
    if signature_info.st_mode & 0o077:
        raise ReleaseError("Synthetic verification signature permissions are unsafe.")

    verified = _run_minisign(
        executable,
        ["-V", "-m", str(message_path), "-x", str(signature_path), "-p", str(public_key_path)],
    )
    if verified.returncode != 0:
        raise ReleaseError("Configured signing key does not match the pinned Gjej v2 public key.")
    return key_id


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--private-key", required=True, type=Path)
    parser.add_argument("--passphrase-file", required=True, type=Path)
    parser.add_argument("--public-key", required=True, type=Path)
    parser.add_argument("--message", required=True, type=Path)
    parser.add_argument("--signature", required=True, type=Path)
    args = parser.parse_args()
    try:
        key_id = verify_signing_key(
            args.private_key, args.passphrase_file, args.public_key, args.message, args.signature,
        )
    except (OSError, ReleaseError) as exc:
        parser.exit(1, f"Signing-key verification failed: {exc}\n")
    print(f"Gjej release signing key verification: PASS (key ID {key_id}).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
