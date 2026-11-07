#!/usr/bin/env python3
"""Build a release archive only from an explicitly selected clean commit/tag."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import subprocess
import tarfile
import tempfile
from datetime import datetime, timezone
from pathlib import Path

from scripts.release.common import ReleaseError, git, isolated_git_environment, require_clean_checkout, resolve_release_ref
from scripts.release.validate_artifact import validate_archive


ROOT = Path(__file__).resolve().parents[2]
PROJECT_DIRS = ("app", "bootstrap", "config", "database", "lang", "public", "resources", "routes")
ROOT_FILES = ("artisan", "composer.json", "composer.lock", "package.json", "package-lock.json", "tsconfig.json", "vite.config.ts")
BUILD_ONLY_FILES = ("tsconfig.json", "vite.config.ts")
MAX_OUTPUT_ARCHIVE_BYTES = 512 * 1024 * 1024


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def run(command: list[str], *, cwd: Path, env: dict[str, str]) -> None:
    print("+ " + " ".join(command), flush=True)
    subprocess.run(command, cwd=cwd, env=env, check=True)


def copy_committed_project(source: Path, destination: Path) -> None:
    destination.mkdir(parents=True, exist_ok=False)
    for name in ROOT_FILES:
        source_file = source / name
        if not source_file.is_file() or source_file.is_symlink():
            raise ReleaseError(f"Required committed project file is missing or unsafe: {name}")
        shutil.copy2(source_file, destination / name)
    for name in PROJECT_DIRS:
        source_dir = source / name
        if not source_dir.is_dir() or source_dir.is_symlink():
            raise ReleaseError(f"Required committed project directory is missing or unsafe: {name}")
        shutil.copytree(source_dir, destination / name, symlinks=True)


def reject_lfs_pointers(root: Path) -> None:
    pointer_version = b"version https://git-lfs.github.com/spec/v1"
    for path in root.rglob("*"):
        if path.is_symlink() or not path.is_file():
            continue
        try:
            with path.open("rb") as stream:
                first_line = stream.readline(256).rstrip(b"\r\n")
        except OSError as exc:
            raise ReleaseError(f"Unable to inspect committed file for Git LFS content: {path.relative_to(root)}") from exc
        if first_line == pointer_version:
            raise ReleaseError(f"Selected commit contains an unhydrated Git LFS pointer: {path.relative_to(root)}")


def reject_submodules(repo: Path, commit: str) -> None:
    entries = git(repo, "ls-tree", "-r", "-z", commit).stdout.split("\0")
    if any(record.split("\t", 1)[0].startswith("160000 commit ") for record in entries if record):
        raise ReleaseError("Selected commit contains Git submodules; packaging them is not supported by this tool.")


def scrub_build_tree(root: Path) -> None:
    for name in ("node_modules", "storage", "tests", "docs", "scripts", ".git"):
        path = root / name
        if path.is_symlink():
            path.unlink()
        elif path.exists():
            shutil.rmtree(path)

    for name in BUILD_ONLY_FILES:
        (root / name).unlink(missing_ok=True)

    forbidden_suffixes = (".log", ".sql", ".sqlite", ".sqlite3", ".db", ".dump", ".bak", ".backup", ".pem", ".key", ".p12", ".pfx", ".crt", ".cer", ".p8", ".p7b", ".p7c", ".jks", ".keystore", ".kdbx", ".gpg", ".tmp", ".temp", ".tar", ".tar.gz", ".tar.bz2", ".tar.xz", ".tgz", ".tbz", ".tbz2", ".txz", ".7z", ".rar", ".zip", ".gz", ".bz2", ".xz", ".zst")
    forbidden_filenames = {"auth.json", "credentials", "credentials.json", "service-account.json", "id_rsa", "id_ed25519", "id_ecdsa", "known_hosts", "authorized_keys"}
    for path in list(root.rglob("*")):
        if path.is_symlink():
            if path.relative_to(root).as_posix() in {"public/hot", "public/storage"}:
                path.unlink()
                continue
            raise ReleaseError(f"Build tree contains a symlink and cannot be packaged: {path.relative_to(root)}")
        relative = path.relative_to(root).as_posix()
        if path.name.startswith(".") and relative not in {"public/.htaccess", "public/.user.ini"}:
            if path.is_dir():
                shutil.rmtree(path)
            elif path.exists():
                path.unlink()
        elif path.is_file() and path.name.lower().startswith(".env"):
            path.unlink()
        elif path.is_file() and (path.name.lower() in forbidden_filenames or path.name.lower().endswith(forbidden_suffixes) or "backup" in path.name.lower() or path.name in {".DS_Store", "phpunit.xml.bak"}):
            path.unlink()
        elif path.is_dir() and path.name.lower() == "storage":
            shutil.rmtree(path)

    for path in (root / "public/hot", root / "public/storage", root / "bootstrap/cache"):
        if path.is_symlink():
            path.unlink()
        elif path.is_dir():
            shutil.rmtree(path)
        elif path.exists():
            path.unlink()

    cache = root / "bootstrap/cache"
    cache.mkdir(parents=True, exist_ok=True)
    cache.rmdir()


def iter_archive_paths(root: Path):
    for path in sorted(root.rglob("*"), key=lambda item: item.relative_to(root).as_posix()):
        relative = path.relative_to(root).as_posix()
        if path.is_symlink():
            raise ReleaseError(f"Refusing to package a symlink: {relative}")
        if path.is_file() or path.is_dir():
            if relative.split("/")[-1].startswith(".") and relative not in {"public/.htaccess", "public/.user.ini"}:
                raise ReleaseError(f"Unexpected hidden file in build tree: {relative}")
            yield path, relative
        else:
            raise ReleaseError(f"Refusing to package a special file: {relative}")


def write_archive(root: Path, archive_path: Path) -> None:
    with tarfile.open(archive_path, mode="w:gz", format=tarfile.USTAR_FORMAT) as archive:
        for path, relative in iter_archive_paths(root):
            info = archive.gettarinfo(str(path), arcname=relative)
            info.uid = info.gid = 0
            info.uname = info.gname = "root"
            info.mtime = 0
            info.mode = 0o750 if path.is_dir() else 0o640
            if path.is_dir():
                archive.addfile(info)
            else:
                with path.open("rb") as stream:
                    archive.addfile(info, stream)


def build_environment(temp_root: Path) -> dict[str, str]:
    # Deliberately do not inherit application, database, mail, cloud, or Vite secrets.
    env = {
        "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
        "LANG": "C",
        "LC_ALL": "C",
        "HOME": str(temp_root / "home"),
        "COMPOSER_HOME": str(temp_root / "composer-home"),
        "COMPOSER_CACHE_DIR": str(temp_root / "composer-cache"),
        "NPM_CONFIG_CACHE": str(temp_root / "npm-cache"),
        "APP_ENV": "production",
        "APP_DEBUG": "false",
        "APP_NAME": "Gjej portier",
        "APP_URL": "http://127.0.0.1",
        "APP_KEY": "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
        "DB_CONNECTION": "sqlite",
        "DB_DATABASE": ":memory:",
        "CACHE_STORE": "array",
        "SESSION_DRIVER": "array",
        "QUEUE_CONNECTION": "sync",
        "MAIL_MAILER": "array",
        "VITE_APP_NAME": "Gjej portier",
    }
    for key in ("SYSTEMROOT", "WINDIR", "TMP", "TEMP", "TMPDIR"):
        if key in os.environ:
            env[key] = os.environ[key]
    for directory in ("home", "composer-home", "composer-cache", "npm-cache"):
        (temp_root / directory).mkdir(parents=True, exist_ok=True)
    return env


def package(ref: str, output_dir: Path) -> tuple[Path, Path]:
    require_clean_checkout(ROOT)
    selection = resolve_release_ref(ROOT, ref)
    reject_submodules(ROOT, selection.commit)
    output_dir = output_dir.expanduser().resolve()
    if output_dir == ROOT or ROOT in output_dir.parents:
        raise ReleaseError("Output directory must be outside the Git checkout.")
    output_dir.mkdir(parents=True, exist_ok=True)
    if not output_dir.is_dir():
        raise ReleaseError("Output path must be a directory.")
    if any(output_dir.iterdir()):
        raise ReleaseError("Output directory must be empty; refusing to overwrite existing files.")

    timestamp = datetime.now(timezone.utc).replace(microsecond=0)
    timestamp_text = timestamp.isoformat().replace("+00:00", "Z")
    release_id = f"{selection.commit[:12]}-{timestamp:%Y%m%dT%H%M%SZ}"
    archive_name = f"gjej-{release_id}.tar.gz"
    with tempfile.TemporaryDirectory(prefix="gjej-release-") as work_name:
        work = Path(work_name)
        checkout = work / "checkout"
        tree = work / "tree"
        subprocess.run(
            ["git", "clone", "--local", "--no-hardlinks", "--no-checkout", str(ROOT), str(checkout)],
            check=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            env=isolated_git_environment(),
        )
        git(checkout, "checkout", "--detach", selection.commit)
        copy_committed_project(checkout, tree)
        reject_lfs_pointers(tree)
        env = build_environment(work)

        for directory in ("bootstrap/cache", "storage/framework/cache/data", "storage/framework/sessions", "storage/framework/views", "storage/logs"):
            (tree / directory).mkdir(parents=True, exist_ok=True)

        run(
            ["composer", "install", "--no-dev", "--prefer-dist", "--no-interaction", "--no-progress", "--optimize-autoloader", "--no-scripts", "--no-plugins"],
            cwd=tree,
            env=env,
        )
        run(["composer", "check-platform-reqs", "--no-dev", "--no-interaction"], cwd=tree, env=env)
        run(["php", "artisan", "package:discover", "--ansi"], cwd=tree, env=env)
        run(["npm", "ci", "--no-audit", "--no-fund"], cwd=tree, env=env)
        run(["npm", "run", "build"], cwd=tree, env=env)
        scrub_build_tree(tree)

        archive_path = output_dir / archive_name
        manifest_path = output_dir / f"{archive_name}.manifest.json"
        with tempfile.TemporaryDirectory(prefix=".gjej-staging-", dir=output_dir) as stage_name:
            stage = Path(stage_name)
            staged_archive = stage / archive_name
            staged_manifest = stage / f"{archive_name}.manifest.json"
            write_archive(tree, staged_archive)
            if staged_archive.stat().st_size > MAX_OUTPUT_ARCHIVE_BYTES:
                raise ReleaseError("Built archive exceeds the configured compressed size limit.")
            manifest = {
                "schema_version": 1,
                "release_id": release_id,
                "git_ref": ref,
                "commit_sha": selection.commit,
                "build_timestamp_utc": timestamp_text,
                "composer_lock_sha256": sha256_file(tree / "composer.lock"),
                "npm_lock_sha256": sha256_file(tree / "package-lock.json"),
                "archive": {
                    "file": archive_name,
                    "size_bytes": staged_archive.stat().st_size,
                    "sha256": sha256_file(staged_archive),
                },
            }
            staged_manifest.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
            validate_archive(staged_archive, staged_manifest)
            other_output = [path for path in output_dir.iterdir() if path != stage]
            if other_output or archive_path.exists() or manifest_path.exists():
                raise ReleaseError("Output directory changed during packaging; refusing to publish artifacts.")
            os.link(staged_archive, archive_path)
            try:
                os.link(staged_manifest, manifest_path)
            except Exception:
                if archive_path.exists() and os.path.samefile(archive_path, staged_archive):
                    archive_path.unlink(missing_ok=True)
                raise
    return archive_path, manifest_path


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--ref", required=True, help="Explicit commit SHA or existing tag; HEAD/branches are refused")
    parser.add_argument("--output-dir", required=True, type=Path, help="Empty directory outside the checkout")
    args = parser.parse_args()
    try:
        archive, manifest = package(args.ref, args.output_dir)
    except (ReleaseError, OSError, ValueError, subprocess.CalledProcessError) as exc:
        detail = str(exc)
        if isinstance(exc, subprocess.CalledProcessError):
            detail = f"Build command failed with exit status {exc.returncode}: {exc.cmd[0]}"
        parser.exit(1, f"release packaging failed: {detail}\n")
    print(json.dumps({"archive": str(archive), "manifest": str(manifest)}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
