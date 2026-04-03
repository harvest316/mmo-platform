# Product Strategy: AI Autoresponder for Local Service Businesses

**Author:** Alex (PM)  
**Date:** 2026-04-02  
**Status:** Draft  
**Version:** 1.1  
**Domain:** contactreplyai.com (purchased 2026-04-02 by Gary, the product owner)

---

## Executive Summary

Local service businesses (plumbers, electricians, cleaners, landscapers, pest control) lose $45,000-$120,000 per year to missed calls and slow inquiry responses. 62% of inbound calls go unanswered; 80% of callers who reach voicemail hang up without leaving a message. These businesses need an always-on AI responder that handles inbound inquiries across SMS, email, web chat, and WhatsApp with human-quality responses -- while the owner stays on the tools.

The market is heating up but fragmented. Competitors either cost too much (Podium at $599/month, Smith.ai at $300/month for human agents), focus only on phone calls (My AI Front Desk, Dialzara), or solve a broader problem poorly (CRM + reviews + messaging bundled together). There is a clear gap for a purpose-built, multi-channel AI responder priced for solo operators at $49-149/month.

We have a structural advantage: the existing customer base is already local SMBs who we have proven we can reach, engage, and convert. This product is a natural cross-sell -- "we found problems with your website; we can also make sure you never miss a lead."

**Recommendation:** Build. Start with SMS + email + web chat in AU market. Target first 10 customers from existing pipeline by Q3 2026. Expand to US/UK by Q4.

---

## 1. Target Persona Deep-Dive

### 1.1 Primary Persona: "Dave the Solo Tradie"

**Demographics:**
- Solo operator or 1-2 employees (no receptionist, no office admin)
- Age 28-55, male-skewed (75%+), based in suburban/regional areas
- Trades: plumbing, electrical, pest control, cleaning, landscaping, fencing, roofing
- Revenue: $80K-$350K AUD / $60K-$250K USD per year
- Works 6-7 days/week, 8-12 hours/day on job sites

**Tech Comfort:**
- Owns a smartphone (iPhone or Samsung), uses it constantly for calls and photos
- Comfortable with: text messaging, Facebook, Google Maps, bank app, trade-specific apps (ServiceM8, Tradify, Xero)
- Uncomfortable with: anything that requires a desktop, multi-step configuration, "dashboards," integrations setup
- Will pay for software that visibly saves time -- ServiceM8 ($6-55/month), Xero ($25/month), Tradify ($35/month) prove this
- Will NOT pay for software they have to "learn" -- if it takes more than 10 minutes to understand, they abandon it

**Current Inquiry Handling (the pain):**
1. Phone rings while they are under a house / on a roof / driving -- voicemail or missed call
2. Texts back 2-6 hours later (if they remember) -- customer has already called 2 other tradies
3. Email goes unseen for 1-3 days -- customer assumes they are too busy or don't care
4. Website contact form submissions sit in an inbox nobody checks
5. Facebook/Google messages are checked weekly at best
6. Partner/spouse sometimes answers the phone or responds to texts informally

**What would make them trust an AI:**
- Seeing it work first (demo before signup -- not a video, a live interaction)
- Knowing they can see every message in real-time and override instantly
- Understanding that it answers the way THEY would answer (not generic corporate speak)
- A "kill switch" they can hit from their phone with one tap
- Hearing from another tradie who uses it (word of mouth is everything)
- It never makes a promise they can't keep (no booking without their approval)

**Willingness to Pay:**
- $29-49/month for a basic tool (comparable to ServiceM8, Tradify)
- $79-149/month for something that demonstrably captures leads they would otherwise lose
- The value framing that works: "How much is one missed job worth? $300? $500? This pays for itself with one captured lead per month."
- Setup fees are a hard no -- the tradie market has been conditioned to expect $0 setup by ServiceM8, Xero, and every other SaaS they use
- Annual discount appeals: 10-15% off for paying upfront (tradies think in annual cash flow)

### 1.2 Secondary Persona: "Sarah the Office Manager"

**Demographics:**
- Small team (3-10 staff), dedicated admin person who handles phones + scheduling
- Often the owner's partner, a part-time employee, or a bookkeeper who also does dispatch
- Already overwhelmed with quoting, invoicing, scheduling, and answering calls

**Why she cares:**
- She is the one answering 40+ calls/day and losing evenings to email
- AI handles the first response; she steps in only for complex inquiries
- Frees her to do the work that actually needs a human (scheduling, quoting, follow-up)

**Tech comfort:** Higher than Dave. She is the one who set up ServiceM8, manages the Facebook page, and checks Google reviews. She will configure the AI, set up the FAQ, and monitor the dashboard.

### 1.3 Tertiary Persona: "Mike the Multi-Tradie Owner"

**Demographics:**
- 5-15 employees across multiple trucks/teams
- $500K-$2M revenue
- Has tried answering services before (Ruby, AnswerConnect) and been burned by cost or quality
- Needs routing logic: which team, which service area, which urgency level

**This persona is v2.** Do not design for Mike in the MVP. Win Dave and Sarah first, then graduate features upward.

---

## 2. User Journey / Onboarding

### 2.1 Principle: Zero-Friction, Value-First

The tradie will not read documentation. They will not watch a tutorial video. They will not attend a webinar. The onboarding must feel like texting a mate who happens to know about their business.

### 2.2 Acquisition-to-Activation Flow

```
Step 1: DISCOVER
  - Cross-sell from Audit&Fix ("We audit your website. Want us to make sure
    you never miss a lead too?")
  - Google Ads: "Never miss a customer call again"
  - Facebook/Instagram: Video ad showing a plumber on a roof, phone buzzing,
    AI responding
  - Word of mouth: Referral program ($50 credit per referred tradie)

Step 2: EXPERIENCE (before signup)
  - Landing page has a LIVE demo bot
  - "Text 0400-XXX-XXX and pretend you need a plumber"
  - Visitor texts the demo number, AI responds as "Dave's Plumbing" within
    5 seconds
  - Visitor sees how natural the conversation is
  - CTA: "Want this for your business? Set up in 5 minutes."

Step 3: SIGNUP (< 2 minutes)
  - Email + business name + trade type (dropdown) + phone number
  - No credit card required for 14-day trial
  - Instant: "Great, you're set up. Let's teach the AI about your business."

Step 4: BUSINESS PROFILE (< 5 minutes, conversational)
  - NOT a form. A chat conversation:
    "What services do you offer?"
      --> plumber types "blocked drains, hot water, general plumbing"
    "What areas do you service?"
      --> "Sydney inner west, up to 30km from Marrickville"
    "What are your hours?"
      --> "Mon-Sat 7am-5pm, emergency 24/7"
    "What's your typical response time for a quote?"
      --> "Same day usually"
    "What should I say if someone asks about price?"
      --> "Tell them I do free quotes on site"
    "Anything I should NEVER say?"
      --> "Don't promise same-day for hot water installs"
  - Each answer trains the AI's persona
  - Can be done via text message (SMS onboarding), not just web

Step 5: CHANNEL SETUP (progressive, not all-at-once)
  - Day 1: SMS autoresponder activated (forward your number, or we
    provision one)
  - Day 3: Email forwarding set up (forward your inbox, or connect
    Gmail/Outlook)
  - Day 7: Web chat widget added to website (one line of JS, or we add it
    during their Audit&Fix)
  - Day 14: WhatsApp Business connected (guided setup)
  - Each channel is opt-in. Start with the one that hurts most (usually
    SMS/phone).

Step 6: FIRST WIN (< 24 hours)
  - AI responds to first real inquiry
  - Owner gets push notification: "New lead! AI responded to John about a
    blocked drain. Tap to review."
  - Owner sees the conversation, sees it was handled well
  - This is the activation moment. Everything before this is setup.
    Everything after is retention.
```

### 2.3 Day 1 / Week 1 / Month 1

| Timeframe | User Experience | System Goal |
|-----------|----------------|-------------|
| Day 1 | SMS autoresponder live. Sees first AI response. Gets push notification. | Activation: user sees AI handle one real inquiry |
| Day 3 | Reviews AI conversations. Corrects one response ("I wouldn't say it like that"). AI learns. | Feedback loop established |
| Day 7 | Email or web chat added as second channel. Weekly summary: "AI handled 12 inquiries this week, 8 were qualified leads." | Second channel activation |
| Day 14 | Trial ending. Decision point. Value shown: "Without AI, you would have missed 5 leads worth ~$2,500." | Conversion to paid |
| Month 1 | Settled into routine. Checks app 2-3x/day. Has overridden AI twice. Told one mate about it. | Retention + referral seed |

### 2.4 How We Collect Business Info (FAQ / Training Data)

The conversational onboarding captures 80% of what the AI needs. The remaining 20% comes from:

1. **Existing website content** -- if they have an Audit&Fix scan, we already have their site content, services page, about page, and contact info. Pre-populate the AI's knowledge base from this.
2. **Google Business Profile** -- with permission, pull their services, hours, reviews, and Q&A. Reviews are gold: they show how real customers describe the business.
3. **Progressive learning** -- every time the owner corrects the AI ("I wouldn't say that"), the correction is logged and the AI adapts. This is the most valuable training data and it arrives organically.
4. **Template library** -- pre-built response patterns for common trades: "How much does a blocked drain cost?" has a good answer template for plumbers that the AI can personalise.

---

## 3. Feature Prioritization (MoSCoW)

### 3.1 MVP (Weeks 1-8) -- Prove the Core Value

**Must Have:**
- AI responds to all inbound channels within 60 seconds (p95) — see architecture Section 6
- Trust graduation UX: Stage 1 (approve before send) → Stage 2 (auto-send after 5-min timer) → Stage 3 (fully automatic) — see architecture Section 12
- Conversational onboarding (business profile via SMS or web chat — not a form)
- PWA dashboard showing all conversations, sorted by recency
- Push notifications for: new inquiries, AI drafts awaiting approval (Stage 1/2), low-confidence replies
- Owner can reply directly from the dashboard (AI pauses when human takes over)
- Owner "pause" button (disable AI for X hours with one tap)
- Emergency safety response framework: keyword detection → templated Tier 1/2/3 response, never free-form LLM — see architecture Section 14
- Business hours logic (after-hours: different response or human-fallback message)
- Conversation context maintained (AI remembers the thread; cross-channel if same customer)
- Escalation trigger: AI recognises when to say "Let me get [owner name] to call you directly"
- Feature waitlist collection during onboarding (calendar, CRM, phone, Facebook, Google) — see architecture Section 17

**Should Have (MVP stretch):**
- Web chat widget (JS embed)
- Weekly summary email to owner ("This week: 18 inquiries, 12 qualified, 3 booked")
- Basic lead qualification (captures: name, service needed, address, urgency, phone number)

**Could Have (v1.1):**
- WhatsApp Business integration
- Google Business Messages integration
- AI-generated quote templates
- Integration with ServiceM8 / Tradify / Fergus for job creation

**Won't Have (this phase):**
- Voice call handling (separate product, separate tech stack, much harder)
- Booking/calendar integration (requires deep integration with their scheduling tool)
- Multi-location support
- Team routing logic
- CRM functionality
- Review solicitation
- Payment collection

### 3.2 v1.0 (Months 3-4) -- Channel Expansion + Intelligence

- WhatsApp Business API integration
- Facebook Messenger integration
- Web chat widget with customisable appearance
- Lead scoring (hot/warm/cold based on conversation signals)
- Conversation tagging (service type, urgency, location)
- Monthly ROI report: "AI captured X leads worth estimated $Y"
- Response quality dashboard (owner ratings on AI responses)
- Multi-language detection (respond in customer's language if supported)

### 3.3 v2.0 (Months 6-9) -- Booking + Multi-User

- Calendar integration (Google Calendar, ServiceM8, Tradify)
- AI can book tentative appointments (owner confirms via app)
- Team management (multiple users, role-based access)
- Service area routing (assign inquiries by geography)
- Quote generation (AI drafts a quote based on conversation, owner approves)
- API for custom integrations
- White-label option for agencies

### 3.4 Channel Launch Order (and why)

| Priority | Channel | Rationale |
|----------|---------|-----------|
| 1 | SMS | Tradies live on their phone. SMS is the channel they miss most and customers expect fastest. Twilio integration proven in our stack. |
| 2 | Email | Low cost per message. Forwarding setup is frictionless. Many web form submissions arrive as email. |
| 3 | Web Chat | Captures leads while they are on the website. One JS snippet. Visual proof of AI quality (prospects see it working). Also serves as live demo. |
| 4 | WhatsApp | Dominant in AU/UK for service businesses. WhatsApp Business API is now free for service replies within 24 hours. |
| 5 | Facebook Messenger | Business pages get messages. Lower priority because FB engagement is declining for local services. |
| 6 | Google Business Messages | Google is deprecating/changing this frequently. Wait for stability. |

---

## 4. Pricing Strategy

### 4.1 Market Context

| Competitor | Price | What You Get | Our Take |
|-----------|-------|-------------|----------|
| Podium | $599/month | Messaging + reviews + payments. Bloated. | Way too expensive for solo tradies. Enterprise tool sold to SMBs. |
| Smith.ai (AI) | $95/month (60 calls) | AI phone answering only | Phone only. Per-call pricing creates anxiety. |
| Smith.ai (Human) | $300/month (30 calls) | Human receptionist | Good quality but 10x our target price for tradies. |
| LeadTruffle | $229-629/month | AI lead capture, web forms, missed calls | US-focused, expensive, feature-heavy. |
| Broadly | ~$300/month | Web chat, reviews, payments | Reviews-focused, not response-focused. Expensive. |
| Dialzara | ~$50/month | AI phone receptionist | Phone only. US-focused. |
| My AI Front Desk | ~$65/month | AI phone answering | Simple, phone-focused. No multi-channel. |
| Trillet | $29/month | AI phone answering | Cheapest but phone only. No multi-channel. |
| AI Trades (AU) | Unknown | Quoting, scheduling, invoicing | Operations tool, not response tool. Different problem. |

### 4.2 Recommended Pricing

**Principle:** All-in-one, not per-channel. Tradies hate surprises. One price, all channels included. The upgrade tiers are about volume and features, not channel access.

| Plan | Monthly (AUD) | Monthly (USD) | Monthly (GBP) | What's Included |
|------|---------------|---------------|---------------|-----------------|
| **Starter** | $49 | $39 | $29 | SMS + Email + Web Chat. Up to 100 AI conversations/month. 1 user. Business hours responses. |
| **Pro** | $99 | $79 | $59 | All channels (+ WhatsApp, FB Messenger). Up to 500 conversations/month. 24/7 responses. Lead qualification. Weekly reports. |
| **Business** | $199 | $149 | $119 | Unlimited conversations. Up to 5 users. Calendar integration. Quote templates. Priority support. Monthly ROI report. |

**Annual discount:** 2 months free (17% off) on all plans.

**No setup fee.** (DR-082 established this pattern for 2Step -- waiving setup fees is proven effective for cold-approached local businesses.)

**Billing: PayPal subscriptions only.** No Stripe. PayPal plan managed under Jason's account initially; migrate to Gary's account when MRR covers infrastructure costs (~$300 AUD/mo). See TODO.md.

**Overage:** $0.50 per conversation over the plan limit, billed at end of month. Soft cap: notify at 80%, auto-upgrade suggestion at 100%. Never cut off a customer mid-conversation.

### 4.3 Founding Member Pricing (Beta Launch)

Gary's existing page uses $197/mo. For the beta launch, we are introducing Option D pricing:

| Tier | Founding Member Price | Standard Price (after beta) |
|------|-----------------------|-----------------------------|
| Pro (all features) | **$99 AUD/mo** (locked forever) | $197 AUD/mo |

- **Founding member rate ($99/mo) is locked for life** — never increases as long as subscription is active.
- **Standard rate ($197/mo)** applies to new signups after the founding member period closes (first 100 customers).
- The $197/mo rate is Gary's existing PayPal plan ID `P-4PA59369BG8691002NG5IFFY` (already live).
- A new PayPal plan at $99/mo needs to be created for founding members.
- The landing page clearly shows both prices: "Limited founding member rate: $99/mo (normally $197/mo). First 100 customers only."

### 4.4 Free Trial

- **7-day free trial, no credit card required.** (Gary's existing page uses 7 days — keep consistent.)
- Trial includes all features so they experience the full value.
- On day 5: "Your trial ends in 2 days. AI has handled X inquiries worth an estimated $Y. [Claim founding member rate: $99/mo]"
- On day 7: AI pauses. Owner gets: "Your AI is paused. Your customers are going to voicemail. [Reactivate now — $99/mo founding member rate]."

### 4.5 Why Not Freemium

- The cost of running Claude API on every conversation makes a free tier unprofitable at any meaningful volume
- A free tier attracts tire-kickers, not paying tradies
- The 7-day trial with no card is the compromise: zero risk to try, but clear conversion moment

### 4.6 Unit Economics (Founding Member at $99 AUD ~$64 USD)

| Item | Cost per Month (est.) |
|------|----------------------|
| Claude API — Haiku classifier (200 calls x $0.001) | ~$0.20 |
| Claude API — Sonnet replies (160 calls x $0.007 avg) | ~$1.12 |
| Claude API — Opus complex replies (40 calls x $0.05 avg) | ~$2.00 |
| Twilio SMS (inbound free, outbound ~100 messages x $0.05 AU) | ~$5-8 |
| Email sending (Resend, ~100 emails) | ~$0.04 |
| WhatsApp Business API (service replies free within 24h) | ~$0 |
| Infrastructure (shared, amortised) | ~$2-5 |
| **Total COGS (AUD)** | **~$14-25 AUD** |
| **Revenue (founding member, AUD)** | **$99 AUD** |
| **Gross margin** | **~75-86%** |

With Haiku→Sonnet routing, LLM costs drop from ~$8-15 (all-Opus) to ~$3.32 per tenant — a 78% LLM cost reduction. At $197/mo standard pricing, gross margin is ~87-93%.

At 100 paying customers on Pro: ~$7,900/month revenue, ~$5,100-6,400/month gross profit. Not venture-scale, but highly profitable as a bootstrapped product within the mmo-platform ecosystem.

---

## 5. Brand Naming

### 5.1 Naming Criteria

- **Memorable for tradies:** Short, punchy, easy to say on a job site. No clever wordplay that requires explanation.
- **Trust factor:** Should sound reliable, not gimmicky. Tradies trust tools, not toys.
- **Approachable, not techy:** "AI" in the name is fine (it's 2026, tradies know what AI is) but don't make it sound like a Silicon Valley startup.
- **Domain availability likely:** .com or .au preferred. .ai acceptable.
- **Works across AU/US/UK:** No slang that alienates one market.

### 5.2 Options (ranked by recommendation)

| # | Name | Reasoning | Concerns |
|---|------|-----------|----------|
| 1 | **ContactReply AI** | "Mate" resonates strongly in AU, works in US/UK as "teammate." Describes exactly what it does (replies). Friendly, approachable, memorable. Easy to say: "I use ContactReply AI." | replymate.org exists (Shopify autoresponder). replymate.com/.ai likely taken. Need to verify. |
| 2 | **QuickReply.ai** | Immediately communicates the value prop (fast replies). Clean, professional. The .ai domain signals the tech without being intimidating. | "Quick Reply" is generic/descriptive. May be hard to trademark. quickreply.com is almost certainly taken. |
| 3 | **OnTheJob** | Speaks directly to the tradie context: "I'm on the job, but my AI handles it." Strong brand storytelling potential. | Might sound too casual. onthejob.com likely taken. Could be confused with job boards. |
| 4 | **TradeReply** | Clearly for the trades. Exactly what it does. Professional but approachable. | Limits perceived market to trades only (fine if that is the permanent focus). |
| 5 | **CatchCall** | Action-oriented. "Catch every call" is the promise. Works as a verb: "CatchCall handles it." | Implies phone calls only, when we are multi-channel. |
| 6 | **NeverMiss** | Benefit-driven. "Never miss a lead." Emotional resonance with tradies who know they lose work. | Very generic. Branding challenge. nevermiss.com likely gone. |
| 7 | **Jobbi** | Short, fun, memorable. "My Jobbi handles texts." Sounds like a mate's name (cf. Alexa, Siri). | Might be too cute for some tradies. Could be confused with "jobby" (Scottish slang -- avoid for UK). |
| 8 | **AutoTrade** | "Auto" (automatic) + "Trade" (the industry). Clean compound. | Sounds like auto trading (stocks). Confusion risk. |
| 9 | **LeadCatch** | Benefit-first. Describes the outcome, not the mechanism. | Sounds like a lead gen tool, not an autoresponder. |
| 10 | **SiteGuard** | "Guard" implies protection (of leads). Professional. | Sounds like security software. Already used in cybersecurity. |
| 11 | **PingBack** | Describes the action: customer pings, AI pings back. Techy but accessible. | Might sound too tech-oriented for non-tech tradies. |
| 12 | **InstaReply** | "Instant reply" -- the core value. Clean. | "Insta" prefix is overused and associated with Instagram. |
| 13 | **BizReply** | "Business reply." Professional, clear. | Boring. No emotional hook. |
| 14 | **HotLead** | Urgency-driven. Tradies understand hot leads. | Sounds like a lead gen service, not an autoresponder. Also vaguely suggestive. |
| 15 | **Offsider** | Australian slang for an assistant/helper. "Your digital offsider." Deeply resonant in AU. | US/UK audiences will not know the word. Limits international branding. |

### 5.3 Recommendation

**Domain purchased (2026-04-02):** contactreplyai.com. Gary (product owner) purchased this domain and built the initial landing page using this identity.

**Marketing name:** Consider using a friendlier name in marketing copy (e.g. "ContactReply AI" as two words) while keeping the URL. The domain functions as the technical anchor; the brand can evolve. Short product name can be introduced in v2 once traction is established.

**Note:** contactreplyai.com describes what the product does (contact + reply + AI) which is actually good for SEO and direct-response marketing, even if it is not the most memorable word-of-mouth name. Tradies searching "AI reply for contacts" will find it naturally.

---

## 6. Sales Channel Strategy

### 6.1 Cross-Sell from Audit&Fix

This is the single most valuable distribution channel. Audit&Fix already:
- Has contact info for 210K+ local business websites
- Has scored their websites and knows their weaknesses
- Has proven cold-to-warm outreach sequences (DR-128)
- Has a payment flow and customer trust

**Cross-sell integration points:**

1. **In the audit report itself:** Add a section: "Response Time Audit -- We called/texted your business number at 2pm on a Tuesday. It took [X hours / no response] to hear back. Industry data shows 80% of leads call someone else within 5 minutes. [Learn about ContactReply AI]."

2. **In the follow-up sequence:** After the free fix (DR-128), touch 3 or 4 can mention: "By the way, while fixing your meta description, we noticed your contact form has no autoresponder. Every form submission sits unanswered until you check email. Want to see what instant AI responses look like? [Demo link]."

3. **Post-purchase upsell:** After someone buys an audit, include a one-pager: "Your website is now optimised. But are you capturing every lead that comes through? [ContactReply AI intro + 30-day free trial for Audit&Fix customers]."

4. **Audit report add-on:** Offer ContactReply AI setup as a line item in the audit proposal: "Website audit: $297. ContactReply AI setup + 3 months: $237 (save $60)." Bundle pricing.

### 6.2 Direct Acquisition

| Channel | Strategy | CAC Estimate |
|---------|----------|-------------|
| Google Ads | "AI answering service plumber [city]" -- bottom-of-funnel, high intent | $40-80 per trial signup |
| Facebook/Instagram Ads | Video ad: tradie on job, phone buzzing, AI responding. Retarget website visitors. | $25-50 per trial signup |
| SEO / Content | Blog: "How much do missed calls cost plumbers?" / "Best AI answering service for tradies 2026" | $0 marginal (time investment) |
| Referral program | $50 credit per referred tradie who activates. Word of mouth is king in trades. | $50 per activated referral |
| Trade shows / expos | Small booth at HIA (Housing Industry), Master Plumbers, etc. Live demo on a phone. | $500-2000 per event, 10-30 leads |
| Local tradie Facebook groups | Provide value, not spam. Answer questions about missed leads. Share data. | $0 (time investment) |

### 6.3 Self-Serve vs. Direct Sales

**Self-serve is the default.** The product must work without a sales call. Tradies do not want to talk to salespeople; they want to try the tool.

**Direct sales (outbound) for:**
- Multi-location businesses (3+ locations = Business plan, higher ACV)
- Franchise groups (one decision-maker, 10-50 locations)
- Existing Audit&Fix customers who have already paid (warm, trust established)

### 6.4 Website Demo Bot

**This is a critical acquisition feature, not a nice-to-have.**

The landing page must have a live demo that lets visitors experience the product before signing up. Two approaches:

1. **Text-based demo:** "Text [number] and pretend you need a plumber." Visitor has a real conversation with the AI. This is the most convincing demo possible.

2. **Web chat demo:** An embedded chat widget on the landing page configured as "Dave's Plumbing." Visitor chats with it, sees how it handles questions about pricing, availability, service areas, emergencies.

Both should be available. The web chat demo has zero friction. The SMS demo proves the SMS channel works.

---

## 7. Competitive Positioning

### 7.1 Competitive Landscape Summary

| Competitor | Channels | Price | Target | Weakness |
|-----------|----------|-------|--------|----------|
| Podium | SMS, web chat, reviews, payments | $599/month | Mid-market, multi-location | Massively overpriced for solo tradies. Feature bloat. |
| Smith.ai | Phone (AI or human) | $95-300/month | Professional services (legal, medical) | Phone only. No SMS/email/web chat AI. Per-call anxiety. |
| LeadTruffle | Web forms, missed calls, lead sources | $229-629/month | US home services contractors | Expensive. US-only. No WhatsApp. |
| Broadly | Web chat, reviews, payments | ~$300/month | Local services | Reviews-focused, not response-focused. Expensive. |
| Dialzara | Phone | ~$50/month | Small business | Phone only. US-focused. |
| My AI Front Desk | Phone | ~$65/month | Small business | Phone only. No multi-channel. |
| Trillet | Phone | $29/month | Small business | Cheapest but phone only. No multi-channel. |
| AI Trades (AU) | Quoting, scheduling, invoicing | Unknown | AU tradies | Operations tool, not response tool. Different problem. |
| Sophiie (AU) | Various AI tools | Unknown | AU tradies | Broad AI toolkit, not focused autoresponder. |

### 7.2 Our Unique Value Proposition

**"The only AI that answers your customers across every channel -- SMS, email, web chat, WhatsApp -- while you're on the job. Set up in 5 minutes. Pays for itself with one saved lead."**

Specific differentiators:

1. **Multi-channel from day one.** Every competitor is phone-only or single-channel. We cover SMS, email, web chat, and WhatsApp in one product. This matters because customers reach out however is convenient for THEM, not however the business prefers.

2. **Built for tradies, priced for tradies.** $49-149/month, not $300-600/month. No per-message or per-call pricing. One flat fee.

3. **5-minute conversational setup.** No forms to fill out, no "knowledge base" to write, no integrations to configure. Chat with the AI to teach it about your business.

4. **Cross-sell from website audits.** No competitor has an existing relationship with 210K+ local business websites. We don't cold-call tradies about an AI product; we upsell them from a service they already trust.

5. **Claude Opus intelligence.** Most competitors use GPT-3.5 or fine-tuned small models. We use Claude Opus, which produces more natural, context-aware responses. The quality difference is noticeable in complex conversations (pricing questions, emergency triage, multi-service inquiries).

6. **AU-first with local knowledge.** Competitors are US-first. We understand that "tradie" is the word, that ABN matters, that service areas are measured in km not miles, and that "arvo" means afternoon.

### 7.3 Positioning Statement

For solo tradies and small trade businesses who lose leads because they can't answer the phone while on the job, [ContactReply AI] is an AI-powered autoresponder that handles SMS, email, web chat, and WhatsApp inquiries with human-quality responses. Unlike Podium, Smith.ai, or answering services that cost $300-600/month and only handle phone calls, [ContactReply AI] covers every channel for $49-149/month and sets up in 5 minutes -- no IT skills required.

---

## 8. Key Metrics / Success Criteria

### 8.1 North Star Metric

**Leads Captured:** Number of qualified inquiries the AI successfully handled that would have otherwise gone unanswered (measured by: response sent outside business hours, or within 30 seconds of inquiry when owner did not respond within 5 minutes).

This metric directly ties to user value. A tradie doesn't care about "conversations handled" or "response time" -- they care about "how many customers did I NOT lose?"

### 8.2 Success Metrics by Phase

| Phase | Metric | Target | Measurement |
|-------|--------|--------|-------------|
| **MVP (Month 1-2)** | | | |
| | Trial signups | 50 | Landing page + Audit&Fix cross-sell |
| | Trial-to-paid conversion | 25% | Stripe billing data |
| | Median AI response time | <10 seconds | System logs |
| | AI response quality (owner rating) | 4.0+/5.0 | In-app rating per conversation |
| | Owner override rate | <20% | Conversations where owner stepped in |
| **v1.0 (Month 3-6)** | | | |
| | Monthly recurring revenue (MRR) | $5,000 AUD | Stripe |
| | Paying customers | 50 | Stripe |
| | Monthly churn rate | <5% | Cohort analysis |
| | Leads captured per customer per month | >10 | System data |
| | NPS | >40 | Quarterly survey |
| **v2.0 (Month 6-12)** | | | |
| | MRR | $25,000 AUD | Stripe |
| | Paying customers | 200 | Stripe |
| | LTV:CAC ratio | >3:1 | Cohort LTV / blended CAC |
| | Referral rate | 15%+ of new signups from referrals | Referral tracking |

### 8.3 Unit Economics Model

**Assumptions (Pro plan, $99 AUD/month):**

| Variable | Value |
|----------|-------|
| ARPU | $99 AUD/month |
| Gross margin | ~70% |
| Monthly churn | 5% |
| Customer lifetime | 20 months |
| LTV | $1,980 AUD |
| Blended CAC (target) | <$500 AUD |
| LTV:CAC | ~4:1 |
| Payback period | ~5 months |

At 100 customers: $9,900/month MRR, $6,930/month gross profit.
At 500 customers: $49,500/month MRR, $34,650/month gross profit.
At 1,000 customers: $99,000/month MRR, $69,300/month gross profit.

**Break-even:** ~30 paying customers covers infrastructure + one part-time support person.

---

## 9. Risk Assessment

### 9.1 Risk Register

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **AI says something wrong/harmful to a customer** | High (will happen) | High | Escalation triggers, owner override, response review queue, "I'll have [owner] call you" fallback for anything uncertain |
| **AI makes a promise the business can't keep** (e.g., "We can be there today") | High | High | Hard rules: AI NEVER confirms bookings, pricing, or timelines without owner approval. Uses language like "Let me check availability and get back to you." |
| **Tradie churn after trial -- "not worth it"** | Medium | High | Value reporting: "AI captured X leads worth $Y." Show the counterfactual. |
| **Claude API cost spikes** | Medium | Medium | Token budgets per conversation. Model fallback: Opus for first 2 turns, Sonnet for follow-ups. Cache system prompts. |
| **Twilio/WhatsApp compliance issues** | Medium | High | DR-121 established SMS compliance framework. Autoresponder is INBOUND-initiated (customer texts first), so consent is implied. Different risk profile from cold outreach. |
| **Competitor launches identical product cheaper** | Medium | Medium | Speed to market. Switching costs (trained AI, conversation history). Quality of AI responses as moat. |
| **Privacy/data concerns from business owners** | Medium | Medium | Clear privacy policy. Data stored in AU/region. Owner controls data retention. GDPR/Privacy Act compliance. |
| **SMS costs blow out** (chatty customers) | Low | Medium | Conversation turn limits (10 turns before "Let me have [owner] call you"). Long-message detection. |
| **Business owner leaves AI on and goes on holiday** | Low | High | "Unattended mode" warning after 48 hours of no owner check-in. Reduced AI confidence in unattended mode. |
| **Negative viral moment ("AI tradie bot goes wrong")** | Low | Very High | Manual review of first 100 conversations for every new customer. Quality gates. Rapid response plan. |

### 9.2 AI Safety Guardrails (Non-Negotiable)

1. **The AI never confirms pricing.** It says: "Pricing depends on the specific job. [Owner] can give you an exact quote." (or Owner can provide an indicative price list)
2. **The AI never confirms bookings (unless we have access to the client's booking system).** It says: "Let me check [owner]'s availability and get back to you."
3. **The AI never gives technical advice.** It says: "That's a great question for [owner] to answer when they call you."
4. **The AI always escalates emergencies.** Gas leaks, flooding, electrical fires -- the AI says "This sounds urgent. I'm alerting [owner] right now. If you're in immediate danger, call 000/911/999."
5. **The AI identifies itself as AI when directly asked.** "I'm an AI assistant for [business]. [Owner] will review this conversation and follow up personally." This is both ethical and legally required in some jurisdictions.
6. **The AI never handles complaints about the business.** It says: "I'm sorry to hear that. Let me have [owner] call you directly to sort this out."
7. **All conversations are logged and reviewable.** The owner can see every message, flag issues, and provide corrections.

### 9.3 Escalation and Complaints Handling

```
Customer sends message
  --> AI responds
    --> If customer expresses anger/frustration/complaint:
      --> AI: "I can see this is important. Let me have [owner] call you
          right away."
      --> PUSH NOTIFICATION to owner (priority: HIGH)
      --> If owner doesn't respond within 15 minutes:
        --> AI: "I've messaged [owner] and they'll be in touch shortly.
            I apologise for the wait."
      --> If owner doesn't respond within 2 hours:
        --> AI: "[Owner] is currently on a job but has been notified.
            They'll call you as soon as they're free."
```

---

## 10. Go-to-Market Strategy

### 10.1 Launch Market: Australia First

**Why AU first:**
- We are here. We understand the market, the language, the trade culture.
- "Tradie" is a deeply embedded cultural identity in AU. The branding, messaging, and product design can be hyper-specific.
- SMS compliance is clean for inbound-initiated conversations (Spam Act 2003).
- Audit&Fix has the deepest data on AU businesses (210K+ sites, many scored).
- Smaller market = faster iteration. AU has ~2.3M SMBs; the trade services subset is ~350K businesses.
- If we win AU, the playbook transfers to US/UK with localisation, not reinvention.

**US/UK expansion (Q4 2026):**
- US: Rebrand messaging from "tradie" to "contractor" / "home service pro." Same product, different vocabulary.
- UK: "Tradesman" / "contractor." Similar to AU culturally. GBP pricing.

### 10.2 First 10 Customers

| # | Method | Timeline | Expected Yield |
|---|--------|----------|---------------|
| 1-3 | **Personal network.** Reach out to tradies we know (or friends of friends). Offer free lifetime Pro plan in exchange for being founding customers + feedback + testimonial. | Week 1-2 | 3 customers |
| 4-6 | **Audit&Fix customers.** Anyone who has purchased an audit or engaged with the free fix sequence. Warm outreach: "We built something that solves the other half of your problem." | Week 2-4 | 3 customers |
| 7-8 | **Audit&Fix pipeline (scored, not purchased).** Businesses we have already contacted, especially those with poor response times. "We noticed your website took 4 hours to respond to our inquiry. Want to fix that?" | Week 3-6 | 2 customers |
| 9-10 | **Facebook group engagement.** Join 5-10 local tradie groups (Tradies Connect, etc.). Provide genuine value (answer questions about missed leads, share industry stats). Soft mention of the product. DM interested people. | Week 4-8 | 2 customers |

### 10.3 Content Marketing / SEO Strategy

**Target keywords (AU):**
- "AI answering service for tradies" (low competition, high intent)
- "never miss a customer call tradie" (problem-aware)
- "best answering service plumber Australia" (comparison shopping)
- "how much do missed calls cost tradies" (top of funnel, data-driven)
- "AI receptionist for small business Australia" (category search)

**Content plan:**
1. **Cornerstone article:** "The Hidden Cost of Missed Calls for Australian Tradies (2026 Data)" -- cite the $45K-$120K annual loss stat, localise with ABS data.
2. **Comparison page:** "AI Answering Services for Tradies: 2026 Comparison" -- compare against Podium, Smith.ai, Dialzara, position ourselves.
3. **Use case pages:** One page per trade (plumber, electrician, cleaner, pest control, landscaper) with trade-specific examples and testimonials.
4. **ROI calculator:** Interactive tool: "Enter your average job value and missed calls per week. See how much you're leaving on the table."
5. **Customer stories:** Video testimonials from founding customers. "Dave saved $2,000/month in missed leads."

### 10.4 Launch Timeline

| Week | Milestone |
|------|-----------|
| 1-2 | Technical spike: SMS autoresponder MVP using existing Twilio infrastructure + Claude API |
| 3-4 | Conversational onboarding flow. Mobile web app (responsive, not native). Push notifications via web push. |
| 5-6 | Email channel added. Web chat widget. Demo bot on landing page. |
| 7-8 | Internal alpha with 3 founding customers. Bug fixes, AI tuning, response quality review. |
| 9-10 | Closed beta with 10 customers. Landing page live. Stripe billing active. |
| 11-12 | Public launch (AU). Google Ads + Facebook Ads. Content marketing begins. |
| 16-20 | v1.0: WhatsApp, Facebook Messenger, lead scoring, weekly reports. |
| 24-36 | v2.0: Calendar integration, multi-user, US/UK expansion. |

---

## 11. Technical Architecture (High-Level)

### 11.1 Channel Ingestion

```
Inbound SMS (Twilio webhook)    --+
Inbound Email (forwarding/IMAP) --+
Web Chat (WebSocket)            --+--> Unified Message Queue --> AI Engine --> Response
WhatsApp (Meta webhook)         --+         (Claude)              |
FB Messenger (Meta webhook)     --+                               v
                                                          Owner Dashboard
                                                          (mobile web app)
                                                                  |
                                                          Push Notification
                                                          Owner Override
```

### 11.2 Key Technical Decisions (to be made)

- **AI model strategy:** Claude Opus for the first 2 turns of every conversation (highest quality for first impression), Sonnet for subsequent turns (cost optimisation). Haiku for classification/routing only.
- **Hosting:** NixOS VPS (existing infrastructure, DR-002) for the core service. Cloudflare Workers for webhook ingestion (proven pattern from Audit&Fix).
- **Database:** PostgreSQL (existing, DR-104) with a new `autoresponder` schema.
- **Real-time:** WebSocket for web chat; polling for SMS/email (Twilio/IMAP).
- **Message queue:** Start simple (PostgreSQL-backed job queue, same as 333Method pipeline). Move to Redis/BullMQ if latency or throughput demands it.

### 11.3 Existing Infrastructure Leverage

| Existing Asset | Reuse For |
|----------------|-----------|
| Twilio account + AU/US numbers | SMS channel |
| Resend account + sending domains | Email notifications to owners |
| Cloudflare Workers | Webhook ingestion |
| NixOS VPS + PostgreSQL | Core service hosting |
| Claude API (via OpenRouter) | AI engine |
| Stripe (via Audit&Fix) | Billing |
| mmo-platform suppression.db (DR-098) | Cross-project opt-out compliance |

---

## 12. What We Are NOT Building (and Why)

| Request | Why Not | Revisit Condition |
|---------|---------|-------------------|
| Voice call handling | Different tech stack (real-time voice AI), much harder, many competitors already there | When voice AI APIs are commodity-priced and reliable (12-18 months) |
| CRM | We are an autoresponder, not a CRM. ServiceM8/Tradify/Fergus own this space. Integrate, don't compete. | Never -- integrate instead |
| Review solicitation | Podium and Broadly own this. It is a different product. | If customer demand is overwhelming (>30% request it) |
| Payment collection | Scope creep. Square and Stripe already handle this for tradies. | v3+ if booking integration creates a natural payment flow |
| Marketing automation | We respond to inbound. We don't do outbound marketing for the tradie. | Never -- different product entirely |
| Website builder | Way out of scope | Never |
| Lead generation | We capture and respond to leads. We don't generate them. Audit&Fix is the lead gen product. | Never -- Audit&Fix is the lead gen arm |

---

## 13. Open Questions (Must Resolve Before Build)

| # | Question | Owner | Deadline | Impact |
|---|----------|-------|----------|--------|
| 1 | ~~Domain: contactreplyai.com~~ — **DONE** (purchased 2026-04-02) | Gary | Complete | |
| 2 | Claude API pricing: What is the actual cost per conversation with Opus + Sonnet mix? Need to run 50 simulated conversations. | PM | Week 2 | Unit economics, pricing confidence |
| 3 | Twilio inbound SMS: Can we use a single number with intelligent routing, or do we need per-customer numbers? | Eng | Week 1 | Cost model, phone number provisioning |
| 4 | Legal review: AU Privacy Act obligations for storing customer conversation data. Data retention policy. | Jason | Week 3 | Architecture, privacy policy |
| 5 | WhatsApp Business API: Approval timeline and requirements for a multi-tenant autoresponder. | Eng | Week 4 | v1.0 timeline |
| 6 | Mobile app vs. mobile web: PWA sufficient, or do we need native for push notifications? | Eng | Week 2 | Dev scope, timeline |
| 7 | Existing Audit&Fix customer data: How many have valid phone numbers we can cross-sell to? | PM | Week 1 | First-10-customers plan |

---

## 14. Appendix

### 14.1 Competitive Research Sources

- LeadTruffle contractor AI comparison (2026)
- Smith.ai pricing and feature pages
- Podium pricing (quote-based, ~$599/month reported)
- AI Trades Australia (aitrades.com.au)
- Dialzara, Trillet, My AI Front Desk feature pages

### 14.2 Market Data Sources

- Missed call statistics: Dialzara blog, AMBS Call Center report, Invoca home services data
- Cost of missed calls: $45K-$120K annually for contractors (InstantBusinessPro, 2026)
- Call answer rates: 37.8% answered, 62.2% missed (industry aggregate)
- Caller behaviour: 80% hang up on voicemail, 85% don't call back (multiple sources)
- Tradie software pricing: ServiceM8 ($6-55/mo), Xero ($25/mo), Tradify ($35/mo)

### 14.3 Channel Cost Estimates

| Channel | Inbound Cost | Outbound Cost | Notes |
|---------|-------------|---------------|-------|
| SMS (AU) | $0.0075/msg | $0.0515/msg | Twilio pricing |
| SMS (US) | $0.0083/msg | $0.0083/msg | Twilio pricing |
| SMS (UK) | $0.0075/msg | $0.0463/msg | Twilio pricing |
| Email | $0 (forwarding) | ~$0.0004/msg | Resend pricing |
| WhatsApp | $0 (24h window) | $0 (service reply) | Meta policy: service replies free within 24h of customer message |
| Web Chat | $0 | $0 | Self-hosted WebSocket |
| Claude Opus | ~$0.015/1K input, $0.075/1K output | -- | Per-turn cost ~$0.02-0.08 depending on context length |
| Claude Sonnet | ~$0.003/1K input, $0.015/1K output | -- | Per-turn cost ~$0.005-0.02 |
