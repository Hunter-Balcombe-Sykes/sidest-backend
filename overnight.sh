#!/bin/bash
#
# overnight.sh — Run a Claude Code prompt unattended, sleeping through usage-limit resets.
#
# Usage:
#   ./overnight.sh prompt.txt          # recommended: read prompt from file (safe with backticks, quotes, etc.)
#   ./overnight.sh "simple prompt"     # inline string — avoid for prompts containing backticks or $()
#   ./overnight.sh                     # no prompt — useful when chaining with --continue
#
# Env overrides:
#   RESET_WAIT=<seconds>   How long to sleep on a usage limit (default: 18300 = 5h 5m)
#   MAX_RETRIES=<n>        Max resume attempts before giving up (default: 5)

set -euo pipefail

RESET_WAIT="${RESET_WAIT:-18300}"
MAX_RETRIES="${MAX_RETRIES:-5}"
LOGFILE="claude-overnight.log"
ARG="${1:-}"

# ── helpers ───────────────────────────────────────────────────────────────────

log() { echo "$*" | tee -a "$LOGFILE"; }

resume_time() {
    if date --version &>/dev/null 2>&1; then
        # GNU date (Linux)
        date -d "+${RESET_WAIT} seconds" "+%H:%M"
    else
        # BSD date (macOS)
        date -v+${RESET_WAIT}S "+%H:%M"
    fi
}

# Returns 0 if the file contains a usage/rate-limit signal.
hit_limit() {
    grep -qiE "rate.?limit|usage.?limit|quota exceeded|too many requests|overloaded|please wait" "$1" 2>/dev/null
}

# Resolve prompt: if ARG is a readable file, slurp it; otherwise treat as literal string.
if [[ -f "$ARG" && -r "$ARG" ]]; then
    PROMPT=$(< "$ARG")
else
    PROMPT="$ARG"
fi

# ── first run ─────────────────────────────────────────────────────────────────

log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log "Starting Claude overnight run at $(date)"
log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

RUN_LOG=$(mktemp)

if [[ -n "$PROMPT" ]]; then
    claude "$PROMPT" 2>&1 | tee -a "$LOGFILE" "$RUN_LOG"
    EXIT_CODE=${PIPESTATUS[0]}
else
    claude 2>&1 | tee -a "$LOGFILE" "$RUN_LOG"
    EXIT_CODE=${PIPESTATUS[0]}
fi

# ── retry loop ────────────────────────────────────────────────────────────────

ATTEMPT=0

while true; do
    if hit_limit "$RUN_LOG"; then
        ATTEMPT=$(( ATTEMPT + 1 ))

        if (( ATTEMPT > MAX_RETRIES )); then
            log "Reached max retries ($MAX_RETRIES). Giving up at $(date)."
            rm -f "$RUN_LOG"
            exit 1
        fi

        log "Usage limit hit (attempt $ATTEMPT/$MAX_RETRIES) at $(date)."
        log "Sleeping ${RESET_WAIT}s — will resume at ~$(resume_time)."
        sleep "$RESET_WAIT"

        # Fresh per-run log so next iteration only checks the new output.
        rm -f "$RUN_LOG"
        RUN_LOG=$(mktemp)

        log "Resuming (--continue) at $(date)."
        claude --continue 2>&1 | tee -a "$LOGFILE" "$RUN_LOG"
        EXIT_CODE=${PIPESTATUS[0]}
    else
        log "Claude finished cleanly at $(date) (exit $EXIT_CODE)."
        rm -f "$RUN_LOG"
        exit "$EXIT_CODE"
    fi
done
