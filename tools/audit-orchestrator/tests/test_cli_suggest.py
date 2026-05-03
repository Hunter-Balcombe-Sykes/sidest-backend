import shutil
from pathlib import Path
from click.testing import CliRunner
from audit_orchestrator.cli import main


def test_suggest_lists_recommended_bundles(tmp_path, monkeypatch):
    """Bundles take precedence: if an item belongs to a recommended bundle,
    the bundle ID is what shows up (not the member ID), preserving the
    'fix all members in one Claude session' abstraction."""
    monkeypatch.chdir(tmp_path)
    fixture = Path(__file__).parent / "fixtures" / "tiny-audit.md"
    shutil.copy(fixture, tmp_path / "pilot-stage-test.md")

    runner = CliRunner()
    result = runner.invoke(main, ["suggest", "--count", "5"])
    assert result.exit_code == 0, result.output
    # Recommended bundles appear by bundle ID (B-T1 contains #T-001, #T-002)
    assert "B-T1" in result.output
    # Items inside a recommended bundle are NOT shown standalone
    assert "#T-001" not in result.output
    # XL standalone item is classified skip
    assert "#T-004" not in result.output


def test_add_suggest_enqueues_recommended_bundles(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    fixture = Path(__file__).parent / "fixtures" / "tiny-audit.md"
    shutil.copy(fixture, tmp_path / "pilot-stage-test.md")

    runner = CliRunner()
    result = runner.invoke(main, ["add", "suggest", "--count", "3"])
    assert result.exit_code == 0, result.output

    queue_result = runner.invoke(main, ["queue"])
    # Bundle ID (not member IDs) is queued — runner will fix the bundle as one session
    assert "B-T1" in queue_result.output
