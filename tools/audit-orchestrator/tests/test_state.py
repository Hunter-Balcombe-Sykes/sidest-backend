"""State manager tests."""
import pytest
import json
from pathlib import Path
from audit_orchestrator.state import StateManager, State


def test_load_returns_empty_state_when_file_missing(tmp_path):
    sm = StateManager(tmp_path / "state.json")
    state = sm.load()
    assert state.queue == []
    assert state.items == {}
    assert state.history == []


def test_save_and_reload_roundtrip(tmp_path):
    sm = StateManager(tmp_path / "state.json")
    state = sm.load()
    state.queue = ["B5", "B10"]
    state.items = {"B5": {"status": "pending"}}
    sm.save(state)

    sm2 = StateManager(tmp_path / "state.json")
    reloaded = sm2.load()
    assert reloaded.queue == ["B5", "B10"]
    assert reloaded.items["B5"]["status"] == "pending"


def test_atomic_write_via_tmp_then_rename(tmp_path, mocker):
    """Verify the write goes to a .tmp first, then rename — never half-written."""
    sm = StateManager(tmp_path / "state.json")
    state = sm.load()
    state.queue = ["A"]

    rename_calls = []
    real_replace = Path.replace

    def spy_replace(self, target):
        rename_calls.append((self, target))
        return real_replace(self, target)

    mocker.patch.object(Path, "replace", spy_replace)
    sm.save(state)
    assert len(rename_calls) == 1
    src, dst = rename_calls[0]
    assert src.name.endswith(".tmp") or src.name.endswith(".tmp.json")
    assert dst.name == "state.json"


def test_corrupted_state_backs_up_and_returns_empty(tmp_path):
    bad_path = tmp_path / "state.json"
    bad_path.write_text("{not valid json")
    sm = StateManager(bad_path)
    state = sm.load()
    assert state.queue == []
    backups = list(tmp_path.glob("state.json.broken-*"))
    assert len(backups) == 1


# --- Task 6.2: lock file exclusion + cleanup ---


def test_lock_file_excludes_second_holder(tmp_path):
    from audit_orchestrator.state import lock_file
    lock_path = tmp_path / ".run.lock"
    with lock_file(lock_path):
        with pytest.raises(RuntimeError, match="already running"):
            with lock_file(lock_path, timeout=0.1):
                pass
    with lock_file(lock_path):
        pass


def test_lock_file_cleaned_up_on_exit(tmp_path):
    from audit_orchestrator.state import lock_file
    lock_path = tmp_path / ".run.lock"
    with lock_file(lock_path):
        assert lock_path.exists()
    assert not lock_path.exists()
