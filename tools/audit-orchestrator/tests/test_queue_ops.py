"""Tests for queue_ops.populate_item_metadata."""
from pathlib import Path

from audit_orchestrator.queue_ops import (
    populate_item_metadata,
    parse_all,
    gather_sources,
    _is_companion_file,
)
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


def test_populate_marks_done_if_markdown_already_ticked(fixtures_dir):
    """If the source markdown shows [x], populate should record status=done so
    the runner skips it rather than re-attempting a completed fix."""
    # #T-003 in the fixture is `- [x] **#T-003** ...` (already done)
    state = State()
    results = [parse_audit_file(fixtures_dir / "tiny-audit.md")]
    populate_item_metadata(state, "#T-003", results)
    assert state.items["#T-003"]["status"] == "done"


def test_populate_marks_bundle_done_if_all_members_ticked(fixtures_dir, tmp_path):
    """A bundle is done only when every one of its members is ticked."""
    # Build a tiny custom audit where B-X has 2 members both [x]
    src = tmp_path / "pilot-stage-test.md"
    src.write_text(
        "# Test\n## Progress\n- P0 Blockers: 0 of 0 complete\n\n"
        "## Suggested Bundled Sessions\n\n### High-impact bundles\n\n"
        "- **B-X — Test bundle.** #X-1, #X-2. ~1h. rationale.\n\n"
        "## P0 — Test\n\n"
        "- [x] **#X-1** · P0 — Member 1\n"
        "    - **Where:** a.py\n    - **Effort:** S (~1h)\n    - **What to do:**\n        - Done.\n\n"
        "- [x] **#X-2** · P0 — Member 2\n"
        "    - **Where:** b.py\n    - **Effort:** S (~1h)\n    - **What to do:**\n        - Done.\n",
    )
    state = State()
    results = [parse_audit_file(src)]
    populate_item_metadata(state, "B-X", results)
    assert state.items["B-X"]["status"] == "done"


def test_populate_bundle_pending_if_any_member_not_ticked(fixtures_dir):
    """B-T1's members are #T-001 (unticked) + #T-002 (unticked) → pending."""
    state = State()
    results = [parse_audit_file(fixtures_dir / "tiny-audit.md")]
    populate_item_metadata(state, "B-T1", results)
    assert state.items["B-T1"]["status"] == "pending"


def test_companion_pattern_excludes_manual_queue_files():
    """Files like pilot-manual-queue.md hold items that are intentionally
    not enqueueable. They must be skipped at scan time so re-runs don't
    re-discover and try to enqueue them."""
    assert _is_companion_file("pilot-manual-queue.md") is True
    assert _is_companion_file("audit-2026-05-05-manual-queue.md") is True
    # Sanity: real audit files still pass through.
    assert _is_companion_file("pilot-stage-1.md") is False
    assert _is_companion_file("audit-2026-05-04-foo.md") is False


def test_gather_sources_skips_manual_queue(tmp_path):
    """End-to-end: gather_sources should not return a manual-queue file
    even though it matches the pilot-*.md include glob."""
    (tmp_path / "pilot-stage-1.md").write_text("# stage 1\n")
    (tmp_path / "pilot-manual-queue.md").write_text("# manual\n")
    config = Config(sources=[], auto_discover=True)
    sources = gather_sources(config, tmp_path)
    names = {p.name for p in sources}
    assert "pilot-stage-1.md" in names
    assert "pilot-manual-queue.md" not in names
