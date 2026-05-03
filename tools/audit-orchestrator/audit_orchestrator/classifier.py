"""Classify audit items as recommended / caution / skip for unattended runs."""
from __future__ import annotations
from dataclasses import dataclass, field

from audit_orchestrator.models import Item, Bundle, Effort, Classification


@dataclass
class ClassifierContext:
    """All inputs needed to classify an item."""
    overrides: dict[str, str] = field(default_factory=dict)
    standalone_xl: list[str] = field(default_factory=list)
    standalone_architectural: list[str] = field(default_factory=list)
    standalone_high_value: list[str] = field(default_factory=list)
    caution_keywords: list[str] = field(default_factory=list)
    skip_keywords: list[str] = field(default_factory=list)


def classify_item(item: Item, ctx: ClassifierContext) -> Classification:
    """Apply rules in order; first match wins."""

    # Rule 1: explicit override
    if item.id in ctx.overrides:
        return Classification(ctx.overrides[item.id])

    # Rule 2: in XL or architectural standalone subcategories
    if item.id in ctx.standalone_xl or item.id in ctx.standalone_architectural:
        return Classification.SKIP

    # Rule 3: Effort tag is L or XL
    if item.effort in (Effort.L, Effort.XL):
        return Classification.SKIP

    # Rule 4: Effort M with caution keyword in title
    if item.effort == Effort.M and _contains_keyword(item.title, ctx.caution_keywords):
        return Classification.CAUTION

    # Rule 5: in high-value standalone subcategory
    if item.id in ctx.standalone_high_value:
        return Classification.CAUTION

    # Default
    return Classification.RECOMMENDED


def classify_bundle(bundle: Bundle, members: list[Item], ctx: ClassifierContext) -> Classification:
    """Bundle inherits the worst classification of its members.

    Order from worst to best: SKIP > CAUTION > RECOMMENDED.
    """
    if not members:
        return Classification.RECOMMENDED

    classifications = [classify_item(m, ctx) for m in members]
    if Classification.SKIP in classifications:
        return Classification.SKIP
    if Classification.CAUTION in classifications:
        return Classification.CAUTION
    return Classification.RECOMMENDED


def _contains_keyword(text: str, keywords: list[str]) -> bool:
    """Case-insensitive substring match."""
    lower = text.lower()
    return any(kw.lower() in lower for kw in keywords)
