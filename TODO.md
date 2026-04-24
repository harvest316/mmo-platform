# TODO

## Cycle Neon + DASHBOARD_API_SECRET after CRAI cron smoke test (by 2026-04-28)

Credentials were embedded in the CRAI widget cron post-verify routine
(trig_01WykbS57aB7ar1pw59hhiXb) created 2026-04-24. After the routine fires
Monday 2026-04-27 02:15 UTC and you've confirmed it ran, rotate both:

1. **Neon password** — Neon Console → Project → Settings → Reset password.
   Update `NEON_DATABASE_URL` in `~/code/ContactReplyAI/.env` and in:
   - Wrangler secret: `cd ~/code/ContactReplyAI/workers && HOME=~/.cache/wrangler-home npx wrangler secret put DATABASE_URL --env production`
   - Any other scripts that read `NEON_DATABASE_URL` from .env

2. **DASHBOARD_API_SECRET** — generate a new 64-char hex secret:
   `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"`.
   Update in `~/code/ContactReplyAI/.env` AND:
   - Wrangler secret: `npx wrangler secret put DASHBOARD_API_SECRET --env production`
   - All active portal sessions will be invalidated (users re-login automatically).

3. **Disable the routine** after cleanup to prevent re-use of embedded creds:
   https://claude.ai/code/routines/trig_01WykbS57aB7ar1pw59hhiXb

---

## CRAI: Platform Lead Email Forwarding Ingest (DR-230)

Tradies get job leads from platforms (hipages, ServiceSeeking, Gumtree, Bark,
Oneflare) via email notifications. CRAI can intercept these by having the tradie
forward platform emails to a dedicated CRAI ingest address, parse the lead
details, and prepare a response for when the tradie accepts the lead and contacts
the customer.

**Research summary (2026-04-17):** No AU tradie platform has a public API. All
send lead notifications by email. Gumtree emails contain the full customer
enquiry. ServiceSeeking/Bark contain job details. Hipages/Airtasker emails
contain only a title + Accept button (less useful). See DR-230 and
`reference_tradie_platforms.md` for full analysis.

### Phase 1: Ingest infrastructure

The SES inbound pipeline already works for E2E tests (`e2e.auditandfix.com` →
S3). Extend it for CRAI lead ingest.

- [ ] Provision SES inbound for `leads.contactreplyai.com` subdomain
  - MX record → `inbound-smtp.ap-southeast-2.amazonaws.com`
  - SES receipt rule: `*@leads.contactreplyai.com` → S3 bucket + SNS/Lambda
  - Domain verification in ap-southeast-2 (per DR-229)
- [ ] Design ingest address scheme: `{tenant_id}@leads.contactreplyai.com`
  - Each CRAI tenant gets a unique forwarding address shown in their dashboard
  - Catch-all receipt rule routes to S3, Lambda extracts tenant_id from the `To:`
- [ ] Lambda or Worker to receive raw email from S3, extract:
  - Tenant ID (from the To: address)
  - Original sender platform (match From: domain — `@hipages.com.au`, etc.)
  - Lead content (subject + body)
  - Customer contact details (if present in body — Gumtree, ServiceSeeking)
- [ ] Store parsed lead in PostgreSQL (`crai.leads` table or similar):
  - `tenant_id`, `platform`, `raw_email_s3_key`, `parsed_subject`,
    `parsed_body`, `customer_name`, `customer_email`, `customer_phone`,
    `job_category`, `location`, `status` (new/reviewed/actioned), `created_at`

### Phase 2: Platform-specific parsers

Each platform's notification email has a different format. Build parsers for
the most useful ones first (ranked by how much actionable content is in the
email).

- [ ] **Gumtree parser** (highest value — full customer enquiry in email)
  - Extract: customer name, message text, listing title, reply-to email
  - CRAI can draft a full response immediately
- [ ] **ServiceSeeking parser** (good detail — job description + contact post-match)
  - Extract: job category, description, location, customer name
  - Pre-match: draft a summary + quote template for the tradie
  - Post-match: customer contact revealed → CRAI sends via Mode A/F
- [ ] **Bark.com parser** (decent detail — job requirements)
  - Extract: service category, job description, location, budget hints
  - Note: contact details only revealed after credit spend
- [ ] **Hipages parser** (limited — title + Accept button only)
  - Extract: job category, location, brief description
  - Can summarise + notify tradie, but cannot accept on their behalf
  - Post-accept: customer phone revealed → CRAI handles via Mode A/F
- [ ] **Oneflare parser** (similar to hipages — job description, pre-credit)
  - Extract: job category, description, location

### Phase 3: AI lead triage + response drafting

- [ ] Classify lead urgency (emergency plumber vs. quote for next month)
- [ ] Match against tenant's trade categories and service area
- [ ] Draft a first-response message using tenant's knowledge base + tone
  - For platforms where customer contact is already revealed (Gumtree,
    post-match ServiceSeeking): queue the draft for auto-send or approval
  - For platforms where contact is behind a paywall (hipages, Bark): show
    draft in dashboard, send after tradie manually accepts/unlocks
- [ ] Dashboard notification: "New lead from [platform] — [job summary]"
  - Push notification (future) or email digest

### Phase 4: Onboarding UX

- [ ] During CRAI tenant onboarding, ask "Where do you get job leads?"
  - Detect linked platform profiles from tradie's website URL (existing
    directory-recovery concept from `deployment-modes-and-friction.md`)
  - Show platform-specific forwarding setup instructions:
    - Gmail: Settings → Forwarding → add `{tenant_id}@leads.contactreplyai.com`
    - Outlook: Rules → Forward all from `@hipages.com.au` to the address
    - Generic: server-side forward rule in email host control panel
- [ ] "Test forwarding" button in dashboard — tradie sends a test email,
  CRAI confirms receipt and shows parsed result
- [ ] Platform-specific tip cards:
  - Gumtree: "Put your CRAI number and email in your listing"
  - Hipages: "Accept leads in the app, we'll handle the follow-up call"
  - ServiceSeeking: "Forward notification emails, we'll draft your quotes"

### Dependencies

- CRAI backend (pre-MVP — the reply service, tenant DB, dashboard)
- SES inbound receipt rules in ap-southeast-2 (scripts exist, extend them)
- `mmo-platform/src/email.js` wrapper for outbound replies (archive-compliant)
- Tenant knowledge base (for AI response drafting)

### Non-goals

- **No platform scraping or API hacking** — all integration is via email
  forwarding or downstream SMS/phone (Modes A-F). Zero ToS risk.
- **No in-platform automation** — CRAI never logs into hipages/Airtasker/etc.
- **No lead acceptance on behalf of tradie** — tradie always makes the
  accept/unlock/credit decision themselves.

---

## ~~E2E contact-form test page: replace Fastmail SMTP with SES~~ — DONE 2026-04-18

Rewrote `$SMTP_CONFIG` in `e2e-test-page-412549753.php` to read from env vars
(`SES_SMTP_HOST`, `SES_SMTP_PORT`, `SES_SMTP_USERNAME`, `SES_SMTP_PASSWORD`).
From address: `e2e-test-page@auditandfix.com`. Deployed to Hostinger.
Fastmail login was already disabled; old app password invalidated.
Filename unchanged so `TEST_E2E_URL` does not need updating.

---

## DMARC: Graduate marketing domains to p=reject (due 2026-05-11)

**Strategy:**
- Transactional domains (`auditandfix.app`, `contactreply.app`) — stay at `p=quarantine` permanently.
  Transactional mail (auth, billing, onboarding) must never hard-reject on misconfiguration.
- Marketing/outreach domains (`auditandfix.com`, `contactreplyai.com`) — move to `p=reject`
  once 4 weeks of clean RUA history confirmed.

**Before changing either domain, check Cloudflare DMARC Management:**
- CF Dashboard → auditandfix.com → Email → DMARC Management
- CF Dashboard → contactreplyai.com → Email → DMARC Management
- Confirm: only spoofing attempts in the failure reports, no legitimate sources failing

**To flip (edit DNS TXT record at `_dmarc.<domain>`):**
- Change `p=quarantine` → `p=reject` — nothing else changes
- auditandfix.com current: `v=DMARC1; p=quarantine; rua=mailto:...cloudflare...; ruf=mailto:dmarc@auditandfix.uriports.com; fo=1:d:s`
- contactreplyai.com current: `v=DMARC1; p=quarantine; rua=mailto:...cloudflare...; ruf=mailto:dmarc@auditandfix.uriports.com; fo=1:d:s`

- [ ] Check CF DMARC Management for auditandfix.com — confirm clean
- [ ] Flip auditandfix.com to `p=reject`
- [ ] Check CF DMARC Management for contactreplyai.com — confirm clean
- [ ] Flip contactreplyai.com to `p=reject`

---

## Upgrade URIports to Stone plan (USD $30/mo)

URIports Stone adds:
- **API access** — pull violation reports programmatically → auto-fix CSP/COOP/Permissions-Policy
  violations in .htaccess without manual review (build into AgentSystem or a 333Method-style cron)
- **Hosted MTA-STS** — they serve `mta-sts.<domain>/.well-known/mta-sts.txt` for you;
  no extra Worker to maintain. Cover all four domains: auditandfix.com, auditandfix.app,
  contactreplyai.com, contactreply.app
- TLS-RPT endpoint also included (complements MTA-STS)

Tasks:
- [ ] Upgrade account at uriports.com
- [ ] Enable hosted MTA-STS for all 4 domains via URIports dashboard
- [ ] Add `_mta-sts.<domain>` TXT records (URIports provides values) — all 4 at once
- [ ] Add `_smtp._tls.<domain>` TXT records for TLS-RPT — all 4 domains
- [ ] Wire URIports API into AgentSystem: poll weekly, parse new violations, auto-patch .htaccess
  and FTP-deploy (same pattern as existing fix dispatcher — see AgentSystem CLAUDE.md)

---

## Buy contactreply.ai domain when funds available

`contactreply.ai` is available for ~$80/yr. Bought as the proper brand for
ContactReplyAI long term — currently using `contactreplyai.com` (.com
fallback).

When purchased:
- Add to setup-ses.mjs DOMAINS list
- Verify SES identity, DKIM, MAIL FROM
- Set up MX → `inbound-smtp.ap-southeast-2.amazonaws.com`
- Migrate Worker custom domain `api.contactreply.ai` (no cross-account zone issue)

---

## Warm up parallel transactional domains

Bought `contactreply.app` and `auditandfix.app` as transactional / app
domains, isolated from cold-outreach reputation on the .com domains.

Tasks:
- [x] Add both to `mmo-platform/scripts/setup-ses.mjs` DOMAINS list
- [x] Run `setup-ses.mjs` — SES identities, DKIM CNAMEs, MAIL FROM, MX, SPF, DMARC for both `.app` domains (2026-04-09). DKIM propagation confirmed 2026-04-13.
- [x] Update `auditandfix-website/site/api.php` demo + onboarding emails to use `marcus@auditandfix.app`
- [x] Update CRAI dispatch to send AI replies from `marcus@contactreply.app` (workers/index.js + src/services/dispatch.js)
- [x] Update `auditandfix-website` magic link — `SENDER_EMAIL` in `.htaccess` switched to `marcus@auditandfix.app`, deployed via FTP
- [x] Update `contactreplyai.com` login button to forward to `contactreply.app/login` so transactional auth uses the .app domain
- Even 2-3 sends/day to varied recipients during the warmup period builds
  reputation. By the time CRAI launches the .app domains will be production-ready.

---

## Audit Cloudflare settings on all zones

Review CF settings for:
- `auditandfix.com` (Main account)
- `contactreplyai.com` (Dads Account — also pending zone transfer to Main)
- `auditandfix.app` (newly bought)
- `contactreply.app` (newly bought)

Specific things to check:
- **Bot Fight Mode** — currently enabled? Does it block legitimate scrapers?
  Does it interfere with Stripe/PayPal webhooks?
- **Super Bot Fight Mode** (paid)
- **Browser Integrity Check** — can falsely block API requests
- **Hotlink Protection** — relevant for image hosting
- **WAF custom rules** — any leftover from migration
- **Rate Limiting** — defaults vs custom rules
- **SSL/TLS mode** — Full (strict) on all
- **Always Use HTTPS**, **Automatic HTTPS Rewrites**
- **HSTS** — enabled with reasonable max-age
- **Email obfuscation** — relevant for landing pages with mailto:
- **Cache rules** — bypass for /api/*, /webhooks/*
- **Page rules** vs new Rules engine — migrate any leftover
- **Workers routes** — what's wired up where

Output: short doc per zone summarising current settings + recommended changes.

---

## Blog Post Visual Assets (images, diagrams, charts)

Add visual content to the 3 citation-gap blog posts to improve dwell time and
shareability. Posts currently have no images.

Candidates:
- `why-your-website-isnt-converting.php` — before/after headline examples as
  a simple comparison diagram; a score distribution chart (% of sites failing
  each factor) from the 35,000-site data
- `website-not-getting-enough-enquiries.php` — annotated screenshot of a
  homepage with the 6 problem areas called out
- `small-business-website-audit-checklist.php` — a visual checklist/scorecard
  graphic (pass/fail grid for all 10 factors)

Notes:
- Images should be stored in the website repo's `assets/img/blog/`
- Use `<figure>` + `<figcaption>` for semantic HTML
- Add `ImageObject` to Article schema in `blog/post.php` template once images exist
- Consider generating with an image AI tool (Flux, Midjourney, or Gemini via
  the OpenRouter image gen pattern documented in memory)

---

## Trustpilot Review Data in Schema

Once Trustpilot reviews start coming in for the production site, update the Product
structured data in `index.php` with real `aggregateRating` and
`review` values. Currently a placeholder (1 review, 5/5).

- Trustpilot profile: linked from the production site

- BCC invite email is active: `TRUSTPILOT_BCC_EMAIL` in `333Method/.env`
- Schema location: `index.php` → `@graph` → `Product` → `aggregateRating` + `review`
- Consider: pull rating/count from Trustpilot API automatically, or update manually
  after first 5–10 reviews

---

## Remove hardcoded `auditandfix.com` and `contactreplyai.com` references

DR-145/146/147/152/157/158 replaced most runtime source code references with
`BRAND_DOMAIN`/`BRAND_URL` env vars. What remains are comments, constants,
test fixtures, docs, and infrastructure scripts. This TODO tracks cleaning up
the stragglers.

### Scripts (infrastructure — real domain references)

These scripts configure real AWS/CF resources and legitimately reference the
domains. Decide per-script: parameterise with env vars, or leave as-is since
they're internal tooling.

- [x] `scripts/setup-ses.mjs` — `mailFromForDomain` simplified (redundant explicit cases removed); `ALLOWED_FROM_DOMAINS` and IAM ARNs derived from `APEX_DOMAINS_FOR_INBOUND`; `switchMxRecords` dry-run log + mxName now use `AUDITANDFIX_INBOUND_DOMAINS[0]`. DMARC `rua`/`ruf` address (`dmarc@auditandfix.com`) left as-is — operational config, not a code smell.
- [x] `scripts/setup-ses-inbound.sh` — `PARENT_DOMAIN` now requires env var (`?:`); `DOMAIN`/`RULE_NAME` derived from it.
- [x] `scripts/diagnose-ses-inbound.sh` — `PARENT_DOMAIN` now requires env var (`?:`); `E2E_DOMAIN` derived; fix-message uses variables.
- [x] `scripts/citation-monitor.sh` — `BRAND_DOMAIN`/`BRAND_NAME` fallbacks removed; now fail-loud (`?:`).
- [x] `scripts/backfill-archive.js` — Reconstructed message-id uses `process.env.BRAND_DOMAIN`.

### Unit tests

Hardcoded domains in test fixtures and assertions. Replace with constants or
clearly-named test fixture values (e.g. `TEST_BRAND_DOMAIN`).

- [x] `tests/unit/setup-ses.test.js` — centralised to `tests/helpers/test-domains.js`
- [x] `tests/unit/ses-normalizer.test.js` — `CRAI_INBOUND_DOMAIN` constant from test-domains.js
- [x] `tests/unit/email-forwarder.test.js` — constants from test-domains.js
- [x] `tests/unit/draft-ip-rerequest.test.js` — constants from test-domains.js
- [x] `tests/unit/canary-magic-link.test.js` — `TEST_CANARY_EMAIL` from test-domains.js

### E2E / PayPal test harness

- [x] `tests/e2e/paypal/harness/sandbox-live-run.js` — email derives from `BRAND_URL`, host comments genericised
- [x] `tests/e2e/paypal/harness/README.md` — `.htaccess` + host refs genericised; `BRAND_URL` default retained
- [x] `tests/e2e/paypal/README.md` — architecture diagram genericised to `<brand>/api.php`.
- [x] `tests/e2e/paypal/scripts/capture-sandbox-fixtures.js` — comments use `$BRAND_URL`.
- [x] `tests/e2e/paypal/fixtures/m333-worker/checkout-order-approved.json` — `payee@example.com`.

### Docs — strategy & plans

These are point-in-time documents. May be fine to leave as-is (they describe
the brand), or update if the brand/domain changes.

- [ ] `docs/growth-strategy-2026-Q2.md` — ~4 references: `site:auditandfix.com`,
  Meta Pixel install, www canonicalization, referral link.
- [ ] `docs/seo-strategy-auditandfix.md` — ~6 references: indexing checks, curl
  commands, www/non-www, Search Console. File is brand-specific by design.
- [ ] `docs/review-acquisition-campaign.md` — ~7 references: Trustpilot profile
  URLs, signup email, GBP panel search.
- [ ] `docs/plans/distributed-agent-system.md` — ~7 references: logo/favicon
  asset paths, copy instructions, dashboard mention.

### Docs — Google Ads

All ad copy and campaign docs are brand-specific by design. If the brand
changes, these need a full rewrite anyway.

- [ ] `docs/google-ads/ad-copy.md` — ~30 references: Final URLs, display path,
  sitelink URLs. All point to `auditandfix.com/scan`.
- [ ] `docs/google-ads/campaign-structure.md` — title and landing page URL.
- [ ] `docs/google-ads/measurement-plan.md` — GTM container name, page URL.
- [ ] `docs/google-ads/2step-video-ads.md` — ~15 references: landing page URLs,
  sitelink URLs, budget references.
- [ ] `docs/google-ads/keywords.csv` — brand keyword `auditandfix.com` (line 5).

### Docs — operational

- [ ] `docs/MANUAL-CHECKS.md` — Cloudflare dashboard URLs, Google Search
  Console URLs, Postmaster Tools URL (~6 references). These are bookmarks —
  the URLs are real and correct.
- [ ] `docs/decisions.md` — ~50+ references across dozens of DRs. These are
  historical records and should NOT be changed (they document what was decided
  at the time). **Leave as-is.**

### This file (TODO.md)

- [ ] Existing sections in this file reference both domains in DMARC, SES,
  Cloudflare, and domain purchase tasks. These are operational TODOs about the
  real domains — **leave as-is** until the tasks are completed.

### Approach

1. **Don't touch** `docs/decisions.md` or existing TODO items — they're historical/operational.
2. **Scripts**: extract domain lists to top-level constants or a shared config.
3. **Tests**: introduce `TEST_BRAND_DOMAIN` / `TEST_CRAI_DOMAIN` constants.
4. **Docs (strategy/ads)**: leave for now — they're brand-specific content.
5. **Docs (operational)**: leave — they're real bookmarks/URLs.
6. **Priority**: scripts → tests → docs (if brand changes).
