from click.testing import CliRunner
from audit_orchestrator.cli import main


def test_status_runs_and_prints_summary(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    runner = CliRunner()
    result = runner.invoke(main, ["status"])
    assert result.exit_code == 0
    assert "queue" in result.output.lower()
