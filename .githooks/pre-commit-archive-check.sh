#!/usr/bin/env bash
# pre-commit-archive-check.sh — DR-223 enforcement (Layer 1)
#
# Blocks commits that introduce direct SES/Twilio/SMTP usage outside the
# approved archive wrappers. Layer 1 of 3: local friction. Layer 2 is the
# same check in CI (archive-enforcement.yml), which cannot be bypassed with
# --no-verify.
#
# Install (per child project):
#   echo 'git config core.hooksPath ../mmo-platform/.githooks' >> .envrc
#   direnv allow
#
# To override for a legitimate change to the wrapper files themselves:
#   SKIP_ARCHIVE_CHECK=1 git commit

set -euo pipefail

if [ "${SKIP_ARCHIVE_CHECK:-}" = "1" ]; then
  echo "[archive-check] Skipping (SKIP_ARCHIVE_CHECK=1)"
  exit 0
fi

# ── Allowlisted files: these ARE the wrappers, so they may contain the patterns ──

ALLOWLIST=(
  "mmo-platform/src/email.js"
  "mmo-platform/src/sms.js"
  "mmo-platform/src/archive.js"
  "mmo-platform/src/archive-uploader.js"
  "auditandfix-website/site/includes/ses-smtp.php"
  "auditandfix-website/site/includes/comms-archive.php"
)

# ── Forbidden patterns ────────────────────────────────────────────────────────

# JS/TS direct SES SDK imports and Twilio direct usage
JS_PATTERNS=(
  "from ['\"]@aws-sdk/client-ses['\"]"
  "from ['\"]@aws-sdk/client-sesv2['\"]"
  "require\(['\"]@aws-sdk/client-ses['\"]"
  "require\(['\"]@aws-sdk/client-sesv2['\"]"
  "from ['\"]twilio['\"]"
  "require\(['\"]twilio['\"]"
  "new SESClient"
  "new SESv2Client"
  "new SendEmailCommand"
  "new SendRawEmailCommand"
  "\.messages\.create("
  "email-smtp\.[a-z0-9-]+\.amazonaws\.com"
)

# PHP raw SMTP and mailer usage
PHP_PATTERNS=(
  "fsockopen.*smtp"
  "stream_socket_client.*smtp"
  "new PHPMailer"
)

# ── Get staged diff (added lines only) ───────────────────────────────────────

STAGED_DIFF=$(git diff --cached --diff-filter=ACM)
if [ -z "$STAGED_DIFF" ]; then
  exit 0
fi

# ── Check staged files against allowlist ─────────────────────────────────────

STAGED_FILES=$(git diff --cached --name-only --diff-filter=ACM)

ERRORS=()

for file in $STAGED_FILES; do
  # Check if this file is in the allowlist
  is_allowed=false
  for allowed in "${ALLOWLIST[@]}"; do
    if [[ "$file" == *"$allowed"* ]]; then
      is_allowed=true
      break
    fi
  done
  # Tests may use mocks — exempt test files
  if [[ "$file" == */test/* ]] || [[ "$file" == *.test.js ]] || [[ "$file" == *.test.ts ]] || [[ "$file" == *.spec.js ]] || [[ "$file" == *.spec.ts ]]; then
    is_allowed=true
  fi
  if [ "$is_allowed" = true ]; then
    continue
  fi

  # Get the added lines for this file
  FILE_DIFF=$(git diff --cached "$file" 2>/dev/null | grep '^+' | grep -v '^+++' || true)
  if [ -z "$FILE_DIFF" ]; then
    continue
  fi

  # Check JS patterns
  case "$file" in
    *.js|*.ts|*.mjs|*.cjs)
      for pattern in "${JS_PATTERNS[@]}"; do
        if echo "$FILE_DIFF" | grep -qP "$pattern"; then
          ERRORS+=("$file: forbidden pattern '$pattern' — must route through mmo-platform/src/archive.js wrapper")
        fi
      done
      ;;
    *.php)
      for pattern in "${PHP_PATTERNS[@]}"; do
        if echo "$FILE_DIFF" | grep -qiP "$pattern"; then
          ERRORS+=("$file: forbidden PHP SMTP/mailer pattern '$pattern' — must use site/includes/ses-smtp.php")
        fi
      done
      ;;
  esac

  # Check for twilio/ses packages being added to package.json in child projects
  if [[ "$file" == */package.json ]] && [[ "$file" != mmo-platform/package.json ]]; then
    if echo "$FILE_DIFF" | grep -qE '"twilio"|"@aws-sdk/client-ses'; then
      ERRORS+=("$file: forbidden package 'twilio' or '@aws-sdk/client-ses*' added to a child project — SES/Twilio are only allowed as deps of mmo-platform")
    fi
  fi
done

# ── Report ────────────────────────────────────────────────────────────────────

if [ ${#ERRORS[@]} -gt 0 ]; then
  echo ""
  echo "╔══════════════════════════════════════════════════════════════════╗"
  echo "║  DR-223: Content Archive Enforcement — COMMIT BLOCKED            ║"
  echo "╚══════════════════════════════════════════════════════════════════╝"
  echo ""
  echo "  Direct SES/Twilio/SMTP usage detected outside the archive wrapper."
  echo "  All email and SMS sends MUST route through:"
  echo "    Node:  mmo-platform/src/email.js or mmo-platform/src/sms.js"
  echo "    PHP:   auditandfix-website/site/includes/ses-smtp.php"
  echo ""
  for err in "${ERRORS[@]}"; do
    echo "  ✗ $err"
  done
  echo ""
  echo "  See: mmo-platform/CLAUDE.md — Code Review Rules > Content Archive"
  echo "  If you are editing the wrapper files themselves: SKIP_ARCHIVE_CHECK=1 git commit"
  echo ""
  exit 1
fi

exit 0
