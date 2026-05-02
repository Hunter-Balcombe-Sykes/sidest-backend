"""Per-item runner — spawns claude, tracks state, applies push rule."""
from __future__ import annotations
import json
import subprocess
from datetime import datetime, timezone
from enum import Enum
from pathlib import Path
from typing import Iterator

from audit_orchestrator.config import Config
from audit_orchestrator.state import StateManager, State
from audit_orchestrator.stream_parser import StreamEventTracker
from audit_orchestrator.prompts import render_item_prompt, render_resume_prompt
from audit_orchestrator.git_utils import (
    pre_push_check, push_to_remote, discard_working_changes, tick_checkbox_for_item,
)
from audit_orchestrator.completion import (
    write_completion_record, write_blocked_record, CompletionContext,
)


class RunMode(str, Enum):
    WORK = "work"
    OVERNIGHT = "overnight"


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def run_test_command(repo: Path, command: str) -> bool:
    """Run the configured test command. Returns True if exit code 0."""
    proc = subprocess.run(command, shell=True, cwd=repo, capture_output=True)
    return proc.returncode == 0


class Runner:
    def __init__(self, *, work_dir: Path, repo_root: Path, config: Config):
        self.work_dir = work_dir
        self.repo_root = repo_root
        self.config = config
        self.state_mgr = StateManager(work_dir / "state.json")
        self.questions_dir = work_dir / "questions"
        self.blocked_dir = work_dir / "blocked"
        self.completed_dir = work_dir / "completed"
        self.logs_dir = work_dir / "logs"
        self.questions_dir.mkdir(parents=True, exist_ok=True)
        self.blocked_dir.mkdir(parents=True, exist_ok=True)
        self.completed_dir.mkdir(parents=True, exist_ok=True)
        self.logs_dir.mkdir(parents=True, exist_ok=True)

    def run_one(
        self, item_id: str, *, mode: RunMode,
        on_step_change=None,
        on_action=None,
    ) -> str:
        """Run one queue item end-to-end. Returns outcome string.

        Callbacks (called on the runner thread):
          on_step_change(EventKind | None) — fired on monotonic stage transitions
          on_action(ToolCallDetail) — fired for every tool use with file/cmd detail
        """
        state = self.state_mgr.load()
        item = state.items.get(item_id)
        if item is None:
            return "skipped"

        item["status"] = "running"
        state.current_run = {"id": item_id, "started_at": now_iso(), "mode": mode.value}
        self.state_mgr.save(state)

        question_file = self.questions_dir / f"{_safe_id(item_id)}.md"
        completion_file = self.completed_dir / f"{_safe_id(item_id)}.md"
        prompt = render_item_prompt(
            item_id=item_id,
            item_body=item.get("body_markdown", ""),
            question_file_path=question_file,
            completion_file_path=completion_file,
            test_command=self.config.test_command,
            repo_root=self.repo_root,
        )

        tracker = self._spawn_claude(
            prompt, item_id=item_id,
            on_step_change=on_step_change, on_action=on_action,
        )
        return self._handle_exit(item_id, tracker, question_file)

    def resume(self, item_id: str, answer: str, *, on_step_change=None, on_action=None) -> str:
        """Resume a session with the user's answer. Returns outcome string."""
        state = self.state_mgr.load()
        item = state.items.get(item_id)
        if item is None or not item.get("session_id"):
            return "skipped"

        prompt = render_resume_prompt(answer=answer)
        tracker = self._spawn_claude(
            prompt, item_id=item_id, resume_id=item["session_id"],
            on_step_change=on_step_change, on_action=on_action,
        )
        question_file = self.questions_dir / f"{_safe_id(item_id)}.md"
        return self._handle_exit(item_id, tracker, question_file)

    def _spawn_claude(
        self, prompt: str, *,
        item_id: str,
        resume_id: str | None = None,
        on_step_change=None,
        on_action=None,
    ) -> StreamEventTracker:
        cmd = ["claude", "--print", "--model", self.config.claude_model,
               "--allowedTools", ",".join(self.config.allowed_tools),
               "--output-format", "stream-json", "--verbose"]
        if resume_id:
            cmd.extend(["--resume", resume_id])
        cmd.extend(self.config.claude_extra_args)
        cmd.append(prompt)

        # Tee raw stream-json to .audit-work/logs/<id>.jsonl so users can
        # `tail -f` it from any terminal for live visibility.
        log_path = self.logs_dir / f"{_safe_id(item_id)}.jsonl"
        tracker = StreamEventTracker()
        proc = subprocess.Popen(cmd, cwd=self.repo_root, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, text=True, bufsize=1)
        prev_event = None
        with log_path.open("a", encoding="utf-8") as logf:
            if proc.stdout is not None:
                for line in proc.stdout:
                    logf.write(line)
                    logf.flush()
                    detail = tracker.feed_line(line)
                    if detail is not None and on_action is not None:
                        try:
                            on_action(detail)
                        except Exception:
                            pass
                    if on_step_change is not None and tracker.last_event != prev_event:
                        prev_event = tracker.last_event
                        try:
                            on_step_change(tracker.last_event)
                        except Exception:
                            pass
        proc.wait()
        tracker.exit_code = proc.returncode
        return tracker

    def _handle_exit(self, item_id: str, tracker: StreamEventTracker, question_file: Path) -> str:
        state = self.state_mgr.load()
        item = state.items.get(item_id, {})

        # Check if Claude wrote a question file before running tests
        if question_file.exists():
            self._wrap_question_file(question_file, item_id, tracker.session_id or "")
            self._mark(item_id, status="awaiting_answer", session_id=tracker.session_id,
                       question_file=str(question_file))
            return "awaiting_answer"

        # Claude exited normally → run tests
        tests_pass = run_test_command(self.repo_root, self.config.test_command)
        if not tests_pass:
            try:
                log_path = self.blocked_dir / f"{_safe_id(item_id)}.log"
                log_excerpt = log_path.read_text(encoding="utf-8") if log_path.exists() else "test failure"
                self._save_blocked_artifacts(item_id, reason="test_failure")
                # discard_working_changes can throw if git is in a weird state;
                # don't let it skip the _mark call below
                try:
                    discard_working_changes(self.repo_root)
                except Exception:
                    pass
                write_blocked_record(
                    self.completed_dir, item_id=item_id,
                    title=item.get("title", ""), source=item.get("source", ""),
                    reason="composer test failed", log_excerpt=log_excerpt,
                )
            except Exception:
                pass  # artifact-saving best-effort; _mark below is what matters
            self._mark(item_id, status="blocked", blocked_reason="tests failed")
            return "blocked"

        # Pre-push safety check
        ppc = pre_push_check(self.repo_root, item_id=item_id, base_ref=f"origin/{self.config.push_target}")
        if not ppc.ok:
            self._save_blocked_artifacts(item_id, reason=f"pre_push: {ppc.reason}")
            write_blocked_record(
                self.completed_dir, item_id=item_id,
                title=item.get("title", ""), source=item.get("source", ""),
                reason="pre-push safety check failed", log_excerpt=ppc.reason,
            )
            self._mark(item_id, status="blocked", blocked_reason=f"pre-push: {ppc.reason}")
            return "blocked"

        # Capture files-touched BEFORE pushing (so we can report what was in the commit)
        files_touched = self._files_in_commit(ppc.commit_sha)

        # Push and mark done
        try:
            push_to_remote(self.repo_root, branch=self.config.push_target)
        except RuntimeError as e:
            self._mark(item_id, status="blocked", blocked_reason=f"push failed: {e}")
            return "blocked"

        # IMPORTANT: write_completion_record FIRST (before any audit-trail commit).
        # It overwrites the completion file with our frontmatter + Q&A version,
        # so it must finalize before we stage and commit the file. Doing this
        # AFTER the commit would leave a tracked-file modification that would
        # block the next run's pre-push check.
        question_files = [question_file] if question_file.exists() else []
        write_completion_record(
            self.completed_dir,
            CompletionContext(
                item_id=item_id,
                title=item.get("title", ""),
                source=item.get("source", ""),
                tier=item.get("tier", ""),
                effort_estimate=item.get("effort", ""),
                mode=state.current_run.get("mode", "unknown") if state.current_run else "unknown",
                commit_sha=ppc.commit_sha or "",
                files_touched=files_touched,
                test_result="pass",
                question_files=question_files,
            ),
        )

        # Tick checkboxes in source markdown — for a bundle, tick every member;
        # for a standalone item, tick the item itself.
        source = item.get("source")
        ticked: list[str] = []
        if source:
            source_path = self.repo_root / source
            if item.get("is_bundle"):
                for member_id in item.get("members", []):
                    if tick_checkbox_for_item(source_path, member_id):
                        ticked.append(member_id)
            else:
                if tick_checkbox_for_item(source_path, item_id):
                    ticked.append(item_id)

        # Commit BOTH the (now-finalized) completion record AND the markdown
        # ticks together. Always runs, even if no ticks happened, so the
        # completion record always lands in git history.
        try:
            self._commit_and_push_audit_trail(item_id, source, ticked)
        except Exception:
            pass  # Best-effort; the next run's pre-push will retry on the dirty file

        self._mark(item_id, status="done", completed_at=now_iso(), session_id=tracker.session_id)
        return "pushed"

    def _commit_and_push_audit_trail(
        self, item_id: str, source: str | None, ticked_ids: list[str],
    ) -> None:
        """Stage the completion record + any ticked-markdown changes, commit
        them as a `chore(audit): ...` commit, and push. Always runs after a
        successful fix push so the working tree is clean for the next run.

        - Completion record (`.audit-work/completed/<id>.md`): always staged
          if it exists. Designed to be a durable per-fix audit trail.
        - Source markdown: only staged if `source` is set (we always try, but
          if nothing actually changed git diff --cached will report empty).
        """
        # Stage the completion record (durable audit trail per spec)
        completion_path = self.completed_dir / f"{_safe_id(item_id)}.md"
        if completion_path.exists():
            try:
                subprocess.run(
                    ["git", "add", str(completion_path)],
                    cwd=self.repo_root, check=True, capture_output=True,
                )
            except subprocess.CalledProcessError:
                pass  # ignored or otherwise unstageable

        # Stage the audit markdown (if a source is set)
        if source:
            try:
                subprocess.run(
                    ["git", "add", source],
                    cwd=self.repo_root, check=True, capture_output=True,
                )
            except subprocess.CalledProcessError:
                pass

        # Nothing actually changed? skip
        diff_check = subprocess.run(
            ["git", "diff", "--cached", "--quiet"], cwd=self.repo_root,
        )
        if diff_check.returncode == 0:
            return

        # Build a clear commit message
        if ticked_ids and source:
            msg = (
                f"chore(audit): mark {item_id} done + completion record\n\n"
                f"Ticks {', '.join(ticked_ids)} in {source} and commits the "
                f"per-fix audit trail after the orchestrator-completed fix."
            )
        else:
            msg = (
                f"chore(audit): completion record for {item_id}\n\n"
                f"Commits the per-fix audit trail "
                f"({completion_path.relative_to(self.repo_root) if completion_path.exists() else 'see .audit-work/'})."
            )
        subprocess.run(
            ["git", "commit", "-m", msg], cwd=self.repo_root, check=True, capture_output=True,
        )
        try:
            push_to_remote(self.repo_root, branch=self.config.push_target)
        except RuntimeError:
            pass

    def _files_in_commit(self, sha: str | None) -> list[str]:
        """Return list of paths touched by a commit (using git show --name-only)."""
        if not sha:
            return []
        try:
            proc = subprocess.run(
                ["git", "show", "--name-only", "--pretty=format:", sha],
                cwd=self.repo_root, capture_output=True, text=True, check=True,
            )
            return [line.strip() for line in proc.stdout.splitlines() if line.strip()]
        except subprocess.CalledProcessError:
            return []

    def _wrap_question_file(self, path: Path, item_id: str, session_id: str) -> None:
        """Prepend YAML frontmatter to a Claude-written question file."""
        body = path.read_text(encoding="utf-8")
        if body.startswith("---\n"):
            return  # already wrapped
        wrapped = (
            f"---\nitem_id: {item_id}\nsession_id: {session_id}\n"
            f"written_at: {now_iso()}\n---\n\n{body}"
        )
        path.write_text(wrapped, encoding="utf-8")

    def _save_blocked_artifacts(self, item_id: str, *, reason: str) -> None:
        diff = subprocess.run(
            ["git", "diff"], cwd=self.repo_root, capture_output=True, text=True,
        ).stdout
        (self.blocked_dir / f"{_safe_id(item_id)}.patch").write_text(diff, encoding="utf-8")
        (self.blocked_dir / f"{_safe_id(item_id)}.log").write_text(reason, encoding="utf-8")

    def _mark(self, item_id: str, **fields) -> None:
        def mutate(state: State) -> None:
            item = state.items.setdefault(item_id, {})
            for k, v in fields.items():
                item[k] = v
            if fields.get("status") in ("done", "blocked"):
                state.queue = [q for q in state.queue if q != item_id]
                state.current_run = None
                state.history.append({
                    "id": item_id, "ended_at": now_iso(),
                    "outcome": fields.get("status"),
                })
        self.state_mgr.update(mutate)


def _safe_id(item_id: str) -> str:
    """Strip leading # for filesystem-safe path component."""
    return item_id.lstrip("#")
