# Audit&Fix SEO & Organic Content Strategy

**Date**: 2026-03-23
**Status**: Strategy document — ready for review and prioritised execution

---

## CRITICAL FINDING: Zero Google Indexation

A `site:auditandfix.com` search returns **zero results**. The site is not indexed by Google at all. This is the single highest-priority issue and must be resolved before any other SEO work has value.

**Likely causes to investigate:**
1. **Hostinger settings** — Check if Hostinger has a "discourage search engines" toggle enabled, or if the hosting `.htaccess` contains a `noindex` header
2. **X-Robots-Tag header** — The PHP config or `.htaccess` may be sending `X-Robots-Tag: noindex` in HTTP response headers (invisible in page source, only visible in server response). Test with `curl -I https://www.auditandfix.com/`
3. **Google Search Console** — Confirm the site is verified in GSC. Check the Index Coverage report for errors, excluded pages, or "Blocked by robots.txt" / "Noindex detected" statuses
4. **Domain age** — If the domain is very new and has zero backlinks, Google may simply not have discovered it yet. Submitting the sitemap in GSC would fix this within days
5. **www vs non-www** — The canonical points to `www.auditandfix.com` but the sitemap references `www.auditandfix.com` too. Verify both resolve correctly and that the non-www version 301-redirects to the www version

**Immediate action**: Run `curl -I https://www.auditandfix.com/` and `curl -I https://auditandfix.com/` from the host. Check for `X-Robots-Tag: noindex` headers. Verify Google Search Console ownership. Submit sitemap manually.

Until indexation is fixed, zero organic traffic is possible regardless of content quality.

---

## 1. Keyword Research — Clusters & Targets

Tradies do not search for "CRO audit" or "conversion rate optimisation." They search in plain language about their problems. The keyword strategy must target the language they actually use.

### Cluster A: Problem-Aware Keywords (Highest Intent)
These are business owners who know their website isn't working but don't know why.

| Keyword | Est. Monthly Volume (AU+UK+US) | Difficulty | Intent | Priority |
|---------|-------------------------------|------------|--------|----------|
| why is my website not getting enquiries | 500-1,500 | Low (15-25) | Informational → Commercial | HIGH |
| website not getting leads | 800-2,000 | Low-Med (20-30) | Informational → Commercial | HIGH |
| website not converting visitors | 500-1,200 | Medium (30-40) | Commercial | HIGH |
| why isn't my website working for my business | 300-800 | Low (10-20) | Informational | HIGH |
| website getting traffic but no enquiries | 200-600 | Low (10-15) | Commercial | HIGH |
| my website doesn't generate leads | 200-500 | Low (10-15) | Commercial | MEDIUM |
| how to get more enquiries from my website | 500-1,000 | Medium (25-35) | Commercial | HIGH |

### Cluster B: Solution-Aware Keywords (Direct Product Match)
These are people actively looking for audit/grading tools.

| Keyword | Est. Monthly Volume | Difficulty | Intent | Priority |
|---------|-------------------|------------|--------|----------|
| free website audit tool | 5,000-15,000 | High (60-70) | Transactional | MEDIUM |
| website audit for small business | 1,000-3,000 | Medium (35-45) | Commercial | HIGH |
| website grader | 8,000-20,000 | Very High (70+) | Transactional | LOW (dominated by HubSpot) |
| free website checker | 5,000-12,000 | High (55-65) | Transactional | MEDIUM |
| website score checker | 1,000-3,000 | Medium (30-40) | Transactional | HIGH |
| check my website for problems | 500-1,500 | Low (15-25) | Transactional | HIGH |
| website health check free | 1,000-2,500 | Medium (30-40) | Transactional | MEDIUM |

### Cluster C: Industry-Specific Keywords (Low Competition, High Conversion)
Nobody is creating "website audit for [industry]" pages. This is wide open.

| Keyword | Est. Monthly Volume | Difficulty | Intent | Priority |
|---------|-------------------|------------|--------|----------|
| website audit for tradies | 50-200 | Very Low (5) | Commercial | HIGH (easy win) |
| plumber website not getting calls | 100-400 | Very Low (5-10) | Commercial | HIGH |
| electrician website not working | 100-300 | Very Low (5-10) | Informational | HIGH |
| tradie website mistakes | 100-400 | Low (10-15) | Informational | HIGH |
| website for plumbers tips | 200-500 | Low (10-15) | Informational | MEDIUM |
| how to improve my tradie website | 100-300 | Very Low (5-10) | Commercial | HIGH |

### Cluster D: Comparison & Alternatives Keywords
People comparing tools. Content-led, not tool pages.

| Keyword | Est. Monthly Volume | Difficulty | Intent | Priority |
|---------|-------------------|------------|--------|----------|
| hubspot website grader alternative | 200-500 | Low (15-20) | Commercial | MEDIUM |
| best free website audit tools 2026 | 1,000-3,000 | Medium (35-45) | Commercial | MEDIUM |
| website audit tool vs seo audit tool | 100-300 | Low (10-15) | Informational | LOW |

### Cluster E: "Fix It" Keywords (Post-Diagnosis Intent)
These capture people who already know something is wrong and want fixes.

| Keyword | Est. Monthly Volume | Difficulty | Intent | Priority |
|---------|-------------------|------------|--------|----------|
| how to fix my website to get more customers | 300-800 | Low (10-20) | Commercial | HIGH |
| website conversion tips small business | 500-1,000 | Medium (25-35) | Informational | MEDIUM |
| improve website conversion rate | 2,000-5,000 | High (50-60) | Informational | LOW |
| website trust signals checklist | 300-800 | Low (15-25) | Informational | MEDIUM |
| how to add social proof to website | 500-1,200 | Medium (25-35) | Informational | MEDIUM |

---

## 2. Content Strategy — 15 Content Pieces Ranked by Impact

Every piece funnels to the free scanner as the primary conversion mechanism.

### Tier 1: Create First (Highest Impact)

**1. "Why Your Website Isn't Getting Enquiries (And How to Find Out in 30 Seconds)"**
- Target: Cluster A head term
- Format: 2,000-word guide with embedded scanner CTA
- Why: Directly matches the #1 pain point tradies Google. No strong competition from CRO-specific tools — mostly agency blog posts that don't offer a self-service tool
- CTA: "Score your site free in 30 seconds" scanner embed mid-article
- Featured snippet target: "why is my website not getting enquiries" with a numbered list of 7-10 reasons
- URL: `/blog/website-not-getting-enquiries`

**2. "10 Website Mistakes Tradies Make That Kill Leads (We Scored 23,990 Sites to Prove It)"**
- Target: Cluster C, competing directly with ServiceScale and TradieWebGuys articles
- Format: 2,500-word data-backed article using aggregate scanner data
- Why: Unique angle — you have actual data from 23,990+ scans. No other "tradie website mistakes" article cites real aggregate data. This is your unfair advantage
- CTA: "See which of these 10 mistakes YOUR site is making" → scanner
- URL: `/blog/tradie-website-mistakes`

**3. "Free Website Audit Tool for Small Business — Score Your Site in 30 Seconds"**
- Target: Cluster B solution-aware searches
- Format: This is really about optimising the existing `/scan` page for these keywords. Add 500-800 words of supporting content below the scanner explaining what it checks, how scoring works, and what the grades mean
- Why: The scanner page already exists but has thin content below the tool. Adding explanatory content gives Google something to rank
- URL: `/scan` (optimise existing page)

**4. "The Average Small Business Website Scores D+. Here's What That Means."**
- Target: Cluster A + data PR angle
- Format: 1,500-word insight piece using aggregate data (average scores by industry, common failures, what separates A-grade sites from F-grade sites)
- Why: Original data = linkable asset. Journalists and bloggers cite statistics. "The average small business website scores D+" is a quotable, shareable stat that can earn backlinks passively
- CTA: "Find out your grade" → scanner
- URL: `/blog/average-website-score`

**5. "Website Audit Checklist for Plumbers: 10 Things to Check Before You Lose Another Lead"**
- Target: Cluster C industry-specific
- Format: 1,500-word checklist-style with screenshots
- Why: Zero competition. Nobody has written a plumber-specific website audit checklist with a free tool attached. Replicate for electricians, builders, landscapers
- CTA: "Or skip the checklist — get your score instantly" → scanner
- URL: `/blog/website-audit-checklist-plumbers`

### Tier 2: Create Second (Strong Impact)

**6. "How to Get More Enquiries From Your Website (Without Spending on Ads)"**
- Target: Cluster A + E combined
- Format: 2,000-word actionable guide
- Why: High search intent, people actively looking for solutions, natural scanner CTA
- URL: `/blog/get-more-enquiries-from-website`

**7. "What Is a Website Conversion Audit? (Plain English Explanation for Business Owners)"**
- Target: Education/awareness, Cluster B adjacent
- Format: 1,200-word explainer
- Why: Defines the category in plain language, captures "what is a website audit" searches, positions Audit&Fix as the accessible option vs jargon-heavy competitors
- URL: `/blog/what-is-website-conversion-audit`

**8. "We Scored 5,000 Tradie Websites: Here's What the Best Ones Have in Common"**
- Target: Cluster C + data PR
- Format: 2,000-word data study
- Why: Positive angle (what works, not just what's broken). Builds authority with original data. Linkable
- URL: `/blog/best-tradie-websites-study`

**9. "Website Trust Signals Checklist: 12 Things Customers Look For Before They Call"**
- Target: Cluster E
- Format: 1,500-word checklist with visual examples
- Why: "Trust signals" is a growing search term, and this is directly one of the 10 scoring factors
- URL: `/blog/website-trust-signals-checklist`

**10. "HubSpot Website Grader vs Audit&Fix: Which Free Tool Should You Use?"**
- Target: Cluster D comparison
- Format: 1,500-word honest comparison
- Why: HubSpot Website Grader is the best-known free tool. A comparison page capturing "[tool] vs [tool]" searches positions Audit&Fix alongside an established brand
- URL: `/blog/hubspot-website-grader-vs-auditandfix`

### Tier 3: Create Third (Supporting Content)

**11. "How to Read Your Website Audit Report (And What to Fix First)"**
- Target: Post-purchase/post-scan support, but also captures "how to read website audit" searches
- Format: 1,200-word guide
- URL: `/blog/how-to-read-website-audit-report`

**12. "5 Website Fixes You Can Do Today Without a Developer"**
- Target: Cluster E DIY-intent
- Format: 1,500-word practical guide
- Why: Captures DIY-intent traffic, establishes goodwill, positions the full report as "if you want ALL the fixes prioritised"
- URL: `/blog/website-fixes-without-developer`

**13. "Website Audit for Electricians: Is Your Site Costing You Jobs?"**
- Target: Cluster C (duplicate format from #5 for different trade)
- Format: 1,500-word checklist
- URL: `/blog/website-audit-electricians`

**14. "What Makes a Good Call-to-Action on a Tradie Website (With Examples)"**
- Target: Cluster E, one specific scoring factor deep-dive
- Format: 1,200-word guide with before/after screenshots
- URL: `/blog/call-to-action-examples-tradie-websites`

**15. "Is Your Website Mobile-Friendly? Why 70% of Your Customers Are On Their Phone"**
- Target: Cluster E, mobile-specific
- Format: 1,200-word guide
- URL: `/blog/mobile-friendly-website-check`

### Blog Infrastructure Required

The site currently has no `/blog/` section. You need:
1. A blog listing page at `/blog/` (simple PHP template)
2. A blog post template that includes: breadcrumb nav, author bio (E-E-A-T), published/updated dates, related posts, scanner CTA block, schema markup (Article + BreadcrumbList)
3. A blog sitemap that auto-generates from a posts directory or database table
4. Internal links from the homepage and scanner page to relevant blog posts

This is a one-time PHP build that then makes publishing new content trivial.

---

## 3. Technical SEO Improvements

### Critical (Fix Immediately)

**A. Fix indexation** (see Critical Finding above)
- Verify no `X-Robots-Tag: noindex` header
- Submit sitemap in Google Search Console
- Verify GSC ownership
- Expected timeline: Indexed within 3-7 days of fix

**B. Expand the sitemap**
- Current: 2 URLs (homepage + /scan)
- Needed: Include all public pages, blog posts, any landing pages
- Add `<lastmod>` dates to all entries
- Auto-generate from content changes

**C. Add a blog section** (as described above)
- `/blog/` listing page
- Article template with proper schema (Article, BreadcrumbList, Author)
- Blog sitemap section

### Important (Do Soon)

**D. Hreflang implementation review**
- Current: `?lang=xx` parameter-based hreflang
- Issue: Google may treat these as duplicate content or low-quality thin pages if the translations are auto-generated. If translations are machine-translated only, consider either:
  - Adding `noindex` to non-English versions (keep hreflang for UX but don't try to rank them)
  - Or investing in quality translations for top markets (DE, ES, FR) and noindexing the rest
- The 14-language approach is ambitious for a solo operation. Focus indexation on English (AU/UK/US markets) first

**E. Internal linking architecture**
- Currently: 2 pages, minimal internal links
- With blog: Every blog post should link to `/scan` and to the homepage pricing section
- Create contextual cross-links between related posts (e.g., "tradie website mistakes" links to "plumber audit checklist")
- Add a "Related posts" section to each blog post template

**F. Page speed / Core Web Vitals**
- The site appears to preload the hero background image (good)
- Verify Core Web Vitals pass in Google PageSpeed Insights for both mobile and desktop
- Key checks: hero image format (use WebP/AVIF), CSS loading strategy, JavaScript defer/async, font loading

### Nice to Have

**G. FAQ schema expansion**
- The homepage has FAQ schema, but blog posts should also have FAQ schema for any Q&A-format sections
- Target People Also Ask boxes with concise 40-60 word answers followed by detail

**H. Breadcrumb schema**
- Add breadcrumb structured data: Home > Blog > [Post Title]
- Improves SERP appearance with breadcrumb trails

**I. Author schema for E-E-A-T**
- Create an author entity with credentials
- Link from Article schema to author profile
- This matters more for YMYL-adjacent content (business advice)

---

## 4. Local SEO Opportunities

### Recommendation: YES, but programmatically, not manually

Creating location-specific landing pages makes sense because:
1. "Website audit for [trade] in [city]" has near-zero competition
2. You have scan data that can be segmented by location
3. Local service businesses search with location qualifiers

### Approach: Programmatic Location Pages (See Section 6)

Do NOT manually create pages like "website-audit-plumbers-sydney.php". Instead, build a template system:

**Template**: `/audit/{trade}/{city}`
**Example**: `/audit/plumber/sydney`, `/audit/electrician/melbourne`, `/audit/builder/london`

**Content per page**:
- H1: "Website Audit for [Trades] in [City]"
- Paragraph: "We've scored [X] [trade] websites in [city]. The average score is [X] (grade [Y]). Here's what we found."
- Top 3 issues found for that trade/city combination (from aggregate scan data)
- Industry benchmark comparison
- CTA: "Score your [trade] website free" → scanner
- Testimonial from that industry if available

**Important**: Each page must have enough unique, valuable content to avoid thin-content penalties. The data-driven angle (real aggregate stats from your scans) is what makes this viable. Generic template pages with just city names swapped will not rank and may trigger quality filters.

### Google Business Profile
- If you want to appear in local pack results, create a Google Business Profile
- Category: "Website Audit Service" or "Marketing Consultant"
- This is low-effort and can generate local visibility quickly

---

## 5. Link Building Tactics (Solo-Friendly)

Ranked by effort-to-value ratio for a single operator.

### Tactic 1: Data PR (Highest ROI)
**Effort**: Medium | **Value**: Very High | **Sustainability**: Excellent

You have original data from 23,990+ website scans. This is your most powerful link building asset.

**Execution**:
1. Write up findings as a "State of Small Business Websites" report (use content piece #4 or #8 as the base)
2. Create 2-3 quotable statistics:
   - "The average small business website scores D+ on conversion readiness"
   - "Only 12% of tradie websites have a mobile-friendly contact form"
   - "73% of small business websites are missing basic trust signals"
3. Submit to HARO/Connectively/Qwoted/SourceBottle when journalists seek small business or web design statistics
4. Pitch to small business media (SmartCompany, Flying Solo, StartupDaily in AU; Business Insider, Forbes small biz sections)
5. Target trade publications (Master Builders Association newsletters, trade body blogs)

**Why this works**: Journalists need statistics to cite. Original data from 23,990 sites is more credible than made-up survey numbers. Every citation = a backlink.

### Tactic 2: Unlinked Brand Mentions (Lowest Effort)
**Effort**: Very Low | **Value**: Medium | **Sustainability**: Ongoing

When 333Method outreach generates replies, conversations, or even just awareness, people may mention "Audit&Fix" online without linking. Set up Google Alerts for:
- "auditandfix"
- "audit and fix" + website
- "audit&fix"

When mentions appear without links, send a polite email asking for a link.

### Tactic 3: Resource Page Inclusion (Low Effort)
**Effort**: Low | **Value**: Medium | **Sustainability**: Good

Find "best free website audit tools" or "small business resources" pages and submit Audit&Fix for inclusion.

Target pages like:
- [RoastMyWeb's website grader tools comparison](https://www.roastmyweb.com/blog/website-grader)
- [MyBizGrade's website audit tools for small businesses](https://www.mybizgrade.com/blog/website-audit-tools-small-business)
- [ScoreCraft's free audit tools comparison](https://scorecraft.ai/blog/free-website-audit-tools-compared)
- Trade association "digital resources" pages

### Tactic 4: Guest Posts on Trade/Small Business Blogs (Medium Effort)
**Effort**: Medium | **Value**: High | **Sustainability**: Good

Write guest posts for sites like:
- TradieSpark, ServiceScale, TradieWebGuys (AU trade marketing)
- SmallBizMKE, FitSmallBusiness (US small business)
- Afford Web Design (UK small business)

Pitch angle: "I scanned 24,000 small business websites — here's what I found." Unique data makes you a desirable guest author.

### Tactic 5: Tool Directories & Listings (One-Time Effort)
**Effort**: Very Low | **Value**: Low-Medium | **Sustainability**: Permanent

Submit the free scanner to:
- Product Hunt (launch event)
- AlternativeTo (as alternative to HubSpot Website Grader)
- G2, Capterra (if applicable)
- Free tool directories and "best of" lists

---

## 6. Programmatic SEO Opportunities

With 23,990+ scored sites and data across industries/locations, there are significant programmatic opportunities.

### Opportunity A: Industry Benchmark Pages
**Template**: `/benchmarks/{industry}`
**Examples**: `/benchmarks/plumbers`, `/benchmarks/electricians`, `/benchmarks/restaurants`, `/benchmarks/dentists`

**Content**:
- Average score for that industry
- Grade distribution (what % get A, B, C, D, F)
- Most common issues in that industry
- How this industry compares to overall average
- CTA: "See how your [industry] website compares"

**Scale**: 20-50 industry pages
**Data requirement**: Minimum 50 scans per industry to be statistically meaningful. Filter scan data by industry classification.

### Opportunity B: Location + Industry Matrix Pages
**Template**: `/benchmarks/{industry}/{location}`
**Examples**: `/benchmarks/plumbers/sydney`, `/benchmarks/electricians/melbourne`

**Content**: Same as above but location-filtered
**Scale**: 200-500 pages (20 industries x 10-25 cities)
**Data requirement**: Minimum 10-20 scans per industry/location combo. Only generate pages where you have sufficient data.

**Risk**: Thin content if data is sparse. Only create pages where you have enough scans to tell a real story.

### Opportunity C: "Website Score" Report Cards
**Template**: `/score/{domain}` (public mini-reports for scanned sites)

**CAUTION**: This is powerful but sensitive. Publishing scores for specific businesses without consent could create legal/reputation issues. Consider:
- Only show aggregate/anonymised data publicly
- Or make individual scores private (email-gated, as currently)
- Or allow businesses to opt-in to a public listing

### Opportunity D: Monthly/Quarterly "State of" Reports
**Template**: `/reports/state-of-small-business-websites-q1-2026`

**Content**: Trend data over time — are small business websites getting better or worse? New pages each quarter.
**Value**: Linkable, shareable, media-pitchable. Creates a recurring reason for journalists to cite you.

### Implementation Priority
1. Start with Opportunity A (industry benchmarks) — 10 pages for top industries
2. Expand to Opportunity B where data supports it
3. Create Opportunity D as quarterly content
4. Skip Opportunity C unless you build an opt-in mechanism

---

## 7. Competitor Analysis & Differentiation

### Direct Competitors (Free Audit/Grader Tools)

| Competitor | Positioning | Pricing | Weakness (Your Advantage) |
|-----------|------------|---------|--------------------------|
| **HubSpot Website Grader** | Generic website grading (performance, mobile, SEO, security) | Free (lead gen for HubSpot) | Not conversion-focused. Gives a score but doesn't tell you WHY you're not getting enquiries. Too generic for tradies. |
| **ScoreCraft.ai** | AI-powered audit, 90+ signals, 13 categories | Free first audit, $9/mo | Broad focus (SEO, security, AI visibility). Not specifically for conversion/leads. Data overload vs plain English. |
| **SEOptimer** | SEO-focused website audit + PDF reports | Free basic, $19/mo+ for white-label | SEO-only, not conversion. Agency-focused, not for the business owner. |
| **My Web Audit** | White-label audit reports for agencies | $59-$199/mo | Made for agencies to sell to clients, not for SMBs to use directly. Expensive. |
| **Google PageSpeed Insights** | Performance + Core Web Vitals only | Free | Technical only. Meaningless to a plumber. Doesn't mention leads, enquiries, or trust. |

### Indirect Competitors (Agency Blog Content)

| Competitor | Content | Weakness |
|-----------|---------|----------|
| **ServiceScale.com.au** | "10 Website Mistakes Tradies Make" | Great content, but no free tool. CTA is "book a call" = high friction. |
| **TradieSpark** | "Why Your Website Isn't Getting Leads" | Same — blog content with agency upsell, no self-service tool. |
| **TradieWebGuys** | "Common Website Mistakes Tradies Make" | Blog-only, agency model. |

### Your Differentiation

**Audit&Fix occupies a unique position**: It's the only tool that combines:
1. **Conversion-focused scoring** (not generic SEO or performance)
2. **Plain English results** (not 200 technical data points)
3. **Industry-specific relevance** (tradies, local services)
4. **Self-service + professional report** (free scan to qualify, paid report for depth)
5. **Real aggregate data** from 23,990+ scans (nobody else publishes this)

**Positioning statement for SEO content**:
"The only website audit tool built for small business owners who want more enquiries, not more data."

### Competitive Content Gaps to Exploit
1. Nobody combines a **free scanner tool + tradie-specific content**. Agency blogs have content but no tool. Tools are generic, not industry-specific.
2. Nobody publishes **aggregate benchmark data by industry**. "The average plumber website scores D+" is a stat nobody else can claim.
3. The comparison space ("HubSpot Website Grader vs X") is ripe — most comparison content is written by generic review sites, not by a competing tool with a genuine angle.

---

## 8. Implementation Roadmap

### Week 1-2: Fix Foundations
- [ ] Diagnose and fix Google indexation issue
- [ ] Submit sitemap to Google Search Console
- [ ] Verify www/non-www redirect
- [ ] Run PageSpeed Insights and fix any Core Web Vitals failures
- [ ] Add `<lastmod>` to sitemap entries

### Week 3-4: Build Blog Infrastructure
- [ ] Create `/blog/` listing page (PHP template)
- [ ] Create blog post template with Article schema, breadcrumbs, author bio, scanner CTA block
- [ ] Create blog sitemap generation (auto-add new posts)
- [ ] Add blog navigation link to site header
- [ ] Add internal links from homepage to blog (footer or "Resources" section)

### Week 5-8: Publish Tier 1 Content
- [ ] Publish content piece #1 (website not getting enquiries)
- [ ] Publish content piece #2 (tradie website mistakes with data)
- [ ] Optimise `/scan` page with supporting content (#3)
- [ ] Publish content piece #4 (average score D+ data report)
- [ ] Publish content piece #5 (plumber audit checklist)
- [ ] Submit all new URLs to GSC for indexing

### Week 9-12: Tier 2 Content + First Programmatic Pages
- [ ] Publish content pieces #6-10
- [ ] Build programmatic template for industry benchmark pages
- [ ] Launch first 10 benchmark pages (top industries by scan volume)
- [ ] Submit to 5-10 "best audit tools" resource pages
- [ ] Pitch data PR to 3-5 small business publications

### Month 4-6: Scale & Measure
- [ ] Publish Tier 3 content (#11-15)
- [ ] Expand benchmark pages to 20+ industries
- [ ] Add location matrix pages where data supports
- [ ] Track keyword positions, organic traffic, scanner conversions
- [ ] Publish first quarterly "State of" report
- [ ] Continue resource page and data PR outreach

### Ongoing
- [ ] Publish 2-4 blog posts per month
- [ ] Monitor Search Console for indexation issues
- [ ] Track and respond to HARO/journalist queries
- [ ] Update benchmark data as scan volume grows
- [ ] A/B test blog CTA placements for scanner conversion rate

---

## 9. Success Metrics & Targets

| Metric | Baseline (Now) | 3-Month Target | 6-Month Target | 12-Month Target |
|--------|---------------|----------------|----------------|-----------------|
| Indexed pages | 0 | 15-20 | 40-60 | 100+ |
| Organic sessions/month | 0 | 200-500 | 1,000-3,000 | 5,000-15,000 |
| Keywords in top 10 | 0 | 5-10 | 20-40 | 50-100 |
| Scanner scans from organic | 0 | 20-50/mo | 100-300/mo | 500-1,500/mo |
| Referring domains | ~0 | 5-10 | 15-30 | 40-80 |
| Featured snippets | 0 | 1-2 | 3-5 | 8-15 |

---

## 10. Quick Reference: Top 5 Actions by ROI

1. **Fix indexation** — zero cost, infinite ROI (currently wasting all existing page value)
2. **Add content to /scan page** — 2 hours of work, captures "free website audit tool" searches
3. **Publish "website not getting enquiries" article** — targets highest-intent keyword cluster with near-zero competition from tool-based competitors
4. **Build 10 industry benchmark pages** — programmatic, data-driven, unique data nobody else has
5. **Submit scanner data stats to 3 journalist query services** — earns backlinks from authority sites with minimal ongoing effort

---

## Sources & Competitive Intelligence

- [ServiceScale — 10 Website Mistakes Tradies Make](https://servicescale.com.au/10-hidden-website-mistakes-that-are-killing-your-leads/)
- [TradieSpark — Why Your Website Isn't Getting Leads](https://tradiespark.com/why-your-website-isnt-getting-leads/)
- [TradieWebGuys — Common Website Mistakes](https://www.tradiewebguys.com.au/common-website-mistakes-tradies-make/)
- [MyWebAudit — Agency Audit Tool Pricing](https://www.mywebaudit.com/pricing)
- [ScoreCraft.ai — Free Audit Tool Competitor](https://scorecraft.ai)
- [RoastMyWeb — Website Grader Tools Comparison](https://www.roastmyweb.com/blog/website-grader)
- [MyBizGrade — Audit Tools for Small Business](https://www.mybizgrade.com/blog/website-audit-tools-small-business)
- [HubSpot Website Grader](https://website.grader.com/)
- [SEOptimer](https://www.seoptimer.com/)
- [Webzun — Tradesman Website UK](https://www.webzun.com/tradesman-website-uk-why-uk-tradespeople-lose-leads-without-a-website/)
