"""Runner state-machine tests using a mocked claude subprocess."""
import json
import subprocess
from pathlib import Path
from unittest.mock import patch, MagicMock
import pytest

from audit_orchestrator.runner import Runner, RunMode
from audit_orchestrator.state import StateManager
from audit_orchestrator.config import Config


def _mock_claude_exits_clean(stream_lines: list[str], exit_code: int = 0):
    """Build a MagicMock that simulates a claude subprocess emitting stream-json lines."""
    mock_proc = MagicMock()
    mock_proc.stdout = iter([line + "\n" for line in stream_lines])
    mock_proc.stderr = iter([])
    mock_proc.wait.return_value = exit_code
    mock_proc.returncode = exit_code
    return mock_proc


@pytest.fixture
def runner_setup(tmp_path):
    """Create a tmp .audit-work/ + state with one queued item.
    Repo root is tmp_path (NOT a real git repo) — git_utils calls are mocked
    in each test rather than running real git."""
    work = tmp_path / ".audit-work"
    work.mkdir()
    sm = StateManager(work / "state.json")
    state = sm.load()
    state.queue = ["#T-001"]
    state.items = {"#T-001": {"id": "#T-001", "title": "Test", "body_markdown": "<body>", "status": "pending", "source": "test.md"}}
    sm.save(state)
    config = Config()
    return Runner(work_dir=work, repo_root=tmp_path, config=config), sm


def _patch_git_clean():
    """Stack of patches that make git calls inside run_one no-op so the
    tests don't need a real git repo. Returns a list of context managers
    to use with `with contextlib.ExitStack`."""
    return [
        patch("audit_orchestrator.runner.dirty_tracked_files", return_value=[]),
        patch("audit_orchestrator.runner.head_sha", return_value="pre-spawn-sha"),
    ]


def test_runner_marks_done_on_clean_exit_with_passing_tests(runner_setup):
    runner, sm = runner_setup
    stream = [json.dumps({"type": "system", "subtype": "init", "session_id": "sid-1"})]

    with patch("subprocess.Popen", return_value=_mock_claude_exits_clean(stream)), \
         patch("audit_orchestrator.runner.dirty_tracked_files", return_value=[]), \
         patch("audit_orchestrator.runner.head_sha", return_value="pre-spawn-sha"), \
         patch("audit_orchestrator.runner.run_test_command", return_value=True), \
         patch("audit_orchestrator.runner.pre_push_check") as ppc, \
         patch("audit_orchestrator.runner.push_to_remote"), \
         patch("audit_orchestrator.runner.tick_checkbox_for_item", return_value=True), \
         patch.object(Runner, "_files_in_commit", return_value=["a.php", "b.php"]):
        ppc.return_value.ok = True
        ppc.return_value.commit_sha = "abc123"

        outcome = runner.run_one("#T-001", mode=RunMode.OVERNIGHT)

    assert outcome == "pushed"
    state = sm.load()
    assert state.items["#T-001"]["status"] == "done"

    # Completion record written
    completed_file = runner.completed_dir / "T-001.md"
    assert completed_file.exists()
    content = completed_file.read_text()
    assert "commit_sha: abc123" in content
    assert "## Plain English" in content
    assert "## Questions Asked" in content


def test_runner_marks_blocked_on_test_failure(runner_setup, tmp_path):
    runner, sm = runner_setup
    stream = [json.dumps({"type": "system", "subtype": "init", "session_id": "sid-1"})]

    with patch("subprocess.Popen", return_value=_mock_claude_exits_clean(stream)), \
         patch("audit_orchestrator.runner.dirty_tracked_files", return_value=[]), \
         patch("audit_orchestrator.runner.head_sha", return_value="pre-spawn-sha"), \
         patch("audit_orchestrator.runner.run_test_command", return_value=False), \
         patch("audit_orchestrator.runner.discard_working_changes"), \
         patch.object(Runner, "_save_blocked_artifacts"):
        outcome = runner.run_one("#T-001", mode=RunMode.OVERNIGHT)

    assert outcome == "blocked"
    state = sm.load()
    assert state.items["#T-001"]["status"] == "blocked"


def test_runner_detects_question_file_and_sets_awaiting(runner_setup):
    runner, sm = runner_setup
    work = runner.work_dir
    questions_dir = work / "questions"
    questions_dir.mkdir(exist_ok=True)

    def popen_side_effect(*args, **kwargs):
        (questions_dir / "T-001.md").write_text("Where should the helper live?")
        return _mock_claude_exits_clean([
            json.dumps({"type": "system", "subtype": "init", "session_id": "sid-2"}),
        ])

    with patch("subprocess.Popen", side_effect=popen_side_effect), \
         patch("audit_orchestrator.runner.dirty_tracked_files", return_value=[]), \
         patch("audit_orchestrator.runner.head_sha", return_value="pre-spawn-sha"):
        outcome = runner.run_one("#T-001", mode=RunMode.OVERNIGHT)

    assert outcome == "awaiting_answer"
    state = sm.load()
    assert state.items["#T-001"]["status"] == "awaiting_answer"
    assert state.items["#T-001"]["session_id"] == "sid-2"
    qfile = (work / "questions" / "T-001.md").read_text()
    assert "session_id: sid-2" in qfile
    assert "Where should the helper live?" in qfile


def test_runner_resume_uses_resume_flag(runner_setup):
    runner, sm = runner_setup
    state = sm.load()
    state.items["#T-001"]["session_id"] = "sid-old"
    state.items["#T-001"]["status"] = "awaiting_answer"
    sm.save(state)

    captured_cmd = []

    def popen_side_effect(cmd, *args, **kwargs):
        captured_cmd.extend(cmd)
        return _mock_claude_exits_clean([
            json.dumps({"type": "system", "subtype": "init", "session_id": "sid-old"}),
        ])

    with patch("subprocess.Popen", side_effect=popen_side_effect), \
         patch("audit_orchestrator.runner.run_test_command", return_value=True), \
         patch("audit_orchestrator.runner.pre_push_check") as ppc, \
         patch("audit_orchestrator.runner.push_to_remote"), \
         patch("audit_orchestrator.runner.tick_checkbox_for_item", return_value=True), \
         patch.object(Runner, "_files_in_commit", return_value=[]):
        ppc.return_value.ok = True
        ppc.return_value.commit_sha = "abc"
        runner.resume("#T-001", answer="Use option (a).")

    assert "--resume" in captured_cmd
    assert "sid-old" in captured_cmd


def test_runner_refuses_to_spawn_when_working_tree_contaminated(runner_setup):
    """Fix E: if non-ignored tracked files are dirty, refuse to run.
    Without this, the agent's commit silently absorbs leftover work from
    an earlier interrupted item — that was the 5ba2c4b frankenstein root
    cause."""
    runner, sm = runner_setup

    # Simulate a contaminated working tree
    contamination = [" M app/SomeFile.php", " M app/Other.php"]

    with patch("audit_orchestrator.runner.dirty_tracked_files", return_value=contamination), \
         patch("subprocess.Popen") as popen, \
         patch("audit_orchestrator.runner.head_sha"):
        outcome = runner.run_one("#T-001", mode=RunMode.OVERNIGHT)

    assert outcome == "blocked"
    state = sm.load()
    item = state.items["#T-001"]
    assert item["status"] == "blocked"
    assert "working tree" in (item.get("blocked_reason") or "").lower()
    assert "app/SomeFile.php" in (item.get("blocked_reason") or "")
    # Crucially: agent was NEVER spawned
    popen.assert_not_called()


def test_runner_rolls_back_to_pre_spawn_sha_on_push_failure(runner_setup):
    """Fix B: when push fails after the agent committed, hard-reset the
    local branch to where it was before the agent ran — so the orphan
    commit can't be absorbed by the next item's squash."""
    runner, sm = runner_setup
    stream = [json.dumps({"type": "system", "subtype": "init", "session_id": "sid-1"})]

    captured_git = []

    def fake_subprocess_run(cmd, **kwargs):
        # Capture only git invocations from the rollback path
        if isinstance(cmd, list) and cmd and cmd[0] == "git":
            captured_git.append(cmd)
        result = MagicMock()
        result.returncode = 0
        result.stdout = ""
        result.stderr = ""
        return result

    with patch("subprocess.Popen", return_value=_mock_claude_exits_clean(stream)), \
         patch("audit_orchestrator.runner.dirty_tracked_files", return_value=[]), \
         patch("audit_orchestrator.runner.head_sha", return_value="anchor-sha"), \
         patch("audit_orchestrator.runner.run_test_command", return_value=True), \
         patch("audit_orchestrator.runner.pre_push_check") as ppc, \
         patch("audit_orchestrator.runner.push_to_remote", side_effect=RuntimeError("non-fast-forward")), \
         patch.object(Runner, "_files_in_commit", return_value=[]), \
         patch("audit_orchestrator.runner.subprocess.run", side_effect=fake_subprocess_run):
        ppc.return_value.ok = True
        ppc.return_value.commit_sha = "agent-commit"

        outcome = runner.run_one("#T-001", mode=RunMode.OVERNIGHT)

    assert outcome == "blocked"
    state = sm.load()
    assert state.items["#T-001"]["status"] == "blocked"
    assert "push failed" in (state.items["#T-001"].get("blocked_reason") or "")

    # The rollback git command was issued with the pre-spawn anchor
    reset_calls = [c for c in captured_git if c[:3] == ["git", "reset", "--hard"]]
    assert len(reset_calls) == 1, f"expected one git reset --hard, got {reset_calls}"
    assert reset_calls[0][3] == "anchor-sha"
