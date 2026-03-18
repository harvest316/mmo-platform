#!/bin/sh
# Unified Orchestrator — mmo-platform
#
# Thin coordination layer over 333Method and 2Step batch processors.
# Shares one Claude Max usage pool, one conservation mode, one loop.
#
# Usage:
#   scripts/orchestrator.sh              # One cycle, then exit
#   scripts/orchestrator.sh --loop       # Repeat until all queues empty
#   scripts/orchestrator.sh --type <t>   # Run one batch type only (all projects)
#   scripts/orchestrator.sh --project 333m|2step  # Limit to one project

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MMO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
METHOD_ROOT="$MMO_ROOT/../333Method"
TWOSTEP_ROOT="$MMO_ROOT/../2Step"

# ── PATH / Claude resolution ──────────────────────────────────────────────────
# Same logic as 333Method orchestrator — NixOS systemd services have a minimal PATH.
if ! command -v node >/dev/null 2>&1; then
  for d in /nix/store/*-nodejs-22.*/bin; do
    if [ -x "$d/node" ]; then
      export PATH="$d:$PATH"
      break
    fi
  done
fi

_claude_bin=""
if [ -d "$HOME/.local/share/claude/versions" ]; then
  for _v in $(ls -1 "$HOME/.local/share/claude/versions" 2>/dev/null | sort -V -r); do
    _candidate="$HOME/.local/share/claude/versions/$_v"
    if [ -x "$_candidate" ] && [ -s "$_candidate" ]; then
      _claude_bin="$_candidate"
      break
    fi
  done
fi
if [ -z "$_claude_bin" ] || [ ! -x "$_claude_bin" ]; then
  _claude_bin=$(command -v claude 2>/dev/null || echo "")
fi
export CLAUDE_BIN="$_claude_bin"

# ── Log file (self-managed when non-interactive) ──────────────────────────────
mkdir -p "$MMO_ROOT/logs"
if [ ! -t 1 ]; then
  LOG_FILE="$MMO_ROOT/logs/orchestrator-$(date +%Y-%m-%d).log"
  exec >> "$LOG_FILE" 2>&1
fi

# ── Shared state ──────────────────────────────────────────────────────────────
LOOP=false
SINGLE_TYPE=""
SINGLE_PROJECT=""  # "" = both, "333m" or "2step"

CONSERVATION_MODE=false
CONSERVATION_REASON=""
CONSECUTIVE_LIMIT_HITS=0
LIMIT_HIT_SINCE=""
WARNING_PCT=85
WEEKLY_WARNING_PCT=90
USAGE_CACHE="${HOME}/.claude/usage-cache.json"

# Gate file — tracks last-run timestamps for frequency-gated batches.
# Separate from 333Method's gate file so they don't interfere.
GATE_FILE="$MMO_ROOT/logs/orchestrator-gates.json"

# ── Arg parsing ───────────────────────────────────────────────────────────────
while [ $# -gt 0 ]; do
  case "$1" in
    --loop)     LOOP=true; shift ;;
    --type)     SINGLE_TYPE="$2"; shift 2 ;;
    --project)  SINGLE_PROJECT="$2"; shift 2 ;;
    *) echo "Unknown arg: $1"; exit 1 ;;
  esac
done

# ── Batch sizes ───────────────────────────────────────────────────────────────
M_PROPOSALS_EMAIL_BATCH=5
M_PROPOSALS_SMS_BATCH=10
M_PROOFREAD_BATCH=50
M_CLASSIFY_BATCH=50
M_NAMES_BATCH=50
M_REPLIES_BATCH=10
M_SCORE_SEMANTIC_BATCH=50
M_ENRICH_SITES_BATCH=15

TS_PROPOSALS_EMAIL_BATCH=5
TS_PROPOSALS_SMS_BATCH=10
TS_PROOFREAD_BATCH=20
TS_CLASSIFY_BATCH=20
TS_NAMES_BATCH=20
TS_REPLIES_BATCH=5

# ── Logging ───────────────────────────────────────────────────────────────────
log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] [mmo-orch] $*"
}

log "Starting unified orchestrator (loop=$LOOP type=${SINGLE_TYPE:-all} project=${SINGLE_PROJECT:-both}) CLAUDE_BIN=$CLAUDE_BIN"

# ── Gate helpers ──────────────────────────────────────────────────────────────
is_due() {
  gate_type="$1"
  interval_mins="$2"
  if [ ! -f "$GATE_FILE" ]; then return 0; fi
  elapsed=$(node -e "
    try {
      const d = JSON.parse(require('fs').readFileSync('$GATE_FILE','utf8'));
      const ts = d['$gate_type'];
      process.stdout.write(ts ? String(Date.now() - new Date(ts).getTime()) : '0');
    } catch { process.stdout.write('0'); }
  " 2>/dev/null)
  [ -z "$elapsed" ] && elapsed=0
  threshold=$((interval_mins * 60 * 1000))
  [ "$elapsed" -ge "$threshold" ] 2>/dev/null || [ "$elapsed" = "0" ]
}

mark_ran() {
  gate_type="$1"
  node -e "
    const fs = require('fs');
    let d = {};
    try { d = JSON.parse(fs.readFileSync('$GATE_FILE','utf8')); } catch {}
    d['$gate_type'] = new Date().toISOString();
    fs.writeFileSync('$GATE_FILE', JSON.stringify(d, null, 2));
  " 2>/dev/null
}

# ── Usage checking ────────────────────────────────────────────────────────────
# Identical logic to 333Method orchestrator — reads the same usage-cache.json.
check_usage_proactive() {
  CREDS_FILE="${HOME}/.claude/.credentials.json"

  usage=$(node -e "
    const fs = require('fs');
    const https = require('https');
    let token = '';
    try {
      const creds = JSON.parse(fs.readFileSync('$CREDS_FILE','utf8'));
      token = creds.claudeAiOauth?.accessToken || '';
    } catch {}

    if (!token) {
      try {
        const d = JSON.parse(fs.readFileSync('$USAGE_CACHE','utf8'));
        process.stdout.write(JSON.stringify({...d, source:'cache_no_token'}));
      } catch { process.stdout.write('{\"five_hour\":0,\"seven_day\":0,\"source\":\"none\"}'); }
      return;
    }

    const req = https.request({
      hostname: 'api.anthropic.com',
      path: '/api/oauth/usage',
      method: 'GET',
      headers: {
        'Authorization': 'Bearer ' + token,
        'anthropic-beta': 'oauth-2025-04-20',
        'User-Agent': 'mmo-orchestrator/1.0'
      },
      timeout: 8000
    }, (res) => {
      let body = '';
      res.on('data', c => body += c);
      res.on('end', () => {
        try {
          const raw = JSON.parse(body);
          const result = {
            five_hour: Math.round(raw.five_hour?.utilization || 0),
            seven_day: Math.round(raw.seven_day?.utilization || 0),
            five_hour_resets_at: raw.five_hour?.resets_at || '',
            seven_day_resets_at: raw.seven_day?.resets_at || '',
            source: 'live'
          };
          try { fs.writeFileSync('$USAGE_CACHE', JSON.stringify({...result, stale: false})); } catch {}
          process.stdout.write(JSON.stringify(result));
        } catch { process.stdout.write('{\"five_hour\":0,\"seven_day\":0,\"source\":\"parse_error\"}'); }
      });
    });
    req.on('error', () => {
      try {
        const d = JSON.parse(fs.readFileSync('$USAGE_CACHE','utf8'));
        process.stdout.write(JSON.stringify({...d, source:'cache_fallback'}));
      } catch { process.stdout.write('{\"five_hour\":0,\"seven_day\":0,\"source\":\"error\"}'); }
    });
    req.on('timeout', () => { req.destroy(); });
    req.end();
  " 2>/dev/null \
    || node -e "try{const d=JSON.parse(require('fs').readFileSync('$USAGE_CACHE','utf8'));d.source='node_error_cache';process.stdout.write(JSON.stringify(d));}catch{process.stdout.write('{\"five_hour\":0,\"seven_day\":0,\"source\":\"node_error\"}');}" 2>/dev/null \
    || echo '{"five_hour":0,"seven_day":0,"source":"node_error"}')

  five_hour=$(echo "$usage" | node -e "let d='';process.stdin.on('data',c=>d+=c);process.stdin.on('end',()=>{try{process.stdout.write(String(Math.round(JSON.parse(d).five_hour||0)));}catch{process.stdout.write('0');}})" 2>/dev/null || echo "0")
  seven_day=$(echo "$usage"  | node -e "let d='';process.stdin.on('data',c=>d+=c);process.stdin.on('end',()=>{try{process.stdout.write(String(Math.round(JSON.parse(d).seven_day||0)));}catch{process.stdout.write('0');}})" 2>/dev/null || echo "0")
  five_resets=$(echo "$usage" | node -e "let d='';process.stdin.on('data',c=>d+=c);process.stdin.on('end',()=>{try{process.stdout.write(JSON.parse(d).five_hour_resets_at||'');}catch{process.stdout.write('');}})" 2>/dev/null || echo "")
  source=$(echo "$usage"     | node -e "let d='';process.stdin.on('data',c=>d+=c);process.stdin.on('end',()=>{try{process.stdout.write(JSON.parse(d).source||'?');}catch{process.stdout.write('?');}})" 2>/dev/null || echo "?")

  log "Usage [${source}]: 5h=${five_hour}% weekly=${seven_day}%  (warn at ${WARNING_PCT}%/${WEEKLY_WARNING_PCT}%)"

  # Exit conservation if window has reset
  if [ "$CONSERVATION_MODE" = "true" ] && [ -n "$five_resets" ] && [ -n "$LIMIT_HIT_SINCE" ]; then
    resets_epoch=$(date -d "$five_resets" +%s 2>/dev/null || echo "0")
    hit_epoch=$(date -d "$LIMIT_HIT_SINCE" +%s 2>/dev/null || echo "0")
    if [ "$resets_epoch" -gt 0 ] && [ "$resets_epoch" -gt "$hit_epoch" ]; then
      CONSERVATION_MODE=false
      CONSECUTIVE_LIMIT_HITS=0
      LIMIT_HIT_SINCE=""
      CONSERVATION_REASON=""
      log "CONSERVATION MODE OFF — 5h window reset at $five_resets. Usage now ${five_hour}%."
      return
    fi
    if [ "$five_hour" -lt "$((WARNING_PCT - 10))" ] && [ "$seven_day" -lt "$((WEEKLY_WARNING_PCT - 10))" ]; then
      CONSERVATION_MODE=false
      CONSECUTIVE_LIMIT_HITS=0
      LIMIT_HIT_SINCE=""
      CONSERVATION_REASON=""
      log "CONSERVATION MODE OFF — usage recovered (5h=${five_hour}% weekly=${seven_day}%)."
      return
    fi
  fi

  # Enter conservation proactively
  if [ "$CONSERVATION_MODE" = "false" ]; then
    if [ "$five_hour" -ge "$WARNING_PCT" ]; then
      CONSERVATION_MODE=true
      LIMIT_HIT_SINCE=$(date '+%Y-%m-%d %H:%M:%S')
      CONSERVATION_REASON="5h=${five_hour}% (>=${WARNING_PCT}%)"
      log "CONSERVATION MODE ON (proactive) — 5h window at ${five_hour}%. Deferring Opus batches. Resets: $five_resets"
    elif [ "$seven_day" -ge "$WEEKLY_WARNING_PCT" ]; then
      CONSERVATION_MODE=true
      LIMIT_HIT_SINCE=$(date '+%Y-%m-%d %H:%M:%S')
      CONSERVATION_REASON="weekly=${seven_day}% (>=${WEEKLY_WARNING_PCT}%)"
      log "CONSERVATION MODE ON (proactive) — weekly window at ${seven_day}%. Deferring Opus batches."
    fi
  fi
}

# ── Reactive limit detection (reads claude -p envelope) ──────────────────────
check_limit_signal() {
  raw="$1"; btype="$2"
  [ -z "$raw" ] && return

  is_limit=$(echo "$raw" | node -e "
    let d='';process.stdin.on('data',c=>d+=c);process.stdin.on('end',()=>{
      try {
        const env = JSON.parse(d);
        const meta = [
          env.is_error ? 'is_error' : '',
          typeof env.stop_reason === 'string' ? env.stop_reason : '',
          typeof env.error === 'string' ? env.error : '',
          env.is_error && typeof env.result === 'string' && env.result.length < 500 ? env.result : '',
        ].join(' ').toLowerCase();
        const hit = env.is_error && /rate.?limit|usage.?limit|claude.?max|overloaded|quota|529|too.?many.?request/.test(meta);
        process.stdout.write(hit ? '1' : '0');
      } catch { process.stdout.write('0'); }
    });
  " 2>/dev/null || echo "0")

  if [ "$is_limit" = "1" ]; then
    CONSECUTIVE_LIMIT_HITS=$((CONSECUTIVE_LIMIT_HITS + 1))
    [ -z "$LIMIT_HIT_SINCE" ] && LIMIT_HIT_SINCE=$(date '+%Y-%m-%d %H:%M:%S')
    CONSERVATION_REASON="hard limit on $btype"
    if [ "$CONSERVATION_MODE" = "false" ]; then
      CONSERVATION_MODE=true
      log "CONSERVATION MODE ON (reactive) — hard limit on $btype (hit #$CONSECUTIVE_LIMIT_HITS)."
    else
      log "Usage limit still active on $btype (hit #$CONSECUTIVE_LIMIT_HITS since $LIMIT_HIT_SINCE)"
    fi
  fi
}

# ── Conservation skip check ───────────────────────────────────────────────────
should_skip_for_conservation() {
  batch_type="$1"
  [ "$CONSERVATION_MODE" = "false" ] && return 1

  case "$batch_type" in
    # Always run — time-critical or cheap (Haiku)
    reply_responses|classify_replies|extract_names|oversee)
      return 1 ;;
    # Defer — Opus-heavy / pipeline-upstream
    proposals_email|proposals_sms|score_semantic|enrich_sites|proofread)
      log "$batch_type: SKIP (conservation mode since $LIMIT_HIT_SINCE — $CONSERVATION_REASON)"
      return 0 ;;
  esac
  return 1
}

# ── effort_for_batch ──────────────────────────────────────────────────────────
effort_for_batch() {
  case "$1" in
    proposals_email)  echo "high" ;;
    proposals_sms)    echo "medium" ;;
    classify_replies) echo "low" ;;
    extract_names)    echo "low" ;;
    reply_responses)  echo "high" ;;
    proofread)        echo "medium" ;;
    score_semantic)   echo "medium" ;;
    enrich_sites)     echo "low" ;;
    oversee)          echo "medium" ;;
    *)                echo "medium" ;;
  esac
}

# ── run_batch_for_project ─────────────────────────────────────────────────────
# Runs one batch type for one project.
# Args: project("333m"|"2step") batch_type model batch_size [context_files...]
run_batch_for_project() {
  _proj="$1"; _type="$2"; _model="$3"; _size="$4"; shift 4

  case "$_proj" in
    333m)
      _root="$METHOD_ROOT"
      _batch_cmd="node scripts/claude-batch.js"
      _store_cmd="node scripts/claude-store-wrapper.js"
      ;;
    2step)
      _root="$TWOSTEP_ROOT"
      _batch_cmd="node scripts/2step-batch.js"
      _store_cmd="node scripts/2step-store-wrapper.js"
      ;;
    *)
      log "run_batch_for_project: unknown project '$_proj'"; return 1 ;;
  esac

  log "[$_proj] Pulling $_type batch (limit=$_size)..."
  batch_json=$(cd "$_root" && $_batch_cmd "$_type" "$_size" 2>/dev/null || echo '{"count":0,"items":[]}')

  count=$(echo "$batch_json" | node -e "
    let d='';process.stdin.on('data',c=>d+=c);process.stdin.on('end',()=>{
      try { process.stdout.write(String(JSON.parse(d).count||0)); }
      catch { process.stdout.write('0'); }
    });
  " 2>/dev/null || echo "0")
  [ -z "$count" ] && count=0

  if [ "$count" = "0" ]; then
    log "[$_proj] $_type: no items"
    return 1
  fi

  _effort=$(effort_for_batch "$_type")
  log "[$_proj] $_type: processing $count items with $_model (effort=$_effort)..."

  # Build context from any supplied files
  context=""
  for f in "$@"; do
    if [ -f "$_root/$f" ]; then
      context="$context

--- FILE: $f ---
$(cat "$_root/$f")
--- END FILE ---"
    elif [ -f "$f" ]; then
      context="$context

--- FILE: $f ---
$(cat "$f")
--- END FILE ---"
    fi
  done

  # Build task prompt per type
  case "$_type" in
    proposals_email)
      task_prompt="Generate email proposals for each site below. Output JSON: {\"batch_type\":\"proposals_email\",\"results\":[{\"site_id\":N,\"contact_method\":\"email\",\"contact_uri\":\"...\",\"country_code\":\"XX\",\"message_body\":\"...\",\"subject_line\":\"...\"}]}" ;;
    proposals_sms)
      task_prompt="Generate SMS proposals (<160 chars) for each site. TCPA opt-out required for US/CA only. Output JSON: {\"batch_type\":\"proposals_sms\",\"results\":[{\"site_id\":N,\"contact_method\":\"sms\",\"contact_uri\":\"...\",\"country_code\":\"XX\",\"message_body\":\"...\"}]}" ;;
    classify_replies)
      task_prompt="Classify each inbound message. Intent: inquiry|opt-out|interested|not-interested|pricing|schedule|unknown|autoresponder. Sentiment: positive|neutral|negative|objection. Output JSON: {\"batch_type\":\"classify_replies\",\"results\":[{\"message_id\":N,\"intent\":\"...\",\"sentiment\":\"...\"}]}" ;;
    extract_names)
      task_prompt="Extract owner first name from email addresses and domains. If no name can be inferred, use null. Output JSON: {\"batch_type\":\"extract_names\",\"results\":[{\"site_id\":N,\"contacts\":[{\"uri\":\"...\",\"name\":\"FirstName\"}]}]}" ;;
    reply_responses)
      task_prompt="Follow the reply guidelines in the context. Respond to every inbound in the batch. Output ONLY valid JSON — no markdown, no explanation." ;;
    proofread)
      task_prompt="Review each message against the proofreading rules. Output a decision for every item. Output JSON: {\"batch_type\":\"proofread\",\"results\":[{\"message_id\":N,\"decision\":\"approve\"},{\"message_id\":N,\"decision\":\"rework\",\"rework_instructions\":\"...\"}]}" ;;
    score_semantic)
      task_prompt="Score headline_quality, value_proposition, unique_selling_proposition (0-10 each) for each site. Score conservatively. Output JSON: {\"batch_type\":\"score_semantic\",\"results\":[{\"site_id\":N,\"headline_quality\":{\"score\":N,\"reasoning\":\"...\"},\"value_proposition\":{\"score\":N,\"reasoning\":\"...\"},\"unique_selling_proposition\":{\"score\":N,\"reasoning\":\"...\"}}]}" ;;
    enrich_sites)
      task_prompt="Extract contact info from HTML. Do NOT fabricate. Output JSON: {\"batch_type\":\"enrich_sites\",\"results\":[{\"site_id\":N,\"domain\":\"...\",\"email_addresses\":[],\"phone_numbers\":[],\"social_profiles\":[]}]}" ;;
    oversee)
      task_prompt="Analyze system health snapshot. Output JSON: {\"batch_type\":\"oversee\",\"results\":[{\"summary\":\"...\",\"severity\":\"ok|warn|critical\",\"findings\":[],\"actions\":[]}]}" ;;
    *)
      task_prompt="Process the batch items according to the context instructions. Output ONLY valid JSON." ;;
  esac

  full_prompt="$task_prompt

$context

BATCH DATA:
$batch_json

IMPORTANT: Output ONLY valid JSON. No markdown, no explanation, no code fences."

  # Write prompt to temp file (avoid arg-length limits and locale corruption)
  _prompt_file=$(mktemp /tmp/mmo-orch-prompt-XXXXXX)
  printf '%s' "$full_prompt" > "$_prompt_file"
  _prompt_kb=$(( $(wc -c < "$_prompt_file" 2>/dev/null || echo 0) / 1024 ))
  log "[$_proj] $_type: context=${_prompt_kb}kB"

  _xdg="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
  [ -d "$_xdg" ] || { _xdg="/tmp/runtime-$(id -u)"; mkdir -p "$_xdg" 2>/dev/null || true; }

  _raw_file="$MMO_ROOT/logs/.mmo-orch-raw-$$.json"
  _claude_stderr=$(mktemp /tmp/mmo-orch-stderr-XXXXXX)

  XDG_RUNTIME_DIR="$_xdg" env -u CLAUDECODE "$CLAUDE_BIN" -p \
    --model "$_model" --effort "$_effort" --output-format json \
    < "$_prompt_file" > "$_raw_file" 2>"$_claude_stderr" || true

  rm -f "$_prompt_file"
  _stderr_content=$(cat "$_claude_stderr"); rm -f "$_claude_stderr"

  # Check for limit signals
  check_limit_signal "$(cat "$_raw_file" 2>/dev/null || true)" "$_proj/$_type"

  _raw_size=$(wc -c < "$_raw_file" 2>/dev/null || echo "missing")
  log "[$_proj] $_type: raw_file=${_raw_size}b"

  if [ ! -s "$_raw_file" ]; then
    rm -f "$_raw_file"
    log "[$_proj] $_type: claude returned empty${_stderr_content:+ (stderr: $(echo "$_stderr_content" | head -1))}"
    return 0
  fi

  # Pipe to the project's store-wrapper
  # 333Method has claude-store-wrapper.js; 2Step needs 2step-store-wrapper.js (or direct stdin)
  if [ "$_proj" = "333m" ]; then
    store_out=$(cd "$_root" && node scripts/claude-store-wrapper.js "$_raw_file" "$_type" 2>&1 \
      || echo '{"error":"store_failed"}')
  else
    # 2Step: 2step-store.js reads from stdin — extract result text then pipe in
    store_out=$(cd "$_root" && node -e "
      const fs = require('fs');
      const { spawnSync } = require('child_process');
      let raw, text;
      try { raw = fs.readFileSync('$_raw_file', 'utf8'); } catch(e) { process.stdout.write(JSON.stringify({error:'read',msg:e.message})); process.exit(0); }
      let envelope;
      try { envelope = JSON.parse(raw); } catch { envelope = null; }
      if (envelope && envelope.is_error) { process.stdout.write(JSON.stringify({error:'claude_error',msg:envelope.result||''})); process.exit(0); }
      text = (envelope && typeof envelope.result === 'string') ? envelope.result : raw;
      text = text.replace(/^\s*\`\`\`(?:json)?\s*\n?/, '').replace(/\n?\s*\`\`\`\s*$/, '').trim();
      const r = spawnSync('node', ['scripts/2step-store.js'], {
        input: text, encoding: 'utf8', cwd: '$_root', maxBuffer: 10*1024*1024
      });
      process.stdout.write(r.stdout || r.stderr || JSON.stringify({error:'spawn_failed',status:r.status}));
    " 2>&1 || echo '{"error":"store_wrapper_failed"}')
  fi

  rm -f "$_raw_file"

  if [ -z "$store_out" ]; then
    log "[$_proj] $_type: store produced no output"
  else
    log "[$_proj] $_type: $store_out"
  fi

  had_work=true
  return 0
}

# ── run_checked ───────────────────────────────────────────────────────────────
# Run a batch for one or both projects with type-filter and conservation checks.
# Args: project("333m"|"2step"|"both") batch_type model batch_size_333m batch_size_2step [context_files...]
run_checked() {
  _rc_proj="$1"; _rc_type="$2"; _rc_model="$3"
  _rc_size_m="$4"; _rc_size_ts="$5"; shift 5

  # Single-type filter
  [ -n "$SINGLE_TYPE" ] && [ "$SINGLE_TYPE" != "$_rc_type" ] && return 0

  # Conservation skip
  should_skip_for_conservation "$_rc_type" && return 0

  # Project filter
  case "$_rc_proj" in
    333m|both)
      if [ -z "$SINGLE_PROJECT" ] || [ "$SINGLE_PROJECT" = "333m" ]; then
        run_batch_for_project 333m "$_rc_type" "$_rc_model" "$_rc_size_m" "$@" || true
      fi
      ;;
  esac

  case "$_rc_proj" in
    2step|both)
      if [ -z "$SINGLE_PROJECT" ] || [ "$SINGLE_PROJECT" = "2step" ]; then
        run_batch_for_project 2step "$_rc_type" "$_rc_model" "$_rc_size_ts" "$@" || true
      fi
      ;;
  esac
}

# ── run_checked_gated ─────────────────────────────────────────────────────────
# Same as run_checked but with a frequency gate (interval in minutes).
# Gate key is scoped per-project to allow independent scheduling.
run_checked_gated() {
  _rg_proj="$1"; _rg_type="$2"; _rg_model="$3"
  _rg_size_m="$4"; _rg_size_ts="$5"; _rg_interval="$6"; shift 6

  [ -n "$SINGLE_TYPE" ] && [ "$SINGLE_TYPE" != "$_rg_type" ] && return 0
  should_skip_for_conservation "$_rg_type" && return 0

  _gate_key="${_rg_proj}_${_rg_type}"

  if is_due "$_gate_key" "$_rg_interval"; then
    case "$_rg_proj" in
      333m|both)
        if [ -z "$SINGLE_PROJECT" ] || [ "$SINGLE_PROJECT" = "333m" ]; then
          run_batch_for_project 333m "$_rg_type" "$_rg_model" "$_rg_size_m" "$@" || true
        fi
        ;;
    esac
    case "$_rg_proj" in
      2step|both)
        if [ -z "$SINGLE_PROJECT" ] || [ "$SINGLE_PROJECT" = "2step" ]; then
          run_batch_for_project 2step "$_rg_type" "$_rg_model" "$_rg_size_ts" "$@" || true
        fi
        ;;
    esac
    mark_ran "$_gate_key"
  else
    log "[$_rg_proj] $_rg_type: not due yet (every ${_rg_interval}min)"
  fi
}

# ── process_all_batches ───────────────────────────────────────────────────────
process_all_batches() {
  had_work=false

  check_usage_proactive

  _conservation=""
  [ "$CONSERVATION_MODE" = "true" ] && _conservation=" [CONSERVATION MODE: $CONSERVATION_REASON]"
  log "Starting cycle${_conservation}"

  # ── 333Method-only batches ──────────────────────────────────────────────────
  # These batch types don't exist yet in 2Step (no scoring/enrichment pipeline there).
  run_checked 333m proposals_email opus   "$M_PROPOSALS_EMAIL_BATCH"  0  prompts/PROPOSAL.md docs/05-outreach/email-best-practices.md
  run_checked 333m proposals_sms   opus   "$M_PROPOSALS_SMS_BATCH"    0  prompts/PROPOSAL.md docs/05-outreach/sms-best-practices.md
  run_checked 333m score_semantic  sonnet "$M_SCORE_SEMANTIC_BATCH"   0
  run_checked 333m enrich_sites    haiku  "$M_ENRICH_SITES_BATCH"     0  prompts/ENRICHMENT.md
  run_checked 333m proofread       opus   "$M_PROOFREAD_BATCH"        0  prompts/PROOFREAD.md

  # ── 2Step-only batches ──────────────────────────────────────────────────────
  # Proposals use the DM-OUTREACH prompt; proofread shares the same logic.
  run_checked 2step proposals_email opus  0  "$TS_PROPOSALS_EMAIL_BATCH"  prompts/DM-OUTREACH.md
  run_checked 2step proposals_sms   opus  0  "$TS_PROPOSALS_SMS_BATCH"    prompts/DM-OUTREACH.md
  run_checked 2step proofread       opus  0  "$TS_PROOFREAD_BATCH"

  # ── Shared batch types — run for both projects each cycle ───────────────────
  run_checked both classify_replies haiku  "$M_CLASSIFY_BATCH"  "$TS_CLASSIFY_BATCH"
  run_checked both reply_responses  opus   "$M_REPLIES_BATCH"   "$TS_REPLIES_BATCH"   prompts/REPLIES.md

  # ── Time-gated shared batches ───────────────────────────────────────────────
  run_checked_gated both extract_names haiku "$M_NAMES_BATCH" "$TS_NAMES_BATCH" 15
  run_checked_gated both oversee       sonnet 1               1                 30

  [ "$had_work" = true ]
}

# ── Main loop ─────────────────────────────────────────────────────────────────
if [ "$LOOP" = true ]; then
  while true; do
    if ! process_all_batches; then
      if [ "$CONSERVATION_MODE" = "true" ]; then
        log "Queues empty in conservation mode — waiting 5min before retry..."
        sleep 300
        continue
      fi
      log "All queues empty — exiting loop"
      break
    fi
    if [ "$CONSERVATION_MODE" = "true" ]; then
      log "Conservation mode: pausing 5min before next cycle..."
      sleep 300
    fi
    log "Cycle complete, starting next..."
  done
else
  process_all_batches || log "No work to process"
fi

log "Orchestrator finished"
