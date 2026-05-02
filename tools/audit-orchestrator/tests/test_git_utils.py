"""Git utility tests using a real temp git repo."""
import pytest
import subprocess
from pathlib import Path
from audit_orchestrator.git_utils import pre_push_check, PrePushResult


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
