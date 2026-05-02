"""Tests for queue_ops.populate_item_metadata."""
from pathlib import Path

from audit_orchestrator.queue_ops import populate_item_metadata, parse_all
from audit_orchestrator.config import Config
from audit_orchestrator.parser import parse_audit_file
from audit_orchestrator.state import State


def test_populate_standalone_item(fixtures_dir):
    state = State()
    results = [parse_audit_file(fixtures_dir / "tiny-audit.md")]
    found = populate_item_metadata(state, "#T-006", results)
    assert found is True
    entry = state.items["#T-006"]
    assert entry["title"]
    assert entry["body_markdown"]
    assert entry["is_bundle"] is False
    assert entry["status"] == "pending"


def test_populate_bundle_concatenates_member_bodies(fixtures_dir):
    state = State()
    results = [parse_audit_file(fixtures_dir / "tiny-audit.md")]
    found = populate_item_metadata(state, "B-T1", results)
    assert found is True
    entry = state.items["B-T1"]
    assert entry["is_bundle"] is True
    assert entry["members"] == ["#T-001", "#T-002"]
    body = entry["body_markdown"]
    # Bundle context
    assert "BUNDLE:" in body
    assert "Test bundle one" in body
    # Both members' full bodies are included
    assert "#T-001" in body
    assert "First test item title" in body
    assert "#T-002" in body
    assert "Second test item title" in body
    # Member sub-fields are present (proves we used the parser's full body)
    assert "**Where:**" in body
    assert "**What to do:**" in body


def test_populate_returns_false_for_unknown_id(fixtures_dir):
    state = State()
    results = [parse_audit_file(fixtures_dir / "tiny-audit.md")]
    found = populate_item_metadata(state, "#NOPE", results)
    assert found is False
    assert "#NOPE" not in state.items


def test_populate_does_not_overwrite_done_status(fixtures_dir):
    state = State()
    state.items["#T-001"] = {"status": "done", "title": "preserved"}
    results = [parse_audit_file(fixtures_dir / "tiny-audit.md")]
    populate_item_metadata(state, "#T-001", results)
    assert state.items["#T-001"]["status"] == "done"
    assert state.items["#T-001"]["title"] == "preserved"
