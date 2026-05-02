# Audit Orchestrator

A terminal tool that runs unattended Claude Code fix sessions across the items in pilot audit checklists (`pilot-stage-*.md`, `audit-*.md`).

## Quick start

```bash
cd tools/audit-orchestrator
python3 -m venv .venv
source .venv/bin/activate
pip install -e ".[dev]"

# (Or install globally with pipx so the `audit` command works anywhere:)
pipx install ./tools/audit-orchestrator
```

## Usage

```bash
# From the backend repo root:
audit add B5 B10                    # queue specific items / bundles
audit add suggest --count 8         # queue auto-recommended items
audit suggest                       # preview recommendations without enqueuing
audit queue                         # show what's queued
audit clear                         # empty the queue
audit status                        # one-line summary

audit                               # open the TUI; F3 picks mode and starts runner
```

## Two modes

- **Work Mode** — runs alongside your other work. Notifies you when Claude needs a question answered. Click the blue panel, type your answer, runner resumes.
- **Overnight Mode** — fires-and-forgets. Questions queue for morning review.

## Design

See `docs/superpowers/specs/2026-05-02-audit-orchestrator-design.md` for the full design spec, including:

- Data model (`.audit-work/state.json`, `.audit-work/config.yml`)
- Module breakdown
- Claude session flow (with `--resume` for in-context resumption)
- Push behavior (tests pass → push to `development-v2`)
- Failure modes

## Audit format

The tool parses any markdown file matching `pilot-*.md` or `audit-*.md` (or files explicitly added via `audit sources add`). Format spec: `docs/audit-conventions.md`.

## Configuration

Edit `.audit-work/config.yml` (created on first run):

```yaml
sources: []                  # explicit list (auto-discover finds the rest)
auto_discover: true
push_target: development-v2
test_command: composer test
claude_model: sonnet
overrides:                   # per-item classification overrides
  "#V5-001": skip
```

## API key vs Max subscription

Each `claude` invocation by the orchestrator uses whatever auth is configured for your `claude` CLI. If you're on a Max subscription, every overnight run burns Max quota (which you may want to preserve for daytime work).

To bill orchestrator-spawned sessions to API instead, export `ANTHROPIC_API_KEY` in the environment of the shell where you run `audit`:

```bash
export ANTHROPIC_API_KEY=sk-ant-...
audit
```

## Tests

```bash
cd tools/audit-orchestrator
source .venv/bin/activate
pytest
```
