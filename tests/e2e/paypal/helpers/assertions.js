/**
 * Shared assertion helpers for PayPal E2E tests.
 *
 * Keeps repetitive "find row X, expect status Y" lookups out of individual
 * test files. All helpers throw if the expectation is not met so they can be
 * used both inside `expect(...).not.toThrow()` and as direct `await` calls.
 */

import { expect } from 'vitest';
import { findSubscription, readSubscriptions } from './sqlite-fixture.js';
import { query } from './neon-test.js';

// ── api.php / 2Step SQLite assertions ────────────────────────────────────────

/**
 * Assert a subscription row exists with the given id and (optionally) status.
 * Passes when status='active' if not specified.
 */
export function expectSubscriptionExists(siteDir, id, { sandbox = false, status = 'active' } = {}) {
  const row = findSubscription(siteDir, id, { sandbox });
  expect(row, `subscription ${id} not found in ${sandbox ? 'sandbox' : 'live'} DB`).toBeTruthy();
  expect(row.status).toBe(status);
  return row;
}

/**
 * Assert a subscription row does NOT exist (useful for cross-DB-contamination
 * checks between live + sandbox modes).
 */
export function expectNoSubscription(siteDir, id, { sandbox = false } = {}) {
  const row = findSubscription(siteDir, id, { sandbox });
  expect(row, `subscription ${id} unexpectedly present in ${sandbox ? 'sandbox' : 'live'} DB`).toBeNull();
}

/**
 * Assert the total row count of the subscriptions table.
 */
export function expectSubscriptionRowCount(siteDir, expected, { sandbox = false } = {}) {
  const rows = readSubscriptions(siteDir, { sandbox });
  expect(rows.length, `expected ${expected} rows, got ${rows.length}`).toBe(expected);
}

// ── CRAI Worker / Postgres assertions ────────────────────────────────────────

/**
 * Assert that exactly one tenant exists with the given email. Optionally check
 * billing_status and billing_plan.
 *
 * Uses the `crai_test` schema by default — override via `schema` param for
 * tests that wire the Worker against a different schema.
 */
export async function expectTenantExists(email, expected = {}, { schema = 'crai_test' } = {}) {
  const rows = await query(
    `SELECT id, owner_email, billing_status, billing_plan, paypal_subscription_id, knowledge_base
     FROM ${schema}.tenants WHERE lower(owner_email) = lower($1)`,
    [email],
  );
  expect(rows.length, `expected tenant for ${email}, found ${rows.length}`).toBe(1);
  const t = rows[0];
  if (expected.billing_status) expect(t.billing_status).toBe(expected.billing_status);
  if (expected.billing_plan) expect(t.billing_plan).toBe(expected.billing_plan);
  if (expected.paypal_subscription_id) {
    expect(t.paypal_subscription_id).toBe(expected.paypal_subscription_id);
  }
  return t;
}

/**
 * Assert a tenant with the given paypal_subscription_id exists + has a given
 * billing_status.
 */
export async function expectTenantStatus(subId, status, { schema = 'crai_test' } = {}) {
  const rows = await query(
    `SELECT id, billing_status FROM ${schema}.tenants WHERE paypal_subscription_id = $1`,
    [subId],
  );
  expect(rows.length, `no tenant for subscription ${subId}`).toBe(1);
  expect(rows[0].billing_status).toBe(status);
}

/**
 * Assert that the founding_taken counter equals an expected value.
 */
export async function expectFoundingCount(expected, { schema = 'crai_test' } = {}) {
  const rows = await query(
    `SELECT int_value FROM ${schema}.site_stats WHERE key = 'founding_taken'`,
  );
  expect(rows.length).toBe(1);
  expect(rows[0].int_value).toBe(expected);
}

/**
 * Assert idempotency row present in webhook_events for (provider, externalId).
 */
export async function expectWebhookDedupRecorded(provider, externalId, { schema = 'crai_test' } = {}) {
  const rows = await query(
    `SELECT processed_at FROM ${schema}.webhook_events
     WHERE provider = $1 AND external_id = $2`,
    [provider, externalId],
  );
  expect(rows.length, `no dedup row for ${provider}/${externalId}`).toBe(1);
}

// ── 333Method R2 Worker assertions ───────────────────────────────────────────

/**
 * Assert the R2 events bucket contains an event of the given type. The Worker
 * appends enrichment fields (worker_received_at, signature_verified, etc.)
 * which are checked opportunistically.
 *
 * @param {Array<object>} events     Output of m333Worker.readR2Events().
 * @param {string} eventType
 * @param {object} [expected]        Optional extra expectations on the match.
 */
export function expectR2EventAppended(events, eventType, expected = {}) {
  const match = events.find((e) => e.event_type === eventType);
  expect(match, `no R2 event with type ${eventType}`).toBeTruthy();
  expect(match.signature_verified, 'signature_verified missing/false').toBe(true);
  expect(match.worker_received_at, 'worker_received_at missing').toBeTruthy();
  if (eventType === 'CUSTOMER.DISPUTE.CREATED') {
    expect(match.disputed).toBe(true);
  }
  if (eventType.startsWith('BILLING.SUBSCRIPTION.')) {
    expect(match.subscription).toBe(true);
  }
  for (const [k, v] of Object.entries(expected)) {
    expect(match[k]).toEqual(v);
  }
  return match;
}

// ── 333Method payment path (Postgres) assertions ─────────────────────────────

/**
 * Assert that processPaymentComplete() inserted the idempotency row for an
 * order id in m333_test.processed_webhooks.
 */
export async function expectProcessedWebhook(orderId, { schema = 'm333_test' } = {}) {
  const rows = await query(
    `SELECT order_id, amount, currency FROM ${schema}.processed_webhooks WHERE order_id = $1`,
    [orderId],
  );
  expect(rows.length, `no processed_webhooks row for ${orderId}`).toBe(1);
  return rows[0];
}

/**
 * Assert that a purchase row exists for the given order id, optionally
 * checking status/amount/country.
 */
export async function expectPurchase(orderId, expected = {}, { schema = 'm333_test' } = {}) {
  const rows = await query(
    `SELECT id, email, paypal_order_id, amount, currency, status, site_id, message_id
     FROM ${schema}.purchases WHERE paypal_order_id = $1`,
    [orderId],
  );
  expect(rows.length, `no purchases row for ${orderId}`).toBe(1);
  const p = rows[0];
  if (expected.status) expect(p.status).toBe(expected.status);
  if (expected.amount != null) expect(Number(p.amount)).toBe(expected.amount);
  if (expected.currency) expect(p.currency).toBe(expected.currency);
  if (expected.site_id != null) expect(Number(p.site_id)).toBe(expected.site_id);
  if (expected.message_id != null) expect(Number(p.message_id)).toBe(expected.message_id);
  return p;
}
