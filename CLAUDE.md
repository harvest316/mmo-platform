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

## Plan Implementation

When a plan is approved via ExitPlanMode, immediately decompose it into agent-delegated tasks. Do not pause for confirmation — the plan approval IS the confirmation. For each task:

1. Choose the appropriate `subagent_type` and model based on the task nature (see agent list above + full catalog)
2. Launch independent tasks in parallel (single message, multiple Agent tool calls)
3. For sequential dependencies, wait for the prior agent to complete before launching the next
4. After each agent completes, mark the corresponding todo item complete
5. After writing significant code (>50 lines), run a Code Reviewer agent before committing
6. Commit after each logical phase, not at the end

If no agent type fits the task, do it directly in the main context. The goal is focused context per task (token efficiency) and parallel execution where possible.

### Model routing by task type

| Task type | Model | Effort | Thinking |
|-----------|-------|--------|----------|
| Architecture / novel design decisions | opus | auto | on |
| Complex multi-system bug diagnosis | opus | high | on |
| Security review | opus | high | on |
| Feature implementation (>100 lines) | sonnet | high | on |
| Refactoring / mechanical changes | sonnet | medium | off |
| Writing tests | sonnet | medium | off |
| Simple extraction / data transforms | haiku | low | off |
| Documentation | haiku | low | off |

### Upfront disclosure (before writing any code)

Scan the plan for any steps that require user action outside the sandbox: credentials, host-side commands, external approvals. List these upfront as "⚠️ You'll need:" items with exact instructions/URLs so the user can handle them in parallel while implementation runs.

### Completion behavior (automatic — no user prompt needed)

When all phases of an approved plan are implemented:
1. Commit all work (one commit per logical phase, per existing guidance)
2. Attempt `rad push` — if it fails (e.g., conflicts, hooks), skip and note it as a manual step
3. Output a **session summary**: what changed, which files were modified, which DRs were added, noteworthy decisions
4. List **all remaining/pending tasks** — unfinished plan steps + any follow-up work surfaced during implementation
5. Format any manual steps (host-side, credentials, external actions) as "⚠️ Manual step required:" blocks

Do not stop mid-plan to ask for confirmation unless: (a) a required secret/credential is missing and wasn't surfaced upfront, (b) a design decision arises that wasn't resolved during planning, or (c) a destructive action is imminent (git force push, data deletion, etc.).

## Code Review Rules

When running a Code Reviewer agent (or reviewing code yourself before committing), enforce these rules in addition to standard review:

### LLM Usage Tracking (DR-172)

**Every LLM API call in every project MUST be tracked in `tel.llm_usage`.** No exceptions.

- **333Method**: Use `callLLM()` from `src/utils/llm-provider.js` with a `stage:` parameter. Never use direct `fetch()` or `axios` to an LLM API.
- **2Step**: Use `logLLMUsage()` from `src/utils/log-llm-usage.js` after any OpenRouter fetch call.
- **ContactReplyAI**: Use `logUsage()` from `src/services/llm.js` after any Anthropic SDK call.
- **AdManager**: Use `LLMTracker::log()` from `src/LLMTracker.php` after any curl or CLI LLM call.
- **AgentSystem**: Dispatcher auto-logs after `claude -p` calls; no manual tracking needed.
- **Orchestrator batches**: Tracked via `claude-store-wrapper.js` (provider='claude-cli').

**Flag as a blocking issue** if a PR introduces:
- A direct `fetch()`/`axios`/`curl` call to any LLM API (openrouter.ai, api.anthropic.com, api.openai.com, etc.)
- A `callLLM()` call without a `stage:` parameter
- A `new Anthropic()` / `new OpenAI()` SDK call without a corresponding usage log
- A `claude -p` CLI invocation outside the dispatcher/orchestrator without usage logging

## Website Deployment

The brand website and CF Worker have been moved to a **private repo**: `~/code/auditandfix-website/` (harvest316/auditandfix-website). See that repo's CLAUDE.md for deploy instructions.

## VSCode Tips

- Open workspace: `File → Open Workspace from File → ~/code/mmo.code-workspace`
- Each project folder appears in the sidebar — click to switch project context
- Claude Code memory path is based on the workspace root; re-read project CLAUDE.md files each session
