#!/bin/sh
# Deploy files to auditandfix.com via FTP (curl)
# Usage:
#   deploy-website.sh <file> [file2...]     Upload specific files
#   deploy-website.sh --changed             Upload git-changed files in auditandfix.com/
#   deploy-website.sh --all                 Upload everything in auditandfix.com/
#
# Files are relative to the auditandfix.com/ directory.
# E.g.: deploy-website.sh index.php api.php assets/css/style.css

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLATFORM_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# Website source — currently in 333Method, will move to mmo-platform
SITE_DIR="${WEBSITE_DIR:-/home/jason/code/333Method/auditandfix.com}"

# Load FTP credentials
ENV_FILE="$PLATFORM_DIR/.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "ERROR: $ENV_FILE not found (needs FTP_HOST, FTP_USER, FTP_PASS)" >&2
  exit 1
fi

# Source .env (POSIX-safe line-by-line parse)
while IFS='=' read -r key value; do
  case "$key" in
    FTP_HOST|FTP_PORT|FTP_USER|FTP_PASS|FTP_REMOTE_PATH)
      # Strip surrounding quotes if present
      value=$(echo "$value" | sed "s/^['\"]//;s/['\"]$//")
      eval "$key='$value'"
      ;;
  esac
done < "$ENV_FILE"

if [ -z "$FTP_HOST" ] || [ -z "$FTP_USER" ] || [ -z "$FTP_PASS" ]; then
  echo "ERROR: FTP_HOST, FTP_USER, FTP_PASS must be set in $ENV_FILE" >&2
  exit 1
fi

FTP_PORT="${FTP_PORT:-21}"
FTP_REMOTE_PATH="${FTP_REMOTE_PATH:-/}"

# Build FTP base URL
FTP_BASE="ftp://${FTP_HOST}:${FTP_PORT}${FTP_REMOTE_PATH}"

upload_file() {
  local_path="$1"
  remote_path="$2"

  # Ensure remote directory exists by creating it (curl --ftp-create-dirs)
  curl --silent --show-error \
    --user "${FTP_USER}:${FTP_PASS}" \
    --ftp-create-dirs \
    --upload-file "$local_path" \
    "${FTP_BASE}${remote_path}"

  echo "  OK: ${remote_path}"
}

# Collect files to upload
FILES=""
if [ "$1" = "--changed" ]; then
  # Git-changed files in auditandfix.com/ (staged + unstaged + untracked)
  cd "$SITE_DIR/.."
  context="auditandfix.com"
  FILES=$(git diff --name-only HEAD -- "$context" 2>/dev/null || true)
  FILES="$FILES
$(git diff --name-only --cached -- "$context" 2>/dev/null || true)"
  FILES="$FILES
$(git ls-files --others --exclude-standard -- "$context" 2>/dev/null || true)"
  # Deduplicate and strip the context prefix
  FILES=$(echo "$FILES" | grep -v '^$' | sort -u | sed "s|^${context}/||")
elif [ "$1" = "--all" ]; then
  cd "$SITE_DIR"
  FILES=$(find . -type f \
    ! -name '*.example' \
    ! -path './.git/*' \
    ! -path './data/*' \
    | sed 's|^\./||' | sort)
else
  FILES="$*"
fi

if [ -z "$(echo "$FILES" | tr -d '[:space:]')" ]; then
  echo "Nothing to deploy."
  exit 0
fi

echo "Deploying to ${FTP_HOST}:${FTP_REMOTE_PATH}"
echo "---"

fail_count=0
ok_count=0

for f in $FILES; do
  local_file="${SITE_DIR}/${f}"
  if [ ! -f "$local_file" ]; then
    echo "  SKIP (not found): $f"
    continue
  fi
  if upload_file "$local_file" "$f"; then
    ok_count=$((ok_count + 1))
  else
    echo "  FAIL: $f"
    fail_count=$((fail_count + 1))
  fi
done

echo "---"
echo "Done: ${ok_count} uploaded, ${fail_count} failed."
[ "$fail_count" -eq 0 ]
