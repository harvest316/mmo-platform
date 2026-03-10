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

## Infrastructure

- [ ] Centralize .env.secrets in mmo-platform (all children load from here)
- [ ] Move distributed-agent-system.md to 333Method-infra/docs/plans/
- [ ] Update distributed-agent-system.md for Claude Max reality
- [ ] Create mmo.code-workspace with all projects
