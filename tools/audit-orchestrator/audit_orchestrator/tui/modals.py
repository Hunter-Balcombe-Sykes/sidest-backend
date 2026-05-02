"""Modal screens for the TUI."""
from __future__ import annotations
from textual.app import ComposeResult
from textual.containers import Horizontal, Vertical
from textual.screen import ModalScreen
from textual.widgets import Button, Static, Input


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
