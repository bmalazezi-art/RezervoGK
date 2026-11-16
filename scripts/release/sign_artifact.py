#!/usr/bin/env python3
"""Create and verify Minisign signatures for release manifests."""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import re
import shutil
import stat
import subprocess
import tempfile
from pathlib import Path
from typing import Any, Sequence

from scripts.release.common import ReleaseError
from scripts.release.validate_artifact import MAX_MANIFEST_BYTES, validate_archive


MINISIGN_MIN_VERSION = (0, 12)
TRUSTED_COMMENT_PREFIX = "gjej-release-v1:"
IDENTITY_RE = re.compile(r"^[^\x00-\x20\x7f]{1,512}$")
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
COMMIT_RE = re.compile(r"^(?:[0-9a-f]{40}|[0-9a-f]{64})$")
RELEASE_ID_RE = re.compile(r"^[0-9a-f]{12}-[0-9]{8}T[0-9]{6}Z$")
METADATA_FIELDS = {
    "schema_version",
    "signer_identity",
    "manifest_sha256",
    "artifact_file",
    "artifact_sha256",
    "commit_sha",
    "release_id",
}


def json_without_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ReleaseError(f"Signed metadata contains duplicate JSON key {key!r}.")
        result[key] = value
    return result


def regular_file(path: Path, label: str) -> Path:
    try:
        details = path.lstat()
    except OSError as exc:
        raise ReleaseError(f"{label} is unavailable.") from exc
    if not stat.S_ISREG(details.st_mode):
        raise ReleaseError(f"{label} must be a regular non-symlink file.")
    return path.resolve(strict=True)


def _identity(value: str) -> str:
    if not isinstance(value, str) or not IDENTITY_RE.fullmatch(value):
        raise ReleaseError("Signer identity must be a non-empty printable string (maximum 512 characters).")
    return value


def _minisign_executable() -> str:
    executable = shutil.which("minisign")
    if not executable:
        raise ReleaseError("Minisign >= 0.12 is required; install the official Minisign CLI and add it to PATH.")
    try:
        result = subprocess.run(
            [executable, "-v"], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT, check=False, timeout=10,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise ReleaseError("Unable to query the Minisign version.") from exc
    output = result.stdout.decode("utf-8", errors="replace").strip()
    match = re.search(r"\bminisign\s+(\d+)\.(\d+)(?:\.(\d+))?\b", output, re.IGNORECASE)
    if result.returncode != 0 or not match:
        raise ReleaseError("Could not identify the Minisign CLI version; expected official Minisign >= 0.12.")
    version = (int(match.group(1)), int(match.group(2)))
    if version < MINISIGN_MIN_VERSION:
        raise ReleaseError("Minisign >= 0.12 is required.")
    return executable


def _run_minisign(
    executable: str,
    args: list[str],
    *,
    interactive: bool = False,
) -> subprocess.CompletedProcess[bytes]:
    try:
        options: dict[str, Any] = {
            "stdout": subprocess.PIPE,
            "stderr": subprocess.PIPE,
            "check": False,
            "timeout": 60,
        }
        if interactive:
            # Minisign reads the protected-key passphrase from the controlling
            # terminal and writes its prompt to stderr. Inherit both streams so
            # the operator can see and answer that prompt.
            options["stdout"] = None
            options["stderr"] = None
        result = subprocess.run([executable, *args], **options)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise ReleaseError("Minisign operation failed or timed out.") from exc
    return result


def _manifest_bytes(path: Path) -> bytes:
    path = regular_file(path, "Release manifest")
    if path.stat().st_size > MAX_MANIFEST_BYTES:
        raise ReleaseError("Release manifest is too large.")
    try:
        return path.read_bytes()
    except OSError as exc:
        raise ReleaseError("Release manifest is unreadable.") from exc


def _manifest_object(raw: bytes) -> dict[str, Any]:
    try:
        value = json.loads(raw, object_pairs_hook=json_without_duplicate_keys)
    except (UnicodeDecodeError, json.JSONDecodeError, RecursionError) as exc:
        raise ReleaseError("Release manifest is not valid bounded JSON.") from exc
    if not isinstance(value, dict):
        raise ReleaseError("Release manifest must be a JSON object.")
    return value


def _signed_metadata(manifest_raw: bytes, manifest: dict[str, Any], identity: str) -> dict[str, Any]:
    archive = manifest.get("archive")
    if not isinstance(archive, dict):
        raise ReleaseError("Release manifest has no archive metadata.")
    metadata = {
        "schema_version": 1,
        "signer_identity": identity,
        "manifest_sha256": hashlib.sha256(manifest_raw).hexdigest(),
        "artifact_file": archive.get("file"),
        "artifact_sha256": archive.get("sha256"),
        "commit_sha": manifest.get("commit_sha"),
        "release_id": manifest.get("release_id"),
    }
    if not isinstance(metadata["artifact_file"], str) or not metadata["artifact_file"] or "/" in metadata["artifact_file"] or "\\" in metadata["artifact_file"]:
        raise ReleaseError("Manifest artifact filename is invalid.")
    if not isinstance(metadata["artifact_sha256"], str) or not SHA256_RE.fullmatch(metadata["artifact_sha256"]):
        raise ReleaseError("Manifest artifact digest is malformed.")
    if not isinstance(metadata["commit_sha"], str) or not COMMIT_RE.fullmatch(metadata["commit_sha"]):
        raise ReleaseError("Manifest commit SHA is malformed.")
    if not isinstance(metadata["release_id"], str) or not RELEASE_ID_RE.fullmatch(metadata["release_id"]):
        raise ReleaseError("Manifest release ID is malformed.")
    return metadata


def _trusted_comment(metadata: dict[str, Any]) -> str:
    compact = json.dumps(metadata, ensure_ascii=True, sort_keys=True, separators=(",", ":")).encode("ascii")
    encoded = base64.urlsafe_b64encode(compact).decode("ascii").rstrip("=")
    comment = TRUSTED_COMMENT_PREFIX + encoded
    # Minisign supports trusted comments up to 8192 bytes. Keep a margin for
    # implementation/version-specific framing overhead.
    if len(comment.encode("ascii")) > 7000:
        raise ReleaseError("Signed release metadata is too large for a Minisign trusted comment.")
    return comment


def _decode_trusted_comment(raw_comment: bytes) -> dict[str, Any]:
    try:
        comment = raw_comment.decode("utf-8").strip()
        if not comment.startswith(TRUSTED_COMMENT_PREFIX):
            raise ValueError("wrong comment prefix")
        token = comment[len(TRUSTED_COMMENT_PREFIX):]
        if not token or not re.fullmatch(r"[A-Za-z0-9_-]+", token):
            raise ValueError("malformed metadata token")
        padded = token + "=" * (-len(token) % 4)
        parsed = json.loads(base64.b64decode(padded, altchars=b"-_", validate=True), object_pairs_hook=json_without_duplicate_keys)
    except (UnicodeDecodeError, ValueError, TypeError, json.JSONDecodeError, RecursionError, ReleaseError) as exc:
        raise ReleaseError("Verified Minisign trusted comment is missing or malformed.") from exc
    if not isinstance(parsed, dict) or set(parsed) != METADATA_FIELDS:
        raise ReleaseError("Verified Minisign trusted comment has missing or unexpected fields.")
    return parsed


def _validate_metadata(metadata: dict[str, Any], expected_identity: str) -> None:
    if type(metadata["schema_version"]) is not int or metadata["schema_version"] != 1:
        raise ReleaseError("Unsupported signed release metadata schema.")
    if metadata["signer_identity"] != expected_identity:
        raise ReleaseError("Minisign trusted comment identity does not match the configured identity.")
    if not isinstance(metadata["manifest_sha256"], str) or not SHA256_RE.fullmatch(metadata["manifest_sha256"]):
        raise ReleaseError("Signed manifest digest is malformed.")
    if not isinstance(metadata["artifact_file"], str) or not metadata["artifact_file"] or "/" in metadata["artifact_file"] or "\\" in metadata["artifact_file"]:
        raise ReleaseError("Signed artifact filename is malformed.")
    if not isinstance(metadata["artifact_sha256"], str) or not SHA256_RE.fullmatch(metadata["artifact_sha256"]):
        raise ReleaseError("Signed artifact digest is malformed.")
    if not isinstance(metadata["commit_sha"], str) or not COMMIT_RE.fullmatch(metadata["commit_sha"]):
        raise ReleaseError("Signed commit SHA is malformed.")
    if not isinstance(metadata["release_id"], str) or not RELEASE_ID_RE.fullmatch(metadata["release_id"]):
        raise ReleaseError("Signed release ID is malformed.")


def sign_artifact(
    archive_path: Path,
    manifest_path: Path,
    private_key_path: Path,
    signer_identity: str,
    signature_path: Path,
) -> Path:
    executable = _minisign_executable()
    archive_path = regular_file(archive_path, "Release archive")
    manifest_path = regular_file(manifest_path, "Release manifest")
    private_key_path = regular_file(private_key_path, "Minisign private key")
    if private_key_path.stat().st_mode & 0o077:
        raise ReleaseError("Minisign private key permissions must exclude group and other access.")
    identity = _identity(signer_identity)

    # Only sign a structurally validated release; no archive is unpacked here.
    validate_archive(archive_path, manifest_path)
    manifest_raw = _manifest_bytes(manifest_path)
    metadata = _signed_metadata(manifest_raw, _manifest_object(manifest_raw), identity)
    comment = _trusted_comment(metadata)

    signature_path = signature_path.expanduser().absolute()
    signature_path.parent.mkdir(parents=True, exist_ok=True)
    try:
        with tempfile.TemporaryDirectory(prefix="gjej-minisign-", dir=signature_path.parent) as temp_name:
            temporary_signature = Path(temp_name) / "release.manifest.minisig"
            result = _run_minisign(executable, [
                "-S", "-s", str(private_key_path), "-m", str(manifest_path),
                "-x", str(temporary_signature), "-t", comment,
            ], interactive=True)
            if result.returncode != 0:
                raise ReleaseError("Minisign failed to sign the release manifest (check the protected key and signing environment).")
            regular_file(temporary_signature, "Generated Minisign signature")
            try:
                os.link(temporary_signature, signature_path)
            except FileExistsError as exc:
                raise ReleaseError("Refusing to overwrite an existing Minisign signature file.") from exc
    except OSError as exc:
        raise ReleaseError("Unable to publish the Minisign signature file.") from exc
    return signature_path


def verify_signed_artifact(
    archive_path: Path,
    manifest_path: Path,
    signature_path: Path,
    trusted_public_key_paths: Sequence[Path],
    expected_identity: str,
) -> dict[str, Any]:
    executable = _minisign_executable()
    archive_path = regular_file(archive_path, "Release archive")
    manifest_path = regular_file(manifest_path, "Release manifest")
    signature_path = regular_file(signature_path, "Minisign detached signature")
    identity = _identity(expected_identity)
    if not trusted_public_key_paths:
        raise ReleaseError("At least one locally configured trusted Minisign public key is required.")
    trusted_keys = [regular_file(Path(path), "Configured trusted Minisign public key") for path in trusted_public_key_paths]

    verified_comment: bytes | None = None
    verified_key: Path | None = None
    for public_key in trusted_keys:
        result = _run_minisign(executable, [
            "-V", "-Q", "-m", str(manifest_path), "-x", str(signature_path), "-p", str(public_key),
        ])
        if result.returncode == 0:
            verified_comment = result.stdout
            verified_key = public_key
            break
    if verified_comment is None or verified_key is None:
        raise ReleaseError("Minisign verification failed for all configured trust roots.")

    # Minisign -Q emits a trusted comment only after verifying it with the
    # signature. Parse signed metadata only after this successful check.
    metadata = _decode_trusted_comment(verified_comment)
    _validate_metadata(metadata, identity)

    manifest_raw = _manifest_bytes(manifest_path)
    if hashlib.sha256(manifest_raw).hexdigest() != metadata["manifest_sha256"]:
        raise ReleaseError("Signed manifest digest does not match the supplied manifest.")
    manifest = _manifest_object(manifest_raw)
    signed_fields = _signed_metadata(manifest_raw, manifest, identity)
    if metadata != signed_fields:
        raise ReleaseError("Signed metadata does not match the supplied manifest.")
    if metadata["artifact_file"] != archive_path.name:
        raise ReleaseError("Signed manifest references a different artifact filename.")

    validated = validate_archive(archive_path, manifest_path)
    if validated["sha256"] != metadata["artifact_sha256"]:
        raise ReleaseError("Artifact SHA-256 does not match the Minisign-authenticated manifest.")
    return {
        "status": "verified",
        **validated,
        "signer_identity": identity,
        "verified_key": str(verified_key),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)

    sign = commands.add_parser("sign", help="Sign a validated artifact manifest with Minisign")
    sign.add_argument("--archive", required=True, type=Path)
    sign.add_argument("--manifest", required=True, type=Path)
    sign.add_argument("--private-key", required=True, type=Path)
    sign.add_argument("--signer-identity", required=True)
    sign.add_argument("--signature", required=True, type=Path)

    verify = commands.add_parser("verify", help="Verify a Minisign signature and release contents")
    verify.add_argument("--archive", required=True, type=Path)
    verify.add_argument("--manifest", required=True, type=Path)
    verify.add_argument("--signature", required=True, type=Path)
    verify.add_argument("--trusted-public-key", required=True, action="append", type=Path, help="Pinned trust root; repeat during an approved rotation overlap")
    verify.add_argument("--expected-identity", required=True)

    args = parser.parse_args()
    try:
        if args.command == "sign":
            signature = sign_artifact(args.archive, args.manifest, args.private_key, args.signer_identity, args.signature)
            output: dict[str, Any] = {"signature": str(signature), "status": "signed"}
        else:
            output = verify_signed_artifact(args.archive, args.manifest, args.signature, args.trusted_public_key, args.expected_identity)
    except (ReleaseError, OSError) as exc:
        parser.exit(1, f"release Minisign operation failed: {exc}\n")
    print(json.dumps(output, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
