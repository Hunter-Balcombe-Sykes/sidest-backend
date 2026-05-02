import shutil
from pathlib import Path
from click.testing import CliRunner
from audit_orchestrator.cli import main


def test_suggest_lists_recommended_items(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    fixture = Path(__file__).parent / "fixtures" / "tiny-audit.md"
    shutil.copy(fixture, tmp_path / "pilot-stage-test.md")

    runner = CliRunner()
    result = runner.invoke(main, ["suggest", "--count", "5"])
    assert result.exit_code == 0, result.output
    assert "#T-001" in result.output
    assert "#T-004" not in result.output  # XL → skip


def test_add_suggest_enqueues_recommended_items(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    fixture = Path(__file__).parent / "fixtures" / "tiny-audit.md"
    shutil.copy(fixture, tmp_path / "pilot-stage-test.md")

    runner = CliRunner()
    result = runner.invoke(main, ["add", "suggest", "--count", "3"])
    assert result.exit_code == 0, result.output

    queue_result = runner.invoke(main, ["queue"])
    assert "#T-001" in queue_result.output
