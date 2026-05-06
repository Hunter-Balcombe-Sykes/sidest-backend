"""Tests for the `unblock` CLI subcommand."""
import json
from pathlib import Path

from click.testing import CliRunner

from audit_orchestrator.cli import main


def _seed_state(tmp_path: Path, items: dict, queue: list[str] | None = None) -> Path:
    """Write a minimal state.json into .audit-work/ under tmp_path."""
    work = tmp_path / ".audit-work"
    work.mkdir()
    (work / "blocked").mkdir()
    state = {
        "schema_version": 1,
        "last_parse": None,
        "sources": [],
        "items": items,
        "queue": queue or [],
        "current_run": None,
        "history": [],
    }
    (work / "state.json").write_text(json.dumps(state), encoding="utf-8")
    return work


def test_unblock_requeues_blocked_item_by_id(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    _seed_state(tmp_path, {
        "#5-04": {"status": "blocked", "blocked_reason": "tests failed"},
    })

    result = CliRunner().invoke(main, ["unblock", "#5-04"])
    assert result.exit_code == 0, result.output
    assert "Unblocked" in result.output

    state = json.loads((tmp_path / ".audit-work" / "state.json").read_text())
    assert state["items"]["#5-04"]["status"] == "pending"
    assert "blocked_reason" not in state["items"]["#5-04"]
    assert state["queue"] == ["#5-04"]


def test_unblock_all_targets_blocked_and_cleans_orphans(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    work = _seed_state(tmp_path, {
        "#5-04": {"status": "blocked", "blocked_reason": "dirty tree"},
        "#6-01": {"status": "blocked", "blocked_reason": "dirty tree"},
        # Already done — has stale reason + orphan files; should be cleaned, not re-queued.
        "#10-10": {"status": "done", "blocked_reason": "tests failed"},
        # Untouched done item, no stale state — ignored entirely.
        "#1-02": {"status": "done"},
    })
    # Orphan artifacts left behind for the previously-blocked-now-done item.
    (work / "blocked" / "10-10.log").write_text("test_failure", encoding="utf-8")
    (work / "blocked" / "10-10.patch").write_text("", encoding="utf-8")

    result = CliRunner().invoke(main, ["unblock", "--all"])
    assert result.exit_code == 0, result.output

    state = json.loads((work / "state.json").read_text())
    # Group A — re-queued.
    assert state["items"]["#5-04"]["status"] == "pending"
    assert state["items"]["#6-01"]["status"] == "pending"
    assert "#5-04" in state["queue"] and "#6-01" in state["queue"]
    # Group B — status untouched, stale reason gone, orphan files removed.
    assert state["items"]["#10-10"]["status"] == "done"
    assert "blocked_reason" not in state["items"]["#10-10"]
    assert not (work / "blocked" / "10-10.log").exists()
    assert not (work / "blocked" / "10-10.patch").exists()
    # Untouched done item stays untouched.
    assert state["items"]["#1-02"] == {"status": "done"}


def test_unblock_explicit_id_on_done_item_strips_stale_reason(tmp_path, monkeypatch):
    """Explicit IDs work on done items too — strips metadata without flipping status."""
    monkeypatch.chdir(tmp_path)
    work = _seed_state(tmp_path, {
        "B16": {"status": "done", "blocked_reason": "pre-push: no new commits"},
    })
    (work / "blocked" / "B16.log").write_text("...", encoding="utf-8")

    result = CliRunner().invoke(main, ["unblock", "B16"])
    assert result.exit_code == 0, result.output
    assert "Cleared stale" in result.output

    state = json.loads((work / "state.json").read_text())
    assert state["items"]["B16"]["status"] == "done"
    assert "blocked_reason" not in state["items"]["B16"]
    assert state["queue"] == []
    assert not (work / "blocked" / "B16.log").exists()


def test_unblock_no_args_errors(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    _seed_state(tmp_path, {})
    result = CliRunner().invoke(main, ["unblock"])
    assert result.exit_code != 0
    assert "Specify item IDs or --all" in result.output


def test_unblock_unknown_id_reports_not_found(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    _seed_state(tmp_path, {"#5-04": {"status": "blocked"}})
    result = CliRunner().invoke(main, ["unblock", "#NOPE"])
    assert result.exit_code == 0
    assert "Not in state.items" in result.output
