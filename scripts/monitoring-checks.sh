#!/bin/sh
# =============================================================================
# Unified MMO Platform Monitoring — SRE-grade health collector
# =============================================================================
# Covers BOTH projects: 333Method (SERP-to-outreach) and 2Step (video outreach).
# Runs every ~30 minutes by Claude Code AFK session (Tier 3 monitoring).
# POSIX sh for NixOS compatibility. Target execution: <15s.
#
# Usage:
#   sh scripts/monitoring-checks.sh           # full check cycle (human output)
#   sh scripts/monitoring-checks.sh --json    # JSON-only output (JSONL line)
#   sh scripts/monitoring-checks.sh --reset   # clear snapshot, start fresh
#
# =============================================================================
# SLI / SLO Definitions
# =============================================================================
#
# SLI 1: Pipeline Freshness
#   Definition: Time since last site entered each pipeline stage (per project).
#   SLO: No stage stale >2h during active hours (08:00-22:00 AEST).
#   Measurement: MAX(updated_at) per status, compared to now.
#   Alert: YELLOW >1h, RED >2h.
#
# SLI 2: Outreach Delivery Rate
#   Definition: sent / (sent + failed) over trailing 24h window.
#   SLO: >90% delivery success rate.
#   Measurement: COUNT by delivery_status from messages DB.
#   Alert: YELLOW <95%, RED <90%.
#
# SLI 3: Inbound Reply Latency
#   Definition: Time from inbound message receipt to auto-reply sent (p95).
#   SLO: <15min at p95.
#   Measurement: (reply.created_at - inbound.created_at) for matched pairs.
#   Alert: YELLOW >10min, RED >15min.
#
# SLI 4: API Error Budget
#   Definition: Error rate per API service in 30min window.
#   SLO: <5% error rate per service.
#   Measurement: error_lines / total_lines from log files.
#   Alert: YELLOW >3%, RED >5%.
#
# SLI 5: Pipeline Throughput
#   Definition: Sites processed per hour per stage.
#   SLO: >0 sites/hr for each active stage during active hours.
#   Measurement: COUNT of sites entering stage in last 1h.
#   Alert: YELLOW =0 for >1h, RED =0 for >2h.
#
# SLI 6: Orchestrator Liveness
#   Definition: Time since last batch processed by orchestrator.
#   SLO: <45min staleness.
#   Measurement: Last log entry timestamp in orchestrator log.
#   Alert: YELLOW >30min, RED >45min.
#
# =============================================================================

# No set -e: this is a data collector; we want ALL sections to run even if one fails

# ── Config ────────────────────────────────────────────────────────────────────
METHOD_DB="${DATABASE_PATH:-/home/jason/code/333Method/db/sites.db}"
TWOSTEP_DB="${TWOSTEP_DATABASE_PATH:-/home/jason/code/2Step/db/2step.db}"
MESSAGES_DB="${MESSAGES_DB_PATH:-/home/jason/code/mmo-platform/db/messages.db}"
METHOD_LOGS="/home/jason/code/333Method/logs"
TWOSTEP_LOGS="/home/jason/code/2Step/logs"
MMO_LOGS="/home/jason/code/mmo-platform/logs"
METHOD_DIR="/home/jason/code/333Method"
TWOSTEP_DIR="/home/jason/code/2Step"

SNAPSHOT="/tmp/mmo-monitor-snapshot.txt"
NOW_UTC=$(date -u +%Y-%m-%dT%H:%M:%SZ)
NOW_EPOCH=$(date +%s)
TODAY=$(date -u +%Y-%m-%d)

# Sydney time for active-hours check (AEST=UTC+10, AEDT=UTC+11)
SYDNEY_HOUR=$(TZ=Australia/Sydney date +%H 2>/dev/null || echo "12")

# 30 min ago timestamps (GNU date primary, BSD fallback)
HALF_HOUR_AGO=$(date -u -d '30 minutes ago' +%Y-%m-%dT%H:%M:%S 2>/dev/null \
  || date -u -v-30M +%Y-%m-%dT%H:%M:%S 2>/dev/null || echo "")
ONE_HOUR_AGO=$(date -u -d '1 hour ago' +%Y-%m-%dT%H:%M:%S 2>/dev/null \
  || date -u -v-1H +%Y-%m-%dT%H:%M:%S 2>/dev/null || echo "")
TWO_HOURS_AGO=$(date -u -d '2 hours ago' +%Y-%m-%dT%H:%M:%S 2>/dev/null \
  || date -u -v-2H +%Y-%m-%dT%H:%M:%S 2>/dev/null || echo "")
TWENTY_FOUR_AGO=$(date -u -d '24 hours ago' +%Y-%m-%dT%H:%M:%S 2>/dev/null \
  || date -u -v-24H +%Y-%m-%dT%H:%M:%S 2>/dev/null || echo "")

# ── DB wrappers (5s busy timeout) ─────────────────────────────────────────────
sqm() { sqlite3 "$METHOD_DB" ".timeout 5000" "$@" 2>/dev/null; }
sqt() { sqlite3 "$TWOSTEP_DB" ".timeout 5000" "$@" 2>/dev/null; }
sqmsg() { sqlite3 "$MESSAGES_DB" ".timeout 5000" "$@" 2>/dev/null; }

# ── Flags ─────────────────────────────────────────────────────────────────────
JSON_ONLY=false
if [ "$1" = "--reset" ]; then
  rm -f "$SNAPSHOT"
  echo "Snapshot cleared. Next run will be baseline."
  exit 0
fi
if [ "$1" = "--json" ]; then
  JSON_ONLY=true
fi

# ── Load previous snapshot ────────────────────────────────────────────────────
PREV_EXISTS=false
if [ -f "$SNAPSHOT" ]; then
  PREV_EXISTS=true
  # shellcheck disable=SC1090
  . "$SNAPSHOT"
fi

# ── Helpers ───────────────────────────────────────────────────────────────────

# delta(current_value, snapshot_key_suffix) — returns "+N", "-N", "=0 (STALL?)", or "NEW"
delta() {
  curr="$1"
  prev_var="prev_$2"
  eval prev_val="\${$prev_var:-}"
  if [ -z "$prev_val" ]; then
    echo "NEW"
  else
    d=$((curr - prev_val))
    if [ "$d" -gt 0 ]; then echo "+$d"
    elif [ "$d" -lt 0 ]; then echo "$d"
    else echo "=0 (STALL?)"
    fi
  fi
}

# slo_status(value, yellow_threshold, red_threshold, higher_is_worse)
# Returns GREEN, YELLOW, or RED
slo_status() {
  val="$1"; yellow="$2"; red="$3"; higher_worse="${4:-true}"
  if [ "$higher_worse" = "true" ]; then
    if [ "$val" -ge "$red" ] 2>/dev/null; then echo "RED"
    elif [ "$val" -ge "$yellow" ] 2>/dev/null; then echo "YELLOW"
    else echo "GREEN"
    fi
  else
    if [ "$val" -le "$red" ] 2>/dev/null; then echo "RED"
    elif [ "$val" -le "$yellow" ] 2>/dev/null; then echo "YELLOW"
    else echo "GREEN"
    fi
  fi
}

# safe_val — default to 0 if empty
sv() { echo "${1:-0}"; }

# Check if DB file exists and is readable
db_ok() { [ -f "$1" ] && [ -r "$1" ]; }

# Epoch from ISO timestamp (handles both UTC and local)
# Orchestrator logs use local AEST time ("2026-03-18 22:13:46") without TZ suffix.
# SQLite datetime('now') is UTC. Gate files use ISO-8601 with Z suffix.
# We try multiple formats and let date figure out the right one.
ts_to_epoch() {
  _input="$1"
  # If input has Z suffix, it's UTC
  case "$_input" in
    *Z) date -u -d "$(echo "$_input" | sed 's/Z$//')" +%s 2>/dev/null && return ;;
  esac
  # Try as-is (local time interpretation by GNU date)
  date -d "$_input" +%s 2>/dev/null && return
  # BSD date fallback
  date -j -f "%Y-%m-%dT%H:%M:%S" "$_input" +%s 2>/dev/null && return
  date -j -f "%Y-%m-%d %H:%M:%S" "$_input" +%s 2>/dev/null && return
  echo "0"
}

# Minutes since a timestamp (returns absolute value, never negative)
mins_since() {
  _ts_epoch=$(ts_to_epoch "$1")
  if [ "$_ts_epoch" -gt 0 ] 2>/dev/null; then
    _diff=$(( (NOW_EPOCH - _ts_epoch) / 60 ))
    # Absolute value — timezone mismatches can cause negatives
    if [ "$_diff" -lt 0 ] 2>/dev/null; then _diff=$(( -_diff )); fi
    echo "$_diff"
  else
    echo "999"
  fi
}

# ── JSON accumulator ─────────────────────────────────────────────────────────
# We build JSON pieces and assemble at the end
json_slo_pipeline_freshness="unknown"
json_slo_delivery_rate="unknown"
json_slo_reply_latency="unknown"
json_slo_api_errors="unknown"
json_slo_throughput="unknown"
json_slo_orchestrator="unknown"

# Collect SLO verdicts — stored as individual variables for POSIX compat
# (grep -oP is not POSIX; use plain variables instead)
slo_pipeline_freshness="UNKNOWN"
slo_delivery_rate="UNKNOWN"
slo_reply_latency="UNKNOWN"
slo_api_errors="UNKNOWN"
slo_pipeline_throughput="UNKNOWN"
slo_orchestrator_liveness="UNKNOWN"
add_slo() {
  eval "slo_$1=\"$2\""
}

# ══════════════════════════════════════════════════════════════════════════════
# JSON-ONLY fast path — collect minimal data and emit JSONL, then exit
# ══════════════════════════════════════════════════════════════════════════════

if [ "$JSON_ONLY" = "true" ]; then
  mkdir -p "$MMO_LOGS"

  # Pipeline statuses
  _jm="{}"; _jt="{}"
  db_ok "$METHOD_DB" && _jm=$(sqm "SELECT json_group_object(status, cnt) FROM (
    SELECT status, COUNT(*) as cnt FROM sites GROUP BY status);" 2>/dev/null || echo '{}')
  db_ok "$TWOSTEP_DB" && _jt=$(sqt "SELECT json_group_object(status, cnt) FROM (
    SELECT status, COUNT(*) as cnt FROM sites GROUP BY status);" 2>/dev/null || echo '{}')

  # Outreach
  _je=0; _jd=0; _jf=0
  if db_ok "$MESSAGES_DB"; then
    _je=$(sqmsg "SELECT COUNT(*) FROM messages WHERE direction='outbound' AND approval_status='approved' AND delivery_status IS NULL AND sent_at IS NULL AND contact_method IN ('email','sms');" || echo 0)
    _jd=$(sqmsg "SELECT COUNT(*) FROM messages WHERE direction='outbound' AND delivery_status IN ('sent','delivered') AND delivered_at > datetime('now','-24 hours');" || echo 0)
    _jf=$(sqmsg "SELECT COUNT(*) FROM messages WHERE direction='outbound' AND delivery_status='failed' AND updated_at > datetime('now','-24 hours');" || echo 0)
  fi
  _jdenom=$(( $(sv $_jd) + $(sv $_jf) ))
  _jdel_pct=100; [ "$_jdenom" -gt 0 ] && _jdel_pct=$(( $(sv $_jd) * 100 / _jdenom ))

  # Staleness
  _jstale=0
  db_ok "$METHOD_DB" && _jstale=$(sqm "SELECT COALESCE(CAST(
    (julianday('now') - julianday(MIN(latest))) * 24 * 60 AS INTEGER), 0)
    FROM (SELECT status, MAX(updated_at) as latest FROM sites
    WHERE status NOT IN ('outreach_sent','ignored','failing','high_score')
    GROUP BY status);" 2>/dev/null || echo 0)

  # Orchestrator idle
  _jidle=999
  _jorch="$METHOD_LOGS/orchestrator-$(date +%Y-%m-%d).log"
  [ ! -f "$_jorch" ] && _jorch="$METHOD_LOGS/orchestrator-$(date -u +%Y-%m-%d).log"
  if [ -f "$_jorch" ]; then
    _jts=$(tail -20 "$_jorch" 2>/dev/null | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}' | tail -1)
    if [ -n "$_jts" ]; then
      _jepoch=$(TZ=Australia/Sydney date -d "$_jts" +%s 2>/dev/null || echo "0")
      [ "$_jepoch" -gt 0 ] 2>/dev/null && _jidle=$(( (NOW_EPOCH - _jepoch) / 60 ))
      [ "$_jidle" -lt 0 ] && _jidle=0
    fi
  fi

  # Usage
  _ju5="?"; _juw="?"
  if [ -f "${HOME}/.claude/usage-cache.json" ]; then
    _ju5=$(node -e "try{const d=JSON.parse(require('fs').readFileSync('${HOME}/.claude/usage-cache.json','utf8'));process.stdout.write(String(Math.round(d.five_hour||d.percent_5h||0)));}catch{process.stdout.write('?');}" 2>/dev/null || echo "?")
    _juw=$(node -e "try{const d=JSON.parse(require('fs').readFileSync('${HOME}/.claude/usage-cache.json','utf8'));process.stdout.write(String(Math.round(d.seven_day||d.percent_weekly||0)));}catch{process.stdout.write('?');}" 2>/dev/null || echo "?")
  fi

  # System
  _jz=$(ps -eo stat --no-headers 2>/dev/null | grep -c '^Z' || echo "0")
  _jn=$(pgrep -c node 2>/dev/null || echo "0")

  # SLO verdicts (quick)
  _jfs="GREEN"; _jds="GREEN"; _jas="GREEN"; _jos="GREEN"
  [ "$(sv $_jstale)" -ge 120 ] 2>/dev/null && _jfs="RED"
  [ "$(sv $_jdel_pct)" -le 90 ] 2>/dev/null && _jds="RED"
  [ "$(sv $_jidle)" -ge 45 ] 2>/dev/null && _jos="RED"

  _jslo="{\"pipeline_freshness\":\"$_jfs\",\"delivery_rate\":\"$_jds\",\"api_errors\":\"$_jas\",\"orchestrator_liveness\":\"$_jos\"}"
  _joverall="GREEN"
  case "$_jfs$_jds$_jas$_jos" in *RED*) _joverall="RED" ;; *YELLOW*) _joverall="YELLOW" ;; esac

  _jline="{\"timestamp\":\"$NOW_UTC\",\"overall\":\"$_joverall\",\"slos\":$_jslo,\"333method\":$_jm,\"2step\":$_jt,\"eligible_outreach\":$(sv $_je),\"delivered_24h\":$(sv $_jd),\"failed_24h\":$(sv $_jf),\"orch_idle_min\":$_jidle,\"usage_5h\":\"$_ju5\",\"usage_weekly\":\"$_juw\",\"zombies\":$_jz,\"node_procs\":$_jn,\"max_stage_stale_min\":$(sv $_jstale),\"max_error_pct\":0}"
  echo "$_jline" >> "$MMO_LOGS/monitoring-${TODAY}.json"
  echo "$_jline"
  exit 0
fi

# ══════════════════════════════════════════════════════════════════════════════
# Begin human-readable output
# ══════════════════════════════════════════════════════════════════════════════

echo "+=======================================================================+"
echo "|       MMO UNIFIED MONITOR -- $NOW_UTC       |"
echo "|  Sydney: $(TZ=Australia/Sydney date '+%H:%M %Z' 2>/dev/null || echo '??:?? AEST')    Active hours: 08-22    Projects: 333Method + 2Step  |"
echo "+=======================================================================+"
echo ""

# ══════════════════════════════════════════════════════════════════════════════
# SECTION A: PIPELINE HEALTH (both projects)
# ══════════════════════════════════════════════════════════════════════════════
echo "+-----------------------------------------------------------------------+"
echo "| A. PIPELINE HEALTH                                                    |"
echo "+-----------------------------------------------------------------------+"
echo "# Status counts with snapshot deltas. STALL = same count as last check."
echo "# Stuck sites: same status for >4h with no progress."
echo ""

# ── 333Method pipeline ──
echo "-- 333Method Pipeline --"
if db_ok "$METHOD_DB"; then
  echo "  Status                    Count   Delta"
  echo "  -----------------------------------------------"
  m_found=$(sqm "SELECT COUNT(*) FROM sites WHERE status='found';")
  m_assets=$(sqm "SELECT COUNT(*) FROM sites WHERE status='assets_captured';")
  m_prog=$(sqm "SELECT COUNT(*) FROM sites WHERE status='prog_scored';")
  m_semantic=$(sqm "SELECT COUNT(*) FROM sites WHERE status IN ('semantic_scored','vision_scored');")
  m_enriched=$(sqm "SELECT COUNT(*) FROM sites WHERE status='enriched';")
  m_proposals=$(sqm "SELECT COUNT(*) FROM sites WHERE status='proposals_drafted';")
  m_sent=$(sqm "SELECT COUNT(*) FROM sites WHERE status='outreach_sent';")
  m_ignored=$(sqm "SELECT COUNT(*) FROM sites WHERE status IN ('ignored','failing','high_score');")
  m_total=$(sqm "SELECT COUNT(*) FROM sites;")

  printf "  %-25s %7s  (%s)\n" "found"             "$(sv $m_found)"     "$(delta "$(sv $m_found)" "m_found")"
  printf "  %-25s %7s  (%s)\n" "assets_captured"    "$(sv $m_assets)"    "$(delta "$(sv $m_assets)" "m_assets")"
  printf "  %-25s %7s  (%s)\n" "prog_scored"        "$(sv $m_prog)"      "$(delta "$(sv $m_prog)" "m_prog")"
  printf "  %-25s %7s  (%s)\n" "semantic/vision"    "$(sv $m_semantic)"  "$(delta "$(sv $m_semantic)" "m_semantic")"
  printf "  %-25s %7s  (%s)\n" "enriched"           "$(sv $m_enriched)"  "$(delta "$(sv $m_enriched)" "m_enriched")"
  printf "  %-25s %7s  (%s)\n" "proposals_drafted"  "$(sv $m_proposals)" "$(delta "$(sv $m_proposals)" "m_proposals")"
  printf "  %-25s %7s  (%s)\n" "outreach_sent"      "$(sv $m_sent)"      "$(delta "$(sv $m_sent)" "m_sent")"
  printf "  %-25s %7s\n"       "ignored/failing/hi" "$(sv $m_ignored)"
  printf "  %-25s %7s\n"       "TOTAL"              "$(sv $m_total)"
  echo ""

  # Stuck sites (>4h in same status, not terminal)
  echo "  -- Stuck sites (>4h, non-terminal) --"
  m_stuck=$(sqm "SELECT COUNT(*) FROM sites
    WHERE status NOT IN ('outreach_sent','ignored','failing','high_score')
      AND updated_at < datetime('now','-4 hours');")
  if [ "$(sv $m_stuck)" -gt 0 ]; then
    printf "  *** %s sites stuck >4h ***\n" "$m_stuck"
    sqm "SELECT status, COUNT(*), MIN(updated_at) as oldest FROM sites
      WHERE status NOT IN ('outreach_sent','ignored','failing','high_score')
        AND updated_at < datetime('now','-4 hours')
      GROUP BY status ORDER BY COUNT(*) DESC LIMIT 10;" | while IFS='|' read -r st cnt oldest; do
      printf "    %-25s %5s  (oldest: %s)\n" "$st" "$cnt" "$oldest"
    done
  else
    echo "    None stuck. Pipeline flowing."
  fi
  echo ""

  # Stage transition rate (last 30min) — uses updated_at as proxy
  echo "  -- Stage transitions (last 30min) --"
  if [ -n "$HALF_HOUR_AGO" ]; then
    sqm "SELECT status, COUNT(*) FROM sites
      WHERE updated_at > '$HALF_HOUR_AGO'
        AND status NOT IN ('ignored','failing','high_score')
      GROUP BY status ORDER BY COUNT(*) DESC;" | while IFS='|' read -r st cnt; do
      printf "    %-25s %5s entered\n" "$st" "$cnt"
    done
  else
    echo "    (timestamp computation unavailable)"
  fi
  echo ""

  # Pipeline freshness — flag stages stale >2h (using SQLite for reliable UTC math)
  echo "  -- Stale stages (>2h since last activity, SQLite UTC) --"
  _stale_stages=$(sqm "SELECT status,
      MAX(updated_at) as latest,
      CAST((julianday('now') - julianday(MAX(updated_at))) * 24 * 60 AS INTEGER) as age_min
    FROM sites
    WHERE status NOT IN ('outreach_sent','ignored','failing','high_score')
    GROUP BY status
    HAVING age_min > 120
    ORDER BY age_min DESC;")
  if [ -n "$_stale_stages" ]; then
    echo "$_stale_stages" | while IFS='|' read -r st latest age; do
      printf "    STALE: %-20s %smin ago  (last: %s)\n" "$st" "$age" "$latest"
    done
  else
    echo "    All active stages fresh (<2h)."
  fi
else
  echo "  [333Method DB not found: $METHOD_DB]"
fi
echo ""

# ── 2Step pipeline ──
echo "-- 2Step Pipeline --"
if db_ok "$TWOSTEP_DB"; then
  echo "  Status                    Count   Delta"
  echo "  -----------------------------------------------"
  t_found=$(sqt "SELECT COUNT(*) FROM sites WHERE status='found';")
  t_video=$(sqt "SELECT COUNT(*) FROM sites WHERE status='video_created';")
  t_outreach=$(sqt "SELECT COUNT(*) FROM sites WHERE status='outreach_sent';")
  t_replied=$(sqt "SELECT COUNT(*) FROM sites WHERE status='replied';")
  t_total=$(sqt "SELECT COUNT(*) FROM sites;")

  # 2Step has simpler statuses — show all
  sqt "SELECT status, COUNT(*) FROM sites GROUP BY status ORDER BY COUNT(*) DESC;" | while IFS='|' read -r st cnt; do
    d=$(delta "$cnt" "t_${st}")
    printf "  %-25s %7s  (%s)\n" "$st" "$cnt" "$d"
  done
  printf "  %-25s %7s\n" "TOTAL" "$(sv $t_total)"
  echo ""

  # Stuck sites
  t_stuck=$(sqt "SELECT COUNT(*) FROM sites
    WHERE status NOT IN ('outreach_sent','replied','closed','ignored')
      AND updated_at < datetime('now','-4 hours');")
  if [ "$(sv $t_stuck)" -gt 0 ]; then
    printf "  *** %s sites stuck >4h ***\n" "$t_stuck"
    sqt "SELECT status, COUNT(*) FROM sites
      WHERE status NOT IN ('outreach_sent','replied','closed','ignored')
        AND updated_at < datetime('now','-4 hours')
      GROUP BY status;" | while IFS='|' read -r st cnt; do
      printf "    %-25s %5s stuck\n" "$st" "$cnt"
    done
  else
    echo "  No stuck sites."
  fi
else
  echo "  [2Step DB not found: $TWOSTEP_DB]"
fi
echo ""

# ══════════════════════════════════════════════════════════════════════════════
# SECTION B: OUTREACH TRUST DASHBOARD
# ══════════════════════════════════════════════════════════════════════════════
echo "+-----------------------------------------------------------------------+"
echo "| B. OUTREACH TRUST DASHBOARD                                           |"
echo "+-----------------------------------------------------------------------+"
echo "# Ground truth: what actually sent, what is blocked, what is queued."
echo ""

if db_ok "$MESSAGES_DB"; then
  # Approved-unsent per channel per project
  echo "-- Approved Unsent (ready to send) --"
  echo "  Project     Channel    Count"
  echo "  -----------------------------------------------"
  sqmsg "SELECT project, contact_method, COUNT(*) FROM messages
    WHERE direction='outbound'
      AND approval_status='approved'
      AND delivery_status IS NULL
      AND sent_at IS NULL
      AND contact_method IN ('email','sms')
    GROUP BY project, contact_method
    ORDER BY project, contact_method;" | while IFS='|' read -r proj method cnt; do
    printf "  %-12s %-10s %5s\n" "$proj" "$method" "$cnt"
  done
  eligible_total=$(sqmsg "SELECT COUNT(*) FROM messages
    WHERE direction='outbound'
      AND approval_status='approved'
      AND delivery_status IS NULL
      AND sent_at IS NULL
      AND contact_method IN ('email','sms');")
  printf "  %-12s %-10s %5s\n" "TOTAL" "--" "$(sv $eligible_total)"
  echo ""

  # Actually delivered in last 24h
  echo "-- Delivered (last 24h, ground truth) --"
  echo "  Project     Channel    Status       Count"
  echo "  -----------------------------------------------"
  sqmsg "SELECT project, contact_method, delivery_status, COUNT(*) FROM messages
    WHERE direction='outbound'
      AND delivered_at > datetime('now','-24 hours')
    GROUP BY project, contact_method, delivery_status
    ORDER BY project, contact_method;" | while IFS='|' read -r proj method status cnt; do
    printf "  %-12s %-10s %-12s %5s\n" "$proj" "$method" "$status" "$cnt"
  done
  delivered_24h=$(sqmsg "SELECT COUNT(*) FROM messages
    WHERE direction='outbound'
      AND delivery_status IN ('sent','delivered')
      AND delivered_at > datetime('now','-24 hours');")
  failed_24h=$(sqmsg "SELECT COUNT(*) FROM messages
    WHERE direction='outbound'
      AND delivery_status='failed'
      AND updated_at > datetime('now','-24 hours');")
  printf "  Sent/delivered 24h: %s   Failed 24h: %s\n" "$(sv $delivered_24h)" "$(sv $failed_24h)"
  echo ""

  # Delivery rate SLI
  _del_denom=$(( $(sv $delivered_24h) + $(sv $failed_24h) ))
  if [ "$_del_denom" -gt 0 ]; then
    delivery_rate_pct=$(( $(sv $delivered_24h) * 100 / _del_denom ))
    _del_status=$(slo_status "$delivery_rate_pct" "95" "90" "false")
    printf "  SLI: Delivery rate (24h) = %s%%  [%s]  (SLO: >90%%)\n" "$delivery_rate_pct" "$_del_status"
    add_slo "delivery_rate" "$_del_status"
  else
    echo "  SLI: Delivery rate = N/A (no deliveries in 24h)"
    delivery_rate_pct=100
    add_slo "delivery_rate" "GREEN"
  fi
  echo ""

  # Blocked breakdown
  echo "-- Blocked Outreach Breakdown --"
  echo "  Reason                  Count"
  echo "  -----------------------------------------------"
  # Failed/retry
  _failed=$(sqmsg "SELECT COUNT(*) FROM messages
    WHERE direction='outbound' AND approval_status='approved'
      AND delivery_status IN ('failed','retry_later');")
  printf "  %-25s %5s\n" "failed/retry_later" "$(sv $_failed)"
  # Parked
  _parked=$(sqmsg "SELECT COUNT(*) FROM messages WHERE approval_status='parked';")
  printf "  %-25s %5s\n" "parked (awaiting orch)" "$(sv $_parked)"
  # Rework
  _rework=$(sqmsg "SELECT COUNT(*) FROM messages WHERE approval_status='rework';")
  printf "  %-25s %5s\n" "rework (needs review)" "$(sv $_rework)"
  # Non-email/sms channels
  _other_ch=$(sqmsg "SELECT COUNT(*) FROM messages
    WHERE direction='outbound' AND approval_status='approved'
      AND contact_method NOT IN ('email','sms');")
  printf "  %-25s %5s\n" "form/x/linkedin (skip)" "$(sv $_other_ch)"
  echo ""

  # Follow-up queue depth
  echo "-- Follow-up Queue --"
  _fu1=$(sqmsg "SELECT COUNT(*) FROM messages
    WHERE message_type='followup1' AND sent_at IS NULL AND approval_status='approved';")
  _fu2=$(sqmsg "SELECT COUNT(*) FROM messages
    WHERE message_type='followup2' AND sent_at IS NULL AND approval_status='approved';")
  printf "  Follow-up 1 due: %s    Follow-up 2 due: %s\n" "$(sv $_fu1)" "$(sv $_fu2)"
else
  echo "  [Messages DB not found: $MESSAGES_DB]"
fi
echo ""

# ══════════════════════════════════════════════════════════════════════════════
# SECTION C: API ERROR RATES
# ══════════════════════════════════════════════════════════════════════════════
echo "+-----------------------------------------------------------------------+"
echo "| C. API ERROR RATES (last 30min)                                       |"
echo "+-----------------------------------------------------------------------+"
echo "# >5% = RED (SLO breach). >3% = YELLOW. Check circuit breakers."
echo ""

# Build timestamp filter for 30-min window (same approach as 333Method)
_awk_pat=""
if command -v date >/dev/null 2>&1; then
  _ts_filter=""
  _i=0
  while [ "$_i" -le 35 ]; do
    _ts_filter="$_ts_filter $(date -u -d "${_i} minutes ago" +%Y-%m-%dT%H:%M 2>/dev/null \
      || date -u -v-${_i}M +%Y-%m-%dT%H:%M 2>/dev/null)"
    _i=$((_i + 1))
  done
  _tz_filter=""
  _i=0
  while [ "$_i" -le 35 ]; do
    _tz_filter="$_tz_filter $(TZ=Australia/Sydney date -d "${_i} minutes ago" +%Y-%m-%dT%H:%M 2>/dev/null \
      || TZ=Australia/Sydney date -v-${_i}M +%Y-%m-%dT%H:%M 2>/dev/null)"
    _i=$((_i + 1))
  done
  _all_minutes="$_ts_filter $_tz_filter"
  _awk_pat=$(echo "$_all_minutes" | tr ' ' '\n' | grep -v '^$' | sort -u | tr '\n' '|' | sed 's/|$//')
fi

_max_error_pct=0
_api_json=""

scan_logs() {
  _log_dir="$1"
  _project="$2"
  [ ! -d "$_log_dir" ] && return

  for logprefix in pipeline scoring rescoring enrich serps outreach proposals app; do
    logfile=$(ls -t "$_log_dir"/"$logprefix"-*.log 2>/dev/null | head -1)
    if [ -f "$logfile" ]; then
      if [ -n "$_awk_pat" ]; then
        recent=$(grep -E "$_awk_pat" "$logfile" 2>/dev/null)
      else
        recent=$(tail -1000 "$logfile" 2>/dev/null)
      fi
      errors=$(echo "$recent" | grep -c 'status code [45][0-9][0-9]\|Timed out\|ETIMEDOUT\|ECONNRESET\|ECONNREFUSED\|Breaker is open' 2>/dev/null || echo "0")
      total=$(echo "$recent" | wc -l | tr -d ' ')
      if [ "$total" -gt 0 ] 2>/dev/null && [ "$errors" -gt 0 ] 2>/dev/null; then
        pct=$((errors * 100 / total))
        if [ "$pct" -gt "$_max_error_pct" ] 2>/dev/null; then _max_error_pct=$pct; fi
        _api_status=$(slo_status "$pct" "3" "5" "true")
        printf "  %-10s %-15s  errors: %5s / %7s lines  (%d%%)  [%s]\n" \
          "$_project" "$logprefix" "$errors" "$total" "$pct" "$_api_status"
      else
        printf "  %-10s %-15s  errors: 0\n" "$_project" "$logprefix"
      fi
    fi
  done
}

scan_logs "$METHOD_LOGS" "333Method"
scan_logs "$TWOSTEP_LOGS" "2Step"

if [ "$_max_error_pct" -ge 5 ]; then
  add_slo "api_errors" "RED"
elif [ "$_max_error_pct" -ge 3 ]; then
  add_slo "api_errors" "YELLOW"
else
  add_slo "api_errors" "GREEN"
fi
echo ""

# Recent error samples
echo "-- Recent error samples (last 10 unique, 30min window) --"
for _dir in "$METHOD_LOGS" "$TWOSTEP_LOGS"; do
  [ ! -d "$_dir" ] && continue
  for logprefix in pipeline scoring rescoring enrich serps outreach proposals app cron; do
    logfile=$(ls -t "$_dir"/"$logprefix"-*.log 2>/dev/null | head -1)
    if [ -f "$logfile" ]; then
      if [ -n "$_awk_pat" ]; then
        grep -E "$_awk_pat" "$logfile" 2>/dev/null | grep -i 'ERROR\|status code [45]' | tail -5
      else
        tail -200 "$logfile" 2>/dev/null | grep -i 'ERROR\|status code [45]' | tail -5
      fi
    fi
  done
done | sort -u | tail -10
echo ""

# ══════════════════════════════════════════════════════════════════════════════
# SECTION D: INBOUND & REPLIES
# ══════════════════════════════════════════════════════════════════════════════
echo "+-----------------------------------------------------------------------+"
echo "| D. INBOUND & REPLIES                                                  |"
echo "+-----------------------------------------------------------------------+"
echo "# Unprocessed inbound = pipeline gap. Reply latency = customer experience."
echo ""

if db_ok "$MESSAGES_DB"; then
  # Unprocessed inbound queue
  _unprocessed=$(sqmsg "SELECT COUNT(*) FROM messages
    WHERE direction='inbound' AND processed_at IS NULL;")
  printf "  Unprocessed inbound queue: %s\n" "$(sv $_unprocessed)"

  # Unclassified inbound (no intent)
  _unclassified=$(sqmsg "SELECT COUNT(*) FROM messages
    WHERE direction='inbound' AND intent IS NULL AND message_body IS NOT NULL;")
  printf "  Unclassified (no intent):  %s\n" "$(sv $_unclassified)"

  # Classified but no reply sent yet (excluding opt-out/autoresponder)
  _unreplied=$(sqmsg "SELECT COUNT(*) FROM messages m
    WHERE m.direction='inbound'
      AND m.intent IS NOT NULL
      AND m.intent NOT IN ('opt-out','autoresponder')
      AND NOT EXISTS (
        SELECT 1 FROM messages m2
        WHERE m2.site_id=m.site_id AND m2.project=m.project
          AND m2.direction='outbound' AND m2.message_type='reply'
          AND m2.created_at > m.created_at
      );" 2>/dev/null || echo "0")
  printf "  Awaiting reply:            %s\n" "$(sv $_unreplied)"
  echo ""

  # Reply latency (p95)
  echo "-- Reply Latency (last 24h) --"
  _reply_latency_p95=$(sqmsg "
    WITH reply_pairs AS (
      SELECT
        inb.id as inb_id,
        MIN(outb.created_at) as reply_at,
        inb.created_at as inb_at,
        ROUND((julianday(MIN(outb.created_at)) - julianday(inb.created_at)) * 24 * 60, 1) as latency_min
      FROM messages inb
      JOIN messages outb ON outb.site_id = inb.site_id
        AND outb.project = inb.project
        AND outb.direction = 'outbound'
        AND outb.message_type = 'reply'
        AND outb.created_at > inb.created_at
      WHERE inb.direction = 'inbound'
        AND inb.created_at > datetime('now','-24 hours')
        AND inb.intent NOT IN ('opt-out','autoresponder')
      GROUP BY inb.id
    )
    SELECT COALESCE(
      (SELECT latency_min FROM reply_pairs
       ORDER BY latency_min DESC
       LIMIT 1 OFFSET (SELECT MAX(0, CAST(COUNT(*)*0.05 AS INTEGER)) FROM reply_pairs)),
      -1
    );" 2>/dev/null || echo "-1")

  if [ "$_reply_latency_p95" != "-1" ] && [ -n "$_reply_latency_p95" ]; then
    _lat_int=$(echo "$_reply_latency_p95" | cut -d. -f1)
    _lat_int=${_lat_int:-0}
    _lat_status=$(slo_status "$_lat_int" "10" "15" "true")
    printf "  p95 reply latency: %s min  [%s]  (SLO: <15min)\n" "$_reply_latency_p95" "$_lat_status"
    add_slo "reply_latency" "$_lat_status"
  else
    echo "  p95 reply latency: N/A (no reply pairs in 24h)"
    add_slo "reply_latency" "GREEN"
  fi
  echo ""

  # New inbound since last check
  echo "-- New Inbound (last 30min) --"
  if [ -n "$HALF_HOUR_AGO" ]; then
    _new_inbound_count=$(sqmsg "SELECT COUNT(*) FROM messages
      WHERE direction='inbound' AND created_at > '$HALF_HOUR_AGO';")
    printf "  New inbound messages: %s\n" "$(sv $_new_inbound_count)"
    if [ "$(sv $_new_inbound_count)" -gt 0 ]; then
      sqmsg "SELECT project, contact_method, contact_uri,
              substr(message_body, 1, 80) as preview,
              intent, created_at
        FROM messages
        WHERE direction='inbound' AND created_at > '$HALF_HOUR_AGO'
        ORDER BY created_at DESC LIMIT 10;" | while IFS='|' read -r proj method uri preview intent cat; do
        printf "    [%s] %s %s\n" "$proj" "$method" "$uri"
        printf "      Intent: %s  Body: %s\n" "${intent:-unclassified}" "$preview"
        printf "      At: %s\n" "$cat"
      done
    fi
  fi
else
  echo "  [Messages DB not found: $MESSAGES_DB]"
fi
echo ""

# ══════════════════════════════════════════════════════════════════════════════
# SECTION E: ORCHESTRATOR HEALTH
# ══════════════════════════════════════════════════════════════════════════════
echo "+-----------------------------------------------------------------------+"
echo "| E. ORCHESTRATOR HEALTH                                                |"
echo "+-----------------------------------------------------------------------+"
echo "# Timer active, conservation mode, throttle gates, batch health."
echo ""

# Systemd timer/service
echo "-- Systemd Status --"
_timer="unknown"
_service="unknown"
if command -v systemctl >/dev/null 2>&1; then
  _timer=$(systemctl --user is-active claude-orchestrator.timer 2>/dev/null || echo "inactive")
  _service=$(systemctl --user is-active claude-orchestrator.service 2>/dev/null || echo "inactive")
  echo "  Timer:   $_timer"
  echo "  Service: $_service (inactive/dead = normal between runs)"
  if systemctl --user is-failed claude-orchestrator.service >/dev/null 2>&1; then
    echo "  WARNING: claude-orchestrator.service is FAILED"
  fi
else
  echo "  systemctl not available"
fi
echo ""

# Orchestrator log analysis
ORCH_LOG="$METHOD_LOGS/orchestrator-$(date +%Y-%m-%d).log"
[ ! -f "$ORCH_LOG" ] && ORCH_LOG="$METHOD_LOGS/orchestrator-$(date -u +%Y-%m-%d).log"

_orch_idle_min=999
if [ -f "$ORCH_LOG" ]; then
  echo "-- Last Activity --"
  _last_ts=$(tail -20 "$ORCH_LOG" 2>/dev/null | grep -oE '[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}' | tail -1)
  if [ -n "$_last_ts" ]; then
    # Orchestrator log timestamps are in local AEST/AEDT — force TZ for correct epoch
    _last_epoch=$(TZ=Australia/Sydney date -d "$_last_ts" +%s 2>/dev/null || echo "0")
    if [ "$_last_epoch" -gt 0 ] 2>/dev/null; then
      _orch_idle_min=$(( (NOW_EPOCH - _last_epoch) / 60 ))
      [ "$_orch_idle_min" -lt 0 ] && _orch_idle_min=0
    else
      _orch_idle_min=999
    fi
    printf "  Last log entry: %s AEST  (%smin ago)\n" "$_last_ts" "$_orch_idle_min"
    if [ "$_orch_idle_min" -gt 45 ]; then
      echo "  *** ORCHESTRATOR STALE >45min -- check systemd timer ***"
    elif [ "$_orch_idle_min" -gt 30 ]; then
      echo "  WARN: Orchestrator idle >30min"
    fi
  else
    echo "  Last log entry: (no timestamps found)"
  fi

  # Conservation mode
  echo ""
  echo "-- Conservation Mode --"
  if grep -q "CONSERVATION MODE ON" "$ORCH_LOG" 2>/dev/null; then
    _cons_line=$(grep "CONSERVATION MODE" "$ORCH_LOG" | tail -1)
    echo "  ACTIVE: $_cons_line"
  else
    echo "  Not active"
  fi

  # Throttle gates
  echo ""
  echo "-- Throttle Gates --"
  _last_backlog=$(grep "Backlog:" "$ORCH_LOG" 2>/dev/null | tail -1)
  if [ -n "$_last_backlog" ]; then
    echo "  $_last_backlog"
    # Parse gate values
    _eligible=$(echo "$_last_backlog" | grep -oE 'eligible_outreach=[0-9]+' | cut -d= -f2)
    _proposals=$(echo "$_last_backlog" | grep -oE 'proposals_drafted=[0-9]+' | cut -d= -f2)
    _enriched_bl=$(echo "$_last_backlog" | grep -oE 'enriched=[0-9]+' | cut -d= -f2)
    printf "  Gate 1 (outreach >90):   eligible=%s  %s\n" "$(sv $_eligible)" \
      "$([ "$(sv $_eligible)" -gt 90 ] 2>/dev/null && echo 'FIRING' || echo 'open')"
    printf "  Gate 2 (proposals >45):  proposals=%s  %s\n" "$(sv $_proposals)" \
      "$([ "$(sv $_proposals)" -gt 45 ] 2>/dev/null && echo 'FIRING' || echo 'open')"
    printf "  Gate 3 (enriched >15):   enriched=%s  %s\n" "$(sv $_enriched_bl)" \
      "$([ "$(sv $_enriched_bl)" -gt 15 ] 2>/dev/null && echo 'FIRING' || echo 'open')"
  else
    echo "  No backlog lines in today's orchestrator log."
  fi

  # LLM batch output health
  echo ""
  echo "-- LLM Batch Health --"
  _stored=$(grep -Ec "stored successfully|store: " "$ORCH_LOG" 2>/dev/null); _stored=${_stored:-0}
  _empty=$(grep -Fc "claude returned empty" "$ORCH_LOG" 2>/dev/null); _empty=${_empty:-0}
  _alert=$(grep -Ec "ALERT:|consecutive_empty=[5-9]|consecutive_empty=[0-9][0-9]" "$ORCH_LOG" 2>/dev/null); _alert=${_alert:-0}
  _fatal=$(grep -Fc "FATAL:" "$ORCH_LOG" 2>/dev/null); _fatal=${_fatal:-0}
  printf "  Batches stored: %s  Empty: %s  ALERTs: %s  FATALs: %s\n" \
    "$_stored" "$_empty" "$_alert" "$_fatal"
  if [ "${_fatal:-0}" -gt 0 ]; then
    echo "  *** FATAL errors -- LLM batches non-functional ***"
    grep "FATAL:" "$ORCH_LOG" | tail -2 | while read -r line; do echo "    $line"; done
  fi

  # Recent orchestrator errors
  echo ""
  echo "-- Recent Orchestrator Errors (last 30 log lines) --"
  _orch_errors=$(tail -30 "$ORCH_LOG" 2>/dev/null | grep -i "error\|WARN\|ALERT\|FATAL\|failed\|CONSERVATION" 2>/dev/null)
  if [ -n "$_orch_errors" ]; then
    echo "$_orch_errors" | while read -r line; do echo "    $line"; done
  else
    echo "    (none)"
  fi
else
  echo "  No orchestrator log found for today."
  _orch_idle_min=999
fi

# Orchestrator liveness SLI
if [ "$_orch_idle_min" -ge 45 ]; then
  add_slo "orchestrator_liveness" "RED"
elif [ "$_orch_idle_min" -ge 30 ]; then
  add_slo "orchestrator_liveness" "YELLOW"
else
  add_slo "orchestrator_liveness" "GREEN"
fi
echo ""

# Claude Max usage
echo "-- Claude Max Usage --"
USAGE_CACHE="${HOME}/.claude/usage-cache.json"
_usage_5h="?"
_usage_weekly="?"
if [ -f "$USAGE_CACHE" ]; then
  _usage_5h=$(node -e "try{const d=JSON.parse(require('fs').readFileSync('$USAGE_CACHE','utf8'));process.stdout.write(String(Math.round(d.five_hour||d.percent_5h||0)));}catch{process.stdout.write('?');}" 2>/dev/null || echo "?")
  _usage_weekly=$(node -e "try{const d=JSON.parse(require('fs').readFileSync('$USAGE_CACHE','utf8'));process.stdout.write(String(Math.round(d.seven_day||d.percent_weekly||0)));}catch{process.stdout.write('?');}" 2>/dev/null || echo "?")
  _usage_stale=$(node -e "try{const d=JSON.parse(require('fs').readFileSync('$USAGE_CACHE','utf8'));process.stdout.write(d.stale?'yes':'no');}catch{process.stdout.write('unknown');}" 2>/dev/null || echo "unknown")
  printf "  5h: %s%%  weekly: %s%%  (stale: %s)\n" "$_usage_5h" "$_usage_weekly" "$_usage_stale"
  if [ "$_usage_5h" != "?" ] && [ "$_usage_5h" -ge 80 ] 2>/dev/null; then
    echo "  WARNING: 5h usage at ${_usage_5h}%% -- conservation mode likely"
  fi
else
  echo "  Usage cache not found"
fi
echo ""

# Gates file
GATE_FILE="$METHOD_DIR/logs/orchestrator-gates.json"
if [ -f "$GATE_FILE" ]; then
  echo "-- Frequency Gates (last run) --"
  _oversee_ts=$(node -e "try{const d=JSON.parse(require('fs').readFileSync('$GATE_FILE','utf8'));process.stdout.write(d.oversee||'never');}catch{process.stdout.write('never');}" 2>/dev/null || echo "unknown")
  _classify_ts=$(node -e "try{const d=JSON.parse(require('fs').readFileSync('$GATE_FILE','utf8'));process.stdout.write(d.classify_errors||'never');}catch{process.stdout.write('never');}" 2>/dev/null || echo "unknown")
  printf "  oversee:         %s  (%smin ago)\n" "$_oversee_ts" "$(mins_since "$_oversee_ts")"
  printf "  classify_errors: %s  (%smin ago)\n" "$_classify_ts" "$(mins_since "$_classify_ts")"
fi
echo ""

# ══════════════════════════════════════════════════════════════════════════════
# SECTION F: SYSTEM RESOURCES
# ══════════════════════════════════════════════════════════════════════════════
echo "+-----------------------------------------------------------------------+"
echo "| F. SYSTEM RESOURCES                                                   |"
echo "+-----------------------------------------------------------------------+"
echo ""

echo "-- Load & Uptime --"
uptime 2>/dev/null || echo "  (uptime unavailable)"
echo ""

echo "-- Memory --"
free -m 2>/dev/null || echo "  (free unavailable)"
_free_mb=$(free -m 2>/dev/null | awk '/^Mem:/ {print $7}')
if [ -n "$_free_mb" ] && [ "$_free_mb" -lt 500 ] 2>/dev/null; then
  echo "  *** LOW MEMORY: ${_free_mb}MB available -- reduce concurrency ***"
fi
echo ""

echo "-- Disk --"
df -h /home/jason/code /tmp 2>/dev/null
_disk_pct=$(df /home/jason/code 2>/dev/null | awk 'NR==2 {gsub(/%/,"",$5); print $5}')
if [ -n "$_disk_pct" ] && [ "$_disk_pct" -gt 90 ] 2>/dev/null; then
  echo "  *** HIGH DISK USAGE: ${_disk_pct}%% -- run log rotation ***"
fi
echo ""

echo "-- Zombies & Processes --"
_zombie_count=$(ps -eo stat --no-headers 2>/dev/null | grep -c '^Z' 2>/dev/null || true)
_zombie_count=${_zombie_count:-0}
_node_count=$(pgrep -c node 2>/dev/null || true)
_node_count=${_node_count:-0}
_chrome_count=$(pgrep -c chromium 2>/dev/null || true)
_chrome_count=${_chrome_count:-0}
printf "  Zombies: %s   Node processes: %s   Chromium: %s\n" "$_zombie_count" "$_node_count" "$_chrome_count"
if [ "$_zombie_count" -gt 50 ]; then
  echo "  *** >50 ZOMBIES -- container restart recommended ***"
fi
echo ""

# ══════════════════════════════════════════════════════════════════════════════
# SECTION G: SLO STATUS SUMMARY
# ══════════════════════════════════════════════════════════════════════════════
echo "+-----------------------------------------------------------------------+"
echo "| G. SLO STATUS SUMMARY                                                |"
echo "+-----------------------------------------------------------------------+"
echo ""

# Pipeline freshness SLI — compute max staleness across active stages
# Use a single query that returns the max across all groups (avoids subshell pipe issue)
_max_stale=0
if db_ok "$METHOD_DB"; then
  _m_stale=$(sqm "SELECT COALESCE(CAST(
    (julianday('now') - julianday(MIN(latest))) * 24 * 60 AS INTEGER), 0)
    FROM (
      SELECT status, MAX(updated_at) as latest FROM sites
      WHERE status NOT IN ('outreach_sent','ignored','failing','high_score')
      GROUP BY status
    );" 2>/dev/null)
  _m_stale=${_m_stale:-0}
  [ "$_m_stale" -gt "$_max_stale" ] 2>/dev/null && _max_stale=$_m_stale
fi
if db_ok "$TWOSTEP_DB"; then
  _t_stale=$(sqt "SELECT COALESCE(CAST(
    (julianday('now') - julianday(MIN(latest))) * 24 * 60 AS INTEGER), 0)
    FROM (
      SELECT status, MAX(updated_at) as latest FROM sites
      WHERE status NOT IN ('outreach_sent','replied','closed','ignored')
      GROUP BY status
    );" 2>/dev/null)
  _t_stale=${_t_stale:-0}
  [ "$_t_stale" -gt "$_max_stale" ] 2>/dev/null && _max_stale=$_t_stale
fi

# Only alert during active hours
_freshness_status="GREEN"
if [ "$SYDNEY_HOUR" -ge 8 ] 2>/dev/null && [ "$SYDNEY_HOUR" -lt 22 ] 2>/dev/null; then
  _freshness_status=$(slo_status "$_max_stale" "60" "120" "true")
else
  # Outside active hours, relax thresholds
  _freshness_status=$(slo_status "$_max_stale" "240" "480" "true")
fi
add_slo "pipeline_freshness" "$_freshness_status"

# Pipeline throughput SLI — sites processed in last 1h
_throughput_status="GREEN"
if db_ok "$METHOD_DB" && [ -n "$ONE_HOUR_AGO" ]; then
  _m_throughput=$(sqm "SELECT COUNT(*) FROM sites
    WHERE updated_at > '$ONE_HOUR_AGO'
      AND status NOT IN ('ignored','failing','high_score');" 2>/dev/null)
  _m_throughput=$(sv $_m_throughput)
  if [ "$SYDNEY_HOUR" -ge 8 ] 2>/dev/null && [ "$SYDNEY_HOUR" -lt 22 ] 2>/dev/null; then
    if [ "$_m_throughput" -eq 0 ] 2>/dev/null; then
      # Check if 2h stall
      _m_2h=$(sqm "SELECT COUNT(*) FROM sites
        WHERE updated_at > '$TWO_HOURS_AGO'
          AND status NOT IN ('ignored','failing','high_score');" 2>/dev/null)
      if [ "$(sv $_m_2h)" -eq 0 ] 2>/dev/null; then
        _throughput_status="RED"
      else
        _throughput_status="YELLOW"
      fi
    fi
  fi
fi
add_slo "pipeline_throughput" "$_throughput_status"

# Print summary table
echo "  SLI                        Status     Detail"
echo "  -----------------------------------------------"
printf "  %-27s [%-6s]  Max stage staleness: %smin (SLO: <120min active hrs)\n" \
  "Pipeline Freshness" "$slo_pipeline_freshness" "$_max_stale"
printf "  %-27s [%-6s]  %s%% delivery rate (SLO: >90%%)\n" \
  "Outreach Delivery Rate" "$slo_delivery_rate" "${delivery_rate_pct:-N/A}"
_display_latency="${_reply_latency_p95:-N/A}"
case "$_display_latency" in -1|"-1"*) _display_latency="N/A" ;; esac
printf "  %-27s [%-6s]  p95: %s min (SLO: <15min)\n" \
  "Inbound Reply Latency" "$slo_reply_latency" "$_display_latency"
printf "  %-27s [%-6s]  Max error rate: %s%% (SLO: <5%%)\n" \
  "API Error Budget" "$slo_api_errors" "$_max_error_pct"
printf "  %-27s [%-6s]  333Method: %s sites/hr (SLO: >0 active hrs)\n" \
  "Pipeline Throughput" "$slo_pipeline_throughput" "$(sv $_m_throughput)"
printf "  %-27s [%-6s]  Idle: %smin (SLO: <45min)\n" \
  "Orchestrator Liveness" "$slo_orchestrator_liveness" "$_orch_idle_min"
echo ""

# Overall health
_red_count=0
_yellow_count=0
for _v in pipeline_freshness delivery_rate reply_latency api_errors pipeline_throughput orchestrator_liveness; do
  eval "_val=\$slo_${_v}"
  case "$_val" in
    RED) _red_count=$((_red_count + 1)) ;;
    YELLOW) _yellow_count=$((_yellow_count + 1)) ;;
  esac
done

if [ "$_red_count" -gt 0 ]; then
  printf "  OVERALL: RED (%s SLO breaches, %s warnings)\n" "$_red_count" "$_yellow_count"
elif [ "$_yellow_count" -gt 0 ]; then
  printf "  OVERALL: YELLOW (%s warnings, 0 breaches)\n" "$_yellow_count"
else
  echo "  OVERALL: GREEN (all SLOs met)"
fi
echo ""

# ══════════════════════════════════════════════════════════════════════════════
# SNAPSHOT MANAGEMENT
# ══════════════════════════════════════════════════════════════════════════════

echo "+-----------------------------------------------------------------------+"
echo "| SNAPSHOT                                                              |"
echo "+-----------------------------------------------------------------------+"

# ── Write snapshot ────────────────────────────────────────────────────────────
{
  echo "# mmo-monitor snapshot $NOW_UTC"

  # 333Method pipeline counts
  if db_ok "$METHOD_DB"; then
    echo "prev_m_found=$(sv $(sqm "SELECT COUNT(*) FROM sites WHERE status='found';"))"
    echo "prev_m_assets=$(sv $(sqm "SELECT COUNT(*) FROM sites WHERE status='assets_captured';"))"
    echo "prev_m_prog=$(sv $(sqm "SELECT COUNT(*) FROM sites WHERE status='prog_scored';"))"
    echo "prev_m_semantic=$(sv $(sqm "SELECT COUNT(*) FROM sites WHERE status IN ('semantic_scored','vision_scored');"))"
    echo "prev_m_enriched=$(sv $(sqm "SELECT COUNT(*) FROM sites WHERE status='enriched';"))"
    echo "prev_m_proposals=$(sv $(sqm "SELECT COUNT(*) FROM sites WHERE status='proposals_drafted';"))"
    echo "prev_m_sent=$(sv $(sqm "SELECT COUNT(*) FROM sites WHERE status='outreach_sent';"))"
  fi

  # 2Step pipeline counts (dynamic — store all statuses)
  if db_ok "$TWOSTEP_DB"; then
    sqt "SELECT status, COUNT(*) FROM sites GROUP BY status;" | while IFS='|' read -r st cnt; do
      _safe=$(echo "$st" | sed 's/[^a-zA-Z0-9]/_/g')
      echo "prev_t_${_safe}=$cnt"
    done
  fi

  # Messages DB
  if db_ok "$MESSAGES_DB"; then
    echo "prev_eligible=$(sv $(sqmsg "SELECT COUNT(*) FROM messages
      WHERE direction='outbound' AND approval_status='approved'
        AND delivery_status IS NULL AND sent_at IS NULL
        AND contact_method IN ('email','sms');"))"
    echo "prev_delivered_24h=$(sv $(sqmsg "SELECT COUNT(*) FROM messages
      WHERE direction='outbound' AND delivery_status IN ('sent','delivered')
        AND delivered_at > datetime('now','-24 hours');"))"
    echo "prev_inbound_unprocessed=$(sv $(sqmsg "SELECT COUNT(*) FROM messages
      WHERE direction='inbound' AND processed_at IS NULL;"))"
  fi
} > "$SNAPSHOT"

if [ "$PREV_EXISTS" = "true" ]; then
  echo "  Snapshot updated -- deltas computed against previous run."
else
  echo "  First run -- baseline snapshot written. Deltas available on next run."
fi
echo ""

# ── Write JSONL ───────────────────────────────────────────────────────────────
mkdir -p "$MMO_LOGS"

# Build JSON object
_json_method_status="{}"
if db_ok "$METHOD_DB"; then
  _json_method_status=$(sqm "SELECT json_group_object(status, cnt) FROM (
    SELECT status, COUNT(*) as cnt FROM sites GROUP BY status);" 2>/dev/null || echo '{}')
fi

_json_twostep_status="{}"
if db_ok "$TWOSTEP_DB"; then
  _json_twostep_status=$(sqt "SELECT json_group_object(status, cnt) FROM (
    SELECT status, COUNT(*) as cnt FROM sites GROUP BY status);" 2>/dev/null || echo '{}')
fi

# Flatten SLO verdicts into proper JSON using the slo_* variables
_slo_json="{\"pipeline_freshness\":\"$slo_pipeline_freshness\",\"delivery_rate\":\"$slo_delivery_rate\",\"reply_latency\":\"$slo_reply_latency\",\"api_errors\":\"$slo_api_errors\",\"pipeline_throughput\":\"$slo_pipeline_throughput\",\"orchestrator_liveness\":\"$slo_orchestrator_liveness\"}"

# Overall status
_overall="GREEN"
[ "$_yellow_count" -gt 0 ] 2>/dev/null && _overall="YELLOW"
[ "$_red_count" -gt 0 ] 2>/dev/null && _overall="RED"

cat >> "$MMO_LOGS/monitoring-${TODAY}.json" <<ENDJSON
{"timestamp":"$NOW_UTC","overall":"${_overall:-UNKNOWN}","slos":${_slo_json},"333method":${_json_method_status},"2step":${_json_twostep_status},"eligible_outreach":$(sv ${eligible_total:-0}),"delivered_24h":$(sv ${delivered_24h:-0}),"failed_24h":$(sv ${failed_24h:-0}),"orch_idle_min":${_orch_idle_min:-999},"usage_5h":"${_usage_5h:-?}","usage_weekly":"${_usage_weekly:-?}","zombies":${_zombie_count:-0},"node_procs":${_node_count:-0},"max_stage_stale_min":${_max_stale:-0},"max_error_pct":${_max_error_pct:-0}}
ENDJSON

# ══════════════════════════════════════════════════════════════════════════════
# FINAL REMINDER
# ══════════════════════════════════════════════════════════════════════════════
echo "+-----------------------------------------------------------------------+"
echo "| CLAUDE CODE AFK REMINDER                                              |"
echo "+-----------------------------------------------------------------------+"
echo "| Your job: find issues that Tier 1 (cron) and orchestrator MISSED.    |"
echo "| For each issue: investigate -> fix code -> commit -> verify.          |"
echo "|                                                                       |"
echo "| Check BOTH projects:                                                  |"
echo "|   333Method: SERP pipeline, orchestrator, outreach                    |"
echo "|   2Step:     video pipeline, prospect outreach                        |"
echo "|                                                                       |"
echo "| Then: sleep 1800 && sh scripts/monitoring-checks.sh                   |"
echo "+-----------------------------------------------------------------------+"
echo ""
