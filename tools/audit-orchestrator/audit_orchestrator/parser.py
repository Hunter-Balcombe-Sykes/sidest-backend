"""Parse audit markdown files into Item and Bundle objects.

Format spec: docs/audit-conventions.md
"""
from __future__ import annotations
import re
from dataclasses import dataclass, field
from pathlib import Path

from audit_orchestrator.models import Item, Bundle, Tier, Effort, ItemStatus


# Item header: `- [ ] **#ID** · P0 — Title here`
ITEM_HEADER_RE = re.compile(
    r"^- \[(?P<check>[ x])\] \*\*(?P<id>#[A-Z0-9-]+)\*\* · (?P<tier>P[0-3]) — (?P<title>.+)$"
)

# Sub-field: `    - **Where:** value`
SUBFIELD_RE = re.compile(
    r"^\s+- \*\*(?P<field>[\w ]+?):\*\* (?P<value>.+)$"
)

# Effort sub-field value: `S (~0.5h)` or `XL (~16h+)`
EFFORT_VALUE_RE = re.compile(r"^(?P<size>S|M|L|XL)\b")

# Bundle line: `- **B5 — Title.** #X, #Y. ~1–2h. Rationale...`
# Members are captured up to the first `.` after the last member ID.
# Optional text like `(Optional ride-alongs: ...)` between members and `~Nh` is skipped.
# Handles both `~1h` (integer, no range) and `~1–2h` / `~0.5–1h` (decimal range).
BUNDLE_RE = re.compile(
    r"^- (?:\[[ x]\] )?"                            # optional checkbox prefix
    r"\*\*(?P<id>B[\w-]+) — (?P<title>.+?)\.\*\* "
    r"(?P<members>(?:#[A-Z0-9-]+(?:, )?)+)\."       # members list ends at '.'
    r"(?:[^~]*)~(?P<low>[\d.]+)(?:[–-](?P<high>[\d.]+))?h\."  # skip optional text, then ~Nh
    r"\s*(?P<rationale>.*)$"
)

# Standalone subcategory line: `- **XL refactors:** #X (label), #Y (label).`
STANDALONE_SUBCAT_RE = re.compile(
    r"^- \*\*(?P<label>[A-Za-z0-9 /\-]+):\*\* (?P<rest>.+)$"
)

# Extract all #ID tokens from a string
ID_EXTRACT_RE = re.compile(r"#[A-Z0-9-]+")


@dataclass
class ParseResult:
    """Output of parse_audit_file."""
    items: list[Item] = field(default_factory=list)
    bundles: list[Bundle] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)
    source_filename: str = ""
    standalone_xl: list[str] = field(default_factory=list)
    standalone_architectural: list[str] = field(default_factory=list)
    standalone_high_value: list[str] = field(default_factory=list)


def parse_audit_file(path: Path) -> ParseResult:
    """Parse a single audit markdown file."""
    text = path.read_text(encoding="utf-8")
    result = ParseResult(source_filename=path.name)
    _parse_items(text, result)
    _parse_bundles(text, result)
    _parse_standalone_subcategories(text, result)
    _link_items_to_bundles(result)
    return result


def _parse_items(text: str, result: ParseResult) -> None:
    lines = text.splitlines()
    current_item: dict | None = None
    current_body: list[str] = []

    def flush_current() -> None:
        if current_item is None:
            return
        item = _build_item(current_item, "\n".join(current_body), result)
        if item is not None:
            result.items.append(item)

    for line in lines:
        header_match = ITEM_HEADER_RE.match(line)
        if header_match:
            flush_current()
            current_item = {
                "id": header_match.group("id"),
                "tier": header_match.group("tier"),
                "title": header_match.group("title").strip(),
                "status": ItemStatus.DONE if header_match.group("check") == "x" else ItemStatus.PENDING,
                "subfields": {},
            }
            current_body = [line]
            continue

        if current_item is not None:
            current_body.append(line)
            sub = SUBFIELD_RE.match(line)
            if sub:
                field_name = sub.group("field").strip().lower().replace(" ", "_")
                current_item["subfields"][field_name] = sub.group("value").strip()
            elif line.startswith("## ") or line.startswith("---"):
                flush_current()
                current_item = None
                current_body = []

    flush_current()


def _build_item(raw: dict, body: str, result: ParseResult) -> Item | None:
    effort = _parse_effort(raw["subfields"].get("effort", ""))
    if effort == Effort.UNKNOWN:
        result.warnings.append(f"{raw['id']}: missing or unparseable Effort field")

    if "where" not in raw["subfields"]:
        result.warnings.append(f"{raw['id']}: missing Where field")
    if "what_to_do" not in raw["subfields"]:
        result.warnings.append(f"{raw['id']}: missing What to do field")

    return Item(
        id=raw["id"],
        tier=Tier(raw["tier"]),
        effort=effort,
        title=raw["title"],
        source=result.source_filename,
        body_markdown=body,
        status=raw["status"],
    )


def _parse_effort(value: str) -> Effort:
    match = EFFORT_VALUE_RE.match(value.strip())
    if not match:
        return Effort.UNKNOWN
    return Effort(match.group("size"))


def _parse_bundles(text: str, result: ParseResult) -> None:
    in_bundles_section = False
    in_standalone_subsection = False

    for line in text.splitlines():
        if line.startswith("## Suggested Bundled Sessions"):
            in_bundles_section = True
            continue
        if in_bundles_section and line.startswith("## ") and not line.startswith("### "):
            in_bundles_section = False
            continue
        if in_bundles_section and line.startswith("### Standalone"):
            in_standalone_subsection = True
            continue
        if in_bundles_section and line.startswith("### "):
            in_standalone_subsection = False
            continue
        if not in_bundles_section or in_standalone_subsection:
            continue

        match = BUNDLE_RE.match(line)
        if not match:
            continue

        members_str = match.group("members")
        members = [m.strip() for m in members_str.split(",") if m.strip()]
        low = float(match.group("low"))
        high_raw = match.group("high")
        high = float(high_raw) if high_raw else low

        bundle = Bundle(
            id=match.group("id"),
            title=match.group("title").strip(),
            members=members,
            source=result.source_filename,
            body_markdown=line,
            effort_estimate_hours=(low, high),
        )
        result.bundles.append(bundle)


def _parse_standalone_subcategories(text: str, result: ParseResult) -> None:
    in_standalone = False
    label_to_field = {
        "xl refactors": "standalone_xl",
        "architectural decisions": "standalone_architectural",
        "high-value standalones": "standalone_high_value",
    }

    for line in text.splitlines():
        if line.startswith("### Standalone"):
            in_standalone = True
            continue
        if in_standalone and (line.startswith("### ") or line.startswith("## ")):
            in_standalone = False
            continue
        if not in_standalone:
            continue

        match = STANDALONE_SUBCAT_RE.match(line)
        if not match:
            continue

        label = match.group("label").strip().lower()
        target = label_to_field.get(label)
        if target is None:
            continue

        ids = ID_EXTRACT_RE.findall(match.group("rest"))
        getattr(result, target).extend(ids)


def _link_items_to_bundles(result: ParseResult) -> None:
    member_to_bundle = {}
    for bundle in result.bundles:
        for member_id in bundle.members:
            member_to_bundle[member_id] = bundle.id

    for item in result.items:
        if item.id in member_to_bundle:
            item.bundle = member_to_bundle[item.id]
