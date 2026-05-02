"""Smoke test: CLI entry point loads."""
from click.testing import CliRunner
from audit_orchestrator.cli import main


def test_audit_help_exits_zero():
    runner = CliRunner()
    result = runner.invoke(main, ["--help"])
    assert result.exit_code == 0
    assert "audit" in result.output.lower()
