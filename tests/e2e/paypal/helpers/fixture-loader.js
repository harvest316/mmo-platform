/**
 * Fixture loader for committed PayPal webhook payloads.
 *
 * Fixtures live at fixtures/<handler>/<name>.json with shape:
 *   {
 *     headers: { "paypal-transmission-id": ..., ... },
 *     body_raw: "<stringified JSON>",
 *     body_parsed: { ... },
 *     notes: "..."
 *   }
 *
 * The `body_raw` field should ALWAYS be used when POSTing to a handler — the
 * `body_parsed` field is for test assertions / building expected rows.
 * Signatures won't validate on replay (fixtures were captured against a real
 * sandbox) so tests must mock /v1/notifications/verify-webhook-signature to
 * return SUCCESS.
 */

import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { randomUUID } from 'node:crypto';

const HERE = dirname(fileURLToPath(import.meta.url));
const FIXTURES_ROOT = join(HERE, '..', 'fixtures');

/**
 * Load a fixture by handler + name.
 *
 * @param {'api-php'|'crai-worker'|'m333-worker'} handler
 * @param {string} name  Filename (without .json extension).
 * @returns {Promise<{ headers: Record<string,string>, body_raw: string, body_parsed: object, notes?: string }>}
 */
export async function loadFixture(handler, name) {
  const path = join(FIXTURES_ROOT, handler, `${name}.json`);
  const raw = await readFile(path, 'utf8');
  return JSON.parse(raw);
}

/**
 * Return a shallow clone of the fixture with a fresh paypal-transmission-id so
 * idempotency tests can distinguish a "replay" from a "fresh delivery" when
 * the rest of the body is identical.
 *
 * Also bumps paypal-transmission-time to the current ISO timestamp so the
 * fixture looks like a brand-new webhook to any time-windowed checks.
 */
export function withFreshTransmissionId(fixture) {
  const headers = {
    ...fixture.headers,
    'paypal-transmission-id': randomUUID(),
    'paypal-transmission-time': new Date().toISOString(),
  };
  return { ...fixture, headers };
}

/**
 * Build a Fetch API Request from a fixture. Headers are lowercased. The body
 * is `body_raw` verbatim so signature verification (if not mocked to SUCCESS)
 * sees the exact bytes PayPal originally signed.
 *
 * @param {object} fixture    Loaded fixture.
 * @param {string} url        Absolute URL to POST to.
 * @param {object} [overrides] Optional overrides: `{ headers: {...}, body: '...' }`.
 */
export function buildRequestFromFixture(fixture, url, overrides = {}) {
  const headers = new Headers();
  for (const [k, v] of Object.entries(fixture.headers || {})) {
    headers.set(k, v);
  }
  if (overrides.headers) {
    for (const [k, v] of Object.entries(overrides.headers)) {
      if (v == null) headers.delete(k);
      else headers.set(k, v);
    }
  }
  return new Request(url, {
    method: 'POST',
    headers,
    body: overrides.body ?? fixture.body_raw,
  });
}

/**
 * List of all fixtures committed to the repo. Useful for parameterised tests
 * that want to iterate every event × every handler.
 */
export const FIXTURE_INDEX = {
  'api-php': [
    'subscription-activated-au-monthly4',
    'subscription-activated-us-monthly8',
    'subscription-activated-gb-monthly12',
    'subscription-cancelled',
    'subscription-suspended',
  ],
  'crai-worker': [
    'subscription-activated-founding',
    'subscription-activated-standard',
    'subscription-activated-sandbox',
    'subscription-renewed',
    'subscription-cancelled',
    'subscription-suspended',
    'payment-sale-completed',
    'payment-sale-denied',
  ],
  'm333-worker': [
    'checkout-order-approved',
    'payment-capture-completed',
    'payment-capture-denied',
    'payment-capture-refunded',
    'customer-dispute-created',
    'billing-subscription-created',
    'billing-subscription-cancelled',
    'billing-subscription-suspended',
    'billing-subscription-payment-failed',
    'billing-subscription-renewed',
  ],
};
