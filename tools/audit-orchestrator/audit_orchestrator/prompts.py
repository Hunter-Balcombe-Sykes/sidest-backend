"""Render Claude prompts for the unattended fix runner."""
from __future__ import annotations
from pathlib import Path


ITEM_PROMPT_TEMPLATE = """\
You are running unattended via the audit orchestrator. Read carefully.

CONTEXT:
- This repo is at: {repo_root}
- Branch is: development-v2 (already checked out, up to date with origin)
- Project guardrails: see CLAUDE.md (will load automatically)

YOUR TASK:
Fix the following audit item(s). Multiple items in one task means it's a
bundle — fix all members in one consistent session.

{item_body}

EXPLORATION STRATEGY (token efficiency — IMPORTANT):
For codebase exploration, DELEGATE to the Explore subagent via the Task tool.
Do NOT use Glob/Grep/multi-file Read in the parent session for exploration —
that bloats this session's cache-read cost on every subsequent turn.

Use Explore (Task tool, subagent_type="Explore") for:
  - "Where is X defined / which files reference Y"
  - Finding the right file when you only know a symbol or pattern
  - Surveying naming conventions or existing patterns across the codebase
  - Multi-file reads to understand a subsystem before editing

Use direct tools in the parent session for:
  - Reading a specific file you already know the path of
  - Making the actual edits (Edit/Write)
  - Running tests, git commands

When dispatching Explore: brief it like a colleague (goal + what's already
known). Ask for "report in under 200 words" so its summary stays compact.

RULES (non-negotiable):

1. BEFORE writing any code, list every decision this fix requires
   (file location, function signatures, naming, test approach, edge cases).
   For each decision, ask yourself: am I 100% certain?
   If ANY answer is no, write your specific questions to:
       {question_file_path}
   ...and EXIT immediately. Do not implement. Asking is success, not failure.

   When writing the question file: include only the question body, no YAML frontmatter.
   The orchestrator will add metadata on its end.

2. After implementing, run: {test_command}
   If it FAILS, do NOT attempt to fix the test failure. Exit cleanly.
   The orchestrator will save the diff and test output for review.

3. If you find yourself making more than 2 attempts at the same fix,
   that's a signal you're guessing. Stop and write a question instead.

4. Allowed actions:
   - Edit, Write, Read, Glob, Grep files; dispatch Task subagents
   - Run: composer test, git add, git commit, git checkout, git status, git diff
   You MAY NOT: git push (the orchestrator handles that), modify .env,
   create Laravel migration files, skip hooks, modify CI config.

5. ONE COMMIT per item (load-bearing — orchestrator's pre-push check rejects
   sessions with more than 1 commit ahead of origin/{push_target}).
   If you naturally produce multiple commits during the work (e.g. one per
   sub-concern), squash them BEFORE you exit:
       git reset --soft origin/{push_target}
       git commit -m "<type>(<scope>): <summary>\n\nItem: {item_id}\n\n<body>"
   The runner has an auto-squash backstop, but doing it yourself keeps the
   commit message accurate to your intent.

   Commit message format:
   <type>(<scope>): <one-line summary>

   Item: {item_id}

   <2-4 lines on what changed and why>

6. After committing, write a completion record to:
       {completion_file_path}
   ...with these four sections (markdown headings exactly as below):

   ## Plain English
   <2-4 sentences explaining the fix to someone who doesn't know the code.
   No jargon. Like you're telling a non-technical founder.>

   ## Technical Summary
   <Engineering-grade summary: files changed, new behavior, contract changes.
   Reference specific methods/classes.>

   ## Decisions Made
   <List of decisions you made that weren't pre-determined by the audit item.
   Format: "- <decision>: <reasoning>". Write "(none)" if mechanical.>

   ## Notes
   <Anything else useful: surprising findings, gotchas, related items.
   Empty section OK if nothing notable.>

   Do NOT add YAML frontmatter or a Questions Asked section — the orchestrator
   adds those automatically.

7. When done (tests pass + committed + completion record written), simply exit.
"""


RESUME_PROMPT_TEMPLATE = """\
Josh's answer to your question:

{answer}

Now: re-evaluate your decision list against this answer. If you're still
uncertain about anything, write a follow-up question file and exit. If
you're now certain, proceed with the implementation per your earlier rules.
"""


def render_item_prompt(
    *,
    item_id: str,
    item_body: str,
    question_file_path: Path,
    completion_file_path: Path,
    test_command: str,
    repo_root: Path,
    push_target: str = "development-v2",
) -> str:
    return ITEM_PROMPT_TEMPLATE.format(
        item_id=item_id,
        item_body=item_body,
        question_file_path=str(question_file_path),
        completion_file_path=str(completion_file_path),
        test_command=test_command,
        repo_root=str(repo_root),
        push_target=push_target,
    )


def render_resume_prompt(*, answer: str) -> str:
    return RESUME_PROMPT_TEMPLATE.format(answer=answer)
