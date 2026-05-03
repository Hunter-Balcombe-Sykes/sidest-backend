"""Stream-JSON event parser tests."""
import json
from audit_orchestrator.stream_parser import StreamEventTracker, EventKind


def test_extracts_session_id_from_first_event():
    tracker = StreamEventTracker()
    tracker.feed_line(json.dumps({"type": "system", "subtype": "init", "session_id": "abc-123"}))
    assert tracker.session_id == "abc-123"


def test_classifies_tool_use_events():
    tracker = StreamEventTracker()
    tracker.feed_line(json.dumps({
        "type": "assistant",
        "message": {"content": [{"type": "tool_use", "name": "Edit", "input": {}}]},
    }))
    assert tracker.last_event == EventKind.EDITING


def test_test_command_classified_as_testing():
    tracker = StreamEventTracker()
    tracker.feed_line(json.dumps({
        "type": "assistant",
        "message": {"content": [{
            "type": "tool_use",
            "name": "Bash",
            "input": {"command": "composer test"},
        }]},
    }))
    assert tracker.last_event == EventKind.TESTING


def test_git_commit_classified_as_committing():
    tracker = StreamEventTracker()
    tracker.feed_line(json.dumps({
        "type": "assistant",
        "message": {"content": [{
            "type": "tool_use",
            "name": "Bash",
            "input": {"command": "git commit -m 'fix'"},
        }]},
    }))
    assert tracker.last_event == EventKind.COMMITTING


def test_malformed_line_ignored():
    tracker = StreamEventTracker()
    tracker.feed_line("not valid json")
    tracker.feed_line("")
    assert tracker.session_id is None
