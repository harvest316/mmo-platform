#!/bin/sh
# Unified monitoring checks — collects health data from both projects.
#
# Runs every 30min by Claude Code AFK session (Tier 3 monitoring).
# Pure data collection — no LLM inference, no cost.
#
# Output: JSON to stdout with combined pipeline health from both projects.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MMO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
METHOD_ROOT="$MMO_ROOT/../333Method"
TWOSTEP_ROOT="$MMO_ROOT/../2Step"

METHOD_DB="${DATABASE_PATH:-$METHOD_ROOT/db/sites.db}"
TWOSTEP_DB="${TWOSTEP_DATABASE_PATH:-$TWOSTEP_ROOT/db/2step.db}"
MESSAGES_DB="${MESSAGES_DB_PATH:-$MMO_ROOT/db/messages.db}"

NOW=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

# ── 333Method pipeline status ─────────────────────────────────────────────────

method_status=""
if [ -f "$METHOD_DB" ]; then
  method_status=$(sqlite3 "$METHOD_DB" "
    SELECT json_group_object(status, cnt) FROM (
      SELECT status, COUNT(*) as cnt FROM sites GROUP BY status
    );
  " 2>/dev/null || echo '{}')
fi

# ── 2Step pipeline status ─────────────────────────────────────────────────────

twostep_status=""
if [ -f "$TWOSTEP_DB" ]; then
  twostep_status=$(sqlite3 "$TWOSTEP_DB" "
    SELECT json_group_object(status, cnt) FROM (
      SELECT status, COUNT(*) as cnt FROM sites GROUP BY status
    );
  " 2>/dev/null || echo '{}')
fi

# ── Shared messages DB ────────────────────────────────────────────────────────

messages_summary=""
if [ -f "$MESSAGES_DB" ]; then
  messages_summary=$(sqlite3 "$MESSAGES_DB" "
    SELECT json_group_array(json_object(
      'project', project,
      'direction', direction,
      'approval_status', approval_status,
      'delivery_status', delivery_status,
      'count', cnt
    )) FROM (
      SELECT project, direction,
             COALESCE(approval_status, 'none') as approval_status,
             COALESCE(delivery_status, 'none') as delivery_status,
             COUNT(*) as cnt
      FROM messages
      GROUP BY project, direction, approval_status, delivery_status
    );
  " 2>/dev/null || echo '[]')

  # Eligible outreach (what can actually send right now)
  eligible=$(sqlite3 "$MESSAGES_DB" "
    SELECT COUNT(*) FROM messages
    WHERE direction = 'outbound'
      AND approval_status = 'approved'
      AND delivery_status IS NULL
      AND sent_at IS NULL
      AND contact_method IN ('email', 'sms');
  " 2>/dev/null || echo "0")

  # Inbound unprocessed
  unprocessed_inbound=$(sqlite3 "$MESSAGES_DB" "
    SELECT COUNT(*) FROM messages
    WHERE direction = 'inbound' AND processed_at IS NULL;
  " 2>/dev/null || echo "0")
fi

# ── Orchestrator health ──────────────────────────────────────────────────────

orch_log=""
orch_log_file="$METHOD_ROOT/logs/orchestrator-$(date +%Y-%m-%d).log"
if [ -f "$orch_log_file" ]; then
  # Last 5 backlog lines
  orch_log=$(grep "Backlog:" "$orch_log_file" 2>/dev/null | tail -3 || echo "no backlog lines")
fi

# Check if conservation mode is active
conservation="false"
if [ -f "$orch_log_file" ] && grep -q "CONSERVATION MODE" "$orch_log_file" 2>/dev/null; then
  last_conservation=$(grep "CONSERVATION MODE" "$orch_log_file" | tail -1)
  conservation="true"
fi

# ── Process health ───────────────────────────────────────────────────────────

# Check if orchestrator timer is active (systemd on host)
timer_active="unknown"
if command -v systemctl >/dev/null 2>&1; then
  timer_active=$(systemctl --user is-active mmo-cron.timer 2>/dev/null || echo "inactive")
fi

# ── Claude Max usage ─────────────────────────────────────────────────────────

usage_5h="unknown"
usage_weekly="unknown"
USAGE_CACHE="${HOME}/.claude/usage-cache.json"
if [ -f "$USAGE_CACHE" ]; then
  usage_5h=$(node -e "
    try { const d = JSON.parse(require('fs').readFileSync('$USAGE_CACHE','utf8'));
    process.stdout.write(String(Math.round((d.percent_5h || 0) * 100) / 100)); }
    catch { process.stdout.write('err'); }
  " 2>/dev/null || echo "err")
  usage_weekly=$(node -e "
    try { const d = JSON.parse(require('fs').readFileSync('$USAGE_CACHE','utf8'));
    process.stdout.write(String(Math.round((d.percent_weekly || 0) * 100) / 100)); }
    catch { process.stdout.write('err'); }
  " 2>/dev/null || echo "err")
fi

# ── Output ────────────────────────────────────────────────────────────────────

cat <<ENDJSON
{
  "timestamp": "$NOW",
  "333method_pipeline": $method_status,
  "2step_pipeline": ${twostep_status:-"{}"},
  "messages_summary": ${messages_summary:-"[]"},
  "eligible_outreach": ${eligible:-0},
  "unprocessed_inbound": ${unprocessed_inbound:-0},
  "orchestrator": {
    "timer_active": "$timer_active",
    "conservation_mode": $conservation,
    "recent_backlog": $(echo "$orch_log" | head -3 | python3 -c "import sys,json; print(json.dumps(sys.stdin.read().strip()))" 2>/dev/null || echo '""')
  },
  "usage": {
    "5h_percent": "$usage_5h",
    "weekly_percent": "$usage_weekly"
  }
}
ENDJSON
