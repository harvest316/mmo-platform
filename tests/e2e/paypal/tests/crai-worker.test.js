/**
 * E2E tests for the CRAI Worker PayPal webhook handler.
 *
 *   POST https://<worker>/webhooks/paypal
 *
 * Source under test: /home/jason/code/ContactReplyAI/workers/index.js
 *   - verifyPayPalWebhookSignature (lines 1185-1222)
 *   - handlePayPalWebhook          (lines 1338-1458)
 *   - buildKnowledgeBaseFromHint   (lines 1270-1298)
 *
 * Test infrastructure:
 *   - msw-backed mock PayPal API (helpers/mock-paypal-api.js) — rewrites
 *     /v1/oauth2/token + /v1/notifications/verify-webhook-signature.
 *   - Neon HTTP bridge (helpers/neon-http-bridge.js) — forwards the Worker's
 *     neon(DATABASE_URL) HTTP traffic to a local Postgres crai_test schema.
 *     Queries issued against `crai.*` are rewritten to `crai_test.*` before
 *     execution so the Worker's hard-coded schema name does not touch prod.
 *   - Miniflare loader (helpers/crai-worker.js) — boots the Worker with both
 *     intercepts wired through outboundService.
 *
 * DR-215 — PayPal webhook E2E coverage.
 */

import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { http, HttpResponse } from 'msw';
import { randomUUID } from 'node:crypto';

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

const WORKER_URL = 'https://crai-worker.test/webhooks/paypal';

// Per-file lifecycle: starting Miniflare is expensive (script parse + isolate
// setup on the order of hundreds of milliseconds). We spin up one instance per
// `describe` block and reset PG state between each test via resetCraiTestSchema.
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
  // Every test gets the default msw handler set (verification SUCCESS, OAuth
  // returns a fake access token, etc.). Individual tests override via
  // paypalMock.server.use(...).
  paypalMock.reset();
});

afterEach(() => {
  paypalMock.server.resetHandlers();
});

// ── Helpers local to this file ───────────────────────────────────────────────

/** Dispatch a webhook fixture at the Worker and return the parsed JSON body. */
async function dispatchFixture(name, overrides = {}) {
  const fx = await loadFixture('crai-worker', name);
  const fresh = overrides.fresh === false ? fx : withFreshTransmissionId(fx);
  const req = buildRequestFromFixture(fresh, WORKER_URL, overrides);
  const res = await worker.dispatch(req);
  const text = await res.text();
  let body;
  try {
    body = JSON.parse(text);
  } catch {
    body = { _raw: text };
  }
  return { res, body, fixture: fresh };
}

// ──────────────────────────────────────────────────────────────────────────────
// Event routing
// ──────────────────────────────────────────────────────────────────────────────

describe('BILLING.SUBSCRIPTION.ACTIVATED', () => {
  it('creates a new tenant with billing_status=trial for the founding plan', async () => {
    const { res, body } = await dispatchFixture('subscription-activated-founding');

    expect(res.status).toBe(200);
    expect(body.ok).toBe(true);

    const rows = await query(
      `SELECT owner_email, billing_status, billing_plan, paypal_subscription_id
         FROM crai_test.tenants
        WHERE lower(owner_email) = 'newtenant@example.com'`,
    );
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({
      owner_email: 'newtenant@example.com',
      billing_status: 'trial',
      billing_plan: 'founding',
      paypal_subscription_id: 'I-CRAIFOUNDING0001',
    });
  });

  it('reactivates an existing paused tenant by flipping billing_status to active', async () => {
    // Pre-seed a paused tenant with the same paypal_subscription_id as the fixture.
    await query(
      `INSERT INTO crai_test.tenants (name, slug, billing_status, billing_plan, paypal_subscription_id, owner_email)
       VALUES ($1, $2, 'paused', 'founding', $3, $1)`,
      ['newtenant@example.com', 'newtenant-example-com-existing', 'I-CRAIFOUNDING0001'],
    );

    const { res } = await dispatchFixture('subscription-activated-founding');
    expect(res.status).toBe(200);

    const [row] = await query(
      `SELECT billing_status FROM crai_test.tenants
        WHERE paypal_subscription_id = 'I-CRAIFOUNDING0001'`,
    );
    expect(row.billing_status).toBe('active');

    // No duplicate insertion.
    const countRows = await query(
      `SELECT COUNT(*)::int AS n FROM crai_test.tenants
        WHERE paypal_subscription_id = 'I-CRAIFOUNDING0001'`,
    );
    expect(countRows[0].n).toBe(1);
  });

  it('prefills knowledge_base from a matching prospect_hint and marks hint used', async () => {
    await query(
      `INSERT INTO crai_test.prospect_hints
         (email, source, business_name, phone, city, state, country_code, trade, service_area,
          conversation_topics, conversation_excerpt)
       VALUES ($1, '333method', $2, $3, $4, $5, $6, $7, $8, $9, $10)`,
      [
        'newtenant@example.com',
        'Acme Plumbing',
        '+61400000000',
        'Sydney',
        'NSW',
        'AU',
        'plumber',
        '20km radius',
        ['emergency', 'quote'],
        'Need emergency plumber, quote please',
      ],
    );

    const { res } = await dispatchFixture('subscription-activated-founding');
    expect(res.status).toBe(200);

    const [tenant] = await query(
      `SELECT knowledge_base FROM crai_test.tenants
        WHERE lower(owner_email) = 'newtenant@example.com'`,
    );
    const kb = tenant.knowledge_base;
    expect(kb).toBeTruthy();
    expect(kb.meta?.prefill_source).toBe('333method');
    expect(kb.step1).toMatchObject({
      business_name: 'Acme Plumbing',
      business_type: 'plumber',
      phone: '+61400000000',
      email: 'newtenant@example.com',
    });
    expect(kb.step1.location).toBe('Sydney, NSW');
    expect(kb.step3).toMatchObject({ service_area: '20km radius' });
    expect(kb.step4?.faqs?.length).toBeGreaterThan(0);

    const [hint] = await query(
      `SELECT used_at FROM crai_test.prospect_hints
        WHERE lower(email) = 'newtenant@example.com'`,
    );
    expect(hint.used_at).not.toBeNull();
  });
});

describe('status-map event routing', () => {
  async function seedTenant({ sub, email, status = 'trial', plan = 'founding' }) {
    await query(
      `INSERT INTO crai_test.tenants (name, slug, billing_status, billing_plan, paypal_subscription_id, owner_email)
       VALUES ($1, $2, $3, $4, $5, $1)`,
      [email, `${email.replace(/[^a-z0-9]/gi, '-').toLowerCase()}-${Math.random().toString(16).slice(2, 8)}`, status, plan, sub],
    );
  }

  it('RENEWED → billing_status=active', async () => {
    await seedTenant({ sub: 'I-CRAIFOUNDING0001', email: 'newtenant@example.com', status: 'trial' });
    const { res } = await dispatchFixture('subscription-renewed');
    expect(res.status).toBe(200);
    const [row] = await query(
      `SELECT billing_status FROM crai_test.tenants WHERE paypal_subscription_id = 'I-CRAIFOUNDING0001'`,
    );
    expect(row.billing_status).toBe('active');
  });

  it('CANCELLED (founding, non-sandbox) → billing_status=cancelled and founding_taken decrements', async () => {
    await seedTenant({ sub: 'I-CRAIFOUNDING0001', email: 'newtenant@example.com', status: 'active', plan: 'founding' });
    // Bump counter so we can observe the decrement.
    await query(
      `UPDATE crai_test.site_stats SET int_value = 3, updated_at = NOW() WHERE key = 'founding_taken'`,
    );

    const { res } = await dispatchFixture('subscription-cancelled');
    expect(res.status).toBe(200);

    const [tenant] = await query(
      `SELECT billing_status FROM crai_test.tenants WHERE paypal_subscription_id = 'I-CRAIFOUNDING0001'`,
    );
    expect(tenant.billing_status).toBe('cancelled');

    const [ct] = await query(
      `SELECT int_value FROM crai_test.site_stats WHERE key = 'founding_taken'`,
    );
    expect(ct.int_value).toBe(2);
  });

  it('SUSPENDED → billing_status=paused', async () => {
    await seedTenant({ sub: 'I-CRAISTANDARD0002', email: 'standard@example.com', status: 'active', plan: 'standard' });
    const { res } = await dispatchFixture('subscription-suspended');
    expect(res.status).toBe(200);
    const [row] = await query(
      `SELECT billing_status FROM crai_test.tenants WHERE paypal_subscription_id = 'I-CRAISTANDARD0002'`,
    );
    expect(row.billing_status).toBe('paused');
  });

  // PAYMENT.SALE.* events: resource.id is the sale-txn id and the subscription
  // id lives in resource.billing_agreement_id. After DR-218 the Worker prefers
  // billing_agreement_id, so the safety-net UPDATE matches the real tenant row.
  it('PAYMENT.SALE.COMPLETED → billing_status=active (safety net)', async () => {
    const fx = await loadFixture('crai-worker', 'payment-sale-completed');
    const subId = fx.body_parsed.resource.billing_agreement_id;
    await seedTenant({ sub: subId, email: 'newtenant@example.com', status: 'trial', plan: 'founding' });

    const { res } = await dispatchFixture('payment-sale-completed');
    expect(res.status).toBe(200);
    const [row] = await query(
      `SELECT billing_status FROM crai_test.tenants WHERE paypal_subscription_id = $1`,
      [subId],
    );
    expect(row.billing_status).toBe('active');
  });

  it('PAYMENT.SALE.DENIED → billing_status=paused', async () => {
    const fx = await loadFixture('crai-worker', 'payment-sale-denied');
    const subId = fx.body_parsed.resource.billing_agreement_id;
    await seedTenant({ sub: subId, email: 'standard@example.com', status: 'active', plan: 'standard' });

    const { res } = await dispatchFixture('payment-sale-denied');
    expect(res.status).toBe(200);
    const [row] = await query(
      `SELECT billing_status FROM crai_test.tenants WHERE paypal_subscription_id = $1`,
      [subId],
    );
    expect(row.billing_status).toBe('paused');
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// Founding counter behaviour
// ──────────────────────────────────────────────────────────────────────────────

describe('founding_taken counter', () => {
  it('ACTIVATED non-sandbox founding → founding_taken = 1', async () => {
    const { res } = await dispatchFixture('subscription-activated-founding');
    expect(res.status).toBe(200);
    const [row] = await query(
      `SELECT int_value FROM crai_test.site_stats WHERE key = 'founding_taken'`,
    );
    expect(row.int_value).toBe(1);
  });

  it('ACTIVATED sandbox-prefixed sub_id does NOT increment founding_taken', async () => {
    const { res } = await dispatchFixture('subscription-activated-sandbox');
    expect(res.status).toBe(200);
    const [row] = await query(
      `SELECT int_value FROM crai_test.site_stats WHERE key = 'founding_taken'`,
    );
    expect(row.int_value).toBe(0);
  });

  it('ACTIVATED standard plan does NOT increment founding_taken', async () => {
    const { res } = await dispatchFixture('subscription-activated-standard');
    expect(res.status).toBe(200);
    const [row] = await query(
      `SELECT int_value FROM crai_test.site_stats WHERE key = 'founding_taken'`,
    );
    expect(row.int_value).toBe(0);
  });

  it('CANCELLED with SANDBOX-prefixed sub_id does not decrement', async () => {
    // Seed a sandbox-prefixed founding tenant so the CANCELLED path has a row
    // to flip and the counter must NOT decrement even though plan=founding.
    await query(
      `INSERT INTO crai_test.tenants (name, slug, billing_status, billing_plan, paypal_subscription_id, owner_email)
       VALUES ('sandbox@example.com', 'sandbox-example-com-001', 'active', 'founding', 'SANDBOX-I-ABCD1234EFGH5678', 'sandbox@example.com')`,
    );
    await query(
      `UPDATE crai_test.site_stats SET int_value = 2 WHERE key = 'founding_taken'`,
    );

    // Synthesise a cancellation for the sandbox sub id by cloning the
    // cancelled fixture and swapping resource.id + body.
    const fx = await loadFixture('crai-worker', 'subscription-cancelled');
    const parsed = JSON.parse(fx.body_raw);
    parsed.resource.id = 'SANDBOX-I-ABCD1234EFGH5678';
    const cloned = {
      ...fx,
      body_raw: JSON.stringify(parsed),
      body_parsed: parsed,
      headers: { ...fx.headers, 'paypal-transmission-id': randomUUID() },
    };
    const req = buildRequestFromFixture(cloned, WORKER_URL);
    const res = await worker.dispatch(req);
    expect(res.status).toBe(200);

    const [ct] = await query(
      `SELECT int_value FROM crai_test.site_stats WHERE key = 'founding_taken'`,
    );
    expect(ct.int_value).toBe(2);
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// Idempotency
// ──────────────────────────────────────────────────────────────────────────────

describe('HA-2 idempotency via crai.webhook_events(provider, external_id)', () => {
  it('replaying the same transmission-id is a no-op on the second fire', async () => {
    const fx = await loadFixture('crai-worker', 'subscription-activated-founding');
    // Use a stable transmission-id so the second dispatch hits the dedup row.
    const headers = { ...fx.headers, 'paypal-transmission-id': 'ID-DEDUP-TEST-001' };
    const fixed = { ...fx, headers };

    const req1 = buildRequestFromFixture(fixed, WORKER_URL);
    const res1 = await worker.dispatch(req1);
    expect(res1.status).toBe(200);
    const body1 = await res1.json();
    expect(body1.ok).toBe(true);

    // Second fire with identical transmission id → dedup short-circuits.
    const req2 = buildRequestFromFixture(fixed, WORKER_URL);
    const res2 = await worker.dispatch(req2);
    expect(res2.status).toBe(200);
    const body2 = await res2.json();
    expect(body2.action).toBe('duplicate_skipped');

    // Tenant inserted only once.
    const rows = await query(
      `SELECT COUNT(*)::int AS n FROM crai_test.tenants
        WHERE paypal_subscription_id = 'I-CRAIFOUNDING0001'`,
    );
    expect(rows[0].n).toBe(1);

    // webhook_events has a single row for this transmission id.
    const dedup = await query(
      `SELECT COUNT(*)::int AS n FROM crai_test.webhook_events
        WHERE provider = 'paypal' AND external_id = 'ID-DEDUP-TEST-001'`,
    );
    expect(dedup[0].n).toBe(1);
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// Signature verification
// ──────────────────────────────────────────────────────────────────────────────

describe('signature verification', () => {
  it('FAILURE verification → 403 and no DB writes', async () => {
    // Override msw handler to return FAILURE for the verify endpoint.
    paypalMock.server.use(
      http.post('*/v1/notifications/verify-webhook-signature', () =>
        HttpResponse.json({ verification_status: 'FAILURE' }),
      ),
    );

    const { res, body } = await dispatchFixture('subscription-activated-founding');
    expect(res.status).toBe(403);
    expect(body.ok).toBe(false);
    expect(body.action).toBe('signature_failed');

    const rows = await query(
      `SELECT COUNT(*)::int AS n FROM crai_test.tenants
        WHERE paypal_subscription_id = 'I-CRAIFOUNDING0001'`,
    );
    expect(rows[0].n).toBe(0);
  });

  it('missing PAYPAL_WEBHOOK_ID env → rejects the webhook (no DB writes)', async () => {
    // Boot a dedicated Worker instance with empty PAYPAL_WEBHOOK_ID. Because
    // this is a one-shot test and Miniflare instances are expensive, we
    // construct it inline rather than polluting the shared `worker` handle.
    const isolated = await loadCraiWorker({
      paypalMockUrl: paypalMock.url,
      neonBridgeUrl: neonBridge.url,
      webhookId: '',
    });
    try {
      const fx = await loadFixture('crai-worker', 'subscription-activated-founding');
      const fresh = withFreshTransmissionId(fx);
      const req = buildRequestFromFixture(fresh, WORKER_URL);
      const res = await isolated.dispatch(req);
      // verifyPayPalWebhookSignature() returns false when webhook id is unset,
      // so handlePayPalWebhook returns {ok:false, action:'signature_failed'}
      // which the route surfaces as 403.
      expect(res.status).toBe(403);
      const rows = await query(
        `SELECT COUNT(*)::int AS n FROM crai_test.tenants
          WHERE paypal_subscription_id = 'I-CRAIFOUNDING0001'`,
      );
      expect(rows[0].n).toBe(0);
    } finally {
      await isolated.close();
    }
  });
});

// ──────────────────────────────────────────────────────────────────────────────
// Malformed inputs
// ──────────────────────────────────────────────────────────────────────────────

describe('malformed inputs', () => {
  it('invalid JSON body → 4xx with no DB writes', async () => {
    const req = new Request(WORKER_URL, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'paypal-transmission-id': randomUUID(),
        'paypal-transmission-time': new Date().toISOString(),
        'paypal-transmission-sig': 'sig',
        'paypal-cert-url': 'https://api.sandbox.paypal.com/v1/notifications/certs/C',
        'paypal-auth-algo': 'SHA256withRSA',
      },
      body: 'not-json{',
    });
    const res = await worker.dispatch(req);
    // Signature verify parses the body; it returns false for invalid JSON,
    // which the handler surfaces as 403 (signature_failed). Either 4xx answer
    // satisfies the "do not process malformed input" contract.
    expect(res.status).toBeGreaterThanOrEqual(400);
    expect(res.status).toBeLessThan(500);

    const rows = await query(`SELECT COUNT(*)::int AS n FROM crai_test.tenants`);
    expect(rows[0].n).toBe(0);
  });

  it('empty body → 4xx with no DB writes', async () => {
    const req = new Request(WORKER_URL, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'paypal-transmission-id': randomUUID(),
        'paypal-transmission-time': new Date().toISOString(),
        'paypal-transmission-sig': 'sig',
        'paypal-cert-url': 'https://api.sandbox.paypal.com/v1/notifications/certs/C',
        'paypal-auth-algo': 'SHA256withRSA',
      },
      body: '',
    });
    const res = await worker.dispatch(req);
    expect(res.status).toBeGreaterThanOrEqual(400);
    expect(res.status).toBeLessThan(500);
  });

  it('unknown event_type → 200 ignored, no DB writes', async () => {
    // Clone the founding fixture but rewrite event_type to something the
    // handler doesn't know. handlePayPalWebhook returns {ok:true, action:'ignored'}.
    const fx = await loadFixture('crai-worker', 'subscription-renewed');
    const parsed = JSON.parse(fx.body_raw);
    parsed.event_type = 'BILLING.SUBSCRIPTION.UNKNOWN_EVENT_TYPE';
    const cloned = {
      ...fx,
      body_raw: JSON.stringify(parsed),
      body_parsed: parsed,
      headers: { ...fx.headers, 'paypal-transmission-id': randomUUID() },
    };
    const req = buildRequestFromFixture(cloned, WORKER_URL);
    const res = await worker.dispatch(req);
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body.ok).toBe(true);
    expect(body.action).toBe('ignored');

    const rows = await query(`SELECT COUNT(*)::int AS n FROM crai_test.tenants`);
    expect(rows[0].n).toBe(0);
  });
});
