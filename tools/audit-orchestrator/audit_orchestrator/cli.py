"""Top-level CLI for audit orchestrator."""
from __future__ import annotations
import click
from pathlib import Path

from audit_orchestrator.state import StateManager
from audit_orchestrator.config import load_config


def _state_path() -> Path:
    return Path.cwd() / ".audit-work" / "state.json"


def _config_path() -> Path:
    return Path.cwd() / ".audit-work" / "config.yml"


@click.group(invoke_without_command=True)
@click.pass_context
def main(ctx: click.Context) -> None:
    """Audit orchestrator — run unattended Claude fix sessions across audit checklists."""
    if ctx.invoked_subcommand is None:
        click.echo("TUI not yet implemented in this build. Run `audit --help` for subcommands.")


@main.command("add")
@click.argument("ids", nargs=-1, required=True)
def add(ids: tuple[str, ...]) -> None:
    """Append item / bundle IDs to the queue."""
    sm = StateManager(_state_path())
    state = sm.load()
    for item_id in ids:
        if item_id not in state.queue:
            state.queue.append(item_id)
    sm.save(state)
    click.echo(f"Queue: {', '.join(state.queue) if state.queue else '(empty)'}")


@main.command("queue")
def show_queue() -> None:
    """Print the current queue."""
    sm = StateManager(_state_path())
    state = sm.load()
    if not state.queue:
        click.echo("Queue is empty.")
        return
    for i, item_id in enumerate(state.queue, 1):
        click.echo(f"{i}. {item_id}")


@main.command("clear")
def clear() -> None:
    """Empty the queue."""
    sm = StateManager(_state_path())
    state = sm.load()
    state.queue = []
    sm.save(state)
    click.echo("Queue cleared.")


if __name__ == "__main__":
    main()
