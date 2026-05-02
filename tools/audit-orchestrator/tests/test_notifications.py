"""Notification tests — mock subprocess calls."""
from unittest.mock import patch, MagicMock
from audit_orchestrator.notifications import notify, NotifierBackend


def test_notify_uses_terminal_notifier_when_available():
    with patch("shutil.which", return_value="/usr/local/bin/terminal-notifier"), \
         patch("subprocess.run") as mock_run:
        notify("hello", title="Audit")
        assert mock_run.called
        args = mock_run.call_args.args[0]
        assert args[0] == "/usr/local/bin/terminal-notifier"
        assert "hello" in args


def test_notify_falls_back_to_osascript():
    with patch("shutil.which", return_value=None), \
         patch("subprocess.run") as mock_run:
        notify("hello", title="Audit")
        assert mock_run.called
        args = mock_run.call_args.args[0]
        assert args[0] == "osascript"


def test_notify_silent_when_disabled():
    with patch("subprocess.run") as mock_run:
        notify("hello", title="Audit", enabled=False)
        assert not mock_run.called
