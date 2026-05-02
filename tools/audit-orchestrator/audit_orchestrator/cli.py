"""Top-level CLI for audit orchestrator."""
from __future__ import annotations
import click
import yaml
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


@main.group("sources", invoke_without_command=True)
@click.pass_context
def sources_group(ctx: click.Context) -> None:
    """Manage audit source files. Without subcommand: list."""
    if ctx.invoked_subcommand is None:
        ctx.invoke(sources_list)


@sources_group.command("list")
@click.pass_context
def sources_list(ctx: click.Context) -> None:
    """List configured sources + auto-discovered files."""
    config = load_config(_config_path())
    click.echo(f"auto-discover: {'enabled' if config.auto_discover else 'disabled'}")
    if config.sources:
        click.echo("explicit sources:")
        for s in config.sources:
            click.echo(f"  - {s}")
    if config.auto_discover:
        cwd = Path.cwd()
        discovered = sorted(set(
            list(cwd.glob("pilot-*.md")) + list(cwd.glob("audit-*.md"))
        ))
        if discovered:
            click.echo("auto-discovered:")
            for d in discovered:
                click.echo(f"  - {d.name}")


@sources_group.command("add")
@click.argument("path")
def sources_add(path: str) -> None:
    """Add a source file to config.yml."""
    config_path = _config_path()
    config_path.parent.mkdir(parents=True, exist_ok=True)
    raw: dict = {}
    if config_path.exists():
        raw = yaml.safe_load(config_path.read_text(encoding="utf-8")) or {}
    sources = raw.get("sources", [])
    if path not in sources:
        sources.append(path)
    raw["sources"] = sources
    config_path.write_text(yaml.dump(raw, sort_keys=False), encoding="utf-8")
    click.echo(f"Added {path}.")


@sources_group.command("remove")
@click.argument("path")
def sources_remove(path: str) -> None:
    """Remove a source file from config.yml."""
    config_path = _config_path()
    if not config_path.exists():
        click.echo("No config.yml — nothing to remove.")
        return
    raw = yaml.safe_load(config_path.read_text(encoding="utf-8")) or {}
    sources = [s for s in raw.get("sources", []) if s != path]
    raw["sources"] = sources
    config_path.write_text(yaml.dump(raw, sort_keys=False), encoding="utf-8")
    click.echo(f"Removed {path}.")


if __name__ == "__main__":
    main()
