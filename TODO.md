# TODO

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
- [ ] **⚠️ User action** — Run `node mmo-platform/scripts/setup-ses.mjs` with admin AWS creds to verify SES identity, DKIM CNAME, MAIL FROM `bounce.{domain}` for both `.app` domains
- [x] Update `auditandfix-website/site/api.php` demo + onboarding emails to use `marcus@auditandfix.app`
- [x] Update CRAI dispatch to send AI replies from `marcus@contactreply.app` (workers/index.js + src/services/dispatch.js)
- [ ] Update `auditandfix-website` magic link to use `marcus@auditandfix.app` — update `SENDER_EMAIL` env var on Gary's host once SES identity verified
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
