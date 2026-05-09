#!/usr/bin/env bash
# audit.sh — One-command audit: DeepSeek scan → Claude (via local CLI) adjudicate → final audit file.
#
# Usage:
#   scripts/audit/audit.sh \
#     --lens "auth/policy coverage on the new SitePolicy" \
#     --scope app/Policies/SitePolicy.php \
#     --scope app/Http/Controllers/Api/Professional/Uploads/
#
# Auth:
#   DEEPSEEK_API_KEY  loaded from scripts/audit/.env (gitignored) or your shell env
#   Claude            uses the local `claude` CLI's existing OAuth login
#
# Output: audit-YYYY-MM-DD-<lens-slug>.md (or whatever --out is set to)
# Pass --keep-drafts to keep the intermediate DeepSeek drafts.md.

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
OUT=""
KEEP_DRAFTS=false

usage() { sed -n '2,16p' "$0" | sed 's/^# \?//'; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --scope)         SCOPE_ARGS+=("--scope" "$2"); shift 2 ;;
        --lens)          LENS_ARG=("--lens" "$2"); shift 2 ;;
        --lens-file)     LENS_ARG=("--lens-file" "$2"); shift 2 ;;
        --out)           OUT="$2"; shift 2 ;;
        --keep-drafts)   KEEP_DRAFTS=true; shift ;;
        -h|--help)       usage; exit 0 ;;
        *) echo "Unknown arg: $1" >&2; usage >&2; exit 2 ;;
    esac
done

[[ ${#LENS_ARG[@]} -gt 0 ]]       || { echo "--lens or --lens-file required" >&2; exit 2; }
[[ ${#SCOPE_ARGS[@]} -gt 0 ]]     || { echo "--scope is required (one or more)" >&2; exit 2; }
[[ -n "${DEEPSEEK_API_KEY:-}" ]]  || { echo "DEEPSEEK_API_KEY not found (set in scripts/audit/.env or export)" >&2; exit 2; }
command -v claude >/dev/null      || { echo "claude CLI not on PATH — install from claude.ai/code" >&2; exit 2; }

# --- Drafts location ---
if $KEEP_DRAFTS; then
    DRAFTS="drafts-$(date +%s).md"
else
    TMP="$(mktemp -d)"
    trap 'rm -rf "$TMP"' EXIT
    DRAFTS="$TMP/drafts.md"
fi

# --- Step 1: Scan ---
echo "" >&2
echo "════════ Step 1/2: DeepSeek scan ════════" >&2
"$SCRIPT_DIR/audit-scan.sh" "${LENS_ARG[@]}" "${SCOPE_ARGS[@]}" --out "$DRAFTS"

# --- Step 2: Adjudicate via local claude CLI ---
echo "" >&2
echo "════════ Step 2/2: Claude adjudication ════════" >&2

OUT_FLAG=()
[[ -n "$OUT" ]] && OUT_FLAG=(--out "$OUT")

"$SCRIPT_DIR/audit-adjudicate.sh" \
    --drafts "$DRAFTS" \
    "${LENS_ARG[@]}" \
    "${SCOPE_ARGS[@]}" \
    ${OUT_FLAG[@]+"${OUT_FLAG[@]}"}

if $KEEP_DRAFTS; then
    echo "" >&2
    echo "Drafts kept at: $DRAFTS" >&2
fi
