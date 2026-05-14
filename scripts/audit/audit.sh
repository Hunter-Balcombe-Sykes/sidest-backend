#!/usr/bin/env bash
# audit.sh — One-command audit: DeepSeek scan(s) → Claude adjudicate → final audit file.
#
# Targeted mode (single lens):
#   scripts/audit/audit.sh \
#     --lens "auth/policy coverage on the new SitePolicy" \
#     --scope app/Policies/SitePolicy.php \
#     --scope app/Http/Controllers/Api/Professional/Uploads/
#
# Full mode (5 lens-focused scans, then one adjudication):
#   scripts/audit/audit.sh --full --scope app/Services/Stripe
#
#   Runs five focused DeepSeek scans against the same scope (security,
#   lifecycle-correctness, scaling-antipatterns, database-and-queue-scaling,
#   schema-rls) then ONE Claude adjudication over the merged drafts. Use this
#   when you want broad coverage and don't have a specific theme.
#
# Phase organization (optional):
#   Pass --phase <name> to organize output under audits/<name>/. Drafts (when
#   --keep-drafts is also set) land in audits/<name>/.drafts/. Without --phase,
#   files default to CWD (current behavior — orchestrator-compatible).
#
#     scripts/audit/audit.sh --phase phase-1-security \
#       --lens "policy coverage" --scope app/Policies
#     # → audits/phase-1-security/audit-YYYY-MM-DD-policy-coverage.md
#
# Auth:
#   DEEPSEEK_API_KEY  loaded from scripts/audit/.env (gitignored) or shell env
#   Claude            uses the local `claude` CLI's existing OAuth login
#
# Output: audit-YYYY-MM-DD-<slug>.md (or whatever --out is set to)
# Pass --keep-drafts to keep the intermediate DeepSeek drafts.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# --- Load .env if present ---
ENV_FILE="$SCRIPT_DIR/.env"
if [[ -f "$ENV_FILE" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
fi

# --- Args ---
SCOPE_ARGS=()
LENS_ARG=()
FULL=false
OUT=""
PHASE=""
KEEP_DRAFTS=false

usage() { sed -n '2,32p' "$0" | sed 's/^# \?//'; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --scope)         SCOPE_ARGS+=("--scope" "$2"); shift 2 ;;
        --lens)          LENS_ARG=("--lens" "$2"); shift 2 ;;
        --lens-file)     LENS_ARG=("--lens-file" "$2"); shift 2 ;;
        --full)          FULL=true; shift ;;
        --out)           OUT="$2"; shift 2 ;;
        --phase)         PHASE="$2"; shift 2 ;;
        --keep-drafts)   KEEP_DRAFTS=true; shift ;;
        -h|--help)       usage; exit 0 ;;
        *) echo "Unknown arg: $1" >&2; usage >&2; exit 2 ;;
    esac
done

# --- Validate ---
if $FULL; then
    [[ ${#LENS_ARG[@]} -eq 0 ]] || { echo "--full and --lens/--lens-file are mutually exclusive" >&2; exit 2; }
else
    [[ ${#LENS_ARG[@]} -gt 0 ]] || { echo "--lens or --full is required" >&2; exit 2; }
fi
[[ ${#SCOPE_ARGS[@]} -gt 0 ]]     || { echo "--scope is required (one or more)" >&2; exit 2; }
[[ -n "${DEEPSEEK_API_KEY:-}" ]]  || { echo "DEEPSEEK_API_KEY not found (set in scripts/audit/.env or export)" >&2; exit 2; }
command -v claude >/dev/null      || { echo "claude CLI not on PATH — install from claude.ai/code" >&2; exit 2; }

# --- Phase folder (optional) ---
BASE_DIR=""
if [[ -n "$PHASE" ]]; then
    BASE_DIR="audits/${PHASE}"
    mkdir -p "$BASE_DIR"
fi

# --- Drafts location ---
if $KEEP_DRAFTS; then
    if [[ -n "$BASE_DIR" ]]; then
        DRAFTS_DIR="${BASE_DIR}/.drafts"
        mkdir -p "$DRAFTS_DIR"
    else
        DRAFTS_DIR="."
    fi
    DRAFTS="$DRAFTS_DIR/drafts-$(date +%s).md"
else
    TMP="$(mktemp -d)"
    trap 'rm -rf "$TMP"' EXIT
    DRAFTS_DIR="$TMP"
    DRAFTS="$TMP/drafts.md"
fi

# --- Adjudicator budget (higher for --full because of merged drafts + tool use) ---
ADJ_BUDGET="2.00"
$FULL && ADJ_BUDGET="5.00"

if $FULL; then
    # --- Lens set for --full mode (5 themes) ---
    LENS_FILES=(
        "$SCRIPT_DIR/lenses/security.md"
        "$SCRIPT_DIR/lenses/lifecycle-correctness.md"
        "$SCRIPT_DIR/lenses/scaling-antipatterns.md"
        "$SCRIPT_DIR/lenses/database-and-queue-scaling.md"
        "$SCRIPT_DIR/lenses/schema-rls.md"
    )

    # Verify all lens files exist BEFORE starting expensive scans
    for lf in "${LENS_FILES[@]}"; do
        [[ -f "$lf" ]] || { echo "Lens file missing: $lf" >&2; exit 2; }
    done

    echo "" >&2
    echo "════════ Full audit — 5 lens-focused scans + 1 adjudication ════════" >&2

    : > "$DRAFTS"  # truncate / create

    LENS_NUM=0
    for lf in "${LENS_FILES[@]}"; do
        LENS_NUM=$((LENS_NUM + 1))
        LENS_NAME=$(basename "$lf" .md)
        echo "" >&2
        echo "──── Scan ${LENS_NUM}/5: $LENS_NAME ────" >&2
        LENS_DRAFTS="$DRAFTS_DIR/drafts-${LENS_NAME}.md"
        "$SCRIPT_DIR/audit-scan.sh" --lens-file "$lf" "${SCOPE_ARGS[@]}" --out "$LENS_DRAFTS"
        {
            echo ""
            echo "<!-- ═══ LENS: $LENS_NAME ═══ -->"
            echo ""
            cat "$LENS_DRAFTS"
        } >> "$DRAFTS"
    done

    # Meta-lens describing the full audit for the adjudicator
    META_LENS="Full audit across 5 focused themes: security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns / read-side caching (CACHE-*), database/queue scaling — N+1/throughput (SCALE-*), and schema/RLS correctness (SCHEMA-*). Drafts below are concatenated from 5 lens-focused scans, each prefixed with a <!-- LENS: name --> marker. Dedupe across lenses where the same finding appears under multiple prefixes."
    LENS_PASS_ARGS=(--lens "$META_LENS")

    # Default output name for --full mode (apply phase prefix if set)
    if [[ -z "$OUT" ]]; then
        OUT="audit-$(date +%F)-full.md"
        [[ -n "$BASE_DIR" ]] && OUT="${BASE_DIR}/${OUT}"
    fi
else
    # --- Targeted mode — single scan ---
    echo "" >&2
    echo "════════ Step 1/2: DeepSeek scan ════════" >&2
    "$SCRIPT_DIR/audit-scan.sh" "${LENS_ARG[@]}" "${SCOPE_ARGS[@]}" --out "$DRAFTS"
    LENS_PASS_ARGS=("${LENS_ARG[@]}")
fi

# --- Adjudicate ---
echo "" >&2
if $FULL; then
    echo "════════ Final step: Claude adjudication across all 5 lenses ════════" >&2
else
    echo "════════ Step 2/2: Claude adjudication ════════" >&2
fi

OUT_FLAG=()
[[ -n "$OUT" ]] && OUT_FLAG=(--out "$OUT")

# Pass --out-dir so the adjudicator's auto-derived filename (targeted mode w/o --out)
# lands inside the phase folder. Adjudicator ignores --out-dir when --out is given.
ADJ_OUT_DIR=()
[[ -n "$BASE_DIR" ]] && ADJ_OUT_DIR=(--out-dir "$BASE_DIR")

"$SCRIPT_DIR/audit-adjudicate.sh" \
    --drafts "$DRAFTS" \
    --max-budget "$ADJ_BUDGET" \
    ${ADJ_OUT_DIR[@]+"${ADJ_OUT_DIR[@]}"} \
    "${LENS_PASS_ARGS[@]}" \
    "${SCOPE_ARGS[@]}" \
    ${OUT_FLAG[@]+"${OUT_FLAG[@]}"}

if $KEEP_DRAFTS; then
    echo "" >&2
    if $FULL; then
        echo "Per-lens drafts kept in: $DRAFTS_DIR" >&2
        echo "Merged drafts: $DRAFTS" >&2
    else
        echo "Drafts kept at: $DRAFTS" >&2
    fi
fi
