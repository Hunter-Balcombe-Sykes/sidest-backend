"""CLI subcommand tests."""
from click.testing import CliRunner
from audit_orchestrator.cli import main


def test_add_appends_to_queue(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    runner = CliRunner()

    result = runner.invoke(main, ["add", "B5", "B10"])
    assert result.exit_code == 0, result.output

    result = runner.invoke(main, ["queue"])
    assert "B5" in result.output
    assert "B10" in result.output


def test_clear_empties_queue(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    runner = CliRunner()
    runner.invoke(main, ["add", "B5"])
    result = runner.invoke(main, ["clear"])
    assert result.exit_code == 0
    result = runner.invoke(main, ["queue"])
    assert "B5" not in result.output
