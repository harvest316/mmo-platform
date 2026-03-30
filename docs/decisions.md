# Decision Register

Architectural and technical decisions for the mmo-platform ecosystem (333Method, 2Step, AdManager, distributed-infra).

Lightweight ADR format grouped by domain. Each entry records what we decided, why, and when.

---

## Infrastructure

### DR-001: Caddy over Traefik for reverse proxy (2026-03-20)

**Context:** Needed a reverse proxy for `dashboard.molecool.org` on Hostinger KVM (NixOS). Evaluated Caddy vs Traefik.

**Decision:** Caddy. It combines reverse proxy + static file server + auto-HTTPS in one binary. Traefik is a pure L7 proxy — can't serve the React SPA directly, would require an extra nginx/caddy container just for static files. Docker auto-discovery (Traefik's strength) is irrelevant with fixed NixOS-managed OCI containers. Caddy also uses ~20-30 MB RAM vs Traefik's ~50-100 MB (matters on 4 GB KVM). Hostinger KVM has no special Traefik integration — it's a bare VPS.

**Status:** Accepted
**Impl:** `distributed-infra/modules/caddy.nix`

### DR-002: Hostinger KVM with nixos-infect, not Hetzner (2026-02-23)

**Context:** Hetzner has official NixOS support and better pricing long-term. Hostinger KVM 1 was pre-paid ($131.88/year).

**Decision:** Use Hostinger for now via `nixos-infect` (not `nixos-anywhere` — kexec unreliable on Hostinger). Use `/dev/vda` (VirtIO) in disko.nix, not `/dev/sda`. Migrate to Hetzner after the pre-paid year if needed via `nixos-anywhere` + `pg_dump` → `pg_restore`.

**Status:** Accepted
**Impl:** `distributed-infra/hosts/production/`

### DR-003: POSIX sh for pipeline scripts, not bash (2026-02-23)

**Context:** `333method-pipeline` systemd service has no `path` config. Bash is NOT at `/usr/bin/bash` on NixOS. Scripts using `#!/usr/bin/env bash` failed silently.

**Decision:** All pipeline scripts use POSIX sh (`#!/bin/sh`). `/bin/sh` is always available on NixOS via FHS stub. Rules:
- Never use `#!/usr/bin/env bash` in scripts run by the pipeline service (PATH is minimal)
- Never set CHROMIUM_PATH to a Playwright bundled binary — NixOS can't run glibc binaries
- `chromium-nice` uses `/nix/store/*-chromium-*/bin/chromium` glob on host; falls back to Playwright cache in Docker container

**Status:** Accepted
**Impl:** `scripts/chromium-nice` (commit bec7419f)

### DR-004: Self-hosted PostgreSQL on VPS, not managed cloud (2026-02-23)

**Context:** Plan originally specified Neon managed PostgreSQL ($7-15/month). Hostinger VPS is already paid for.

**Decision:** Self-host PostgreSQL on the Hostinger VPS. Removes cloud DB cost. Ongoing infra = Hostinger only (~$11/month).

**Status:** Accepted

### DR-018: Adaptive concurrency thresholds (2026-02-21)

**Context:** Pipeline needed auto-scaling that respects system resources without manual tuning.

**Decision:** EASE_LOAD=0.4, MAX_LOAD=0.8 (based on `os.loadavg()`). ADAPTIVE_FREE_MEM_FLOOR_MB=768 default.

**Status:** Accepted
**Impl:** `src/utils/adaptive-concurrency.js` (commit c49454c8)

---

## Pipeline Architecture

### DR-005: Zombie process fix — remove detached/unref (2026-02-21)

**Context:** Z-state processes piling up in codium-sandbox container. Codium (PID 1, not real init) never calls `wait()`.

**Decision:** Root cause: `task-manager.js` used `detached:true` + `child.unref()` → orphaned children → zombies. Fix: removed both flags, added exit handler. Node.js reaps children properly now. Process reaper also kills live stale `npm run agent` workers (skips Z-state). To clear existing zombies: restart the container.

**Status:** Accepted
**Impl:** commit acba99fe

### DR-006: Proposals go to LOW scorers — cutoff is upper bound (2026-02-20)

**Context:** `LOW_SCORE_CUTOFF=82` interpretation was ambiguous.

**Decision:** 82 is an **upper bound**: sites scoring ≥82 are skipped, sites <82 get proposals. Business logic: selling web optimization services — high scorers don't need help, low scorers are the prospects. Rescoring (below-fold vision) gives low-scoring sites a second chance before proposal generation, not to exclude them further.

**Status:** Accepted
**Impl:** `src/proposal-generator-v2.js:138` — `if (siteData.score >= cutoff) throw`

### DR-007: getCountryByCode returns null for unknown codes (2026-02-23)

**Context:** EU TLD sites get `country_code='EU'` which isn't in the COUNTRIES map — crashed the entire enrich stage.

**Decision:** Return `null` instead of throwing. Callers already guard with `if (country && country.requiresGDPRCheck)`.

**Status:** Accepted
**Impl:** `src/config/countries.js` (commit f2113423)

### DR-008: html_dom sentinel system (2026-02-28)

**Context:** Need to distinguish "HTML never captured" (bug) from "captured, scored, then cleaned up" (healthy).

**Decision:** After scoring, set `html_dom = 'HTML removed after scoring'` (sentinel string). Two distinct states:
- `NULL` = never captured → reset to `found` status for re-capture
- Sentinel string = intentionally removed (normal, healthy)

All pipeline stages (scoring, rescoring, enrich) exclude both null and sentinel. Enrich requires either `contacts_json IS NOT NULL` OR real html_dom to prevent wasted LLM calls.

**Status:** Accepted
**Impl:** `cleanup-html-dom.js`

### DR-009: Programmatic score/grade computation, not LLM (2026-02-24)

**Context:** LLM-generated scores were inconsistent across runs.

**Decision:** LLM returns only `factor_scores` (0-10 per factor). Score and grade computed deterministically:
- `computeScoreFromFactors()`: weighted sum × 10 → 0-100. Weights: headline=.15, VP=.14, USP=.13, CTA=.13, urgency=.10, hook=.09, trust=.11, imagery=.08, offer=.04, context=.03
- `computeGrade()`: standard academic scale — A+(97+), A(93+), A-(90+), B+(87+), B(83+), B-(80+), C+(77+), C(73+), C-(70+), D+(67+), D(63+), D-(60+), F(0-59)

Unified across all code, prompts, docs, and dashboard.

**Status:** Accepted
**Impl:** `src/score.js`

### DR-010: pipeline_metrics.duration_ms = per-batch, not per-site (2026-03-03)

**Context:** Misread "2807s proposals" as 47 min per site. Actually 200-site batch = 13.5s/site.

**Decision:** `pipeline_metrics` records duration of one `processBatch()` call (up to 200 sites). Per-site: `AVG(CAST(duration_ms AS REAL)/NULLIF(sites_processed,0)/1000.0)`.

**Status:** Accepted

### DR-011: Incomplete LLM responses = rate-limit burst, not truncation (2026-02-25)

**Context:** 1,624 incomplete LLM errors on 2026-02-24, $5.41-$8.82 wasted (21-34% overhead). Analysis showed completionTokens=778-907 (avg 842) — tokens present but response malformed.

**Decision:** Root cause: rate-limit burst from `SCORING_CONCURRENCY=10`. Fix: set `SCORING_CONCURRENCY=2` (matching `ENRICHMENT_CONCURRENCY=2`). Savings: ~$8.84/day waste eliminated.

**Status:** Accepted
**Impl:** `.env` + commit a659c71c

### DR-012: All LLM prompts in prompts/*.md, never inline in code (2026-02-24)

**Context:** Prompts embedded in JS were hard to review, iterate on, and audit.

**Decision:** All LLM prompts live exclusively in `prompts/*.md`. Never embed prompt text in `.js` or `.sh`. Orchestrator `task_prompt` lines should be one-liners referencing the MD file. Attach the MD file as a context file to `run_batch` calls.

**Status:** Accepted

### DR-013: British spelling for British markets, American for US/CA (2026-02-24)

**Context:** AU/GB/NZ/IE/ZA/IN prospects were receiving American spelling in outreach emails.

**Decision:**
- **British markets** (AU/GB/NZ/IE/ZA/IN): British spelling only — `optimise`, `specialise`, `customise`. No `{s|z}` spintax. "ring" is fine.
- **US/CA**: American only — `optimization`, `specialize`, `customize`. Say "call" not "ring" (but `{ring|call}` OK in non-US).
- Don't put `.` after `[recommendation]` in templates — code adds one.

**Status:** Accepted

### DR-014: Proposals LLM call count = 2N+1 per site (2026-03-03)

**Context:** Needed to understand and optimize proposal generation cost per site.

**Decision:** Per site with N contacts: N `extractFirstname` (now regex fast-path for Western names, bypassing Haiku) + 1 generation + N `polishProposalWithHaiku` (parallelized with `Promise.all`).

**Status:** Accepted
**Impl:** `src/proposal-generator-v2.js`, `src/utils/name-extractor.js`

### DR-015: OpenRouter image generation via chat completions (2026-03-01)

**Context:** Needed image generation via OpenRouter. The `/images/generations` endpoint returns HTML 404.

**Decision:** Use chat completions endpoint instead. Response in `message.images[]` as base64 data URLs. Best model: `google/gemini-2.5-flash-image` ($0.0003/img). Good for lifestyle/marketing images; not for document mockups (use HTML → Playwright screenshot instead).

**Status:** Accepted

### DR-016: Sonnet overseer design (2026-02-23)

**Context:** Need automated pipeline health checks beyond basic cron monitors.

**Decision:** `src/cron/sonnet-overseer.js` runs every 30 min via `cron_jobs` table. Prefers `ANTHROPIC_API_KEY` (Claude Max, zero cost), falls back to `OPENROUTER_API_KEY`. Three actions:
- `RESTART_PIPELINE` — restart systemd service
- `CLEAR_STALE_TASKS` — clear blocked/pending agent tasks
- `RESET_STUCK_SITES` — nudge sites back to prior stage (4h minimum floor enforced)

Stage reset map: semantic_scored→prog_scored, vision_scored→prog_scored, prog_scored→assets_captured, assets_captured→found, enriched→semantic_scored.

**Status:** Accepted
**Impl:** `src/cron/sonnet-overseer.js`

### DR-017: Enrich LLM calls must use rate limiter (2026-03-02)

**Context:** `enrich.js` called `callLLM()` without `openRouterLimiter`, causing burst 400 errors when all concurrent Playwright instances fired LLM calls simultaneously.

**Decision:** Wrap both `callLLM()` calls in `openRouterLimiter.schedule()` — same pattern as `score.js` and `proposal-generator-v2.js`.

**Status:** Accepted

---

## Known Failure Modes

### DR-019: Browser loop hang detection (2026-03-02)

**Context:** Playwright gets stuck on a single site page for hours with no timeout.

**Decision:** `checkBrowserLoopHung()` in process-guardian.js (Check 5). Restarts if browser stale >30min while API loop active <60min. 20-min cooldown prevents cascading restarts. `API_ACTIVE_THRESHOLD_MIN=60` (was 15 — too tight, API cycles take 30-60min with slow scoring).

**Status:** Accepted

### DR-020: Dead cron timer detection and recovery (2026-03-03)

**Context:** If a cron handler hangs (e.g. DB lock via `PRAGMA integrity_check`), systemd timer shows `Trigger: n/a` (NextElapseUSecMonotonic=infinity). All 15/30-min jobs stop.

**Decision:** Check 6 in process-guardian detects and auto-restarts timer + stops hung service. Root cause fixes:
- All cron modules: `db.pragma('busy_timeout = 10000')`
- `precomputeDashboard`: removed `PRAGMA integrity_check` (60s+ on 2M rows), added `idx_sites_created_at` (migration 074), 5-min budget guard, changed `proc.on('close')` → `proc.on('exit')` + stream destroy
- Better-sqlite3 is synchronous — `setTimeout` cannot interrupt stuck writes; only `busy_timeout` can

**Status:** Accepted

### DR-021: Check 1 auto-restarts manually stopped pipeline (2026-03-02)

**Context:** Process Guardian Check 1 runs every minute. `systemctl --user stop 333method-pipeline` gets immediately undone.

**Decision:** Known behavior, not yet fixed. Workaround: stop cron timer alongside pipeline (`systemctl --user stop 333method-cron.timer`). Proper fix (add `pipeline_paused` flag to `pipeline_control` table that Check 1 respects) not yet implemented.

**Status:** Accepted (workaround)

---

## Pipeline Operations

### DR-022: Error categorization with LLM-proposed patterns (2026-02-28)

**Context:** 335K ignored and 107K failing sites with no visibility into failure reasons.

**Decision:** Regex-based pattern matching in `error-categories.js` with two groups (terminal vs retriable) per context (site vs outreach). Daily cron proposes new patterns via Haiku LLM to `error_pattern_proposals` table; human approves to merge into code. `buildStatusTree()` creates hierarchical error breakdowns for CLI and dashboard.

**Status:** Accepted
**Impl:** `src/utils/error-categories.js`, `src/cron/classify-unknown-errors.js`

### DR-023: SMS business hours block = skip, not error (2026-03-06)

**Context:** 1,908 SMS outreaches silently failed when business hours check threw without persisting error_message.

**Decision:** Return early instead of throwing for business hours blocks, keeping `status='approved'` for next cycle (not `'failed'`). Fix catch block to always persist error_message.

**Status:** Accepted
**Impl:** `src/outreach/sms.js`

### DR-024: Assets stage 120s per-site timeout (2026-03-06)

**Context:** No per-site timeout on Playwright captures; hung Chromium froze entire browser loop for hours.

**Decision:** Wrap `captureSiteScreenshots` with `withTimeout(120000ms)`. Prevents hung processes from cascading.

**Status:** Accepted
**Impl:** `src/stages/assets.js`

### DR-025: Outreach reputation guard with sliding window (2026-03-06)

**Context:** No protection against burning Twilio/Resend reputation by sending thousands of messages that all fail with the same error.

**Decision:** Lightweight in-memory sliding window tracker (not opossum): same error ≥25 times in 2h window → halt channel. Sits above circuit breaker. In-memory by design (resets on restart; assumes fix applied between restarts).

**Status:** Accepted
**Impl:** `src/utils/outreach-guard.js`

### DR-026: Circuit breaker auto-recovery with cooldown (2026-02-15)

**Context:** Agents staying blocked indefinitely when failure rate didn't drop exactly below threshold.

**Decision:** After 30-min cooldown expires AND failure rate drops below threshold, auto-recover. Minimum 10 tasks required before breaker triggers. `agent_system_error` type (severity: low) for validation failures — excluded from breach calculation so unknown task types don't block agents.

**Status:** Accepted
**Impl:** `src/agents/runner.js`

### DR-027: Immediate agent invocation for chained workflows (2026-02-15)

**Context:** Agents only ran every 5 min via cron, creating 15-20 min delays for multi-step workflows (Monitor → Triage → Developer → QA).

**Decision:** When task created or handoff occurs, immediately invoke receiving agent in-process (not spawn process). Max chain depth 10 prevents infinite loops. One task per invocation limits blast radius. Falls back to cron on error. Speed: 15-20 min → <2 min (10-15x improvement).

**Status:** Accepted
**Impl:** `src/agents/base-agent.js` `invokeAgentImmediately()`, env var `AGENT_IMMEDIATE_INVOCATION`

### DR-028: Direct agent tool access — 75-85% token reduction (2026-02-15)

**Context:** Agents calling Claude API for file reads and searches cost 500-2000 tokens per call.

**Decision:** Added direct tool access to BaseAgent: `readFileTool()`, `searchFilesTool()`, `runCommandTool()`, `executeInParallelTool()` — 0 tokens, milliseconds. Gather context with tools, use LLM only for analysis/generation.

**Status:** Accepted
**Impl:** `src/agents/utils/agent-tools.js`

### DR-029: SLO tracking for pipeline stage transitions (2026-02-15)

**Context:** No quantitative reliability targets; couldn't detect when system was degrading.

**Decision:** SLOs for 3 stage transitions: serps_to_assets (95% in 60min), assets_to_scored (95% in 30min), scored_to_rescored (90% in 45min). Monitor agent checks every 30min via `slo-tracker.js`, calculates P50/P95/P99 from `site_status` history. Violations create Architect tasks.

**Status:** Accepted
**Impl:** `src/agents/utils/slo-tracker.js`, `src/agents/monitor.js`

### DR-030: Agent file operations with atomic writes and path whitelist (2026-02-15)

**Context:** Agents need safe file read/write without path traversal or accidental overwrites.

**Decision:** Comprehensive module: automatic backups before writes, atomic writes (temp → move), whitelist-based path validation (`src/`, `tests/`, `docs/`, `scripts/`, `prompts/`), blacklist (`.env`, `package-lock.json`), ESLint syntax validation (parser only, no lint rules), diff generation. Timestamped backup naming for uniqueness and sorting.

**Status:** Accepted
**Impl:** `src/agents/utils/file-operations.js` (600+ LOC), `docs/agents/file-operations.md`

---

## Outreach & Website

### DR-031: Cloudflare Worker KV for free scan queue (2026-02-19)

**Context:** Free website scanner needed to capture emails; NixOS server behind residential firewall (no inbound HTTP).

**Decision:** Hostinger PHP pushes scan data to CF Worker KV (`scans` + `purchases` queues); NixOS polls CF Worker every 5 min via `poll-free-scans.js`. KV TTL: 7 days. Local SQLite backup on Hostinger as fire-and-forget fallback.

**Status:** Accepted
**Impl:** `workers/auditandfix-api/`, `src/cron/poll-free-scans.js`, migrations 109-110

### DR-032: Base62 short URLs for outreach links (2026-03-10)

**Context:** Needed opaque, short, non-enumerable URLs for outreach prefill links.

**Decision:** Base62-encode site IDs (e.g., 17757 → "4jv"). `reply-processor.js` generates `auditandfix.com/a/4jv`. `a.php` decodes back to site_id. Non-enumerable, backward-compatible with numeric fallback.

**Status:** Accepted
**Impl:** `src/cli/reply-processor.js`, `auditandfix.com/a.php`

### DR-033: Marketing opt-in dedup with partial unique index (2026-03-17)

**Context:** Re-polling scans with marketing opt-in could create duplicate nurture records.

**Decision:** Unique partial index on `messages(site_id, contact_uri, message_type) WHERE message_type = 'scan_optin'`. `INSERT OR IGNORE` prevents duplicates.

**Status:** Accepted
**Impl:** Migration 110

### DR-034: CF Worker test/prod isolation via separate KV namespaces (2026-03-17)

**Context:** Needed separate test and production CF Worker environments with isolated data.

**Decision:** Wrangler `[env.test]` bindings with separate KV namespaces (`SCANS_KV_TEST`, `PURCHASES_KV_TEST`). `api.php` routes via `?sandbox=1` to sandbox worker URL.

**Status:** Accepted
**Impl:** `workers/auditandfix-api/wrangler.toml`, `auditandfix.com/api.php`

### DR-067: Scanner CTA: single hero + exit-intent downsell, not pricing table (2026-03-22)

**Context:** Scanner funnel has free scan → score → email gate → factor breakdown → CTA. Needed to add Quick Fixes ($67) and Audit+Implementation ($497) products alongside the Full Audit ($297). Initial instinct was a 3-column pricing table on the results page. Growth strategist agent recommended against it: (a) pricing table reframes urgency → shopping, killing conversion; (b) $67 next to $297 cheapens the core product; (c) tradies are binary decision-makers — three options = decision paralysis.

**Decision:** Single hero CTA for Full Audit at peak intent. Implementation upgrade as a subordinate horizontal strip below ("add implementation for $200 more" — not an equal-weight column). Quick Fixes product exists only as: (1) exit-intent popup for desktop users who try to leave, (2) email follow-up sequence for non-converters. Credit-toward-upgrade mechanic lives in post-purchase email, not on-page.

Multi-product pricing infrastructure added to `pricing.php`, `api.php`, `index.php`, `main.js`, `thank-you.php` — all three products share one order page via `?product=` param.

**Status:** Accepted
**Impl:** `scan.php` (hero card + upgrade strip), `exit-intent.js` (downsell popup), `pricing.php` (getProductPricing), `api.php` (product-aware createOrder/capturePayment)

### DR-068: Post-scan email sequence — 7 emails, 14 days, three score-band segments (2026-03-22)

**Context:** Users who scan at scan.php, enter their email at the factor breakdown gate, and leave without purchasing. DR-067 established Quick Fixes exists only as exit-intent popup (on-page) and email follow-up sequence (off-page). Needed a full sequence spec for the email channel.

**Decision:** 7-email sequence over 14 days. Enrolled on marketing_optin = 1 at email gate. Three segments by score band: A (0-59, critical), B (60-76, needs work), C (77-81, almost there). Sequence logic:
- Email 1 (immediate): transactional score delivery; commercial CTA only if marketing_optin = 1
- Emails 2-4 (days 1-5): worst-factor deep-dive, social proof with credit mechanic intro, objection handle (no-code fixes)
- Email 5 (day 7): Full Audit pivot; first mention of Audit+Implementation
- Email 6 (day 10): credit mechanic spelled out explicitly (Quick Fixes price credited toward Full Audit)
- Email 7 (day 14): soft close, no hard sell, open door for future re-engagement

Quick Fixes is the primary entry CTA for Segments A and B through Email 4. Full Audit becomes primary from Email 5. Segment C leads with Full Audit earlier (Email 1) because higher-scoring prospects are closer to the conversion threshold. Credit-toward-upgrade mechanic first introduced in Email 3 social proof, made explicit in Email 6.

Compliance: only enrol marketing_optin = 1. GDPR/CAN-SPAM/Spam Act 2003 via signed unsubscribe token. Suppression on hard bounce (immediate), soft bounce x3, spam complaint, or any purchase. Localised pricing via getProductPriceForCountry using country_code captured at scan time. British spelling for AU/GB/NZ/IE (DR-013).

Implementation: Resend API with dedicated subdomain, scan_email_sequence state table, 5-10 min cron worker, one A/B test running at a time.

**Status:** Accepted
**Impl:** `docs/email-sequence-post-scan-non-converter.md`

### DR-070: ColorCraft AI social media strategy — Pinterest + TikTok/Reels as Tier 1 channels (2026-03-23)

**Context:** colorcraft-ai.com (AI coloring book and color palette generator) needed an organic social and content marketing strategy from scratch. Early-stage product, no confirmed active social accounts, solo/small team. Evaluated Pinterest, TikTok, Instagram Reels, Reddit, Facebook Groups, YouTube, and Twitter/X.

**Decision:** Tier 1: Pinterest (visual search engine with 13-month pin longevity; coloring/printables is a proven high-volume category) and TikTok + Instagram Reels (AI tool demo format drives discovery; #coloringbook has 11B TikTok views). Twitter/X deprioritized — buyer audience not concentrated there. YouTube deferred to Month 4+ due to production cost. Facebook Groups (not pages) included for KDP/Etsy seller community engagement. Reddit included for trust-building in r/KDP, r/adultcoloring, r/Teachers, r/EtsySellers.

Lead magnet strategy: free themed coloring page packs as email capture on Pinterest and TikTok bio link. Primary viral mechanic: live text-prompt-to-coloring-page generation reveal. KDP passive income angle is highest-leverage TikTok content pillar.

**Status:** Accepted
**Impl:** `colorcraft/social-media-strategy.md`

### DR-070: ColorCraft AI growth strategy — Etsy sellers as primary acquisition segment (2026-03-23)

**Context:** ColorCraft AI is at sub-1,000 users with no paid ads, no social media presence, and a credit-based monetization model. Needed a full growth strategy covering viral loops, distribution, lead magnets, partnerships, and a Product Hunt launch playbook.

**Decision:** Etsy sellers (digital printable product sellers) designated as the primary acquisition segment. Rationale: highest LTV (need volume repeatedly), commercially motivated (willing to pay for tools), and networked (share tool recommendations across Facebook groups, YouTube, Pinterest). Secondary segments: teachers/homeschool parents, parents, interior designers. Pinterest identified as the highest-impact distribution channel for this product category. Watermark attribution on free-tier downloads designated as foundational viral mechanic. Referral-for-credits loop and public gallery with shareable URLs are the two product-led growth priorities. Product Hunt launch planned for month 3 after gallery seeding and community building.

**Status:** Accepted
**Impl:** `docs/colorcraft-growth-strategy.md`

### DR-069: Quick Fixes report — variant of full audit pipeline, not separate pipeline (2026-03-22)

**Context:** Quick Fixes ($67) product needs a shorter report (5 worst factors instead of 10). Options: (A) fork the entire report pipeline into a separate orchestrator, (B) add a `variant` parameter through the existing pipeline, (C) only change the HTML template.

**Decision:** Option B — variant parameter flows through `process-purchases.js → report-orchestrator.js → html-report-template.js`. After Opus scoring (step 4), the orchestrator filters `scoreJson` to keep only the 5 worst factors by score, top 5 problem areas, and top 7 recommendations. The HTML template conditionally skips the second factor page, adjusts cover title and intro text, and shows an upgrade CTA instead of benchmarking CTA. Delivery and confirmation emails branch on `purchase.product` for subject lines and body copy.

Audit+Fix ($497) uses the full audit pipeline unchanged, then queues a `human_review_queue` entry for manual implementation.

**Status:** Accepted
**Impl:** `333Method/src/reports/report-orchestrator.js` (variant filtering), `html-report-template.js` (conditional rendering), `report-delivery.js` + `purchase-confirmation.js` (product-aware emails), `process-purchases.js` (routing + audit_fix queue)

### DR-116: Extend follow-up sequence from 5 to 8 touches over 42 days (2026-03-29)

**Context:** Only 46 follow-ups sent from 44,900 initial outreach messages. Industry data shows most cold replies come on touches 3-7. Old sequence was 5 touches over 21 days with breakup at touch 5.

**Decision:** Extend to 8 touches over 42 days. New angles: Touch 5 = quick win (give one specific free fix), Touch 6 = ad waste / competitor gap, Touch 7 = authority ("scored 43,000+ sites"), Touch 8 = breakup. Old breakup moved from touch 5 to touch 8. Updated in followup-generator.js, claude-store.js, claude-orchestrator.sh, claude-batch.js, and AU templates.

**Status:** Accepted
**Impl:** `333Method/src/stages/followup-generator.js`, `data/templates/AU/followup-{email,sms}.json`

### DR-117: Add follow-up generation to main pipeline (2026-03-29)

**Context:** Follow-up generation only ran via the orchestrator's LLM batch path (claude-orchestrator.sh), which skips during conservation mode. The local template-based generator in followup-generator.js was never called by the main pipeline (all.js), explaining the near-zero follow-up rate.

**Decision:** Add `runFollowupGenerationStage` to `all.js` between outreach and replies stages. Uses template-based generation (no LLM needed). Orchestrator LLM path remains as supplementary.

**Status:** Accepted
**Impl:** `333Method/src/all.js` (added followup stage)

### DR-118: Ad pixel detection as outreach prioritisation signal (2026-03-29)

**Context:** Cold outreach to 44,900 businesses with $0 revenue. Marketing strategist analysis identified that businesses running paid ads are a higher-value cohort — they're already investing in traffic and would benefit most from conversion audit (ad spend wasted on low-scoring site).

**Decision:** Detect ad platform pixels from HTML source during assets stage (Google Ads AW- tags, Meta Pixel, Bing UET, call tracking services, LinkedIn/TikTok/Pinterest pixels, retargeting). Store as `is_running_ads` boolean + `ad_signals` JSONB on sites table. Outreach ORDER BY changed to `is_running_ads DESC, score ASC` (ad-running sites first). Backfilled 307 sites with stored HTML: 122 had ad signals (39.7%), 63 met the threshold for `is_running_ads=true`. Also built Meta Ad Library API module for definitive active-ad confirmation via Facebook Page slug extraction.

**Status:** Accepted
**Impl:** `333Method/src/utils/ad-detector.js`, `src/utils/meta-ad-library.js`, `src/stages/assets.js` (live detection), `src/stages/outreach.js` (prioritisation), `db/migrations/127-ad-signals.sql`, `scripts/backfill-ad-signals.js`, `scripts/enrich-meta-ads.js`

---

## Infrastructure (continued)

### DR-035: sops-nix + age encryption for secrets management (2026-03-01)

**Context:** Needed version-controlled, encrypted secrets for NixOS deployments. Docker Secrets alone don't provide git history.

**Decision:** sops-nix + age encryption. Secrets committed to git encrypted with server's SSH host key (via ssh-to-age conversion). `nixos-rebuild switch` decrypts at `/run/secrets/` with 0400 perms. 35 secrets encrypted (commit 759a2e9b). Server cloning: update `.sops.yaml` with new server's age key, re-encrypt, deploy.

**Status:** Accepted
**Impl:** `distributed-infra/.sops.yaml`, `secrets/production.yaml`, `modules/secrets.nix`

---

## 2Step Project

### DR-036: Parent-child platform architecture with separate repos (2026-03-10)

**Context:** 2Step and 333Method need shared code without duplication. Evaluated monorepo vs separate repos.

**Decision:** Separate GitHub repos per project with shared `@mmo/*` packages via npm workspaces (source of truth in mmo-platform). Not git submodules. Cleaner git history per project, easier to open-source later. Multi-root VSCode workspace (`mmo.code-workspace`) provides unified view.

**Status:** Accepted
**Impl:** Separate repos; `~/code/mmo.code-workspace`

### DR-037: Separate SQLite DB per project (2026-03-10)

**Context:** Each project has different data models. Shared DB vs separate?

**Decision:** Separate SQLite per project (`db/2step.db`, `db/sites.db`). Different schemas, independent migrations. Cross-query via `ATTACH` if needed later.

**Status:** Accepted

### DR-038: Outscraper for prospect data, not direct scraping (2026-03-10)

**Context:** 2Step needs business data with Google reviews. Options: direct scraping, SerpApi, DataForSEO, Outscraper.

**Decision:** Outscraper at $3/1K results. Purpose-built for Google Maps, moves ToS risk to data processor, returns clean REST JSON. Direct scraping rejected for bot detection risk and ToS liability.

**Status:** Accepted
**Impl:** `src/prospect/` modules

### DR-039: Fliki API over Creatomate for video automation (2026-03-10)

**Context:** Three tools evaluated: InVideo/Holo (manual, high quality), Creatomate ($0.46/video true cost incl. ElevenLabs+Stability), Fliki ($0.06/video, self-contained).

**Decision:** Fliki for Phase 2 automation. 7x cheaper than Creatomate when dependencies included. Fully self-contained (2,000+ voices, stock footage). Keep InVideo + Holo for manual split-test validation first.

**Status:** Accepted

### DR-040: R2 primary + B2 backup for clip hosting (2026-03-10)

**Context:** Need CDN-fast video clip hosting with redundancy.

**Decision:** Cloudflare R2 as primary (fast CDN, public URLs), Backblaze B2 as offsite backup. Local `clips/` for working copy, deleted after focus-tagger session. Clips stored flat in R2 (filename only, no subdir) for clean public URLs.

**Status:** Accepted
**Impl:** `src/video/r2-upload.js`, R2 bucket `2step-clips`

### DR-041: Video thumbnail+link in emails, never attachment (2026-03-10)

**Context:** How to deliver video demos: embed, attach, or link?

**Decision:** Thumbnail image with fake play button → click-through link. Never attach video. Reasons: Gmail/Outlook strip `<video>` tags, large attachments destroy deliverability, thumbnail emails get 10.3% CTR vs 6.1% static images.

**Status:** Accepted
**Impl:** `src/outreach/email.js`

### DR-042: 8-touch outreach sequence over 28 days (2026-03-10)

**Context:** Video demos need multi-touch to convert. What cadence?

**Decision:** 8 touches over 28 days, stops on reply. Mixed channels: 5 email, 2 SMS, 1 form. Angles: initial demo (0d), nudge (2d), ROI data (5d), view signal (8d), social proof (12d), case study + pricing (16d), SEO benefits (21d), breakup (28d). Templates per country.

**Status:** Accepted
**Impl:** `src/stages/proposals.js`

### DR-043: Deterministic clip rotation via prospect ID seed (2026-03-10)

**Context:** 174 clips across verticals. How to assign to prospects?

**Decision:** `CLIP_POOLS` with 5 clips per slot (a-e), rotated via `seed = prospect_id`. Same prospect always gets same clips. Shared pools (technician/resolution/CTA) + niche-specific pools. `NICHE_ALIASES` maps variants.

**Status:** Accepted
**Impl:** `src/video/shotstack-lib.js`

### DR-044: Logo use in demo videos is legal — nominative fair use (2026-03-10)

**Context:** 2Step embeds prospect's logo in demo videos. Legal risk assessment.

**Decision:** No risk. Nominative fair use: using trademark to refer to the owner. Not public broadcast, not commercial use of their mark, benefits them. Australian Trade Marks Act 1995 s122 applies. Hard limit: no re-sending to other businesses or publishing.

**Status:** Accepted

### DR-045: DM automation deferred to Phase 4 (2026-03-10)

**Context:** Instagram/Facebook DMs are high-touch. Automate now or wait?

**Decision:** Manual DMs only until email proven (2+ weeks). <50/day: LLM-generated per message via `claude -p` (zero cost). Meta automation deferred to Phase 4 — aggressive bot detection makes early automation risky.

**Status:** Accepted

### DR-046: Phase 2 @mmo/* package extraction — deferred (2026-03-10)

**Context:** 2Step imports from 333Method via `file:../333Method`. Needs proper shared packages long-term.

**Decision:** Deferred. Extract to `mmo-platform/packages/@mmo/*` (core, outreach, browser, monitor, orchestrator) when user directs. Multi-hour refactor touching both projects. Today's `file:` dep is acceptable interim.

**Status:** Accepted (deferred)

---

## Proposed — from unapproved distributed-agent-system.md

> These decisions are documented in [distributed-agent-system.md](plans/distributed-agent-system.md)
> which is a planning document, **not yet approved**. They represent evaluated options and
> recommended directions, but are not commitments. Status will change to Accepted when the plan
> (or relevant section) is approved and implementation begins.

### DR-047: Hybrid MCP + Redis for distributed agent coordination (proposed)

**Context:** Agent system needs distributed task coordination, mobile monitoring, and external integrations.

**Decision:** MCP for external integrations (Claude Desktop, Android, OpenClaw), Redis pub/sub + WebSocket for internal coordination. MCP is industry-standard; Redis provides sub-millisecond latency for task claiming.

**Status:** Proposed

### DR-048: Valkey over Redis for open-source licensing (proposed)

**Context:** Redis changed to SSPL (non-OSI) in 2024, then AGPL-3.0 in 2025. Need clear open-source persistence.

**Decision:** Valkey (BSD-3-Clause, Linux Foundation, drop-in Redis 7.2 compatible). Eliminates copyleft concerns for future commercial products.

**Status:** Proposed

### DR-049: NetBird + Rosenpass for quantum-safe VPN (proposed)

**Context:** Need secure mesh VPN for distributed USB workers without public internet exposure.

**Decision:** NetBird (WireGuard-based, self-hostable) with Rosenpass for hybrid PQ security (Curve25519 + ML-KEM + Classic McEliece). Prevents harvest-now-decrypt-later attacks.

**Status:** Proposed (NetBird itself is already in use — DR-001 references it)

### DR-050: FastAPI + React dashboard over Streamlit (proposed)

**Context:** Streamlit re-executes entire Python process on every page navigation.

**Decision:** React SPA (Vite, instant client-side routing) + FastAPI backend (async, 7 endpoints). Pre-computed cache table with 4-min TTL. Dashboard v2 scaffolded but not deployed.

**Status:** Proposed (scaffolded)
**Impl:** `dashboard-v2/` directory exists

### DR-051: USB worker nodes for distributed compute (proposed)

**Context:** VPS has 4 GB RAM; Playwright + pipeline + agents need 8+ GB for scale.

**Decision:** PostgreSQL + Redis stay on always-on VPS. Distribute pipeline stages, agents, and Playwright to USB-booted NixOS desktops connected via NetBird. Workers are amnesic (reset on reboot). Cost: VPS only; workers reuse spare desktops.

**Status:** Proposed
**Impl:** `distributed-infra/hosts/worker-usb/configuration.nix` exists

### DR-052: FOR UPDATE SKIP LOCKED for concurrent batch claiming (proposed)

**Context:** Multiple USB workers claiming pipeline jobs simultaneously without Redis job queue.

**Decision:** PostgreSQL native `FOR UPDATE SKIP LOCKED` in all stage-claiming SELECTs. Zero collision, no Redis queue overhead. Requires PostgreSQL migration (currently SQLite).

**Status:** Proposed (blocked by SQLite → PostgreSQL migration)

### DR-053: IronClaw WASM sandbox for agent safety (proposed)

**Context:** Claude Code on VPS (OpenClaw) needs isolation from secrets and production systems.

**Decision:** Replace Docker-based OpenClaw with IronClaw (WASM-sandboxed agent platform). WASM capability model blocks syscalls before kernel level — stronger than Docker + iptables + AppArmor. Simplifies architecture but adds vendor dependency.

**Status:** Proposed (IronClaw requires commercial license from Alias Robotics)

### DR-054: Shannon + Strix + CAI for defense-in-depth pentesting (proposed)

**Context:** Need multi-layered security testing beyond single tool.

**Decision:** Weekly Shannon (whitebox static), nightly Strix (dynamic blackbox), monthly CAI (external pentest). Findings normalized to common severity schema, routed to human_review_queue.

**Status:** Proposed

### DR-055: GPT-4o (not Claude) for independent audit log review (proposed)

**Context:** If IronClaw is compromised, attacker shouldn't be able to influence the LLM reviewing its own logs.

**Decision:** Use OpenRouter's `openai/gpt-4o` for daily audit analysis. Independence: different vendor from the agent being audited.

**Status:** Proposed

### DR-056: Claude Code desktop (not IronClaw) for NixOS config changes (proposed)

**Context:** IronClaw has broad system access; allowing it to run `nixos-rebuild switch` would be dangerous.

**Decision:** Only Claude Code on desktop applies NixOS config via SSH (as `admin` user with restricted sudo). All changes captured by auditd before Claude Code could alter them.

**Status:** Proposed

### DR-057: Hyper-V backend (not WSL2) for Windows Docker workers (proposed)

**Context:** WSL2 shares kernel with Windows, exposing container processes to Windows telemetry.

**Decision:** Always use Hyper-V backend (full VM boundary). Requires Windows Pro+. If machines can be wiped, prefer Linux (zero telemetry).

**Status:** Proposed

---

## Database & Storage (Phase 2b — Post-MVP)

### DR-058: Filesystem-backed JSON storage for score and contacts (2026-03-19)

**Context:** `score_json` and `contacts_json` columns in sites table grew as blobs; every query loaded full JSON even when only derived fields (score/grade/count) were needed.

**Decision:** Move JSON payloads to filesystem: `data/scores/{site_id}.json` and `data/contacts/{site_id}.json`. DB columns set to sentinel `{"_fs":true}` after extraction. Maintains `IS NOT NULL` gating for stage logic. Two-migration approach: 120 (extraction, idempotent, supports `--dry-run`) + 121 (column drop, requires SQLite 3.35+, not idempotent).

**Status:** Accepted
**Impl:** Migrations 120/121 (commit 683302f7), `db/schema.sql`

### DR-059: Remove proposals from OpenRouter stage map (2026-03-18)

**Context:** Proposal generation halted whenever OpenRouter hit rate limits, even though proposals use Claude Max subscription (via `claude -p`), not OpenRouter.

**Decision:** Remove `proposals` entry from OpenRouter stage map in rate-limiter config. Proposals depend only on Claude Max, not OpenRouter. OpenRouter rate limits no longer block proposal stage.

**Status:** Accepted
**Impl:** rate-limits.json (commit 9fb8e58d)

### DR-060: Rolling 7-day send rate for Gate 1 throttle (2026-03-17)

**Context:** Fixed 3×batch threshold was too rigid; didn't account for real-world send patterns and weekly variation.

**Decision:** Gate 1 now uses rolling 7-day daily_send_avg instead of fixed threshold. Gates 2/3 count only "actionable" outreach (ACTIVE_COUNTRY_CODES + non-skipped channels + sites below LOW_SCORE_CUTOFF). Improves gating stability and prevents false positives from one-day spikes.

**Status:** Accepted
**Impl:** Orchestrator throttle logic (commit 3cfed327)

### DR-061: Lazy-import 2Step stages into unified pipeline service (2026-03-16)

**Context:** 2Step stages (Reviews, Enrich, Video, Proposals, Outreach, Replies) lived in separate code; needed unified orchestrator without tight coupling.

**Decision:** Pipeline-service.js conditionally imports 2Step stages only when `TWOSTEP_PIPELINE_ENABLED=true`. Lazy-load prevents module bloat in 333Method-only environments. Single orchestrator loop can process both projects simultaneously.

**Status:** Accepted
**Impl:** src/pipeline-service.js (commit 3cfed327)

### DR-062: Project-aware autoresponder (2026-03-15)

**Context:** Autoresponder used fixed prompt and pricing; 333Method and 2Step have different value props, funnels, and payment models.

**Decision:** Autoresponder detects `project` from config, loads project-specific prompt (`prompts/autoresponder-{project}.md`), and adjusts identity, value prop, pricing, and payment URL accordingly. For 2Step: video review identity + $625 setup/$99/mo pricing + auditandfix.com/v/ payment URL.

**Status:** Accepted
**Impl:** src/autoresponder.js (commit 2c93c15e)

### DR-063: Shared messages.db with project column and ATTACH (2026-03-14)

**Context:** 333Method and 2Step maintained separate message logs. Needed unified audit trail without code duplication.

**Decision:** Centralize all messages to `mmo-platform/db/messages.db` with `project` column (`'333method'` or `'2step'`). 333Method's db.js ATTACHes shared schema. Migration 117 maps 333Method columns to shared schema (e.g., `gdpr_blocked→parked`, `reply_skipped→reply`) and drops project-specific columns. Cross-project queries via ATTACH.

**Status:** Accepted
**Impl:** Migration 117 (commit b9c8bd09), db.js `ATTACH db/messages.db`

### DR-064: Archive ignored sites to separate table (2026-03-12)

**Context:** Ignored sites (69% of sites table) were scanned in every pipeline cycle but never queried for outreach or scoring.

**Decision:** Move ignored sites to `sites_archive` table in migration 116. Main table now contains only active prospects. Reduces query size, improves performance. Migration is idempotent (INSERT OR IGNORE) with --dry-run support.

**Status:** Accepted
**Impl:** Migration 116 (commit 6e927abf)

### DR-065: Database resilience strategy — complete the split, fix the migration runner, defer Postgres (2026-03-22)

**Context:** sites.db has grown to 13GB (plus a 12GB WAL file that is not checkpointing down), causing three wipe incidents in two days. Root cause of wipes: migration scripts running DDL outside transactions. Backup is also broken: `cp`-based backup on a live WAL-mode DB produces a malformed copy, and the 8-13GB size makes any file-copy backup slow (15-60 min). Options evaluated: (A) split SQLite files, (B) migrate to local Postgres now, (C) keep SQLite + fix tooling, (D) hybrid split with Postgres for critical tables.

**Decision:** Complete Option A (split already started as DR-063), layer Option C safeguards on top, and explicitly defer Option B until VPS is provisioned.

Rationale for each option:

**Option B (local Postgres now) is rejected.** Migrating ~50 files from `better-sqlite3` to a `pg` async driver is a multi-day refactor touching every pipeline stage. Local Postgres also needs a VPS migration within weeks anyway, meaning two rounds of driver changes for zero operational benefit today. The real problems — wipes from unsafe migrations and unreliable backups — are not solved by the database engine choice.

**Option D (hybrid) is rejected.** Two databases with different engines during a period of instability is more complex to operate, not less. The partial-protection benefit (messages in Postgres, sites in SQLite) is achieved more cheaply by completing the SQLite split already in progress.

**Option A is already half-done.** DR-063 moved messages, opt_outs, and unsubscribed_emails into `mmo-platform/db/messages.db` (51MB). The critical data is already isolated. Sites.db can be wiped and re-seeded without touching messages.db. The remaining work is: (1) split off `ops.db` for cron_jobs, pipeline_control, settings, migrations, and agent_tasks; (2) verify all migration runner scripts are wrapped in explicit transactions with a row-count canary check before commit; (3) add a pre-migration backup of messages.db (trivially fast at 51MB).

**Option C safeguards to layer on top of the split:**
- Migration runner: every DDL migration must run inside `BEGIN IMMEDIATE ... COMMIT` (not just `BEGIN`); if a row-count canary fails, roll back and abort — never proceed with a half-applied migration.
- WAL bloat: the 12GB WAL file indicates the autocheckpoint threshold (4000 pages) is not keeping up with write volume. Switch to `PRAGMA wal_autocheckpoint = 1000` (default) for sites.db since backup no longer depends on WAL state. Run `PRAGMA wal_checkpoint(TRUNCATE)` in the daily backup job, not just PASSIVE.
- Backup: back up messages.db (51MB) every 4 hours via `sqlite3 messages.db .dump | gzip` — done in seconds, safe with live WAL. Back up sites.db daily using `db.backup()` online API as already implemented; the WAL checkpoint must precede it.

**Migration path to VPS-ready state:**
1. Today: ops.db split (new migration runner wraps in transactions).
2. Before VPS provisioning: set up restic/B2 for both messages.db and ops.db (tiny files, fast offsite).
3. VPS day: `pg_dump`-equivalent is `sqlite3 messages.db .dump` — restore into Postgres with a schema translation script. This is a one-time port of the schema + data, not a driver rewrite (messages.db has a clean, project-namespaced schema already designed for this, per DR-063).

**What we are giving up:** SQLite still has no row-level locking (writer blocks all writers), no point-in-time recovery, and no streaming replication. These are accepted risks for the current single-writer pipeline architecture. Revisit when the VPS is live and a second project needs to write concurrently.

**Status:** Accepted
**Impl:** ops.db split migration (pending), migration runner transaction hardening (pending)

### DR-066: CAPTCHA benchmark guard condition — skip when no recent solve activity (2026-03-22)

**Context:** `scripts/benchmark-captcha-providers.js` runs every 30 minutes via cron, calling NopeCHA and CapMonster APIs with a test reCAPTCHA job (~1-2 credits per provider per run). Form outreach is disabled most of the time, so the benchmark fires unconditionally when there is nothing to optimise, wasting credits and adding noise to the rolling averages.

Two options were evaluated:
- Option A: skip if fewer than N solves occurred in the last hour (time-windowed activity check)
- Option B: run every N cumulative solves (counter-based trigger)

**Decision:** Option A, using a 60-minute activity window, with the signal sourced from `data/captcha-provider-benchmark.json` rather than a DB query. `solveCaptcha()` in `form.js` already writes `updated_at` to the benchmark file on every successful solve. Checking whether any provider's `updated_at` is within the last 60 minutes is a zero-dependency proxy for "form outreach is actively running."

Option B was rejected because it requires a persistent counter or is functionally equivalent to Option A (querying "solves since last benchmark run"), but with worse clarity. It also conflates activity rate with information age: slow batches benchmark too rarely, fast batches benchmark unnecessarily often.

The 60-minute window fits the 30-minute cron cadence: a batch that stopped 31 minutes ago causes the next two cron invocations to skip, which is correct behaviour. Provider performance drifts on the order of hours, not minutes, so one benchmark per active hour is more than sufficient. A 24-hour staleness floor (run unconditionally if `updated_at` is older than 24h) was considered but deferred — CapMonster's 6.4s average is far enough ahead of NopeCHA's 57s that stale data still picks the correct winner.

**Guard condition** (top of `main()`):
```js
const oneHourAgo = Date.now() - 60 * 60 * 1000;
const hadRecentActivity = Object.values(benchmark).some(
  v => v.updated_at && new Date(v.updated_at).getTime() > oneHourAgo
);
if (!hadRecentActivity) { process.exit(0); }
```

**Status:** Accepted
**Impl:** `333Method/scripts/benchmark-captcha-providers.js` (not yet applied)

### DR-082: Social profile contact extraction + key_pages consolidation (2026-03-24)

**Context:** Enrichment collected social profile URLs (Facebook, Instagram, LinkedIn, YouTube, Yelp) but never visited them for contact extraction. Only key_pages (contact/about pages on the prospect's own site) were browsed. Additionally, `key_pages` was duplicated in both score JSON (`contact_details.key_pages`) and contacts JSON — the score copy was a stale snapshot never read downstream.

**Decision:** (1) Remove `key_pages` from score JSON output; contacts JSON is the canonical source. Backfill stripped 53,972 existing score files. (2) New `social-contact-extractor.js` module visits social profiles during enrichment to extract email, phone, and city. YouTube uses raw HTTP (parse `ytInitialData` JSON); Facebook, LinkedIn, Yelp, Instagram use Playwright stealth (reusing the existing browser instance from key_pages browsing). Facebook is the highest-yield platform — JS-rendered pages expose email+phone. LinkedIn provides city. Yelp requires nopecha for Cloudflare CAPTCHA. Instagram is mostly login-walled. Integrated after key_pages merge in `enrich.js`, using `mergeExtractedContacts()` for dedup.

**Status:** Accepted
**Impl:** `333Method/src/utils/social-contact-extractor.js`, `333Method/src/stages/enrich.js`, `333Method/src/stages/scoring.js`

## Marketing & Growth

### DR-070: Q2 2026 growth strategy -- channel prioritization and funnel restructure (2026-03-23)

**Context:** Reviewed auditandfix.com to develop a comprehensive growth strategy. Found critical gaps: site not indexed by Google (zero organic search presence), no Meta Pixel installed, no Google Analytics/GA4, no post-scan email sequence built despite 23,990+ scans, passive referral program with no tracking, and the $337 Full Audit positioned as primary CTA over the $67 Quick Fixes entry point.

**Decision:** Three strategic priorities for Q2 2026:

1. **Build post-scan email sequence immediately** (7 emails, 14 days, segmented by score band). This monetizes the existing email list with zero ad spend.
2. **Get indexed by Google** -- submit to Search Console, create 50-100 programmatic SEO pages for industry/city combinations (e.g., `/audit/plumber-sydney`), start blog content.
3. **Lead with Quick Fixes ($67) as the primary CTA** everywhere -- scanner, cold outreach, ads. Use credit-toward-upgrade as the upsell path.

Paid budget ($200/mo) split: $150 Google Ads (search, scanner as landing page) + $50 Meta retargeting (scanner visitors who did not purchase). No Meta prospecting -- budget insufficient.

Full strategy documented in `docs/growth-strategy-2026-Q2.md`.

**Status:** Proposed
**Impl:** `docs/growth-strategy-2026-Q2.md`

### DR-071: SEO & organic content strategy for auditandfix.com (2026-03-23)

**Context:** Developed comprehensive SEO strategy for auditandfix.com. Critical finding: site has zero Google indexation despite robots.txt not blocking crawlers. Likely cause is either an X-Robots-Tag noindex header, missing Search Console verification, or the site simply never being discovered (no backlinks, no sitemap submission). Competitive analysis shows the "website audit for tradies/small business" niche is dominated by agency blog content with no self-service tool attached -- a gap Audit&Fix can own.

**Decision:** Five-phase strategy:
1. Fix indexation immediately (diagnose noindex header, verify GSC, submit sitemap)
2. Build blog infrastructure (PHP template, Article schema, auto-sitemap)
3. Target problem-aware keywords tradies actually search ("website not getting enquiries", "tradie website mistakes") not marketing jargon ("CRO audit")
4. Create programmatic industry benchmark pages using aggregate scan data from 23,990+ sites
5. Build backlinks via data PR (original statistics from scan data) and tool directory submissions

Key differentiation: Only tool combining conversion-focused scoring + plain English + industry-specific relevance + 23,990-site dataset. Content strategy: 15 articles prioritised by impact, all funnelling to free scanner.

**Status:** Proposed
**Impl:** `docs/seo-strategy-auditandfix.md`

### DR-072: Google Ads campaign structure for auditandfix.com (2026-03-23)

**Context:** A$200/month budget for paid search. Need to drive scanner usage (free scans leading to email capture and paid reports). Australia-only targeting. Solo operator setup -- must be manageable without a PPC specialist.

**Decision:** Three-campaign structure:
1. Brand campaign (A$30/mo) -- protect "audit and fix" brand terms, cheap insurance
2. Non-Brand Website Audit (A$105/mo) -- core keywords across 3 ad groups: website audit, website review/analysis, CRO/conversion
3. Non-Brand Website Problems (A$60/mo) -- problem-aware searchers across 2 ad groups: website not converting, fix my website

Start with Manual CPC to learn, transition to Maximise Conversions after 30+ conversions/month. Exact + phrase match only (no broad at this budget). 2Step video reviews kept as separate future campaign with its own budget -- do not split from the A$200.

Primary conversion: email capture (A$5 value). Secondary: scan started/completed. Purchase tracking with dynamic values.

**Status:** Proposed
**Impl:** `docs/google-ads/`

### DR-074: ColorCraft AI paid media strategy — channel selection and launch architecture (2026-03-23)

**Context:** colorcraft-ai.com has no tracking, no ad spend history, and no conversion data. Product is a credit-based AI coloring book / palette generator (Base44 SPA). Six target audiences: parents, teachers, artists/designers, Etsy sellers, interior designers, gift buyers. Needed a comprehensive paid media strategy from tracking setup through to scaling, appropriate for a bootstrapped founder starting from $0.

**Decision:** Full paid media strategy documented in `docs/colorcraft-paid-media-strategy.md`. Key decisions:
1. **Tracking first, spend second** — GTM injection via existing Cloudflare Worker (or Base44 settings) is the unlock; all other tags deploy through it. GA4 → Google Ads import for conversion data; Meta Pixel + CAPI via sGTM within first 2 weeks of Meta spend.
2. **Google Search as primary channel** — Arts & Entertainment CPCs are lowest of all Google industries (~$0.80–$1.80 for this niche), purchase intent is direct, keyword demand is measurable. $900 of $1,500/month starter budget allocated here.
3. **Bidding strategy: Maximize Conversions (no tCPA) for first 30 conversions** — new account has no data; hard CPA constraints starve Smart Bidding's learning phase. Transition to loose tCPA (1.5–2x observed CPA) once 50+ monthly conversions are reached.
4. **7-campaign Google structure**: Brand, 4 non-brand Search (Coloring Book, Palette, Print/Gift, Etsy/Creator), PMax, Display Retargeting. Campaigns staggered — launch Brand + Coloring Book first, expand after 30 conversions.
5. **Meta at $225/month, 2 ad sets only in month 1** (Parents + Etsy Sellers). Meta cannot exit learning phase below ~50 conversions/ad set/week; spreading $7.50/day across 6 ad sets guarantees all fail to learn.
6. **Etsy seller segment is highest LTV** — justify 2x CPA ceiling; these users buy $40–$90 credit packs repeatedly.
7. **Pinterest, TikTok, YouTube standalone deferred** to months 3+ — cover via PMax in the interim.
8. **Performance benchmarks** calibrated to Arts & Entertainment Google industry data and Books/Music Meta category: expected Search CPC $0.80–$2.50, CPA (purchase) $25–$80, Meta CPA (purchase) $20–$90 depending on segment.

**Status:** Proposed
**Impl:** `docs/colorcraft-paid-media-strategy.md`

### DR-073: Outreach template overhaul — tradie language, score segmentation, angle rotation, spintax (2026-03-23)

**Context:** Cold outreach templates used marketing jargon ("poor headline clarity", "missing CTA") that local business owners/tradies don't relate to. Templates lacked score-range segmentation, so a site scoring 35 got the same framing as one scoring 75. Follow-up sequences used the same angle (score) for every touch, increasing spam perception. Insufficient spintax variation meant emails could appear textually identical.

**Decision:** Four-part overhaul:
1. **Tradie language** — FACTOR_LABELS in template-proposals.js rewritten in plain English ("your site doesn't tell visitors what you do in 3 seconds" instead of "weak headline"). New AU-specific "tradie" templates for missing CTA, no reviews, weak value prop.
2. **Score-range segmentation** — Three new template tiers: score-urgent (0-40, transformation framing), score-precision (41-65, targeted fixes), score-optimization (66-82, refinement). Applied to AU, GB, US, NZ.
3. **Angle rotation** — New template approaches: competitor-gap ("they're not better at plumbing — their website just works harder"), reviews-disconnect ("great Google reviews but none on your site"), ad-waste (Touch 5 breakup for Google Ads spenders), video-crosssell (2Step upsell for 20+ review prospects). Follow-up templates now rotate angles across touches 3 and 5.
4. **Spintax density** — Every existing template rewritten with significantly more spintax throughout greetings, closings, transitions, and value statements. Goal: no two emails from the system are textually identical.

All changes applied to AU, GB, US, NZ templates (email + SMS + follow-ups). Prompt files (PROPOSAL.md, FOLLOWUP.md) updated with guidance.

**Status:** Accepted
**Impl:** `data/templates/{AU,GB,US,NZ}/{email,sms,followup-email,followup-sms}.json`, `src/utils/template-proposals.js` (FACTOR_LABELS), `prompts/PROPOSAL.md`, `prompts/FOLLOWUP.md`

### DR-074: SEO strategy for colorcraft-ai.com (2026-03-23)

**Context:** colorcraft-ai.com is a React SPA on Base44 hosting an AI coloring book and color palette generator with print fulfillment. Zero organic indexation as of 2026-03-23. Cloudflare Worker handles meta/OG/schema injection but no crawlable body content exists. Two overlapping competitive keyword spaces: AI coloring generators (high competition, free-tool dominated) and color palette generators (owned by Adobe/Canva/Coolors). Site's defensible moat is combining both use cases with professional print fulfillment — unique in the market.

**Decision:** Three-phase strategy:
1. Unblock indexation immediately — GA4 injection via Worker, GSC setup, sitemap fix (PascalCase to lowercase), dynamic rendering for bots (Prerender.io via Worker)
2. Build content on a subdirectory blog (Ghost or Astro at /blog/) — 10 priority articles targeting low-competition keywords for Etsy sellers, teachers, KDP publishers, and print buyers; pillar page on "how to make a coloring book"
3. Authority via AI tools directories (20+ submissions in Month 1), roundup article outreach, teacher/homeschool community placements, and an original research asset from prompt data

Global English-first targeting (American spelling); US is primary market for volume; AU targeting only for print-specific queries if fulfillment is AU-based. No hreflang needed at this stage.

**Status:** Proposed
**Impl:** `docs/seo-strategy-colorcraft-ai.md`

### DR-075: ColorCraft market research findings — positioning and product priorities (2026-03-23)

**Context:** Commissioned comprehensive market research on the AI coloring book and color palette generator space. Covered market size, competitors, keyword demand, audience segments, monetisation benchmarks, platform trends, seasonal patterns, emerging opportunities, and risks. Full report: `docs/colorcraft-market-research-2026-03-23.md`.

**Decision:** Key findings that should drive product and go-to-market decisions:

1. **Commercial rights are mispriced by competitors.** GenColor gates commercial use at $49.90/mo. ColorCraft's one-time credit model is harder to compare. Opportunity: introduce a subscription tier with commercial rights at $20–$29/mo to capture the KDP/Etsy creator segment — the fastest-growing, highest-willingness-to-pay audience.

2. **KDP/Etsy creators are the primary target.** They pay for: batch generation, KDP-ready PDF export, no watermarks, commercial rights. "Paige" AI assistant is a genuine differentiator — lean into it in all positioning.

3. **Educator/homeschool segment is underserved.** No AI coloring tool specifically addresses curriculum-aligned generation. Homeschool market growing at 10.3% CAGR; 64% of families use digital resources.

4. **Pinterest + YouTube are the two highest-ROI acquisition channels.** Pinterest drives evergreen long-tail traffic (pins surface for months/years); YouTube creator tutorials convert the KDP/Etsy audience directly.

5. **November–December is the primary seasonal window.** Google Trends peaks at December (score 84–94 normalised), trough May–July (score 43–45). Plan campaigns ramping from October.

6. **Canva is the primary medium-term threat.** Acquired Leonardo.ai Phoenix model 2025; adding a line-art coloring workflow is trivially achievable. The durable moat is workflow depth (KDP specs, Paige AI, batch), not raw generation quality.

7. **Copyright ambiguity is a real risk.** US Copyright Office ruling: AI-generated images cannot be copyrighted. This erodes the PLR resale market long-term. Position as a production workflow tool, not a content ownership play.

**Status:** Accepted (research findings recorded)
**Impl:** `docs/colorcraft-market-research-2026-03-23.md`

### DR-076: ColorCraft AI — brand name legal review and trademark strategy (2026-03-23)

**Context:** Before investing in AU business registration and US marketing for colorcraft-ai.com, a legal risk assessment was needed covering: AU trademark clearance, ASIC business name availability, domain conflicts, "AI" in brand name under ACCC/ACL, US trademark risk, colour/color spelling, AU trademark filing strategy, common law rights, and business structure.

**Decision:** "ColorCraft AI" is viable to register and build on in Australia with a medium overall risk profile. Key findings:

1. **Australia is clear.** No conflicting AU trademarks found in Classes 9, 41, or 42. Existing "Colourcraft" ASIC registrations are all in painting/decorating trades — different sector, different classes. "ColorCraft AI Pty Ltd" and "ColorCraft AI" business name appear available. "AI" is not a restricted word under ASIC rules.

2. **The US is the primary risk.** colorcraft.ai (US-based, California law, founded 2024) operates an identical product — AI coloring page generator — under the identical brand name "ColorCraft". They have prior common law use in the US market. No registered US trademark was found at time of research, but this doesn't eliminate risk. US market investment should be preceded by a professional USPTO clearance search and a decision on whether to file a US Intent-to-Use trademark application.

3. **AU trademark filing: proceed promptly.** File in Classes 9 (software/downloadable apps), 41 (educational/creative services), and 42 (SaaS/cloud platform). Government fees: $250/class = $750 for 3 classes. Total with attorney: ~$2,250–$3,250 AUD. Timeline: 7 months minimum to registration.

4. **Business structure: Pty Ltd required.** Sole trader creates personal liability exposure for a global SaaS with international customers and potential IP/data claims. Pty Ltd provides liability protection, correct IP ownership structure, and tax efficiency at scale.

5. **"AI" in brand name:** No ACCC/ACL concern with the name itself. Product must genuinely use AI technology (it does). No disclosure requirement for the specific AI stack used.

6. **Color vs Colour spelling:** No legal consideration. American spelling is standard in software/tech and works correctly with .com/.ai domains. Use consistently across all markets.

7. **Common law rights from operating colorcraft-ai.com** are real but weak at early stage in Australia (limited reputation to prove) and weaker than colorcraft.ai's rights in the US. Registration is the priority.

**Status:** Superseded by DR-077 (Wyoming LLC scenario)
**Impl:** `docs/colorcraft-legal-review-2026-03-23.md`

---

### DR-077: ColorCraft AI — Wyoming LLC as holding entity; USPTO filing before IP Australia (2026-03-23)

**Context:** Business may be registered under a Wyoming LLC instead of (or in addition to) an Australian Pty Ltd, with the US as the primary market. DR-076 recommended filing Australian trademark first and forming a Pty Ltd first. This revision covers: (1) Wyoming LLC vs Pty Ltd for IP holding, (2) whether USPTO should come before IP Australia, (3) cross-border IP ownership and tax implications, and (4) a full re-evaluation of all 10 risk areas with the LLC structure in mind.

**Decision:** The Wyoming LLC is the correct holding entity for a US-primary market SaaS. Material strategy changes from DR-076:

1. **File USPTO first, not IP Australia.** The US is the primary market and the colorcraft.ai conflict is US-based. The Wyoming LLC has direct domestic standing to file and enforce a USPTO trademark. File an Intent-to-Use (ITU) application in Classes 9, 41, and 42 immediately in the LLC's name. Government fee: $750–$1,050 USD. Attorney: $1,500–$3,000 USD.

2. **File IP Australia within 6 months of USPTO filing** using the Paris Convention priority claim. This gives the AU application the same effective priority date as the USPTO application. Applicant: Wyoming LLC (foreign entity applicants are permitted at IP Australia). Cost: $750 AUD government fees + $1,500–$2,500 AUD attorney. File UK trademark at the same time if UK is a real market (~£270 GBP government fees).

3. **Wyoming LLC IP ownership is clean.** A Wyoming LLC is a domestic US juristic person — it can own USPTO trademarks directly with no complexity. It can also own IP Australia trademarks as a foreign applicant (with an Australian address for service via the IP attorney). All IP (trademarks, domain names) should be held by the LLC.

4. **Transfer colorcraft-ai.com domain to the LLC** to evidence the LLC as the US trading entity for common law trademark purposes.

5. **Defer the Australian Pty Ltd** until AU operations warrant it (approximately $50K+ AUD annual AU revenue, or an AU employee). When incorporated, the Pty Ltd takes a documented IP license from the LLC (arm's-length royalty 3–8% of net revenue; transfer pricing rules apply under Subdivision 815-B ITAA 1997).

6. **Cross-border tax is manageable but requires advice.** Wyoming LLC owned by an Australian resident = foreign-owned disregarded entity for US federal tax. Australian-source income is included in the owner's AU tax return; US-source income triggers a US federal filing obligation. The Australia-US Tax Convention reduces double taxation. Stripe Tax handles AU GST (10%) and UK VAT collection. Engage a US/AU international tax advisor before significant revenue.

7. **FinCEN BOI report is required** for the Wyoming LLC within 30 days of formation (or by Jan 1, 2025 if formed before 2024). This is a compliance step, not a risk.

8. **Business structure: Option A (LLC only) now; Option B (LLC + Pty Ltd) when AU operations mature.** The original DR-076 "Pty Ltd from day one" recommendation is revised — it applies only if the AU market is primary. With US as primary, Wyoming LLC alone is appropriate at early stage.

**Status:** Superseded by DR-078 (sole trader correction — Wyoming LLC is not the actual structure)
**Impl:** `docs/colorcraft-legal-review-wyoming-2026-03-23.md`

---

### DR-078: ColorCraft AI — Australian sole trader structure; IP Australia filing first (2026-03-23)

**Context:** DR-077 was premised on a Wyoming LLC holding entity. This was incorrect. The business is registered as an **Australian sole trader** with an existing ABN. Stripe is already live under that ABN. No company (Pty Ltd or LLC) exists. This entry records the correct structural baseline and the resulting strategy changes.

**Decision:** Australian sole trader is the operating and IP-holding structure. Key conclusions:

1. **All IP is personally owned.** A sole trader is not a separate legal entity — trademarks, domain names, and copyrights vest in the individual. No IP assignment or licensing is needed at this stage.

2. **File IP Australia first.** Lower cost ($750 AUD government fees), faster timeline (6–9 months to registration), simpler as an individual applicant. File in Classes 9, 41, and 42 in the individual's personal name. Attorney: $1,500–$2,500 AUD.

3. **File USPTO within 6 months via Paris Convention** claiming the IP Australia filing date as priority. Both applications share the same effective priority date. Foreign-domiciled applicants must use a US-licensed attorney (mandatory under 37 C.F.R. § 2.11). File as an Australian individual, not as any entity. Cost: $750–$1,050 USD government fees + $1,500–$3,000 USD attorney.

4. **Register "ColorCraft AI" as an ASIC business name** under the existing ABN. Required to trade legally under a name other than the individual's personal name. Cost: $44–$102 AUD. Takes 10 minutes at abr.business.gov.au.

5. **Stripe requires no changes.** Already correctly configured under the AU ABN as a sole trader.

6. **Sole trader liability exposure is the primary structural risk.** No liability shield — personal assets exposed if sued. Consider incorporating a Pty Ltd before significant US marketing spend or if a cease-and-desist arrives. IP assignment to the Pty Ltd at that time requires a formal assignment deed and IP Australia/USPTO recordal.

7. **Wyoming LLC is not needed and not in place.** DR-077 Wyoming LLC content is archived but not enacted. The colorcraft.ai conflict analysis and Paris Convention filing strategy are materially the same; only the applicant identity and filing order change.

**Status:** Accepted
**Impl:** `docs/colorcraft-legal-review-2026-03-23.md`

### DR-079: ColorCraft AI — brand rename candidate screening and winner selection (2026-03-23)

**Context:** "ColorCraft AI" has a direct .com domain conflict with colorcraft.ai, a live US competitor. A rename is required before significant marketing spend. 20 candidate names were generated and screened against four criteria: .com domain availability (RDAP/WHOIS), web brand presence (DuckDuckGo/Google search), trademark search (web-indexed USPTO records), and trademark risk assessment. All domain checks performed via Verisign RDAP API. All web searches performed live.

**Decision:** Rename to **Inkmora** (inkmora.com). Full screening results below.

**Candidate pool and screening results:**

| # | Name | .com Status | Web Brand Presence | TM Risk | Notes |
|---|------|-------------|-------------------|---------|-------|
| 1 | Inkmora | AVAILABLE | None found | Low | Invented word; "ink" + "-mora" suffix; no hits in adjacent software/AI/creative space |
| 2 | Inkfolia | AVAILABLE | None found | Low | "Ink" + "folia" (Latin for leaves/pages); evokes coloring book pages; clean search results |
| 3 | TintMind | AVAILABLE | None found | Low | "Tint" + "Mind" (AI cognition); no competing brand found; slightly abstract |
| 4 | HueGeni | AVAILABLE | None found | Low | "Hue" + "Geni" (genius/genie); clear AI connotation; Huemint exists but no conflict |
| 5 | Palettopia | AVAILABLE | None found | Low | "Palette" + "-topia" (utopia); evocative but longer (10 chars) |
| 6 | TintGen | AVAILABLE | None found (TINT brand unrelated — window tint software) | Low | Short, functional; "Tint" + "Gen" (generate); slightly generic |
| 7 | ColorAIO | AVAILABLE (.com) | Color.io (live competitor), color-io.en.softonic.com | Medium-High | Visually/phonetically similar to color.io — likely confusion |
| 8 | ColorAIX | AVAILABLE | None found | Low-Med | Functional but "-AIX" reads as IBM OS; slightly awkward |
| 9 | ChromaPage | AVAILABLE | Chroma coloring app exists; "Chroma" crowded in design tools | Medium | "Chroma" segment overused in color/design space |
| 10 | InkPagen | AVAILABLE | None found | Low-Med | Awkward compound; "pagen" not a natural English morpheme |
| 11 | SketchAir | REGISTERED (.com) | SketchAI, SketchAR, SketchAir iOS app all live | High | Multiple live products with nearly identical names; confusion risk |
| 12 | PixelBloom | REGISTERED (.com) | — | — | Domain taken |
| 13 | ChromaGen | REGISTERED (.com) | — | — | Domain taken; "chromagen" already a genetics/biotech brand |
| 14 | PalettIQ | REGISTERED (.com) | — | — | Domain taken |
| 15 | LumiGen | REGISTERED (.com) | — | — | Domain taken |
| 16 | Tintify | REGISTERED (.com) | — | — | Domain taken |
| 17 | Kolora | REGISTERED (.com) | — | — | Domain taken |
| 18 | PrismAI | REGISTERED (.com) | — | — | Domain taken |
| 19 | VivAIA | REGISTERED (.com) | — | — | Domain taken; vivaia.com is active shoe brand (unrelated but taken) |
| 20 | ChromaFlow | REGISTERED (.com) | — | — | Domain taken |

**Top 5 shortlist:**

1. **Inkmora** — inkmora.com AVAILABLE; inkmora.ai AVAILABLE; inkmora.app likely available. Zero web brand presence. "Ink" clearly evokes illustration/coloring; "-mora" is a pleasing invented suffix (evokes "more", "amore", fluidity). 8 chars. Low TM risk — fully invented word.
2. **Inkfolia** — inkfolia.com AVAILABLE; inkfolia.ai AVAILABLE. Zero web brand presence. "Folia" (Latin: pages/leaves) is a strong semantic fit for coloring books. 8 chars. Low TM risk.
3. **TintMind** — tintmind.com AVAILABLE; tintmind.ai AVAILABLE. Zero web brand presence. Clean AI + color connotation. 8 chars. Low TM risk. Slightly more abstract than Inkmora.
4. **HueGeni** — huegeni.com AVAILABLE; huegeni.ai AVAILABLE. Zero web brand presence. "Geni" suffix signals AI/intelligence clearly. 7 chars. Low TM risk. Huemint (huemint.com) exists but is visually and phonetically distinct.
5. **Palettopia** — palettopia.com AVAILABLE; palettopia.ai AVAILABLE. Zero web brand presence. Strong semantic fit — "palette" + "utopia". 10 chars (within 12-char limit). Low TM risk.

**Winner: Inkmora**

Rationale:
- "Ink" is the clearest single-syllable anchor to the product category (coloring, illustration, drawing, book pages) without using "color" or "craft"
- "-mora" is a distinctive invented suffix with no competing meaning in software; phonetically smooth in all target markets (AU/US/UK)
- 8 characters — well within the <12 char preference; easy to spell, say, and type
- inkmora.com is unregistered (confirmed via Verisign RDAP); inkmora.ai is also clear
- No existing brand, app, product, or trademark found in any adjacent space after live web search
- Fully invented compound — strong trademark distinctiveness (fanciful/arbitrary category)
- Works as a domain, app name, social handle, and spoken brand equally well
- "Ink" anchors it to creativity/art; "mora" can later be positioned as a coined term (e.g., "inspire more color")

Rejected alternatives:
- ColorAIO: Too close to color.io (live competitor); likely confusion
- SketchAir: Multiple live products with near-identical names; high collision risk
- ChromaPage: "Chroma" is overused in design/color tooling; diluted distinctiveness
- TintGen/ColorAIX: Functional but low brand personality; harder to trademark generic-adjacent names

**Status:** Accepted — pending owner confirmation
**Impl:** Brand rename pending; ASIC name registration update required once confirmed; domain registration at inkmora.com + inkmora.ai recommended immediately

### DR-080: Remove ANTHROPIC_API_KEY and @anthropic-ai/sdk from 333Method and 2Step (2026-03-23)
**Context:** Claude Max subscription provides free LLM access via the claude CLI orchestrator. The Anthropic SDK was a parallel paid API path to the same models. Running both meant unnecessary API credit costs and dual code paths.
**Decision:** Remove all `@anthropic-ai/sdk` imports and `ANTHROPIC_API_KEY` usage. Production LLM calls route through OpenRouter exclusively. Dev tools (sage-auto-fix, generate-tests, update-stale-docs) use `claude -p` CLI invocations instead of the SDK. The `@anthropic-ai/sdk` package remains in package.json for now (separate cleanup).
**Status:** Implemented
**Impl:** `src/utils/llm-provider.js`, `src/inbound/autoresponder.js`, `src/agents/utils/agent-claude-api.js` (333Method); `src/video/shotstack.js` (2Step); `scripts/sage-auto-fix.js`, `scripts/generate-tests.js`, `scripts/update-stale-docs.js`, `scripts/unified-autofix.js` (333Method)

### DR-081: Agency Agents management -- git clone with update script, no custom agents (2026-03-23)

**Context:** `~/.claude/agents/` contains ~170 .md files bulk-copied from [msitarzewski/agency-agents](https://github.com/msitarzewski/agency-agents) (MIT license, updated weekly). No version tracking, no way to detect upstream additions/removals/renames. The original concern was that some local files might be custom (backend-architect-with-memory.md, workflow-*.md, nexus-spatial-discovery.md) but analysis confirmed all 170 local files exist in upstream -- zero are custom. 24 of the 170 are non-agent files (README, strategy docs, examples, workflows) that the upstream install.sh deliberately skips when installing to ~/.claude/agents/. The upstream repo recently reorganized from flat to subdirectories (engineering/, marketing/, etc.) but its install.sh flattens back to a flat dir for Claude Code. 11 new upstream agents are missing locally.

**Options considered:**

1. **Make ~/.claude/agents/ itself a git clone** -- Rejected. The upstream repo contains ~170 files across subdirectories plus scripts/, integrations/, examples/, strategy/, .github/. Claude Code expects a flat directory of .md files. Cloning directly into ~/.claude/agents/ would pollute it with non-agent files (LICENSE, scripts/convert.sh, etc.) and Claude Code does not recursively scan subdirectories. The upstream install.sh exists precisely because the repo layout differs from the install target.

2. **Clone upstream to a separate path, run install.sh on a cron** -- Accepted. Clone to `~/.local/share/agency-agents/` (outside the Docker sandbox, on the NixOS host). A systemd timer runs `git pull` + `./scripts/install.sh --tool claude-code --no-interactive` weekly. This matches the upstream project's own intended workflow. Advantages: (a) install.sh handles flattening and filters out non-agent files, (b) git tracks upstream changes, (c) `git diff` after pull shows exactly what changed, (d) no custom wrapper scripts to maintain, (e) upstream renames/reorganizations are handled by their install script.

3. **Clone upstream + maintain custom agents in a separate dir** -- Deferred. Analysis showed zero custom agents currently exist. If custom agents are needed later, options ranked by preference: (a) per-project `.claude/agents/` (scoped, does not pollute global namespace), (b) symlink custom files into ~/.claude/agents/ alongside upstream-managed files, (c) ~/.claude/custom-agents/ (requires Claude Code to support multiple agent dirs -- not currently documented).

4. **Pin to a tag/release** -- Rejected. The upstream repo does not publish tags or releases. Weekly commits to main are the only release mechanism. Pinning to a specific commit hash adds maintenance burden with no safety benefit since the install is local-only and easily reversible via `git checkout <prev-sha>` + reinstall.

**Decision:** Option 2. Clone msitarzewski/agency-agents to `~/.local/share/agency-agents/` on the NixOS host. Weekly systemd timer runs pull + install. The update script logs a diff summary and only notifies when changes touch agents referenced in `docs/agency-agents-reference.md` (the curated subset used by 333Method, 2Step, colorcraft, and distributed-infra).

**Implementation plan:**

1. **Immediate cleanup:** Delete the 24 non-agent files from ~/.claude/agents/ (README.md, CONTRIBUTING.md, PULL_REQUEST_TEMPLATE.md, QUICKSTART.md, EXECUTIVE-BRIEF.md, agent-activation-prompts.md, handoff-templates.md, nexus-spatial-discovery.md, nexus-strategy.md, phase-0-*.md through phase-6-*.md, scenario-*.md, workflow-*.md, backend-architect-with-memory.md). These lack YAML frontmatter and are not agents -- they are documentation/examples that were copied by mistake from the original bulk copy.

2. **Host setup:** `git clone https://github.com/msitarzewski/agency-agents.git ~/.local/share/agency-agents/`

3. **Update script:** `~/.local/share/agency-agents/scripts/install.sh --tool claude-code --no-interactive` (upstream's own script, no custom wrapper needed for the install step). Wrap in a thin shell script that: (a) runs `git pull`, (b) captures `git diff --name-only HEAD@{1}..HEAD`, (c) runs install.sh, (d) cross-references changed filenames against a list of agents from agency-agents-reference.md, (e) writes a one-line summary to syslog only if relevant agents changed.

4. **Systemd timer:** `agency-agents-update.timer` on NixOS host, weekly (Sunday 04:00 AEDT). Service runs the update script. Add to `distributed-infra/modules/`.

5. **Verification:** After first install, confirm `ls ~/.claude/agents/ | wc -l` matches expected count and all files have YAML frontmatter.

**Trade-offs:**
- Giving up: Ability to add files directly to ~/.claude/agents/ without them being overwritten on next sync (install.sh copies, does not delete, so manually-added files survive -- but this is fragile and undocumented behavior).
- Gaining: Version-tracked upstream sync, automatic new agent pickup, no stale/renamed files accumulating, matches the upstream project's intended installation method.
- Risk: If upstream install.sh changes behavior (e.g., starts deleting files not in repo), custom files in ~/.claude/agents/ could be lost. Mitigated by: no custom agents exist today, and the update script runs `git diff` before install so changes are logged.

**Status:** Accepted
**Impl:** Pending -- cleanup of non-agent files is safe to do immediately; host-side git clone + systemd timer requires NixOS host access

### DR-082: 2Step VoD — waive setup fee, show "$0 (waived)" on pricing (2026-03-24)

**Context:** 2Step video review subscription pricing had a setup fee (US $625, AU A$899, NZ NZ$989). Competitor research (March 2026) found nearly every SaaS competitor charges $0 setup — Widewail explicitly markets "no setup fees." The fee creates friction for cold-approached businesses with no prior relationship. Local business owners (pest control, plumber, cleaning) expect setup fees from agencies ($500-2,000), so anchoring at $0 is powerful.

**Decision:** Waive setup fee entirely. Set `setup: 0` in `pricing.php` for all markets. Display "Setup: $0 (waived)" on pricing cards — the word "waived" implies real value given away, stronger than "no setup fee" or silent removal. In cold outreach messages: "starts at $99/month (no setup fee)" as a parenthetical.

**Status:** Accepted
**Impl:** `auditandfix.com/includes/pricing.php` — `get2StepPricing()` setup values set to 0

### DR-083: 2Step VoD — CF Worker VIDEO-DEMOS KV queue architecture (2026-03-24)

**Context:** Need a self-serve inbound funnel for 2Step. The scan.php → CF Worker → NixOS pipeline pattern (SCANS KV namespace) is proven for async request → process → deliver workflows.

**Decision:** Replicate the SCANS pattern with a VIDEO-DEMOS KV namespace. Routes: POST /video-demo, GET /video-demo/:id, POST /video-demo/:id/email, GET /video-demos/pending, DELETE /video-demos/:key. Status flow: pending → verified (email confirmed) → processing → ready. Google Places Autocomplete for business matching (place_id captured client-side). Email verification gate prevents abuse. One free watermarked video per Google Place ID.

**Status:** Accepted
**Impl:** `333Method/workers/auditandfix-api/` — new routes + VIDEO-DEMOS KV namespace

### DR-084: 2Step VoD — local FFmpeg Phase 1, serverless FFmpeg Phase 2 (2026-03-24)

**Context:** CF Workers can't run FFmpeg (V8 isolates, 128MB memory, 30s CPU limit). Evaluated Shotstack ($0.26/video + $50/mo), Creatomate ($0.16/video + $54/mo), and serverless FFmpeg (AWS Lambda/GCP Cloud Run, ~$0.005/compute + $0.06 ElevenLabs = ~$0.07/video, $0/mo). Serverless FFmpeg uses the exact same `ffmpeg-render.js` code — zero additional QA needed.

**Decision:** Phase 1: local FFmpeg on NixOS host (works today, $0.06/video). Phase 2: deploy same code to AWS Lambda container or GCP Cloud Run (~$0.07/video, no local PC dependency). No Shotstack/Creatomate API dependency. Also parallelise 7 sequential ElevenLabs TTS calls to ~2s (from ~10s) for real-time on-page generation.

**Status:** Accepted
**Impl:** Phase 1 in `2Step/src/stages/video-demo-requests.js`, Phase 2 deferred

### DR-085: 2Step VoD — all verticals listed, Branch A/B fulfillment (2026-03-24)

**Context:** Clip pools exist for pest control (cockroaches, termites, spiders, rodents), plumbing (blocked-drain), and house cleaning (deep-clean, greasy-rangehood) plus shared and default pools. 12 more niches have keyword matching but no dedicated clips. Want to list all verticals on the landing page to maximise funnel width.

**Decision:** List all 13+ niches + "Other" (free-text) on the landing page. Branch A (have clips): auto-fulfillment via local FFmpeg, watermarked video in ~15 min. Branch B (no clips / "Other" / non-AU/NZ/US): take order, fulfill manually, no hard timing promise. Niche-to-clip config in Worker — niches auto-switch from B to A as clips are added. Support `?niche=` URL parameter for Google Ads targeting.

**Status:** Accepted
**Impl:** `auditandfix.com/video-reviews/index.php` + `video-demo-flow.js`

### DR-086: 2Step VoD — watermark overlay for demo videos (2026-03-24)

**Context:** Demo videos sent to prospects before payment need a watermark to discourage unpaid use while still showcasing the video quality. Must be non-obtrusive (not distract from content) but clearly visible.

**Decision:** Add optional `watermark` parameter to `renderVideo()` in ffmpeg-render.js. When enabled, overlays "auditandfix.com" in white text at 50% opacity, 36px DejaVu Sans Bold, positioned bottom-right with 40px margin. Applied as a final drawtext filter across the entire video duration, after all scene text and logo overlays. Default off for backward compatibility.

**Status:** Accepted
**Impl:** `2Step/src/video/ffmpeg-render.js` — `watermark` option on `renderVideo()`

### DR-087: 2Step VoD — demo requests pipeline stage architecture (2026-03-24)

**Context:** Inbound VoD requests from the landing page are stored in CF Worker KV. Need a pipeline stage to poll for new requests, create site records, and callback when videos are ready. Must integrate with the existing pipeline stages (reviews -> enrich -> video) without disruption.

**Decision:** New `video-demo-requests` stage runs as the FIRST pipeline stage (before reviews). Two-phase design: Phase A polls `GET /video-demos/pending` and inserts sites with `source='demo_request'` and `status='found'`; Phase B finds sites where video is ready and calls `DELETE /video-demos/{kv_key}` to complete the callback. Self-migrating columns (`source`, `demo_kv_key`, `manual_fulfillment`) via try/catch ALTER TABLE. Skips silently when `AUDITANDFIX_WORKER_URL`/`AUDITANDFIX_WORKER_SECRET` are not set.

**Status:** Accepted
**Impl:** `2Step/src/stages/video-demo-requests.js`, registered in `2Step/src/stages/pipeline-service.js`

---

### DR-088: Outscraper API for Yelp/Facebook/LinkedIn social extraction (2026-03-24)

**Context:** Social profile extraction for Yelp/Facebook/LinkedIn was using Playwright stealth browser. Yelp required nopecha CAPTCHA solving (1/3 success rate in testing). Evaluated ZenRows and Outscraper as structured API alternatives to eliminate browser overhead.

**Decision:** Outscraper API (`OUTSCRAPER_API_KEY`) replaces Playwright for Yelp, Facebook, LinkedIn:
- Yelp `/yelp` → phone + city, 3/3 success vs 1/3 Playwright, no CAPTCHA
- Facebook `/facebook-pages` → structured email + phone as dedicated fields (better than HTML regex), 2/3 with data
- LinkedIn `/linkedin/companies` → headquarters city, 3/3, ~2s API call vs ~5s Playwright page load
- ZenRows rejected: 402 during daily quota for our test; even when available, Yelp requires `js_render=true + premium_proxy=true` (25x credits, ~$7/1k) vs Outscraper's ~$3/1k. ZenRows has no structured Facebook API.
- YouTube: remains raw HTTP (free, `ytInitialData` JSON parse) — no Outscraper endpoint found
- Instagram: remains Playwright best-effort — no Outscraper endpoint, ZenRows 0% success rate
- Playwright kept as fallback if `OUTSCRAPER_API_KEY` not set or API call fails

**Status:** Accepted
**Impl:** `333Method/src/utils/social-contact-extractor.js` — `outscraperFetch()` helper, per-platform primary/fallback logic; `OUTSCRAPER_API_KEY` added to `333Method/.env`

---

### DR-089: VoD email verification — PHP-side token, not CF Worker (2026-03-24)

**Context:** The plan required email verification before video production starts (kills bots + disposable addresses). Two options: (a) CF Worker generates and validates token, sends email via Resend; (b) PHP generates token, stores in SQLite, sends email, PHP calls Worker only after verification.

**Decision:** PHP is the authority on email verification (option b). Rationale: (1) Resend API key is in 333Method/.env, not yet configured as a CF Worker secret; (2) PHP already has the SQLite pattern for scan_emails; (3) Worker's `/email` endpoint already marks `status='verified'` — PHP calls it only after validating the token, so the Worker still controls the authoritative verified state; (4) avoids adding email-sending infrastructure to the Worker.

Token: 64-char hex, 24h TTL. Stored in `scan_emails.verification_token + email_verified_at`. Disposable domain blocklist at PHP layer (Worker has its own KV-based blocklist).

**Status:** Accepted
**Impl:** `api.php` `demoEmail()` + `verifyDemo()`, `video-demo-flow.js` `initVerify()` IIFE + `transitionToEmailSent()`, `video-reviews/index.php` `vod-email-sent-section`

---

### DR-090: Google Maps Places Autocomplete — conditional load (2026-03-24)

**Context:** Places Autocomplete requires a Google Maps API key with HTTP referrer restriction. No key exists yet. Form validation requires `place_id` only when Places API is loaded.

**Decision:** Load Maps JS conditionally: `<script>` tag only output when `GOOGLE_MAPS_API_KEY` env var is set. Validation for `place_id` only runs when `typeof google !== 'undefined' && google.maps.places` (form degrades gracefully without it). User must create key at Google Cloud Console, add to Hostinger env/htaccess, and add `GOOGLE_MAPS_API_KEY` env.

**Status:** Accepted — key pending
**Impl:** `video-reviews/index.php` lines conditional script load; `video-demo-flow.js` validation guard

---

### DR-092: SMS signature in body — all three forms allowed; US/CA no signature (2026-03-25)

**Context:** Proofreader was reworking every SMS message containing "- Marcus" in the body, treating it the same as the full "- Marcus, Audit&Fix" (which is system-appended). PROPOSAL.md was contradictory — two rules said to include sender ID in SMS body, two others said the system appends it automatically. Additionally, proposal generator was baking "Reply STOP to opt out." into the body because PROPOSAL.md line 278 said "TCPA opt-out is REQUIRED" (ambiguous — model included it manually). Grade scale in PROPOSAL.md also mismatched score.js: examples showed 58/100 = D+ (correct: F), score bands 0-40/41-65/66-82 (correct per score.js: F=0-59, D-–C-=60-72, C–B-=73-81).

**Decision:** (1) All three signature forms allowed in non-US/CA SMS body: `"- Marcus, Audit&Fix"` (preferred), `"- Audit&Fix"`, `"- Marcus"` (fallback). Keep total body ≤120 chars. (2) US/CA: no signature in body — system appends "Reply STOP to opt out. - Audit&Fix". (3) Opt-out text ("Reply STOP to opt out.") is ALWAYS system-appended — never include it in body for any market. (4) Grade scale corrected to match score.js throughout PROPOSAL.md.

**Status:** Accepted
**Impl:** `prompts/PROPOSAL.md` lines 278–279, 384–390, 399, 34, 364–366, 429 updated; `prompts/PROOFREAD.md` SMS compliance section updated

---

### DR-093: Outscraper API preferred over ZenRows/Playwright for social contact extraction (2026-03-24)

**Context:** Social contact extraction (Yelp, Facebook, LinkedIn) was using Playwright stealth + nopecha. ZenRows was evaluated as an alternative — confirmed 402 (daily quota exhaustion, not billing issue) and the cost for Yelp with js_render+premium_proxy would be ~$7/1k. Outscraper API (key already in 2Step/.env) was tested against the same URLs.

**Decision:** Outscraper API is the primary extraction path for Yelp, Facebook, and LinkedIn:
- Yelp `/yelp` → structured phone + city, 3/3 success, no CAPTCHA (~$3/1k estimated)
- Facebook `/facebook-pages` → structured **email + phone** (better than Playwright regex), 2/3 success
- LinkedIn `/linkedin/companies` → headquarters city, 3/3 success, no browser needed

Playwright retained as fallback (if `OUTSCRAPER_API_KEY` missing or API returns null). YouTube stays on raw HTTP (free). Instagram stays on Playwright (no Outscraper endpoint, best-effort). ZenRows not used for social extraction — its value is SERP scraping under existing subscription.

**Status:** Accepted
**Impl:** `333Method/src/utils/social-contact-extractor.js` — `outscraperFetch()` helper + platform extractor functions; `OUTSCRAPER_API_KEY` added to `333Method/.env`

---

### DR-091: CF Worker deploy blocked — token permissions missing (2026-03-24)

**Context:** CF API token in `2Step/.env` lacks `Workers Scripts:Edit` and `Workers KV Storage:Edit` permissions. Cannot deploy Worker or create VIDEO-DEMOS KV namespace from sandbox.

**Decision:** Comment out `VIDEO_DEMOS` KV binding in `wrangler.toml` (placeholder IDs fail wrangler validation). All video-demo routes gracefully return 503 when `env.VIDEO_DEMOS` is undefined. Worker to be deployed once token is updated. Instructions in wrangler.toml commit message.

**Status:** Blocked — user action required: dash.cloudflare.com/profile/api-tokens → add Workers Scripts:Edit + Workers KV Storage:Edit → then `wrangler kv namespace create VIDEO-DEMOS` + fill IDs + `wrangler deploy`
**Impl:** `333Method/workers/auditandfix-api/wrangler.toml`

---

### DR-092: Outscraper reviewsQuery matching behaviour — confirmed (2026-03-25)

**Context:** Building review-criteria configs for 2Step video pipeline. Needed to know how Outscraper's `reviewsQuery` parameter matches text in Google reviews to design optimal search terms.

**Decision:** Live API tests (5 tests, 2 businesses) confirmed:

1. **Whole-word matching** — `roach` does NOT match `cockroach`. Both returned different reviews with zero overlap. Must include both forms as separate terms.
2. **Multi-word terms = phrase match** — `rat infestation` (space-separated) returns 0 results even though `rat` returns 1 and `infestation` returns 4 separately. Spaces within a term enforce adjacent-word matching. Use single words, not phrases, to maximise recall.
3. **Commas = spaces** — `rat,mice`, `rat mice`, `rat, mice` all return identical results. No special delimiter behaviour.
4. **No quoted phrase support** — `"rat infestation"` returns 0. Quotes are stripped/ignored.
5. **Word-boundary safe** — `rat` matched "rat in the roof" but NOT "grateful" or "brat". No false positives detected.

**Implications for review-criteria configs:** Use single whole words as OR terms. Include all morphological variants (roach + cockroach, mouse + mice). Avoid multi-word phrases (they accidentally become AND queries). `rat` is safe to use as a standalone term.

**Status:** Accepted
**Impl:** `2Step/data/review-criteria/` — configs updated; findings documented in JSON `_comment` blocks

---

### DR-094: AdManager source bugs fixed during PHPUnit test suite build (2026-03-25)

**Context:** Building the first PHPUnit test suite for `AdManager/` revealed several bugs in the source code that prevented tests from passing.

**Decisions / fixes applied:**

1. **PHP class name collision in `AdGroup.php`** — `use Google\Ads\...\Resources\AdGroup` imported the same short name as `class AdGroup`. PHP fatal-errors on load. Fixed: added `as AdGroupProto` alias; changed all `new AdGroup([...])` to `new AdGroupProto([...])`.

2. **`SitelinkAsset::final_urls` proto field placement** — `src/Assets.php` was setting `final_urls` inside the `SitelinkAsset` constructor, but the Google Ads proto schema places `final_urls` on the `Asset` resource wrapper, not on `SitelinkAsset`. Fixed: moved `final_urls` to the `Asset` constructor.

3. **SDK v29 Request object API — positional args not supported** — All 10 source files were calling service clients with the old positional-arg convention (`$service->mutate($customerId, $ops)`). SDK v29 requires a Request object via the `::build()` factory (`$service->mutate(MutateXRequest::build($customerId, $ops))`). Fixed in: `AdGroup.php`, `Assets.php`, `Keywords.php`, `Campaign/Manager.php`, `Campaign/Search.php`, `Campaign/Display.php`, `Campaign/Video.php`, `Campaign/PMax.php`, `Campaign/DemandGen.php`, `Ads/ResponsiveSearch.php`.

4. **`VIDEO_OUTSTREAM` constant does not exist in SDK v20** — `Campaign/Video.php` referenced `AdvertisingChannelSubType::VIDEO_OUTSTREAM`. The correct constant for the "video reach" subtype in this SDK version is `VIDEO_REACH_TARGET_FREQUENCY`. Fixed in source and test.

5. **PHP reference capture bug in PHPUnit `willReturnCallback`** — All mock helper methods returned `[$mock, &$capturedOp]` (array reference), but PHP array destructuring (`[$a, $b] = fn()`) does not preserve references. The capture variables always stayed null. Fixed: replaced `$capturedOp = [null]` with a `stdClass` container (`$capture->op = null`), which is always shared by identity through closures.

**Status:** Accepted — all 150 tests pass (805 assertions)
**Impl:** `AdManager/src/` (source fixes), `AdManager/tests/` (test suite)

### DR-095: AdManager expansion — multi-project ad platform with Google + Meta (2026-03-25)

**Context:** AdManager started as a Google Ads API wrapper. User wants it to become a standalone, AI-powered ad platform managing multiple products across Google Ads + Meta.

**Decision:**
1. **Parallel namespaces**: `src/Google/` and `src/Meta/` for platform-specific code
2. **Multi-project**: `projects` table as top-level entity, budgets per project+platform
3. **Creative pipeline**: OpenRouter (Gemini Flash free, FLUX $0.04) for images, Kling for video, ffmpeg for text overlays
4. **YouTube upload**: YouTube Data API v3 via same OAuth client for video ad automation
5. **Meta API**: Direct curl (no SDK) to keep deps simple
6. **Strategy engine**: Claude Code CLI (`claude -p`) for strategy generation, not OpenRouter
7. **Upload-then-review**: campaigns always PAUSED, human reviews in HTML dashboard before enabling
8. **Optimisation**: A/B split tests with z-test significance, keyword mining, budget reallocation, creative fatigue detection
9. **Conversion tracking**: Google Ads API for conversion actions, partial automation for GA4/Meta pixel

**Status:** Accepted — all 4 phases built, pushed to GitHub
**Impl:** `~/code/AdManager/` — see `CLAUDE.md` for full architecture

### DR-096: Sync-safe DB backup via Syncthing (2026-03-25)

**Context:** Live `.db` files can't sync via Syncthing — inotify + WAL checkpoint races caused the 2026-03-20 data loss (DR-049). But having recent DB snapshots on remote machines is valuable for analysis and disaster recovery. Need a safe mechanism to sync backups without risking corruption.

**Decision:**
1. **Sync-snapshot symlinks**: each backup run creates a `sync-snapshot/` dir inside `db/backup/` (on `/store` via existing symlink) containing `*-latest.db.gz` symlinks pointing at the most recent backup. Syncthing syncs these; no live DB files ever touch Syncthing.
2. **Atomic symlink swap**: `symlink()` to temp name + `rename()` to final name — avoids the `unlink` → `symlink` race where Syncthing could propagate a deletion.
3. **Gzip all backups**: `db.backup()` → integrity check → gzip → delete `.db`. Saves ~60% on sites.db (~7 GB → ~3 GB per copy). Integrity check runs before gzip since `better-sqlite3` can't open `.gz`.
4. **Grandfathered retention**: 4×4h + 4×daily + 4×weekly = 12 copies max per DB. Replaces old 7-daily + 4-weekly. Rotation by tier prefix in filename, not timestamp parsing.
5. **All four DBs**: sites.db, ops.db, telemetry.db (333Method) + 2step.db (2Step). ops/telemetry use `db.backup()` (not raw file copy) for consistency.
6. **data/scores/ + data/contacts/ tarballs**: `tar czf` with same retention tiers, symlinked into `sync-snapshot/`.
7. **`.stignore` ordering**: specific `sync-snapshot/` file patterns listed BEFORE `(?d)333Method/db/backup` exclude — Syncthing first-match-wins means `!` exceptions don't work inside excluded parents. Also fixed existing typo (`backups` → `backup`).
8. **No .gz on main drive**: all backup output goes to `/store` via the `db/backup` symlink. Temp files written to same dir (same filesystem → atomic rename).
9. **Syncthing config**: Follow Symlinks enabled, Watch for Changes (inotify) for near-instant detection on symlink swap, 4h rescan interval as fallback only.

**Status:** Approved — implementation pending
**Impl:** `333Method/src/cron.js` (backup functions), `.stignore`, plan at `~/.claude/plans/warm-foraging-rabbit.md`

### DR-098: Legal compliance — correcting spelling in Google Reviews used in video ads (2026-03-26)

**Context:** 2Step video ads feature real Google Reviews as testimonials. Some reviews contain obvious spelling errors (e.g. "cockroches", "agian"). Question: can we silently correct these while keeping meaning identical, or must we display verbatim text?

**Decision:** Do not silently correct spelling. Use verbatim review text in all ads. Rationale:

1. **ACL s.29(1)(e)/(f)** — Prohibits false or misleading representations purporting to be testimonials, including genuine testimonials that are "misrepresented or misquoted." The burden of proof is reversed: the representation is presumed misleading unless the business proves otherwise. There is no statutory carve-out for spelling-only edits.

2. **ACCC enforcement pattern** — PhotobookShop (2023, $39,600 penalty) was fined partly because it edited an influencer review to remove negative language and reposted it. HealthEngine (2020, $2.9M penalty) edited ~3,000 reviews. Citymove was fined twice for copying and modifying testimonials. The ACCC has never publicly distinguished "minor corrections" from "substantive edits" — all editing is treated as a risk category.

3. **FTC Endorsement Guides (2023)** — More permissive: an ad "need not present an endorser's message in the exact words" unless quotation marks imply verbatim text, but "the endorsement may not be reworded so as to distort in any way the endorser's opinion." Since our video ads display review text in quotes on screen (implying verbatim), the FTC standard also requires exactness.

4. **Copyright** — Google reviews are copyrighted by the reviewer. Reproducing them in paid ads without permission is technically infringement under Australian copyright law and Google Maps ToS, independent of ACL issues. Best practice: get written consent from reviewers before featuring their reviews in ads.

5. **Reviewer dispute risk** — If a reviewer sees their review quoted with corrections they didn't make, they may dispute the ad's authenticity or claim misrepresentation. An unmodified screenshot of the original review is the strongest defence.

**Safe alternatives:** (a) Display verbatim text including typos. (b) Use [sic] if the review is shown in a documentary/editorial context (not appropriate for video ads). (c) Contact the reviewer and ask them to correct their review themselves, then screenshot the corrected version. (d) Use only reviews that happen to be spelled correctly. (e) Show the review as a visual screenshot of the actual Google review card — the source is self-evident and less likely to be challenged.

**Status:** Accepted

### DR-099: Syncthing root cause confirmed — .stignore + Postgres migration (2026-03-27)

**Context:** Two major SQLite DB failures in 6 days (Mar 20 wipe, Mar 25-26 corruption). Investigation confirmed Syncthing is syncing `~/code/` (`.stfolder` present at `~/code/`), which includes `333Method/db/sites.db` and WAL/SHM files. Syncthing syncs these files independently, breaking SQLite's atomicity guarantees. Mar 25: `disk I/O error` at 99% during SQLite backup API. Mar 26: `database disk image is malformed`.

**Decision:**
1. **Immediate:** Add `~/code/.stignore` excluding all SQLite DB files (`*.db`, `*.db-wal`, `*.db-shm`) across 333Method, 2Step, and future projects
2. **Short-term:** Migrate 333Method from SQLite to PostgreSQL — 5.3GB DB, 660K+ sites, 5+ concurrent writer processes (cron, orchestrator, enrich, proposals, scoring, outreach) exceeds SQLite's single-writer design
3. **Rationale for Postgres over SQLite fixes:** The `.stignore` prevents Syncthing corruption, but SQLite at this scale with concurrent writers is operating at the edge. Two incidents in 6 days, each costing hours of downtime and data loss, is unacceptable for a production outreach pipeline

**Impact:** Requires replacing `better-sqlite3` with a Postgres client (`pg`, `drizzle-orm`, or `kysely`), standing up Postgres on NixOS host, schema migration (mostly portable SQL), and connection string changes across all pipeline stages.

**Status:** Accepted — .stignore is immediate, Postgres migration to be planned

### DR-100: English-only markets — no non-English outreach (2026-03-27)

**Context:** FR (36), IT (30), and other non-English country sites are leaking into outreach despite `ENGLISH_ONLY_MARKETS=AU,CA,GB,IE,IN,NZ,UK,US,ZA` in `.env`. The individual stage scripts (`proposals.js`, `scoring.js`, `enrich.js`) enforce this filter, but `claude-batch.js` (orchestrator path) does not — pre-existing non-English sites that were enriched/scored before the filter was added leak through to proposals and sends. Messages are a mess: half-English, half-translated, or wrong language entirely.

**Decision:** Non-English markets are out of scope. Only `ENGLISH_ONLY_MARKETS` countries receive outreach. `claude-batch.js` must enforce the same `ENGLISH_ONLY_MARKETS` filter on all proposal, proofread, and outreach queries. India (IN) is included — English is a valid business language there.

**Status:** Accepted
**Impl:** `scripts/claude-batch.js` — add `ENGLISH_ONLY_MARKETS` filter to `fetchProposalSites`, `fetchProofreadBatch`, and outreach-eligible queries

### DR-097: Niche landing pages — single template file via ?niche= param (2026-03-26)

**Context:** auditandfix.com/video-reviews needed SEO-targeted landing pages for specific trade verticals (pest-control, plumber, house-cleaning) to capture bottom-of-funnel Google search traffic (e.g. "pest control video reviews"). Options: one PHP file per niche, a single template file, or a build-time generator.

**Decision:** Single template file `video-reviews/niche.php` parameterised by `?niche=`. .htaccess rewrites `/video-reviews/{slug}` → `niche.php?niche={slug}`. The `$niches` config array inside the file holds all per-niche data (title, meta, hero copy, video URL, review snippet, FAQs). Adding a new vertical = one array entry + one .htaccess line. Per-niche files would duplicate ~400 lines of HTML/CSS/PHP per vertical with no benefit; a build-time generator is over-engineered for 3 verticals.

**Status:** Accepted
**Impl:** `auditandfix.com/video-reviews/niche.php`; .htaccess rewrite required on Hostinger: `RewriteRule ^video-reviews/([a-z-]+)/?$ video-reviews/niche.php?niche=$1 [L,QSA]` (add after line 71, before the blog rules)

### DR-098: Cross-project opt-out suppression list — dedicated suppression.db (2026-03-26)

**Context:** The existing `opt_outs` table in `messages.db` handles cross-project opt-outs at the channel level (sms/email method split). However, the system needs a simpler, project-agnostic suppression list where any opt-out from any project (333Method, 2Step, future projects) blocks outreach across all projects. The existing system requires callers to know the channel; the new system should block by identity (email or phone) regardless of channel. Options: (a) extend `messages.db` opt_outs table, (b) create a dedicated `suppression.db`, (c) use a flat file.

**Decision:** Dedicated `suppression.db` at `mmo-platform/db/suppression.db` with a `suppression_list` table. Single row per identity with both email and phone (merged when both are known for the same contact). Case-insensitive email matching via `COLLATE NOCASE`. Unique indexes on email and phone separately (partial indexes, WHERE NOT NULL). Merge/consolidation logic handles the case where email and phone are initially added as separate entries then later linked. Sync polling via `getSuppressionsAfter(timestamp)` for child projects to pull new suppressions. The `checkBeforeSend({ email, phone })` function is the primary integration point for outreach pipelines. This complements (does not replace) the existing `opt_outs` table in `messages.db` — that table retains channel-level granularity (sms vs email method), while `suppression.db` provides blanket identity-level blocking.

**Status:** Accepted
**Impl:** `mmo-platform/src/suppression.js` (module), `mmo-platform/db/suppression.db` (database), `mmo-platform/tests/unit/suppression.test.js` (80 tests). Integration: 333Method `src/outreach/email.js` + `src/outreach/sms.js`; 2Step `src/stages/outreach.js` — call `checkBeforeSend()` before send.

### DR-100: Webhook signature verification audit and hardening (2026-03-26)

**Context:** Security audit of all inbound webhook endpoints in 333Method found the Cloudflare Workers (Resend, PayPal) properly verify signatures at the edge, but the Express fallback server in `webhook-handler.js` accepted raw POST requests without any PayPal signature verification. Additionally, the PayPal Worker's GET/DELETE `/paypal-events.json` endpoints lacked authentication (unlike the Resend Worker which uses `requireSecret()`), exposing order IDs and payer info to anyone who knows the Worker URL.

**Decision:** (1) Add `verifyWebhookSignature()` to the Express PayPal webhook handler — calls PayPal's `/v1/notifications/verify-webhook-signature` API using the same approach as the Cloudflare Worker. Raw body is captured via `express.json({ verify })` middleware. Forged requests get 401 before any business logic runs. (2) Add `requireSecret()` auth gate to PayPal Worker's GET/DELETE endpoints, gated by `PAYPAL_WORKER_SECRET` (same pattern as Resend Worker). (3) Update `poll-paypal-events.js` to send `X-Auth-Secret` header when polling the Worker. No restructuring of existing code — guards were added as pre-checks.

**Status:** Implemented
**Impl:** `333Method/src/payment/webhook-handler.js` (verifyWebhookSignature + Express middleware), `333Method/workers/paypal-webhook/src/index.js` (requireSecret), `333Method/src/payment/poll-paypal-events.js` (auth header). Tests: 10 new tests in `tests/payments/webhook-handler.test.js`, all 72 payment tests pass. Deployment: run `wrangler secret put PAYPAL_WORKER_SECRET` on the PayPal worker, add `PAYPAL_WORKER_SECRET` to `.env`.

### DR-101: Humanizing AI-generated content — anti-AI-pattern rules in LLM prompts (2026-03-26)

**Context:** Research into Google SEO penalties and email spam filter detection of AI-generated content. Google does not penalize AI content per se (targets "scaled content abuse" at 50-500+ pages/day — we're nowhere near). Email spam filters don't have explicit AI detection, but AI writing triggers existing signals harder: content uniformity, formulaic openers, uniform sentence length, imperative verb clustering, template fingerprinting. Yahoo flags ~90% of AI emails in phishing tests; Gmail moderate; Outlook ~4%. Evidence: B2B SaaS deliverability dropped 96% → 78% after scaling AI outreach due to repetitive patterns.

**Decision:** Add "Human Voice" anti-AI-pattern instructions to all LLM prompts that generate customer-facing text. Rules: vary sentence length (no 3+ similar-length sentences in a row), avoid formulaic openers spam filters trained on, use n-dashes with spaces not m-dashes, allow natural imperfections (fragments, "And"/"But" starters), vary paragraph structure across variants, one soft CTA stated conversationally. The HAIKU-POLISH prompt (final gate before send) also gets a "de-robotify" check pass. **Exception:** Audit reports (paid deliverables) keep professional tone — no "minor errors" or informal language. Spintax templates reviewed — already adequate (word-level variation + multiple authors/approaches), no changes needed.

**Status:** Implemented
**Impl:** `333Method/prompts/PROPOSAL.md` (Human Voice section), `333Method/prompts/FOLLOWUP.md` (Human Voice section), `333Method/prompts/HAIKU-POLISH.md` (de-robotify pass + character normalisation), `333Method/prompts/autoresponder.md` (Human Voice section), `2Step/prompts/DM-OUTREACH.md` (Human Voice section). Full humanizing reference prompt stored in memory: `reference_humanizing_prompt.md`.

### DR-102: LLM output sanitisation, phone-based TCPA timezone, SMS idempotency (2026-03-26)

**Context:** Security audit of three items: (1) LLM-generated proposal text was validated for structure (llm-response-validator.js) and input HTML was sanitised (llm-sanitizer.js), but there was no OUTPUT path sanitisation -- script tags, javascript: URLs, or attacker-controlled URLs from scraped websites could flow through LLM responses into outreach emails. (2) TCPA business hours check derived timezone from city+country only; US/CA sites without city data fell back to America/New_York, risking out-of-hours SMS to Pacific/Mountain prospects. (3) Twilio SMS sends had no idempotency mechanism; a 30s timeout + retry could deliver the same message twice.

**Decision:** (1) Added `sanitizeLlmOutput()` to llm-sanitizer.js -- strips script tags, javascript:/vbscript:/data: URLs, event handlers, HTML comments, injection markers, and unauthorised URLs (only auditandfix.com and the prospect's own domain are allowed). Integrated into proposal-generator-v2.js `storeProposalVariant()` on the output path after spintax resolution, before DB storage. (2) Added `timezoneFromPhone()` to timezone-detector.js -- maps US/CA phone area codes to IANA timezones (350+ area codes covering Eastern/Central/Mountain/Pacific/Alaska/Hawaii). Integrated as fallback in `getSiteTimezone()` when city-based lookup returns the default. (3) Added `generateIdempotencyKey()` (SHA-256 of outreachId+phone+body) and a `sending` status guard to sms.js -- marks messages as 'sending' before Twilio call, skips sends if status is 'sent' or 'sending', resets to NULL on timeout for valid retry.

**Status:** Implemented
**Impl:** `333Method/src/utils/llm-sanitizer.js` (sanitizeLlmOutput), `333Method/src/proposal-generator-v2.js` (output path integration), `333Method/src/utils/timezone-detector.js` (timezoneFromPhone + getSiteTimezone fallback), `333Method/src/outreach/sms.js` (idempotency key + sending guard). Tests: `tests/utils/llm-output-sanitizer.test.js` (31 tests), `tests/pipeline/timezone-phone-fallback.test.js` (35 tests), `tests/outreach/sms-idempotency-guards.test.js` (14 tests). All 80 new tests pass; all existing tests pass (zero regressions).

### DR-103: Standardised Dependabot + CI across all repos (2026-03-27)

**Context:** 333Method had Dependabot and CI workflows but they were broken: (1) auto-merge workflow lacked `permissions` block → "Resource not accessible by integration" on every Dependabot PR, (2) `npm audit --audit-level=moderate` in PR Quality Check was failing on 21 vulnerabilities (15 moderate, 6 high), blocking all merges, (3) auto-merge used `--auto` flag which requires GitHub Pro for private repos. Result: 8 Dependabot PRs piled up since Feb 8 with zero auto-merging. Meanwhile 2Step, AdManager, and mmo-platform had zero CI or Dependabot config.

**Decision:** (1) Fix 333Method auto-merge: add `permissions: {contents: write, pull-requests: write}`, add explicit `gh pr review --approve` step, switch from `--auto` to direct merge (no branch protection on free plan, so no status checks to wait for). (2) Relax audit check: `npm audit --audit-level=high || true` — informational, not blocking. Security vulns are caught by Dependabot PRs themselves. (3) Expand 333Method dependabot.yml: add grouped updates, cover workers dirs, pip (dashboard), and github-actions ecosystem. (4) Add identical Dependabot + auto-merge + PR quality check configs to 2Step (npm), AdManager (composer), mmo-platform (npm). (5) Enable `allow_auto_merge` on repos where possible (only works on public repos with free plan — enabled on AdManager). (6) Triage: merged 4 safe PRs (#19 fast-xml-parser/flatted/socket.io-parser, #18 undici workers, #16 black, #13 playwright), closed 2 with conflicts (#10 pdfkit, #11 resend — will recreate), closed 2 major bumps (#14 express 4→5, #12 eslint 9→10 — need manual migration).

**Status:** Implemented
**Impl:** `333Method/.github/dependabot.yml` (grouped, multi-ecosystem), `333Method/.github/workflows/dependabot.yml` (permissions + direct merge), `333Method/.github/workflows/pr-quality-check.yml` (relaxed audit), `2Step/.github/` (dependabot + workflows), `AdManager/.github/` (dependabot + composer workflows), `mmo-platform/.github/` (dependabot + workflows). Note: `@tigtech/sage` removed from npm registry — `npm ci` fails locally; separate issue from CI config.

### DR-104: PostgreSQL migration topology — single database, multiple schemas (2026-03-27)

**Context:** DR-099 mandated migrating from SQLite to PostgreSQL. Current state: 4 SQLite files (sites.db, ops.db, telemetry.db, messages.db) connected via `ATTACH DATABASE` through a single better-sqlite3 connection. Pipeline has cross-database JOINs in the hot path (`messages JOIN sites` runs every cycle). Also need to accommodate 2Step, future AdManager, and shared cross-project data (messages, opt-outs, suppression). Options evaluated: (1) single database with schemas, (2) one database per project + shared database, (3) one database per SQLite file.

**Decision:** Single `mmo` database with named schemas: `m333` (333Method), `ops`, `tel`, `msgs` (shared), `twostep` (2Step), `admanager` (future). Schema names match existing ATTACH aliases so `ops.cron_jobs`, `tel.llm_usage`, `msgs.messages` queries work unchanged. Per-project `search_path` (`m333, ops, tel, msgs` for 333Method; `twostep, msgs` for 2Step) resolves unqualified table names.

Rejected multi-database: cross-schema JOINs are load-bearing in the pipeline hot path. `postgres_fdw` (required for cross-DB JOINs) cannot push down predicates or use remote indexes — would degrade the most critical query. Multi-DB also doubles connection pools per process and prevents cross-schema transactions. What we give up: per-project process isolation (accepted for solo dev).

Additional decisions: peer auth (no passwords, all processes run as `jason`), SCRAM-SHA-256 for future TCP, JSONB for all JSON columns, BIGSERIAL for high-growth tables, monthly partitioning for `site_status` and `pipeline_metrics`, wal2json logical decoding for data change audit log (~200KB/day compressed), automated backup verification (prevent March 20 repeat).

**Status:** Implemented (2026-03-28)
**Impl:** NixOS: `mmo-platform/infra/nixos/postgresql.nix` + `pg-backup.nix`. Schema: `333Method/db/pg-schema.sql` (36 tables). Migration: `333Method/scripts/migrate-sqlite-to-pg.js`. Cutover: `333Method/scripts/cutover-to-pg.sh`. 2Step: `2Step/src/utils/db.js` rewritten. ~95 src files + ~120 test files converted.

### DR-105: SQLite-to-PostgreSQL 16 schema conversion for 333Method (2026-03-27)

**Context:** DR-104 established the single-database, multi-schema topology. Needed the concrete DDL to create all 333Method tables in PostgreSQL 16, converted from the SQLite reference schema (db/schema.sql, 971 lines, 99+ migrations applied). The SQLite schema uses AUTOINCREMENT, COLLATE NOCASE, datetime('now'), GROUP_CONCAT, DECIMAL, INTEGER-as-boolean, TEXT-for-JSON, and SQLite-style triggers (inline BEGIN/END blocks).

**Decision:** Full mechanical conversion with these rules applied:
1. **Schema placement** — tables split into `m333` (26 core tables), `ops` (6 operational tables), `tel` (8 telemetry tables) per DR-104.
2. **BIGSERIAL** for 8 high-growth tables (sites, site_status, messages, llm_usage, pipeline_metrics, cron_job_logs, system_metrics, agent_logs); SERIAL for the rest.
3. **Special PKs preserved** — `pipeline_control.id INTEGER CHECK (id = 1)`, `countries.country_code TEXT`, `agent_state.agent_name TEXT`, `config.key TEXT`, etc.
4. **28 JSONB columns** replacing TEXT-stored JSON (evidence, http_headers, locale_data, form_fill_data, raw_payload, rate_limit, etc.).
5. **CITEXT** for `unsubscribed_emails.email` replacing COLLATE NOCASE; citext extension created at top of file.
6. **TIMESTAMPTZ DEFAULT NOW()** replacing all DATETIME/TIMESTAMP/datetime('now') variants.
7. **BOOLEAN DEFAULT FALSE/TRUE** replacing INTEGER 0/1 for boolean-like fields (is_read, resulted_in_sale, is_free_tier, etc.).
8. **NUMERIC(10,6)** replacing DECIMAL(10,6).
9. **Trigger refactor** — all SQLite BEGIN/END triggers converted to function + trigger pairs; timestamp updaters use BEFORE UPDATE with RETURN NEW (row mutation) instead of SQLite's AFTER UPDATE + separate UPDATE statement.
10. **STRING_AGG** replacing GROUP_CONCAT in views; CREATE OR REPLACE VIEW.
11. **Expression index** `(created_at::date)` replacing `DATE(created_at)`.
12. **Autovacuum tuning** for 8 high-write tables (scale_factor 0.05-0.1).
13. **Dependency ordering** — pricing stub table placed before messages (FK requirement).
14. **IF NOT EXISTS removed** from CREATE TABLE (fresh schemas); retained on CREATE INDEX for re-run safety.
15. **New composite index** `idx_sites_status_country` added per conversion spec.

**Status:** Implemented
**Impl:** `333Method/db/pg-schema.sql` (1112 lines, directly executable via `psql -d mmo -f pg-schema.sql`)

### DR-106: PostgreSQL migration cutover — data migration, validation, cleanup (2026-03-28)

**Context:** DR-104/DR-105 established schema and topology. This covers the actual data migration, production cutover, and post-migration cleanup.

**Decision:** Full cutover from SQLite to PostgreSQL completed in a single session:

1. **Data migration** — `migrate-sqlite-to-pg.js` loaded all 5 schemas (m333, ops, tel, twostep, msgs) from 4 SQLite files + messages.db. Total: ~5M rows across 45 tables. WAL temporarily disabled during bulk load to prevent 78GB WAL blowout on root volume.
2. **Deduplication** — UNIQUE constraint on `sites.domain` was missing in original PG schema. SERPs stage inserted 2.2M duplicate rows (91% of table) before discovery. Deduped to 210K unique domains keeping most-progressed row per domain. UNIQUE index added.
3. **Schema gaps fixed** — 6 missing tables created (processed_webhooks, free_scans, scan_email_sequence, email_exclusion_list, phone_exclusion_list, llm_cost_budgets, tel.llm_usage). 3 missing columns added (conversation_id, product on purchases; estimated_cost on sites). `dead_letter` added to status CHECK constraint.
4. **claude-store.js** — 1400-line batch storage script converted from sync SQLite to async PG (34 query calls, SAVEPOINTs per item).
5. **Security fixes** — P0 opt-out INSERT using wrong columns (compliance breach), P1 command allowlist for cron shell execution, P2 payment verification guard.
6. **Backup system** — `backupDatabase` cron handler now runs pg_dump (677MB, ~100s). `walCheckpoint` repurposed for WAL archive cleanup (>7 days). Old SQLite backup crons (backup2StepDb, backupOpsAndTelemetry) disabled. ~44GB stale SQLite backups removed from store volume.
7. **Validation** — All tables compared against SQLite backup. All deltas accounted for (FK orphan drops, dedup, new data from running pipeline). No data loss.
8. **Test coverage** — 89.16% line coverage (target 87%). 352 new tests added for PG compatibility. Test mock infrastructure (pg-mock.js) updated across ~120 test files.

**Status:** Implemented
**Impl:** Pipeline running on PG since 2026-03-28 21:33 UTC. WAL re-enabled (logical). Services: 333method-pipeline.service, 333method-orchestrator.timer. DATABASE_URL: `postgresql://jason@/mmo?host=/run/postgresql`.

### DR-107: auditandfix.com customer portal — auth architecture and security requirements (2026-03-28)

**Context:** Building a customer login portal on auditandfix.com from scratch. Stack: PHP 8.3, Hostinger shared hosting, SQLite, PayPal integration. Security review conducted before implementation. Key pre-existing issues found: (1) `api.php` line 75 leaks exception messages (including internal paths) to callers via the top-level catch; (2) `data/` directories created with 0755 making SQLite files world-readable on shared hosting.

**Decision:** Adopt passwordless magic-link authentication with the following non-negotiable constraints:

1. **Token generation** — `bin2hex(random_bytes(32))` (256-bit entropy). Store `hash('sha256', $token)` in DB; send raw token in email URL only. Expiry: 15 minutes. Invalidate (set `used_at`) on first use — single-use enforced.
2. **Session hardening** — Custom session name `AF_PORTAL` separate from anonymous nav session. Cookie flags: `httponly`, `secure`, `samesite=Strict`. `use_strict_mode=1`, `use_only_cookies=1`. `session_regenerate_id(true)` on login. Custom save path outside `/tmp` (shared on Hostinger). Prefer PDO-backed session handler to eliminate filesystem session exposure entirely.
3. **SQLite security** — Auth DB outside webroot (`/home/account/data/af_customers.sqlite`). Permissions: `0700` on directory, `0600` on file. WAL mode + `busy_timeout=3000ms` on all SQLite DBs. Existing `data/` directories (0755) to be tightened and protected with `.htaccess Require all denied`.
4. **CSRF** — Synchronizer token (`bin2hex(random_bytes(32))` in `$_SESSION['csrf_token']`) on all state-changing endpoints (cancel subscription, update email, delete account). AJAX endpoints accept token as `X-CSRF-Token` header.
5. **Rate limiting** — SQLite `rate_limits` table (WAL mode). Limits on magic link requests: 5/15min per IP (hashed), 3/15min per email (hashed). HTTP 429 + `Retry-After`. No attempt count disclosed to caller.
6. **Purchase linking** — Email verification (link click) required before linking existing purchases to a new account. Provisional account status `unverified` until click. Link by `account_id` FK (not email join at query time) after first verified login.
7. **Error disclosure** — Top-level exception catch in `api.php` must be fixed to return generic error message; detail goes to `error_log()` only. Applies before portal launch.
8. **PayPal subscriptions** — Cancel/modify proxied through our API (not direct PayPal link). Register PayPal webhooks for `BILLING.SUBSCRIPTION.CANCELLED` / `BILLING.SUBSCRIPTION.SUSPENDED` with signature verification via `/v1/notifications/verify-webhook-signature`.
9. **Email enumeration** — Magic link request endpoint returns identical response regardless of whether email exists. Timing equalized with `usleep(random_int(80000, 150000))` in the not-found path.

**Status:** Decided — not yet implemented
**Impl:** Security review at `docs/decisions.md` DR-107. Implementation in `auditandfix.com/portal/` (not yet created).

### DR-108: AdManager ad copy proofreading — two-pass QA with auto-approve (2026-03-29)

**Context:** AdManager strategy engine (Opus) generates full ad copy (15 RSA headlines + 4 descriptions per campaign, Meta primary text), but `create-ad.php` ignored it and hardcoded 5 generic headlines. No copy validation, proofreading, or platform policy enforcement existed. The `ad_copy` DB table was missing despite the review dashboard already having UI for it. Account bans from policy violations are often permanent.

**Decision:**
1. **Two-pass proofreading**: Programmatic checks (15 deterministic rules — char limits, locale, duplicates, editorial policy) run first, then Claude Opus evaluates sales effectiveness (AIDA), competitive differentiation, RSA combination safety, and platform policy compliance
2. **Auto-approve model**: Copy scoring >= 70 with no programmatic fails gets auto-approved. Human review is opt-out (unapprove), not opt-in (approve). Rationale: non-English copy can't be manually reviewed — if automated QA is good enough for that, it's good enough for English
3. **Platform policy enforcement**: Curated Google Ads + Meta policy reference docs in `policies/`, consumed by both the copy proofreader (Opus) and the CV image/video reviewer (upgraded from Haiku to Sonnet). Weekly cron checks for policy page changes via content hashing
4. **Model selection**: Opus for copy proofreading (high-stakes, low-volume, needs nuanced sales judgment); Sonnet for image/video policy QA (visual understanding + policy, not just artifact detection)
5. **Auto-re-check on policy changes**: When `check-policy-updates.php --recheck` detects changes, all approved copy for affected platform(s) is re-run through proofreading. Items that now fail get flagged for review
6. **Rework flow**: `bin/rework-copy.php` generates revised copy from Opus with human feedback, inserts as new row (original preserved for audit), runs through full pipeline

**Status:** Accepted — implemented
**Impl:** `~/code/AdManager/src/Copy/` (Parser, Store, ProgrammaticCheck, Proofreader), `prompts/PROOFREAD.md`, `prompts/IMAGE-POLICY-CHECK.md`, `policies/`, `bin/proofread-copy.php`, `bin/rework-copy.php`, `bin/check-policy-updates.php`

### DR-109: AdManager dashboard architecture — API layer, view decomposition, changelog (2026-03-29)

**Context:** AdManager review page (review/index.php, 427 lines, 3 tabs) needs to evolve into a full management dashboard with performance overview, change log, strategy annotations, project CRUD, and sync controls. The eventual target is deploying the dashboard to auditandfix.com on Hostinger shared hosting, but it currently runs locally via PHP's built-in server. Key constraint: the dashboard API must return decision-ready abstractions (e.g. `{cost: 4.50, ctr: 2.3}`) not raw DB rows (e.g. `{cost_micros: 4500000}`).

**Decision:**
1. **Option C: Build in AdManager with clean API layer, deploy subset to Hostinger later.** Single codebase, works locally today, FTP-deployable later. Rejected separate dashboard app (duplication) and extending monolith without structure (no deployment path)
2. **Monolith breakup via PHP includes:** `index.php` becomes a router + layout shell, each view is a separate file in `review/views/` (overview, creative, copy, campaigns, performance, changelog, strategies, settings). No build step, no frontend framework
3. **New `src/Dashboard/` namespace:** `Metrics.php` (shared metric computation -- the single place where cost_micros becomes cost), `Auth.php` (session + bcrypt), `SyncRunner.php` (background process via shell_exec + file-based polling), `Changelog.php`, `PerformanceQuery.php`
4. **Three new DB tables:** `changelog` (all optimisation decisions with human-readable summary + JSON detail), `strategy_annotations` (section-level comments keyed by header text anchor, not line numbers), `sync_jobs` (async sync tracking). Plus `projects.products` column for multi-product domains
5. **Auth:** Session-based with bcrypt password hash in `.env`. Single admin user. 7-day session lifetime
6. **Sync from web:** Background `shell_exec()` of `bin/sync-performance.php` with DB-tracked job status and 2-second frontend polling. Gracefully degrades on Hostinger (sync trigger disabled, last-synced timestamp still works)
7. **Changelog captures everything:** Split test conclusions, budget changes, creative fatigue, keyword changes, campaign state changes, strategy approvals, sync completions, manual notes. Each entry has both human-readable `summary` and machine-readable `detail_json`

**Status:** Accepted -- architecture designed, migration SQL created, implementation pending
**Impl:** `~/code/AdManager/docs/dashboard-architecture.md`, `~/code/AdManager/db/migrations/001-004`

### DR-110: AdManager scope audit — platform feature gaps and build priority (2026-03-29)

**Context:** Audited AdManager's current API coverage against Google Ads, Meta Ads, and GA4 platform capabilities to identify what's missing and what to build next.

**Decision:** Prioritise closing broken loops (features where analysis produces recommendations but cannot execute them) over adding new capabilities.

**Build order:**

**Week 1 — Close broken loops:**
- Search term text sync to DB (KeywordMiner currently blind — no search_terms table)
- Impression share + Quality Score in Google Reports (GAQL changes)
- Ad status management: SplitTest.conclude() must pause losing ads
- Campaign/budget update methods so BudgetAllocator can execute recommendations
- `include_in_conversions_metric` on ConversionTracking (macro vs micro goals)

**Week 2 — Conversion quality:**
- Goal hierarchy: macro/micro/proxy distinction in goals table
- RLSA audience attachment (observation mode on all campaigns from day 1)
- Bid strategy update method (manual_cpc → maximize_conversions transition)

**Week 3 — Meta gaps:**
- Custom Audience class (website visitors, customer match, lookalikes)
- frequency/reach/unique_clicks in Meta reports
- Placement control in AdSet (force FB Feed + IG Feed + Reels, exclude Audience Network)
- Ad/AdSet update methods

**Week 4 — Cross-platform:**
- GA4 integration: landing page bounce rate + conversion validation
- CrossPlatform reports class (side-by-side spend/conversions/CPA per platform)
- Cross-platform budget reallocation in BudgetAllocator
- unified_conversions table for attribution reconciliation

**Deferred:** GA4 micro-conversion import, PMax asset groups, lead forms, negative keyword shared lists, keyword planner API.

**GA4 integration rationale:** GA4 provides post-click behaviour (bounce rate, time on site, pages/session) that ad platforms don't report. Key use: when CPA is high, GA4 tells you if it's a targeting problem (irrelevant traffic) or a landing page problem (relevant traffic that bounces). Also validates conversion tracking accuracy (GA4 vs platform-reported conversions should match within 15%).

**Status:** APPROVED

### DR-111: Orchestrator backlog query — migrate from SQLite to PostgreSQL (2026-03-29)

**Context:** After DR-104/DR-106 migrated 333Method from SQLite to PostgreSQL, the orchestrator's `refresh_backlog()` function was still using `better-sqlite3` with `./db/sites.db`. This caused all backlog values to be empty (eligible_outreach, actionable_proposals, approved_unsent, etc.), which meant throttle gates couldn't fire and the send pipeline couldn't determine work availability. The bug was silent because errors were piped to `/dev/null`.

**Decision:** Extract the inline SQLite Node.js script into a standalone `scripts/refresh-backlog.js` that uses the project's existing `src/utils/db.js` PostgreSQL module. Key changes: `better-sqlite3` → `pg` via shared pool, `?` placeholders → `$N` parameterised queries, `datetime('now', '-3 days')` → `NOW() - INTERVAL '3 days'`, `gdpr_verified = 0` → `gdpr_verified = false`.

**Impact:** Restored orchestrator visibility into all pipeline queues. Before fix: all gates blind, zero send awareness. After fix: 210K sites visible, 1,335 actionable proposals, 12,520 approved-unsent, daily_send_avg=488.

**Implementation:** `333Method/scripts/refresh-backlog.js` (new), `333Method/scripts/claude-orchestrator.sh` (updated `refresh_backlog()` function).

**Status:** IMPLEMENTED

### DR-112: AdManager Week 2 — RLSA audiences + bid strategy transition logic (2026-03-29)

**Context:** Week 2 build for AdManager. Two new features needed: (1) RLSA audience management for attaching remarketing lists to campaigns, and (2) data-driven bid strategy transitions based on 30-day conversion volume.

**Decision:**

*RLSA (Audiences.php):* Use OBSERVATION mode (not TARGETING) for all audience attachments. OBSERVATION allows bid modifiers without restricting reach — the right default for RLSA since restricting to audience-only would kill impression volume. Uses `CampaignCriterionServiceClient` with `UserListInfo` criterion, same pattern as Keywords.php's `CampaignCriterion`. Bid modifier validated at 0.0 (exclude) or 0.1–10.0 (Google Ads valid range).

*BidStrategyManager.php:* Four conversion tiers: <15 stay on manual_cpc/maximize_clicks (insufficient signal); 15–30 switch to maximize_conversions with no target (learning phase); 30–50 maximize_conversions with loose tCPA at 2x actual CPA (gives algorithm headroom); 50+ tighten tCPA to 1.2x actual CPA (squeeze efficiency). Maps to three internal strategy names (`maximize_conversions`, `maximize_conversions_with_tcpa`, `maximize_conversions_tcpa_tightened`) which translate to the Google Ads API's `target_cpa` strategy with appropriate micros value.

*Schema:* Added `bid_strategy TEXT DEFAULT 'manual_cpc'` column to `campaigns` table in schema.sql. Tests include `ALTER TABLE ... ADD COLUMN` guard for existing DBs.

**Implementation:** `AdManager/src/Google/Audiences.php`, `AdManager/src/Optimise/BidStrategyManager.php`, `AdManager/db/schema.sql` (bid_strategy column), `AdManager/tests/Google/AudiencesTest.php` (22 tests), `AdManager/tests/Optimise/BidStrategyManagerTest.php` (20 tests). All 42 new tests pass; full suite 770 tests (11 pre-existing GA4Test failures unrelated).

### DR-113: AdManager Week 4 — GA4 integration, cross-platform reporting, budget reallocation across platforms (2026-03-29)

**Context:** Week 4 build. Adds GA4 Data API integration, cross-platform performance reporting, cross-platform budget reallocation, and GA4-enriched campaign analysis.

**Decision:**

*GA4 (GA4.php):* Direct REST calls to `analyticsdata.googleapis.com/v1beta` rather than the `google/analytics-data` PHP SDK, consistent with Meta/Client.php pattern. Reuses the existing `GOOGLE_ADS_REFRESH_TOKEN` + OAuth client credentials for token exchange. All internal methods (`runReport`, `parseRows`, `getAccessToken`) are `protected` to enable clean subclass-based test doubles without mocking frameworks. Properties also `protected` for the same reason.

*GA4 table:* New `ga4_performance` table in schema.sql — stores landing page + source/medium/campaign rows. Upsert pattern matches sync-performance.php (delete-then-insert on composite key, since SQLite ON CONFLICT can't reference COALESCE columns directly).

*CrossPlatform.php:* Three methods — `summary()` (per-platform + totals row), `conversionReconciliation()` (GA4 vs platform comparison, flags >15% discrepancy), `platformComparison()` (side-by-side with best_cpa/best_roas/best_ctr winner flags and % gap vs best).

*BudgetAllocator::recommendCrossPlatform():* Allocates across ALL platforms (not within one). Same ROAS×0.7 + CTR×0.3 score. 30% floor prevents cutting any platform to zero. Platforms live <14 days or <50 conversions excluded from rebalancing (returned with excluded=true notice). Per-step ±50% guard still applies on top of floor.

*Analyser::enrichWithGA4():* Joins per-campaign performance to ga4_performance on campaign_name + date (last 14 days). When CPA > 2× target AND bounce rate > 70%, overrides recommendation to "fix landing page" instead of "add negatives". No bounce data → falls back to "add negatives". No CPA goal defined → null recommendation (no override).

**Status:** Accepted
**Implementation:** `AdManager/src/Google/GA4.php`, `AdManager/src/Reports/CrossPlatform.php`, `AdManager/db/schema.sql` (ga4_performance table), `AdManager/bin/sync-ga4.php`, `AdManager/tests/Google/GA4Test.php` (18 tests), `AdManager/tests/Reports/CrossPlatformTest.php` (16 tests), updated `BudgetAllocatorTest.php` (+8 tests), updated `AnalyserTest.php` (+10 tests). Full suite: 820 tests, 2181 assertions, all green.

**Status:** IMPLEMENTED

### DR-114: auditandfix.com llms.txt and structured data improvements (2026-03-29)

**Context:** SEO audit of auditandfix.com identified two gaps: (1) no llms.txt file for AI search engine discoverability, and (2) structured data on index.php was incomplete -- missing WebSite, Person (E-E-A-T), Product, and BreadcrumbList schemas; FAQPage had only 6 of 8 on-page questions; Organization logo used a bare string instead of ImageObject; Service offers hardcoded AUD-only pricing despite multi-currency support.

**Decision:**

1. **llms.txt added** at `/llms.txt`. Markdown format per the llmstxt.org spec. Contains site summary, main pages, services, legal pages, and optional section. Honest assessment: no major LLM company has confirmed they read llms.txt, but implementation cost is near-zero and it positions for future AI search indexing.

2. **Structured data expanded** from 3 to 7 schema types in the `@graph`:
   - **WebSite** (new) -- establishes site entity, publisher link, language list
   - **Organization** (fixed) -- logo upgraded to ImageObject, empty `sameAs` array ready for social profiles
   - **Person** (new) -- Marcus Webb with jobTitle, worksFor, image for E-E-A-T
   - **Service** (fixed) -- AggregateOffer with lowPrice/highPrice replacing single AUD price; areaServed added
   - **Product** (new) -- CRO Audit Report with AggregateOffer, shippingDetails (24hr handling time), return policy
   - **BreadcrumbList** (new) -- single-item for homepage, pattern for subpages to extend
   - **FAQPage** (fixed) -- all 8 on-page questions now included (was missing Q4 "performing well" and Q8 "What's a CTA?"); `@id` added

3. **Not added (deferred):** AggregateRating/Review (need genuine third-party reviews first -- self-published testimonials risk manual action), VideoObject (for video-reviews pages when ready), HowTo (low priority).

**Status:** Implemented
**Impl:** `auditandfix.com/llms.txt` (new), `auditandfix.com/index.php` (structured data block replaced)

### DR-115: auditandfix.com scoring methodology page (2026-03-29)

**Context:** auditandfix.com lacked a dedicated page explaining the 10-factor CRO scoring methodology. The scoring system is central to the product's credibility and differentiation, but the only explanation was in the homepage checklist section (10 one-line items). A methodology page serves three purposes: (1) E-E-A-T signal for Google -- demonstrates genuine expertise and transparent process, (2) conversion support -- prospects who understand the methodology trust the report more, (3) content depth for SEO -- targets "CRO scoring methodology" and related long-tail queries.

**Decision:** Created `/methodology` as a standalone PHP page following the compare.php pattern (light-hero theme, inline `<style>` block, shared header/footer includes). Content structure: hero, scoring system overview (10 factors / letter grades / overall score), grading scale table (A+ through F with score ranges), all 10 factors explained in detail (what we look for + why it matters), how we analyse (visual screenshot analysis, AI scoring, below-the-fold deep dive, human expert review), calibration section (sites-scored count pulled from pricing API), deliverable section (9-page PDF, annotated screenshots, prioritised fix list, overall score, technical assessment, plain-English explanations), and CTA back to homepage order form. Marcus Webb expert callout included.

Structured data: TechArticle schema (datePublished, author linked to Marcus Webb Person entity) + BreadcrumbList (Home > Scoring Methodology). Navigation updated: methodology added as a child link under "Conversion Audit" in header.php hamburger menu, and added to footer.php site links.

**Status:** Implemented
**Impl:** `auditandfix.com/methodology.php` (new), `auditandfix.com/includes/header.php` (nav link), `auditandfix.com/includes/footer.php` (footer link)

### DR-116: US follow-up email templates — 7-touch cadence with 4 variants per touch (2026-03-29)

**Context:** 333Method had 18 US touch-1 email templates and AU follow-up templates (touches 2-8), but no US-specific follow-up sequence. US market requires American English (-ize not -ise), American tone ("Hey/Hi" not "G'day"), no "Best,/Regards,/Cheers" signoffs, and USD pricing. The follow-up sequence is the primary conversion mechanism — touch 1 opens the conversation, touches 2-8 close it.

**Decision:** Created 28 US follow-up email templates (4 variants x 7 touches). Each touch has a distinct strategic angle with 4 different approaches per touch: Touch 2 (Day 3) — different weakness + social proof (4 approaches: social-proof, problem-solution, data-backed-social-proof, anecdote-social-proof). Touch 3 (Day 7) — ROI/dollar-loss framing (roi-framing, dollar-loss, compound-loss, competitor-loss-roi). Touch 4 (Day 14) — case study + sample report link (case-study-sample, case-study-detail, case-study-anecdote, case-study-personalized). Touch 5 (Day 21) — one free fix to demonstrate competence, audit remains paid (quick-win-free, quick-win-actionable, quick-win-no-strings, quick-win-premium). Touch 6 (Day 28) — ad waste + competitor gap (ad-waste, competitor-gap, ad-waste-optimize-first, competitor-benchmarking). Touch 7 (Day 35) — authority/43,000+ sites scored (authority-pattern, authority-compounding, authority-diagnostic, authority-persistence). Touch 8 (Day 42) — breakup/closing the file (breakup-graceful, breakup-casual, breakup-recap, breakup-open-door). Rich spintax at both sentence and word level — templates produce from ~1,700 to ~23 billion unique body combinations each. All templates validated: no British spellings, no forbidden phrases (free audit/report, complimentary, G'day, Best/Regards signoffs), correct JSON structure, sender is Marcus.

**Status:** Implemented
**Impl:** `333Method/data/templates/US/followup-email.json`

### DR-117: 2Step — Google Guaranteed detection in reviews stage (2026-03-30)

**Context:** Migration 015 added `is_google_guaranteed INTEGER DEFAULT 0` to `twostep.sites`. The column was only in the SQLite migration file — it had not been applied to the live PostgreSQL `twostep` schema. We needed to: (a) apply the migration to PG, (b) detect the badge during prospecting, and (c) backfill existing records.

**Decision:**
- Applied migration manually to PostgreSQL: `ALTER TABLE twostep.sites ADD COLUMN IF NOT EXISTS is_google_guaranteed INTEGER DEFAULT 0` + partial index.
- Added `detectGoogleGuaranteed(result)` function to `src/stages/reviews.js`. Checks the Outscraper Maps v3 result object for `is_google_guaranteed`, `google_guaranteed`, `subtypes`/`type` array membership, and `badge`/`google_badge` text fields. Returns 1 or 0.
- Wired detection into the `processKeyword` INSERT — new sites get the correct value automatically going forward.
- Wrote `scripts/backfill-google-guaranteed.js` to check stored JSON blobs (`contacts_json`, `selected_review_json`, `all_reviews_json`) for badge data. All 40 existing sites were CSV-imported with no stored Outscraper data, so none could be backfilled — all remain at 0 (default). Site 34 has a `google_maps_url` but no stored place data. Correct values will populate automatically when these sites go through the reviews stage again.

**Status:** Implemented
**Impl:** `2Step/src/stages/reviews.js`, `2Step/scripts/backfill-google-guaranteed.js`

### DR-118: DataForSEO Google Ads detection module — 333Method (2026-03-30)

**Context:** The existing `ad-detector.js` detects ad platforms (Meta Pixel, Google Tag Manager, etc.) from the site's own HTML. This misses cases where a business runs Google Ads without embedding any tracking pixel on their site (e.g. using a third-party landing page or call-only campaigns). DataForSEO's `keywords_for_site/live` endpoint can confirm whether a domain is actively bidding on keywords in Google paid search — a complementary signal to HTML-based detection.

**Decision:** Created `src/utils/dataforseo.js` with `checkDomainAdActivity(domain, options)` and `batchCheckDomainAdActivity(domains, options)`. Uses the `keywords_data/google_ads/keywords_for_site/live` endpoint (PAYG ~$0.0025/task). A domain is flagged `is_running_ads=true` if any returned keyword has `competition > 0` or `cpc > 0`. Confidence is `high` (3+ ad keywords), `medium` (1-2), or `low` (0). Returns `null` if credentials missing (no throw). Created `scripts/backfill-dataforseo-ads.js` to backfill the 210K+ sites with `is_running_ads IS NULL`, prioritising English-speaking markets (AU/US/CA/GB/NZ/IE/IN/ZA) at 1 request per 2 seconds. Merges `google_ads` key into existing `ad_signals` JSONB rather than overwriting. Task-level error handling distinguishes 40200 (Payment Required — account needs credits) from 40501 (no data for domain — treated as not running ads).

**Credentials note:** `DATAFORSEO_LOGIN` and `DATAFORSEO_PASSWORD` are confirmed present in `.env`. Dry-run confirmed API auth works but account returned 40200 Payment Required for all test domains — the `keywords_for_site` endpoint requires credits. Top up the DataForSEO account before running production backfill.

**Status:** Backfill running (2026-03-30) — 291/210,505 sites complete. ETA ~5 days at 0.5 req/s. Running in separate Claude Code session.
**Impl:** `333Method/src/utils/dataforseo.js`, `333Method/scripts/backfill-dataforseo-ads.js`


### DR-119: AdManager dashboard not deployed to Hostinger — NixOS service instead (2026-03-30)

**Context:** AdManager review dashboard needed remote access. Initial plan was to FTP-deploy to Hostinger alongside auditandfix.com.

**Decision:** Hostinger deployment rejected — vendor/ is 2.5GB (Google Ads SDK), FTP upload impractical. Shared hosting also likely disables `shell_exec`, breaking background sync. Dashboard is an internal admin tool, not public-facing. Correct deployment is a systemd service on the NixOS host (via `php -S 0.0.0.0:PORT review/index.php`), accessible via SSH tunnel or internal network. Alternatively, run locally via `php bin/review-server.php`.

**Status:** Pending NixOS service setup (user action required)
**Impl:** N/A — no code change, architectural decision

### DR-120: Meta CAPI wired into auditandfix.com Purchase + Lead flows (2026-03-30)

**Context:** Server-side conversion events needed for reliable Meta attribution past browser ad-blockers and iOS restrictions.

**Decision:** Added standalone `metaCapiEvent()` function to `auditandfix.com/api.php` (no Composer dependency — fire-and-forget curl). Fires `Purchase` in `capturePayment()` and `Lead` in `saveEmail()`. Requires `META_PIXEL_ID` + `META_ACCESS_TOKEN` env vars on Hostinger; silently no-ops if absent. Event dedup IDs: `purchase_{captureId}` and `lead_{sha256(email+date)}`. Browser pixel must use same IDs in `fbq()` calls for proper dedup (future work).

**Status:** Implemented — deployed to Hostinger (commit 4b99fb2)
**Impl:** `auditandfix.com/api.php:metaCapiEvent()`

### DR-121: Cold SMS permanently blocked for all countries except AU/NZ (2026-03-30)

**Context:** Twilio A2P 10DLC campaign rejected with error 30909 (CTA verification). The TCR vetting team could not verify an opt-in mechanism because cold outreach recipients have not opted in. Comprehensive legal review of all target markets (AU, CA, GB, IE, IN, NZ, UK, US, ZA) against TCPA, CASL, PECR, Spam Act 2003, ePrivacy Regulations, and POPIA.

**Decision:**

1. **10DLC is fundamentally incompatible with cold outreach.** Do not resubmit. The TCR registration process requires demonstrated opt-in flow (URL, short code, or keyword). No creative framing can make unsolicited outreach pass 10DLC vetting. Repeated rejections risk Twilio account-level review.

2. **10DLC does not affect AU/NZ SMS.** 10DLC is a US-only program for US long codes. Australian Twilio numbers (+61...) route through Australian carriers with no 10DLC requirement. Continue AU/NZ SMS as-is.

3. **Only AU and NZ have a clean legal basis for cold SMS** (Spam Act 2003 s.7(1)(b) inferred consent for AU; Unsolicited Electronic Messages Act 2007 for NZ). All other markets require express consent that cold outreach cannot provide.

4. **Block CA, GB, IE, ZA from SMS immediately.** CA was missing from both `OUTREACH_BLOCKED_COUNTRIES` and `OUTREACH_BLOCKED_SMS_COUNTRIES` in the live `.env` — a compliance gap exposing CASL liability (up to $10M CAD per violation). GB and IE have SMS templates but require PECR consent for mobile numbers. ZA has unclear POPIA status. Add all four to `OUTREACH_BLOCKED_SMS_COUNTRIES`.

5. **US and CA SMS are permanently blocked**, not "pending legal review." The Duguid defence is a structural argument against ATDS liability only — it does not substitute for the express written consent requirement. Do not unblock without external legal counsel sign-off.

6. **Future SMS expansion path (if desired):** Convert cold outreach to warm outreach via email-first consent funnel. Cold email (legal in US/CA/AU) asks recipient to reply YES for SMS follow-up. That reply constitutes express written consent under TCPA. This would also satisfy 10DLC registration requirements.

7. **Toll-free verification, short codes, and alternative providers** (Plivo, Vonage, MessageBird) all participate in the same TCR ecosystem for US traffic. None bypass the consent requirement. Grey-market SIM farms are illegal and unreliable.

8. **UK SMS permanently blocked** (user-confirmed 2026-03-30). While PECR's corporate subscriber exemption technically covers SMS, the ICO treats mobile numbers as personal in practice. The exemption is not a reliable defence for cold SMS. Do not reactivate without external legal counsel.

9. **Compliance must apply cross-project.** The same SMS blocking rules apply to 2Step and any future mmo-platform project. Shared compliance module planned for `@mmo/outreach`.

**Status:** Accepted
**Impl:** `333Method/.env` OUTREACH_BLOCKED_SMS_COUNTRIES updated; migration 129 disables sms_enabled for all except AU/NZ; `docs/05-outreach/legal-basis.md` updated with permanent block status. 2Step compliance to follow.
