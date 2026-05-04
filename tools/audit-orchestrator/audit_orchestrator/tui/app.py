"""Textual application root for the audit orchestrator TUI."""
from __future__ import annotations
import subprocess
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
        ("r", "reconcile", "Reconcile"),
        ("w", "watch_terminal", "Watch in Terminal"),
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

        # Sweep the queue: drop items whose status is already done/blocked,
        # so a stale leftover doesn't block the runner from making progress.
        removed = self._cleanup_stale_queue()

        self._refresh_panels()

        if removed:
            self._log(
                f"[dim]Queue swept: removed {len(removed)} stale items "
                f"({', '.join(removed[:5])}{'...' if len(removed) > 5 else ''})[/dim]"
            )

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
            # A question is "pending" only if it doesn't yet have an answer
            # appended. Once the runner consumes the answer (detected by
            # "## Answer" in the file), the file stays on disk for history
            # but should disappear from the pending count + summary.
            pending = []
            if questions_dir.exists():
                for f in questions_dir.glob("*.md"):
                    try:
                        if "## Answer" not in f.read_text(encoding="utf-8"):
                            pending.append(f)
                    except Exception:
                        pending.append(f)  # fallback: count it if unreadable
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
        # Only open files without an answer yet — matches the panel's
        # pending count (see _refresh_panels). Otherwise clicking the panel
        # right after writing an answer via the file would re-open the modal
        # for an already-answered question.
        pending = []
        for f in questions_dir.glob("*.md"):
            try:
                if "## Answer" not in f.read_text(encoding="utf-8"):
                    pending.append(f)
            except Exception:
                pending.append(f)
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
            # Clear any prior pause flag so the runner can actually start
            self._stop_runner.clear()
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

            # Defensive: if item is already done/blocked but somehow still in
            # the queue (e.g. user re-queued via UI, stale state from a crashed
            # earlier run, or _mark didn't fire due to an artifact-write error),
            # don't re-run it. Pop and move on.
            current_status = item.get("status")
            if current_status in ("done", "blocked"):
                self._log_from_thread(
                    f"  [dim]{next_id}[/dim] already [yellow]{current_status}[/yellow] — popping from queue"
                )
                state.queue = [q for q in state.queue if q != next_id]
                self.state_mgr.save(state)
                continue

            # If item is awaiting an answer, check whether one has arrived
            if item.get("status") == "awaiting_answer":
                qfile = self.work_dir / "questions" / f"{next_id.lstrip('#')}.md"
                if qfile.exists() and "## Answer" in qfile.read_text(encoding="utf-8"):
                    answer = qfile.read_text(encoding="utf-8").split("## Answer")[-1].strip()
                    self._log_from_thread(f"Resuming {next_id} with answer.")
                    outcome = runner.resume(
                        next_id, answer,
                        on_step_change=self._make_on_step(next_id),
                        on_action=self._make_on_action(next_id),
                        should_stop=lambda: self._stop_runner.is_set(),
                    )
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

            self._kickoff_banner(next_id, item, mode)
            outcome = runner.run_one(
                next_id, mode=mode,
                on_step_change=self._make_on_step(next_id),
                on_action=self._make_on_action(next_id),
                should_stop=lambda: self._stop_runner.is_set(),
            )
            self._end_summary(next_id, item, outcome, runner)
            if outcome == "skipped":
                state = self.state_mgr.load()
                state.queue = [q for q in state.queue if q != next_id]
                self.state_mgr.save(state)
            elif outcome == "interrupted":
                # Item stays in queue for resume — but if user pressed pause,
                # break the loop so the runner stops processing the queue too.
                if self._stop_runner.is_set():
                    self._log_from_thread("[dim]Runner paused. Press F3 to resume.[/dim]")
                    break
                # Otherwise (usage limit hit but no manual pause): also stop;
                # there's no point trying the next item if we're rate-limited.
                self._log_from_thread(
                    "[yellow]Stopping queue — usage limit hit. Press F3 to resume later.[/yellow]"
                )
                break

    def _kickoff_banner(self, item_id: str, item: dict, mode) -> None:
        """Log a multi-line header summarising what's about to run."""
        title = item.get("title", "")
        is_bundle = bool(item.get("is_bundle"))
        members = item.get("members") or []
        source = item.get("source", "?")
        effort = item.get("effort", "?")

        kind = "BUNDLE" if is_bundle else "ITEM"
        self._log_from_thread("")
        self._log_from_thread(f"[bold cyan]━━━ {kind} {item_id} ━━━[/bold cyan]")
        self._log_from_thread(f"[bold]{title}[/bold]")
        self._log_from_thread(f"[dim]source: {source}  ·  effort: {effort}  ·  mode: {mode.value}[/dim]")
        if is_bundle and members:
            self._log_from_thread(f"[dim]members ({len(members)}): {', '.join(members)}[/dim]")
        self._log_from_thread(
            f"[dim]live log: tail -f .audit-work/logs/{item_id.lstrip('#')}.jsonl  ·  press [b]w[/b] for terminal[/dim]"
        )

    def _end_summary(self, item_id: str, item: dict, outcome: str, runner) -> None:
        """Log a multi-line footer summarising what happened."""
        emoji = {
            "pushed": "✅", "blocked": "⚠️ ",
            "awaiting_answer": "❓", "skipped": "⏭ ",
            "interrupted": "⏸ ",
        }.get(outcome, "·")

        if outcome == "pushed":
            # Get the most recent commit's files + sha
            try:
                files = subprocess.run(
                    ["git", "show", "--name-only", "--pretty=format:", "HEAD"],
                    cwd=self.repo_root, capture_output=True, text=True, check=True,
                ).stdout.strip().splitlines()
                files = [f for f in files if f.strip()]
                sha = subprocess.run(
                    ["git", "rev-parse", "--short", "HEAD"],
                    cwd=self.repo_root, capture_output=True, text=True, check=True,
                ).stdout.strip()
            except Exception:
                files, sha = [], "?"

            self._log_from_thread(f"{emoji} [green]{item_id} → pushed[/green] (commit {sha})")
            if files:
                self._log_from_thread(f"   files changed ({len(files)}):")
                for f in files[:8]:
                    marker = "🗄️ " if "/migrations/" in f else "  "
                    self._log_from_thread(f"   {marker}{f}")
                if len(files) > 8:
                    self._log_from_thread(f"   ... and {len(files) - 8} more")

            # Migration warning
            migrations = [f for f in files if "/migrations/" in f and f.endswith(".sql")]
            if migrations:
                self._log_from_thread(
                    f"[yellow]⚠️  Migration created — orchestrator did NOT apply it. "
                    f"Run [b]supabase db push[/b] to apply to remote DB.[/yellow]"
                )

        elif outcome == "blocked":
            self._log_from_thread(f"{emoji} [yellow]{item_id} → blocked[/yellow]")
            self._log_from_thread(
                f"   diff:  .audit-work/blocked/{item_id.lstrip('#')}.patch"
            )
            self._log_from_thread(
                f"   log:   .audit-work/blocked/{item_id.lstrip('#')}.log"
            )

        elif outcome == "awaiting_answer":
            self._log_from_thread(f"{emoji} [blue]{item_id} → awaiting answer[/blue] — click question panel below")

        elif outcome == "interrupted":
            # Pull the reason from state so the message reflects pause vs limit
            state = self.state_mgr.load()
            reason = state.items.get(item_id, {}).get("interrupted_reason", "?")
            label = {
                "user_paused": "paused by user",
                "usage_limited": "hit usage limit",
                "claude_error": "Claude reported an error",
            }.get(reason, reason)
            self._log_from_thread(
                f"{emoji} [cyan]{item_id} → interrupted[/cyan] ({label}) — stays in queue, F3 to resume"
            )

        else:
            self._log_from_thread(f"{emoji} {item_id} → {outcome}")
        self._log_from_thread("")

    def _make_on_step(self, item_id: str):
        """Build a callback that updates NowRunningPanel.step. Stage-monotonic
        per stream_parser, so it won't bounce back to 'planning'.
        """
        last = {"stage": None}

        def on_step(event_kind):
            stage = event_kind.value if event_kind is not None else None
            if stage is None or stage == last["stage"]:
                return
            last["stage"] = stage
            try:
                self.call_from_thread(self._set_running_step, stage)
            except Exception:
                pass

        return on_step

    def _set_running_step(self, stage: str) -> None:
        try:
            self.query_one(NowRunningPanel).step = stage
        except Exception:
            pass

    def _cleanup_stale_queue(self) -> list[str]:
        """Remove already-done or already-blocked items from state.queue.
        Interrupted items (paused / usage-limited) are KEPT — they're
        meant to be resumed.

        Returns the list of removed ids (for logging).
        """
        state = self.state_mgr.load()
        if not state.queue:
            return []
        removed: list[str] = []
        kept: list[str] = []
        for qid in state.queue:
            status = state.items.get(qid, {}).get("status")
            if status in ("done", "blocked"):
                removed.append(f"{qid}({status})")
            else:
                kept.append(qid)
        if removed:
            state.queue = kept
            self.state_mgr.save(state)
        return removed

    def _make_on_action(self, item_id: str):
        """Build a callback that logs every tool call's snippet to the activity
        log, with a per-file dedup window so we don't spam re-reads of the same file.
        """
        recent: list[str] = []  # last few snippets for dedup

        def on_action(detail):
            snippet = detail.snippet
            # Dedup: skip if we just logged the exact same snippet 1-3 lines ago
            if snippet in recent[-3:]:
                return
            recent.append(snippet)
            self._log_from_thread(f"  [dim]{item_id}[/dim]  {snippet}")

        return on_action

    def action_reconcile(self) -> None:
        """Walk state.items; promote anything to done if its markdown checkbox
        is now [x] (or, for bundles, every member is [x]). Same logic as the
        `audit-orch reconcile` CLI command."""
        from audit_orchestrator.queue_ops import parse_all
        from audit_orchestrator.models import ItemStatus

        try:
            parse_results = parse_all(self._config, self.repo_root)
            item_done: dict[str, bool] = {}
            bundle_members: dict[str, list[str]] = {}
            for r in parse_results:
                for it in r.items:
                    item_done[it.id] = (it.status == ItemStatus.DONE)
                for b in r.bundles:
                    bundle_members[b.id] = list(b.members)

            state = self.state_mgr.load()
            promoted: list[str] = []
            for sid, sitem in state.items.items():
                if sitem.get("status") == "done":
                    continue
                if sitem.get("is_bundle"):
                    members = sitem.get("members") or bundle_members.get(sid, [])
                    if members and all(item_done.get(m, False) for m in members):
                        sitem["status"] = "done"
                        promoted.append(sid)
                else:
                    if item_done.get(sid):
                        sitem["status"] = "done"
                        promoted.append(sid)
            self.state_mgr.save(state)

            if promoted:
                self._log(f"[green]✅ Reconciled — promoted {len(promoted)} to done:[/green]")
                for p in promoted:
                    self._log(f"   ✅ {p}")
                self.notify(f"Reconciled {len(promoted)} items")
            else:
                self._log("[dim]Reconcile: state already matches markdown — nothing to promote.[/dim]")
                self.notify("Nothing to reconcile")
        except Exception as e:
            self._log(f"[red]Reconcile failed: {e}[/red]")
            self.notify(f"Reconcile failed: {e}")

    def action_watch_terminal(self) -> None:
        """Open a new Terminal.app window tailing the current run's log."""
        state = self.state_mgr.load()
        run = state.current_run
        if not run:
            self.notify("No item is currently running")
            return
        item_id = run.get("id", "")
        log_path = self.work_dir / "logs" / f"{item_id.lstrip('#')}.jsonl"
        if not log_path.exists():
            self.notify(f"No log file yet for {item_id}")
            return
        # Use AppleScript to open Terminal.app with `tail -f` (pipes through jq
        # if available for prettier output, falls back to plain tail otherwise).
        cmd = (
            f"if command -v jq >/dev/null 2>&1; then "
            f"tail -f {log_path} | jq -c '. | {{type, subtype, msg: .message.content[0]?.name // .message.content[0]?.text}}'; "
            f"else tail -f {log_path}; fi"
        )
        script = (
            f'tell application "Terminal" to do script "{cmd}"\n'
            f'tell application "Terminal" to activate'
        )
        try:
            subprocess.Popen(["osascript", "-e", script])
            self.notify(f"Opened Terminal tailing {item_id}")
        except Exception as e:
            self.notify(f"Failed to open Terminal: {e}")

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
