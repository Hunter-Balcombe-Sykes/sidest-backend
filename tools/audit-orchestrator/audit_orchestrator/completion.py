"""Per-fix completion record writer.

Two-author format: Claude writes the freeform body sections; this module
wraps with YAML frontmatter and appends a Questions Asked section assembled
from the per-item question files.
"""
from __future__ import annotations
import re
import yaml
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _safe_id(item_id: str) -> str:
    return item_id.lstrip("#")


@dataclass
class CompletionContext:
    """Factual data the orchestrator knows about a successful fix."""
    item_id: str
    title: str
    source: str
    tier: str
    effort_estimate: str
    mode: str                                  # "work" | "overnight"
    commit_sha: str
    files_touched: list[str]
    test_result: str                           # "pass" | "fail" | "not_run"
    question_files: list[Path] = field(default_factory=list)


def write_completion_record(completed_dir: Path, ctx: CompletionContext) -> Path:
    """Wrap Claude's freeform body with frontmatter + Questions Asked section.

    Expects Claude to have already written {completed_dir}/{safe_id}.md with
    the four required sections. Returns the path to the final file.
    """
    completed_dir.mkdir(parents=True, exist_ok=True)
    path = completed_dir / f"{_safe_id(ctx.item_id)}.md"

    if path.exists():
        body = path.read_text(encoding="utf-8")
    else:
        # Claude didn't write a record. Synthesize a minimal one so we still
        # get an audit trail.
        body = (
            "## Plain English\n(Claude did not provide this section.)\n\n"
            "## Technical Summary\n(Claude did not provide this section.)\n\n"
            "## Decisions Made\n(none)\n\n"
            "## Notes\n(none)\n"
        )

    frontmatter = {
        "item_id": ctx.item_id,
        "title": ctx.title,
        "source": ctx.source,
        "tier": ctx.tier,
        "effort_estimate": ctx.effort_estimate,
        "completed_at": _now_iso(),
        "mode": ctx.mode,
        "commit_sha": ctx.commit_sha,
        "files_touched": ctx.files_touched,
        "test_result": ctx.test_result,
        "questions_asked": len(ctx.question_files),
    }

    fm_yaml = yaml.dump(frontmatter, sort_keys=False, default_flow_style=False).strip()
    questions_section = _render_questions_section(ctx.question_files)

    final = (
        f"---\n{fm_yaml}\n---\n\n"
        f"# {ctx.item_id} — {ctx.title}\n\n"
        f"{body.strip()}\n\n"
        f"{questions_section}\n"
    )
    path.write_text(final, encoding="utf-8")
    return path


def write_blocked_record(
    completed_dir: Path,
    *,
    item_id: str,
    title: str,
    source: str,
    reason: str,
    log_excerpt: str,
) -> Path:
    """Write a stripped-down record for a blocked item."""
    completed_dir.mkdir(parents=True, exist_ok=True)
    path = completed_dir / f"{_safe_id(item_id)}.md"

    frontmatter = {
        "item_id": item_id,
        "title": title,
        "source": source,
        "blocked_at": _now_iso(),
        "outcome": "blocked",
    }
    fm_yaml = yaml.dump(frontmatter, sort_keys=False, default_flow_style=False).strip()

    final = (
        f"---\n{fm_yaml}\n---\n\n"
        f"# {item_id} — {title}\n\n"
        f"## Why Blocked\n{reason}\n\n"
        f"## Log Excerpt\n```\n{log_excerpt[:2000]}\n```\n"
    )
    path.write_text(final, encoding="utf-8")
    return path


def _render_questions_section(question_files: list[Path]) -> str:
    if not question_files:
        return "## Questions Asked\n(none)"

    out = ["## Questions Asked"]
    for i, qf in enumerate(question_files, 1):
        if not qf.exists():
            continue
        text = qf.read_text(encoding="utf-8")
        question, answer = _split_question_answer(text)
        out.append(f"\n### Q{i}\n")
        out.append(f"> {question.strip()}\n")
        if answer:
            out.append(f"\n**Answer:** {answer.strip()}\n")
        else:
            out.append("\n**Answer:** (no answer recorded)\n")
    return "\n".join(out)


def _split_question_answer(text: str) -> tuple[str, str | None]:
    """Strip frontmatter, split body on '## Answer' heading."""
    body = text
    if body.startswith("---\n"):
        _, _, rest = body.partition("---\n")[2].partition("---\n")
        body = rest.strip()

    parts = re.split(r"^##\s+Answer\b.*$", body, maxsplit=1, flags=re.MULTILINE)
    if len(parts) == 2:
        return parts[0].strip(), parts[1].strip()
    return body.strip(), None
