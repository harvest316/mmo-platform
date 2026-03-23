# Google Ads -- Weekly Optimisation Checklist (15 Minutes)

Run this every Monday morning. Set a calendar reminder.

---

## 1. Search Terms Review (5 minutes)

Go to: Campaigns > Insights and reports > Search terms

**Action:** Review every search term that triggered your ads in the past 7 days.

- Add irrelevant terms as negative keywords immediately (use the negative-keywords.csv format for your records)
- Look for patterns: if you see multiple terms with "free" or "DIY", add those as account-level negatives
- Star any high-performing search terms you did not explicitly target -- consider adding them as exact match keywords

**Red flag:** If more than 20% of clicks come from irrelevant search terms, your negative keyword list needs expansion. Pause and do a thorough negative keyword session.

---

## 2. Performance Check (3 minutes)

Go to: Campaigns > Overview

Check these numbers for the past 7 days:

| Metric | Healthy Range | Action if Outside |
|--------|--------------|-------------------|
| CTR (Search) | > 3% | Below 3%: review ad copy, consider pausing low-CTR ads |
| Avg. CPC | < A$2.50 | Above A$2.50: lower max CPC bids by A$0.20 |
| Conv. rate (email capture) | > 5% | Below 5%: landing page issue, not an ads issue |
| Cost/conversion | < A$25 | Above A$25: pause lowest-performing keywords |
| Impression share | > 40% | Below 40%: budget-limited, acceptable at A$200/mo |
| Budget spent | 90-100% of daily | Below 90%: raise bids slightly or add keywords |

---

## 3. Keyword Performance (3 minutes)

Go to: Keywords > Search keywords

Sort by: Cost (highest first)

**Pause if a keyword has:**
- Spent > A$15 with zero conversions
- CTR < 1% after 200+ impressions
- Avg. position consistently > 4 (not showing on first page)

**Increase bid if a keyword has:**
- Conv. rate > 10% but impression share < 50%
- Consistently converting at below-target CPA
- Avg. position 3-4 (could benefit from top-of-page placement)

**Decrease bid if a keyword has:**
- CPA > 2x target but still converting occasionally
- Avg. CPC higher than the value it generates

---

## 4. Ad Copy Check (2 minutes)

Go to: Ads > Assets (or Ads section in each ad group)

- Check asset performance ratings (Best, Good, Low). Replace any "Low" performing headlines or descriptions
- If an RSA has been running 3+ weeks with "Low" ad strength, create a new RSA variant and pause the underperformer
- Compare RSA performance across the ad group -- keep 2-3 active RSAs, pause the weakest

---

## 5. Budget Pacing (2 minutes)

Go to: Campaigns > Columns > add "Budget" related columns

- Is any campaign consistently hitting its daily budget before 3 PM? If yes, either increase its budget (take from an underspending campaign) or lower bids to spread clicks across the day
- Is any campaign consistently underspending? Reallocate that budget to better-performing campaigns

**Monthly budget tracker:**

```
Week 1: $___  (target: ~$50)
Week 2: $___  (target: ~$100 cumulative)
Week 3: $___  (target: ~$150 cumulative)
Week 4: $___  (target: ~$195 cumulative)
Buffer: $5
```

---

## Decision Triggers

### Pause a keyword when:
- A$20+ spend, zero conversions, 2+ weeks running
- CTR < 0.5% after 500+ impressions

### Pause an ad group when:
- All keywords in the group are paused or have < 1% CTR
- Zero conversions after A$40+ spend

### Pause a campaign when:
- Zero conversions after A$60+ spend across 3+ weeks
- This should not happen with the Website Audit campaign -- if it does, the problem is the landing page, not the ads

### Increase budget when:
- CPA is below target AND impression share loss (budget) > 30%
- This means you are leaving conversions on the table

### Decrease budget when:
- CPA is 2x+ above target for 2 consecutive weeks
- Reallocate to better-performing campaigns

### Switch to automated bidding when:
- 30+ conversions in the past 30 days across the account
- Conversion tracking has been verified and stable for 2+ weeks
- Switch to Maximise Conversions first (no target), then add tCPA after 2 more weeks

---

## Monthly Deep Dive (30 minutes, first Monday of each month)

In addition to the weekly checklist:

1. **Audience review:** Check observation audiences -- any segment converting 2x+ above average? Add bid adjustment.
2. **Device review:** Compare desktop vs mobile CPA. If mobile CPA is 2x+ desktop, add -25% mobile bid adjustment.
3. **Day/hour review:** Check day-of-week and hour-of-day performance. Adjust ad schedule if weekends or evenings consistently underperform.
4. **Geographic review:** Check state-level performance. If a state has 2x+ CPA, consider reducing bids or excluding.
5. **Competitor check:** Review Auction Insights (Campaigns > Auction insights). Note any new competitors appearing.
6. **Landing page:** Check scan completion rate in GA4. If declining, investigate page speed or UX issues.
7. **Ad extension review:** Check sitelink, callout, and structured snippet performance. Replace underperformers.
