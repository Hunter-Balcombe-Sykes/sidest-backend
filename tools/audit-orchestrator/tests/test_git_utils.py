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


def test_pre_push_fails_when_working_tree_dirty(repo: Path):
    (repo / "fix.txt").write_text("fixed")
    _git(repo, "add", ".")
    _git(repo, "commit", "-q", "-m", "fix(scope): thing\n\nItem: B5")
    (repo / "uncommitted.txt").write_text("oops")
    _git(repo, "add", "uncommitted.txt")
    result = pre_push_check(repo, item_id="B5", base_ref="origin/development-v2")
    assert not result.ok
    assert "dirty" in result.reason.lower() or "uncommitted" in result.reason.lower()


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
