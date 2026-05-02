"""Git operations: pre-push safety, push, markdown checkbox tick."""
from __future__ import annotations
import subprocess
import re
from dataclasses import dataclass
from pathlib import Path


@dataclass
class PrePushResult:
    ok: bool
    reason: str = ""
    commit_sha: str | None = None


def _run(repo: Path, *args: str) -> str:
    proc = subprocess.run(
        ["git", *args], cwd=repo, capture_output=True, text=True,
    )
    if proc.returncode != 0:
        raise RuntimeError(f"git {' '.join(args)} failed: {proc.stderr.strip()}")
    return proc.stdout


def pre_push_check(repo: Path, *, item_id: str, base_ref: str = "origin/development-v2") -> PrePushResult:
    """Verify it's safe to push: clean tree, one commit ahead, commit references item.

    Returns PrePushResult.ok=True on success, with .commit_sha set.
    """
    status = _run(repo, "status", "--porcelain")
    if status.strip():
        return PrePushResult(ok=False, reason=f"working tree dirty / uncommitted changes:\n{status}")

    log = _run(repo, "log", "--oneline", f"{base_ref}..HEAD").strip()
    if not log:
        return PrePushResult(ok=False, reason="no new commits ahead of base ref")
    commits = log.splitlines()
    if len(commits) != 1:
        return PrePushResult(ok=False, reason=f"expected exactly 1 commit ahead, found {len(commits)}")

    sha = commits[0].split()[0]

    msg = _run(repo, "log", "-1", "--pretty=%B", sha).strip()
    if item_id not in msg:
        return PrePushResult(
            ok=False,
            reason=f"commit message for {sha} does not contain item id {item_id}",
        )

    return PrePushResult(ok=True, commit_sha=sha)


def push_to_remote(repo: Path, *, branch: str = "development-v2") -> None:
    """Push the named branch to origin. Raises on failure."""
    _run(repo, "push", "origin", branch)


def discard_working_changes(repo: Path) -> None:
    """Reset working tree to HEAD (used after a failing fix attempt)."""
    _run(repo, "checkout", "--", ".")
    _run(repo, "clean", "-fd")


def tick_checkbox_for_item(audit_file: Path, item_id: str) -> bool:
    """Flip `- [ ] **<item_id>**` to `- [x] **<item_id>**` in the source markdown.

    Returns True if a flip happened, False if no matching unchecked item was found.
    """
    text = audit_file.read_text(encoding="utf-8")
    pattern = re.compile(
        r"^(- \[)( )(\] \*\*" + re.escape(item_id) + r"\*\*)",
        flags=re.MULTILINE,
    )
    new_text, n = pattern.subn(r"\1x\3", text, count=1)
    if n == 0:
        return False
    audit_file.write_text(new_text, encoding="utf-8")
    return True
