"""Textual application root for the audit orchestrator TUI."""
from __future__ import annotations
import threading
from pathlib import Path
from textual.app import App, ComposeResult
from textual.widgets import Header, Footer, Static

from audit_orchestrator.tui.panels import (
    ProgressPanel, NowRunningPanel, ActivityLog, QuestionPanel,
)
from audit_orchestrator.tui.modals import ModePicker, QuestionModal, HelpModal, QueueBrowser
from audit_orchestrator.runner import Runner, RunMode, now_iso
from audit_orchestrator.config import load_config
from audit_orchestrator.state import StateManager
from audit_orchestrator.tui.watcher import watch, watch_glob


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
        yield QuestionPanel(classes="hidden")
        yield Footer()

    def on_mount(self) -> None:
        self._mode: str | None = None
        self._runner_thread: threading.Thread | None = None
        self._stop_runner = threading.Event()
        self._config = load_config(self.work_dir / "config.yml")

        self._refresh_panels()

        # Watch state.json for queue/status changes
        self._state_observer = watch(
            self.work_dir / "state.json",
            lambda: self.call_from_thread(self._refresh_panels),
        )
        # Watch repo root for new/edited audit files (auto-discovers
        # pilot-*.md and audit-*.md added during the session, and detects
        # when a user ticks a checkbox in an existing audit markdown)
        self._audit_observer = watch_glob(
            self.repo_root,
            ["pilot-*.md", "audit-*.md"],
            lambda: self.call_from_thread(self._refresh_panels),
        )
        self._log("TUI ready. Press F1 for help, F2 to browse, F3 to pick a mode.")

    def on_unmount(self) -> None:
        for attr in ("_state_observer", "_audit_observer"):
            obs = getattr(self, attr, None)
            if obs is not None:
                try:
                    obs.stop()
                    obs.join(timeout=1)
                except Exception:
                    pass

    def _refresh_panels(self) -> None:
        from audit_orchestrator.parser import parse_audit_file
        from audit_orchestrator.models import ItemStatus
        from audit_orchestrator.tui.modals import _gather_sources, _short_source

        state = self.state_mgr.load()

        # Progress panel: per-source done/total from re-parsed markdown
        try:
            detail = self.query_one("#progress-detail", Static)
            sources = _gather_sources(self._config, self.repo_root)
            if not sources:
                detail.update(f"No audit files found · queue: {len(state.queue)}")
            else:
                lines = []
                for p in sources:
                    r = parse_audit_file(p)
                    done = sum(1 for i in r.items if i.status == ItemStatus.DONE)
                    total = len(r.items) or 1
                    pct = int(100 * done / total)
                    lines.append(f"{_short_source(r.source_filename):>5}: {done:>3}/{total:<3} ({pct}%)")
                lines.append(f"queue: {len(state.queue)}")
                detail.update("\n".join(lines))
        except Exception as e:
            try:
                self.query_one("#progress-detail", Static).update(f"(progress error: {e})")
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
        self.push_screen(HelpModal())

    def action_queue(self) -> None:
        self.push_screen(QueueBrowser(work_dir=self.work_dir, repo_root=self.repo_root))

    def action_mode(self) -> None:
        def handle(result: str | None) -> None:
            if result is None:
                return
            self._mode = result
            self._log(f"Mode set: {result}. Starting runner...")
            self._start_runner()
        self.push_screen(ModePicker(), handle)

    def _start_runner(self) -> None:
        if self._runner_thread is not None and self._runner_thread.is_alive():
            self._log("Runner already running.")
            return
        self._stop_runner.clear()
        self._runner_thread = threading.Thread(target=self._runner_loop, daemon=True)
        self._runner_thread.start()

    def _runner_loop(self) -> None:
        from audit_orchestrator.queue_ops import populate_item_metadata, parse_all

        runner = Runner(work_dir=self.work_dir, repo_root=self.repo_root, config=self._config)
        mode = RunMode(self._mode) if self._mode else RunMode.OVERNIGHT
        while not self._stop_runner.is_set():
            state = self.state_mgr.load()
            if not state.queue:
                self._log_from_thread("Queue empty. Runner idle.")
                break
            next_id = state.queue[0]

            # Safety net: if metadata is missing (e.g. legacy queue from before
            # the queue_ops fix), try to lazily populate. If still missing, drop
            # it from the queue rather than infinite-looping on "skipped".
            if next_id not in state.items:
                parse_results = parse_all(self._config, self.repo_root)
                if not populate_item_metadata(state, next_id, parse_results):
                    self._log_from_thread(
                        f"{next_id} not found in any audit source — removing from queue"
                    )
                    state.queue = [q for q in state.queue if q != next_id]
                    self.state_mgr.save(state)
                    continue
                self.state_mgr.save(state)

            item = state.items.get(next_id, {})

            # If item is awaiting an answer, check whether one has arrived
            if item.get("status") == "awaiting_answer":
                qfile = self.work_dir / "questions" / f"{next_id.lstrip('#')}.md"
                if qfile.exists() and "## Answer" in qfile.read_text(encoding="utf-8"):
                    answer = qfile.read_text(encoding="utf-8").split("## Answer")[-1].strip()
                    self._log_from_thread(f"Resuming {next_id} with answer.")
                    outcome = runner.resume(next_id, answer)
                    self._log_from_thread(f"{next_id} → {outcome}")
                    if outcome == "skipped":
                        self._log_from_thread(
                            f"{next_id} skipped despite being known — removing from queue"
                        )
                        state = self.state_mgr.load()
                        state.queue = [q for q in state.queue if q != next_id]
                        self.state_mgr.save(state)
                    continue
                else:
                    if mode == RunMode.WORK:
                        self._log_from_thread(f"{next_id} is awaiting your answer.")
                        break
                    else:
                        # Overnight: rotate this item to the back, work on the next
                        state.queue = state.queue[1:] + [next_id]
                        self.state_mgr.save(state)
                        continue

            self._log_from_thread(f"Starting {next_id}...")
            outcome = runner.run_one(next_id, mode=mode)
            self._log_from_thread(f"{next_id} → {outcome}")
            # Defensive: if runner reports skipped, pop the item to avoid loop
            if outcome == "skipped":
                state = self.state_mgr.load()
                state.queue = [q for q in state.queue if q != next_id]
                self.state_mgr.save(state)

    def _log(self, msg: str) -> None:
        try:
            self.query_one(ActivityLog).write(msg)
        except Exception:
            pass

    def _log_from_thread(self, msg: str) -> None:
        try:
            self.call_from_thread(self._log, msg)
        except Exception:
            pass

    def action_pause(self) -> None:
        self._stop_runner.set()
        self._log("Pause requested. Runner will stop after current item.")
