"""CLI subcommand tests."""
import shutil
from pathlib import Path
from click.testing import CliRunner
from audit_orchestrator.cli import main


def _seed_audit(tmp_path: Path) -> None:
    """Drop the tiny-audit fixture into tmp_path so add can resolve IDs."""
    fixture = Path(__file__).parent / "fixtures" / "tiny-audit.md"
    shutil.copy(fixture, tmp_path / "pilot-stage-test.md")


def test_add_appends_to_queue(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    _seed_audit(tmp_path)
    runner = CliRunner()

    result = runner.invoke(main, ["add", "B-T1", "B-T2"])
    assert result.exit_code == 0, result.output

    result = runner.invoke(main, ["queue"])
    assert "B-T1" in result.output
    assert "B-T2" in result.output


def test_add_rejects_unknown_ids(tmp_path, monkeypatch):
    """IDs not present in any audit source should not enter the queue."""
    monkeypatch.chdir(tmp_path)
    _seed_audit(tmp_path)
    runner = CliRunner()

    result = runner.invoke(main, ["add", "#NOPE-001"])
    assert result.exit_code == 0
    assert "Not found" in result.output

    result = runner.invoke(main, ["queue"])
    assert "#NOPE-001" not in result.output


def test_clear_empties_queue(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    _seed_audit(tmp_path)
    runner = CliRunner()
    runner.invoke(main, ["add", "B-T1"])
    result = runner.invoke(main, ["clear"])
    assert result.exit_code == 0
    result = runner.invoke(main, ["queue"])
    assert "B-T1" not in result.output
