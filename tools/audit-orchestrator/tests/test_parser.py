"""Parser tests."""
import pytest
from pathlib import Path
from audit_orchestrator.parser import parse_audit_file
from audit_orchestrator.models import Tier, Effort, ItemStatus


def test_parses_three_real_items(fixtures_dir):
    result = parse_audit_file(fixtures_dir / "tiny-audit.md")
    items = {item.id: item for item in result.items}

    assert "#T-001" in items
    assert items["#T-001"].tier == Tier.P0
    assert items["#T-001"].effort == Effort.S
    assert "First test item title" in items["#T-001"].title

    assert "#T-002" in items
    assert items["#T-002"].tier == Tier.P1
    assert items["#T-002"].status == ItemStatus.PENDING

    assert "#T-003" in items
    assert items["#T-003"].status == ItemStatus.DONE  # checkbox is - [x]


def test_parses_bundles(fixtures_dir):
    result = parse_audit_file(fixtures_dir / "tiny-audit.md")
    bundles = {b.id: b for b in result.bundles}

    assert "B-T1" in bundles
    assert bundles["B-T1"].members == ["#T-001", "#T-002"]
    assert bundles["B-T1"].effort_estimate_hours == (1.0, 2.0)
    assert "Test bundle one" in bundles["B-T1"].title

    assert "B-T2" in bundles
    assert bundles["B-T2"].members == ["#T-003"]
    assert bundles["B-T2"].effort_estimate_hours == (0.5, 0.5)


def test_bundle_back_reference_on_items(fixtures_dir):
    result = parse_audit_file(fixtures_dir / "tiny-audit.md")
    items = {item.id: item for item in result.items}

    assert items["#T-001"].bundle == "B-T1"
    assert items["#T-002"].bundle == "B-T1"
    assert items["#T-003"].bundle == "B-T2"
    assert items["#T-004"].bundle is None


def test_extracts_standalone_subcategories(fixtures_dir):
    result = parse_audit_file(fixtures_dir / "tiny-audit.md")
    assert "#T-004" in result.standalone_xl
    assert "#T-005" in result.standalone_architectural
    assert "#T-006" in result.standalone_high_value


def test_malformed_items_produce_warnings(fixtures_dir):
    result = parse_audit_file(fixtures_dir / "malformed-audit.md")
    warnings_text = "\n".join(result.warnings)
    assert "#BAD-001" in warnings_text
    item_ids = [item.id for item in result.items]
    assert "#BAD-002" not in item_ids
    assert "#BAD-003" not in item_ids


def test_parses_real_pilot_stage_1():
    """Sanity check against the actual file the tool will operate on."""
    repo_root = Path(__file__).parent.parent.parent.parent
    audit_path = repo_root / "pilot-stage-1.md"
    if not audit_path.exists():
        pytest.skip("pilot-stage-1.md not present; integration test skipped")

    result = parse_audit_file(audit_path)

    item_count = len(result.items)
    bundle_count = len(result.bundles)
    assert 100 < item_count < 200, f"expected ~137 items, got {item_count}"
    assert 10 < bundle_count < 25, f"expected ~17 bundles, got {bundle_count}"

    item_ids = {item.id for item in result.items}
    assert "#V5-068" in item_ids
    assert "#10-01" in item_ids
    bundle_ids = {b.id for b in result.bundles}
    assert "B5" in bundle_ids
