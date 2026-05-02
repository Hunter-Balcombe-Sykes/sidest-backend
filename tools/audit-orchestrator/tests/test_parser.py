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
