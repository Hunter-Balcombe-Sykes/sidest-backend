"""Modal screens for the TUI."""
from __future__ import annotations
from pathlib import Path

from textual.app import ComposeResult
from textual.binding import Binding
from textual.containers import Horizontal, Vertical, VerticalScroll
from textual.screen import ModalScreen
from textual.widgets import Button, Static, Input, Markdown, DataTable

from audit_orchestrator.classifier import (
    ClassifierContext, classify_item, classify_bundle,
)
from audit_orchestrator.config import load_config
from audit_orchestrator.models import Classification
from audit_orchestrator.parser import parse_audit_file
from audit_orchestrator.state import StateManager


class ModePicker(ModalScreen[str]):
    """Returns 'work' or 'overnight' (or None if cancelled)."""

    def compose(self) -> ComposeResult:
        yield Vertical(
            Static("Pick a run mode", id="mode-title"),
            Horizontal(
                Button("Work Mode (interactive questions)", id="mode-work", variant="primary"),
                Button("Overnight Mode (queue questions for morning)", id="mode-overnight"),
            ),
            id="mode-dialog",
        )

    def on_button_pressed(self, event: Button.Pressed) -> None:
        if event.button.id == "mode-work":
            self.dismiss("work")
        elif event.button.id == "mode-overnight":
            self.dismiss("overnight")


class QuestionModal(ModalScreen[str | None]):
    """Show a pending question + accept the user's answer."""

    def __init__(self, item_id: str, question_body: str) -> None:
        super().__init__()
        self.item_id = item_id
        self.question_body = question_body

    def compose(self) -> ComposeResult:
        yield Vertical(
            Static(f"Question for {self.item_id}", id="q-title"),
            Static(self.question_body, id="q-body"),
            Input(placeholder="Type your answer and press Enter to submit...", id="q-input"),
            Horizontal(
                Button("Submit", id="q-submit", variant="primary"),
                Button("Skip Item", id="q-skip"),
                Button("Cancel", id="q-cancel"),
            ),
            id="q-dialog",
        )

    def on_button_pressed(self, event: Button.Pressed) -> None:
        if event.button.id == "q-submit":
            answer = self.query_one("#q-input", Input).value
            self.dismiss(answer if answer.strip() else None)
        elif event.button.id == "q-skip":
            self.dismiss("__SKIP__")
        elif event.button.id == "q-cancel":
            self.dismiss(None)

    def on_input_submitted(self, event: Input.Submitted) -> None:
        if event.input.id == "q-input":
            self.dismiss(event.value if event.value.strip() else None)


HELP_MARKDOWN = """\
# Audit Orchestrator — Help

## What is this?

A TUI that drives unattended Claude Code fix sessions over your audit
checklists (`pilot-stage-*.md`, `audit-*.md`).

## Getting started — 3 steps

### 1. Queue items from another terminal

```
audit-orch suggest --count 8     # see what's recommended
audit-orch add B5 B10            # queue specific items/bundles
audit-orch add suggest           # queue the recommended batch
audit-orch queue                 # show what's queued
audit-orch clear                 # empty the queue
```

### 2. Pick a mode in this TUI

Press **F3**. Two choices:

- **Work Mode** — runs alongside your other work; pings you when Claude
  needs a question answered. Click the blue panel to answer.
- **Overnight Mode** — fires-and-forgets; questions queue silently for
  morning review.

### 3. Watch

Runner processes items one at a time. Activity log shows progress.
The Question panel (blue) appears when Claude needs you.

## What the panels show

- **Audit Progress** — total done across all audits + current queue length
- **Now Running** — current item ID + step (planning / editing / testing)
- **Activity Log** — recent events, scrollable
- **Question Pending** (blue, only when active) — click to answer

## Keybinds

| Key | Action |
|---|---|
| F1 | This help screen |
| F2 | Queue browser *(not yet implemented)* |
| F3 | Pick mode + start runner |
| F5 | Pause (stops after current item) |
| q  | Quit |
| Esc | Close this help / dismiss modals |

## Where things live

- `.audit-work/state.json` — queue + per-item status
- `.audit-work/config.yml` — settings (push target, model, classifier)
- `.audit-work/questions/<id>.md` — questions Claude wrote during a run
- `.audit-work/blocked/<id>.{patch,log}` — failed-test diffs for review
- `.audit-work/completed/<id>.md` — per-fix audit trail (Plain English +
  Technical Summary + Decisions + Questions Asked)

## Push behavior

If `composer test` passes after a fix, the commit is pushed straight
to `development-v2`. If tests fail, no commit; the diff goes to
`.audit-work/blocked/` for morning review.

## Tips

- The TUI is a **monitor + driver**, not an item browser. Use
  `audit-orch suggest` in another terminal to see what's recommended.
- F2 (queue browser) is on the TBD list — for now use the CLI.
- To kill the runner: F5 (graceful — finishes current item) or
  Ctrl+C (hard, may leave state mid-write).
- Set `ANTHROPIC_API_KEY` in your shell to bill orchestrator-spawned
  Claude sessions to API instead of your Max quota.

Press **Esc** to close this help.
"""


class HelpModal(ModalScreen[None]):
    """Scrollable help screen. Dismiss with Esc or q."""

    BINDINGS = [
        Binding("escape", "close", "Close"),
        Binding("q", "close", "Close"),
    ]

    def compose(self) -> ComposeResult:
        yield Vertical(
            VerticalScroll(
                Markdown(HELP_MARKDOWN),
                id="help-scroll",
            ),
            Static("Press Esc or q to close", id="help-footer"),
            id="help-dialog",
        )

    def action_close(self) -> None:
        self.dismiss(None)


# Helpers for QueueBrowser. Duplicated from cli.py — if a third caller appears,
# extract to audit_orchestrator/discovery.py.

def _gather_sources(config, repo_root: Path) -> list[Path]:
    explicit = [repo_root / s for s in config.sources]
    discovered: list[Path] = []
    if config.auto_discover:
        discovered = sorted(set(
            list(repo_root.glob("pilot-*.md")) + list(repo_root.glob("audit-*.md"))
        ))
    seen: set[Path] = set()
    out: list[Path] = []
    for p in explicit + discovered:
        if p in seen or not p.exists():
            continue
        seen.add(p)
        out.append(p)
    return out


def _build_classifier_ctx(config, parse_results) -> ClassifierContext:
    ctx = ClassifierContext(
        overrides=config.overrides,
        caution_keywords=config.classifier.caution_keywords,
        skip_keywords=config.classifier.skip_keywords,
    )
    for r in parse_results:
        ctx.standalone_xl.extend(r.standalone_xl)
        ctx.standalone_architectural.extend(r.standalone_architectural)
        ctx.standalone_high_value.extend(r.standalone_high_value)
    return ctx


class QueueBrowser(ModalScreen[None]):
    """Browse all parsed items + bundles. Toggle queue membership with Enter or Space."""

    BINDINGS = [
        Binding("escape", "close", "Close"),
        Binding("q", "close", "Close"),
        Binding("space", "toggle", "Toggle queue"),
        Binding("enter", "toggle", "Toggle queue"),
    ]

    def __init__(self, work_dir: Path, repo_root: Path) -> None:
        super().__init__()
        self.work_dir = work_dir
        self.repo_root = repo_root
        self.state_mgr = StateManager(work_dir / "state.json")
        self._row_ids: list[str] = []  # row index → item/bundle id
        self._show_skip = False         # filter: show ✗ skip items?

    def compose(self) -> ComposeResult:
        yield Vertical(
            Static(
                "Queue Browser — Space/Enter to toggle, [s] toggle skip-filter, Esc to close",
                id="qb-title",
            ),
            DataTable(id="qb-table", cursor_type="row", zebra_stripes=True),
            Static("", id="qb-status"),
            id="qb-dialog",
        )

    def on_mount(self) -> None:
        table = self.query_one("#qb-table", DataTable)
        table.add_columns("Q", "ID", "Title", "Tier", "Eff", "Class")
        self._populate()

    def _populate(self) -> None:
        config = load_config(self.work_dir / "config.yml")
        sources = _gather_sources(config, self.repo_root)

        if not sources:
            self.query_one("#qb-status", Static).update(
                f"No audit files found in {self.repo_root}. "
                f"Drop a pilot-*.md or audit-*.md there."
            )
            return

        parse_results = [parse_audit_file(p) for p in sources]
        ctx = _build_classifier_ctx(config, parse_results)

        state = self.state_mgr.load()
        queued = set(state.queue)

        table = self.query_one("#qb-table", DataTable)
        table.clear()
        self._row_ids.clear()

        total_items = 0
        total_bundles = 0

        for r in parse_results:
            item_by_id = {i.id: i for i in r.items}

            for bundle in r.bundles:
                members = [item_by_id[m] for m in bundle.members if m in item_by_id]
                cls = classify_bundle(bundle, members, ctx)
                if cls == Classification.SKIP and not self._show_skip:
                    continue
                self._add_row(table, bundle.id, bundle.title, "—", "bun", cls, queued)
                total_bundles += 1

            for item in r.items:
                if item.bundle is not None:
                    continue  # member of a bundle — shown as the bundle row
                cls = classify_item(item, ctx)
                if cls == Classification.SKIP and not self._show_skip:
                    continue
                self._add_row(
                    table, item.id, item.title,
                    item.tier.value, item.effort.value, cls, queued,
                )
                total_items += 1

        skip_label = "showing ✗ skip" if self._show_skip else "hiding ✗ skip (press s)"
        self.query_one("#qb-status", Static).update(
            f"{total_bundles} bundles · {total_items} standalone items · "
            f"queue: {len(queued)} · {skip_label}"
        )

    def _add_row(
        self, table: DataTable, item_id: str, title: str,
        tier: str, effort: str, cls: Classification, queued: set[str],
    ) -> None:
        check = "✓" if item_id in queued else " "
        badge = {
            Classification.RECOMMENDED: "⭐",
            Classification.CAUTION: "⚠",
            Classification.SKIP: "✗",
        }.get(cls, "?")
        # Truncate title if very long
        title_trunc = title if len(title) <= 70 else title[:67] + "..."
        table.add_row(check, item_id, title_trunc, tier, effort, badge)
        self._row_ids.append(item_id)

    def on_key(self, event) -> None:
        # Catch the "s" toggle for the skip-filter (separate from BINDINGS so we
        # can rely on the runtime key event rather than a global binding)
        if event.key == "s":
            self._show_skip = not self._show_skip
            self._populate()
            event.stop()

    def action_toggle(self) -> None:
        table = self.query_one("#qb-table", DataTable)
        row = table.cursor_row
        if row is None or row >= len(self._row_ids):
            return
        item_id = self._row_ids[row]
        state = self.state_mgr.load()
        if item_id in state.queue:
            state.queue.remove(item_id)
        else:
            state.queue.append(item_id)
        self.state_mgr.save(state)
        self._populate()
        # Keep cursor where it was
        try:
            table.move_cursor(row=row)
        except Exception:
            pass

    def action_close(self) -> None:
        self.dismiss(None)
