"""Load and validate .audit-work/config.yml."""
from __future__ import annotations
from dataclasses import dataclass, field
from pathlib import Path
import yaml


@dataclass
class ClassifierConfig:
    caution_keywords: list[str] = field(default_factory=lambda: [
        "Stripe", "payout", "auth", "GDPR", "compliance", "webhook secret", "money",
    ])
    skip_keywords: list[str] = field(default_factory=lambda: [
        "XL", "policy rollout", "architectural",
    ])


@dataclass
class Config:
    sources: list[str] = field(default_factory=list)
    auto_discover: bool = True
    push_target: str = "development-v2"
    push_on_tests_pass: bool = True
    test_command: str = "composer test"
    claude_model: str = "sonnet"
    claude_extra_args: list[str] = field(default_factory=list)
    allowed_tools: list[str] = field(default_factory=lambda: [
        "Edit", "Write", "Read",
        "Bash(composer test:*)",
        "Bash(git add:*)",
        "Bash(git commit:*)",
        "Bash(git checkout:*)",
        "Bash(git status:*)",
        "Bash(git diff:*)",
    ])
    notify_on_question: bool = True
    notifier_command: str = "terminal-notifier -title 'Audit' -message"
    overrides: dict[str, str] = field(default_factory=dict)
    classifier: ClassifierConfig = field(default_factory=ClassifierConfig)


def load_config(path: Path) -> Config:
    """Load config.yml, returning defaults if file missing.

    Unknown top-level keys are silently dropped (forward-compatible).
    """
    if not path.exists():
        return Config()

    raw = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
    classifier_raw = raw.pop("classifier", {}) or {}

    config = Config(**{
        k: v for k, v in raw.items()
        if k in Config.__dataclass_fields__
    })
    if classifier_raw:
        config.classifier = ClassifierConfig(**{
            k: v for k, v in classifier_raw.items()
            if k in ClassifierConfig.__dataclass_fields__
        })
    return config
