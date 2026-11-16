from __future__ import annotations

import os
import re
import subprocess
from dataclasses import dataclass
from pathlib import Path


class ReleaseError(RuntimeError):
    """A release source or artifact failed a fail-closed check."""


@dataclass(frozen=True)
class GitSelection:
    ref: str
    commit: str


def isolated_git_environment() -> dict[str, str]:
    """Prevent user/system Git config and filters from changing the selected tree."""
    env = {key: value for key, value in os.environ.items() if not key.startswith("GIT_")}
    env["GIT_CONFIG_NOSYSTEM"] = "1"
    env["GIT_CONFIG_GLOBAL"] = os.devnull
    return env


def git(repo: Path, *args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        ["git", "-C", str(repo), *args],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        env=isolated_git_environment(),
    )
    if check and result.returncode != 0:
        detail = result.stderr.strip() or "git command failed"
        raise ReleaseError(detail)
    return result


def require_clean_checkout(repo: Path) -> None:
    root = Path(git(repo, "rev-parse", "--show-toplevel").stdout.strip()).resolve()
    if root != repo.resolve():
        raise ReleaseError("Run release tooling from the Git checkout root.")

    status = git(
        repo,
        "status",
        "--porcelain=v1",
        "--untracked-files=all",
        "--ignore-submodules=none",
    ).stdout
    if status:
        raise ReleaseError("Refusing release packaging: the selected source checkout is dirty.")


def resolve_release_ref(repo: Path, ref: str) -> GitSelection:
    if not ref or ref == "HEAD" or ref.startswith("-") or any(ord(c) < 32 for c in ref):
        raise ReleaseError("Select an explicit commit SHA or tag; HEAD is not accepted.")

    is_sha = bool(re.fullmatch(r"(?:[0-9a-fA-F]{40}|[0-9a-fA-F]{64})", ref))
    tag_ref = ref if ref.startswith("refs/tags/") else f"refs/tags/{ref}"
    tag_exists = git(repo, "show-ref", "--verify", "--quiet", tag_ref, check=False).returncode == 0
    if not is_sha and not tag_exists:
        raise ReleaseError("The selected ref must resolve to an existing commit or tag.")

    expression = f"{ref}^{{commit}}"
    result = git(repo, "rev-parse", "--verify", "--end-of-options", expression, check=False)
    if result.returncode != 0:
        raise ReleaseError("The selected commit or tag does not resolve to a commit.")
    commit = result.stdout.strip().lower()
    if not re.fullmatch(r"[0-9a-f]{40,64}", commit):
        raise ReleaseError("Git returned an invalid commit identifier.")
    if git(repo, "cat-file", "-t", commit).stdout.strip() != "commit":
        raise ReleaseError("The selected object is not a commit.")

    return GitSelection(ref=ref, commit=commit)
