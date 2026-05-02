"""Textual application root for the audit orchestrator TUI."""
from __future__ import annotations
from pathlib import Path
from textual.app import App, ComposeResult
from textual.widgets import Header, Footer, Static

from audit_orchestrator.tui.panels import ProgressPanel, NowRunningPanel, ActivityLog
from audit_orchestrator.state import StateManager
from audit_orchestrator.tui.watcher import watch


class AuditApp(App):
    """Audit orchestrator TUI."""

    CSS_PATH = "styles.tcss"
    BINDINGS = [
        ("q", "quit", "Quit"),
        ("f1", "help", "Help"),
        ("f2", "queue", "Queue"),
        ("f3", "mode", "Mode"),
        ("f5", "pause", "Pause"),
    ]

    def __init__(self, work_dir: Path, repo_root: Path) -> None:
        super().__init__()
        self.work_dir = work_dir
        self.repo_root = repo_root
        self.state_mgr = StateManager(work_dir / "state.json")

    def compose(self) -> ComposeResult:
        yield Header()
        yield ProgressPanel()
        yield NowRunningPanel()
        yield ActivityLog()
        yield Footer()

    def on_mount(self) -> None:
        self._refresh_panels()
        self._observer = watch(
            self.work_dir / "state.json",
            lambda: self.call_from_thread(self._refresh_panels),
        )

    def on_unmount(self) -> None:
        if hasattr(self, "_observer"):
            self._observer.stop()
            self._observer.join(timeout=1)

    def _refresh_panels(self) -> None:
        state = self.state_mgr.load()

        try:
            detail = self.query_one("#progress-detail", Static)
            done = sum(1 for v in state.items.values() if v.get("status") == "done")
            total = len(state.items) or 1
            detail.update(f"{done}/{total} items done · queue: {len(state.queue)}")
        except Exception:
            pass

        try:
            running = self.query_one(NowRunningPanel)
            if state.current_run:
                running.item_id = state.current_run.get("id", "?")
            else:
                running.item_id = "idle"
        except Exception:
            pass

    def action_help(self) -> None:
        self.notify("Help screen TBD")

    def action_queue(self) -> None:
        self.notify("Queue browser TBD")

    def action_mode(self) -> None:
        self.notify("Mode picker TBD")

    def action_pause(self) -> None:
        self.notify("Pause TBD")
