"""Parse Claude Code's --output-format stream-json output incrementally."""
from __future__ import annotations
import json
from enum import Enum


class EventKind(str, Enum):
    """Coarse classification used to drive the TUI step indicator."""
    PLANNING = "planning"
    EDITING = "editing"
    TESTING = "testing"
    COMMITTING = "committing"
    OTHER = "other"


class StreamEventTracker:
    """Stateful, line-buffered parser. Feed it stdout lines; it tracks state."""

    def __init__(self) -> None:
        self.session_id: str | None = None
        self.last_event: EventKind | None = None
        self.lines_seen: int = 0

    def feed_line(self, line: str) -> None:
        """Process one stdout line. Silently ignores malformed JSON."""
        line = line.strip()
        if not line:
            return
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            return

        self.lines_seen += 1

        # Capture session_id from any event that carries it (first occurrence wins).
        # Real sessions may surface session_id in hook_started or other system events,
        # not necessarily a subtype=init event, so we check every event.
        if self.session_id is None and isinstance(event, dict):
            self.session_id = event.get("session_id") or self.session_id

        kind = self._classify(event)
        if kind is not None:
            self.last_event = kind

    def _classify(self, event: dict) -> EventKind | None:
        """Return EventKind for the event, or None if not classifiable."""
        if not isinstance(event, dict):
            return None
        if event.get("type") != "assistant":
            return None

        content = event.get("message", {}).get("content", [])
        for block in content:
            if not isinstance(block, dict) or block.get("type") != "tool_use":
                continue
            name = block.get("name", "")
            if name in ("Edit", "Write"):
                return EventKind.EDITING
            if name == "Bash":
                cmd = block.get("input", {}).get("command", "")
                if "composer test" in cmd or "pytest" in cmd:
                    return EventKind.TESTING
                if cmd.startswith("git commit"):
                    return EventKind.COMMITTING
                return EventKind.OTHER
            if name == "Read":
                return EventKind.PLANNING
        return EventKind.PLANNING
