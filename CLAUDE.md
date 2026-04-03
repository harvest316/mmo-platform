# CLAUDE.md — mmo-platform

Parent platform for all "make money online" child projects. This workspace (`~/code/mmo.code-workspace`) shows all projects simultaneously. You are likely working across multiple of them in a single session.

## Workspace Layout

```
~/code/
  mmo-platform/          ← you are here (shared @mmo/* packages, future)
  333Method/             ← SERP → score → proposal → outreach pipeline (ACTIVE)
  2Step/                 ← Video review outreach: prospect → video → DM/email (BUILDING)
  AdManager/             ← Multi-project ad platform (Google + Meta)
  AgentSystem/           ← Cross-project automated fix dispatcher (DR-163)
  distributed-infra/     ← NixOS infrastructure + infra plans (PRIVATE)
  auditandfix-website/   ← PHP website + CF Worker for the production site (PRIVATE)
  mmo.code-workspace     ← VSCode multi-root workspace file
```

Each child project has its own `CLAUDE.md` with full context. **Read the relevant project's CLAUDE.md first** before working in that project.

## Active Projects

### 333Method (`~/code/333Method/`)
Full SERP-to-outreach automation. Pipeline: Keywords → SERPs → Assets → Scoring → Enrich → Proposals → Outreach → Replies.
- DB: `db/sites.db`
- AFK orchestrator runs on NixOS host via systemd (not managed from VSCode)
- Agent system: disabled (`AGENT_SYSTEM_ENABLED=false`)
- See `333Method/CLAUDE.md` for full details (comprehensive — read it)

### 2Step (`~/code/2Step/`)
Video-based cold outreach. Finds businesses with strong Google reviews, creates free 30-45s AI video, sends as demo.
- DB: `db/2step.db` — 15 prospects loaded (pest control, Sydney, 2026-03-10)
- Google Sheet: `1iuWVqG_bCA1R1VWN8i0Bb2qwXY8bQuav695f2PrLV-g` (populated)
- Close: $625 setup + $99/month retainer
- Split test: Arm A (InVideo, manual) / Arm B (Holo, manual) / Arm C (Creatomate API)
- See `2Step/CLAUDE.md` for full details

## Shared Secrets (Temporary Bridge)

Until Phase 2 extraction, child projects load secrets from 333Method:

```
2Step/src/utils/load-env.js loads:
  1. ~/code/2Step/.env              (project-specific)
  2. ~/code/333Method/.env.secrets  (Twilio, Resend, etc.)
  3. ~/code/333Method/.env          (GOOGLE_SHEETS_*, ZENROWS_*, etc.)
```

Phase 2: centralise to `~/code/mmo-platform/.env.secrets`.

## mmo-platform Package Extraction (Phase 2 — not started)

Packages exist as stubs only. No actual code has been moved from 333Method yet.

| Package | What goes in it | Source in 333Method |
|---------|----------------|---------------------|
| `@mmo/core` | logger, error-handler, db, load-env, adaptive-concurrency | `src/utils/` |
| `@mmo/outreach` | email, sms, form, spintax, compliance, outreach-guard, sheets | `src/outreach/`, `src/utils/` |
| `@mmo/browser` | stealth-browser, html-contact-extractor, browser-notifications | `src/utils/` |
| `@mmo/monitor` | cron framework, process-guardian | `src/cron/` |
| `@mmo/orchestrator` | claude-batch, claude-store | `scripts/` |

**Do not start extraction without user direction** — it's a multi-hour refactor touching both projects.

## AFK Monitoring

The 333Method AFK orchestrator runs on the **NixOS host** via systemd timer — independent of which VSCode workspace is open. Claude Code sessions are separate:

- **333Method AFK session**: monitors `333Method/` pipeline, runs `monitoring-checks.sh` every 30 min
- **2Step session**: separate Claude Code session for 2Step work

When user goes AFK and asks for monitoring across both projects, a single Claude Code session can monitor both by checking both DBs and log dirs. No unified overseer exists yet (Phase 2).

## Autonomy Preferences

Same as 333Method: operate autonomously for local, reversible actions.
Always confirm before: git push, deleting files, external communications.

## Agency Agents — When to Use

When working on tasks, consider using these specialized agents via `subagent_type`:

- **Rapid Prototyper** (Sonnet, medium) — When asked to build a quick PoC or MVP
- **Code Reviewer** (Sonnet, medium) — After writing significant code (>50 lines), run on changed files before committing
- **Security Engineer** (Opus, max) — When touching payment, auth, webhook, or PII-handling code
- **Database Optimizer** (Sonnet, high) — When writing new migrations or complex queries
- **Test Results Analyzer** (Haiku, low) — After test failures, analyze patterns before attempting fixes
- **Performance Benchmarker** (Sonnet, medium) — When changing concurrency settings, batch sizes, or rate limits

Full catalog: `docs/agency-agents-reference.md`

## Website Deployment

The brand website and CF Worker have been moved to a **private repo**: `~/code/auditandfix-website/` (harvest316/auditandfix-website). See that repo's CLAUDE.md for deploy instructions.

## VSCode Tips

- Open workspace: `File → Open Workspace from File → ~/code/mmo.code-workspace`
- Each project folder appears in the sidebar — click to switch project context
- Claude Code memory path is based on the workspace root; re-read project CLAUDE.md files each session
