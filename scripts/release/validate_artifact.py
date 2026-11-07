#!/usr/bin/env python3
"""Validate a Gjej portier release archive without extracting it."""

from __future__ import annotations

import argparse
import gzip
import hashlib
import json
import re
import stat
import tarfile
import zlib
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath
from typing import Any

from scripts.release.common import ReleaseError


MAX_ARCHIVE_BYTES = 512 * 1024 * 1024
MAX_EXPANDED_BYTES = 1024 * 1024 * 1024
MAX_MEMBERS = 50_000
MAX_MANIFEST_BYTES = 10 * 1024 * 1024
MAX_TAR_STREAM_BYTES = MAX_EXPANDED_BYTES + MAX_MEMBERS * 1024 + 10_240

ALLOWED_HIDDEN_PATHS = {"public/.htaccess", "public/.user.ini"}
ALLOWED_TOP_LEVEL = {
    "app",
    "artisan",
    "bootstrap",
    "composer.json",
    "composer.lock",
    "config",
    "database",
    "lang",
    "package-lock.json",
    "package.json",
    "public",
    "resources",
    "routes",
    "vendor",
}
REQUIRED_FILES = {
    "artisan",
    "bootstrap/app.php",
    "bootstrap/providers.php",
    "config/app.php",
    "config/filesystems.php",
    "config/session.php",
    "composer.json",
    "composer.lock",
    "lang/en.json",
    "lang/sq.json",
    "package-lock.json",
    "package.json",
    "public/index.php",
    "public/build/manifest.json",
    "resources/views/app.blade.php",
    "routes/web.php",
    "routes/console.php",
    "vendor/autoload.php",
    "vendor/composer/installed.php",
}
FORBIDDEN_COMPONENTS = {
    ".git",
    "node_modules",
    "storage",
    "tests",
    "docs",
    "__pycache__",
    "uploads",
    "receipts-private",
    "keeper-photos",
    "sessions",
    "logs",
    "backups",
    "coverage",
}
FORBIDDEN_SUFFIXES = (
    ".log",
    ".sql",
    ".sqlite",
    ".sqlite3",
    ".db",
    ".dump",
    ".bak",
    ".backup",
    ".pem",
    ".key",
    ".p12",
    ".pfx",
    ".crt",
    ".cer",
    ".p8",
    ".p7b",
    ".p7c",
    ".jks",
    ".keystore",
    ".kdbx",
    ".gpg",
)
FORBIDDEN_FILENAMES = {
    "auth.json",
    "credentials",
    "credentials.json",
    "service-account.json",
    "id_rsa",
    "id_ed25519",
    "id_ecdsa",
    "known_hosts",
    "authorized_keys",
}
FORBIDDEN_ARCHIVE_SUFFIXES = (
    ".tar",
    ".tar.gz",
    ".tar.bz2",
    ".tar.xz",
    ".tgz",
    ".tbz",
    ".tbz2",
    ".txz",
    ".7z",
    ".rar",
    ".zip",
    ".gz",
    ".bz2",
    ".xz",
    ".zst",
)
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
COMMIT_RE = re.compile(r"^(?:[0-9a-f]{40}|[0-9a-f]{64})$")
RELEASE_ID_RE = re.compile(r"^[0-9a-f]{12}-[0-9]{8}T[0-9]{6}Z$")


def json_without_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ReleaseError(f"JSON object contains a duplicate key: {key!r}")
        result[key] = value
    return result


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def normalize_member_name(raw: str, is_directory: bool) -> str:
    if not raw or "\x00" in raw or "\\" in raw or raw.startswith("/"):
        raise ReleaseError(f"Unsafe archive path: {raw!r}")

    path = raw[:-1] if is_directory and raw.endswith("/") else raw
    parts = path.split("/")
    if any(part == ".." for part in parts):
        raise ReleaseError(f"Path traversal in archive path: {raw!r}")
    normalized_parts = [part for part in parts if part not in ("", ".")]
    if not normalized_parts:
        raise ReleaseError("Archive contains an empty/root member path.")
    return "/".join(normalized_parts)


def validate_member_path(name: str) -> None:
    parts = name.split("/")
    lower_parts = [part.lower() for part in parts]
    basename = lower_parts[-1]

    if parts[0] not in ALLOWED_TOP_LEVEL:
        raise ReleaseError(f"Unexpected top-level release path: {parts[0]!r}")

    if name.startswith("bootstrap/cache/") or name == "bootstrap/cache":
        raise ReleaseError("Runtime bootstrap cache files must not enter a release artifact.")

    if len(parts) >= 2 and parts[:2] == ["public", "storage"]:
        raise ReleaseError("public/storage must be linked on the host, not packaged.")
    if name == "public/hot" or name.startswith("public/hot/"):
        raise ReleaseError("Vite development marker public/hot is forbidden.")

    for part in lower_parts:
        if part in FORBIDDEN_COMPONENTS:
            raise ReleaseError(f"Forbidden archive path component: {part!r}")
        if part.lower().startswith(".env"):
            raise ReleaseError("Environment files are forbidden in release artifacts.")
        if part.startswith(".") and name not in ALLOWED_HIDDEN_PATHS:
            raise ReleaseError(f"Unexpected hidden archive path: {name!r}")

    if basename in FORBIDDEN_FILENAMES or basename.endswith(FORBIDDEN_SUFFIXES):
        raise ReleaseError(f"Credential, log, database or backup file is forbidden: {name!r}")
    if basename.endswith(FORBIDDEN_ARCHIVE_SUFFIXES):
        raise ReleaseError(f"Nested archive is forbidden: {name!r}")
    if basename.endswith((".tmp", ".temp", "~")):
        raise ReleaseError(f"Temporary file is forbidden: {name!r}")


def read_regular_member(archive: tarfile.TarFile, member: tarfile.TarInfo, max_bytes: int) -> bytes:
    if not member.isfile() or member.size > max_bytes:
        raise ReleaseError("Expected a bounded regular archive member.")
    stream = archive.extractfile(member)
    if stream is None:
        raise ReleaseError("Unable to read a required archive member.")
    data = stream.read(max_bytes + 1)
    if len(data) != member.size or len(data) > max_bytes:
        raise ReleaseError("Archive member size did not match its contents.")
    return data


def preflight_tar_gzip(archive_path: Path) -> tuple[int, int]:
    """Bound and inspect raw tar headers before tarfile parses metadata extensions."""
    stream_bytes = 0
    member_count = 0
    expanded_size = 0

    def read_exact(stream: Any, size: int) -> bytes:
        nonlocal stream_bytes
        chunks: list[bytes] = []
        remaining = size
        while remaining:
            chunk = stream.read(min(remaining, 1024 * 1024))
            if not chunk:
                raise ReleaseError("Compressed archive ends before the declared tar contents.")
            stream_bytes += len(chunk)
            if stream_bytes > MAX_TAR_STREAM_BYTES:
                raise ReleaseError("Uncompressed tar stream exceeds the configured bound.")
            chunks.append(chunk)
            remaining -= len(chunk)
        return b"".join(chunks)

    try:
        with gzip.open(archive_path, "rb") as stream:
            while True:
                header = stream.read(512)
                stream_bytes += len(header)
                if stream_bytes > MAX_TAR_STREAM_BYTES:
                    raise ReleaseError("Uncompressed tar stream exceeds the configured bound.")
                if len(header) != 512:
                    raise ReleaseError("Tar archive is truncated or lacks an end marker.")
                if header == b"\0" * 512:
                    second = read_exact(stream, 512)
                    if second != b"\0" * 512:
                        raise ReleaseError("Tar archive has only one end marker block.")
                    while True:
                        trailing = stream.read(1024 * 1024)
                        if not trailing:
                            break
                        stream_bytes += len(trailing)
                        if stream_bytes > MAX_TAR_STREAM_BYTES:
                            raise ReleaseError("Uncompressed tar stream exceeds the configured bound.")
                        if any(trailing):
                            raise ReleaseError("Unexpected non-zero data follows the tar end markers.")
                    return member_count, expanded_size

                typeflag = header[156:157]
                if typeflag not in (b"\0", b"0", b"5"):
                    raise ReleaseError("PAX/GNU metadata, links and special tar entries are forbidden.")

                size_field = header[124:136]
                if size_field[0] & 0x80:
                    raise ReleaseError("Base-256 tar sizes are not supported.")
                size_text = size_field.strip(b" \0")
                if size_text and any(byte not in b"01234567" for byte in size_text):
                    raise ReleaseError("Tar entry has an invalid size field.")
                size = int(size_text or b"0", 8)
                if typeflag == b"5" and size:
                    raise ReleaseError("Tar directories must have zero content size.")

                member_count += 1
                if member_count > MAX_MEMBERS:
                    raise ReleaseError("Release archive contains too many members.")
                if typeflag != b"5":
                    expanded_size += size
                    if expanded_size > MAX_EXPANDED_BYTES:
                        raise ReleaseError("Expanded release archive exceeds the configured size limit.")

                padded_size = ((size + 511) // 512) * 512
                remaining = padded_size
                while remaining:
                    amount = min(remaining, 1024 * 1024)
                    read_exact(stream, amount)
                    remaining -= amount
    except ReleaseError:
        raise
    except (OSError, EOFError, gzip.BadGzipFile, zlib.error) as exc:
        raise ReleaseError("Release archive is not a bounded, readable gzip tar file.") from exc


def validate_vite_manifest(manifest_bytes: bytes, regular_files: set[str]) -> set[str]:
    try:
        manifest = json.loads(manifest_bytes, object_pairs_hook=json_without_duplicate_keys)
    except (UnicodeDecodeError, json.JSONDecodeError, RecursionError) as exc:
        raise ReleaseError("Vite manifest is not valid UTF-8 JSON.") from exc

    if not isinstance(manifest, dict) or not manifest:
        raise ReleaseError("Vite manifest must be a non-empty object.")
    referenced_files: set[str] = set()

    def validate_asset(reference: Any) -> None:
        if not isinstance(reference, str) or not reference:
            raise ReleaseError("Vite asset reference must be a non-empty string.")
        path = PurePosixPath(reference)
        if path.is_absolute() or ".." in path.parts or "\\" in reference:
            raise ReleaseError("Vite manifest contains an unsafe asset reference.")
        full_path = "public/build/" + reference
        validate_member_path(full_path)
        if full_path not in regular_files:
            raise ReleaseError(f"Vite manifest references a missing asset: {reference!r}")
        referenced_files.add(full_path)

    for key, entry in manifest.items():
        if not isinstance(key, str) or not isinstance(entry, dict):
            raise ReleaseError("Vite manifest entries have an invalid shape.")
        if "file" not in entry:
            raise ReleaseError("Every Vite manifest entry must reference its generated file.")
        for field in ("file",):
            if field in entry:
                validate_asset(entry[field])
        for field in ("css", "assets"):
            if field in entry:
                values = entry[field]
                if not isinstance(values, list):
                    raise ReleaseError(f"Vite manifest field {field!r} must be an array.")
                for value in values:
                    validate_asset(value)
        for field in ("imports", "dynamicImports"):
            if field in entry:
                values = entry[field]
                if not isinstance(values, list) or any(value not in manifest for value in values):
                    raise ReleaseError(f"Vite manifest field {field!r} references an unknown entry.")
    build_files = {path for path in regular_files if path.startswith("public/build/") and path != "public/build/manifest.json"}
    if build_files != referenced_files:
        unexpected = sorted(build_files - referenced_files)
        missing = sorted(referenced_files - build_files)
        raise ReleaseError(f"Vite build files do not match manifest references; unexpected={unexpected!r}, missing={missing!r}.")
    return referenced_files


def validate_manifest(archive_path: Path, manifest_path: Path) -> dict[str, Any]:
    if manifest_path.stat().st_size > MAX_MANIFEST_BYTES:
        raise ReleaseError("Release manifest is too large.")
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"), object_pairs_hook=json_without_duplicate_keys)
    except (OSError, UnicodeDecodeError, json.JSONDecodeError, RecursionError) as exc:
        raise ReleaseError("Release manifest is unreadable or invalid JSON.") from exc

    required_keys = {
        "schema_version",
        "release_id",
        "git_ref",
        "commit_sha",
        "build_timestamp_utc",
        "composer_lock_sha256",
        "npm_lock_sha256",
        "archive",
    }
    if not isinstance(manifest, dict) or set(manifest) != required_keys:
        raise ReleaseError("Release manifest has missing or unexpected fields.")
    if manifest["schema_version"] != 1:
        raise ReleaseError("Unsupported release manifest schema.")
    if not isinstance(manifest["release_id"], str) or not RELEASE_ID_RE.fullmatch(manifest["release_id"]):
        raise ReleaseError("Release ID is invalid.")
    if not isinstance(manifest["git_ref"], str) or not manifest["git_ref"] or any(ord(c) < 32 for c in manifest["git_ref"]):
        raise ReleaseError("Selected Git ref is missing or invalid.")
    if not isinstance(manifest["commit_sha"], str) or not COMMIT_RE.fullmatch(manifest["commit_sha"]):
        raise ReleaseError("Commit SHA is invalid.")
    commit_sha = manifest["commit_sha"]
    if not manifest["release_id"].startswith(commit_sha[:12] + "-"):
        raise ReleaseError("Release ID does not match the recorded commit SHA.")
    if COMMIT_RE.fullmatch(manifest["git_ref"].lower()) and manifest["git_ref"].lower() != commit_sha:
        raise ReleaseError("Selected commit ref does not match the recorded commit SHA.")
    if not isinstance(manifest["build_timestamp_utc"], str):
        raise ReleaseError("Build timestamp is missing.")
    try:
        timestamp = datetime.fromisoformat(manifest["build_timestamp_utc"].replace("Z", "+00:00"))
    except ValueError as exc:
        raise ReleaseError("Build timestamp is invalid.") from exc
    if timestamp.tzinfo != timezone.utc:
        raise ReleaseError("Build timestamp must be UTC.")
    if timestamp.strftime("%Y%m%dT%H%M%SZ") != manifest["release_id"][13:]:
        raise ReleaseError("Release ID timestamp does not match the build timestamp.")
    for field in ("composer_lock_sha256", "npm_lock_sha256"):
        if not isinstance(manifest[field], str) or not SHA256_RE.fullmatch(manifest[field]):
            raise ReleaseError(f"Manifest field {field!r} is invalid.")

    artifact = manifest["archive"]
    if not isinstance(artifact, dict) or set(artifact) != {"file", "size_bytes", "sha256"}:
        raise ReleaseError("Manifest archive metadata has an invalid shape.")
    if artifact["file"] != archive_path.name or artifact["file"] != f"gjej-{manifest['release_id']}.tar.gz":
        raise ReleaseError("Manifest archive filename does not match the supplied archive.")
    if isinstance(artifact["size_bytes"], bool) or not isinstance(artifact["size_bytes"], int) or artifact["size_bytes"] != archive_path.stat().st_size:
        raise ReleaseError("Manifest archive size does not match the supplied archive.")
    if not isinstance(artifact["sha256"], str) or not SHA256_RE.fullmatch(artifact["sha256"]):
        raise ReleaseError("Manifest archive digest is invalid.")
    if sha256_file(archive_path) != artifact["sha256"]:
        raise ReleaseError("SHA-256 digest mismatch.")
    return manifest


def _regular_input(path: Path, label: str) -> Path:
    try:
        details = path.lstat()
    except OSError as exc:
        raise ReleaseError(f"{label} is unavailable.") from exc
    if not path.is_absolute():
        path = path.resolve(strict=True)
    if not stat.S_ISREG(details.st_mode):
        raise ReleaseError(f"{label} must be a regular file, not a link or special file.")
    return path.resolve(strict=True)


def validate_archive(archive_path: Path, manifest_path: Path) -> dict[str, Any]:
    archive_path = _regular_input(archive_path, "Release archive")
    manifest_path = _regular_input(manifest_path, "Release manifest")
    if archive_path.stat().st_size > MAX_ARCHIVE_BYTES:
        raise ReleaseError("Compressed release archive exceeds the configured size limit.")
    manifest = validate_manifest(archive_path, manifest_path)
    preflight_members, preflight_expanded = preflight_tar_gzip(archive_path)

    names: dict[str, tarfile.TarInfo] = {}
    regular_files: set[str] = set()
    expanded_size = 0
    member_count = 0

    try:
        archive = tarfile.open(archive_path, mode="r:gz")
    except (OSError, tarfile.TarError) as exc:
        raise ReleaseError("Release archive is not a readable gzip tar file.") from exc

    with archive:
        try:
            for member in archive:
                member_count += 1
                if member_count > MAX_MEMBERS:
                    raise ReleaseError("Release archive contains too many members.")

                name = normalize_member_name(member.name, member.isdir())
                validate_member_path(name)
                if name in names:
                    raise ReleaseError(f"Duplicate normalized archive path: {name!r}")
                names[name] = member

                if not (member.isdir() or member.isfile()):
                    raise ReleaseError(f"Links and special files are forbidden: {name!r}")
                if name in ALLOWED_HIDDEN_PATHS and not member.isfile():
                    raise ReleaseError(f"Allowed public dotfiles must be regular files: {name!r}")
                if member.uid != 0 or member.gid != 0:
                    raise ReleaseError(f"Archive entry is not normalized to root ownership: {name!r}")
                expected_mode = 0o750 if member.isdir() else 0o640
                if member.mode != expected_mode:
                    raise ReleaseError(f"Archive entry has unexpected permissions: {name!r}")

                if member.isfile():
                    if member.size < 0:
                        raise ReleaseError(f"Archive entry has invalid size: {name!r}")
                    expanded_size += member.size
                    if expanded_size > MAX_EXPANDED_BYTES:
                        raise ReleaseError("Expanded release archive exceeds the configured size limit.")
                    regular_files.add(name)
                elif member.size != 0:
                    raise ReleaseError(f"Archive directory declares unexpected content size: {name!r}")
        except (tarfile.TarError, OSError) as exc:
            raise ReleaseError("Unable to read every archive member safely.") from exc

        missing = sorted(path for path in REQUIRED_FILES if path not in regular_files)
        if missing:
            raise ReleaseError("Release archive is missing required files: " + ", ".join(missing))
        if "database/migrations" not in names or not names["database/migrations"].isdir():
            raise ReleaseError("Release archive is missing the Laravel migrations directory.")

        composer_bytes = read_regular_member(archive, names["composer.lock"], 20 * 1024 * 1024)
        npm_bytes = read_regular_member(archive, names["package-lock.json"], 20 * 1024 * 1024)
        if hashlib.sha256(composer_bytes).hexdigest() != manifest["composer_lock_sha256"]:
            raise ReleaseError("Composer lock digest does not match the release manifest.")
        if hashlib.sha256(npm_bytes).hexdigest() != manifest["npm_lock_sha256"]:
            raise ReleaseError("npm lock digest does not match the release manifest.")

        vite_member = names["public/build/manifest.json"]
        vite_bytes = read_regular_member(archive, vite_member, MAX_MANIFEST_BYTES)
        validate_vite_manifest(vite_bytes, regular_files)

    if member_count != preflight_members or expanded_size != preflight_expanded:
        raise ReleaseError("Tar preflight and archive parser disagree about archive contents.")

    return {
        "release_id": manifest["release_id"],
        "commit_sha": manifest["commit_sha"],
        "member_count": member_count,
        "expanded_bytes": expanded_size,
        "sha256": manifest["archive"]["sha256"],
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("archive", type=Path)
    parser.add_argument("--manifest", required=True, type=Path)
    args = parser.parse_args()
    try:
        result = validate_archive(args.archive, args.manifest)
    except (ReleaseError, OSError) as exc:
        parser.exit(1, f"release validation failed: {exc}\n")
    print(json.dumps({"status": "valid", **result}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
