# Post-Scan Email Sequence — Non-Converter Spec
# Brand Site | Draft 2026-03-22

## Overview

This sequence targets users who completed a scan, entered their email at the factor breakdown gate,
and left without purchasing. Marketing optin flag must be 1 before any email beyond the immediate
transactional delivery (the score summary). Sequence is 7 emails over 14 days.

**Sequence goal:** Convert non-purchasers to the Quick Fixes Report ($67/$97/£47) as the entry
product, then upsell to Full Audit ($297/$337/£159). The credit-toward-upgrade mechanic (Quick
Fixes purchase price credited against Full Audit) is the primary conversion bridge.

**Sender identity:** "Marcus @ Audit&Fix" — named sender converts better than a brand name for
small business audiences. Keeps the persona consistent with the site.

---

## Data Required at Email Capture Time

The scan_emails table already stores scan_id, email, marketing_optin, optin_timestamp, ip_hash,
and created_at. The sequence system needs the following additional fields stored or joined at
send time. Confirm these are available from the scan results before building the sequence worker.

| Token | Source | Where stored |
|---|---|---|
| `{{domain}}` | Submitted URL, stripped to bare domain | scan result |
| `{{score}}` | Numeric score 0-100 | scan result |
| `{{grade}}` | Letter grade e.g. C+ | scan result |
| `{{worst_factor_label}}` | Human label of lowest-scoring factor | scan result, FACTOR_LABELS map |
| `{{worst_factor_score}}` | Score out of 10 for worst factor | scan result |
| `{{second_worst_label}}` | Second lowest factor label | scan result |
| `{{price_quickfixes}}` | Localised price e.g. $67 / A$97 / £47 | getProductPriceForCountry(country, 'quick_fixes') |
| `{{price_fullaudit}}` | Localised price e.g. $297 / A$337 / £159 | getProductPriceForCountry(country, 'full_audit') |
| `{{country_code}}` | ISO country from geo.php detect | request IP at scan time |
| `{{scan_url}}` | Deep link back to their results | `{{BRAND_URL}}/scan?url={{domain}}&ref=email` |
| `{{order_url_qf}}` | Quick Fixes purchase link | `{{BRAND_URL}}/?domain={{domain}}&product=quick_fixes#order` |
| `{{order_url_fa}}` | Full Audit purchase link | `{{BRAND_URL}}/?domain={{domain}}&product=full_audit#order` |

Store country_code and factor_scores JSON against the scan_id at scan time. Without this, you
cannot personalise beyond score/grade, which kills the reply rate justification for the whole
sequence.

---

## Segmentation Rules

Apply before sequence enrolment. This is not optional — a tradie who scored 38 and one who
scored 74 have completely different emotional states and different product fits.

### Segment A — Critical (score 0-59, grade F or D)

Frame: "your site is actively costing you customers." Urgency is genuine and earned. Lead with
Quick Fixes as immediate triage, not as a budget option. These prospects feel embarrassed and
want a fast win. Do not labour over the Full Audit in early emails — they need to see action,
not a roadmap.

### Segment B — Needs Work (score 60-76, grade C- to C+)

Frame: "you're in the middle of the pack, which means competitors are eating your lunch." The
site is functional but not converting. This is the most common score band. Balance Quick Fixes
(fast, cheap, tangible) with Full Audit (the complete picture). Credit mechanic resonates here
because they're likely to want more after seeing the Quick Fixes result.

### Segment C — Almost There (score 77-81, grade B- to B)

Frame: "you're close but the top factors dragging you down are specific and fixable." These
prospects already feel reasonably good about their site, so you cannot open with fear. Open
with the gap — what's holding a B from becoming an A, and what that grade difference means in
lost conversions. Quick Fixes pitch should focus on precision, not triage. Full Audit is a
natural next step because they're already invested in the idea of optimisation.

Note: Scores 82+ are above the pipeline's LOW_SCORE_CUTOFF. If inbound scan traffic produces
scores in that range, a shorter 3-email sequence is appropriate with a different angle
("your site scores well — here's how to push it to A+ territory"). That variant is out of scope
for this spec.

### Segmentation implementation note

Segment is computed once at enrolment and stored with the sequence record. Do not re-compute on
each send — if a site gets re-scanned mid-sequence and the score changes, honour the original
enrolment segment to keep messaging coherent.

---

## Compliance Rules

Apply to every email in this sequence, no exceptions.

**GDPR (UK/EU):** Only send if marketing_optin = 1. Every email must include a one-click
unsubscribe link. Unsubscribe must suppress all future marketing immediately (not "within 10
days"). Store optin_timestamp and optin source ("scan email gate") with the contact record.
Do not use pre-ticked boxes for the optin — confirm the scan.php gate uses an explicit unchecked
checkbox or equivalent.

**CAN-SPAM (US):** Include physical address in every email footer. Subject lines must not be
deceptive. Unsubscribe must be honoured within 10 business days (aim for immediate).

**Spam Act 2003 (AU):** Consent at the email gate qualifies as express consent (user actively
entered email and opted in for updates). Include a functional unsubscribe mechanism. Consent
is not time-limited under AU law but best practice is to treat 12+ months of no engagement as
lapsed. After 90 days with no email open or click, suppress from future sends.

**All regions:** Include unsubscribe link, sender name, and physical address (or registered
business address) in every footer. Do not obscure the commercial nature of any email.

---

## Send Timing

All times are localised. Use the country_code to determine timezone:
- AU: Sydney time (AEDT/AEST, UTC+11/+10)
- GB: London time (GMT/BST, UTC+0/+1)
- US: Use recipient's local time if state is known; otherwise Eastern time

Send window: 9am-6pm local, Monday-Friday. Batch sends that fall outside the window should be
queued to the next available morning slot, not skipped. Weekend sends have 30-40% lower open
rates for the tradie ICP — avoid unless the user opened a previous email on a weekend (signal
they do check on weekends).

---

## The Sequence

### Email 1 — Score Delivery (Immediate, Transactional)

**Trigger:** Email submitted at scan gate.
**Send delay:** Immediately (within 60 seconds).
**Compliance note:** This email is transactional — delivering the thing the user asked for
(their full factor breakdown summary). It can be sent regardless of marketing_optin. However,
the CTA at the bottom is commercial, so treat it as marketing for safe compliance practice.
Only include the CTA if marketing_optin = 1. If marketing_optin = 0, send the score summary
only, with no product pitch and no sequence enrolment.

**Subject line (all segments):**
`{{domain}}: your score is {{score}}/100 (grade {{grade}})`

Subject rationale: personalised, factual, lowercase, reads like an internal notification.
No emoji, no exclamation mark. Tradies open email that looks like it's about them specifically.

**Content outline:**

Opening: "Here's the full breakdown you asked for." One line. Get to the data immediately.

Score block: Reproduce all 10 factor scores in plain text or a simple table. Show the factor
label, score out of 10, and a one-word status (Good / Needs Work / Critical). No images — plain
text renders everywhere and avoids clipping in Gmail.

Segment-specific framing after the score block:
- Segment A: "Three of your ten factors are in critical territory. That's not a cosmetic
  problem — those are the factors that decide whether a visitor picks up the phone or hits
  back."
- Segment B: "Your score puts {{domain}} in the bottom half of the sites we've scanned. The
  good news: most of the gap is concentrated in 2-3 factors."
- Segment C: "You're above average — most sites we scan are a D+. But {{worst_factor_label}}
  is holding you back from a B+ or higher, and that gap is fixable."

CTA (marketing_optin = 1 only): One line, one link.
- Segments A/B: "See exactly what to fix — Quick Fixes Report for {{price_quickfixes}}: {{order_url_qf}}"
- Segment C: "Get the full picture — Full Audit Report for {{price_fullaudit}}: {{order_url_fa}}"

Footer: unsubscribe link, physical address, "You're receiving this because you scanned
{{domain}} at our site and asked for your results."

---

### Email 2 — Worst Factor Deep-Dive (Day 1, +18 hours after Email 1)

**Trigger:** marketing_optin = 1, no purchase, Email 1 sent.
**Send delay:** 18 hours after Email 1, within the 9am-6pm local window.

**Subject lines by segment:**

Segment A: `your {{worst_factor_label}} score — here's what that means`
Segment B: `the one thing pulling {{domain}}'s score down`
Segment C: `why {{domain}} isn't converting at its potential`

**Content outline:**

Open with a one-sentence observation about their specific worst factor — no pleasantries.

Example openers by worst factor (write one for each of the 10 factors — these are the load-
bearing sentences that determine whether they keep reading):

- headline_quality: "The first thing a visitor reads on {{domain}} is your headline. If it
  doesn't immediately answer 'what do you do and who is it for', they're already looking at
  the back button."
- call_to_action: "A weak call to action doesn't just mean fewer enquiries — it means you're
  paying for traffic that bounces without ever knowing what to do next."
- trust_signals: "For a trade business, trust signals aren't optional extras. A visitor who
  doesn't see licences, reviews, or guarantees on page load makes a snap decision and moves on."
- urgency_messaging: "Most trade sites give a visitor no reason to call today rather than
  tomorrow. Tomorrow usually means never."
- value_proposition: "If someone can't tell within 5 seconds why they should use you instead
  of the next plumber in Google, the comparison shopping starts."
- hook_engagement: "The first scroll determines whether someone reads the rest. A low hook
  score means the page is losing people before they've seen your offer."

(Implement a template per factor key — 10 templates total. Use worst_factor_label key to
select the correct one. Fallback: generic "your lowest-scoring factor was {{worst_factor_label}},
scoring {{worst_factor_score}}/10. Here's what that means for your conversion rate.")

Middle paragraph: Brief, non-technical explanation of what that factor controls in terms of
customer behaviour. One paragraph, 3-4 sentences max. Write at a tradie reading level — assume
they know their trade cold and know nothing about conversion optimisation.

Transition to product: "The Quick Fixes Report tells you exactly what to change on {{domain}}
for this factor and the four others dragging your score down. We annotate screenshots so you
can hand it directly to whoever builds your site — or follow the instructions yourself."

CTA: Single link.
- Segments A/B: "Get the Quick Fixes Report — {{price_quickfixes}}: {{order_url_qf}}"
- Segment C: "Get the Full Audit — {{price_fullaudit}}: {{order_url_fa}}"

---

### Email 3 — Social Proof / Similar Business (Day 3)

**Subject lines by segment:**

Segment A: `what a {{score}}/100 site looks like after the fixes`
Segment B: `a plumber in Brisbane went from C to B+ in 2 weeks`
Segment C: `from B to A- — what changed`

Note on subject line personalisation: "plumber in Brisbane" is an example. If you have vertical
and city data from the scan URL or form context, use it. If not, use a generic trade category
that applies to the ICP broadly ("a local tradie"). The city reference dramatically increases
open rates for localised audiences — they assume it's about someone they might know or compete
with.

**Content outline:**

Lead with a before/after case study. The case study format: [trade], [city], [starting score
and grade], [specific factors that were fixed], [outcome in measurable terms — enquiry rate,
call volume, or Google rating mentions if available]. Keep it to 4-5 sentences.

If you don't have real case study data at launch, write a composite based on realistic
outcomes and mark it as "typical results for sites in this score range" — do not fabricate
specific company names or specific numbers you cannot defend. British spelling for AU/UK,
American for US.

Reinforce the credit mechanic for the first time here: "If you start with the Quick Fixes
Report and want to go deeper later, the {{price_quickfixes}} comes off the price of the Full
Audit. You're not choosing between products — you're deciding where to start."

CTA: Same as Email 2 by segment, but add the credit line directly above it:
"Quick Fixes Report — {{price_quickfixes}} (credited toward Full Audit if you upgrade):
{{order_url_qf}}"

---

### Email 4 — Rescan Nudge / Urgency Without Fake Scarcity (Day 5)

**Subject lines:**

Segment A: `{{domain}} — is this fixable without a developer?`
Segment B: `you can fix 3 of your 5 issues without touching the code`
Segment C: `the quick fixes that don't need a developer`

**Content outline:**

This email addresses the most common objection for the tradie ICP: "I'd need to get my web
guy involved and that's a whole thing." Reframe the Quick Fixes Report as actionable for
non-technical owners. "At least 3 of the top 5 issues on most sites in the C-D range require
no code changes — they're copy, layout, or missing elements you can add yourself or brief to
anyone."

Second paragraph: Acknowledge the friction. "We know getting someone to look at your site is
often the hard part. The report format is designed so you can email the annotated screenshots
directly to your web person and say 'fix these five things'. No technical briefing required."

Urgency angle: Do not manufacture false scarcity ("only 3 spots left"). The real urgency is
competitive: "Every week your site scores a C+ is a week where the competitor on the next
Google result is getting the call instead." One sentence. Do not belabour it.

CTA: Quick Fixes for all segments. By Day 5, Segment C should have received two Full Audit
CTAs. Introduce Quick Fixes as the lower-friction starting point for them: "Start with the
Quick Fixes — {{price_quickfixes}}, and if you want the full roadmap after, we credit it:
{{order_url_qf}}"

---

### Email 5 — Full Audit Pivot (Day 7)

**Subject lines:**

Segment A: `the full picture for {{domain}} — 9-page report`
Segment B: `beyond the quick fixes — the full audit`
Segment C: `what a B+ site needs to reach A territory`

**Content outline:**

This is the first email that leads with the Full Audit for Segments A and B (who have seen
two Quick Fixes pitches). Segment C has seen the Full Audit before; reframe it here.

Describe what the Full Audit contains without a feature list. "Nine pages covering all ten
factors, with zoomed screenshots of the specific elements on your site that are costing you
conversions, a prioritised roadmap in order of likely impact, and a benchmark against the
23,000+ sites we've scored." Write it as what a busy owner gets out of it, not what it is.

Mention the Audit + Implementation product briefly for the first time: "If you'd rather
hand it off entirely — we implement the top 3 fixes for you as part of the Audit + Fix package
({{price_auditfix}}). 48-hour turnaround." One sentence. Do not price-anchor here — just
name it and move on. The goal is awareness, not conversion, for the upsell in this email.

CTA: Full Audit as primary for all segments.
"Full Audit Report — {{price_fullaudit}}: {{order_url_fa}}"
Below it, a secondary line in smaller/lighter text: "Or start with Quick Fixes
({{price_quickfixes}}, credited toward Full Audit): {{order_url_qf}}"

---

### Email 6 — Credit Mechanic Spotlight (Day 10)

**Subject lines:**

Segment A: `a {{price_quickfixes}} report that counts toward the full audit`
Segment B: `how the credit works — quick fixes to full audit`
Segment C: `the upgrade path for {{domain}}`

**Content outline:**

This email exists specifically to explain the credit mechanic to prospects who may not have
processed it in earlier emails. Spell it out simply:

"Quick Fixes Report: {{price_quickfixes}}. Full Audit Report: {{price_fullaudit}}. If you buy
the Quick Fixes first and later want the full audit, we subtract {{price_quickfixes}} from the
price. You pay the difference, not both in full. Most people use it as a try-before-you-go-
deeper option."

Follow with a practical reason the order makes sense for a busy tradie: "The Quick Fixes gives
you the five most impactful changes immediately. The Full Audit gives you the complete picture
for the rest of the year. Doing them in that order means you see results in days, not weeks."

This email should be the plainest, most conversational in the sequence. No case studies, no
feature lists. Just the mechanic and why it's sensible.

CTA: Quick Fixes as primary (anchoring the credit entry point).
"Start here — Quick Fixes Report ({{price_quickfixes}}): {{order_url_qf}}"

---

### Email 7 — Final Email / Soft Close (Day 14)

**Subject lines:**

Segment A: `last one from me re: {{domain}}`
Segment B: `still thinking about it? here's where things stand`
Segment C: `wrapping up — the offer's still open`

**Content outline:**

Honest, brief, no hard sell. Acknowledge the sequence is ending. "This is the last email I'll
send about your {{domain}} results — I don't want to be that person who emails you every week
about something you've already decided isn't for you right now."

Restate the offer clearly one final time: scores, products, prices, the credit mechanic.
Two short paragraphs, no padding.

Close with a genuine open door: "If the timing changes — maybe after a slow month or when the
site gets a refresh — your scan results are still valid and the offer stands. You can always
come back: {{scan_url}}"

No aggressive CTA language. A plain text link to both products. The tone here is what protects
the brand — a tradie who doesn't buy now but feels respected will refer someone who does.

---

## Sequence Summary Table

| # | Day | Delay | Subject theme | Primary CTA | Segment variation |
|---|-----|-------|---------------|-------------|-------------------|
| 1 | 0 | Immediate | Score delivery + breakdown | QF (A/B) / FA (C) | Framing only |
| 2 | 1 | +18h | Worst factor explanation | QF (A/B) / FA (C) | Opener per factor |
| 3 | 3 | +48h | Social proof / case study | QF with credit note | Generic across segments |
| 4 | 5 | +48h | No-code fixes / objection handle | QF (all) | Segment C gets QF intro |
| 5 | 7 | +48h | Full Audit pivot + Impl mention | FA (all) | QF as secondary |
| 6 | 10 | +72h | Credit mechanic spotlight | QF entry point | Plain for all |
| 7 | 14 | +96h | Soft close | Both products | Minimal variation |

---

## Sequence State Machine

Enrol on: email submitted at gate + marketing_optin = 1.

Exit conditions (any stops the sequence immediately):
- Purchase of any product (quick_fixes, full_audit, audit_fix)
- Unsubscribe
- Hard bounce (mark contact as undeliverable, never retry)
- Soft bounce 3x in a row (pause and flag for review)
- Email marked as spam (suppress permanently)

Pause conditions (do not exit, just delay):
- No condition warrants pausing and resuming. Either send or exit.

Purchase detection: The sequence worker must check the orders/purchases table before each
send. Do not rely on a webhook from PayPal arriving in time — poll the DB directly.

---

## Resend Implementation Notes

Resend sends transactional email via API. For this sequence:

1. Use a dedicated subdomain for sequence mail (e.g. `mail.BRAND_DOMAIN`) with proper SPF,
   DKIM, DMARC. Do not send sequence mail from the same domain/IP as transactional order
   confirmations — a spam classification on sequence mail must not affect order confirmation
   deliverability.

2. Use Resend's `tags` field to label each email with `sequence:post_scan`, `email_num:1` etc.
   This makes analytics filtering straightforward.

3. Store send state in a `scan_email_sequence` table:
   - scan_id (FK to scan_emails)
   - email (denormalised for query convenience)
   - segment (A/B/C)
   - next_email_num (1-7, or 8 = completed)
   - next_send_at (UTC datetime)
   - last_sent_at
   - status (active / paused / completed / unsubscribed / bounced)
   - purchase_detected_at (nullable)

4. A cron job running every 5-10 minutes selects records where status = 'active' AND
   next_send_at <= NOW() AND next_send_at is in the local send window. For each, check for
   purchase, then send, then update next_send_at and next_email_num.

5. Unsubscribe endpoint: generate a signed token (HMAC of email + scan_id + secret) and
   include it as a query param in every email footer link. The handler sets status =
   'unsubscribed' and records the timestamp. No login required.

---

## A/B Test Plan

Run one test at a time. Do not test multiple variables simultaneously.

**Test 1 (launch):** Subject line format for Email 2.
- Variant A: `your {{worst_factor_label}} score — here's what that means`
- Variant B: `{{domain}} is losing customers because of this`
- Measure: open rate. Minimum 200 sends per variant before calling it.

**Test 2 (after Test 1 is called):** CTA phrasing for Email 3.
- Variant A: "Get the Quick Fixes Report — {{price_quickfixes}}"
- Variant B: "Fix these 5 issues — {{price_quickfixes}}"
- Measure: click-through rate on the CTA link.

**Test 3:** Sequence length. After 60 days of data, compare conversion rates for users who
received all 7 emails vs a holdout that received only emails 1-4. If emails 5-7 produce less
than 0.5% incremental conversion, cut the sequence.

---

## Localisation Notes

- AU/GB/NZ/IE: British spelling — "optimise", "recognise", "colour". Tone: direct but not
  pushy. Avoid "Hey" as an opener — "Hi" or no salutation.
- US/CA: American spelling. Slightly warmer opener tone acceptable.
- All regions: Do not say "ring" in US/CA copy. Do not say "call" in UK copy where "ring"
  reads more naturally (the sequence is email-only so this is a minor point, but keep the
  principle consistent with outreach copy standards per DR-013).
- Prices must always show the localised currency. Never show a USD price to an AU visitor or
  vice versa. Use the country_code captured at scan time.

---

## Metrics to Track

| Metric | Target | Action if below target |
|---|---|---|
| Email 1 open rate | 55%+ (transactional) | Check deliverability, SPF/DKIM config |
| Email 2-4 open rate | 35%+ | A/B test subject lines |
| Email 5-7 open rate | 20%+ | Acceptable attrition; review if below 15% |
| Sequence click-through rate | 8-12% per email | Review CTA copy and link placement |
| Quick Fixes conversion (from sequence) | 3-6% of enrollees | Review Email 4 objection handling |
| Full Audit conversion (from sequence) | 1-3% of enrollees | Review Email 5 framing |
| Unsubscribe rate | Under 2% per email | Review send frequency and relevance |
| Spam complaint rate | Under 0.1% | Immediate deliverability review |

The primary conversion window is emails 1-4 (days 0-5). If a user hasn't bought by Email 5,
they are a low-probability converter and the sequence should shift to brand preservation — no
hard selling in emails 6-7.

---

## Decision Register Entry

Add DR-068 to docs/decisions.md after this spec is approved. Context: post-scan email sequence
design for non-converters. Decision: 7-email sequence over 14 days, three score-band segments,
Quick Fixes as entry CTA with credit-toward-upgrade mechanic introduced at Email 3, Full Audit
pivot at Email 5, plain-text soft close at Email 7. Compliance: GDPR/CAN-SPAM/Spam Act 2003
via marketing_optin gate. Implementation: Resend API, scan_email_sequence state table, 5-10
min cron.
