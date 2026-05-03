"""Config loader tests."""
import pytest
from pathlib import Path
import yaml
from audit_orchestrator.config import load_config, Config


def test_loads_defaults_when_file_missing(tmp_path):
    config = load_config(tmp_path / "nonexistent.yml")
    assert config.push_target == "development-v2"
    assert config.test_command == "composer test"
    assert config.claude_model == "sonnet"
    assert config.auto_discover is True
    assert config.notify_on_question is True


def test_loads_user_settings_overriding_defaults(tmp_path):
    config_path = tmp_path / "config.yml"
    config_path.write_text(yaml.dump({
        "push_target": "main",
        "claude_model": "opus",
        "sources": ["custom.md"],
        "overrides": {"#X-1": "skip"},
    }))
    config = load_config(config_path)
    assert config.push_target == "main"
    assert config.claude_model == "opus"
    assert config.sources == ["custom.md"]
    assert config.overrides == {"#X-1": "skip"}
    assert config.test_command == "composer test"


def test_default_caution_keywords_present():
    config = load_config(Path("/dev/null"))
    assert "Stripe" in config.classifier.caution_keywords
    assert "GDPR" in config.classifier.caution_keywords
