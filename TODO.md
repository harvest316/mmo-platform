# TODO

## E2E contact-form test page: replace Fastmail SMTP with SES

The standalone PHP page (`e2e-test-page-XXXXXXX.php`, deployed to a throwaway
webhost for 333Method E2E testing) uses a raw `fsockopen` SMTP connection to
Fastmail with hardcoded credentials.

**What to do:**

- [ ] **Rotate the old Fastmail app password** (`948e5s4r962g4y9n` for
  `paulh@corpseo.com.au`) — it was sitting in a plaintext file, consider it
  compromised even though the file was gitignored.
- [ ] Rewrite `sendSMTP()` in the template to use SES SMTP instead:
  - Host: `email-smtp.ap-southeast-2.amazonaws.com` port `587`
  - Credentials: read from env vars `SES_SMTP_USERNAME` / `SES_SMTP_PASSWORD`
    (same as in `auditandfix-website/site/.htaccess`) — set these in the
    webhost's `.htaccess` or `php.ini` alongside the deployed file.
  - From address: `e2e-test-page@auditandfix.com` (verified SES identity)
- [ ] Or simplify further: replace the raw SMTP with PHP's `mail()` and let
  Plesk/sendmail route via SES — but only if the throwaway host is also on SES.
  Otherwise stay with explicit SMTP.
- [ ] Rename and re-upload the updated file; update `TEST_E2E_URL` in
  `333Method/.env` if the filename changes.

**Why:** SES credentials are already provisioned and environment-sourced;
Fastmail is a personal account with no business continuity guarantee.

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

- [ ] `scripts/setup-ses.mjs` — ~40 references: domain lists (lines 92–106),
  MAIL FROM helpers (343–348), receipt rules (936–981), IAM policy ARNs
  (1056–1077), SPF/DMARC records (1196–1270), MX setup (1304–1319). Consider
  extracting domain lists to a `DOMAINS` const at top or a config file.
- [ ] `scripts/setup-ses-inbound.sh` — 7 references: `DOMAIN`, `PARENT_DOMAIN`
  constants and DNS instructions. Already uses variables but defaults are
  hardcoded.
- [ ] `scripts/diagnose-ses-inbound.sh` — `PARENT_DOMAIN` and `E2E_DOMAIN`
  constants (lines 19–20, 109).
- [ ] `scripts/citation-monitor.sh` — `BRAND_DOMAIN` default fallback (line 31).
  Already parameterised, just has `auditandfix.com` as default.
- [ ] `scripts/backfill-archive.js` — Reconstructed message-id fallback
  `@auditandfix.com` (line 119).

### Unit tests

Hardcoded domains in test fixtures and assertions. Replace with constants or
clearly-named test fixture values (e.g. `TEST_BRAND_DOMAIN`).

- [ ] `tests/unit/setup-ses.test.js` — ~30 references: zone-routing assertions
  for both domains and all subdomains (lines 45–221). These are testing real
  zone routing logic so the domain strings are intentional test data, but should
  use a shared constant.
- [ ] `tests/unit/ses-normalizer.test.js` — `inbound.contactreplyai.com` in
  fixture data (lines 131–185).
- [ ] `tests/unit/email-forwarder.test.js` — `marcus@auditandfix.com`,
  `status@dev.auditandfix.com` in test fixtures (lines 99–157).
- [ ] `tests/unit/draft-ip-rerequest.test.js` — `brandDomain: 'auditandfix.com'`
  fixture and assertion (lines 52, 150).
- [ ] `tests/unit/canary-magic-link.test.js` — `test+canary@auditandfix.com`
  in canary config fixtures (lines 85–101).

### E2E / PayPal test harness

- [ ] `tests/e2e/paypal/harness/sandbox-live-run.js` — generated email
  `@auditandfix.com` (line 118), comments referencing the host (lines 176, 564).
  (`AUDITANDFIX_BASE` → `BRAND_URL` rename already done — DR-154.)
- [ ] `tests/e2e/paypal/harness/README.md` — endpoint URLs, `.htaccess`
  references (~5 remaining; `BRAND_URL` env example already updated).
- [ ] `tests/e2e/paypal/README.md` — architecture diagram mentioning
  `auditandfix.com/api.php` (line 23).
- [ ] `tests/e2e/paypal/scripts/capture-sandbox-fixtures.js` — deploy
  instructions and URL in comments (lines 23, 26).
- [ ] `tests/e2e/paypal/fixtures/m333-worker/checkout-order-approved.json` —
  `payee@auditandfix.com` in fixture payload (line 30).

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
