/**
 * E2E tests for the CRAI Worker's /api/internal/seed-prospect endpoint
 * (DR-208: 333Method cross-sell pre-population).
 *
 *   POST https://<worker>/api/internal/seed-prospect
 *   X-Seed-Secret: <env.SEED_API_SECRET>
 *   Body: { email, business_name?, phone?, city?, state?, country_code?,
 *           trade?, service_area?, conversation_topics?, conversation_excerpt? }
 *
 * Source under test: /home/jason/code/ContactReplyAI/workers/index.js
 *   - handleSeedProspect            (lines 1300-1336)
 *   - route + auth                  (lines 2759-2765)
 *   - buildKnowledgeBaseFromHint    (lines 1270-1298)
 *   - handlePayPalWebhook prefill   (lines 1396-1425)
 *
 * DR-215 — PayPal webhook E2E coverage (cross-sell pre-population subset).
 */

import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';

import { createPayPalMock } from '../helpers/mock-paypal-api.js';
import { loadCraiWorker } from '../helpers/crai-worker.js';
import { startNeonHttpBridge } from '../helpers/neon-http-bridge.js';
import {
  resetCraiTestSchema,
  setupCraiTestSchema,
  query,
} from '../helpers/neon-test.js';
import {
  loadFixture,
  withFreshTransmissionId,
  buildRequestFromFixture,
} from '../helpers/fixture-loader.js';

const WORKER_HOST = 'https://crai-worker.test';
const SEED_URL = `${WORKER_HOST}/api/internal/seed-prospect`;
const PAYPAL_URL = `${WORKER_HOST}/webhooks/paypal`;
const SEED_SECRET = 'TEST_SEED_SECRET';

let paypalMock;
let neonBridge;
let worker;

beforeAll(async () => {
  await setupCraiTestSchema();
  paypalMock = await createPayPalMock();
  neonBridge = await startNeonHttpBridge({ fromSchema: 'crai', toSchema: 'crai_test' });
  worker = await loadCraiWorker({
    paypalMockUrl: paypalMock.url,
    neonBridgeUrl: neonBridge.url,
  });
}, 60_000);

afterAll(async () => {
  await worker?.close();
  await neonBridge?.close();
  await paypalMock?.close();
}, 30_000);

beforeEach(async () => {
  await resetCraiTestSchema();
  paypalMock.reset();
});

afterEach(() => {
  paypalMock.server.resetHandlers();
});

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Build a POST request against /api/internal/seed-prospect. */
function seedRequest(body, { secret = SEED_SECRET, headers = {} } = {}) {
  const hdrs = { 'content-type': 'application/json', ...headers };
  if (secret !== undefined && secret !== null) {
    hdrs['x-seed-secret'] = secret;
  }
  return new Request(SEED_URL, {
    method: 'POST',
    headers: hdrs,
    body: JSON.stringify(body),
  });
}

async function postSeed(body, opts) {
  const res = await worker.dispatch(seedRequest(body, opts));
  const text = await res.text();
  let parsed;
  try { parsed = JSON.parse(text); } catch { parsed = { _raw: text }; }
  return { res, body: parsed };
}

const FULL_PAYLOAD = {
  email: 'cross-sell@example.com',
  business_name: 'Acme Plumbing',
  phone: '+61400000000',
  city: 'Sydney',
  state: 'NSW',
  country_code: 'AU',
  trade: 'plumber',
  service_area: '20km radius from CBD',
  conversation_topics: ['emergency', 'quote', 'licensed'],
  conversation_excerpt: 'Prospect asked about emergency plumbing quotes last Tuesday.',
};

// ──────────────────────────────────────────────────────────────────────────────
// Happy path
// ──────────────────────────────────────────────────────────────────────────────

describe('POST /api/internal/seed-prospect — happy path', () => {
  it('inserts a prospect_hints row with every field populated', async () => {
    const { res, body } = await postSeed(FULL_PAYLOAD);
    expect(res.status).toBe(200);
    expect(body).toEqual({ ok: true });

    const rows = await query(
      `SELECT email, source, business_name, phone, city, state, country_code,
              trade, service_area, conversation_topics, conversation_excerpt,
              used_at
         FROM crai_test.prospect_hints
        WHERE lower(email) = 'cross-sell@example.com'`,
    );
    expect(rows).toHaveLength(1);
    const hint = rows[0];
    expect(hint).toMatchObject({
      email: 'cross-sell@example.com',
      source: '333method',
      business_name: 'Acme Plumbing',
      phone: '+61400000000',
      city: 'Sydney',
      state: 'NSW',
      country_code: 'AU',
      trade: 'plumber',
      service_area: '20km radius from CBD',
      conversation_excerpt: 'Prospect asked about emergency plumbing quotes last Tuesday.',
    });
    expect(hint.conversation_topics).toEqual(['emergency', 'quote', 'licensed']);
    expect(hint.used_at).toBeNull();
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// Upsert semantics
// ──────────────────────────────────────────────────────────────────────────────

describe('upsert on lower(email)', () => {
  it('second POST updates non-null fields and resets used_at=NULL', async () => {
    const first = await postSeed({
      email: 'UPPER-CASE@Example.COM',
      business_name: 'Original Name',
      trade: 'plumber',
      city: 'Sydney',
    });
    expect(first.res.status).toBe(200);

    // Mark the first row as "used" so we can detect the reset on upsert.
    await query(
      `UPDATE crai_test.prospect_hints SET used_at = NOW()
        WHERE lower(email) = 'upper-case@example.com'`,
    );

    const second = await postSeed({
      email: 'upper-case@example.com',
      business_name: 'Updated Name',
      phone: '+61411111111',
    });
    expect(second.res.status).toBe(200);

    const rows = await query(
      `SELECT email, business_name, phone, trade, city, used_at
         FROM crai_test.prospect_hints
        WHERE lower(email) = 'upper-case@example.com'`,
    );
    expect(rows).toHaveLength(1);
    const hint = rows[0];
    // email is stored lower-cased by handleSeedProspect.
    expect(hint.email).toBe('upper-case@example.com');
    // Non-null fields in the new payload win.
    expect(hint.business_name).toBe('Updated Name');
    expect(hint.phone).toBe('+61411111111');
    // Existing non-null fields survive when the new payload omits them
    // (COALESCE(EXCLUDED.x, crai.prospect_hints.x)).
    expect(hint.trade).toBe('plumber');
    expect(hint.city).toBe('Sydney');
    // used_at reset to NULL so the hint can be consumed again.
    expect(hint.used_at).toBeNull();
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// Auth
// ──────────────────────────────────────────────────────────────────────────────

describe('auth (X-Seed-Secret)', () => {
  it('missing X-Seed-Secret → 401 and no hint row', async () => {
    const { res, body } = await postSeed(FULL_PAYLOAD, { secret: null });
    expect(res.status).toBe(401);
    expect(body.error).toMatch(/Unauthorized/i);

    const rows = await query(`SELECT COUNT(*)::int AS n FROM crai_test.prospect_hints`);
    expect(rows[0].n).toBe(0);
  });

  it('wrong X-Seed-Secret → 401 and no hint row', async () => {
    const { res } = await postSeed(FULL_PAYLOAD, { secret: 'NOT-THE-REAL-SECRET' });
    expect(res.status).toBe(401);

    const rows = await query(`SELECT COUNT(*)::int AS n FROM crai_test.prospect_hints`);
    expect(rows[0].n).toBe(0);
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// Validation
// ──────────────────────────────────────────────────────────────────────────────

describe('payload validation', () => {
  it('missing email → 400', async () => {
    const { res, body } = await postSeed({ business_name: 'No Email Co' });
    expect(res.status).toBe(400);
    expect(body.error).toMatch(/email/i);

    const rows = await query(`SELECT COUNT(*)::int AS n FROM crai_test.prospect_hints`);
    expect(rows[0].n).toBe(0);
  });

  it('email without "@" → 400', async () => {
    const { res, body } = await postSeed({ email: 'not-an-email' });
    expect(res.status).toBe(400);
    expect(body.error).toMatch(/email/i);

    const rows = await query(`SELECT COUNT(*)::int AS n FROM crai_test.prospect_hints`);
    expect(rows[0].n).toBe(0);
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// Integration with BILLING.SUBSCRIPTION.ACTIVATED
// ──────────────────────────────────────────────────────────────────────────────

describe('integration with BILLING.SUBSCRIPTION.ACTIVATED', () => {
  it('seed a hint, then fire CRAI ACTIVATED → tenant.knowledge_base is prefilled and hint.used_at set', async () => {
    // 1. Seed a hint for newtenant@example.com (the email baked into the
    //    founding fixture).
    const seed = await postSeed({
      email: 'newtenant@example.com',
      business_name: 'Founding Plumber Co',
      phone: '+61444444444',
      city: 'Melbourne',
      state: 'VIC',
      country_code: 'AU',
      trade: 'plumber',
      service_area: 'Melbourne inner suburbs',
      conversation_topics: ['emergency', 'licensed'],
      conversation_excerpt: 'Asked about after-hours plumbing on last inbound reply.',
    });
    expect(seed.res.status).toBe(200);

    // 2. Fire the ACTIVATED webhook for the same email.
    const fx = await loadFixture('crai-worker', 'subscription-activated-founding');
    const fresh = withFreshTransmissionId(fx);
    const req = buildRequestFromFixture(fresh, PAYPAL_URL);
    const res = await worker.dispatch(req);
    expect(res.status).toBe(200);

    // 3. Tenant exists with prefilled knowledge_base.
    const [tenant] = await query(
      `SELECT knowledge_base FROM crai_test.tenants
        WHERE lower(owner_email) = 'newtenant@example.com'`,
    );
    expect(tenant).toBeTruthy();
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

    // 4. Hint is marked used.
    const [hint] = await query(
      `SELECT used_at FROM crai_test.prospect_hints
        WHERE lower(email) = 'newtenant@example.com'`,
    );
    expect(hint.used_at).not.toBeNull();
  });

  it('hint is consumed — subsequent ACTIVATED for the same email gets no prefill', async () => {
    // Seed + first activation exactly as above (shortened).
    await postSeed({
      email: 'newtenant@example.com',
      business_name: 'One-shot Hint',
      trade: 'plumber',
    });

    const fx = await loadFixture('crai-worker', 'subscription-activated-founding');
    const first = await worker.dispatch(
      buildRequestFromFixture(withFreshTransmissionId(fx), PAYPAL_URL),
    );
    expect(first.status).toBe(200);

    // Delete the first tenant and fire a second ACTIVATED for a brand-new
    // paypal_subscription_id but the same email. The handler will insert a
    // new tenant row and attempt a prospect_hints lookup — the existing hint
    // has `used_at IS NOT NULL`, so the WHERE clause excludes it and
    // knowledge_base stays empty.
    await query(
      `DELETE FROM crai_test.tenants WHERE lower(owner_email) = 'newtenant@example.com'`,
    );

    const parsed = JSON.parse(fx.body_raw);
    parsed.resource.id = 'I-CRAIFOUNDING0002';
    const cloned = {
      ...fx,
      body_raw: JSON.stringify(parsed),
      body_parsed: parsed,
    };
    const second = await worker.dispatch(
      buildRequestFromFixture(withFreshTransmissionId(cloned), PAYPAL_URL),
    );
    expect(second.status).toBe(200);

    const [tenant] = await query(
      `SELECT knowledge_base FROM crai_test.tenants
        WHERE paypal_subscription_id = 'I-CRAIFOUNDING0002'`,
    );
    expect(tenant).toBeTruthy();
    // Default empty JSONB ('{}') — no prefill.
    expect(tenant.knowledge_base).toEqual({});
  });
});
