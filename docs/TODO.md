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

- [ ] Move website from 333Method to mmo-platform/website/
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
- [ ] **Consolidate web hosting DBs to Postgres on VPS** — Once VPS is reimaged/hardened (distributed-agent-system.md), migrate Hostinger SQLite DBs (customers.sqlite, subscriptions.sqlite, scan_emails.sqlite) into Postgres alongside existing m333/ops/tel/msgs/twostep schemas. New `portal` schema. Cleaner to have all DBs in one place with unified pg_dump + B2 offsite backup. AdManager SQLite also migrates at this point (`admanager` schema already reserved in pg-init-schemas.sql). Hostinger PHP would talk to Postgres via an API layer on the VPS rather than local SQLite.

## Website (production site)

### HIGH PRIORITY
- [ ] **Generate hero images** — AI-generate replacement hero backgrounds for scan, video-reviews, compare, blog pages. Prompts stored in the website repo's `assets/img/hero-prompts.md`. Current `hero-background.png` works but is generic. Target: cohesive, editorial-quality, <200KB each.

### Medium Priority
- [ ] RTL background image for Arabic layout (current hero/section backgrounds assume LTR — need mirrored or neutral versions for `?lang=ar`)
- [ ] Test full Arabic (RTL) layout — CSS may need `[dir="rtl"]` overrides for padding, margins, flex direction
- [x] Spintax structural variation — 6 new structures added to AU/US/GB (question-lead, finding-first, ultra-short, value-give, reverse-cta, industry-observation)
- [x] Full i18n — scan.php, video-reviews, compare pages translated across 20 languages (361 keys x 20 langs)

## 333Method — Ad Signal Quality

- [ ] **DataForSEO large-scale backfill** (210K sites, ~$525 at $0.0025/req) — `node scripts/backfill-dataforseo-ads.js --limit 10000`. Results go into `ad_signals->'dataforseo_keywords'` only (not is_running_ads — see DR-118). Run when budget allows and after confirming the keywords data is actually useful for outreach prioritisation.

## SEO & EEAT (Investigate)

- [ ] Ask SEO agent: is EEAT work for Marcus Webb actually worth it for our business model? We drive traffic via cold outreach, not organic search — does Google authoritativeness even matter?
  - LinkedIn profile for Marcus Webb — does it move the needle?
  - Google Business Profile, author schema, about page bio
  - How much of this painful setup actually impacts conversions vs. just SEO vanity?
  - If SEO matters at all, what's the minimum viable EEAT investment?

## Brand & IP (Low Priority)

- [ ] Commission professional AU trademark clearance search for "Audit&Fix" (~AU$300–600)
- [ ] File TM Headstart pre-examination with IP Australia (Classes 35 + 42, ~AU$400 govt fees)
- [ ] Collect and date-stamp use evidence (invoices, screenshots, traffic data) for acquired distinctiveness
- [ ] Evaluate logo mark filing (device mark more registrable than word mark alone)
- [ ] Once AU base TM registered: Madrid Protocol filing for UK/US/CA/NZ
