"""Top-level CLI for audit orchestrator."""
from __future__ import annotations
import click
import yaml
from pathlib import Path

from audit_orchestrator.state import StateManager
from audit_orchestrator.config import load_config
from audit_orchestrator.parser import parse_audit_file
from audit_orchestrator.classifier import classify_item, classify_bundle, ClassifierContext
from audit_orchestrator.models import Classification, Item, Bundle


def _state_path() -> Path:
    return Path.cwd() / ".audit-work" / "state.json"


def _config_path() -> Path:
    return Path.cwd() / ".audit-work" / "config.yml"


def _gather_sources(config) -> list[Path]:
    """Collect source paths from explicit config + auto-discovery, deduplicated."""
    cwd = Path.cwd()
    explicit = [cwd / s for s in config.sources]
    discovered: list[Path] = []
    if config.auto_discover:
        discovered = sorted(set(
            list(cwd.glob("pilot-*.md")) + list(cwd.glob("audit-*.md"))
        ))
    seen: set[Path] = set()
    out: list[Path] = []
    for p in explicit + discovered:
        if p in seen or not p.exists():
            continue
        seen.add(p)
        out.append(p)
    return out


def _build_classifier_context(config, parse_results) -> ClassifierContext:
    """Merge per-file standalone lists into a single ClassifierContext."""
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


def _collect_recommended(config, count: int) -> list[str]:
    """Return up to `count` IDs (bundles AND/OR standalone items) classified as RECOMMENDED.

    Bundles are returned as bundle IDs (e.g. "B5") — NOT expanded into members.
    The runner processes a queued bundle as one Claude session that fixes all
    members together. Expanding here would lose that bundle abstraction.
    Standalone items (not in any bundle) are appended after bundles.
    """
    sources = _gather_sources(config)
    parse_results = [parse_audit_file(p) for p in sources]
    ctx = _build_classifier_context(config, parse_results)

    out: list[str] = []
    for r in parse_results:
        item_by_id = {i.id: i for i in r.items}
        for bundle in r.bundles:
            members = [item_by_id[m] for m in bundle.members if m in item_by_id]
            if classify_bundle(bundle, members, ctx) == Classification.RECOMMENDED:
                if bundle.id not in out:
                    out.append(bundle.id)
        for item in r.items:
            if item.bundle is not None:
                continue
            if classify_item(item, ctx) == Classification.RECOMMENDED:
                if item.id not in out:
                    out.append(item.id)
    return out[:count]


@click.group(invoke_without_command=True)
@click.pass_context
def main(ctx: click.Context) -> None:
    """Audit orchestrator — run unattended Claude fix sessions across audit checklists."""
    if ctx.invoked_subcommand is None:
        from audit_orchestrator.tui import AuditApp
        work = _state_path().parent
        work.mkdir(parents=True, exist_ok=True)
        AuditApp(work_dir=work, repo_root=Path.cwd()).run()


@main.command("add")
@click.argument("ids", nargs=-1, required=True)
@click.option("--count", default=8, help="When adding suggest, max items.")
def add(ids: tuple[str, ...], count: int) -> None:
    """Append item / bundle IDs to the queue.

    Special form: `audit-orch add suggest [--count N]` adds the auto-recommended top N.
    Each added id is also looked up in the audit sources so its full metadata
    (title, body, source) gets written to state.items — without that, the
    runner has nothing to send to Claude and would skip the item.
    """
    from audit_orchestrator.queue_ops import populate_item_metadata, parse_all

    config = load_config(_config_path())
    sm = StateManager(_state_path())
    state = sm.load()

    if ids == ("suggest",):
        to_add = _collect_recommended(config, count)
    else:
        to_add = list(ids)

    parse_results = parse_all(config, Path.cwd())
    not_found: list[str] = []
    for item_id in to_add:
        if not populate_item_metadata(state, item_id, parse_results):
            not_found.append(item_id)
            continue
        if item_id not in state.queue:
            state.queue.append(item_id)

    sm.save(state)
    click.echo(f"Queue: {', '.join(state.queue) if state.queue else '(empty)'}")
    if not_found:
        click.echo(f"Not found in audit sources: {', '.join(not_found)}", err=True)


@main.command("suggest")
@click.option("--count", default=8, help="Maximum suggestions to return.")
def suggest(count: int) -> None:
    """Print recommended items without enqueuing."""
    config = load_config(_config_path())
    ids = _collect_recommended(config, count)
    if not ids:
        click.echo("No recommended items found.")
        return
    for i in ids:
        click.echo(f"⭐ {i}")


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


@main.command("sweep")
def sweep() -> None:
    """Remove already-done or already-blocked items from the queue."""
    sm = StateManager(_state_path())
    state = sm.load()
    removed: list[str] = []
    kept: list[str] = []
    for qid in state.queue:
        status = state.items.get(qid, {}).get("status")
        if status in ("done", "blocked"):
            removed.append(f"{qid} ({status})")
        else:
            kept.append(qid)
    state.queue = kept
    sm.save(state)
    if removed:
        click.echo(f"Swept {len(removed)} stale items: {', '.join(removed)}")
    else:
        click.echo("Queue is clean — nothing to sweep.")
    click.echo(f"Queue now: {', '.join(kept) if kept else '(empty)'}")


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


@main.command("status")
def status() -> None:
    """One-line summary suitable for shell prompts / tmux status-line."""
    sm = StateManager(_state_path())
    state = sm.load()
    queued = len(state.queue)
    done = sum(1 for v in state.items.values() if v.get("status") == "done")
    blocked = sum(1 for v in state.items.values() if v.get("status") == "blocked")
    running = state.current_run["id"] if state.current_run else "idle"
    click.echo(f"audit: queue={queued} done={done} blocked={blocked} running={running}")


if __name__ == "__main__":
    main()
