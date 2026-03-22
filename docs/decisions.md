# Decision Register

Architectural and technical decisions for the mmo-platform ecosystem (333Method, 2Step, distributed-infra).

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
