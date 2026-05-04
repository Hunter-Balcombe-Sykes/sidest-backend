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


def head_sha(repo: Path) -> str:
    """Current HEAD commit sha. Used by the runner to capture a 'before-spawn'
    anchor that lets us hard-reset on push failure (Fix B in the recovery
    plan) without losing track of where the branch was."""
    return _run(repo, "rev-parse", "HEAD").strip()


def _path_from_porcelain_line(line: str) -> str:
    """`git status --porcelain` formats each row as `XY <path>` (or with
    a `->` for renames). Pull the path out for prefix matching."""
    rest = line[3:] if len(line) > 3 else ""
    if " -> " in rest:
        rest = rest.split(" -> ", 1)[1]
    return rest.strip()


def _is_ignored(path: str, prefixes: list[str]) -> bool:
    """True if path lives under any of the prefixes."""
    return any(path.startswith(p) for p in (prefixes or []))


def dirty_tracked_files(repo: Path, *, ignore_prefixes: list[str] | None = None) -> list[str]:
    """List modified tracked files, with paths under `ignore_prefixes`
    filtered out. Used by the runner's working-tree isolation guard so a
    user editing the orchestrator's own source doesn't block agent runs."""
    status = _run(repo, "status", "--porcelain")
    out = []
    for line in status.splitlines():
        if not line or line.startswith("??") or line.startswith("!!"):
            continue
        path = _path_from_porcelain_line(line)
        if not _is_ignored(path, ignore_prefixes or []):
            out.append(line)
    return out


def pre_push_check(
    repo: Path, *, item_id: str,
    base_ref: str = "origin/development-v2",
    ignore_prefixes: list[str] | None = None,
) -> PrePushResult:
    """Verify it's safe to push: no uncommitted modifications to tracked files,
    exactly one commit ahead of base, commit message references the item id.

    Untracked files (`??` in porcelain) are ignored — `git push` doesn't care
    about them, and the orchestrator's own state files (`.audit-work/...`)
    legitimately appear as untracked during a run. We only reject when there
    are MODIFIED tracked files that should have been committed.

    `ignore_prefixes` filters out paths the user might be editing live (e.g.
    the orchestrator's own source under `tools/audit-orchestrator/`) so those
    in-progress edits don't block agent pushes.

    Returns PrePushResult.ok=True on success, with .commit_sha set.
    """
    dirty = dirty_tracked_files(repo, ignore_prefixes=ignore_prefixes)
    if dirty:
        return PrePushResult(
            ok=False,
            reason="uncommitted modifications to tracked files:\n" + "\n".join(dirty),
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
    """Fetch + rebase the local branch onto origin, then push.

    The rebase step is what prevents the cascading frankenstein-commit failure
    mode: if origin moved on (because the user pushed manually, another
    Claude session pushed, or CI made a commit), a bare `git push` would be
    rejected non-fast-forward and the runner had no recovery path. With
    rebase, the agent's local commit replays cleanly on top of the new
    remote tip and pushes normally.

    On rebase conflict — i.e. the agent's commit overlaps with new remote
    work — abort cleanly to restore pre-rebase state and raise. Caller
    (runner._handle_exit) treats this as a hard block and rolls back the
    agent's commit so it doesn't pollute the next item's squash.
    """
    # Fetch first so origin/<branch> reflects current reality, not a stale
    # local view from whenever it was last fetched.
    _run(repo, "fetch", "origin", branch)

    # Rebase onto fetched origin. If our local branch was already in sync,
    # this is a no-op. If origin moved, we replay our commit on top.
    rebase_proc = subprocess.run(
        ["git", "rebase", f"origin/{branch}"],
        cwd=repo, capture_output=True, text=True,
    )
    if rebase_proc.returncode != 0:
        # Conflict during replay. Abort to restore pre-rebase HEAD and
        # surface the failure. The runner's caller will reset the local
        # commit and mark the item blocked.
        subprocess.run(
            ["git", "rebase", "--abort"],
            cwd=repo, capture_output=True, text=True,
        )
        stderr = (rebase_proc.stderr or "")[:300].strip()
        raise RuntimeError(
            f"rebase onto origin/{branch} failed (conflicts with remote): {stderr}"
        )

    # Now safe to push — local is fast-forward of origin.
    _run(repo, "push", "origin", branch)


def discard_working_changes(
    repo: Path, *, ignore_prefixes: list[str] | None = None,
) -> None:
    """Reset modified tracked files to HEAD, optionally skipping paths under
    `ignore_prefixes`.

    Without the filter, the old `git checkout -- .` reverted EVERY modified
    tracked file — including a developer's in-progress edits to the
    orchestrator's own source code. That was the root cause of the "your
    changes keep disappearing" pattern: every test-failed item run wiped
    pending edits as collateral damage.

    With the filter, we list only the modified tracked files outside the
    ignored prefixes and revert just those. Untracked files are no longer
    auto-cleaned — `git clean -fd` was blowing away `.audit-work/`
    artifacts (questions, completion records) that are legitimately
    untracked during a run.
    """
    ignore_prefixes = ignore_prefixes or []
    status = _run(repo, "status", "--porcelain")
    to_revert: list[str] = []
    for line in status.splitlines():
        if not line or line.startswith("??") or line.startswith("!!"):
            continue
        path = _path_from_porcelain_line(line)
        if not _is_ignored(path, ignore_prefixes):
            to_revert.append(path)

    if to_revert:
        # Use checkout HEAD -- <files> rather than `git checkout -- <files>`
        # so we explicitly target HEAD (defensive against detached-HEAD or
        # mid-merge states).
        _run(repo, "checkout", "HEAD", "--", *to_revert)


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
