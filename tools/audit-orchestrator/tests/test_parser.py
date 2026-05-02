"""Parser tests."""
import pytest
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
