#!/bin/sh
# Pre-commit hook: scan staged diffs for PII patterns.
# Add to your pre-commit hook chain: sh scripts/check-pii.sh
#
# Exit 1 if PII found (blocks commit). Set SKIP_PII_CHECK=1 to bypass.

if [ "${SKIP_PII_CHECK}" = "1" ]; then
  exit 0
fi

DIFF=$(git diff --cached --diff-filter=ACM -U0)

if [ -z "$DIFF" ]; then
  exit 0
fi

FOUND=0

# Patterns to check (each is a regex + description)
check_pattern() {
  local pattern="$1"
  local desc="$2"
  local matches
  matches=$(echo "$DIFF" | grep -inE "^\+" | grep -iE "$pattern" | grep -v "^+++\|REDACTED\|CHANGE_ME\|example\.com\|check-pii" | head -5)
  if [ -n "$matches" ]; then
    echo "  ⚠ ${desc}:"
    echo "$matches" | sed 's/^/    /'
    FOUND=1
  fi
}

check_pattern 'paulh@|corpseo\.com' 'Personal email'
check_pattern 'Suite 255.*Barratt|Hurstville NSW 2220' 'Personal address'
check_pattern '0424.?225.?495' 'Personal phone'
check_pattern 'Paul Harvey' 'Real name (use PERSONA_NAME env var)'
check_pattern 'sk_live_|sk_test_' 'Stripe secret key'
check_pattern 'ghp_[A-Za-z0-9]{36}|gho_[A-Za-z0-9]{36}' 'GitHub token'
check_pattern 'AKIA[A-Z0-9]{16}' 'AWS access key'
check_pattern 'password\s*[:=]\s*["\x27][^"\x27]{6,}' 'Hardcoded password'

if [ "$FOUND" = "1" ]; then
  echo ""
  echo "❌ PII/secrets detected in staged changes. Fix before committing."
  echo "   Set SKIP_PII_CHECK=1 to bypass (use with care)."
  exit 1
fi
