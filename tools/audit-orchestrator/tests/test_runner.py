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
    """Create a tmp .audit-work/ + state with one queued item."""
    work = tmp_path / ".audit-work"
    work.mkdir()
    sm = StateManager(work / "state.json")
    state = sm.load()
    state.queue = ["#T-001"]
    state.items = {"#T-001": {"id": "#T-001", "title": "Test", "body_markdown": "<body>", "status": "pending", "source": "test.md"}}
    sm.save(state)
    config = Config()
    return Runner(work_dir=work, repo_root=tmp_path, config=config), sm


def test_runner_marks_done_on_clean_exit_with_passing_tests(runner_setup):
    runner, sm = runner_setup
    stream = [json.dumps({"type": "system", "subtype": "init", "session_id": "sid-1"})]

    with patch("subprocess.Popen", return_value=_mock_claude_exits_clean(stream)), \
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

    with patch("subprocess.Popen", side_effect=popen_side_effect):
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
