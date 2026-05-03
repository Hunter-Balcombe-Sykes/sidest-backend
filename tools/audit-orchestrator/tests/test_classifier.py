"""Classifier tests."""
import pytest
from audit_orchestrator.classifier import classify_item, ClassifierContext
from audit_orchestrator.models import Item, Tier, Effort, Classification, ItemStatus


def make_item(id="#T-001", effort=Effort.S, title="Some title", tier=Tier.P0):
    return Item(
        id=id, tier=tier, effort=effort, title=title,
        source="test.md", body_markdown="<body>", status=ItemStatus.PENDING,
    )


def make_context(**overrides):
    defaults = dict(
        overrides={},
        standalone_xl=[],
        standalone_architectural=[],
        standalone_high_value=[],
        caution_keywords=["Stripe", "payout", "auth", "GDPR", "compliance", "webhook secret", "money"],
        skip_keywords=["XL", "policy rollout", "architectural"],
    )
    defaults.update(overrides)
    return ClassifierContext(**defaults)


def test_small_item_is_recommended():
    assert classify_item(make_item(effort=Effort.S), make_context()) == Classification.RECOMMENDED


def test_xl_item_is_skip():
    assert classify_item(make_item(effort=Effort.XL), make_context()) == Classification.SKIP


def test_large_item_is_skip():
    assert classify_item(make_item(effort=Effort.L), make_context()) == Classification.SKIP


def test_medium_item_with_caution_keyword_is_caution():
    item = make_item(effort=Effort.M, title="Stripe webhook race condition")
    assert classify_item(item, make_context()) == Classification.CAUTION


def test_medium_item_without_caution_keyword_is_recommended():
    item = make_item(effort=Effort.M, title="Cache key versioning sweep")
    assert classify_item(item, make_context()) == Classification.RECOMMENDED


def test_override_wins_over_default():
    item = make_item(effort=Effort.S)
    ctx = make_context(overrides={item.id: "skip"})
    assert classify_item(item, ctx) == Classification.SKIP


def test_xl_standalone_is_skip_even_when_effort_is_S():
    """Edge case: an item flagged XL in the standalone list takes precedence."""
    item = make_item(id="#T-XL", effort=Effort.S)
    ctx = make_context(standalone_xl=["#T-XL"])
    assert classify_item(item, ctx) == Classification.SKIP


def test_high_value_standalone_with_small_effort_is_caution():
    item = make_item(id="#T-HV", effort=Effort.S)
    ctx = make_context(standalone_high_value=["#T-HV"])
    assert classify_item(item, ctx) == Classification.CAUTION


def test_bundle_inherits_worst_member_classification():
    from audit_orchestrator.classifier import classify_bundle
    from audit_orchestrator.models import Bundle

    members = [
        make_item(id="#A", effort=Effort.S),                         # recommended
        make_item(id="#B", effort=Effort.M, title="Stripe payout"),  # caution
    ]
    bundle = Bundle(
        id="B-X", title="t", members=["#A", "#B"],
        source="test.md", body_markdown="b", effort_estimate_hours=(1, 2),
    )
    assert classify_bundle(bundle, members, make_context()) == Classification.CAUTION

    members2 = members + [make_item(id="#C", effort=Effort.XL)]      # skip
    assert classify_bundle(bundle, members2, make_context()) == Classification.SKIP
