"""Prompt template tests."""
from pathlib import Path
from audit_orchestrator.prompts import render_item_prompt, render_resume_prompt


def test_item_prompt_includes_required_sections():
    prompt = render_item_prompt(
        item_id="#V5-068",
        item_body="- [ ] **#V5-068** · P0 — Theme webhook HMAC\n    - **Where:** ...",
        question_file_path=Path("/tmp/.audit-work/questions/V5-068.md"),
        completion_file_path=Path("/tmp/.audit-work/completed/V5-068.md"),
        test_command="composer test",
        repo_root=Path("/repo"),
    )

    assert "BEFORE writing any code" in prompt
    assert "100% certain" in prompt
    assert "#V5-068" in prompt
    assert "Theme webhook HMAC" in prompt
    assert "/tmp/.audit-work/questions/V5-068.md" in prompt
    assert "/tmp/.audit-work/completed/V5-068.md" in prompt
    assert "## Plain English" in prompt
    assert "## Technical Summary" in prompt
    assert "## Decisions Made" in prompt
    assert "## Notes" in prompt
    assert "composer test" in prompt
    assert "MAY NOT" in prompt
    assert "git push" in prompt
    assert "Item: #V5-068" in prompt


def test_resume_prompt_includes_answer():
    prompt = render_resume_prompt(answer="Use option (a). Use BrandCommerceAnalyticsController as the basis.")
    assert "Use option (a)" in prompt
    assert "BrandCommerceAnalyticsController" in prompt
    assert "re-evaluate" in prompt.lower()
