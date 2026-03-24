# Agency Agents Reference

Cross-project reference for specialized AI agent types, their configurations, and scheduling.
Updated: 2026-03-23

---

## Agent Catalog

### 1. Code Quality, Best Practices & Security

| Agent | Model | Effort | Thinking | Trigger | Use Case |
|-------|-------|--------|----------|---------|----------|
| **Code Reviewer** | Sonnet | medium | off | Scheduled — orchestrator batch, 1 file/cycle | Review modules for correctness, security, performance |
| **Security Engineer** | Opus | max | on | One-shot + quarterly + on major refactor | Threat model outreach pipeline, 2Step unsigned tokens |
| **Software Architect** | Opus | max | on | One-shot + quarterly + on major refactor | Review pipeline architecture, distributed-agent-system design |
| **Backend Architect** | Opus | high | on | On PostgreSQL migration + on new API endpoint | Design migration path, API layer, WebSocket server |
| **Database Optimizer** | Sonnet | high | off | On new DB migration + monthly schema review | Schema review, query optimization, PostgreSQL design |
| **DevOps Automator** | Sonnet | medium | off | One-shot (2Step CI/CD) + on new project | Build GitHub Actions, pre-commit hooks, pipelines |
| **Git Workflow Master** | Haiku | low | off | One-shot + on `@mmo/*` extraction | Standardize branching for multi-repo |
| **SRE** | Sonnet | medium | off | One-shot + monthly SLO review | Define SLOs, observability, Grafana Loki stack |
| **Compliance Auditor** | Opus | high | on | Quarterly + on major refactor + on new outreach channel | GDPR/TCPA/CAN-SPAM compliance audit |
| **Test Results Analyzer** | Haiku | low | off | Weekly — Sunday night | Coverage gaps, flaky tests, health trends |
| **Performance Benchmarker** | Sonnet | medium | off | Weekly + after concurrency config changes | Pipeline bottlenecks, Playwright benchmarks |
| **Threat Detection Engineer** | Opus | max | on | One-shot + on IronClaw deployment | SIEM detection rules for audit sidecar |
| **Incident Response Commander** | Opus | high | on | One-shot (create playbook) | Incident response playbook for distributed system |

### 2. Promotion & Marketing

*SEO, UX, Legal, Ad Creative, and Tracking agents already running on auditandfix.com.*

| Agent | Model | Effort | Thinking | Trigger | Use Case |
|-------|-------|--------|----------|---------|----------|
| **SEO Specialist** | Sonnet | medium | off | One-shot (colorcraft-ai) + monthly audit | Technical SEO, keywords, content optimization |
| **Content Creator** | Opus | high | off | Bi-weekly — content calendar cadence | Blog posts, case studies, landing page copy |
| **Social Media Strategist** | Sonnet | medium | off | One-shot (strategy) + monthly review | Cross-platform LinkedIn + Twitter strategy |
| **LinkedIn Content Creator** | Opus | high | off | Weekly — 2-3 posts/week cadence | Thought leadership on AI audits, video, art |
| **Growth Hacker** | Sonnet | high | off | One-shot + quarterly | Viral loops, lead magnets, growth experiments |
| **Ad Creative Strategist** | Opus | high | off | On campaign launch + on A/B test results | Ad copy for Google/Meta targeting SMBs |
| **PPC Campaign Strategist** | Sonnet | medium | off | On campaign launch + monthly optimization | Google Ads campaigns AU/UK/US |
| **Paid Social Strategist** | Sonnet | medium | off | On campaign launch + monthly | Meta/LinkedIn ads targeting SMB owners |
| **Reddit Community Builder** | Sonnet | medium | off | 2-3x/week — authentic engagement cadence | r/webdev, r/smallbusiness, r/SEO |
| **Instagram Curator** | Sonnet | medium | off | Weekly — visual content cadence | Before/after, video showcase, AI art |
| **Twitter Engager** | Haiku | low | off | Daily — real-time engagement | Web dev / AI community engagement |
| **Brand Guardian** | Sonnet | medium | off | Quarterly + on new property launch | Brand identity consistency |
| **Outbound Strategist** | Opus | high | on | One-shot (2Step sequences) + on new niche | Cold outreach sequences, ICP definition |
| **Content Creator (email)** | Opus | high | off | One-shot (templates) + on funnel change | Nurture sequences, reply funnel (5 stages) |

### 3. Quality, Throughput & Cost

| Agent | Model | Effort | Thinking | Trigger | Use Case |
|-------|-------|--------|----------|---------|----------|
| **Workflow Optimizer** | Sonnet | medium | off | Monthly + after config changes | Pipeline bottlenecks, throttle gate tuning |
| **Analytics Reporter** | Sonnet | medium | off | One-shot (dashboards) + weekly refresh | 8 dashboard pages, pipeline insights |
| **Data Engineer** | Sonnet | high | off | On PostgreSQL migration | ETL SQLite → PostgreSQL, streaming pipeline |
| **Rapid Prototyper** | Sonnet | medium | off | On `[poc]` tag in TODO.md or user request | PoC for MCP server, approval queue UI |
| **Autonomous Optimization Architect** | Haiku | low | off | Per orchestrator cycle (30min) | Shadow-test API calls, cost guardrails |
| **Automation Governance Architect** | Sonnet | medium | off | Quarterly + on major refactor | Audit cron jobs + orchestrator |
| **Infrastructure Maintainer** | Haiku | low | off | Daily + on process guardian alert | NixOS, Docker, systemd reliability |
| **Finance Tracker** | Haiku | low | off | Weekly + on cost spike | API costs across all services |

### 4. Distributed Agent System Plan

| Agent | Model | Effort | Thinking | Trigger | Plan Area |
|-------|-------|--------|----------|---------|-----------|
| **Software Architect** | Opus | max | on | One-shot + on major design change | Parts 1-4, L |
| **Agentic Identity & Trust Architect** | Opus | max | on | One-shot | Parts 2, 7, 14 |
| **Identity Graph Operator** | Sonnet | medium | off | On multi-agent deployment | Part 2, M |
| **MCP Builder** | Sonnet | high | off | On distributed plan Phase 1 start | Part 14 |
| **Security Engineer** | Opus | max | on | One-shot + on infra change | Parts 6-13, M |
| **Threat Detection Engineer** | Opus | max | on | One-shot + on IronClaw deploy | Parts 8-11 |
| **DevOps Automator** | Sonnet | medium | off | On VPS provisioning + Docker topology change | Parts 16-17, K |
| **SRE** | Sonnet | medium | off | One-shot + monthly SLO review | Parts 4, 8 |
| **Database Optimizer** | Sonnet | high | off | One-shot (schema design) | Part 3, L |
| **Backend Architect** | Opus | high | on | On distributed plan implementation | Parts 1-2, K |
| **Blockchain Security Auditor** | Sonnet | medium | off | One-shot | Parts 6-13 |
| **Legal Compliance Checker** | Opus | high | on | One-shot + on new jurisdiction | Part 15, 16 |
| **Model QA Specialist** | Sonnet | medium | off | One-shot + monthly | Part 11 |

---

## Model Selection Rules

| Model | When to Use |
|-------|------------|
| **Opus** | Judgment, creativity, security, architecture, legal/compliance |
| **Sonnet** | Structured analysis, code review, schema design, dashboards |
| **Haiku** | Classification, extraction, monitoring, cost tracking, pattern matching |

| Effort | When to Use |
|--------|------------|
| **low** | Simple, well-defined tasks. One right answer. |
| **medium** | Standard analysis. Some judgment needed. |
| **high** | Complex reasoning, creative generation, multi-step. |
| **max** | Extended thinking. Critical decisions, novel problems. Rare. |

**Rule of thumb:** Judgment/creativity → Opus+high+thinking. Analysis → Sonnet+medium. Patterns → Haiku+low.

---

## Orchestrator Batch Types

### Existing Pipeline Batches

| Batch Type | Model | Effort | Thinking |
|------------|-------|--------|----------|
| `proposals_email` | Opus | high | on |
| `proposals_sms` | Opus | medium | off |
| `reword_*` (all 5) | Opus | medium | off |
| `classify_replies` | Haiku | low | off |
| `extract_names` | Haiku | low | off |
| `reply_responses` | Opus | high | on |
| `proofread` | Opus | medium | off |
| `score_sites` | Sonnet | medium | off |
| `score_semantic` | Sonnet | medium | off |
| `enrich_sites` | Haiku | low | off |
| `oversee` | Sonnet | medium | off |
| `classify_errors` | Haiku | low | off |

### New Dev/Ops Batches

| Batch Type | Model | Effort | Thinking | Agency Agent Role |
|------------|-------|--------|----------|-------------------|
| `monitor_health` | Haiku | low | off | SRE |
| `triage_errors` | Haiku | medium | off | Incident Response Commander |
| `code_review` | Sonnet | medium | off | Code Reviewer |
| `check_docs` | Haiku | low | off | Technical Writer |
| `security_audit` | Opus | max | on | Security Engineer |
| `design_review` | Opus | max | on | Software Architect |

### Tier C (Interactive — Claude Code sessions, not pipe mode)

| Batch Type | Model | Effort | Thinking | Agency Agent Role |
|------------|-------|--------|----------|-------------------|
| `fix_bug` | Opus | high | on | Senior Developer |
| `fix_code` | Opus | high | on | Senior Developer |
| `verify_fix` | Sonnet | medium | off | Code Reviewer |

---

## Execution Tiers

| Tier | How | When |
|------|-----|------|
| **A: Orchestrator batch** | `claude -p` via orchestrator loop | Every 30-min cycle |
| **B: Orchestrator scheduled** | `claude -p`, time-gated via `is_due()` | Daily/weekly/monthly |
| **C: Interactive session** | Full Claude Code session | One-shot or user-triggered |

---

## Tier C Session Start Prompts

### SEO Specialist (Monthly)
```
Run an SEO Specialist audit on colorcraft-ai.com. Fetch the site, analyze technical SEO (meta tags, page speed indicators, structured data, sitemap, robots.txt), keyword opportunities, content gaps, and link building potential. Output a prioritized report of changes for Base44 to implement.
```

### Security Engineer (Quarterly + major refactor)
```
Run a Security Engineer audit on 333Method and 2Step. Review: (1) API key handling in load-env.js and .env files, (2) Twilio webhook authentication in src/inbound/sms.js, (3) PayPal integration in src/payment/paypal.js, (4) unsigned unsubscribe tokens in 2Step src/outreach/email.js, (5) stealth-browser SSRF risk, (6) SQL injection review of all DB queries. Output findings as JSON with severity/file/line/description.
```

### Compliance Auditor (Quarterly + major refactor)
```
Run a Compliance Auditor review on 333Method outreach system. Check: (1) CAN-SPAM compliance in email templates, (2) TCPA compliance in SMS sending (business hours, opt-out), (3) GDPR country blocking logic in src/config/countries.js, (4) unsubscribe honoring in src/outreach/, (5) 72-hour cooldown enforcement. Also audit 2Step CAN-SPAM compliance in src/outreach/email.js.
```

### Software Architect (Quarterly + major refactor)
```
Run a Software Architect review. Evaluate: (1) 333Method 9-stage pipeline architecture — are stages well-separated? coupling issues? (2) @mmo/* package extraction readiness — what can be safely extracted now? (3) distributed-agent-system plan in docs/plans/distributed-agent-system.md — are there simpler alternatives to the hybrid MCP+Redis approach? Output an architecture decision record (ADR) with recommendations.
```

### Automation Governance (Quarterly + major refactor)
```
Run an Automation Governance audit. Read the cron_jobs table (sqlite3 db/sites.db "SELECT * FROM cron_jobs"), review all 18 cron jobs in src/cron/ and the orchestrator in scripts/claude-orchestrator.sh. Identify: redundant jobs, poor sequencing, missing error handling, jobs that should be consolidated. Output a ranked list of changes.
```

### Brand Guardian (Quarterly)
```
Run a Brand Guardian review across auditandfix.com, 2Step, and colorcraft-ai.com. Fetch each site and evaluate: visual consistency, messaging tone, value proposition clarity, call-to-action alignment. Output a brand consistency scorecard with specific recommendations per property.
```

### colorcraft-ai.com Report (Monthly)
```
Generate a consolidated improvement report for colorcraft-ai.com (hosted on Base44). Run these analyses and combine into a single actionable report: (1) SEO audit, (2) UX review, (3) Legal compliance, (4) Ad creative recommendations, (5) Tracking setup. Format as step-by-step changes that a Base44 LLM can implement.
```

---

## Due Notification System

The orchestrator writes `logs/agents-due.json` when Tier C agents are overdue. Surfaced in:
- Orchestrator log: `[AGENT DUE] security_audit (14d overdue)`
- Daily progress report: "Agents Due" section with copy-paste prompts
- AFK progress updates

---

## "Major Refactor" Trigger

Any change set meeting **any** of these criteria:
- 10+ files changed in a commit series / PR
- Any change to `src/stages/` (pipeline core)
- Any change to `scripts/claude-orchestrator.sh`
- New database migration in `db/migrations/`
- New file in `src/outreach/`, `src/payment/`, `src/inbound/`
- Changes to `load-env.js`, `stealth-browser.js`, or `src/payment/`
- Package extraction (`@mmo/*` moves)
- New cron job in `src/cron/`

Detection: orchestrator runs `git diff --name-only HEAD~10` each cycle.

---

## Affected-Tests-Only

`scripts/run-affected-tests.sh` maps changed `src/X/Y.js` → `tests/X/Y*.test.js` and runs only those.

Usage:
```bash
./scripts/run-affected-tests.sh              # Uncommitted changes
./scripts/run-affected-tests.sh HEAD~3       # Last 3 commits
./scripts/run-affected-tests.sh src/utils/foo.js  # Specific file
```

Full `npm test` only on: pre-commit hooks, CI, periodic scheduled runs.

---

## Code Review Throttle Strategy

```
Code Review (Tier A)  →  agent_tasks  →  Fix (Tier C)
  1 file/call              queue          Claude Code session
```

- **Gate 1:** Pause reviews if pending fix tasks > 30
- **Gate 2:** Max 3-5 fixes per cycle
- **Conservation:** Pause reviews + low-priority fixes during conservation mode
- **Priority:** Security (9-10, never gated) > Bug (7-8) > Performance (5-6) > Style (3-4)

---

## Agents Not Applicable

Game engines, XR/VR, Solidity/Blockchain, China-market platforms, Embedded Firmware, visionOS/macOS Spatial, Blender.

---

## Upstream Sync

Agent definitions come from [msitarzewski/agency-agents](https://github.com/msitarzewski/agency-agents) (MIT, updated weekly).

- **Source clone:** `~/.local/share/agency-agents/` (git repo)
- **Install target:** `~/.claude/agents/` (global, flat .md files with YAML frontmatter)
- **Install method:** upstream `scripts/install.sh --tool claude-code --no-interactive`
- **Auto-update:** systemd user timer `agency-agents-update.timer` (daily 04:00 AEDT)
- **Manual update:** `~/code/mmo-platform/scripts/update-agency-agents.sh --verbose`
- **Relevance filter:** only logs when changes affect agents listed in this document
- **Update log:** `~/code/mmo-platform/logs/agency-agents-update.log` (only relevant changes)
- **Decision:** DR-081

---

## colorcraft-ai.com

Built on Base44. No codebase in workspace. Marketing/promotion agents only. Generate consolidated report for Base44 LLM to implement.
