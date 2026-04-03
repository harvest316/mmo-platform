# Architecture: AI Autoresponder Subscription Service

Project codename: **ReplyMate** (working title)

Target market: Australian local trades (plumbers, electricians, cleaners) expanding to US/UK/NZ.

---

## Table of Contents

1. [Domain Model](#1-domain-model)
2. [SMS Number Handling](#2-sms-number-handling)
3. [Email Integration Strategy](#3-email-integration-strategy)
4. [WhatsApp Integration](#4-whatsapp-integration)
5. [Booking System Integration](#5-booking-system-integration)
6. [LLM Architecture](#6-llm-architecture)
7. [Web Chat Widget](#7-web-chat-widget)
8. [Mobile App for Business Owners](#8-mobile-app-for-business-owners)
9. [Multi-Tenant Architecture](#9-multi-tenant-architecture)
10. [Deliverability](#10-deliverability)
11. [Infrastructure](#11-infrastructure)
12. [Trust Graduation UX](#12-trust-graduation-ux)
13. [Domain Connect (DNS Autoconfigure)](#13-domain-connect-dns-autoconfigure)
14. [Emergency Safety Response Framework](#14-emergency-safety-response-framework)
15. [Localisation](#15-localisation)
16. [AI Phone Answering Bolt-On](#16-ai-phone-answering-bolt-on)
17. [Onboarding — Feature Waitlist Collection](#17-onboarding--feature-waitlist-collection)
18. [Build Sequence](#18-build-sequence)

---

## 1. Domain Model

### Bounded Contexts

```
+---------------------+     +---------------------+     +---------------------+
|   Channel Gateway    |     |   Conversation      |     |   Tenant Mgmt       |
|                     |     |   Engine            |     |                     |
| - Inbound routing   |---->| - Threading         |     | - Onboarding        |
| - Outbound dispatch |<----| - AI reply gen      |     | - FAQ/KB mgmt       |
| - Deliverability    |     | - Human override    |     | - Billing           |
| - Channel adapters  |     | - Context window    |     | - Business hours    |
+---------------------+     +---------------------+     +---------------------+
         |                           |                           |
         v                           v                           v
+---------------------+     +---------------------+     +---------------------+
|   Booking Bridge     |     |   Owner Dashboard   |     |   Billing &         |
|                     |     |                     |     |   Metering          |
| - Calendar sync     |     | - Conversation view |     |                     |
| - Availability API  |     | - Override/takeover |     | - Usage tracking    |
| - Confirmation msgs |     | - Push notifications|     | - Stripe sub mgmt   |
+---------------------+     +---------------------+     +---------------------+
```

### Core Entities

- **Tenant**: A business (plumber, cleaner, etc.) with their own channels, FAQ, and billing
- **Channel**: An inbound/outbound path (SMS, email, WhatsApp, web chat, contact form)
- **Conversation**: A thread with one end-customer, spanning multiple channels
- **Message**: A single inbound or outbound message within a conversation
- **KnowledgeBase**: Per-tenant FAQ + vertical template content
- **Override**: A human takeover of a conversation (pauses AI until released)

### Key Invariants

1. A conversation belongs to exactly one tenant
2. AI never replies while an override is active on that conversation
3. Every outbound message is logged before dispatch (audit trail)
4. Channel-specific compliance rules are enforced at the gateway, not the conversation engine
5. A tenant's knowledge base is always included in the AI prompt (no cross-tenant leakage)

---

## 2. SMS Number Handling

### The Problem

The tradie has an existing phone number printed on their van, Google Business Profile, and business cards. They need to keep receiving voice calls on it. We need to intercept SMS to that number and reply via AI.

### Options Evaluated

| Option | How It Works | Tradie Effort | Voice Impact | Cost | Deliverability |
|--------|-------------|---------------|-------------|------|----------------|
| A. New Twilio number | Give them a second number for SMS; they advertise it alongside existing | Medium (update marketing materials) | None | ~$1.50/mo + usage | Excellent (dedicated) |
| B. Twilio hosted SMS (number porting) | Port their number to Twilio; Twilio handles SMS; forward voice calls back to their phone via SIP/PSTN | High (porting paperwork, 2-5 day downtime risk) | Forwarded calls add latency; voicemail complex | ~$1.50/mo + usage | Excellent (their own number) |
| C. Conditional call forwarding + Twilio number | Tradie's carrier forwards SMS to Twilio number; calls stay on their phone | Low (one carrier setting change) | None | ~$1.50/mo + usage | Good, but carrier-dependent |
| D. Carrier business SMS APIs (T-Mobile, AT&T) | Direct API access to their existing number's SMS | Very high (carrier contracts, business accounts) | None | Variable | Good |
| E. Twilio number with "text us at" marketing | Separate Twilio number positioned as the "text" number | Medium (update GBP, cards) | None | ~$1.50/mo + usage | Excellent |

### Decision: Option E (Phase 1) with Option B as Phase 2

**Phase 1 (launch): Dedicated Twilio number per tenant.** The tradie keeps their existing number for calls. We provision a local Twilio number (same area code when possible) and they add "Text us: (04XX XXX XXX)" to their Google Business Profile, website, and cards. This is the only option that requires zero carrier interaction, zero porting risk, and works in every country Twilio serves.

**Phase 2 (if demand validates): Hosted SMS via number porting** for tenants who want a single-number experience. Twilio becomes the SMS carrier; voice calls are forwarded via Twilio Programmable Voice back to a SIP endpoint or their mobile. This is the premium tier feature -- more complex onboarding but a better end-customer experience.

**Why not Option C (conditional forwarding)?** Australian carriers (Telstra, Optus, Vodafone) do not support SMS forwarding at the consumer/small-business level. This is a US-specific feature and even there it is unreliable. Conditional call forwarding only affects voice calls, not SMS. SMS and voice are separate signalling paths in GSM/LTE.

**Why not Option D (carrier APIs)?** The target user is a sole-trader plumber, not a Telstra Enterprise customer. Carrier business SMS APIs require enterprise contracts, minimum volumes, and API integration per carrier. This is a non-starter for the MVP.

### Implementation Notes

- Provision Twilio numbers via the Numbers API during onboarding
- Configure a webhook on each number pointing to the Channel Gateway
- Use Twilio Messaging Service (not raw number) for outbound -- enables A2P 10DLC registration, sticky sender, and future number pool scaling
- Store the mapping: `tenant_id -> twilio_number_sid -> messaging_service_sid`
- The existing `twilioLimiter` (Bottleneck, 0.7 msg/sec) and `twilioBreaker` (circuit breaker) from 333Method are directly reusable

### What We Are Giving Up

- Single-number experience at launch (tradie has two numbers)
- Carrier-native threading (iOS shows the Twilio number as a separate contact)

### What We Are Gaining

- Zero onboarding friction (no porting, no carrier calls)
- Full control over SMS infrastructure from day one
- Works identically in AU, US, UK, NZ
- Clean separation of personal/business SMS

---

## 3. Email Integration Strategy

### Options Evaluated

| Option | Reply-As-Them | Setup Effort | Deliverability | Ongoing Maintenance | Privacy |
|--------|--------------|-------------|----------------|-------------------|---------|
| A. OAuth into Gmail (Google Workspace API) | Yes (send as their address) | Medium (OAuth consent, scopes) | Their existing reputation | Token refresh, scope changes | High risk (full mailbox access) |
| B. OAuth into Outlook (Microsoft Graph API) | Yes (send as their address) | Medium (Azure AD app registration) | Their existing reputation | Token refresh, Graph API changes | High risk (full mailbox access) |
| C. IMAP polling + SMTP relay | Yes (send via their SMTP) | High (app passwords, IMAP config) | Their existing reputation | Connection failures, credential rotation | Medium (stored credentials) |
| D. Email forwarding + branded reply | Partial (replies from our domain) | Low (one forwarding rule) | Our reputation (new) | Minimal | Low risk |
| E. Branded email (theirbrand@ourdomain.com) | No (from our domain) | Low (DNS CNAME) | Our reputation | Minimal | Low risk |
| F. Custom subdomain (ai@reply.theirdomain.com) | Yes (from their domain, our infra) | Medium (DNS records: SPF, DKIM, DMARC) | Their domain, our IP reputation | DNS must stay configured | Low risk |

### Decision: Option F (custom subdomain on their domain) as primary, Option D as fallback

**Primary path: Custom sending subdomain on the tenant's domain.** We add `reply.theirdomain.com.au` with SPF include, DKIM CNAME, and DMARC record pointing to our Resend infrastructure. Emails come from `ai@reply.theirdomain.com.au` -- clearly from their business but on a subdomain that isolates autoresponder reputation from their primary email.

**Inbound: Forward to our processing address.** The tradie sets up a forwarding rule in Gmail/Outlook: "Forward a copy of all messages to `{tenant_id}@inbound.contactreplyai.com`". We receive via Resend inbound webhooks (or Cloudflare Email Workers for cost efficiency). No OAuth, no stored credentials, no token refresh nightmares.

**Fallback: Branded email** for tenants who cannot or will not add DNS records. They get `theirbusiness@contactreplyai.com` as their AI email address. Lower trust signal but zero DNS setup.

**Why not OAuth (Options A/B)?** Three reasons:
1. **Scope creep risk.** Gmail's `gmail.send` and `gmail.readonly` scopes give us access to their entire mailbox history. One data breach and we are front-page news. For a product targeting non-technical tradies, the trust barrier is already high.
2. **Token maintenance.** Google revokes OAuth tokens after 7 days of inactivity for unverified apps. Verified app review takes 4-8 weeks and requires a privacy policy, homepage, and compliance with Google's API Services User Data Policy. Microsoft Graph requires Azure AD app registration with admin consent for `Mail.ReadWrite`. Both are significant ongoing maintenance.
3. **Our users do not use Google Workspace.** Most sole-trader tradies use free Gmail or Outlook.com. Free Gmail accounts have restricted API access (no Google Workspace API, only limited Gmail API). Outlook.com personal accounts have restricted Graph API scopes.

**Why not IMAP (Option C)?** App passwords are being deprecated by Google (already enforced for Workspace, consumer accounts next). IMAP polling adds latency (minimum 1-minute poll interval vs instant webhook). Connection management across hundreds of tenants is operationally expensive.

### DNS Setup for Tenants (Option F)

The onboarding wizard generates three DNS records the tradie (or their web person) adds:

```
reply._domainkey.theirdomain.com.au  CNAME  resend._domainkey.resend.dev
reply.theirdomain.com.au             TXT    v=spf1 include:send.resend.dev ~all
_dmarc.reply.theirdomain.com.au      TXT    v=DMARC1; p=none; rua=mailto:dmarc@contactreplyai.com
```

We verify via DNS lookup during onboarding (same pattern as Resend's domain verification API).

### What We Are Giving Up

- Replies do not appear in the tradie's Gmail "Sent" folder (they see them in the dashboard instead)
- Inbound requires a forwarding rule (one-time setup but not zero-touch)

### What We Are Gaining

- No OAuth tokens to manage or breach
- No stored credentials
- Deliverability tied to their domain reputation (warm domain)
- Subdomain isolation protects their primary domain from any AI-reply reputation issues
- Works with any email provider (Gmail, Outlook, Yahoo, custom hosting)

---

## 4. WhatsApp Integration

### Options Evaluated

| Option | Cost | Setup | 24h Window | Message Templates |
|--------|------|-------|-----------|------------------|
| A. WhatsApp Cloud API (direct from Meta) | Free hosting; per-conversation pricing (~$0.05-0.08 AU) | Meta Business verification, phone number registration | Standard | Must be pre-approved by Meta |
| B. BSP (Twilio, MessageBird, Vonage) | BSP markup + Meta conversation fees | BSP manages Meta verification | Standard | BSP-assisted approval |
| C. WhatsApp Business App API (unofficial) | Free | None | N/A | None (violates ToS) |

### Decision: Option A (WhatsApp Cloud API) via Twilio as BSP

Use Twilio's WhatsApp integration (Option B implemented via A). Rationale:

1. **Twilio is already in the stack.** The Channel Gateway already has a Twilio adapter for SMS. WhatsApp via Twilio uses the same webhook format, same `twilioClient.messages.create()` API, same rate limiter. The incremental code for WhatsApp is approximately 50 lines (channel-specific message formatting).

2. **Twilio handles Meta Business Verification.** This is the hardest part of WhatsApp integration. Direct Cloud API requires each tenant to complete Meta Business Verification (2-4 weeks, document uploads, business documentation). Twilio's BSP onboarding simplifies this significantly.

3. **24-hour conversation window handling.** WhatsApp enforces a 24-hour window: after a customer messages, we can reply freely for 24h. After that, we must use a pre-approved message template. The Conversation Engine needs a `last_customer_message_at` timestamp per WhatsApp conversation. If >24h, the outbound message must use an approved template ("Hi {name}, we have an update on your enquiry. Reply to continue the conversation.").

4. **Template pre-approval.** Required templates (submitted via Twilio console):
   - `enquiry_followup`: "Hi {{1}}, thanks for reaching out to {{2}}. We'll get back to you shortly."
   - `booking_confirmation`: "Your appointment with {{1}} is confirmed for {{2}} at {{3}}."
   - `conversation_reopen`: "Hi {{1}}, we have an update regarding your enquiry with {{2}}. Reply to continue."

### What We Are Giving Up

- Direct Cloud API is cheaper per conversation (~30% less than Twilio markup)
- Direct integration gives more control over webhook configuration

### What We Are Gaining

- Single vendor for SMS + WhatsApp (operational simplicity)
- Twilio handles Meta compliance (massive time savings)
- Same codebase pattern for both channels

---

## 5. Booking System Integration

### Options Evaluated

| Option | Coverage | Maintenance | Cost | Reliability |
|--------|----------|-------------|------|-------------|
| A. Custom API integrations per platform | Full control per platform | High (N APIs to maintain) | Dev time only | High per integration |
| B. Zapier/Make/n8n as middleware | Broad (5000+ apps) | Low (no-code) | $20-50/mo per tenant | Medium (webhook reliability) |
| C. Booking-specific aggregator (Nylas Calendar, CozyCal) | Calendar-focused | Low | $5-15/mo per tenant | High |
| D. Cal.com (self-hosted) as universal calendar | Full control | Medium (hosting) | Free (self-hosted) | High |
| E. No integration; AI collects details, owner books manually | None needed | Zero | Zero | N/A |

### Decision: Option E (Phase 1) then Option D + selective A (Phase 2)

**Phase 1: AI captures booking intent, owner completes manually.**

The AI recognises booking requests ("I need a plumber Tuesday arvo"), extracts the details (service type, preferred date/time, address, urgency), and formats them as a structured booking request in the dashboard. The business owner sees a notification: "New booking request: blocked drain, 14 Smith St Parramatta, Tuesday 2-4pm preferred." They book it in whatever system they already use (their head, a paper diary, Jobber, ServiceTitan).

This is the correct Phase 1 because:
- 80%+ of sole-trader tradies do NOT use a booking platform. They use their phone calendar or a whiteboard.
- Integrating with ServiceTitan/Jobber before we have paying customers is architecture astronautics.
- The AI's value is the instant acknowledgement and detail capture, not the calendar sync.

**Phase 2: Self-hosted Cal.com + selective platform integrations.**

When we have 50+ tenants and booking friction is validated as a churn driver:
1. Deploy Cal.com (MIT license, self-hosted) on the NixOS VPS. This gives us a universal calendar backend with a clean API.
2. Tenants who want calendar sync connect Cal.com to their Google Calendar / Outlook Calendar (Cal.com has native OAuth integrations for both).
3. For the subset of tenants on ServiceTitan or Jobber (enterprise-tier tradies), build direct API integrations. ServiceTitan has a public REST API; Jobber has a GraphQL API. Both require partner agreements.
4. n8n (self-hosted, fair-source license) as the glue layer for long-tail platforms that have Zapier integrations but no direct API.

**Why not Zapier/Make from the start?** Cost. At $20-50/month per tenant, the booking integration alone could exceed the subscription price. n8n self-hosted eliminates the per-tenant cost.

### What We Are Giving Up

- Automated booking confirmation at launch
- Two-way calendar sync at launch

### What We Are Gaining

- Zero integration maintenance at launch
- No dependency on third-party booking platforms
- The AI still captures 100% of the booking information
- Validates whether booking integration is actually a churn driver before building it

---

## 6. LLM Architecture

### The Core Decision: Anthropic API Direct

The autoresponder is a multi-tenant commercial service. Claude Max subscription (`claude -p` CLI) explicitly violates Anthropic's Terms of Service for commercial multi-tenant use (DR-159 — confirmed 2026-04-02). Anthropic API direct is the only compliant path.

| Factor | Anthropic API (direct) | OpenRouter API |
|--------|----------------------|----------------|
| Model quality | Opus/Sonnet/Haiku (full range) | Same models via routing |
| Cost at 100 tenants | ~$200-600/mo (with classifier routing) | ~$180-500/mo |
| Cost at 1000 tenants | ~$2k-6k/mo | ~$1.8k-5k/mo |
| Latency | 1-3s (streaming) | 2-5s (routing overhead) |
| Rate limits | 4000 RPM (Tier 4) | Varies by model |
| Multi-tenant isolation | Request-level headers | Request-level |
| Reliability | 99.9% SLA | 99.5% (multi-provider) |
| ToS compliance | Full — API keys are for commercial use | Full |

### Decision: Anthropic API primary, OpenRouter overflow

**Primary: Anthropic API (direct) for all customer-facing replies.** Request-level tenant isolation, streaming support for sub-60-second response times, proper error handling, Messages API with system prompts that include tenant-specific knowledge bases. API keys obtained from console.anthropic.com — standard commercial usage.

**Overflow: OpenRouter** if Anthropic rate limits are hit or during cost spikes. Route to `anthropic/claude-sonnet-4-5` via OpenRouter. Same model, slightly higher latency.

**Cost management architecture:**

```
Inbound message arrives
    |
    v
[Complexity classifier] -- Haiku, ~$0.001/call, <500ms
    |
    +-- Simple (hours, location, pricing FAQ) -----> Sonnet (~80% of calls)
    |   ~$0.003-0.01/reply, target <15s total
    |
    +-- Complex (complaints, multi-turn, negotiate) -> Opus (~20% of calls)
    |   ~$0.02-0.08/reply, target <30s total
    |
    +-- Safety keyword detected (gas/fire/flood) ---> Templated response (Tier 1/2/3)
    |   ~$0/reply (no LLM), <1s, per DR-161
    |
    +-- Booking request --------------------------> Haiku extraction + dashboard notification
        ~$0.001/call, <5s
```

Sub-60-second total response time is achievable: Haiku classifier (0.5s) + Sonnet streaming (5-15s) = ~15s p50, ~45s p95. Opus path hits 20-45s streaming. Combined p95 < 60s for all calls.

Estimated cost per tenant at 200 messages/month: ~$2-4/month with Haiku→Sonnet routing (vs $8-15 without classifier). At a $99 AUD/month subscription, LLM component is 2-4% of revenue.

### Multi-Tenant Prompt Architecture

```
System prompt structure:
+--------------------------------------------------+
| [Global system prompt]                            |
| - Role: AI assistant for {business_name}          |
| - Personality: friendly, professional, Australian |
| - Never reveal you are AI unless asked directly   |
| - Never make up information not in knowledge base |
| - Channel-specific formatting rules               |
+--------------------------------------------------+
| [Tenant knowledge base]                           |
| - Business name, address, service area            |
| - Services offered + pricing (if authorised)      |
| - Business hours + after-hours policy             |
| - FAQ entries (custom per business)               |
| - Booking preferences (min notice, service types) |
+--------------------------------------------------+
| [Vertical template]                               |
| - Plumbing: emergency procedures, common Qs       |
| - Electrical: safety disclaimers, licence info    |
| - Cleaning: quoting process, product questions    |
+--------------------------------------------------+
| [Conversation history]                            |
| - Last 10 messages in this thread                 |
| - Channel context (SMS = short, email = longer)   |
| - Any active override/human-takeover status       |
+--------------------------------------------------+
```

### Knowledge Base Storage

Per-tenant knowledge base stored as structured JSON in the database (not vector embeddings). Rationale: a tradie's FAQ is 10-50 entries, not thousands of documents. The entire knowledge base fits within Claude's context window with room to spare. Vector search adds complexity and latency with zero benefit at this scale.

```json
{
  "business": {
    "name": "Dave's Plumbing",
    "abn": "12345678901",
    "service_area": "Sydney Northern Beaches, North Shore",
    "address": "123 Main St, Dee Why NSW 2099",
    "phone": "0412 345 678",
    "email": "dave@davesplumbing.com.au",
    "website": "https://davesplumbing.com.au"
  },
  "hours": {
    "mon-fri": "7am-5pm",
    "sat": "8am-12pm",
    "sun": "Emergency only",
    "after_hours": "Emergency callout available 24/7, $150 callout fee applies"
  },
  "services": [
    { "name": "Blocked drains", "price_range": "$150-$400", "notes": "CCTV inspection included for blockages over $300" },
    { "name": "Hot water systems", "price_range": "$200-$2500", "notes": "Same-day replacement for most brands" },
    { "name": "Leaking taps", "price_range": "$120-$250", "notes": "Most repairs done in one visit" }
  ],
  "faq": [
    { "q": "Do you do free quotes?", "a": "Yes, free quotes for all non-emergency work. Emergency callouts have a $150 fee which is waived if you go ahead with the job." },
    { "q": "Are you licensed?", "a": "Yes, fully licensed and insured. Licence number: 12345C." }
  ],
  "policies": {
    "quoting": "Free quotes for standard work. Emergency callout fee: $150 (waived if job proceeds).",
    "payment": "Cash, card, bank transfer. Payment on completion.",
    "cancellation": "24 hours notice required. Same-day cancellations may incur a $75 fee."
  }
}
```

### Conversation Context Management

- **Per-conversation context window:** Last 10 messages (user + AI) plus the full knowledge base. This fits within 8K tokens for most conversations.
- **Cross-channel threading:** Messages from the same end-customer phone number or email address are linked to the same conversation, regardless of channel. A customer who texts and then emails sees continuity.
- **Context decay:** After 72 hours of inactivity, a new inbound message starts a new conversation (but the AI can reference "I can see you contacted us on {date} about {topic}" via a lightweight summary stored on the old conversation).

---

## 7. Web Chat Widget

### Architecture

```
Tenant's website                    Our infrastructure
+-------------------+              +-------------------+
| <script src=       |   WebSocket  | CF Worker         |
|  "contactreplyai.com/   |<----------->| (ws.contactreplyai.com)|
|   widget.js">      |              |                   |
|                    |              | - Auth validation  |
| [Chat bubble]      |              | - Rate limiting    |
| [Message input]    |              | - Message routing  |
+-------------------+              +---+---------------+
                                       |
                                       | HTTP POST
                                       v
                                   +-------------------+
                                   | Channel Gateway   |
                                   | (same as SMS/     |
                                   |  email/WhatsApp)  |
                                   +-------------------+
```

### Widget Delivery

- `widget.js` served from Cloudflare CDN (~5KB gzipped)
- Initialised with a tenant-specific public key: `<script src="https://cdn.contactreplyai.com/widget.js" data-key="rm_pub_xxxx"></script>`
- Widget renders as an iframe (style isolation from host page) with a floating chat bubble
- No React/Vue dependency -- vanilla JS + shadow DOM for maximum compatibility and minimum bundle size

### Bot Attack Prevention (Critical)

Web chat is the highest-risk channel for LLM credit drain. A bot can send thousands of messages without any identity verification. Defence in depth:

| Layer | Mechanism | What It Stops |
|-------|-----------|---------------|
| 1. Rate limiting | CF Worker: 5 messages/minute per IP, 20 messages/hour per session | Naive bots, accidental spam |
| 2. Session token | HMAC-signed session token with 30-minute rolling expiry; must be present on every message | Replay attacks |
| 3. Proof of work | Client-side SHA-256 puzzle (difficulty 18 = ~0.5s on mobile, ~0.1s on desktop); must be solved before first message | Automated scripts without JS execution |
| 4. Browser fingerprint | Canvas + WebGL + AudioContext fingerprint hash; flag if >10 unique sessions from same fingerprint in 1 hour | Headless browser farms |
| 5. Behavioural analysis | Message timing variance < 50ms = bot (humans have 200ms+ variance between keystrokes); messages arriving faster than typing speed = paste/bot | Sophisticated bots |
| 6. Per-tenant daily cap | Maximum 100 web chat conversations per tenant per day; alert tenant + pause widget if exceeded | Targeted attacks on a specific tenant |
| 7. LLM cost circuit breaker | If web chat LLM spend exceeds $5/tenant/day, switch to canned responses only | Cost runaway from any cause |

**Layer 3 (proof of work) is the key innovation.** CAPTCHA is terrible UX for a chat widget (imagine solving a CAPTCHA before asking a plumber about a leaking pipe). Proof of work runs invisibly in the browser: the widget requests a challenge from the CF Worker, solves it client-side, and submits the solution with the first message. Legitimate users never notice; bots without a JS runtime cannot solve it.

### Real-Time vs Queued

Web chat messages go through the same Channel Gateway and Conversation Engine as SMS/email/WhatsApp. Web chat users expect fast responses. Architecture:

- **First reply:** Immediate canned acknowledgement ("Thanks for your message! We're looking into this right now.") -- zero LLM cost, instant (<1s).
- **AI reply:** Target sub-60-second delivery (p95). Web chat gets a high-priority queue lane: Haiku classifier runs immediately on arrival, Sonnet replies dispatch within 15-20s, Opus within 45s.
- **Typing indicator:** The widget shows "typing..." when the AI reply job is actively being processed (via WebSocket push from the Worker).

### What We Are Giving Up

- True real-time chat (sub-second responses for the AI reply itself)

### What We Are Gaining

- Single pipeline for all channels (no separate real-time infrastructure)
- Cost control (no per-keystroke LLM calls)
- Quality (full context, not a degraded fast-path model)
- Sub-60-second performance is indistinguishable from "fast" for most chat contexts

---

## 8. Mobile App for Business Owners

### Options Evaluated

| Option | Dev Cost | Performance | Push Notifications | App Store | Offline |
|--------|----------|-------------|-------------------|----------|---------|
| A. Native iOS + Android (Swift + Kotlin) | Very high (2 codebases) | Best | Native | Required | Full |
| B. React Native / Expo | Medium | Good | Via Expo Push | Required | Limited |
| C. Flutter | Medium | Good | Via FCM/APNs | Required | Limited |
| D. PWA (Progressive Web App) | Low | Good enough | Web Push API | Not required | Service worker |
| E. Capacitor (web-to-native wrapper) | Low-Medium | Good | Native via plugin | Required | Service worker + native |

### Decision: Option D (PWA) for Phase 1, Option E (Capacitor) for Phase 2

**Phase 1: PWA.** A Progressive Web App is the correct choice for a tradie who "just wants it to work on their phone":

1. **No app store required.** The tradie opens a URL, taps "Add to Home Screen", done. No App Store review process, no Google Play listing, no $99/year Apple Developer fee, no 30% revenue cut.
2. **Push notifications work.** Web Push API is supported on iOS 16.4+ (since March 2023) and all Android browsers. The tradie gets a native-feeling push notification when a customer message arrives or when AI confidence is low and human review is needed.
3. **Instant updates.** Service worker caches the app shell; updates deploy instantly without app store review cycles.
4. **The UI is simple.** This is a conversation list + detail view + settings screen. There is no need for native camera access, GPS, or hardware APIs that would justify native development.

**Phase 2: Capacitor wrapper** if the PWA limitations become a churn driver. Capacitor wraps the same web app in a native shell, giving us App Store/Play Store presence (some users only trust "real" apps), native push via APNs/FCM (more reliable than Web Push), and background sync.

### Core Features

1. **Conversation list** -- All active conversations, sorted by most recent message. Badge count for unread. Filter by channel.
2. **Conversation detail** -- Full message thread. AI-generated replies shown in a distinct colour with a "Sent by AI" label. Tap any AI reply to see the knowledge base entries that informed it.
3. **Override/Takeover** -- Tap "Take over" on any conversation to pause AI and type manually. Tap "Resume AI" when done. AI gets a summary of the human's messages for context continuity.
4. **Business hours** -- Set open/closed hours. Configure after-hours behaviour: "AI responds with emergency info only" vs "AI responds normally" vs "Send to voicemail message".
5. **Knowledge base editor** -- Add/edit FAQ entries, update pricing, change service area. Changes take effect on the next AI reply (no redeployment).
6. **Notifications** -- Push notification for: new conversation, low-confidence AI reply (needs review), customer complaint detected, booking request captured.
7. **Quick stats** -- Messages today, response time, AI vs human reply ratio, customer satisfaction (inferred from conversation outcomes).

### Push Notification Architecture

```
Conversation Engine detects event (new message, low confidence, complaint)
    |
    v
Notification Service (Node.js)
    |
    +-- Web Push API (PWA subscribers, via web-push npm package)
    |
    +-- FCM/APNs (Phase 2, Capacitor users)
    |
    +-- SMS fallback (if push fails + event is critical, e.g. complaint)
```

Web Push requires a VAPID key pair (generated once, stored in env). Subscription objects stored per-tenant-user in the database.

---

## 9. Multi-Tenant Architecture

### The Database Question

| Option | Tenant Isolation | Operational Complexity | Query Flexibility | Backup/Restore | Existing Ecosystem Fit |
|--------|-----------------|----------------------|-------------------|----------------|----------------------|
| A. SQLite per tenant | Complete (file-level) | High at scale (1000 files) | Full per-tenant | Trivial per-tenant | Strong (333Method uses SQLite) |
| B. Single PostgreSQL with tenant_id column | Row-level | Low | Full cross-tenant analytics | All-or-nothing | Partial (PG exists on VPS for suppression) |
| C. PostgreSQL with schema-per-tenant | Schema-level | Medium | Per-schema queries | Per-schema | Partial |
| D. Single SQLite for shared data + SQLite per tenant for conversations | Hybrid | Medium | Good | Mixed | Strong |

### Decision: Option B (PostgreSQL with row-level tenant isolation)

The VPS already runs PostgreSQL (DR-004, DR-106 -- suppression system). The autoresponder service is inherently multi-tenant from day one -- every query needs `WHERE tenant_id = ?`. SQLite per tenant (Option A) becomes an operational nightmare at 100+ tenants: file management, connection pooling, backup orchestration, and the inability to run cross-tenant analytics queries.

**Schema design:**

```sql
-- Core tenant table
CREATE TABLE tenants (
    id              SERIAL PRIMARY KEY,
    name            TEXT NOT NULL,
    slug            TEXT UNIQUE NOT NULL,        -- URL-safe identifier
    vertical        TEXT NOT NULL,               -- 'plumbing', 'electrical', 'cleaning', etc.
    knowledge_base  JSONB NOT NULL DEFAULT '{}', -- FAQ, services, policies
    settings        JSONB NOT NULL DEFAULT '{}', -- business hours, AI personality, etc.
    billing_status  TEXT NOT NULL DEFAULT 'trial', -- trial, active, paused, cancelled
    paypal_subscription_id TEXT,   -- PayPal subscription ID (no Stripe)
    paypal_payer_id TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Channel configuration per tenant
CREATE TABLE channels (
    id              SERIAL PRIMARY KEY,
    tenant_id       INTEGER NOT NULL REFERENCES tenants(id),
    channel_type    TEXT NOT NULL, -- 'sms', 'email', 'whatsapp', 'webchat', 'form'
    config          JSONB NOT NULL DEFAULT '{}', -- channel-specific: twilio_number_sid, email_domain, etc.
    enabled         BOOLEAN NOT NULL DEFAULT true,
    verified        BOOLEAN NOT NULL DEFAULT false,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Conversations (one per end-customer per tenant)
CREATE TABLE conversations (
    id              SERIAL PRIMARY KEY,
    tenant_id       INTEGER NOT NULL REFERENCES tenants(id),
    customer_identifier TEXT NOT NULL, -- phone, email, or generated ID for webchat
    customer_name   TEXT,
    status          TEXT NOT NULL DEFAULT 'active', -- active, resolved, archived
    override_active BOOLEAN NOT NULL DEFAULT false,
    override_by     TEXT, -- user who took over
    override_at     TIMESTAMPTZ,
    last_message_at TIMESTAMPTZ,
    last_channel    TEXT, -- most recent channel used
    summary         TEXT, -- AI-generated conversation summary (updated periodically)
    metadata        JSONB DEFAULT '{}', -- booking details, extracted info, etc.
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Messages (the core audit trail)
CREATE TABLE messages (
    id              SERIAL PRIMARY KEY,
    tenant_id       INTEGER NOT NULL REFERENCES tenants(id),
    conversation_id INTEGER NOT NULL REFERENCES conversations(id),
    channel         TEXT NOT NULL, -- 'sms', 'email', 'whatsapp', 'webchat', 'form'
    direction       TEXT NOT NULL, -- 'inbound', 'outbound'
    sender          TEXT NOT NULL, -- phone/email of sender
    body            TEXT NOT NULL,
    ai_generated    BOOLEAN NOT NULL DEFAULT false,
    ai_model        TEXT, -- 'opus', 'sonnet', 'haiku', 'canned'
    ai_confidence   REAL, -- 0.0-1.0; null for human messages
    ai_cost_usd     REAL, -- LLM cost for this reply
    delivery_status TEXT DEFAULT 'pending', -- pending, sent, delivered, failed, read
    external_id     TEXT, -- Twilio SID, Resend ID, etc.
    metadata        JSONB DEFAULT '{}', -- channel-specific metadata
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Indexes for the hot paths
CREATE INDEX idx_messages_tenant_conv ON messages(tenant_id, conversation_id);
CREATE INDEX idx_messages_pending ON messages(delivery_status) WHERE delivery_status = 'pending';
CREATE INDEX idx_conversations_tenant_active ON conversations(tenant_id, status) WHERE status = 'active';
CREATE INDEX idx_conversations_customer ON conversations(tenant_id, customer_identifier);

-- Row-level security (defense in depth)
ALTER TABLE messages ENABLE ROW LEVEL SECURITY;
ALTER TABLE conversations ENABLE ROW LEVEL SECURITY;

-- Application enforces tenant_id via middleware; RLS is a safety net
CREATE POLICY tenant_isolation_messages ON messages
    USING (tenant_id = current_setting('app.current_tenant_id')::INTEGER);
CREATE POLICY tenant_isolation_conversations ON conversations
    USING (tenant_id = current_setting('app.current_tenant_id')::INTEGER);
```

**Tenant isolation enforcement:**

Every database connection sets `SET app.current_tenant_id = {id}` at the start of each request. PostgreSQL Row-Level Security (RLS) acts as a safety net -- even if application code forgets a `WHERE tenant_id = ?`, RLS prevents cross-tenant data access. This is defence in depth, not the primary mechanism.

### White-Labeling

Not in Phase 1. When needed (agency partners reselling):
- `white_label` JSONB on tenants table: custom logo URL, colour scheme, sender name
- Widget CSS variables for theming
- Custom domain CNAME for the dashboard (agency.theirbrand.com -> dashboard.contactreplyai.com)

---

## 10. Deliverability

### SMS Deliverability

**10DLC (US) / A2P Registration (AU):**

| Market | Registration | Lead Time | Impact |
|--------|-------------|-----------|--------|
| US | 10DLC Campaign Registration via Twilio | 2-4 weeks | Mandatory. Unregistered = 1 msg/sec, $0.003 carrier surcharge. Registered = 10+ msg/sec. |
| AU | No A2P registration required (yet) | N/A | Telstra/Optus filter aggressively on content; no registration regime exists. |
| UK | No A2P registration; Ofcom regulations | N/A | Alpha sender ID support (business name instead of number). |
| NZ | No A2P registration | N/A | Similar to AU filtering. |

**Key practices (reusable from 333Method DR-025, DR-023, DR-121):**

1. **Messaging Service, not raw number.** Twilio Messaging Service provides sticky sender (same number for a conversation), automatic number selection from a pool, and is required for 10DLC.
2. **Business hours enforcement.** TCPA (US): 8am-9pm recipient local time. AU Spam Act: no statutory hours but industry practice is 8am-8pm. Reuse the compliance.js business hours logic from 333Method.
3. **Opt-out handling.** Twilio Advanced Opt-Out handles STOP/UNSUBSCRIBE/CANCEL automatically for US numbers. For AU, we must handle opt-out keywords ourselves (reuse existing pattern from sms.js).
4. **Content filtering.** Avoid URL shorteners (carrier spam filters flag them). Use full domain URLs. Keep messages under 160 chars when possible (single SMS segment). The existing spintax system from 333Method prevents identical message content across sends.
5. **Outreach guard.** The existing `outreach-guard.js` (sliding window, 25 errors in 2h = halt channel) is directly reusable for the autoresponder. It protects against sending failures cascading into reputation damage.

### Email Deliverability

**For the custom subdomain approach (Section 3, Option F):**

1. **SPF/DKIM/DMARC** configured during onboarding (see Section 3 DNS records). Verified via DNS lookup before sending.
2. **Subdomain isolation.** `reply.theirdomain.com.au` isolates autoresponder reputation from the tenant's primary domain. If the autoresponder generates a complaint, it hits the subdomain, not `theirdomain.com.au`.
3. **Warm-up not required** for the custom subdomain approach because we are sending as the tenant's domain (which already has reputation from their normal email activity). The subdomain inherits some parent domain reputation.
4. **Resend shared IP pool.** At current scale (<1k emails/month per tenant), shared IPs are fine. Per DR-124 and DR-126, dedicated IP only makes sense at 5k+/month. The autoresponder will send far fewer emails than the cold outreach pipeline.
5. **Bounce handling.** Resend webhooks for bounces and complaints. Hard bounce = suppress email for that conversation. Complaint = pause AI email replies for tenant and alert.

---

## 11. Infrastructure

### Architecture Overview

```
                                   Cloudflare
                                   +-----------------------------------+
Internet --> CF DNS/CDN            | widget.js (CDN cached)            |
                |                  | ws.contactreplyai.com (Durable Object) |
                |                  +---+-------------------------------+
                |                      |
                v                      v
         +------+------+        +-----+-----+
         | CF Worker    |        | CF Worker  |
         | (webhooks)   |        | (WebSocket)|
         |              |        |            |
         | POST /sms    |        | Widget     |
         | POST /email  |        | sessions   |
         | POST /wa     |        |            |
         +-+----+-------+        +-----+------+
           |    |                       |
           v    v                       |
      +----+----+----+                 |
      | Message Queue |<----------------+
      | (PG LISTEN/   |
      |  NOTIFY +     |
      |  polling)     |
      +-------+-------+
              |
              v
      +-------+-------+
      | Node.js        |
      | Reply Service  |
      |                |
      | - Dequeue msg  |
      | - Load tenant  |
      | - Build prompt |
      | - Call Claude   |
      | - Dispatch via  |
      |   channel       |
      +-------+--------+
              |
              v
      +-------+--------+
      | PostgreSQL      |
      |                 |
      | tenants         |
      | channels        |
      | conversations   |
      | messages        |
      +-------+---------+
              |
              v
      +-------+---------+
      | Outbound         |
      | Dispatchers      |
      |                  |
      | Twilio (SMS/WA)  |
      | Resend (Email)   |
      | WebSocket (Chat) |
      +------------------+
```

### Queue System

**PostgreSQL as the queue** (not Redis, not RabbitMQ). Rationale:

1. The 5-10 minute reply window means we do not need sub-second dequeue latency. PostgreSQL `SELECT ... FOR UPDATE SKIP LOCKED` (already planned per DR-052) gives us exactly the semantics we need: multiple worker processes can dequeue messages concurrently without collision.

2. No additional infrastructure to operate. The PostgreSQL instance already exists. Adding Redis or RabbitMQ for a queue that processes ~1 message/second at 100 tenants is unjustified complexity.

3. `LISTEN/NOTIFY` provides pseudo-real-time notification. When a new message is inserted, `NOTIFY new_message` wakes up the Reply Service immediately instead of waiting for the next poll cycle. Polling runs as a backup every 30 seconds.

```sql
-- Queue table (or use the messages table directly with status filtering)
-- Messages with delivery_status = 'pending' and direction = 'inbound' are the queue.

-- Dequeue pattern (sub-60s target):
BEGIN;
SELECT m.* FROM messages m
WHERE m.delivery_status = 'pending'
  AND m.direction = 'inbound'
  AND (
    -- Rapid-fire batch window: wait up to 8s for more messages from same customer
    -- (handles "where are you located?" followed immediately by "and what's your rate?")
    NOT EXISTS (
      SELECT 1 FROM messages m2
      WHERE m2.tenant_id = m.tenant_id
        AND m2.conversation_id = m.conversation_id
        AND m2.direction = 'inbound'
        AND m2.delivery_status = 'pending'
        AND m2.created_at > m.created_at
        AND m2.created_at < NOW() - INTERVAL '8 seconds'
    )
    -- After 8s of no follow-up, process immediately
    OR m.created_at < NOW() - INTERVAL '8 seconds'
  )
ORDER BY m.created_at ASC
LIMIT 10
FOR UPDATE SKIP LOCKED;

-- Process each, generate reply, insert outbound message, update status
COMMIT;
```

**No fixed 30-second deliberate delay.** Messages are processed as soon as the rapid-fire window (8 seconds) has elapsed with no follow-up from the same customer. This keeps the p50 reply time under 20 seconds while still batching conversational multi-part messages. Webhook ordering is handled by the 8-second window (SMS delivery ordering is typically within milliseconds, not seconds).

### Webhook Architecture

**Cloudflare Workers for webhook ingestion.** Same pattern as 333Method (DR-031, DR-034):

- Twilio SMS webhook -> CF Worker -> validate signature -> insert to PG -> NOTIFY
- Twilio WhatsApp webhook -> CF Worker -> validate signature -> insert to PG -> NOTIFY
- Resend inbound email webhook -> CF Worker -> validate -> insert to PG -> NOTIFY
- Web chat WebSocket -> CF Durable Object -> insert to PG -> NOTIFY

The CF Worker does signature validation and basic rate limiting at the edge. No LLM calls happen in the Worker (10ms CPU limit). All intelligence is in the Node.js Reply Service on the VPS.

### Monitoring and Alerting

Per-tenant monitoring (reusable patterns from 333Method):

| Metric | Source | Alert Threshold |
|--------|--------|----------------|
| Reply latency (p95) | messages table (created_at delta between inbound and outbound) | >90 seconds |
| AI confidence (p10) | messages.ai_confidence | <0.3 for 5+ consecutive replies |
| Delivery failure rate | messages.delivery_status = 'failed' / total | >5% per channel per tenant |
| LLM cost per tenant per day | messages.ai_cost_usd | >$5/day (web chat cap) |
| Unhandled inbound (no reply generated) | inbound messages without a corresponding outbound within 15 min | Any occurrence |
| Channel halt | outreach-guard pattern | Any channel halted |

Monitoring runs as a cron job (same pattern as `pipeline-status-monitor.js` and `process-guardian.js` in 333Method). Alerts via the tenant dashboard (push notification) and internal Slack/email for system-level issues.

---

## 12. Trust Graduation UX

### The Problem

The tradie doesn't trust AI to send messages on their behalf — not on day one. But once they do trust it, they don't want to approve every message. The UX must build trust gradually.

### The Gmail "Undo Send" Pattern

Trust graduation mirrors Gmail's "Undo Send" feature: AI composes a reply, waits N seconds, then sends automatically — unless the owner intervenes. As trust grows, N shrinks.

```
Stage 1: Preview Only (default for new tenants)
  AI composes reply → push notification "New reply drafted"
  Owner must tap Approve before AI sends
  Window: unlimited (reply waits indefinitely)

Stage 2: Auto-send after timer (after 10 approved replies)
  AI composes reply → push notification with 5-minute countdown
  "Reply sending in 5:00 — Approve / Edit / Cancel"
  If owner doesn't act, sends automatically at T+5min
  N is configurable: 5 min default, adjustable to 2-30 min in settings

Stage 3: Fully automatic (after 30 approved/auto-sent replies)
  AI composes and sends immediately
  Push notification: "Replied to John about blocked drain [View]"
  Owner can still override retroactively (can't unsend SMS, but can send follow-up)
```

### Server-Side Implementation

```sql
-- pending_reply table
CREATE TABLE pending_replies (
    id              SERIAL PRIMARY KEY,
    tenant_id       INTEGER NOT NULL REFERENCES tenants(id),
    conversation_id INTEGER NOT NULL REFERENCES conversations(id),
    message_id      INTEGER REFERENCES messages(id), -- draft message
    draft_body      TEXT NOT NULL,
    stage           INTEGER NOT NULL DEFAULT 1, -- 1, 2, or 3
    send_at         TIMESTAMPTZ, -- NULL for stage 1; set for stage 2
    status          TEXT NOT NULL DEFAULT 'pending', -- pending, approved, edited, cancelled, sent
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_pending_replies_send_at ON pending_replies(send_at)
    WHERE status = 'pending' AND send_at IS NOT NULL;
```

Stage transitions are tracked per tenant using the `approved_reply_count` field on the tenants table. A cron job (`pending-reply-dispatcher.js`, runs every 30s) checks for `send_at < NOW()` and dispatches auto-send replies.

Push notification action buttons (Web Push API `actions` field):
- Stage 1: `[Approve] [Edit] [Cancel]`
- Stage 2: `[Send Now] [Edit] [Cancel]` (with countdown in body)

### What This Achieves

- Day 1: Owner sees the AI draft and approves it. Zero risk. Maximum transparency.
- Day 7: Auto-send with 5-minute window. Owner checks app periodically, cancels anything off.
- Month 2: Fully automatic. AI runs the inbox. Owner gets a daily summary.
- Graduated trust means churn is low: owners who hit Stage 3 are locked in.

---

## 13. Domain Connect (DNS Autoconfigure)

### The Problem

Setting up email DNS records (SPF/DKIM/DMARC for custom subdomain) requires the tradie to log into their domain registrar, navigate DNS settings, and add three records they don't understand. Most will give up or make errors.

### Domain Connect Standard

Domain Connect (IETF draft, open standard) is supported by all major registrars including Namecheap, GoDaddy, IONOS, Hostinger, 123-reg, and Cloudflare. It allows a service provider to configure DNS records on a user's domain via OAuth redirect — the user clicks "Connect DNS" and is redirected to their registrar, which applies the records after a single approval click.

```
1. During onboarding, user enters their domain
2. We detect their DNS provider via SOA/NS lookup
3. If Domain Connect supported: redirect to provider's DC OAuth endpoint
   → User sees: "ContactReplyAI wants to add these DNS records to yourdomain.com:
     reply._domainkey CNAME, reply TXT (SPF), _dmarc.reply TXT"
   → User clicks Approve
   → Records added automatically
4. If not supported: show manual DNS instructions (with copy-paste blocks)
5. We poll DNS to verify records propagated (up to 24h)
```

### Template Submission

We submit a Domain Connect template to the GitHub registry (https://github.com/Domain-Connect/Templates) so registrars can apply it directly. Template file: `templates/com.contactreplyai.dns/template.json`.

Required DNS records in template:
```json
{
  "providerId": "contactreplyai.com",
  "serviceId": "email-relay",
  "records": [
    { "type": "CNAME", "host": "reply._domainkey.@", "pointsTo": "resend._domainkey.resend.dev" },
    { "type": "TXT",   "host": "reply.@",             "data": "v=spf1 include:send.resend.dev ~all" },
    { "type": "TXT",   "host": "_dmarc.reply.@",       "data": "v=DMARC1; p=none; rua=mailto:dmarc@contactreplyai.com" }
  ]
}
```

Cloudflare natively supports Domain Connect (verified) — so the majority of AU SMBs using Cloudflare for DNS get one-click setup. Namecheap, GoDaddy, and IONOS support it for their own hosting customers.

**Fallback (manual):** Onboarding wizard shows copy-paste DNS records with a "Check DNS" button that polls for verification. Same pattern as Resend's domain verification flow.

---

## 14. Emergency Safety Response Framework

Per DR-161, when customers describe emergencies (gas leaks, flooding, electrical hazards), the AI must NOT free-form generate safety advice. The response must be templated, legally reviewed, and jurisdiction-appropriate.

### Three-Tier Framework

| Tier | Category | Examples | Response Approach |
|------|----------|----------|------------------|
| **Tier 1 (SAFE)** | Life-safety warnings from emergency services | "I smell gas", "fire", "electrocution", "drowning" | Immediately: emergency number + evacuate + call professional. Template only. |
| **Tier 2 (CAUTIOUS)** | Infrastructure interaction, standard homeowner actions | "flooding inside", "water everywhere", "tripping breaker" | Guided action: "turn off mains water at the meter" (with "if unsure, don't proceed" caveat). Template only. |
| **Tier 3 (NEVER)** | Technical diagnosis, gas infrastructure, professional-only procedures | "fix the pipe", "check the gas valve", "rewire" | Hard stop: "This needs a professional — calling me now would be the fastest option." No instructions. |

### Trigger Detection

Keywords are detected **before** the LLM call (in the CF Worker, sub-millisecond):

```javascript
const EMERGENCY_KEYWORDS = {
  tier1: ['gas leak', 'smell gas', 'fire', 'burning smell', 'electric shock',
          'sparking', 'flooding rapidly', 'water rising', 'roof collapse'],
  tier2: ['flooding', 'burst pipe', 'water everywhere', 'power out', 'no power',
          'tripped breaker', 'hot water system', 'leaking badly'],
};
```

When a Tier 1 keyword is detected, the reply bypasses the LLM entirely and returns a pre-authored template within <1 second. This is the highest-priority path — faster than any LLM call.

### Response Templates (Tier 1 example)

```
AU: "This sounds like an emergency. Please call 000 immediately if anyone is in danger.
For a gas leak: leave the building now, don't use any switches or lights, call 1800 GAS LINE
(1800 427 546) from outside. I've alerted {owner_name} — they'll call you as soon as possible.
[This is an automated response from an AI assistant for {business_name}]"

US: "This sounds like an emergency. Call 911 immediately if anyone is in danger.
For a gas leak: leave the building now, don't use any switches, call your gas company's
emergency line from outside. I've alerted {owner_name} and they'll reach out as soon as possible.
[This is an automated response from an AI assistant for {business_name}]"
```

All templates: localised by jurisdiction, include AI identification, include human callback assurance, and are stored in the `emergency_templates` table (not in source code) for quarterly review.

---

## 15. Localisation

### Why Localisation is a First-Class Concern

The same plumber in Sydney vs London vs Denver uses different words. An AI that says "heater" to a US customer asking about their "boiler" sounds wrong. An AI that says "arvo" to a US customer sounds weird. Localisation is not just spelling — it is trade terminology, emergency numbers, and cultural register.

### Scope

| Dimension | AU | US | UK |
|-----------|----|----|-----|
| Spelling | Australian English (-ise, colour, organise, aluminium) | American English (-ize, color, organize, aluminum) | British English (-ise, colour, organise, aluminium) |
| Trade terms | Tradie, hot water system, tap, render, weatherboard, tiler | Contractor, water heater, faucet, stucco, siding, tile contractor |
Tradesperson, boiler, tap, render, weatherboard, tiler |
| Business hours | 8am-8pm (Spam Act industry practice) | 8am-9pm ET/CT/MT/PT (TCPA) | 9am-8pm (PECR/industry) |
| Emergency numbers | 000, 13 XXXX utility lines | 911, utility emergency lines | 999, 105 (power), 0800 111 999 (gas) |
| Currency | AUD ($) | USD ($) | GBP (£) |
| Time zones | AEST/AEDT/AWST | ET/CT/MT/PT | GMT/BST |
| Distance | km | miles | miles |
| Temperature | Celsius | Fahrenheit | Celsius |

### Implementation

Localisation is resolved at the tenant level, not the message level. `tenants.settings.locale` stores `{ country: 'AU', spelling: 'en-AU', trade_terms: 'au' }`. The system prompt includes a locale block:

```
You are the AI assistant for {business_name}, an Australian {vertical} business.
Use Australian English spelling and terminology. Refer to the business owner as a "tradie"
only in casual contexts. Use "tap" not "faucet", "hot water system" not "water heater",
"arvo" only if the customer uses casual language first.
Emergency number: 000. Currency: AUD.
```

Vertical-specific term mappings (e.g., `hot_water_system_au`, `boiler_uk`, `water_heater_us`) are stored in the knowledge base vertical templates and injected into the system prompt. New vertical terms are added without code changes.

---

## 16. AI Phone Answering Bolt-On

Per DR-160, phone answering is a natural extension of the multi-channel autoresponder. It is NOT built in Phase 1.

### Phase 1: Resell My AI Front Desk (MAIFD) White-Label

- **Wholesale cost:** $54.99 USD/receptionist/month
- **Retail price:** $149 AUD/mo as a bolt-on to any ContactReplyAI plan
- **Gross margin:** ~47-55% per receptionist
- **Setup:** Sub-5 minutes. Tradie imports their Twilio number into MAIFD via the reseller dashboard.
- **AU caveat:** Uses US-accent voice by default. Configurable to more neutral English. Not ideal but viable for Phase 1.

### Phase 2: Johnni.ai Partnership (AU-Optimised)

Johnni.ai (Adelaide, SA) builds specifically for AU tradies: Aussie-accented AI, ServiceM8/Simpro/ServiceTitan native integrations, trained on Australian trade jargon. Explore reseller/OEM arrangement. If not available, use as affiliate/referral channel (20-30% recurring).

### Phase 3: DIY Build (Differentiation)

Twilio ConversationRelay + ElevenLabs custom voice + Claude (Haiku routing). Cost: ~$0.28-0.41 AUD/min. At typical tradie call volume (30 calls/mo × 3 min avg): ~$25-37 AUD/mo infrastructure cost. Charge $99-149 AUD/mo bolt-on = 60-74% gross margin. Timeline: 4-6 weeks dev. Full integration with ContactReplyAI dashboard (call transcripts, conversation continuity across SMS + voice).

---

## 17. Onboarding — Feature Waitlist Collection

During conversational SMS onboarding (Step 4), the system implicitly collects feature waitlist data by asking natural questions at the right moments. This data shapes the roadmap and surfaces pre-validated demand before features are built.

### Implicit Collection Pattern

```
After SMS channel setup:
"Do you use any calendar or booking software for managing your jobs?" 
→ Captures: CRM/job management interest

After email channel setup:
"Do you get messages on Facebook or Instagram from customers?"
→ Captures: Facebook Messenger / Instagram DM interest

After web chat widget setup:
"Do customers ever message you on Google Business?"
→ Captures: Google Business Messages interest

In profile setup:
"Do customers ever call a phone number and leave voicemails that you miss?"
→ Captures: AI phone answering interest

End of onboarding:
"One last thing — is there anything else you wish was easier about managing enquiries?"
→ Free-text, tagged by ops team
```

### Storage

```sql
CREATE TABLE waitlist_signals (
    id          SERIAL PRIMARY KEY,
    tenant_id   INTEGER NOT NULL REFERENCES tenants(id),
    feature     TEXT NOT NULL,  -- 'calendar_integration', 'crm', 'ai_phone', 'facebook', 'google_biz'
    response    TEXT,           -- raw answer text
    captured_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

Dashboard for Gary (product owner): waitlist count per feature → drives roadmap prioritisation.

---

## 18. Build Sequence

### Phase 1: MVP (4-6 weeks, 1 developer)

**Goal:** 5-10 beta tenants (AU plumbers), SMS + email only, PWA dashboard.

| Week | Deliverable |
|------|-------------|
| 1 | PostgreSQL schema, tenant CRUD, Channel Gateway (Twilio SMS webhook), basic Reply Service with hardcoded knowledge base |
| 2 | Email inbound (forwarding + Resend webhook), conversation threading, AI reply generation (Opus via Anthropic API) |
| 3 | PWA dashboard: conversation list, detail view, override/takeover, knowledge base editor |
| 4 | Onboarding flow: Twilio number provisioning, DNS verification for email, Stripe billing integration |
| 5 | Bot protection for web chat widget (proof of work, rate limiting), widget.js deployment |
| 6 | Beta launch: 5 AU plumbers, monitoring, iterate on knowledge base quality |

**What is NOT in Phase 1:**
- WhatsApp (requires Meta Business Verification, 2-4 weeks lead time -- start the process in Week 1)
- Booking system integration (AI captures intent, owner books manually)
- Number porting (dedicated Twilio number only)
- White-labeling
- Contact form forwarding (simple to add but not critical for MVP)

### Phase 2: Channel Expansion (Weeks 7-12)

- WhatsApp integration (Meta verification should be complete by now)
- Contact form forwarding (generic webhook endpoint)
- Web chat widget v2 (typing indicators, file upload for photos of plumbing issues)
- Complexity-based model routing (Haiku classifier -> Sonnet/Opus)
- Multi-language support (not for replies -- for the dashboard UI)

### Phase 3: Growth Features (Weeks 13-20)

- Booking integration (Cal.com self-hosted + Google Calendar sync)
- Number porting option (premium tier)
- Vertical expansion (electrical, cleaning, HVAC, landscaping)
- Referral system (tenant refers another tradie)
- Analytics dashboard (response time trends, conversation volume, AI vs human ratio)

### Phase 4: Scale (Weeks 21+)

- White-label for agencies
- n8n integration layer for long-tail booking platforms
- Capacitor native app wrapper
- Multi-region deployment (US, UK)
- SOC 2 compliance preparation

---

## Appendix: Reusable Components from Existing Stack

| Component | Source | Reuse Level |
|-----------|--------|-------------|
| Twilio SMS sending + rate limiting | 333Method `src/outreach/sms.js`, `src/utils/rate-limiter.js` | Direct reuse (extract to @mmo/outreach) |
| Twilio circuit breaker | 333Method `src/utils/circuit-breaker.js` | Direct reuse |
| Outreach reputation guard | 333Method `src/utils/outreach-guard.js` | Direct reuse |
| Reply classification | 333Method `src/utils/reply-classifier.js` | Adapt (different categories for autoresponder) |
| LLM provider routing | 333Method `src/utils/llm-provider.js` | Adapt (add Anthropic direct API alongside OpenRouter) |
| Compliance validation | 333Method `src/utils/compliance.js`, `sms-compliance.js` | Direct reuse |
| Suppression system | mmo-platform `src/suppression.js` | Direct reuse (already cross-project PG) |
| CF Worker webhook pattern | 333Method `workers/brand-api/` | Adapt (new routes, same pattern) |
| Cron framework | 333Method `src/cron/` | Direct reuse (add autoresponder service crons) |
| Logger | 333Method `src/utils/logger.js` | Direct reuse (extract to @mmo/core) |
| Spintax | 333Method `src/utils/spintax.js` | Reuse for template variation in canned responses |
| Phone normalisation | 333Method `src/utils/phone-normalizer.js` | Direct reuse |
| LLM sanitiser (jailbreak detection) | 333Method `src/utils/llm-sanitizer.js` | Critical reuse -- customer messages are untrusted input |

---

## Appendix: Cost Model (100 Tenants)

| Item | Monthly Cost |
|------|-------------|
| Twilio numbers (100 x $1.50) | $150 |
| Twilio SMS usage (20K messages x $0.0079 AU) | $158 |
| Twilio WhatsApp (5K conversations x $0.06) | $300 |
| Resend email (50K emails, Pro plan) | $20 |
| Anthropic API — Haiku classifier (30K calls x $0.001) | $30 |
| Anthropic API — Sonnet (24K replies x $0.007 avg) | $168 |
| Anthropic API — Opus (6K complex replies x $0.05 avg) | $300 |
| OpenRouter (overflow, ~5%) | $25 |
| Cloudflare Workers (Pro plan) | $5 |
| PostgreSQL (existing VPS, no additional cost) | $0 |
| NixOS VPS (existing Hostinger, no additional cost) | $0 |
| **Total infrastructure** | **~$1,156** |
| **Revenue (100 x $99 AUD/mo = ~$64 USD avg)** | **~$6,400 USD** |
| **Gross margin** | **~82%** |

With Haiku→Sonnet/Opus complexity routing (Section 6), LLM costs drop from a naive ~$1,500/mo (all Opus) to ~$498/mo — a 67% reduction. Routing 80% of calls to Sonnet and 20% to Opus is the key cost lever. AUD pricing: at 100 x $99 AUD = $9,900 AUD/mo, infrastructure cost in AUD (~$1,850 AUD at 0.625 exchange) gives ~81% gross margin.

---

## Appendix: Risk Register

| Risk | Impact | Likelihood | Mitigation |
|------|--------|-----------|------------|
| AI says something wrong (wrong price, wrong hours, makes a promise) | High (customer trust, legal) | Medium | Knowledge base is the single source of truth; AI instructed to say "let me check with {owner}" for anything not in KB; low-confidence replies flagged for human review |
| Twilio number spam-flagged | High (channel dead) | Low | Separate Messaging Service per tenant; reputation guard; 10DLC registration |
| LLM cost spike (bot attack on web chat) | Medium (margin erosion) | Medium | 7-layer defence (Section 7); per-tenant daily cap; circuit breaker at $5/day |
| Cross-tenant data leak | Critical | Very low | PG Row-Level Security; tenant_id in every query; middleware enforcement; security audit before launch |
| Tradie churns because AI replies are "not them" | High (business model) | Medium | Onboarding captures voice/personality; vertical templates; easy override; personality tuning in settings |
| WhatsApp template rejection by Meta | Medium (channel delayed) | Medium | Submit templates early; follow Meta's guidelines exactly; have fallback templates |
| Email forwarding stops working (tradie changes password, disables forwarding) | Medium (silent failure) | Medium | Daily heartbeat check: if no inbound email in 48h for an active tenant, alert |
