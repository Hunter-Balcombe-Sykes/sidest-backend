"""Git utility tests using a real temp git repo."""
import pytest
import subprocess
from pathlib import Path
from audit_orchestrator.git_utils import (
    pre_push_check, PrePushResult, squash_to_single_commit,
    head_sha, dirty_tracked_files, discard_working_changes, push_to_remote,
)


def _git(repo: Path, *args: str) -> str:
    return subprocess.run(
        ["git", *args], cwd=repo, check=True, capture_output=True, text=True,
    ).stdout.strip()


@pytest.fixture
def repo(tmp_path: Path) -> Path:
    """Initialize a git repo with a remote and an initial commit on development-v2."""
    r = tmp_path / "repo"
    r.mkdir()
    _git(r, "init", "-q")
    _git(r, "config", "user.email", "test@test")
    _git(r, "config", "user.name", "Test")
    (r / "README.md").write_text("hi")
    _git(r, "add", ".")
    _git(r, "commit", "-q", "-m", "initial")
    _git(r, "branch", "-M", "development-v2")
    remote = tmp_path / "remote.git"
    remote.mkdir()
    subprocess.run(["git", "init", "-q", "--bare"], cwd=remote, check=True)
    _git(r, "remote", "add", "origin", str(remote))
    _git(r, "push", "-q", "-u", "origin", "development-v2")
    return r


def test_pre_push_passes_for_one_commit_with_item_id(repo: Path):
    (repo / "fix.txt").write_text("fixed")
    _git(repo, "add", ".")
    _git(repo, "commit", "-q", "-m", "fix(scope): thing\n\nItem: B5\n\nDetails")
    result = pre_push_check(repo, item_id="B5", base_ref="origin/development-v2")
    assert result.ok, result.reason


def test_pre_push_fails_when_tracked_files_modified_uncommitted(repo: Path):
    """Modifications to tracked files (staged but not committed, or staged for
    addition) must block push."""
    (repo / "fix.txt").write_text("fixed")
    _git(repo, "add", ".")
    _git(repo, "commit", "-q", "-m", "fix(scope): thing\n\nItem: B5")
    # Stage a new file but don't commit it — this IS a real "uncommitted change"
    (repo / "uncommitted.txt").write_text("oops")
    _git(repo, "add", "uncommitted.txt")
    result = pre_push_check(repo, item_id="B5", base_ref="origin/development-v2")
    assert not result.ok
    assert "uncommitted" in result.reason.lower()


def test_pre_push_passes_when_only_untracked_files_present(repo: Path):
    """Untracked files (?? in porcelain) are OK — git push doesn't care.

    The orchestrator's own state files (.audit-work/...) and any other
    untracked artifacts left by Claude should not block the push.
    """
    (repo / "fix.txt").write_text("fixed")
    _git(repo, "add", ".")
    _git(repo, "commit", "-q", "-m", "fix(scope): thing\n\nItem: B5")
    # Untracked files everywhere — used to block but no longer should
    (repo / ".audit-work").mkdir(exist_ok=True)
    (repo / ".audit-work" / "completed").mkdir(exist_ok=True)
    (repo / ".audit-work" / "completed" / "B5.md").write_text("# B5 record")
    (repo / "scratch.txt").write_text("temp")
    result = pre_push_check(repo, item_id="B5", base_ref="origin/development-v2")
    assert result.ok, f"untracked files should not block push: {result.reason}"


def test_pre_push_fails_when_two_commits_ahead(repo: Path):
    for i in range(2):
        (repo / f"fix{i}.txt").write_text(str(i))
        _git(repo, "add", ".")
        _git(repo, "commit", "-q", "-m", f"fix: {i}\n\nItem: B5")
    result = pre_push_check(repo, item_id="B5", base_ref="origin/development-v2")
    assert not result.ok
    assert "ahead" in result.reason.lower()


def test_pre_push_fails_when_commit_missing_item_id(repo: Path):
    (repo / "fix.txt").write_text("fixed")
    _git(repo, "add", ".")
    _git(repo, "commit", "-q", "-m", "fix(scope): thing without item id")
    result = pre_push_check(repo, item_id="B5", base_ref="origin/development-v2")
    assert not result.ok
    assert "item id" in result.reason.lower()


def test_squash_collapses_three_commits_into_one_with_item_id(repo: Path):
    """Three commits ahead → one commit ahead. Squash body lists each subject."""
    for i in range(3):
        (repo / f"f{i}.txt").write_text(str(i))
        _git(repo, "add", ".")
        _git(repo, "commit", "-q", "-m", f"fix(scope): part {i}")

    new_sha = squash_to_single_commit(repo, base_ref="origin/development-v2", item_id="B16")
    assert new_sha is not None

    # Now exactly one commit ahead, and pre_push_check accepts it
    log = _git(repo, "log", "--oneline", "origin/development-v2..HEAD")
    assert len(log.splitlines()) == 1

    result = pre_push_check(repo, item_id="B16", base_ref="origin/development-v2")
    assert result.ok, result.reason

    # Body documents what was rolled together (oldest first)
    msg = _git(repo, "log", "-1", "--pretty=%B")
    assert "fix(scope): part 0" in msg
    assert "fix(scope): part 1" in msg
    assert "fix(scope): part 2" in msg
    assert "Item: B16" in msg


def test_squash_no_op_when_already_single_commit(repo: Path):
    """One commit ahead → returns None, doesn't rewrite history."""
    (repo / "single.txt").write_text("only")
    _git(repo, "add", ".")
    _git(repo, "commit", "-q", "-m", "fix: single\n\nItem: B5")
    sha_before = _git(repo, "rev-parse", "HEAD")

    result = squash_to_single_commit(repo, base_ref="origin/development-v2", item_id="B5")
    assert result is None
    assert _git(repo, "rev-parse", "HEAD") == sha_before


def test_squash_no_op_when_zero_commits_ahead(repo: Path):
    """Nothing to squash → returns None, doesn't error."""
    result = squash_to_single_commit(repo, base_ref="origin/development-v2", item_id="B5")
    assert result is None


def test_squash_appends_item_id_when_missing_from_subjects(repo: Path):
    """If neither original commit subject mentions the item id, the squash
    appends an `Item:` line so pre_push_check passes."""
    for i in range(2):
        (repo / f"x{i}.txt").write_text(str(i))
        _git(repo, "add", ".")
        _git(repo, "commit", "-q", "-m", f"chore: cleanup pass {i}")  # no Item: anywhere

    squash_to_single_commit(repo, base_ref="origin/development-v2", item_id="B99")
    msg = _git(repo, "log", "-1", "--pretty=%B")
    assert "Item: B99" in msg


def test_checkbox_tick_flips_only_named_item(tmp_path):
    from audit_orchestrator.git_utils import tick_checkbox_for_item
    md = tmp_path / "audit.md"
    md.write_text(
        "- [ ] **#A** · P0 — first\n"
        "- [ ] **#B** · P0 — second\n"
        "- [x] **#C** · P0 — already done\n"
    )

    flipped = tick_checkbox_for_item(md, "#B")
    assert flipped is True
    new_text = md.read_text()
    assert "- [x] **#B**" in new_text
    assert "- [ ] **#A**" in new_text
    assert "- [x] **#C**" in new_text


def test_checkbox_tick_returns_false_for_already_done(tmp_path):
    from audit_orchestrator.git_utils import tick_checkbox_for_item
    md = tmp_path / "audit.md"
    md.write_text("- [x] **#A** · P0 — done\n")
    assert tick_checkbox_for_item(md, "#A") is False


def test_checkbox_tick_returns_false_for_missing_item(tmp_path):
    from audit_orchestrator.git_utils import tick_checkbox_for_item
    md = tmp_path / "audit.md"
    md.write_text("- [ ] **#A** · P0 — exists\n")
    assert tick_checkbox_for_item(md, "#NOPE") is False


# --- Fix C: ignore-prefix filtering --------------------------------------

def test_dirty_tracked_files_filters_ignored_prefix(repo: Path):
    """Files under ignore_prefixes shouldn't show up as 'dirty'."""
    (repo / "tools").mkdir()
    (repo / "tools" / "orch.py").write_text("hello")
    _git(repo, "add", ".")
    _git(repo, "commit", "-q", "-m", "init orch")

    # Modify both an ignored path and a non-ignored path
    (repo / "tools" / "orch.py").write_text("hello modified")
    (repo / "README.md").write_text("readme modified")

    all_dirty = dirty_tracked_files(repo)
    filtered = dirty_tracked_files(repo, ignore_prefixes=["tools/"])

    assert any("tools/orch.py" in line for line in all_dirty)
    assert any("README.md" in line for line in all_dirty)
    assert any("README.md" in line for line in filtered)
    assert not any("tools/orch.py" in line for line in filtered)


def test_pre_push_check_passes_when_only_dirty_files_are_ignored(repo: Path):
    """A commit landing while orchestrator-source edits are pending in
    the working tree must still pass pre_push_check — that's the bug
    that blocked #9-010 and B13 in the recovery scenario."""
    (repo / "tools" / "audit-orchestrator").mkdir(parents=True)
    (repo / "tools" / "audit-orchestrator" / "config.py").write_text("# orig")
    _git(repo, "add", ".")
    _git(repo, "commit", "-q", "-m", "track orch")
    _git(repo, "push", "-q", "origin", "development-v2")  # baseline so the fix below is "1 ahead"

    # Make a real fix commit
    (repo / "fix.txt").write_text("fixed")
    _git(repo, "add", "fix.txt")
    _git(repo, "commit", "-q", "-m", "fix: real work\n\nItem: B16")

    # Simulate developer-in-progress edits to orchestrator source
    (repo / "tools" / "audit-orchestrator" / "config.py").write_text("# edited")

    # Without ignore: blocked
    result_strict = pre_push_check(repo, item_id="B16", base_ref="origin/development-v2")
    assert not result_strict.ok
    assert "uncommitted modifications" in result_strict.reason

    # With ignore: passes
    result_filtered = pre_push_check(
        repo, item_id="B16", base_ref="origin/development-v2",
        ignore_prefixes=["tools/audit-orchestrator/"],
    )
    assert result_filtered.ok, result_filtered.reason


def test_discard_working_changes_preserves_ignored_files(repo: Path):
    """The previous `git checkout -- .` wiped EVERY modified tracked file,
    including dev-in-progress edits to the orchestrator's own source.
    Ignored prefixes must survive."""
    (repo / "tools").mkdir()
    (repo / "tools" / "orch.py").write_text("# original")
    (repo / "app.php").write_text("<?php // original")
    _git(repo, "add", ".")
    _git(repo, "commit", "-q", "-m", "init")

    # Modify both
    (repo / "tools" / "orch.py").write_text("# IN PROGRESS")
    (repo / "app.php").write_text("<?php // agent edited")

    discard_working_changes(repo, ignore_prefixes=["tools/"])

    # Agent's edit reverted, dev-in-progress preserved
    assert (repo / "app.php").read_text() == "<?php // original"
    assert (repo / "tools" / "orch.py").read_text() == "# IN PROGRESS"


# --- Fix A: push_to_remote rebases before pushing ------------------------

def _make_remote_advance(repo: Path, tmp_path: Path, label: str, file_name: str, contents: str) -> None:
    """Helper: simulate someone else pushing a commit to origin/development-v2
    while our local repo is unaware. Used by the push_to_remote tests."""
    remote_path = (repo.parent / "remote.git").resolve()
    other = tmp_path / f"other-clone-{label}"
    subprocess.run(["git", "clone", "-q", str(remote_path), str(other)],
                   check=True, capture_output=True)
    # Cloning a bare remote can leave us on a detached state or wrong branch
    # depending on git defaults — explicitly checkout development-v2.
    subprocess.run(["git", "checkout", "-q", "development-v2"],
                   cwd=other, check=True, capture_output=True)
    subprocess.run(["git", "config", "user.email", "x@x"], cwd=other, check=True, capture_output=True)
    subprocess.run(["git", "config", "user.name", "X"], cwd=other, check=True, capture_output=True)
    (other / file_name).write_text(contents)
    subprocess.run(["git", "add", "."], cwd=other, check=True, capture_output=True)
    subprocess.run(["git", "commit", "-q", "-m", f"manual: {label}"],
                   cwd=other, check=True, capture_output=True)
    subprocess.run(["git", "push", "-q", "origin", "development-v2"],
                   cwd=other, check=True, capture_output=True)


def test_push_to_remote_rebases_when_origin_moved(repo: Path, tmp_path: Path):
    """When origin has new commits we don't have locally, push_to_remote
    should fetch + rebase + push instead of failing non-fast-forward.
    This is what would have prevented the 5ba2c4b cascade."""
    _make_remote_advance(repo, tmp_path, "advance", "from-someone-else.txt", "manual fix")

    # Now make a non-conflicting commit locally
    (repo / "agent-fix.txt").write_text("agent did this")
    _git(repo, "add", "agent-fix.txt")
    _git(repo, "commit", "-q", "-m", "fix(agent): something\n\nItem: B16")

    # push_to_remote should fetch + rebase onto origin's new tip + push
    push_to_remote(repo, branch="development-v2")

    # Both commits now on origin
    log = _git(repo, "log", "--oneline", "origin/development-v2", "-5")
    assert "manual: advance" in log
    assert "fix(agent): something" in log


def test_push_to_remote_aborts_rebase_on_conflict(repo: Path, tmp_path: Path):
    """If our local commit conflicts with remote work, rebase should abort
    cleanly and raise — leaving the working tree as it was before the push
    attempt so the runner can roll back the agent's commit."""
    _make_remote_advance(repo, tmp_path, "conflict", "shared.txt", "MANUAL VERSION")

    # Local makes a conflicting edit on the same file
    (repo / "shared.txt").write_text("AGENT VERSION")
    _git(repo, "add", "shared.txt")
    _git(repo, "commit", "-q", "-m", "agent edit shared\n\nItem: B16")

    head_before = head_sha(repo)

    # Should raise — rebase conflict
    with pytest.raises(RuntimeError, match="rebase"):
        push_to_remote(repo, branch="development-v2")

    # And HEAD should be untouched (rebase --abort restored it)
    assert head_sha(repo) == head_before


def test_head_sha_returns_current_commit(repo: Path):
    sha = head_sha(repo)
    assert len(sha) == 40  # full sha
    expected = _git(repo, "rev-parse", "HEAD")
    assert sha == expected
