"""Operations for queue items: populating state metadata from parsed sources.

Bridges the parser (which produces `Item` / `Bundle` objects) and the runner
(which reads body_markdown + metadata from state.items at run time). Without
this bridge, a freshly-queued item has no metadata and the runner skips it.

For a bundle, the body_markdown given to Claude is the bundle line plus every
member item's full body — Claude needs the per-item details to actually fix
all members in one consistent session.
"""
from __future__ import annotations
from pathlib import Path

from audit_orchestrator.config import Config
from audit_orchestrator.models import ItemStatus
from audit_orchestrator.parser import parse_audit_file, ParseResult
from audit_orchestrator.state import State


def gather_sources(config: Config, repo_root: Path) -> list[Path]:
    """Return ordered, deduplicated list of audit source paths to parse."""
    explicit = [repo_root / s for s in config.sources]
    discovered: list[Path] = []
    if config.auto_discover:
        discovered = sorted(set(
            list(repo_root.glob("pilot-*.md")) + list(repo_root.glob("audit-*.md"))
        ))
    seen: set[Path] = set()
    out: list[Path] = []
    for p in explicit + discovered:
        if p in seen or not p.exists():
            continue
        seen.add(p)
        out.append(p)
    return out


def parse_all(config: Config, repo_root: Path) -> list[ParseResult]:
    """Parse every active source file."""
    return [parse_audit_file(p) for p in gather_sources(config, repo_root)]


def populate_item_metadata(
    state: State,
    item_id: str,
    parse_results: list[ParseResult],
) -> bool:
    """Look up `item_id` across parse_results and write its metadata to state.items.

    Handles both standalone items (matched by Item.id) and bundles (matched by
    Bundle.id). For bundles, the recorded body_markdown is the bundle line PLUS
    every member's full body — that's what gets given to Claude as the task.

    Returns True if the id was found, False otherwise. Existing entries with
    a `status` of `done` or `blocked` are left untouched (preserves history).
    """
    existing = state.items.get(item_id)
    if existing and existing.get("status") in ("done", "blocked"):
        return True  # already known, don't overwrite history

    for r in parse_results:
        # Standalone item lookup
        for item in r.items:
            if item.id == item_id:
                state.items[item_id] = {
                    "id": item.id,
                    "title": item.title,
                    "source": item.source,
                    "tier": item.tier.value,
                    "effort": item.effort.value,
                    "body_markdown": item.body_markdown,
                    "bundle": item.bundle,
                    "status": ItemStatus.PENDING.value,
                    "is_bundle": False,
                }
                return True

        # Bundle lookup
        for bundle in r.bundles:
            if bundle.id == item_id:
                item_by_id = {i.id: i for i in r.items}
                body_parts = [
                    f"BUNDLE: {bundle.title}",
                    "",
                    bundle.body_markdown,
                    "",
                    "## Members of this bundle",
                    "",
                ]
                for member_id in bundle.members:
                    member = item_by_id.get(member_id)
                    if member is None:
                        body_parts.append(f"(member {member_id} not found in source)")
                    else:
                        body_parts.append(member.body_markdown)
                    body_parts.append("")

                state.items[item_id] = {
                    "id": bundle.id,
                    "title": bundle.title,
                    "source": bundle.source,
                    "tier": "mixed",
                    "effort": "bundle",
                    "body_markdown": "\n".join(body_parts).strip(),
                    "members": list(bundle.members),
                    "status": ItemStatus.PENDING.value,
                    "is_bundle": True,
                }
                return True

    return False
