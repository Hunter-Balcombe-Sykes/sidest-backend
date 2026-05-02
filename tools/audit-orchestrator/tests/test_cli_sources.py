import yaml
from pathlib import Path
from click.testing import CliRunner
from audit_orchestrator.cli import main


def test_sources_list_shows_default_when_no_config(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    runner = CliRunner()
    result = runner.invoke(main, ["sources"])
    assert result.exit_code == 0
    assert "auto-discover: enabled" in result.output.lower()


def test_sources_add_writes_config(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    runner = CliRunner()
    result = runner.invoke(main, ["sources", "add", "audit-2026-08.md"])
    assert result.exit_code == 0
    config_path = tmp_path / ".audit-work" / "config.yml"
    raw = yaml.safe_load(config_path.read_text())
    assert "audit-2026-08.md" in raw["sources"]


def test_sources_remove_drops_from_config(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    config_path = tmp_path / ".audit-work" / "config.yml"
    config_path.parent.mkdir(parents=True, exist_ok=True)
    config_path.write_text(yaml.dump({"sources": ["a.md", "b.md"]}))

    runner = CliRunner()
    result = runner.invoke(main, ["sources", "remove", "a.md"])
    assert result.exit_code == 0
    raw = yaml.safe_load(config_path.read_text())
    assert raw["sources"] == ["b.md"]
