# mmo-platform — TODO

## Package Extraction (from 333Method)

- [ ] @mmo/core — logger.js, error-handler.js, db.js, load-env.js, adaptive-concurrency.js (~750 lines)
- [ ] @mmo/core — circuit-breaker.js, rate-limiter.js
- [ ] @mmo/outreach — email.js, sms.js, form.js (~4,800 lines)
- [ ] @mmo/outreach — spintax.js, compliance.js, outreach-guard.js (~480 lines)
- [ ] @mmo/outreach — sheets-export.js (~300 lines, 333Method no longer needs this)
- [ ] @mmo/browser — stealth-browser.js, html-contact-extractor.js, browser-notifications.js (~1,200 lines)
- [ ] @mmo/monitor — cron framework, process-guardian.js (~1,000 lines)
- [ ] @mmo/orchestrator — claude-batch.js, claude-orchestrator.sh, claude-store.js (~1,600 lines)
- [ ] Update 333Method imports from relative paths to @mmo/* packages
- [ ] Update 2Step imports from 333method dep to @mmo/* packages
- [ ] Run full 333Method test suite after migration
- [ ] Run full 2Step test suite after migration

## Website Migration

- [ ] Move auditandfix.com/ from 333Method to mmo-platform/website/
- [ ] Move workers/ from 333Method to mmo-platform/website/workers/
- [ ] Update wrangler.toml paths
- [ ] Delete leftover cloudflare-worker/ directory from 333Method

## Services

- [ ] Unified AFK overseer (services/overseer/)
  - [ ] projects.json registry
  - [ ] Multi-project monitoring-checks.sh
  - [ ] overseer.js (cross-project insights)
- [ ] Unified dashboard (services/dashboard/) — later

## Dependencies

- [ ] Fix/replace `@tigtech/sage` in 333Method — unpublished from npm registry (2026-03-27). Dev dependency (AI code review). Options: find replacement, vendor a fork, or remove entirely if pre-commit hook covers it.

## Infrastructure

- [ ] Centralize .env.secrets in mmo-platform (all children load from here)
- [ ] Move distributed-agent-system.md to 333Method-infra/docs/plans/
- [ ] Update distributed-agent-system.md for Claude Max reality
- [ ] Create mmo.code-workspace with all projects

## Website — auditandfix.com

- [ ] RTL background image for Arabic layout (current hero/section backgrounds assume LTR — need mirrored or neutral versions for `?lang=ar`)
- [ ] Test full Arabic (RTL) layout — CSS may need `[dir="rtl"]` overrides for padding, margins, flex direction
- [x] Spintax structural variation — 6 new structures added to AU/US/GB (question-lead, finding-first, ultra-short, value-give, reverse-cta, industry-observation)

## Brand & IP (Low Priority)

- [ ] Commission professional AU trademark clearance search for "Audit&Fix" (~AU$300–600)
- [ ] File TM Headstart pre-examination with IP Australia (Classes 35 + 42, ~AU$400 govt fees)
- [ ] Collect and date-stamp use evidence (invoices, screenshots, traffic data) for acquired distinctiveness
- [ ] Evaluate logo mark filing (device mark more registrable than word mark alone)
- [ ] Once AU base TM registered: Madrid Protocol filing for UK/US/CA/NZ
