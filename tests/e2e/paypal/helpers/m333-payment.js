/**
 * Host-side loader for the 333Method payment path.
 *
 * Directly imports:
 *   ~/code/333Method/src/payment/webhook-handler.js
 *     — exports processPaymentComplete(), createWebhookServer(),
 *       verifyWebhookSignature(), plus the private claimWebhook / verifyPaymentAmount
 *       via `export { ... }` at the bottom.
 *   ~/code/333Method/src/payment/poll-paypal-events.js
 *     — exports pollPayPalEvents() which fetches the R2 bucket and processes each event.
 *
 * DB note: 333Method uses PostgreSQL via pg.Pool (src/utils/db.js), NOT SQLite.
 * The pool uses DATABASE_URL + PG_SEARCH_PATH. For tests we override
 * PG_SEARCH_PATH to put m333_test in front of m333, so unqualified
 * `processed_webhooks`, `sites`, `messages`, `purchases` queries hit the test
 * schema.
 *
 * verifyPayment stubbing: webhook-handler.js imports
 *   verifyPayment from './paypal.js'
 * which calls PayPal's /v2/checkout/orders/:id endpoint. For tests we can
 * stub that via vi.mock() at the call site, or provide a PayPal API mock
 * that matches the /v2/checkout/orders/:id shape and lets the real path run.
 *
 * triggerFreshAssessment stubbing: the function is private inside
 * webhook-handler.js; we cannot stub it from here. The cleanest pattern is to
 * disable the downstream report generation by stubbing
 *   generateAuditReportForPurchase + deliverReport
 * (both imported at the top of webhook-handler.js) via vi.mock() in the test
 * file.
 */

import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));

/**
 * Absolute path to the 333Method repo root. Respects M333_REPO env override
 * for unusual layouts (e.g. running tests from outside the workspace).
 */
export const M333_REPO =
  process.env.M333_REPO ??
  join(HERE, '..', '..', '..', '..', '..', '333Method');

export const M333_WEBHOOK_HANDLER = join(M333_REPO, 'src/payment/webhook-handler.js');
export const M333_POLLER = join(M333_REPO, 'src/payment/poll-paypal-events.js');
export const M333_PAYPAL = join(M333_REPO, 'src/payment/paypal.js');
export const M333_REPORT_ORCHESTRATOR = join(M333_REPO, 'src/reports/report-orchestrator.js');
export const M333_REPORT_DELIVERY = join(M333_REPO, 'src/reports/report-delivery.js');

/**
 * Apply the test DB search path on the m333 pg pool by setting env vars
 * BEFORE the db.js module is first imported by webhook-handler.js.
 *
 * Must be called at the top of the test file (or in beforeAll) before any
 * dynamic import of webhook-handler.js. The pool is singleton-cached inside
 * db.js on first call to getPool().
 */
export function configureM333TestEnv({
  databaseUrl = process.env.DATABASE_URL ?? 'postgresql:///mmo?host=/run/postgresql',
  searchPath = 'm333_test, ops, tel, msgs, public',
} = {}) {
  process.env.DATABASE_URL = databaseUrl;
  process.env.PG_SEARCH_PATH = searchPath;
  // Signal to verifyPaymentAmount() in webhook-handler.js that it may skip
  // amount verification when SKIP_AMOUNT_VERIFICATION=true + NODE_ENV=test.
  // Tests can opt out of the skip by clearing SKIP_AMOUNT_VERIFICATION.
  process.env.NODE_ENV = 'test';
}

/**
 * Dynamic loader that returns the webhook handler module. Split out so the
 * caller can stub dependencies (verifyPayment, generateAuditReportForPurchase,
 * deliverReport) via vi.mock() BEFORE this import lands.
 */
export async function loadWebhookHandler() {
  return import(M333_WEBHOOK_HANDLER);
}

/**
 * Dynamic loader for the poller.
 */
export async function loadPoller() {
  return import(M333_POLLER);
}

/**
 * Convenience helper to stand up a tiny test R2 proxy so poll-paypal-events.js
 * can fetch events from it. poll-paypal-events.js calls
 *   fetch(`${PAYPAL_EVENTS_WORKER_URL}/paypal-events.json`, {
 *     headers: { 'X-Auth-Secret': PAYPAL_WORKER_SECRET }
 *   });
 *
 * For tests we can either:
 *   (a) point PAYPAL_EVENTS_WORKER_URL at the Miniflare m333 Worker (dispatch
 *       interface) — requires resolving Miniflare's listening URL, which is
 *       available via `mf.ready` resolved value + `mf.getWorkerHost()`.
 *   (b) start a bare node:http server in the test that returns a JSON array.
 *
 * This helper offers (b) — simpler, avoids Miniflare listener wiring for tests
 * that only care about the poller's DB side-effects.
 *
 * @param {Array<object>} events  Events to serve from /paypal-events.json.
 * @param {object} [opts]
 * @param {string} [opts.secret='TEST_WORKER_SECRET']  Required X-Auth-Secret.
 * @returns {Promise<{ url: string, close: () => Promise<void> }>}
 */
export async function startFakeEventsHost(events, { secret = 'TEST_WORKER_SECRET' } = {}) {
  const { createServer } = await import('node:http');
  const listener = createServer((req, res) => {
    if (req.url !== '/paypal-events.json' || req.method !== 'GET') {
      res.statusCode = 404;
      res.end();
      return;
    }
    if (req.headers['x-auth-secret'] !== secret) {
      res.statusCode = 401;
      res.end();
      return;
    }
    res.setHeader('content-type', 'application/json');
    res.end(JSON.stringify(events));
  });
  await new Promise((r) => listener.listen(0, '127.0.0.1', r));
  const { port } = listener.address();
  return {
    url: `http://127.0.0.1:${port}`,
    close: async () => new Promise((r) => listener.close(r)),
  };
}
