#!/bin/sh
# afk-monitor.sh — Lightweight AFK pipeline health monitor for systemd timer
#
# Designed to run on the NixOS HOST (not inside the Docker container).
# Checks key health signals and writes a structured log entry.
# Exit codes: 0=healthy, 1=warnings, 2=critical
#
# Install: see afk-monitor.service / afk-monitor.timer alongside this script.
# Log output: ~/code/mmo-platform/logs/afk-monitor-YYYY-MM-DD.log
#
# POSIX sh — no bashisms — runs on NixOS without a full PATH.

PROJECT_DIR="${PROJECT_DIR:-/home/jason/code/333Method}"
PLATFORM_DIR="${PLATFORM_DIR:-/home/jason/code/mmo-platform}"
DB="$PROJECT_DIR/db/sites.db"
LOGS="$PROJECT_DIR/logs"
LOG_DIR="$PLATFORM_DIR/logs"
TODAY=$(date +%Y-%m-%d)
NOW=$(date +%Y-%m-%dT%H:%M:%S)
LOG_FILE="$LOG_DIR/afk-monitor-${TODAY}.log"

# Ensure log directory exists
mkdir -p "$LOG_DIR"

# sqlite3 wrapper with 5s busy timeout
sq() { sqlite3 "$DB" ".timeout 5000" "$@" 2>/dev/null; }

# Severity tracker — 0=ok, 1=warn, 2=critical
SEVERITY=0
set_warn()     { [ "$SEVERITY" -lt 1 ] && SEVERITY=1; }
set_critical() { SEVERITY=2; }

# Log a line with timestamp prefix
log() { printf "[%s] %s\n" "$NOW" "$1" >> "$LOG_FILE"; }
log_raw() { printf "%s\n" "$1" >> "$LOG_FILE"; }

# ─────────────────────────────────────────────────────────────────────────────
log "=== AFK MONITOR CHECK ==="

# ── Check 1: sqlite3 availability ────────────────────────────────────────────
if ! command -v sqlite3 >/dev/null 2>&1; then
  log "CRITICAL: sqlite3 not found — cannot check DB"
  set_critical
  echo "$SEVERITY" > "$LOG_DIR/afk-monitor-exit.txt"
  exit 2
fi

# ── Check 2: DB file existence ────────────────────────────────────────────────
if [ ! -f "$DB" ]; then
  log "CRITICAL: Database not found at $DB"
  set_critical
  echo "$SEVERITY" > "$LOG_DIR/afk-monitor-exit.txt"
  exit 2
fi

# ── Check 3: Orchestrator log freshness (last activity within 90 min) ────────
ORCH_LOG="$LOGS/orchestrator-${TODAY}.log"
if [ ! -f "$ORCH_LOG" ]; then
  # Try yesterday (handles midnight boundary)
  YESTERDAY=$(date -d 'yesterday' +%Y-%m-%d 2>/dev/null || date -v-1d +%Y-%m-%d 2>/dev/null)
  ORCH_LOG="$LOGS/orchestrator-${YESTERDAY}.log"
fi

if [ -f "$ORCH_LOG" ]; then
  # Get modification time in epoch seconds
  orch_mtime=$(date -r "$ORCH_LOG" +%s 2>/dev/null || stat -c %Y "$ORCH_LOG" 2>/dev/null || echo "0")
  now_epoch=$(date +%s)
  orch_age_min=$(( (now_epoch - orch_mtime) / 60 ))
  log "Orchestrator log age: ${orch_age_min}min ($ORCH_LOG)"
  if [ "$orch_age_min" -gt 90 ]; then
    log "WARN: Orchestrator log stale (${orch_age_min}min) — orchestrator may be stopped"
    set_warn
  fi

  # Check for CONSERVATION MODE
  if grep -q "CONSERVATION MODE" "$ORCH_LOG" 2>/dev/null; then
    conservation_line=$(grep "CONSERVATION MODE" "$ORCH_LOG" | tail -1)
    log "INFO: $conservation_line"
  fi
else
  log "WARN: No orchestrator log found for today or yesterday"
  set_warn
fi

# ── Check 4: Pipeline last cycle (pipeline_control table) ────────────────────
api_loop_at=$(sq "SELECT last_api_loop_at FROM pipeline_control WHERE id=1;")
browser_loop_at=$(sq "SELECT last_browser_loop_at FROM pipeline_control WHERE id=1;")

if [ -n "$api_loop_at" ]; then
  api_epoch=$(date -d "${api_loop_at}Z" +%s 2>/dev/null || date -jf "%Y-%m-%d %H:%M:%S" "${api_loop_at}" +%s 2>/dev/null || echo "0")
  now_epoch=$(date +%s)
  if [ "$api_epoch" -gt 0 ]; then
    api_age_min=$(( (now_epoch - api_epoch) / 60 ))
    log "API loop last cycle: ${api_loop_at} (${api_age_min}min ago)"
    if [ "$api_age_min" -gt 60 ]; then
      log "WARN: API loop stale (${api_age_min}min) — pipeline may be stopped"
      set_warn
    fi
  fi
else
  log "INFO: No pipeline_control data yet"
fi

if [ -n "$browser_loop_at" ] && [ -n "$api_loop_at" ]; then
  browser_epoch=$(date -d "${browser_loop_at}Z" +%s 2>/dev/null || echo "0")
  if [ "$browser_epoch" -gt 0 ] && [ "$api_epoch" -gt 0 ]; then
    browser_age_min=$(( (now_epoch - browser_epoch) / 60 ))
    api_age_min=$(( (now_epoch - api_epoch) / 60 ))
    if [ "$browser_age_min" -gt 30 ] && [ "$api_age_min" -lt 30 ]; then
      log "WARN: Browser loop HUNG (${browser_age_min}min stale while API loop active ${api_age_min}min)"
      set_warn
    fi
  fi
fi

# ── Check 5: Stuck sites (sites in non-terminal status, updated >4h ago) ─────
stuck_count=$(sq "SELECT COUNT(*) FROM sites WHERE status NOT IN ('ignored','failing','high_score','outreach_sent') AND updated_at < datetime('now','-4 hours') AND updated_at > datetime('now','-7 days');")
log "Stuck sites (non-terminal, >4h): ${stuck_count:-0}"
if [ "${stuck_count:-0}" -gt 50 ]; then
  log "WARN: ${stuck_count} stuck sites — overseer may need to run"
  set_warn
fi

# ── Check 6: Error rate — recent pipeline errors ──────────────────────────────
pipeline_log=$(ls -t "$LOGS"/pipeline-*.log 2>/dev/null | head -1)
if [ -f "$pipeline_log" ]; then
  recent_errors=$(tail -500 "$pipeline_log" 2>/dev/null | grep -c 'ERROR\|status code [45][0-9][0-9]\|ETIMEDOUT\|ECONNRESET' 2>/dev/null || echo "0")
  recent_lines=$(tail -500 "$pipeline_log" 2>/dev/null | wc -l | tr -d ' ')
  if [ "$recent_lines" -gt 0 ] && [ "$recent_errors" -gt 0 ]; then
    err_pct=$((recent_errors * 100 / recent_lines))
    log "Pipeline error rate (last 500 lines): ${recent_errors}/${recent_lines} (${err_pct}%)"
    if [ "$err_pct" -gt 30 ]; then
      log "CRITICAL: Pipeline error rate ${err_pct}% — circuit breaker territory"
      set_critical
    elif [ "$err_pct" -gt 10 ]; then
      log "WARN: Pipeline error rate ${err_pct}% — investigate"
      set_warn
    fi
  else
    log "Pipeline errors (last 500 lines): 0"
  fi
fi

# ── Check 7: Queue depth snapshot ────────────────────────────────────────────
found=$(sq "SELECT COUNT(*) FROM sites WHERE status='found';")
assets=$(sq "SELECT COUNT(*) FROM sites WHERE status='assets_captured';")
scored=$(sq "SELECT COUNT(*) FROM sites WHERE status='prog_scored';")
enriched=$(sq "SELECT COUNT(*) FROM sites WHERE status='enriched';")
proposals=$(sq "SELECT COUNT(*) FROM sites WHERE status='proposals_drafted';")
approved=$(sq "SELECT COUNT(*) FROM messages WHERE direction='outbound' AND approval_status='approved' AND delivery_status IS NULL;")
sent_24h=$(sq "SELECT COUNT(*) FROM messages WHERE direction='outbound' AND delivery_status IN ('sent','delivered') AND sent_at > datetime('now','-24 hours');")
inbound_24h=$(sq "SELECT COUNT(*) FROM messages WHERE direction='inbound' AND created_at > datetime('now','-24 hours');")

log_raw "  Queue: found=${found:-0} assets=${assets:-0} scored=${scored:-0} enriched=${enriched:-0} proposals=${proposals:-0}"
log_raw "  Outreach: approved=${approved:-0} sent_24h=${sent_24h:-0} inbound_24h=${inbound_24h:-0}"

# ── Check 8: Zombie / runaway process detection ───────────────────────────────
zombie_count=$(ps -eo stat --no-headers 2>/dev/null | grep -c '^Z' || echo "0")
log "Zombie processes: ${zombie_count}"
if [ "${zombie_count:-0}" -gt 50 ]; then
  log "WARN: >50 zombies — container restart may be needed"
  set_warn
fi

# ── Final status ──────────────────────────────────────────────────────────────
case "$SEVERITY" in
  0) log "STATUS: HEALTHY" ;;
  1) log "STATUS: WARNINGS — review log" ;;
  2) log "STATUS: CRITICAL — action required" ;;
esac
log "=== END CHECK (exit=$SEVERITY) ==="
log_raw ""

# Write exit code to a known file for external polling
echo "$SEVERITY" > "$LOG_DIR/afk-monitor-exit.txt"
exit "$SEVERITY"
