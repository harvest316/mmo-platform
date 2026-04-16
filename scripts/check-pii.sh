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

# ── Known PII (project-specific) ──
check_pattern 'paulh@|corpseo\.com' 'Personal email'
check_pattern 'Suite 255.*Barratt|Hurstville NSW 2220' 'Personal address'
check_pattern '0424.?225.?495' 'Personal phone'
check_pattern 'Paul Harvey' 'Real name (use PERSONA_NAME env var)'

# ── Domain and brand names (should use env vars, not hardcoded) ──
check_pattern 'auditandfix\.com' 'Domain name (use BRAND_DOMAIN env var)'
check_pattern 'Audit\s*&\s*Fix|Audit&Fix|AuditFix' 'Brand name (use BRAND_NAME env var)'
check_pattern 'Marcus Webb' 'Persona name (use PERSONA_NAME env var)'

# ── Phone numbers (AU/international) ──
check_pattern '\+61[0-9 ]{9,12}' 'Australian phone number (+61)'
check_pattern '\b04[0-9]{2}[- ]?[0-9]{3}[- ]?[0-9]{3}\b' 'Australian mobile (04xx)'
check_pattern '\b\(0[2-9]\)[- ]?[0-9]{4}[- ]?[0-9]{4}\b' 'Australian landline'

# ── Credit card numbers ──
check_pattern '\b4[0-9]{15}\b' 'Visa card number'
check_pattern '\b5[1-5][0-9]{14}\b' 'Mastercard number'
check_pattern '\b3[47][0-9]{13}\b' 'Amex card number'

# ── Australian identifiers ──
check_pattern '\b[0-9]{2} [0-9]{3} [0-9]{3} [0-9]{3}\b' 'ABN (Australian Business Number)'
check_pattern '\b[0-9]{3} [0-9]{3} [0-9]{3}\b' 'Possible TFN (Tax File Number)'

# ── API keys and tokens ──
check_pattern 'sk_live_|sk_test_' 'Stripe secret key'
check_pattern 'ghp_[A-Za-z0-9]{36}|gho_[A-Za-z0-9]{36}' 'GitHub token'
check_pattern 'AKIA[A-Z0-9]{16}' 'AWS access key'
check_pattern 'sk-ant-[A-Za-z0-9-]{20,}' 'Anthropic API key'
check_pattern 'sk-or-[A-Za-z0-9-]{20,}' 'OpenRouter API key'
check_pattern 're_[A-Za-z0-9]{20,}' 'Resend API key (legacy — should not appear)'
check_pattern 'password\s*[:=]\s*["\x27][^"\x27]{6,}' 'Hardcoded password'

# ── Dynamic: check if any .env/.env.secrets VALUES appear in the diff ──
for envfile in .env .env.secrets; do
  if [ -f "$envfile" ]; then
    while IFS='=' read -r key value; do
      # Skip comments, empty lines, short values (<12 chars — too many false positives)
      case "$key" in \#*|"") continue ;; esac
      value=$(echo "$value" | sed 's/^["'\''"]//;s/["'\''"]$//')
      if [ ${#value} -ge 12 ]; then
        matches=$(echo "$DIFF" | grep -inF "^\+" | grep -F "$value" | grep -v "^+++\|\.env\|\.env\.secrets\|check-pii" | head -3)
        if [ -n "$matches" ]; then
          echo "  ⚠ Value from ${envfile} (${key}) found in staged diff:"
          echo "$matches" | sed 's/^/    /'
          FOUND=1
        fi
      fi
    done < "$envfile"
  fi
done

if [ "$FOUND" = "1" ]; then
  echo ""
  echo "❌ PII/secrets detected in staged changes. Fix before committing."
  echo "   Set SKIP_PII_CHECK=1 to bypass (use with care)."
  exit 1
fi
