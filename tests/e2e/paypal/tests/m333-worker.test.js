/**
 * 333Method PayPal R2 collector Worker — ~/code/333Method/workers/paypal-webhook/src/index.js.
 *
 * The Worker receives a PayPal webhook POST at /webhook/paypal, verifies the
 * signature via PayPal's verify-webhook-signature API, then APPENDS the event
 * (plus enrichment: worker_received_at, ip, webhook_headers, signature_verified,
 *  disputed?, subscription?) to the SINGLE R2 key `paypal-events.json`.
 *
 * Fixture note: the committed body_raw for each m333 fixture is a minimal
 * payload that does NOT include a top-level create_time field. The Worker
 * rejects events without create_time at src/index.js:149 ("Invalid event
 * structure"). These tests therefore POST `JSON.stringify(body_parsed)` —
 * which does contain create_time — as the raw body. This is fine for Worker
 * tests because the Worker only reads event_type / create_time / resource
 * from the parsed JSON — it never hashes the raw bytes itself (that's handled
 * by PayPal's verify-webhook-signature API, which is mocked in-test).
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { http, HttpResponse } from 'msw';

import { createPayPalMock } from '../helpers/mock-paypal-api.js';
import { loadM333Worker } from '../helpers/m333-worker.js';
import { loadFixture, FIXTURE_INDEX } from '../helpers/fixture-loader.js';

const WEBHOOK_URL = 'http://m333-worker.test/webhook/paypal';

/**
 * POST a fixture's parsed body (serialised) to the Worker with the fixture's
 * original PayPal headers. We use body_parsed not body_raw because the raw
 * form in the committed fixtures lacks top-level create_time (which the
 * Worker requires at src/index.js:149).
 */
async function dispatchFixture(m333, fixture, { urlOverride } = {}) {
  const body = JSON.stringify(fixture.body_parsed);
  const headers = {
    ...Object.fromEntries(
      Object.entries(fixture.headers).map(([k, v]) => [k, String(v)]),
    ),
    'content-type': 'application/json',
  };
  // Miniflare's dispatchFetch accepts a (url, init) signature — NOT a Request
  // instance directly (it re-constructs a Request and the Request() constructor
  // from its bundled undici barfs on `[object Request]`). So pass url + init.
  return m333.dispatch(urlOverride ?? WEBHOOK_URL, {
    method: 'POST',
    headers,
    body,
  });
}

/**
 * Per-test setup — a fresh mock PayPal bridge + a fresh Miniflare instance
 * with in-memory R2. The outbound service redirects api-m.*.paypal.com to the
 * mock bridge so the Worker's OAuth + verify-webhook-signature calls always
 * hit the mock, not the real PayPal API.
 */
async function bootWorker({ webhookId = 'TEST_WEBHOOK_ID', mockOpts = {} } = {}) {
  const mockApi = await createPayPalMock(mockOpts);
  const m333 = await loadM333Worker({
    paypalMockUrl: mockApi.url,
    webhookId,
    extraBindings: {
      ENVIRONMENT: 'test', // selects the sandbox hostname inside the Worker
    },
  });
  return { mockApi, m333 };
}

describe('333Method PayPal R2 collector Worker — /webhook/paypal', () => {
  let mockApi;
  let m333;

  beforeEach(async () => {
    ({ mockApi, m333 } = await bootWorker());
  });

  afterEach(async () => {
    if (m333) await m333.close();
    if (mockApi) await mockApi.close();
  });

  // ── All 10 supported events: one test per fixture ─────────────────────────
  //
  // Each assertion verifies that the appended R2 entry carries the enrichment
  // contract the poller + downstream code relies on:
  //   - event_type preserved
  //   - worker_received_at is an ISO-8601 string
  //   - webhook_headers captures the five PAYPAL-* transmission headers
  //   - signature_verified === true (we mock verify-webhook-signature SUCCESS)
  //   - disputed === true  ONLY for CUSTOMER.DISPUTE.CREATED
  //   - subscription === true ONLY for BILLING.SUBSCRIPTION.* events
  //
  // The body_parsed top-level fields are preserved via `...event` spread at
  // src/index.js:224, so resource.id etc. should still be reachable.

  const SUBSCRIPTION_EVENTS = new Set([
    'BILLING.SUBSCRIPTION.CREATED',
    'BILLING.SUBSCRIPTION.CANCELLED',
    'BILLING.SUBSCRIPTION.SUSPENDED',
    'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
    'BILLING.SUBSCRIPTION.RENEWED',
  ]);

  for (const fixtureName of FIXTURE_INDEX['m333-worker']) {
    it(`appends "${fixtureName}" event to R2 with full enrichment`, async () => {
      const fixture = await loadFixture('m333-worker', fixtureName);
      const expectedType = fixture.body_parsed.event_type;

      const before = await m333.readR2Events();
      const res = await dispatchFixture(m333, fixture);
      expect(res.status, `unexpected status for ${fixtureName}`).toBe(200);

      const after = await m333.readR2Events();
      expect(after.length).toBe(before.length + 1);

      const appended = after[after.length - 1];
      expect(appended.event_type).toBe(expectedType);
      // worker_received_at is emitted via new Date().toISOString() at src/index.js:226.
      expect(typeof appended.worker_received_at).toBe('string');
      expect(() => new Date(appended.worker_received_at).toISOString())
        .not.toThrow();
      // Sanity check: the ISO parser accepts it and the month/year line up.
      expect(new Date(appended.worker_received_at).getUTCFullYear())
        .toBeGreaterThanOrEqual(2020);

      // webhook_headers must carry all five transmission fields.
      expect(appended.webhook_headers).toBeTruthy();
      expect(appended.webhook_headers.transmissionId)
        .toBe(fixture.headers['paypal-transmission-id']);
      expect(appended.webhook_headers.transmissionTime)
        .toBe(fixture.headers['paypal-transmission-time']);
      expect(appended.webhook_headers.transmissionSig)
        .toBe(fixture.headers['paypal-transmission-sig']);
      expect(appended.webhook_headers.certUrl)
        .toBe(fixture.headers['paypal-cert-url']);
      expect(appended.webhook_headers.authAlgo)
        .toBe(fixture.headers['paypal-auth-algo']);

      // signature_verified is ALWAYS true in the appended record (the Worker
      // rejects non-verified events before reaching the R2 append).
      expect(appended.signature_verified).toBe(true);

      // Conditional flags.
      if (expectedType === 'CUSTOMER.DISPUTE.CREATED') {
        expect(appended.disputed).toBe(true);
      } else {
        expect(appended.disputed).toBeUndefined();
      }

      if (SUBSCRIPTION_EVENTS.has(expectedType)) {
        expect(appended.subscription).toBe(true);
      } else {
        expect(appended.subscription).toBeUndefined();
      }

      // Original payload preserved (top-level id + resource preserved via
      // `...event` spread at src/index.js:224).
      expect(appended.id).toBe(fixture.body_parsed.id);
      expect(appended.resource).toBeTruthy();
    });
  }

  // ── Signature failure ─────────────────────────────────────────────────────

  it('signature verification FAILURE → rejects event, R2 unchanged', async () => {
    // Override verify-webhook-signature to return FAILURE for all hosts.
    const hosts = [
      'https://api-m.paypal.com',
      'https://api-m.sandbox.paypal.com',
      'http://127.0.0.1:*',
      'http://localhost:*',
    ];
    mockApi.server.use(
      ...hosts.map((host) =>
        http.post(`${host}/v1/notifications/verify-webhook-signature`, () =>
          HttpResponse.json({ verification_status: 'FAILURE' }),
        ),
      ),
    );

    const fixture = await loadFixture('m333-worker', 'checkout-order-approved');
    const before = await m333.readR2Events();

    const res = await dispatchFixture(m333, fixture);
    // src/index.js:140 — returns 401 on failed signature verification.
    expect(res.status).toBe(401);
    const json = await res.json();
    expect(json.success).toBe(false);

    const after = await m333.readR2Events();
    expect(after.length).toBe(before.length); // unchanged
  });

  // ── Missing PAYPAL_WEBHOOK_ID ─────────────────────────────────────────────

  it('missing PAYPAL_WEBHOOK_ID binding → rejects event, R2 unchanged', async () => {
    // Tear down the default Worker and start a new one without the webhook id.
    await m333.close();
    await mockApi.close();
    ({ mockApi, m333 } = await bootWorker({ webhookId: '' }));

    const fixture = await loadFixture('m333-worker', 'checkout-order-approved');

    const res = await dispatchFixture(m333, fixture);
    // src/index.js:43-47 — short-circuits into verify failure path → 401.
    expect(res.status).toBe(401);
    const json = await res.json();
    expect(json.success).toBe(false);
    expect(json.error).toMatch(/verification failed/i);

    // Also verified in R2 → still empty.
    const events = await m333.readR2Events();
    expect(events.length).toBe(0);
  });

  // ── Sequential appends preserve order ─────────────────────────────────────

  it('three sequential events all appended in order, JSON remains valid', async () => {
    const fx1 = await loadFixture('m333-worker', 'checkout-order-approved');
    const fx2 = await loadFixture('m333-worker', 'payment-capture-completed');
    const fx3 = await loadFixture('m333-worker', 'billing-subscription-created');

    const r1 = await dispatchFixture(m333, fx1);
    expect(r1.status).toBe(200);
    const r2 = await dispatchFixture(m333, fx2);
    expect(r2.status).toBe(200);
    const r3 = await dispatchFixture(m333, fx3);
    expect(r3.status).toBe(200);

    const events = await m333.readR2Events();
    expect(events).toHaveLength(3);

    // Order preserved (Array.prototype.push semantics at src/index.js:224).
    expect(events[0].event_type).toBe('CHECKOUT.ORDER.APPROVED');
    expect(events[1].event_type).toBe('PAYMENT.CAPTURE.COMPLETED');
    expect(events[2].event_type).toBe('BILLING.SUBSCRIPTION.CREATED');

    // Distinct event ids — no accidental duplicate appends.
    const ids = events.map((e) => e.id);
    expect(new Set(ids).size).toBe(ids.length);

    // JSON round-trip of the raw object — catches accidental string corruption.
    const bucket = await m333.mf.getR2Bucket('PAYPAL_EVENTS_BUCKET');
    const obj = await bucket.get('paypal-events.json');
    const text = await obj.text();
    expect(() => JSON.parse(text)).not.toThrow();
    expect(JSON.parse(text)).toHaveLength(3);
  });
});
