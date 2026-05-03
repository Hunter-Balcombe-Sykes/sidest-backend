"""Completion record writer tests."""
from pathlib import Path
from audit_orchestrator.completion import (
    write_completion_record,
    CompletionContext,
    write_blocked_record,
)


def _make_ctx(tmp_path, **overrides) -> CompletionContext:
    defaults = dict(
        item_id="#V5-068",
        title="Theme webhook HMAC",
        source="pilot-stage-1.md",
        tier="P0",
        effort_estimate="~1h",
        mode="overnight",
        commit_sha="abc123def",
        files_touched=[
            "app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php",
            "tests/Feature/Webhooks/ShopifyThemePublishedWebhookTest.php",
        ],
        test_result="pass",
        question_files=[],
    )
    defaults.update(overrides)
    return CompletionContext(**defaults)


def test_writes_completed_file_with_all_sections(tmp_path):
    completed_dir = tmp_path / ".audit-work" / "completed"
    completed_dir.mkdir(parents=True)
    body = (
        "## Plain English\nThe webhook was returning OK on bad signatures.\n\n"
        "## Technical Summary\nChanged ShopifyThemePublishedWebhookController:30 "
        "to return 401 on invalid HMAC.\n\n"
        "## Decisions Made\n- Mirror sibling: matched the GDPR controller's pattern.\n\n"
        "## Notes\n(none)\n"
    )
    (completed_dir / "V5-068.md").write_text(body, encoding="utf-8")

    ctx = _make_ctx(tmp_path)
    out_path = write_completion_record(completed_dir, ctx)
    text = out_path.read_text(encoding="utf-8")

    # Frontmatter present
    assert text.startswith("---\n")
    assert "#V5-068" in text  # accepts any yaml quoting
    assert "commit_sha: abc123def" in text
    assert "test_result: pass" in text
    # Body sections preserved
    assert "## Plain English" in text
    assert "## Technical Summary" in text
    assert "## Decisions Made" in text
    assert "## Notes" in text
    # Questions Asked section auto-appended
    assert "## Questions Asked" in text
    assert "(none)" in text


def test_writes_questions_section_from_question_files(tmp_path):
    completed_dir = tmp_path / ".audit-work" / "completed"
    completed_dir.mkdir(parents=True)
    questions_dir = tmp_path / ".audit-work" / "questions"
    questions_dir.mkdir(parents=True)

    qfile = questions_dir / "V5-068.md"
    qfile.write_text(
        "---\nitem_id: '#V5-068'\nsession_id: sid-1\nwritten_at: 2026-05-02T20:00:00Z\n---\n\n"
        "Where should the helper live?\n\n"
        "## Answer (answered_at: 2026-05-02T20:05:00Z)\nUse option (a).\n",
        encoding="utf-8",
    )

    (completed_dir / "V5-068.md").write_text(
        "## Plain English\nx\n\n## Technical Summary\nx\n\n"
        "## Decisions Made\nx\n\n## Notes\nx\n",
        encoding="utf-8",
    )

    ctx = _make_ctx(tmp_path, question_files=[qfile])
    out_path = write_completion_record(completed_dir, ctx)
    text = out_path.read_text(encoding="utf-8")

    assert "## Questions Asked" in text
    assert "Where should the helper live?" in text
    assert "Use option (a)" in text


def test_blocked_record_has_why_blocked_section(tmp_path):
    completed_dir = tmp_path / ".audit-work" / "completed"
    completed_dir.mkdir(parents=True)
    out_path = write_blocked_record(
        completed_dir,
        item_id="#V5-068",
        title="Theme webhook HMAC",
        source="pilot-stage-1.md",
        reason="composer test failed",
        log_excerpt="FAIL: ShopifyThemePublishedWebhookTest::test_invalid_hmac",
    )
    text = out_path.read_text(encoding="utf-8")
    assert "## Why Blocked" in text
    assert "composer test failed" in text
    assert "FAIL: ShopifyThemePublishedWebhookTest" in text
