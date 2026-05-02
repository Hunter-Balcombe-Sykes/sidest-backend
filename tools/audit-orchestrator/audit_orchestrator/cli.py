"""Top-level CLI for audit orchestrator."""
import click


@click.group(invoke_without_command=True)
@click.pass_context
def main(ctx: click.Context) -> None:
    """Audit orchestrator — run unattended Claude fix sessions across audit checklists."""
    if ctx.invoked_subcommand is None:
        click.echo("TUI not yet implemented. Run `audit --help` to see subcommands.")


if __name__ == "__main__":
    main()
