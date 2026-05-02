"""Parse Claude Code's --output-format stream-json output incrementally."""
from __future__ import annotations
import json
from dataclasses import dataclass
from enum import Enum


class EventKind(str, Enum):
    """Coarse classification used to drive the TUI step indicator."""
    PLANNING = "planning"
    EDITING = "editing"
    TESTING = "testing"
    COMMITTING = "committing"
    OTHER = "other"


_STAGE_ORDER = {
    EventKind.PLANNING: 0,
    EventKind.EDITING: 1,
    EventKind.TESTING: 2,
    EventKind.COMMITTING: 3,
}


@dataclass
class ToolCallDetail:
    """Specific information about one Claude tool invocation."""
    kind: EventKind
    tool: str               # 'Read', 'Edit', 'Write', 'Bash', 'Glob', 'Grep'
    target: str             # file path or shell command
    snippet: str            # short human-readable summary, with emoji prefix


def _short_path(path: str) -> str:
    """Trim a long file path to its last 3 components."""
    parts = path.split("/")
    if len(parts) <= 3:
        return path
    return ".../" + "/".join(parts[-3:])


class StreamEventTracker:
    """Stateful, line-buffered parser. Feed it stdout lines; it tracks state.

    Key attributes:
      session_id           — Claude session ID (captured from first event with one)
      last_event           — most recent EventKind, monotonic (never goes backwards)
      raw_last_event       — most recent EventKind raw (does go backwards)
      last_detail          — ToolCallDetail of the most recent tool use, or None
      detail_history       — list of every ToolCallDetail seen, in order
      assistant_text       — list of plain-text assistant messages (no tool calls)
    """

    def __init__(self) -> None:
        self.session_id: str | None = None
        self.last_event: EventKind | None = None
        self.raw_last_event: EventKind | None = None
        self.last_detail: ToolCallDetail | None = None
        self.detail_history: list[ToolCallDetail] = []
        self.assistant_text: list[str] = []
        self.lines_seen: int = 0
        self.result_event: dict | None = None
        self.is_error: bool = False
        self.error_message: str | None = None
        self.is_usage_limit: bool = False

    def feed_line(self, line: str) -> ToolCallDetail | None:
        """Process one stdout line. Returns ToolCallDetail if it was a tool
        invocation, else None. Silently ignores malformed JSON."""
        line = line.strip()
        if not line:
            return None
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            return None

        self.lines_seen += 1

        if self.session_id is None and isinstance(event, dict):
            self.session_id = event.get("session_id") or self.session_id

        # Capture the final result event (Claude's exit summary). If it
        # indicates an error, classify the cause — usage limits look very
        # different from real failures and need to be re-runnable.
        if isinstance(event, dict) and event.get("type") == "result":
            self.result_event = event
            if event.get("is_error") or event.get("subtype") == "error":
                self.is_error = True
                # Stitch together whatever error info Claude provided
                bits: list[str] = []
                for k in ("api_error_status", "result", "error", "terminal_reason"):
                    v = event.get(k)
                    if v:
                        bits.append(str(v))
                self.error_message = " · ".join(bits) or "unknown error"
                # Detect usage-limit signals (substring match across known phrasings)
                lower = self.error_message.lower()
                if any(kw in lower for kw in (
                    "rate_limit", "rate limit",
                    "usage limit", "quota",
                    "max_tokens", "token limit",
                    "credit", "billing",
                )):
                    self.is_usage_limit = True

        text = self._extract_assistant_text(event)
        if text:
            self.assistant_text.append(text)

        detail = self._classify_detail(event)
        if detail is None:
            return None

        self.raw_last_event = detail.kind
        # Monotonic: once we've moved past planning, don't go back when Claude
        # reads another file mid-edit. Stage indicator should only move forward.
        if self.last_event is None:
            self.last_event = detail.kind
        else:
            current_rank = _STAGE_ORDER.get(self.last_event, -1)
            new_rank = _STAGE_ORDER.get(detail.kind, -1)
            if new_rank > current_rank:
                self.last_event = detail.kind

        self.last_detail = detail
        self.detail_history.append(detail)
        return detail

    def _extract_assistant_text(self, event: dict) -> str | None:
        if not isinstance(event, dict) or event.get("type") != "assistant":
            return None
        content = event.get("message", {}).get("content", [])
        text_parts = [
            (b.get("text") or "").strip()
            for b in content
            if isinstance(b, dict) and b.get("type") == "text"
        ]
        text = " ".join(p for p in text_parts if p).strip()
        return text or None

    def _classify_detail(self, event: dict) -> ToolCallDetail | None:
        if not isinstance(event, dict) or event.get("type") != "assistant":
            return None

        content = event.get("message", {}).get("content", [])
        for block in content:
            if not isinstance(block, dict) or block.get("type") != "tool_use":
                continue
            name = block.get("name", "")
            inp = block.get("input", {}) or {}

            if name == "Read":
                target = str(inp.get("file_path", "?"))
                return ToolCallDetail(
                    kind=EventKind.PLANNING, tool="Read",
                    target=target, snippet=f"📖 reading {_short_path(target)}",
                )
            if name in ("Edit", "Write"):
                target = str(inp.get("file_path", "?"))
                verb = "writing" if name == "Write" else "editing"
                emoji = "📝" if name == "Write" else "✏️"
                return ToolCallDetail(
                    kind=EventKind.EDITING, tool=name,
                    target=target, snippet=f"{emoji} {verb} {_short_path(target)}",
                )
            if name == "Bash":
                cmd = (inp.get("command", "") or "").strip()
                short_cmd = cmd if len(cmd) <= 60 else cmd[:57] + "..."
                if "composer test" in cmd or cmd.startswith("pytest"):
                    return ToolCallDetail(
                        kind=EventKind.TESTING, tool="Bash",
                        target=cmd, snippet="🧪 running tests",
                    )
                if cmd.startswith("git commit"):
                    return ToolCallDetail(
                        kind=EventKind.COMMITTING, tool="Bash",
                        target=cmd, snippet="✅ committing",
                    )
                if cmd.startswith("git "):
                    return ToolCallDetail(
                        kind=EventKind.OTHER, tool="Bash",
                        target=cmd, snippet=f"git: {short_cmd[4:]}",
                    )
                return ToolCallDetail(
                    kind=EventKind.OTHER, tool="Bash",
                    target=cmd, snippet=f"$ {short_cmd}",
                )
            if name in ("Glob", "Grep"):
                pattern = str(inp.get("pattern", inp.get("query", "?")))
                return ToolCallDetail(
                    kind=EventKind.PLANNING, tool=name,
                    target=pattern, snippet=f"🔍 {name.lower()} {pattern}",
                )

        return None
