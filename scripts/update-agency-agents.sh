#!/usr/bin/env bash
#
# update-agency-agents.sh -- Pull latest agency-agents and reinstall for Claude Code.
# Only logs when changes affect agents referenced in agency-agents-reference.md.
#
# Usage:
#   ./scripts/update-agency-agents.sh          # normal (quiet unless relevant changes)
#   ./scripts/update-agency-agents.sh --verbose # show all output
#   ./scripts/update-agency-agents.sh --dry-run # pull + diff only, don't install
#
# Designed to run via systemd timer (weekly) or manually.

set -euo pipefail

REPO_DIR="${HOME}/.local/share/agency-agents"
REFERENCE_DOC="${HOME}/code/mmo-platform/docs/agency-agents-reference.md"
LOG_TAG="agency-agents-update"

verbose=false
dry_run=false
while [[ $# -gt 0 ]]; do
  case "$1" in
    --verbose) verbose=true; shift ;;
    --dry-run) dry_run=true; verbose=true; shift ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

log() { logger -t "$LOG_TAG" "$*"; $verbose && echo "[agency-agents] $*" || true; }

# --- Preflight ---
if [[ ! -d "$REPO_DIR/.git" ]]; then
  echo "Error: $REPO_DIR is not a git repo. Clone it first:" >&2
  echo "  git clone https://github.com/msitarzewski/agency-agents.git $REPO_DIR" >&2
  exit 1
fi

# --- Pull ---
cd "$REPO_DIR"
git fetch origin main --quiet 2>/dev/null

local_sha=$(git rev-parse HEAD)
remote_sha=$(git rev-parse origin/main)

if [[ "$local_sha" == "$remote_sha" ]]; then
  log "Already up to date ($local_sha)"
  exit 0
fi

# --- Diff before merge ---
changed_files=$(git diff --name-only "$local_sha".."$remote_sha" -- '*.md')

if [[ -z "$changed_files" ]]; then
  log "Upstream moved ($local_sha -> $remote_sha) but no .md files changed"
  git pull --quiet origin main
  exit 0
fi

# Count changes
added=$(git diff --diff-filter=A --name-only "$local_sha".."$remote_sha" -- '*.md' | wc -l)
modified=$(git diff --diff-filter=M --name-only "$local_sha".."$remote_sha" -- '*.md' | wc -l)
deleted=$(git diff --diff-filter=D --name-only "$local_sha".."$remote_sha" -- '*.md' | wc -l)

log "Upstream update: +${added} added, ~${modified} modified, -${deleted} deleted agent files"

# --- Check relevance to our projects ---
# Extract agent names referenced in our curated doc (e.g., "**Code Reviewer**" -> "code-reviewer")
relevant_agents=""
if [[ -f "$REFERENCE_DOC" ]]; then
  # Pull bold agent names from markdown tables, convert to kebab-case filenames
  relevant_agents=$(grep -oP '\*\*\K[^*]+(?=\*\*)' "$REFERENCE_DOC" \
    | sed 's/ /-/g' \
    | tr '[:upper:]' '[:lower:]' \
    | sort -u)
fi

relevant_changes=()
while IFS= read -r file; do
  # Extract just the filename without path and extension
  basename=$(basename "$file" .md)
  # Check if any of our referenced agents match this filename
  while IFS= read -r agent; do
    [[ -z "$agent" ]] && continue
    if [[ "$basename" == *"$agent"* || "$agent" == *"$basename"* ]]; then
      relevant_changes+=("$file (matches: $agent)")
      break
    fi
  done <<< "$relevant_agents"
done <<< "$changed_files"

# --- Apply ---
if ! $dry_run; then
  git pull --quiet origin main
  bash scripts/install.sh --tool claude-code --no-interactive 2>/dev/null
  log "Installed from $local_sha -> $remote_sha"
fi

# --- Report ---
if [[ ${#relevant_changes[@]} -gt 0 ]]; then
  log "RELEVANT CHANGES for your projects:"
  for change in "${relevant_changes[@]}"; do
    log "  - $change"
  done
  # Also write to a file the daily report can pick up
  report_file="${HOME}/code/mmo-platform/logs/agency-agents-update.log"
  mkdir -p "$(dirname "$report_file")"
  {
    echo "--- $(date -Iseconds) ---"
    echo "Updated: $local_sha -> $remote_sha"
    echo "Relevant changes:"
    for change in "${relevant_changes[@]}"; do
      echo "  - $change"
    done
    echo ""
  } >> "$report_file"
else
  log "No changes affect agents in agency-agents-reference.md"
fi

if $verbose; then
  echo ""
  echo "All changed files:"
  echo "$changed_files" | sed 's/^/  /'
  echo ""
  echo "Agent count: $(ls ~/.claude/agents/*.md 2>/dev/null | wc -l)"
fi
