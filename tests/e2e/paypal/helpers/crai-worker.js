/**
 * Miniflare loader for the CRAI Worker.
 *
 * Investigation note (2026-04-14): The CRAI Worker at
 *   ~/code/ContactReplyAI/workers/index.js
 * does NOT honour a PAYPAL_API_BASE env var. It hardcodes one of
 *   - https://api-m.paypal.com
 *   - https://api-m.sandbox.paypal.com
 * based on env.PAYPAL_MODE === 'live'. See lines 1165, 1191, 1744, 1785.
 *
 * Options for redirecting PayPal calls at test time:
 *   A) Patch the Worker to read PAYPAL_API_BASE (small upstream change).
 *   B) Use Miniflare's `fetchMock` / `outboundService` to intercept outbound
 *      fetch() requests at Worker level and forward them to the msw listener.
 *   C) Run msw's setupServer() in the host process — but that doesn't cover
 *      Miniflare outbound fetches, which bypass the host Node fetch() hook.
 *
 * Chosen approach: (B). We install a tiny outboundService that forwards any
 * request for api-m.(sandbox.)paypal.com to the msw-backed HTTP listener
 * returned by createPayPalMock(). This keeps the Worker unmodified.
 *
 * The helper returns a `dispatch(request)` function that wraps the Miniflare
 * instance's `dispatchFetch()`. Tests build a Request with PayPal headers
 * and call dispatch() to exercise /webhooks/paypal.
 */

import { Miniflare } from 'miniflare';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const CRAI_WORKER_PATH =
  process.env.CRAI_WORKER_PATH ??
  join(HERE, '..', '..', '..', '..', '..', 'ContactReplyAI', 'workers', 'index.js');

/**
 * @param {object} opts
 * @param {string} opts.paypalMockUrl   URL of the createPayPalMock() bridge listener.
 * @param {string} [opts.databaseUrl]   Neon/PG DSN. Defaults to unix socket + mmo DB
 *                                      with search_path set so qualified `crai.*`
 *                                      lookups are transparently rewritten to
 *                                      `crai_test.*`. See note below.
 * @param {string} [opts.webhookId='TEST_WEBHOOK_ID']
 * @param {object} [opts.extraBindings] Additional env vars to pass to the Worker.
 * @returns {Promise<{
 *   dispatch: (req: Request) => Promise<Response>,
 *   mf: Miniflare,
 *   close: () => Promise<void>,
 * }>}
 *
 * Schema rewrite note:
 *   The Worker uses `sql\`... FROM crai.tenants ...\`` unconditionally. To route
 *   those reads/writes at the crai_test schema we set search_path='crai_test, crai'
 *   on the DATABASE_URL and rename crai_test.* to match. For tests that need
 *   real `crai.tenants` qualification, the test must substitute a DATABASE_URL
 *   pointing at a throwaway DB instead — or use a `crai` schema alias via
 *   `CREATE SCHEMA crai` that's actually symlinked to crai_test via views (set
 *   up in neon-test.js if needed). Default path assumes tests override
 *   databaseUrl to a DB where `crai` IS the test schema, OR rewrite the
 *   fixtures to target whichever schema the Worker is configured against.
 *
 * Simpler alternative (recommended for Phase 4 tests): give the Worker a
 * dedicated test DB where the real `crai` schema exists and is wiped between
 * tests. neon-test.js exposes the DDL — re-run it into a `crai` schema
 * instead of `crai_test` for Worker tests.
 */
export async function loadCraiWorker(opts) {
  const {
    paypalMockUrl,
    databaseUrl = process.env.DATABASE_URL ??
      'postgresql:///mmo?host=/run/postgresql&options=-csearch_path%3Dcrai_test%2Cpublic',
    webhookId = 'TEST_WEBHOOK_ID',
    extraBindings = {},
  } = opts ?? {};

  if (!paypalMockUrl) {
    throw new Error('loadCraiWorker: paypalMockUrl is required');
  }

  const script = await readFile(CRAI_WORKER_PATH, 'utf8');

  // Build target host → mock URL map. The Worker talks to both api-m.paypal.com
  // and api-m.sandbox.paypal.com. We rewrite both to the mock listener.
  const mockHostRewrites = new Map([
    ['api-m.paypal.com', paypalMockUrl],
    ['api-m.sandbox.paypal.com', paypalMockUrl],
  ]);

  const mf = new Miniflare({
    modules: true,
    script,
    scriptPath: CRAI_WORKER_PATH,
    compatibilityDate: '2024-09-01',
    compatibilityFlags: ['nodejs_compat'],
    bindings: {
      PAYPAL_WEBHOOK_ID: webhookId,
      PAYPAL_CLIENT_ID: 'TEST_CLIENT_ID',
      PAYPAL_CLIENT_SECRET: 'TEST_CLIENT_SECRET',
      PAYPAL_MODE: 'sandbox',
      PAYPAL_PLAN_FOUNDING: 'P-56E25614FA1705931NHIKAWQ',
      PAYPAL_PLAN_STANDARD: 'P-14B91836MB2948547NHIKAYQ',
      DATABASE_URL: databaseUrl,
      DASHBOARD_API_SECRET: 'TEST_DASHBOARD_SECRET',
      PORTAL_SHARED_SECRET: 'TEST_PORTAL_SECRET',
      SEED_PROSPECT_SECRET: 'TEST_SEED_SECRET',
      ...extraBindings,
    },
    kvNamespaces: ['RATE_LIMIT_KV'],
    // Route outbound fetch() calls to api-m.(sandbox.)paypal.com at the mock.
    outboundService(request) {
      const url = new URL(request.url);
      const rewrite = mockHostRewrites.get(url.hostname);
      if (rewrite) {
        const mockUrl = new URL(rewrite);
        url.protocol = mockUrl.protocol;
        url.hostname = mockUrl.hostname;
        url.port = mockUrl.port;
        return fetch(url.toString(), request);
      }
      return fetch(request);
    },
  });

  // Miniflare bootstraps lazily; forcing ready here surfaces config errors early.
  await mf.ready;

  return {
    mf,
    dispatch: async (request) => {
      // Miniflare needs the URL to be absolute; callers can pass a relative URL.
      const abs = request instanceof Request ? request : new Request(request);
      return mf.dispatchFetch(abs);
    },
    close: async () => {
      await mf.dispose();
    },
  };
}
