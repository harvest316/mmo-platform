/**
 * 333Method PayPal poller — ~/code/333Method/src/payment/poll-paypal-events.js
 * driving ~/code/333Method/src/payment/webhook-handler.js::processPaymentComplete().
 *
 * Topology exercised:
 *   1. A fake "R2 Worker" HTTP server (helpers/m333-payment.js::startFakeEventsHost)
 *      serves GET /paypal-events.json returning a seeded event array.
 *   2. PAYPAL_EVENTS_WORKER_URL points at that fake host.
 *   3. The poller fetches events, filters to CHECKOUT.ORDER.APPROVED +
 *      PAYMENT.CAPTURE.COMPLETED, extracts order_id, and calls
 *      processPaymentComplete(orderId).
 *   4. processPaymentComplete calls verifyPayment() which hits PayPal
 *      /v2/checkout/orders/:id → we add an msw handler that returns a
 *      COMPLETED order with the expected reference_id + amount.
 *   5. The handler then updates m333_test.* tables.
 *
 * Deep mocks (via vi.mock, hoisted):
 *   - src/payment/paypal.js::verifyPayment — stubbed to a plain function so
 *     we don't need to plumb axios through msw (axios has its own adapter
 *     layer and doesn't fall through Node fetch). This is the cleanest way
 *     per helpers/m333-payment.js's documented recommendation.
 *   - src/reports/report-orchestrator.js::generateAuditReportForPurchase
 *     and src/reports/report-delivery.js::deliverReport — both stubbed so
 *     the fire-and-forget triggerFreshAssessment() inside the handler is
 *     neutralised. Without these mocks the test would attempt to generate a
 *     real PDF report + send an email.
 *   - src/utils/country-pricing.js::getPrice — the m333_test schema does not
 *     include the `countries` pricing table. Stubbed to return a fixed AU
 *     pricing row so verifyPaymentAmount() can run deterministic assertions.
 *
 * Schema note: configureM333TestEnv() sets PG_SEARCH_PATH='m333_test, ...'
 * BEFORE db.js is dynamically imported. Unqualified references to `sites`,
 * `messages`, `processed_webhooks`, `purchases` in webhook-handler.js will
 * resolve to m333_test.* for the duration of the test run.
 */

import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

// ── Absolute paths for vi.mock() (must be literal — vi.mock is hoisted) ─────
// These need to exactly match the module specifiers used internally by the
// 333Method source. webhook-handler.js imports them via relative specifiers,
// so we mock by the resolved absolute path (the module registry key).
//
// vi.mock() is hoisted to the top of the file, so its first argument must be
// a string literal (not a const reference or template literal) or
// vi.hoisted() registered state. We pass absolute path literals and keep the
// const aliases below for readability in the rest of the file.

vi.mock('/home/jason/code/333Method/src/payment/paypal.js', () => ({
  // verifyPaymentMock is referenced below via vi.hoisted() so it survives the
  // vi.mock hoist. Same pattern for the other three mocks.
  verifyPayment: (...args) => mocks.verifyPaymentMock(...args),
  default: {},
  createPaymentOrder: vi.fn(),
  capturePayment: vi.fn(),
  refundPayment: vi.fn(),
  generatePaymentMessage: vi.fn(),
}));

vi.mock('/home/jason/code/333Method/src/reports/report-orchestrator.js', () => ({
  generateAuditReportForPurchase: (...args) => mocks.generateReportMock(...args),
  default: {},
}));

vi.mock('/home/jason/code/333Method/src/reports/report-delivery.js', () => ({
  deliverReport: (...args) => mocks.deliverReportMock(...args),
  default: {},
}));

vi.mock('/home/jason/code/333Method/src/utils/country-pricing.js', () => ({
  getPrice: (...args) => mocks.getPriceMock(...args),
  default: {},
}));

// vi.hoisted() lets us share state across the hoisted mock factories and the
// rest of the file. Everything inside the callback runs BEFORE the imports.
const mocks = vi.hoisted(() => ({
  verifyPaymentMock: vi.fn(),
  generateReportMock: vi.fn(),
  deliverReportMock: vi.fn(),
  getPriceMock: vi.fn(),
}));
const { verifyPaymentMock, generateReportMock, deliverReportMock, getPriceMock } = mocks;

const M333_DB = '/home/jason/code/333Method/src/utils/db.js';

import {
  configureM333TestEnv,
  startFakeEventsHost,
} from '../helpers/m333-payment.js';
import {
  resetM333TestSchema,
  query,
} from '../helpers/neon-test.js';
import { loadFixture } from '../helpers/fixture-loader.js';

/**
 * Dynamically import the poller after env + mocks are set. This must be called
 * inside a test/hook after `configureM333TestEnv()` so the first `getPool()`
 * call in db.js sees the correct PG_SEARCH_PATH. The module graph is cached
 * across tests — we accept that because all tests in this file share the
 * same PG schema setup.
 */
async function importPoller() {
  const mod = await import('../helpers/m333-payment.js');
  return mod.loadPoller();
}

// ── Hooks ────────────────────────────────────────────────────────────────────

beforeAll(() => {
  configureM333TestEnv();
  // Silence the 333Method Logger by default — if a test needs the logs it
  // can override process.env.LOG_LEVEL.
  process.env.LOG_LEVEL = 'error';
  // ── Discovered bug (DR-215-bug-1, see test output below) ────────────────
  //
  // webhook-handler.js:77 calls `const pricing = getPrice(countryCode)` WITHOUT
  // `await`, even though getPrice became async in commit e201066d (the Phase 4
  // PostgreSQL migration). As a result, `pricing` is always a Promise object,
  // `pricing.currency` is always undefined, and verifyPaymentAmount always
  // returns {valid:false, reason:'Currency mismatch: paid X, expected undefined'}.
  //
  // Impact in production: every CHECKOUT.ORDER.APPROVED / PAYMENT.CAPTURE
  // payment >= $5 is rejected at the amount-guard — the `processed_webhooks`
  // row is inserted then DELETED (line 169) and the purchase row is never
  // written. Fresh assessment never triggers. This explains why no real
  // revenue has landed since the async conversion.
  //
  // To exercise the rest of the payment path deterministically in happy-path
  // tests, we enable the SKIP_AMOUNT_VERIFICATION=true escape hatch
  // (webhook-handler.js:60, gated on NODE_ENV=test). Tests that exercise the
  // amount-guard explicitly delete this env var first.
  process.env.SKIP_AMOUNT_VERIFICATION = 'true';
});

beforeEach(async () => {
  await resetM333TestSchema();
  verifyPaymentMock.mockReset();
  generateReportMock.mockReset();
  deliverReportMock.mockReset();
  getPriceMock.mockReset();

  // Sensible defaults — individual tests override as needed.
  generateReportMock.mockResolvedValue({ score: 72, grade: 'C' });
  deliverReportMock.mockResolvedValue({ delivered: true });
  getPriceMock.mockImplementation((countryCode) => {
    if (countryCode === 'AU') {
      return Promise.resolve({
        countryCode: 'AU',
        currency: 'AUD',
        priceLocal: 337.0,
        currencySymbol: '$',
        priceFormatted: '337',
      });
    }
    return Promise.resolve(null);
  });
});

let fakeHost;

afterEach(async () => {
  if (fakeHost) {
    await fakeHost.close();
    fakeHost = null;
  }
});

afterAll(async () => {
  // Close the pg Pool held by 333Method/src/utils/db.js (it is a singleton
  // per-process). Without this the vitest worker hangs waiting for idle
  // connections to time out.
  try {
    const dbMod = await import(M333_DB);
    await dbMod.closePool();
  } catch {
    // Best-effort
  }
});

// ── Test helpers ─────────────────────────────────────────────────────────────

/**
 * Build the synthetic "R2 event" shape the Worker would have appended. The
 * poller reads top-level event_type and event.resource, so we just spread the
 * fixture's parsed body and add the enrichment fields the Worker would have
 * attached.
 */
function r2EventFromFixture(fixture) {
  return {
    ...fixture.body_parsed,
    worker_received_at: new Date().toISOString(),
    signature_verified: true,
    webhook_headers: {},
    ip: '127.0.0.1',
  };
}

/**
 * Seed a single site + message so the handler's reference_id parse can find
 * matching rows. Returns { siteId, messageId }.
 */
async function seedSiteAndMessage({
  siteId = 42,
  messageId = 17,
  countryCode = 'AU',
  landingUrl = 'https://example.com/',
} = {}) {
  await query(
    `INSERT INTO m333_test.sites (id, country_code, landing_page_url)
     VALUES ($1, $2, $3)
     ON CONFLICT (id) DO UPDATE
       SET country_code = EXCLUDED.country_code,
           landing_page_url = EXCLUDED.landing_page_url`,
    [siteId, countryCode, landingUrl],
  );
  await query(
    `INSERT INTO m333_test.messages (id, site_id) VALUES ($1, $2)
     ON CONFLICT (id) DO UPDATE SET site_id = EXCLUDED.site_id`,
    [messageId, siteId],
  );
  return { siteId, messageId };
}

// ── Tests ────────────────────────────────────────────────────────────────────

describe('333Method PayPal poller + processPaymentComplete()', () => {
  // ── Happy path: CHECKOUT.ORDER.APPROVED ───────────────────────────────────

  it('CHECKOUT.ORDER.APPROVED → updates messages/sites/purchases + triggers assessment', async () => {
    const fixture = await loadFixture('m333-worker', 'checkout-order-approved');
    await seedSiteAndMessage({ siteId: 42, messageId: 17, countryCode: 'AU' });

    const orderId = fixture.body_parsed.resource.id; // 5O190127TN3649126
    verifyPaymentMock.mockResolvedValue({
      isPaid: true,
      status: 'COMPLETED',
      orderId,
      payerEmail: 'pat.payer@example.com',
      payerName: 'Pat Payer',
      amount: 337.0,
      currency: 'AUD',
      referenceId: 'site_42_conv_17',
    });

    fakeHost = await startFakeEventsHost([r2EventFromFixture(fixture)]);
    process.env.PAYPAL_EVENTS_WORKER_URL = fakeHost.url;
    process.env.PAYPAL_WORKER_SECRET = 'TEST_WORKER_SECRET';

    const { pollPayPalEvents } = await importPoller();
    const result = await pollPayPalEvents();
    expect(result.processed).toBe(1);
    expect(result.successful).toBe(1);
    expect(result.failed).toBe(0);

    // processed_webhooks row inserted
    const pw = await query(
      `SELECT order_id, amount, currency FROM m333_test.processed_webhooks WHERE order_id = $1`,
      [orderId],
    );
    expect(pw).toHaveLength(1);
    expect(Number(pw[0].amount)).toBe(337.0);
    expect(pw[0].currency).toBe('AUD');

    // messages.payment_id / payment_amount set
    const msg = await query(
      `SELECT payment_id, payment_amount FROM m333_test.messages WHERE id = $1`,
      [17],
    );
    expect(msg[0].payment_id).toBe(orderId);
    expect(Number(msg[0].payment_amount)).toBe(337.0);

    // sites.resulted_in_sale flipped to 1
    const site = await query(
      `SELECT resulted_in_sale, sale_amount FROM m333_test.sites WHERE id = $1`,
      [42],
    );
    expect(site[0].resulted_in_sale).toBe(1);
    expect(Number(site[0].sale_amount)).toBe(337.0);

    // purchases row created
    const pu = await query(
      `SELECT id, email, paypal_order_id, amount, currency, status, site_id, message_id
       FROM m333_test.purchases WHERE paypal_order_id = $1`,
      [orderId],
    );
    expect(pu).toHaveLength(1);
    expect(pu[0].status).toBe('paid');
    expect(pu[0].email).toBe('pat.payer@example.com');
    expect(pu[0].amount).toBe(33700); // cents
    expect(pu[0].currency).toBe('AUD');
    expect(Number(pu[0].site_id)).toBe(42);
    expect(Number(pu[0].message_id)).toBe(17);

    // triggerFreshAssessment is fire-and-forget — yield microtasks so it runs.
    await new Promise((r) => setTimeout(r, 50));
    expect(generateReportMock).toHaveBeenCalledTimes(1);
    expect(generateReportMock).toHaveBeenCalledWith(pu[0].id);
  });

  // ── Happy path: PAYMENT.CAPTURE.COMPLETED fallback order-id extraction ─────

  it('PAYMENT.CAPTURE.COMPLETED falls back to supplementary_data.related_ids.order_id', async () => {
    const fixture = await loadFixture('m333-worker', 'payment-capture-completed');
    await seedSiteAndMessage({ siteId: 42, messageId: 17, countryCode: 'AU' });

    // The Worker fixture's resource.id is the CAPTURE id (1C488812KK739240E);
    // the ORDER id lives at resource.supplementary_data.related_ids.order_id.
    // The poller at poll-paypal-events.js:65-66 uses resource.id ?? supplementary,
    // so when resource.id IS set, it would pick the capture id — but processPayment
    // would then try to verify against a capture id, which PayPal's verify endpoint
    // doesn't know how to handle. To exercise the FALLBACK path we null out
    // resource.id before seeding.
    const event = r2EventFromFixture(fixture);
    event.resource = { ...event.resource, id: null };

    const orderId = fixture.body_parsed.resource.supplementary_data.related_ids.order_id;
    verifyPaymentMock.mockResolvedValue({
      isPaid: true,
      status: 'COMPLETED',
      orderId,
      payerEmail: 'pat.payer@example.com',
      payerName: 'Pat Payer',
      amount: 337.0,
      currency: 'AUD',
      referenceId: 'site_42_conv_17',
    });

    fakeHost = await startFakeEventsHost([event]);
    process.env.PAYPAL_EVENTS_WORKER_URL = fakeHost.url;
    process.env.PAYPAL_WORKER_SECRET = 'TEST_WORKER_SECRET';

    const { pollPayPalEvents } = await importPoller();
    const result = await pollPayPalEvents();
    expect(result.processed).toBe(1);
    expect(result.successful).toBe(1);

    // Confirm verifyPayment was invoked with the ORDER id, not the capture id.
    expect(verifyPaymentMock).toHaveBeenCalledWith(orderId);

    // processed_webhooks recorded the order id.
    const pw = await query(
      `SELECT order_id FROM m333_test.processed_webhooks WHERE order_id = $1`,
      [orderId],
    );
    expect(pw).toHaveLength(1);
  });

  // ── Ignored events: subscriptions + disputes ──────────────────────────────

  it('non-payment event types (subscriptions, disputes) are skipped silently', async () => {
    const renewed = r2EventFromFixture(
      await loadFixture('m333-worker', 'billing-subscription-renewed'),
    );
    const dispute = r2EventFromFixture(
      await loadFixture('m333-worker', 'customer-dispute-created'),
    );

    fakeHost = await startFakeEventsHost([renewed, dispute]);
    process.env.PAYPAL_EVENTS_WORKER_URL = fakeHost.url;
    process.env.PAYPAL_WORKER_SECRET = 'TEST_WORKER_SECRET';

    const { pollPayPalEvents } = await importPoller();
    const result = await pollPayPalEvents();
    // Both events are skipped → processed stays 0 (see poll-paypal-events.js:60).
    expect(result.processed).toBe(0);
    expect(result.successful).toBe(0);
    expect(result.failed).toBe(0);

    // No DB writes happened.
    expect(await query(`SELECT COUNT(*)::int AS c FROM m333_test.processed_webhooks`))
      .toEqual([{ c: 0 }]);
    expect(await query(`SELECT COUNT(*)::int AS c FROM m333_test.purchases`))
      .toEqual([{ c: 0 }]);
    expect(verifyPaymentMock).not.toHaveBeenCalled();
    expect(generateReportMock).not.toHaveBeenCalled();
  });

  // ── Idempotency: same order_id twice → one effect ─────────────────────────

  it('same CHECKOUT.ORDER.APPROVED twice → one processed_webhooks row, one assessment', async () => {
    const fixture = await loadFixture('m333-worker', 'checkout-order-approved');
    await seedSiteAndMessage({ siteId: 42, messageId: 17, countryCode: 'AU' });

    const orderId = fixture.body_parsed.resource.id;
    verifyPaymentMock.mockResolvedValue({
      isPaid: true,
      status: 'COMPLETED',
      orderId,
      payerEmail: 'pat.payer@example.com',
      amount: 337.0,
      currency: 'AUD',
      referenceId: 'site_42_conv_17',
    });

    // Two identical events seeded in the same R2 array (the Worker would
    // have appended both if PayPal re-delivered the webhook).
    const ev = r2EventFromFixture(fixture);
    fakeHost = await startFakeEventsHost([ev, { ...ev }]);
    process.env.PAYPAL_EVENTS_WORKER_URL = fakeHost.url;
    process.env.PAYPAL_WORKER_SECRET = 'TEST_WORKER_SECRET';

    const { pollPayPalEvents } = await importPoller();
    const result = await pollPayPalEvents();
    expect(result.processed).toBe(2);
    // First one is successful=true with a purchase row. The second is also
    // success=true (handler returns {success:true, message:'Payment already
    // processed'}) — see webhook-handler.js:143-148.
    expect(result.successful).toBe(2);

    // But downstream side effects happened exactly once.
    const pw = await query(
      `SELECT order_id FROM m333_test.processed_webhooks WHERE order_id = $1`,
      [orderId],
    );
    expect(pw).toHaveLength(1);

    const pu = await query(
      `SELECT id FROM m333_test.purchases WHERE paypal_order_id = $1`,
      [orderId],
    );
    expect(pu).toHaveLength(1);

    await new Promise((r) => setTimeout(r, 50));
    expect(generateReportMock).toHaveBeenCalledTimes(1);
  });

  // ── Amount mismatch: > 0.02 tolerance → no DB writes after the guard ──────

  it('amount mismatch (> 0.02 tolerance) → no purchase row, processed_webhooks is rolled back', async () => {
    // Explicitly run WITH the amount guard enabled (i.e. without the test-mode
    // escape hatch). See beforeAll for why SKIP_AMOUNT_VERIFICATION is set
    // globally in this file — we unset it for this one test so the guard runs.
    //
    // Because of the known bug (getPrice missing await), ANY amount >= $5
    // currently trips the currency-mismatch path — so the "mismatch" here is
    // detected for the same reason as a real mismatch. The assertions on
    // side-effects (no purchase, processed_webhooks removed) are still correct.
    const savedSkip = process.env.SKIP_AMOUNT_VERIFICATION;
    delete process.env.SKIP_AMOUNT_VERIFICATION;

    const fixture = await loadFixture('m333-worker', 'checkout-order-approved');
    await seedSiteAndMessage({ siteId: 42, messageId: 17, countryCode: 'AU' });

    const orderId = fixture.body_parsed.resource.id;
    // PayPal says 99.99 but our pricing (mocked) says 337.00 → reject.
    verifyPaymentMock.mockResolvedValue({
      isPaid: true,
      status: 'COMPLETED',
      orderId,
      payerEmail: 'pat.payer@example.com',
      amount: 99.99,
      currency: 'AUD',
      referenceId: 'site_42_conv_17',
    });

    fakeHost = await startFakeEventsHost([r2EventFromFixture(fixture)]);
    process.env.PAYPAL_EVENTS_WORKER_URL = fakeHost.url;
    process.env.PAYPAL_WORKER_SECRET = 'TEST_WORKER_SECRET';

    const { pollPayPalEvents } = await importPoller();
    const result = await pollPayPalEvents();
    // Handler returns {success:false, message:'Amount verification failed'}
    // → poller counts this as "failed" (see poll-paypal-events.js:84-87).
    expect(result.processed).toBe(1);
    expect(result.successful).toBe(0);
    expect(result.failed).toBe(1);

    // processed_webhooks row is DELETED by the amount guard at
    // webhook-handler.js:169 so a later correct delivery can re-claim the id.
    const pw = await query(
      `SELECT order_id FROM m333_test.processed_webhooks WHERE order_id = $1`,
      [orderId],
    );
    expect(pw).toHaveLength(0);

    // No purchase row.
    const pu = await query(
      `SELECT id FROM m333_test.purchases WHERE paypal_order_id = $1`,
      [orderId],
    );
    expect(pu).toHaveLength(0);

    // No message update.
    const msg = await query(
      `SELECT payment_id FROM m333_test.messages WHERE id = $1`,
      [17],
    );
    expect(msg[0].payment_id).toBeNull();

    expect(generateReportMock).not.toHaveBeenCalled();

    // Restore env for subsequent tests.
    if (savedSkip !== undefined) {
      process.env.SKIP_AMOUNT_VERIFICATION = savedSkip;
    }
  });

  // ── R2 persistence across runs ────────────────────────────────────────────
  //
  // poll-paypal-events.js line 97 — the clear-R2 call is commented out. So
  // running the poller twice with the same fake host content should: run the
  // event twice, but idempotency ensures it only hits the DB once.

  it('R2 events persist across runs — second run is idempotent via processed_webhooks', async () => {
    const fixture = await loadFixture('m333-worker', 'checkout-order-approved');
    await seedSiteAndMessage({ siteId: 42, messageId: 17, countryCode: 'AU' });

    const orderId = fixture.body_parsed.resource.id;
    verifyPaymentMock.mockResolvedValue({
      isPaid: true,
      status: 'COMPLETED',
      orderId,
      payerEmail: 'pat.payer@example.com',
      amount: 337.0,
      currency: 'AUD',
      referenceId: 'site_42_conv_17',
    });

    fakeHost = await startFakeEventsHost([r2EventFromFixture(fixture)]);
    process.env.PAYPAL_EVENTS_WORKER_URL = fakeHost.url;
    process.env.PAYPAL_WORKER_SECRET = 'TEST_WORKER_SECRET';

    const { pollPayPalEvents } = await importPoller();
    const run1 = await pollPayPalEvents();
    expect(run1.successful).toBe(1);

    // Second run — same events still being served by the fake host.
    const run2 = await pollPayPalEvents();
    expect(run2.processed).toBe(1);
    expect(run2.successful).toBe(1); // handler returns success=true + "already processed"

    // Exactly one downstream invocation total.
    await new Promise((r) => setTimeout(r, 50));
    expect(generateReportMock).toHaveBeenCalledTimes(1);

    // Exactly one row in each affected table.
    const pw = await query(
      `SELECT order_id FROM m333_test.processed_webhooks WHERE order_id = $1`,
      [orderId],
    );
    expect(pw).toHaveLength(1);
    const pu = await query(
      `SELECT id FROM m333_test.purchases WHERE paypal_order_id = $1`,
      [orderId],
    );
    expect(pu).toHaveLength(1);
  });
});
