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
    """Verify it's safe to push: no uncommitted modifications to tracked files,
    exactly one commit ahead of base, commit message references the item id.

    Untracked files (`??` in porcelain) are ignored — `git push` doesn't care
    about them, and the orchestrator's own state files (`.audit-work/...`)
    legitimately appear as untracked during a run. We only reject when there
    are MODIFIED tracked files that should have been committed.

    Returns PrePushResult.ok=True on success, with .commit_sha set.
    """
    status = _run(repo, "status", "--porcelain")
    # Filter porcelain lines: only keep modifications to tracked files
    # (status codes M, A, D, R, C, U). Untracked (??) and ignored (!!) are OK.
    dirty_tracked = [
        line for line in status.splitlines()
        if line and not line.startswith("??") and not line.startswith("!!")
    ]
    if dirty_tracked:
        return PrePushResult(
            ok=False,
            reason="uncommitted modifications to tracked files:\n" + "\n".join(dirty_tracked),
        )

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


def squash_to_single_commit(
    repo: Path, *, base_ref: str, item_id: str,
) -> str | None:
    """Collapse multiple commits ahead of base_ref into one atomic commit.

    The orchestrator's contract is one item = one commit (so revert maps 1:1
    to an audit ledger entry). Agents sometimes produce multiple commits
    (e.g. one per sub-concern in a bundle); this is a backstop that runs
    BEFORE pre_push_check so well-meaning multi-commit sessions don't get
    rejected and lose their work.

    Returns the new commit SHA if a squash happened, or None if there were
    fewer than 2 commits ahead (nothing to do). Raises RuntimeError on git
    failure — the caller treats that as best-effort and lets pre_push_check
    surface the underlying problem.

    The squashed commit's subject is the FIRST commit's subject (usually the
    most descriptive of the overall fix); the body lists every original
    subject as bullets so the audit trail is preserved. An `Item: <id>` line
    is appended if not already present, since pre_push_check requires it.
    """
    log = _run(repo, "log", "--oneline", f"{base_ref}..HEAD").strip()
    if not log:
        return None  # nothing ahead — pre_push_check will report this
    commits = log.splitlines()
    if len(commits) < 2:
        return None  # already a single commit — no-op

    # Capture every commit's subject (oldest first) so the squash body
    # documents what was rolled together.
    subjects = _run(
        repo, "log", "--reverse", "--pretty=%s", f"{base_ref}..HEAD",
    ).strip().splitlines()

    squash_subject = subjects[0]
    body = (
        f"Squashed {len(subjects)} commits during {item_id}:\n\n"
        + "\n".join(f"- {s}" for s in subjects)
    )
    # Always append the explicit `Item:` line — pre_push_check uses substring
    # match so it's not strictly required when the id appears in the body, but
    # an explicit trailer keeps the audit trail uniform across squashed and
    # non-squashed commits.
    msg = f"{squash_subject}\n\n{body}\n\nItem: {item_id}"

    # Soft reset keeps the working tree + index intact; just rewinds HEAD.
    _run(repo, "reset", "--soft", base_ref)
    _run(repo, "commit", "-q", "-m", msg)

    return _run(repo, "rev-parse", "HEAD").strip()


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
