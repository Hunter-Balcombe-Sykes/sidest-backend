"""Send OS-level notifications for question events.

Prefers terminal-notifier (richer notifications). Falls back to macOS osascript.
"""
from __future__ import annotations
import shutil
import subprocess
from enum import Enum


class NotifierBackend(str, Enum):
    TERMINAL_NOTIFIER = "terminal-notifier"
    OSASCRIPT = "osascript"


def notify(message: str, *, title: str = "Audit Orchestrator", enabled: bool = True) -> None:
    """Fire a notification. Silent on failure (notifications are best-effort)."""
    if not enabled:
        return

    tn = shutil.which("terminal-notifier")
    try:
        if tn:
            subprocess.run(
                [tn, "-title", title, "-message", message],
                check=False, capture_output=True, timeout=2,
            )
        else:
            script = f'display notification "{_escape(message)}" with title "{_escape(title)}"'
            subprocess.run(
                ["osascript", "-e", script],
                check=False, capture_output=True, timeout=2,
            )
    except (subprocess.SubprocessError, FileNotFoundError):
        return  # Notifications are best-effort; never let them block the runner


def _escape(s: str) -> str:
    """Escape double quotes for osascript string literal."""
    return s.replace("\\", "\\\\").replace('"', '\\"')
