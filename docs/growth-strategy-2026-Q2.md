# Audit&Fix Growth Strategy -- Q2 2026

Prepared 2026-03-23. Bootstrapped solo operation, $200/mo ad budget.
Cold outreach pipeline (333Method) is the primary engine. Everything else is supplementary.

---

## Table of Contents

1. [Situation Assessment](#1-situation-assessment)
2. [Channel Prioritization](#2-channel-prioritization)
3. [Paid Advertising Strategy ($200/mo)](#3-paid-advertising-strategy)
4. [Cold Outreach Optimization](#4-cold-outreach-optimization)
5. [Organic/Free Growth Channels](#5-organicfree-growth-channels)
6. [Funnel Optimization](#6-funnel-optimization)
7. [Quick Wins vs Long-Term Plays](#7-quick-wins-vs-long-term-plays)
8. [Metrics and KPIs](#8-metrics-and-kpis)

---

## 1. Situation Assessment

### What is working

- **Cold outreach pipeline** runs at scale with low marginal cost. This is the competitive moat.
- **Free scanner** has processed 23,990+ sites -- proves product-market interest exists.
- **Pricing** is well-positioned: $67-$497 range undercuts agencies ($2,500+) while being premium enough to signal quality.
- **Credit mechanic** (Quick Fixes price credited toward Full Audit) is smart upsell friction reduction.
- **Landing page** has strong structure: social proof, guarantee, analyst credibility, FAQ.

### Critical gaps found

1. **Not indexed by Google.** `site:auditandfix.com` returns zero results. The sitemap only lists 2 URLs (/ and /scan). There is no blog, no content, no indexable pages beyond the homepage and scanner. This means zero organic traffic, zero brand search presence, and zero SEO authority. This is the single biggest missed opportunity.

2. **No post-scan email sequence built yet.** 23,990 emails collected (or a subset who opted in) with no automated follow-up. Every day without this sequence is lost revenue from already-captured leads.

3. **Referral program is passive.** The thank-you page mentions "15% off your next order" for referrals but requires the customer to manually forward a URL and have the referee mention them by email. No tracking, no shareable link, no incentive for the referee.

4. **Video Reviews product has no cross-sell path** from the CRO audit funnel. A business that buys a CRO audit is a perfect candidate for video reviews (they care about conversion), but there is no bridge between the two products.

5. **No analytics installed.** No Google Analytics/GA4, no Google Tag Manager, no Meta Pixel. You have zero visibility into website traffic, user behavior, or conversion paths. UTM parameter fields exist in the scanner config but are not connected to any analytics platform.

6. **Thank-you page has no order confirmation number**, no receipt, and no immediate engagement hook beyond "wait for your email." This is dead time where buyer excitement is highest.

7. **Quick Fixes ($67) is buried.** The homepage leads with the $337 Full Audit. The scanner page shows Quick Fixes as a secondary CTA. For cold traffic and price-sensitive small business owners, the $67 entry point should be more prominent as a foot-in-the-door offer.

---

## 2. Channel Prioritization

Ranked by expected ROI given the constraints (solo operator, $200/mo, existing pipeline):

| Priority | Channel | Cost | Timeline | Expected Impact |
|----------|---------|------|----------|----------------|
| 1 | Post-scan email sequence | $0 (Resend already set up) | 1-2 weeks to build | Highest. Converts existing leads. |
| 2 | Cold outreach optimization | $0 (already running) | Ongoing | Improve conversion on existing volume. |
| 3 | SEO / content pages | $0 (time only) | 2-6 months to compound | Medium-term organic acquisition. |
| 4 | Google Ads (scanner as lead magnet) | $150/mo | Immediate | Targeted inbound leads. |
| 5 | Facebook/Meta retargeting | $50/mo | 1-2 weeks setup | Re-engage scanner users who did not convert. |
| 6 | Referral program upgrade | $0 (time only) | 1 week | Viral loop from existing customers. |
| 7 | Partnerships / white-label | $0 | 1-3 months | Leverage other people's audiences. |
| 8 | Social proof / case studies | $0 (time only) | Ongoing | Supports all other channels. |

**Do not spread the $200 across more than 2 paid channels.** Concentration beats diversification at this budget level.

---

## 3. Paid Advertising Strategy

### Budget split: $150 Google Ads + $50 Meta retargeting

### Google Ads ($150/mo)

**Campaign type:** Search ads, manual CPC, exact/phrase match only.

**Why search, not display:** At $150/mo you get roughly 30-50 clicks (assuming $3-5 CPC for these keywords). Every click must be high-intent. Display is awareness -- you cannot afford awareness plays.

**Target keywords (tiered by intent):**

Tier 1 -- Buyer intent (bid highest):
- "website audit service"
- "CRO audit small business"
- "website conversion audit"
- "website audit report"

Tier 2 -- Problem-aware (bid moderate):
- "why is my website not converting"
- "website not getting leads"
- "improve website conversions"
- "website losing customers"

Tier 3 -- Scanner keywords (bid lowest, use scanner as landing page):
- "free website audit"
- "free website score"
- "check my website score"
- "website grader"

**Ad copy framework:**

Headline 1: "Your Website Scored {{score}} -- See Why"
(Dynamic insertion is not possible without audience data, so use:)

Headline 1: "Free Website Score in 30 Seconds"
Headline 2: "Find Out Why Visitors Aren't Converting"
Headline 3: "CRO Audit From $67 -- Same-Day Delivery"
Description: "Enter your URL. Get your conversion score instantly. No signup required. 23,000+ sites already scored. Agency-quality audit at a fraction of the price."

**Landing pages by tier:**
- Tier 1/2: Send to `/scan` (free scanner), NOT the homepage. The scanner is a lower-friction entry point than a $337 purchase page.
- Tier 3: Send to `/scan` as well.

**Geographic targeting:**
- Australia only initially (primary market, highest familiarity with AUD pricing).
- Exclude brand searches if any exist (they are free clicks from SEO).

**Negative keywords:** "free tool", "free checker", "SEO audit tool", "software", "template", "DIY", "course", "learn".

**Bidding strategy:** Manual CPC. Set max CPC at $5 AUD. Monitor for 2 weeks, then adjust. Target 30+ clicks/mo minimum to get meaningful data.

**Success math:**
- 150 spend / $4 avg CPC = ~37 clicks
- Scanner completion rate (estimate): 60% = ~22 scans
- Email capture rate (estimate): 40% = ~9 emails
- Conversion to $67 Quick Fixes (estimate): 5% of emails = 0.45 purchases/mo
- That is roughly breakeven on ad spend alone. The real value is the email sequence converting over 14 days + cold outreach pipeline enrichment.

### Meta Retargeting ($50/mo)

**Pixel requirement:** Install Meta Pixel on auditandfix.com if not already present. Fire events on: page view, scan completion, email submission, purchase.

**Audience:** Retarget visitors who completed a scan but did not purchase. This is a warm audience.

**Ad creative:**
- Format: Single image or short video (15s), not carousel.
- Message: "You scored {{grade}} on your website audit. Here's what to fix first -- $67, delivered today."
- Since dynamic score insertion is not possible in Meta ads, use segmented audiences if volume allows, or generic: "Your free website score revealed issues. Get your fix list for $67 -- delivered same day."

**Budget note:** At $50/mo with retargeting CPMs around $5-15, you will get 3,000-10,000 impressions per month. This is a frequency play, not a reach play. You want the same people seeing the ad 3-5 times.

**Do not run Meta prospecting campaigns.** $50/mo is insufficient for cold Meta traffic in any competitive niche.

---

## 4. Cold Outreach Optimization

The pipeline already works. The goal is to improve conversion rate at each stage, not change the architecture.

### A. Subject line and opening line testing

Run 3 concurrent subject line variants per batch. Track open rates per variant. Current approach likely uses a single template with spintax. Instead, test fundamentally different angles:

- **Score angle:** "Your website scored 43/100 -- here's what's costing you leads"
- **Competitor angle:** "3 things [competitor] does better than [their site] (with screenshots)"
- **Money angle:** "You're spending $X/mo on Google Ads but losing 60% of visitors at checkout"
- **Social proof angle:** "[Similar business] fixed these 3 issues and doubled their leads"

The score angle is likely already in use. The competitor and money angles are worth testing if you have enrichment data to support them.

### B. Quick Fixes as the primary cold outreach offer

The current cold outreach likely pushes the Full Audit ($297-337). For cold traffic from strangers, the $67 Quick Fixes report is a far easier first purchase. Consider restructuring the outreach funnel:

1. Cold email/SMS mentions their score and offers the $67 Quick Fixes report
2. Quick Fixes report includes credit toward the Full Audit (already exists)
3. Post-delivery email offers the Full Audit upgrade at the credited price

This reduces the initial ask from $297+ to $67, dramatically lowering the conversion barrier. The LTV math works out: $67 initial + $230-270 upgrade + potential $497 implementation = $794 max LTV per lead.

### C. Timing optimization

- **Send window:** Tuesday-Thursday, 9am-11am local time for the recipient's timezone.
- **Follow-up cadence:** If no response after initial, send follow-up at Day 3 and Day 7. Three touches maximum for cold outreach.
- **Seasonal awareness:** Avoid sending during school holidays (varies by market). For tradies specifically, Monday morning is when they plan their week -- consider Monday 7am sends for that ICP.

### D. Personalization depth

Move beyond "Hi {first_name}, I noticed your website {domain}..." to demonstrating you actually looked at their site:

- Include one specific screenshot from their site showing a real issue
- Reference their Google rating or review count
- Mention their specific industry/service area
- If they run Google Ads (visible via auction insights or ad transparency), mention that specifically: "You're paying for clicks that your website isn't converting"

### E. SMS as a follow-up channel, not primary

If SMS is available: use it as a Day 3 follow-up to an unopened email, not as the first touch. SMS from unknown numbers about website audits reads as spam. After an email establishes context, a short SMS ("Hey [name], sent you an email about your website score -- worth a quick look") has higher legitimacy.

---

## 5. Organic/Free Growth Channels

### A. SEO -- The biggest untapped opportunity

**Current state:** 2 pages in sitemap, zero Google indexing. This needs to change immediately.

**Content strategy -- programmatic SEO pages:**

Create templated pages for every industry + location combination you serve. These are not blog posts; they are landing pages targeting long-tail search queries.

Example URL structure:
```
/audit/plumber-sydney
/audit/electrician-melbourne
/audit/pest-control-london
/audit/dentist-los-angeles
```

Each page contains:
- H1: "Website Audit for [Industry] in [City]"
- 3-5 common conversion issues specific to that industry (e.g., "Most plumber websites bury their phone number below the fold")
- A CTA to the free scanner
- A sample score breakdown for that industry vertical
- Aggregate stats: "We've audited X [industry] websites. The average score is Y/100."

**Volume target:** 50-100 pages covering top industry/city combinations across AU, UK, US. These can be generated systematically since you have the scoring data from 23,990+ scans.

**Why this works:** Each page targets a long-tail keyword with very low competition (no one else is targeting "website audit for plumbers in sydney"). The aggregate gets hundreds of indexable pages that collectively build domain authority and capture long-tail traffic.

**Blog content (secondary priority):**

Publish 2-4 articles per month targeting problem-aware searches:
- "5 Reasons Your Tradie Website Isn't Getting Leads"
- "What's a Good Website Conversion Rate for [Industry]?"
- "Google Ads vs Website Fixes: Where Should You Spend First?"
- "How to Tell If Your Website Is Costing You Customers"

Each article ends with a CTA to the free scanner.

**Technical SEO fixes needed:**
1. Expand sitemap.xml to include all new pages
2. Submit sitemap to Google Search Console (do this immediately regardless of content -- get the existing 2 pages indexed)
3. Add structured data (LocalBusiness, Product, FAQ schema) to the homepage
4. Ensure www vs non-www canonicalization is correct (sitemap references www.auditandfix.com but the site may serve from both)
5. Add internal linking between programmatic pages and the scanner

### B. Partnerships -- White-label and referral

**Web designers and developers:**
These are the natural referral partners. A web designer who builds a site for a tradie can upsell a $67 Quick Fixes audit as a "post-launch health check." Offer:
- 30% recurring commission on referred purchases
- White-label option: they can rebrand the report as their own service (you deliver, they resell at their markup)
- Bulk pricing: 10-pack of Quick Fixes audits for $500 (they sell at $97 each, making $470 profit)

**Digital marketing agencies:**
Small agencies servicing local businesses can use Audit&Fix as a lead qualification tool. They send prospects through the free scanner, and any prospect who scores below 60 becomes a warm lead for the agency's services. In exchange, the agency promotes the scanner to their audience.

**Outreach to partners:** Use the same cold outreach pipeline. Search for "web designer [city]" or "digital marketing agency [city]" and send a partnership pitch instead of a sales pitch.

### C. Community and social

**Facebook Groups:** Join 5-10 groups where small business owners and tradies congregate (e.g., "Aussie Tradies", "Small Business Australia", "UK Small Business Owners"). Do not spam. Instead:
- When someone asks "how do I get more leads from my website?" respond with genuine advice and mention the free scanner as a resource.
- Share anonymized case study posts: "A pest control company in Sydney scored 34/100 on their website. The #1 issue? Their contact form was hidden behind 3 clicks. After fixing that one thing, enquiries went up 40%."
- This is a long game but costs nothing and builds authority.

**LinkedIn:** Post 2-3 times per week about website conversion insights. Target the same business owner audience. LinkedIn organic reach is still high relative to other platforms in 2026.

### D. Referral program upgrade

Replace the passive "forward this link" approach with a structured referral system:

1. After purchase, email the customer a unique referral link: `auditandfix.com/scan?ref={customer_id}`
2. When a referee completes a purchase via that link, the referrer gets a $20 credit toward their next audit (or a cash payout if preferred)
3. The referee gets $10 off their first purchase
4. Track referrals in the database, not via email reply

**Implementation complexity:** Low. Add a `ref` query parameter to the scanner, store it with the email capture, attribute it on purchase. This is a 1-2 day dev task.

---

## 6. Funnel Optimization

### Current funnel: Scanner -> Email Gate -> Purchase

**Stage 1: Scanner entry (URL submission)**

Current: "Check your conversion score in 30 seconds -- free, no signup required"

This is good. Do not change the core promise. But add:
- **Social proof counter** on the scan page: "23,990 sites scored" (already present -- good)
- **Industry selector** before scan: "What type of business is this?" dropdown (plumber, electrician, dentist, etc.). This does two things: (a) enables industry-specific benchmarking in results, (b) gives you segmentation data for email sequences.

**Stage 2: Score display + email gate**

This is the critical conversion point. Improvements:

- **Show the overall score and grade** before the email gate, but gate the individual factor breakdown. Currently, you show "0/100" as a placeholder -- the user should see their actual score (e.g., "Your site scored 43/100 -- Grade: D") immediately. The emotional reaction to a bad score is the motivation to provide an email.
- **Factor preview:** Show factor names (e.g., "Headline Quality: ???", "Call to Action: ???", "Mobile Experience: ???") with scores hidden behind the email gate. The curiosity gap drives email submission.
- **Urgency element:** "Your score report expires in 24 hours" (soft expiry -- they can always re-scan, but it creates urgency to provide email now).

**Stage 3: Post-email factor reveal + purchase CTA**

After email submission, show the full factor breakdown. Then:
- **Lead with Quick Fixes ($67)** as the primary CTA, not the Full Audit. Reason: someone who just discovered their score is not ready to commit $337. They want to know "what should I fix first?" -- that is exactly what Quick Fixes delivers.
- **Position Full Audit as "going deeper"** for those who want comprehensive analysis.
- **Add a comparison table** showing Free Scan vs Quick Fixes vs Full Audit deliverables side by side.

**Stage 4: Post-scan email sequence (7 emails, 14 days)**

This is already specced (DR-068) but not built. Prioritize building it. Recommended structure:

| Day | Email | Purpose |
|-----|-------|---------|
| 0 | Score delivery + factor breakdown | Deliver value, establish credibility |
| 1 | "The #1 thing killing your conversions" | Address their worst factor, tease Quick Fixes |
| 3 | Social proof / case study | Show what happened when someone similar fixed their issues |
| 5 | Quick Fixes offer ($67) | Direct CTA with urgency (score may change as competitors improve) |
| 7 | "Did you know?" educational content | Position expertise, address common misconception |
| 10 | Full Audit offer ($297) | Upgrade pitch for those who did not buy Quick Fixes |
| 14 | Final follow-up + free tip | Give one actionable fix for free, re-CTA |

Segment by score band:
- Score 0-40 (poor): emphasize urgency, "you're losing customers every day"
- Score 41-65 (average): emphasize competitive advantage, "small fixes, big results"
- Score 66-85 (good): emphasize optimization, "you're close -- a few tweaks could make the difference"
- Score 86-100 (excellent): light touch, "your site is strong -- here's how to stay ahead"

**Stage 5: Thank-you page (post-purchase)**

Current thank-you page improvements:
1. **Add order confirmation number** and receipt summary
2. **Immediate engagement:** "While your report is being prepared, here are 3 things you can do right now to start improving your website" -- link to a short guide or video
3. **Cross-sell Video Reviews:** "Want to turn your best Google reviews into social media videos? See how it works" -- link to /video-reviews
4. **Referral prompt with trackable link** (see Section 5D above)
5. **Survey:** "How did you hear about us?" -- critical attribution data you are currently missing

---

## 7. Quick Wins vs Long-Term Plays

### This week (Days 1-7)

1. **Install Google Analytics (GA4) and Google Tag Manager.** There is currently zero analytics on the site. Without this, you cannot measure anything. Set up GA4 via GTM, configure events for: page view, scan start, scan complete, email submit, purchase.
2. **Submit sitemap to Google Search Console.** Zero-effort, immediate. The site needs to be indexed.
3. **Install Meta Pixel** on all pages via GTM. Even before running ads, start building retargeting audiences from day one.
4. **Add "How did you hear about us?" field** to the order form or thank-you page. You need attribution data.
5. **Restructure scanner CTA hierarchy:** Make Quick Fixes ($67) the primary CTA after email capture, Full Audit secondary.
6. **Add www/non-www canonical tags** and verify redirects are consistent.

### This month (Days 8-30)

6. **Build and launch the post-scan email sequence** (7 emails, 14 days). This is the highest-ROI item on the entire list. You have thousands of captured emails and no automated follow-up.
7. **Launch Google Ads campaign** with $150/mo budget targeting Tier 1/2 keywords, sending to /scan.
8. **Set up Meta retargeting campaign** with $50/mo budget targeting scanner visitors who did not purchase.
9. **Create 10 programmatic SEO pages** for top industry/city combinations (start with Australian trades).
10. **Build referral tracking** (ref parameter on scanner URL, database attribution).
11. **Launch 2Step Video Reviews ad landing page** at /video-reviews/ads -- lead capture page (not direct checkout) with demo video, pricing cards, UTM tracking. Separate Google Ads campaign for video review keywords once CRO audit ads are validated.

### This quarter (Days 31-90)

11. **Expand programmatic SEO to 50-100 pages** covering AU, UK, US markets.
12. **Start blog content** -- 2 posts per month targeting problem-aware keywords.
13. **Partner outreach to web designers** -- use the cold outreach pipeline to find and pitch web designers on a referral/white-label arrangement.
14. **A/B test cold outreach** -- Quick Fixes ($67) as primary offer vs Full Audit as primary offer. Measure conversion rate difference.
15. **Cross-sell Video Reviews** to existing CRO audit customers via a post-delivery email (Day 7 after report delivery).
16. **Optimize Google Ads** -- by now you have 60-90 days of data. Cut underperforming keywords, increase bids on winners.

### Next quarter (Q3 2026)

17. **Scale what works.** If Google Ads are profitable, increase budget to $500/mo. If programmatic SEO is generating traffic, double the page count.
18. **Launch white-label product** for web design agencies if partner outreach shows interest.
19. **Build an "industry report"** (e.g., "The State of Tradie Websites in Australia 2026") from aggregated scan data -- use as PR and link-building asset.
20. **Consider YouTube** -- short videos showing real website audits (anonymized). "I audited a plumber's website -- here's what I found." This content format performs well and drives scanner traffic.

---

## 8. Metrics and KPIs

### Primary metrics (check weekly)

| Metric | Current (estimate) | Target (90 days) | How to measure |
|--------|-------------------|-------------------|----------------|
| Scanner completions/week | Unknown | 200+ | Database scan records |
| Email capture rate (scans -> emails) | Unknown | 50%+ | Emails collected / scans completed |
| Email -> Quick Fixes conversion | Unknown | 3-5% | Purchases / emails within 14 days |
| Email -> Full Audit conversion | Unknown | 1-2% | Purchases / emails within 30 days |
| Cold outreach reply rate | Unknown | 3-5% | Pipeline metrics |
| Cold outreach -> purchase rate | Unknown | 0.5-1% | Pipeline attribution |
| Google Ads CPC | -- | < $5 AUD | Google Ads dashboard |
| Google Ads -> scan conversion | -- | > 50% | Landing page analytics |
| Revenue/month | Unknown | $2,000+ | PayPal + manual tracking |

### Secondary metrics (check monthly)

| Metric | Target | How to measure |
|--------|--------|----------------|
| Organic search impressions | 1,000+/mo by month 3 | Google Search Console |
| Organic search clicks | 50+/mo by month 3 | Google Search Console |
| Referral purchases | 2+/mo | Ref parameter tracking |
| Email sequence open rate | 40%+ | Resend analytics |
| Email sequence click rate | 5%+ | Resend analytics |
| Quick Fixes -> Full Audit upgrade rate | 20%+ | Purchase records |
| Customer acquisition cost (blended) | < $50 | Total spend / total customers |
| LTV (average revenue per customer) | > $150 | Total revenue / total customers |

### North Star metric

**Revenue per email captured.** This single number captures the efficiency of the entire funnel from scanner to purchase. It accounts for email capture quality, sequence effectiveness, and conversion rate. Target: $5+ revenue per email captured within 30 days of capture.

### Attribution tracking needed

You are currently flying blind on attribution. Implement:
1. UTM parameters on all paid traffic (`?utm_source=google&utm_medium=cpc&utm_campaign=scanner`)
2. `ref` parameter for referral tracking
3. Source field on email capture records (organic, paid, outreach, referral)
4. "How did you hear about us?" on order form
5. Track the full path: source -> scan -> email -> purchase, stored in the database

---

## Summary: The Three Big Bets

If you execute nothing else from this document, do these three things:

1. **Build the post-scan email sequence.** You have 23,990+ scanned sites and presumably thousands of captured emails sitting in a database with no follow-up. A 7-email sequence converting even 1% of those into $67 Quick Fixes purchases would generate meaningful revenue from an asset you already own.

2. **Get indexed by Google.** Submit to Search Console this week. Create 10 programmatic SEO pages this month. 50+ by end of quarter. This is the foundation for free, compounding organic traffic that reduces dependence on the cold outreach pipeline.

3. **Lead with the $67 Quick Fixes report everywhere.** The $337 Full Audit is a big ask for cold traffic. The $67 entry point with credit toward upgrade is the classic foot-in-the-door strategy. Restructure the scanner CTAs, cold outreach offers, and ad copy around this lower-friction first purchase.

Everything else -- ads, retargeting, partnerships, referrals, content -- builds on top of these three foundations.
