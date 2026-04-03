#!/bin/sh
# Citation Monitor — Run via mmo-platform cron system
#
# Uses `claude -p` to search for brand citations across search engines,
# compare against the previous baseline, create content to close gaps,
# and deploy changes.
#
# Schedule: every 14 days (cron_jobs table)
# Timeout: 1800s (30 min)

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

DATE=$(date +%Y-%m-%d)
LOG_FILE="$PROJECT_ROOT/logs/citation-monitor-${DATE}.log"
BASELINE_DIR="$PROJECT_ROOT/tmp"
LATEST_BASELINE=$(ls -t "$BASELINE_DIR"/citation-audit-*.md 2>/dev/null | head -1)

mkdir -p "$BASELINE_DIR" "$(dirname "$LOG_FILE")"

log() {
  echo "[$(date -Iseconds)] [CitationMonitor] $1" | tee -a "$LOG_FILE"
}

log "Starting citation monitor run"
log "Previous baseline: ${LATEST_BASELINE:-none}"

BRAND_DOMAIN="${BRAND_DOMAIN:-auditandfix.com}"
BRAND_NAME="${BRAND_NAME:-Audit\&Fix}"

# Build the prompt with context
PROMPT=$(cat <<PROMPT_END
You are the AI Citation Monitor for ${BRAND_DOMAIN} — a B2B CRO audit service for small business websites.

## Your mission

Run a citation audit, compare against the previous baseline, identify gaps, and if actionable gaps are found, create content to close them, deploy via FTP, and commit.

## Step 1: Run citation audit

Search for these 25 queries using WebSearch and record whether ${BRAND_DOMAIN} or '${BRAND_NAME}' appears in results:

### Brand queries
1. ${BRAND_NAME} website audit service
2. ${BRAND_DOMAIN} reviews
3. ${BRAND_DOMAIN} CRO audit

### Service queries
4. CRO audit service small business
5. website conversion audit report service
6. conversion rate optimisation audit report
7. website audit report with actionable recommendations
8. CRO audit report service that tells you what to fix

### Comparison queries
9. best website audit tool
10. CRO audit vs Hotjar website analysis
11. website audit service under $500 affordable
12. my web audit vs SEOptimer comparison

### Problem queries
13. how to improve website conversion rate small business
14. why is my website not converting visitors to customers
15. website not getting enough enquiries leads fix conversion
16. hire someone to review my website and tell me what's wrong

### Category queries
17. best CRO tools conversion rate optimisation
18. website conversion rate optimisation services small business
19. best conversion rate audit agency Australia UK
20. website performance audit one time report not subscription

### Additional high-intent queries
21. professional website review service
22. website conversion audit with screenshots
23. affordable CRO audit report PDF
24. small business website audit checklist service
25. website not converting what to do

For each query, record: the query, whether we appeared, and the top 5 competitors that did appear.

## Step 2: Compare against baseline

Read the previous baseline (most recent tmp/citation-audit-*.md file). Compare:
- New citations we gained
- Citations we lost
- New competitors appearing
- Queries where we're still absent but competition is weak (opportunity gaps)

Save the new audit as tmp/citation-audit-YYYY-MM-DD.md (using today's date).

## Step 3: Decide what content to create

Based on the gaps, decide what would have the highest impact. Priority order:
1. Blog posts targeting uncontested problem/how-to queries
2. Landing pages for high-intent service queries
3. Comparison content for vs-type queries
4. FAQ schema additions to existing pages

Only create content if there's a clear gap to fill. If no actionable gaps, just save the audit and stop.

## Step 4: Create content (if needed)

When creating new PHP pages:
- Read the website's compare.php and methodology.php as style references
- Match the exact design language (same CSS class prefixes, colour palette, layout patterns)
- Use the same PHP includes: config.php, i18n.php, geo.php, pricing.php, header.php, footer.php, consent-banner.php
- Add structured data (BreadcrumbList + appropriate page-level schema)
- Use British spelling (optimisation, prioritised, analyse, colour)
- First person plural, conversational tone. No corporate jargon.
- Add i18n translation keys to ALL 20 language files in the website's lang/ directory
- Add .htaccess rewrite rules for clean URLs
- Add nav links to includes/header.php and includes/footer.php if appropriate

## Step 5: Deploy and commit

If files were changed:
1. Deploy using: bash scripts/deploy-website.sh --changed
2. Stage changed files with git add
3. Commit with message: "feat(citation-monitor): [date] — [summary of changes]"
   Do NOT push — the user will push after review.

## Important constraints
- Never create more than 3 new pages per run
- Never modify existing page content without clear evidence it would improve citations
- Validate PHP syntax with: php -l filename.php
- The working directory is ~/code/mmo-platform/
- Read CLAUDE.md and the website files for full context
PROMPT_END
)

log "Invoking claude -p --model opus (30 min timeout)"

# Run Claude with the prompt — opus for complex content creation
# Unset CLAUDECODE to allow nested claude -p invocation (same pattern as orchestrator)
RESULT=$(env -u CLAUDECODE claude -p \
  --model opus \
  --output-format text \
  --max-turns 50 \
  "$PROMPT" 2>&1) || true

echo "$RESULT" >> "$LOG_FILE"

# Count new/changed files
CHANGED=$(git diff --name-only 2>/dev/null | wc -l)
UNTRACKED=$(git ls-files --others --exclude-standard tmp/ 2>/dev/null | wc -l)

log "Run complete. Changed files: $CHANGED, New files: $UNTRACKED"
log "Review log: $LOG_FILE"

# Output summary for cron_job_logs
echo "{\"date\":\"$DATE\",\"changed_files\":$CHANGED,\"new_files\":$UNTRACKED,\"baseline\":\"${LATEST_BASELINE:-none}\"}"
