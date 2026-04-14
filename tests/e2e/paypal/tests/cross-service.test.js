/**
 * Cross-service E2E: 333Method inbound reply → CRAI prospect seed → CRAI
 * subscription ACTIVATED → tenant knowledge_base prefilled.
 *
 * This test exercises the full signal chain across repo boundaries:
 *
 *   1. 333Method detects an inbound reply with an email address and records
 *      a cross-sell queue entry targeting CRAI.
 *   2. 333Method's CRAI prospect seeder (src/utils/crai-prospect-seeder.js,
 *      DR-208) POSTs the extracted prospect data to the CRAI Worker's
 *      /api/internal/seed-prospect endpoint with `X-Seed-Secret`.
 *   3. The CRAI Worker inserts a row into crai_test.prospect_hints (source
 *      column = '333method').
 *   4. Later, PayPal fires BILLING.SUBSCRIPTION.ACTIVATED for the same email
 *      against the CRAI Worker.
 *   5. The Worker's handlePayPalWebhook looks up prospect_hints by email,
 *      builds a knowledge_base payload via buildKnowledgeBaseFromHint(), and
 *      persists both the new tenants row AND stamps prospect_hints.used_at.
 *
 * DR-215 — cross-service PayPal + cross-sell E2E coverage.
 *
 * Source under test:
 *   - ~/code/333Method/src/utils/crai-prospect-seeder.js
 *     · seedProspectFromData()         (lines 220-250)
 *   - ~/code/ContactReplyAI/workers/index.js
 *     · handleSeedProspect             (lines 1300-1336)
 *     · buildKnowledgeBaseFromHint     (lines 1270-1298)
 *     · handlePayPalWebhook prefill    (lines 1396-1425)
 *
 * Why seedProspectFromData() vs seedProspect(contactId):
 *   `seedProspect(contactId)` reads hard-coded `msgs.contacts`, `m333.sites`,
 *   and `m333.messages` (production schemas) which cannot be swapped for test
 *   schemas without patching the 333Method source. `seedProspectFromData()` is
 *   the payload-driven sibling used from the 2Step PayPal webhook path (also
 *   DR-208) and exercises the same HTTP → Worker → DB signal chain without
 *   requiring a production schema clone.
 *
 *   The test still simulates the upstream queue row (inserted into
 *   m333_test.cross_sell_queue) to make the scenario readable — the seeder
 *   call uses a payload derived from that row's shape, matching what the
 *   full seedProspect() dispatcher would produce.
 */

import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';

import { createPayPalMock } from '../helpers/mock-paypal-api.js';
import { loadCraiWorker } from '../helpers/crai-worker.js';
import { startNeonHttpBridge } from '../helpers/neon-http-bridge.js';
import {
  resetCraiTestSchema,
  resetM333TestSchema,
  setupCraiTestSchema,
  setupM333TestSchema,
  query,
} from '../helpers/neon-test.js';
import {
  loadFixture,
  withFreshTransmissionId,
  buildRequestFromFixture,
} from '../helpers/fixture-loader.js';

// The 333Method seeder captures env vars into module-level `const`s at import
// time (WORKER_URL, SEED_API_SECRET — lines 20-21). We therefore need to set
// the env vars BEFORE the module is imported, and we import dynamically from
// within the suite so the Miniflare URL (unknown at file-load time) can be
// baked in.
const SEED_SECRET = 'TEST_SEED_SECRET';
const WRONG_SECRET = 'WRONG_SEED_SECRET';

let paypalMock;
let neonBridge;
let worker;
/** Dynamically-imported seeder module — loaded once Miniflare URL is known. */
let seeder;

beforeAll(async () => {
  await setupCraiTestSchema();
  await setupM333TestSchema();

  paypalMock = await createPayPalMock();
  neonBridge = await startNeonHttpBridge({ fromSchema: 'crai', toSchema: 'crai_test' });
  worker = await loadCraiWorker({
    paypalMockUrl: paypalMock.url,
    neonBridgeUrl: neonBridge.url,
  });

  // Miniflare exposes a real HTTP listener on `mf.ready` — the seeder's
  // fetch() can reach it the same way it would reach production.
  const workerUrl = (await worker.mf.ready).toString().replace(/\/$/, '');

  // Set env BEFORE dynamic-importing the seeder so its top-level `const`s
  // capture the right values. The seeder also imports 333Method's load-env.js
  // (dotenv) — dotenv never overwrites existing vars, so these stick.
  process.env.CRAI_WORKER_URL = workerUrl;
  process.env.CRAI_SEED_API_SECRET = SEED_SECRET;

  seeder = await import(
    '/home/jason/code/333Method/src/utils/crai-prospect-seeder.js'
  );
}, 60_000);

afterAll(async () => {
  await worker?.close();
  await neonBridge?.close();
  await paypalMock?.close();
}, 30_000);

beforeEach(async () => {
  await resetCraiTestSchema();
  await resetM333TestSchema();
  paypalMock.reset();
});

afterEach(() => {
  paypalMock.server.resetHandlers();
});

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Insert a simulated 333Method inbound-reply scenario:
 *  - m333_test.contacts          (email, phone, business_name, domain)
 *  - m333_test.cross_sell_queue  (target_project='crai', payload JSONB)
 *
 * Returns the queue row + derived seeder payload (what production's
 * seedProspect(contactId) would send, shaped to match seedProspectFromData).
 */
async function seedInboundReplyScenario(overrides = {}) {
  const scenario = {
    email: 'newtenant@example.com',
    phone: '+61444444444',
    business_name: 'Founding Plumber Co',
    domain: 'founding-plumber.com.au',
    city: 'Melbourne',
    state: 'VIC',
    country_code: 'AU',
    trade: 'plumber',
    service_area: 'Melbourne inner suburbs',
    conversation_topics: ['emergency', 'licensed'],
    conversation_excerpt:
      'Asked about after-hours plumbing on last inbound reply — wanted pricing and a licensed tradesperson.',
    ...overrides,
  };

  const [contact] = await query(
    `INSERT INTO m333_test.contacts (domain, business_name, phone, email, first_seen_project)
     VALUES ($1, $2, $3, $4, '333method')
     RETURNING id`,
    [scenario.domain, scenario.business_name, scenario.phone, scenario.email],
  );

  await query(
    `INSERT INTO m333_test.cross_sell_queue
       (contact_id, source_project, target_project, reason, status, payload)
     VALUES ($1, '333method', 'crai', 'positive_inbound_reply', 'pending', $2::jsonb)`,
    [
      contact.id,
      JSON.stringify({
        city: scenario.city,
        state: scenario.state,
        country_code: scenario.country_code,
        trade: scenario.trade,
        service_area: scenario.service_area,
        conversation_topics: scenario.conversation_topics,
        conversation_excerpt: scenario.conversation_excerpt,
      }),
    ],
  );

  // Shape matches what crai-prospect-seeder.js :: seedProspect() builds before
  // the fetch() call.
  const payload = {
    email: scenario.email,
    business_name: scenario.business_name,
    phone: scenario.phone,
    city: scenario.city,
    state: scenario.state,
    country_code: scenario.country_code,
    trade: scenario.trade,
    service_area: scenario.service_area,
    conversation_topics: scenario.conversation_topics,
    conversation_excerpt: scenario.conversation_excerpt,
  };

  return { scenario, payload, contactId: contact.id };
}

/** Fire the CRAI-founding ACTIVATED fixture, with optional email override. */
async function fireActivated({ email, subscriptionId } = {}) {
  const PAYPAL_URL = 'https://crai-worker.test/webhooks/paypal';
  const fx = await loadFixture('crai-worker', 'subscription-activated-founding');

  // Clone + rewrite email/id inside body_raw (signed bytes, verbatim to handler).
  const parsed = JSON.parse(fx.body_raw);
  if (email) parsed.resource.subscriber.email_address = email;
  if (subscriptionId) parsed.resource.id = subscriptionId;
  const mutated = {
    ...fx,
    body_raw: JSON.stringify(parsed),
    body_parsed: parsed,
  };
  const fresh = withFreshTransmissionId(mutated);
  const req = buildRequestFromFixture(fresh, PAYPAL_URL);
  return worker.dispatch(req);
}

// ──────────────────────────────────────────────────────────────────────────────
// Test 1 — Happy path
// ──────────────────────────────────────────────────────────────────────────────

describe('cross-service happy path: 333Method seeder → CRAI hint → CRAI ACTIVATED', () => {
  it('dispatches the seed, stores a hint, and prefills the tenant on activation', async () => {
    // 1. Simulate the upstream trigger: an inbound reply lands in the queue.
    const { payload } = await seedInboundReplyScenario();

    // Sanity: queue row exists so the scenario is realistic.
    const queueRows = await query(
      `SELECT target_project, status FROM m333_test.cross_sell_queue`,
    );
    expect(queueRows).toHaveLength(1);
    expect(queueRows[0].target_project).toBe('crai');

    // 2. Drive 333Method's production seeder (fire-and-forget in prod; awaited
    //    here for race-safe assertions).
    const result = await seeder.seedProspectFromData(payload);
    expect(result).toMatchObject({ ok: true, seeded: true });

    // 3. CRAI Worker should have persisted the hint.
    const hintRows = await query(
      `SELECT email, source, business_name, phone, city, state, country_code,
              trade, service_area, conversation_topics, conversation_excerpt,
              used_at
         FROM crai_test.prospect_hints
        WHERE lower(email) = 'newtenant@example.com'`,
    );
    expect(hintRows).toHaveLength(1);
    const hint = hintRows[0];
    expect(hint).toMatchObject({
      email: 'newtenant@example.com',
      source: '333method',
      business_name: 'Founding Plumber Co',
      phone: '+61444444444',
      city: 'Melbourne',
      state: 'VIC',
      country_code: 'AU',
      trade: 'plumber',
      service_area: 'Melbourne inner suburbs',
    });
    expect(hint.conversation_topics).toEqual(['emergency', 'licensed']);
    expect(hint.used_at).toBeNull();

    // 4. PayPal fires ACTIVATED for the same email.
    const res = await fireActivated({ email: 'newtenant@example.com' });
    expect(res.status).toBe(200);

    // 5. Tenant row exists with prefilled knowledge_base.
    const [tenant] = await query(
      `SELECT billing_status, billing_plan, knowledge_base
         FROM crai_test.tenants
        WHERE lower(owner_email) = 'newtenant@example.com'`,
    );
    expect(tenant).toBeTruthy();
    expect(tenant.billing_status).toBe('trial');
    expect(tenant.billing_plan).toBe('founding');

    const kb = tenant.knowledge_base;
    expect(kb.meta?.prefill_source).toBe('333method');
    expect(kb.meta?.has_conversation).toBe(true);
    expect(kb.meta?.conversation_topics).toEqual(['emergency', 'licensed']);
    expect(kb.step1).toMatchObject({
      business_name: 'Founding Plumber Co',
      business_type: 'plumber',
      phone: '+61444444444',
      email: 'newtenant@example.com',
      location: 'Melbourne, VIC',
    });
    expect(kb.step3?.service_area).toBe('Melbourne inner suburbs');
    expect(Array.isArray(kb.step4?.faqs)).toBe(true);
    expect(kb.step4.faqs.length).toBeGreaterThan(0);

    // 6. Hint has been consumed.
    const [usedHint] = await query(
      `SELECT used_at FROM crai_test.prospect_hints
        WHERE lower(email) = 'newtenant@example.com'`,
    );
    expect(usedHint.used_at).not.toBeNull();
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// Test 2 — Auth failure: wrong seed secret → no hint → no prefill
// ──────────────────────────────────────────────────────────────────────────────

describe('cross-service auth failure: wrong X-Seed-Secret → no prefill', () => {
  it('seeder returns 401, no hint row, subsequent ACTIVATED creates empty tenant', async () => {
    const { payload } = await seedInboundReplyScenario({
      email: 'unauth@example.com',
    });

    // Drive the same HTTP path the seeder uses, but with a mismatched secret.
    // We don't re-import the seeder module (its SEED_API_SECRET const is
    // captured at import time); instead we hit the same endpoint directly
    // with the wrong header, which exercises the identical Worker auth branch
    // the seeder would exercise if it were misconfigured.
    const workerUrl = process.env.CRAI_WORKER_URL;
    const resp = await fetch(`${workerUrl}/api/internal/seed-prospect`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Seed-Secret': WRONG_SECRET,
      },
      body: JSON.stringify(payload),
    });
    expect(resp.status).toBe(401);

    // No hint landed.
    const hintCount = await query(
      `SELECT COUNT(*)::int AS n FROM crai_test.prospect_hints`,
    );
    expect(hintCount[0].n).toBe(0);

    // Fire ACTIVATED for the same email — tenant should be created with an
    // empty knowledge_base because there's no hint to prefill from.
    const res = await fireActivated({
      email: 'unauth@example.com',
      // Unique subscription id to avoid colliding with any previous test row.
      subscriptionId: 'I-CRAIFOUNDINGAUTH01',
    });
    expect(res.status).toBe(200);

    const [tenant] = await query(
      `SELECT billing_status, billing_plan, knowledge_base
         FROM crai_test.tenants
        WHERE lower(owner_email) = 'unauth@example.com'`,
    );
    expect(tenant).toBeTruthy();
    expect(tenant.billing_status).toBe('trial');
    // Default empty JSONB — no prefill source available.
    expect(tenant.knowledge_base).toEqual({});
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// Test 3 — Queue-row derived payload round-trips cleanly
// ──────────────────────────────────────────────────────────────────────────────

describe('cross-service fidelity: queue payload survives the full round-trip', () => {
  it('every field seeded from the queue row appears in the prefilled tenant knowledge_base', async () => {
    const { payload } = await seedInboundReplyScenario({
      email: 'fidelity@example.com',
      business_name: 'Sparky Electrical',
      phone: '+61422333444',
      city: 'Brisbane',
      state: 'QLD',
      country_code: 'AU',
      trade: 'electrician',
      service_area: 'Brisbane northside',
      conversation_topics: ['quote', 'licensed', 'same_day'],
      conversation_excerpt:
        'Prospect wanted same-day licensed electrician for switchboard upgrade.',
    });

    const result = await seeder.seedProspectFromData(payload);
    expect(result.ok).toBe(true);

    const res = await fireActivated({
      email: 'fidelity@example.com',
      subscriptionId: 'I-CRAIFIDELITY0001',
    });
    expect(res.status).toBe(200);

    const [tenant] = await query(
      `SELECT knowledge_base FROM crai_test.tenants
        WHERE paypal_subscription_id = 'I-CRAIFIDELITY0001'`,
    );
    const kb = tenant.knowledge_base;
    expect(kb.meta.prefill_source).toBe('333method');
    expect(kb.meta.conversation_topics).toEqual(['quote', 'licensed', 'same_day']);
    expect(kb.step1).toMatchObject({
      business_name: 'Sparky Electrical',
      business_type: 'electrician',
      phone: '+61422333444',
      email: 'fidelity@example.com',
      location: 'Brisbane, QLD',
    });
    expect(kb.step3.service_area).toBe('Brisbane northside');
    // faqs should include entries for each recognised conversation topic.
    expect(kb.step4.faqs.length).toBeGreaterThanOrEqual(2);
  });
});
