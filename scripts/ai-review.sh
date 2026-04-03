#!/bin/sh
# scripts/ai-review.sh — AI code review gate for pre-commit (global, all repos)
#
# Runs the Code Reviewer agent against the staged diff.
# Blocks commit on BLOCK verdict, warns on WARN, passes on PASS.
# Reads CLAUDE.md from the current repo if present to provide architecture context.
#
# Prerequisites:
#   - claude CLI must be in PATH (runs on NixOS host, not inside container)
#   - Claude Max subscription active
#
# Skip: SKIP_AI_REVIEW=1 git commit

set -e

if [ "${SKIP_AI_REVIEW}" = "1" ]; then
  echo "[ai-review] Skipped (SKIP_AI_REVIEW=1)"
  exit 0
fi

if ! command -v claude > /dev/null 2>&1; then
  echo "[ai-review] claude CLI not found in PATH — skipping review"
  exit 0
fi

# Get staged diff (exclude lock files, generated files, test fixtures)
DIFF=$(git diff --cached --diff-filter=ACMR \
  -- \
  '*.js' '*.mjs' '*.cjs' '*.ts' '*.php' '*.json' '*.md' '*.sh' '*.yaml' '*.yml' \
  ':!package-lock.json' \
  ':!composer.lock' \
  ':!*.lock' \
  ':!reports/*' \
  ':!db/*' \
  ':!data/pronunciation/*' \
  2>/dev/null)

if [ -z "$DIFF" ]; then
  echo "[ai-review] No reviewable changes — skipping"
  exit 0
fi

DIFF_LINES=$(echo "$DIFF" | wc -l)

if [ "$DIFF_LINES" -lt 10 ]; then
  echo "[ai-review] Diff too small ($DIFF_LINES lines) — skipping"
  exit 0
fi

echo "[ai-review] Reviewing $DIFF_LINES-line diff..."

# Load repo-specific architecture context from CLAUDE.md if present
CLAUDE_MD_CONTEXT=""
if [ -f "CLAUDE.md" ]; then
  CLAUDE_MD_CONTEXT="## Repo Architecture Context (from CLAUDE.md)

$(cat CLAUDE.md)
"
fi

REVIEW_PROMPT="You are acting as the Code Reviewer agent.

$CLAUDE_MD_CONTEXT
## Universal Rules (all repos in this workspace)

- Never commit secrets, API keys, passwords, or PII in source files
- Never interpolate user/external input directly into SQL, shell commands, or HTML
- Never swallow errors silently — always log with context
- Never use eval() or dynamic require() with untrusted data
- Async functions must handle errors (try/catch or .catch())
- process.exit() must not appear in library code (only CLI entry points)

## Staged Diff to Review

\`\`\`diff
$DIFF
\`\`\`

## Your Task

Review this diff for:
1. **Security issues** — SQL injection, XSS, command injection, secrets in code, untrusted input not sanitised
2. **Correctness** — logic errors, off-by-one, race conditions, unhandled edge cases
3. **Architecture violations** — breaking the rules from CLAUDE.md (if provided above)
4. **Dangerous patterns** — fire-and-forget async, process.exit() in library code, sync FS in hot paths

Do NOT flag:
- Style preferences or formatting (linters handle that)
- Minor improvements or nice-to-haves
- Issues already present before this diff (focus only on what changed)
- Test files (*.test.js, *.spec.js, *.test.php)

## Output Format

Start with exactly one of:
- PASS — no issues found
- WARN — minor issues, commit can proceed, but fix soon
- BLOCK — serious issue, commit must not proceed

Then list findings (if any):
[SEVERITY] file:line — description

If PASS, just write: PASS"

RESULT=$(echo "$REVIEW_PROMPT" | claude --print --model claude-haiku-4-5-20251001 2>/dev/null) || {
  echo "[ai-review] claude CLI failed — skipping (commit proceeds)"
  exit 0
}

VERDICT=$(echo "$RESULT" | grep -m1 -E '^(PASS|WARN|BLOCK)')

echo ""
echo "════════════════════════════════════════"
echo " AI Code Review"
echo "════════════════════════════════════════"
echo "$RESULT"
echo "════════════════════════════════════════"
echo ""

case "$VERDICT" in
  PASS)
    echo "[ai-review] ✓ PASS"
    exit 0
    ;;
  WARN)
    echo "[ai-review] ⚠ WARN — fix before next commit (or SKIP_AI_REVIEW=1 to suppress)"
    exit 0
    ;;
  BLOCK)
    echo "[ai-review] ✗ BLOCK — fix above issues, then re-commit"
    echo "[ai-review] To override: SKIP_AI_REVIEW=1 git commit"
    exit 1
    ;;
  *)
    echo "[ai-review] Unexpected response — skipping (commit proceeds)"
    exit 0
    ;;
esac
