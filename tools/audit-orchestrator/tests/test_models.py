"""Tests for the core dataclasses."""
from audit_orchestrator.models import Item, Bundle, ItemStatus, Classification, Effort, Tier


def test_item_construction():
    item = Item(
        id="#V5-068",
        tier=Tier.P0,
        effort=Effort.S,
        title="Shopify theme webhook returns 200 on bad HMAC",
        source="pilot-stage-1.md",
        body_markdown="<full body>",
        bundle=None,
        classification=Classification.RECOMMENDED,
        status=ItemStatus.PENDING,
    )
    assert item.id == "#V5-068"
    assert item.tier == Tier.P0
    assert item.effort == Effort.S
    assert item.is_bundle is False


def test_bundle_construction():
    bundle = Bundle(
        id="B5",
        title="Throwable→QueryException narrowing in analytics",
        members=["#CR-010", "#V5-017"],
        source="pilot-stage-1.md",
        body_markdown="<full body>",
        effort_estimate_hours=(1.0, 2.0),
        classification=Classification.RECOMMENDED,
        status=ItemStatus.PENDING,
    )
    assert bundle.id == "B5"
    assert bundle.members == ["#CR-010", "#V5-017"]
    assert bundle.is_bundle is True


def test_status_transitions():
    """Smoke test: valid status enum values."""
    assert ItemStatus.PENDING.value == "pending"
    assert ItemStatus.RUNNING.value == "running"
    assert ItemStatus.AWAITING_ANSWER.value == "awaiting_answer"
    assert ItemStatus.DONE.value == "done"
    assert ItemStatus.BLOCKED.value == "blocked"
