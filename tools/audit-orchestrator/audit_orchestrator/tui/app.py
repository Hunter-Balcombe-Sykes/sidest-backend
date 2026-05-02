"""Textual application root for the audit orchestrator TUI."""
from __future__ import annotations
from pathlib import Path
from textual.app import App, ComposeResult
from textual.widgets import Header, Footer, Static

from audit_orchestrator.tui.panels import (
    ProgressPanel, NowRunningPanel, ActivityLog, QuestionPanel,
)
from audit_orchestrator.tui.modals import ModePicker, QuestionModal
from audit_orchestrator.runner import now_iso
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
        self._mode: str | None = None

    def compose(self) -> ComposeResult:
        yield Header()
        yield ProgressPanel()
        yield NowRunningPanel()
        yield ActivityLog()
        yield QuestionPanel(classes="hidden")
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

        try:
            questions_dir = self.work_dir / "questions"
            pending = list(questions_dir.glob("*.md")) if questions_dir.exists() else []
            qp = self.query_one(QuestionPanel)
            qp.pending_count = len(pending)
            if pending:
                qp.pending_summary = ", ".join(p.stem for p in pending[:3])
        except Exception:
            pass

    def on_click(self, event) -> None:
        """Open question modal when QuestionPanel is clicked."""
        target = event.widget
        while target is not None:
            if isinstance(target, QuestionPanel):
                self._handle_question_click()
                return
            target = target.parent

    def _handle_question_click(self) -> None:
        import re
        questions_dir = self.work_dir / "questions"
        pending = list(questions_dir.glob("*.md"))
        if not pending:
            return
        qfile = pending[0]
        raw = qfile.read_text(encoding="utf-8")

        # Parse frontmatter to recover the original item_id (handles # vs B prefix correctly)
        item_id = qfile.stem  # fallback if frontmatter missing
        body = raw
        if raw.startswith("---\n"):
            _, _, rest = raw.partition("---\n")[2].partition("---\n")
            body = rest.strip()
            m = re.search(r"^item_id:\s*(\S+)", raw, flags=re.MULTILINE)
            if m:
                item_id = m.group(1)

        def handle(answer: str | None) -> None:
            if answer == "__SKIP__":
                qfile.unlink()
                self.notify(f"Skipped {item_id}")
            elif answer:
                with qfile.open("a", encoding="utf-8") as f:
                    f.write(f"\n\n## Answer (answered_at: {now_iso()})\n{answer}\n")
                self.notify(f"Answer saved for {item_id} — runner will resume")

        self.push_screen(QuestionModal(item_id=item_id, question_body=body), handle)

    def action_help(self) -> None:
        self.notify("Help screen TBD")

    def action_queue(self) -> None:
        self.notify("Queue browser TBD")

    def action_mode(self) -> None:
        def handle(result: str | None) -> None:
            if result:
                self.notify(f"Mode set: {result}")
                self._mode = result
        self.push_screen(ModePicker(), handle)

    def action_pause(self) -> None:
        self.notify("Pause TBD")
