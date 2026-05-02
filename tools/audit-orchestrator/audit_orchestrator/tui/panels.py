"""Widget classes for the TUI panels."""
from __future__ import annotations
from textual.widgets import Static, RichLog
from textual.reactive import reactive


class ProgressPanel(Static):
    """Always-visible: per-source + tonight progress bars."""

    def compose(self):
        yield Static("Audit Progress", id="progress-title")
        yield Static("(no sources loaded yet)", id="progress-detail")


class NowRunningPanel(Static):
    """Always-visible: what's running, mode, step indicator."""

    item_id = reactive("idle")
    step = reactive("waiting")

    def compose(self):
        yield Static("Now Running", id="running-title")
        yield Static("idle", id="running-detail")

    def watch_item_id(self, value: str) -> None:
        try:
            self.query_one("#running-detail", Static).update(f"{value} · step: {self.step}")
        except Exception:
            pass

    def watch_step(self, value: str) -> None:
        try:
            self.query_one("#running-detail", Static).update(f"{self.item_id} · step: {value}")
        except Exception:
            pass


class ActivityLog(RichLog):
    """Always-visible scrollable log."""

    def __init__(self) -> None:
        super().__init__(highlight=True, markup=True, wrap=True, id="activity-log")


class QuestionPanel(Static):
    """Visible only when a question file is pending. Click → opens QuestionModal."""

    pending_count = reactive(0)
    pending_summary = reactive("")

    def compose(self):
        yield Static("(no questions pending)", id="question-detail")

    def watch_pending_count(self, n: int) -> None:
        try:
            detail = self.query_one("#question-detail", Static)
            if n == 0:
                self.add_class("hidden")
                detail.update("(no questions pending)")
            else:
                self.remove_class("hidden")
                detail.update(
                    f"❗ {n} question(s) pending — click to answer\n{self.pending_summary}"
                )
        except Exception:
            pass
