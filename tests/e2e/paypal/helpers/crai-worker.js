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
import { readFile, mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { build as esbuild } from 'esbuild';

const HERE = dirname(fileURLToPath(import.meta.url));
const CRAI_WORKER_PATH =
  process.env.CRAI_WORKER_PATH ??
  join(HERE, '..', '..', '..', '..', '..', 'ContactReplyAI', 'workers', 'index.js');

// In-memory cache for the bundled Worker script. Miniflare re-parses the
// bundle for each instance but the bundle itself is expensive to generate
// (~50ms per call) and identical across tests.
let _bundleCache = null;
async function bundleWorker() {
  if (_bundleCache) return _bundleCache;
  // Output must live inside this package so Miniflare's workerd can resolve
  // the scriptPath without escaping its modulesRoot (`..` traversals fail).
  const outDir = join(HERE, '..', 'tmp', 'bundle');
  await mkdir(outDir, { recursive: true });
  const outfile = join(outDir, 'crai-worker.mjs');

  // Bundle the Worker so Miniflare sees a single ESM module with no external
  // bare imports (the Worker imports `@neondatabase/serverless` which isn't a
  // Cloudflare builtin). We keep `node:*` and `cloudflare:*` external since
  // Miniflare/workerd provides those.
  await esbuild({
    entryPoints: [CRAI_WORKER_PATH],
    bundle: true,
    format: 'esm',
    platform: 'neutral',
    outfile,
    external: ['cloudflare:*', 'node:*'],
    logLevel: 'silent',
    // Keep names so stack traces remain readable when a test fails.
    keepNames: true,
  });

  const script = await readFile(outfile, 'utf8');
  _bundleCache = { script, outfile };
  return _bundleCache;
}

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
    // The neon HTTP bridge URL (from startNeonHttpBridge()). When set, the
    // Worker's DATABASE_URL hostname is rewritten so Neon's /sql fetch lands
    // on the bridge. Required for any Worker route that hits Postgres.
    neonBridgeUrl,
    // Virtual hostname embedded in DATABASE_URL. Must match whatever the
    // outboundService intercepts. Defaults to a unique marker so tests don't
    // collide with any real DNS.
    fakeDbHost = 'crai-test.neon.local',
    fakeDbPort = 5432,
    databaseUrl,
    webhookId = 'TEST_WEBHOOK_ID',
    extraBindings = {},
  } = opts ?? {};

  if (!paypalMockUrl) {
    throw new Error('loadCraiWorker: paypalMockUrl is required');
  }

  const effectiveDatabaseUrl =
    databaseUrl ??
    (neonBridgeUrl
      ? `postgresql://test:test@${fakeDbHost}:${fakeDbPort}/mmo?sslmode=require`
      : (process.env.DATABASE_URL ??
        'postgresql:///mmo?host=/run/postgresql&options=-csearch_path%3Dcrai_test%2Cpublic'));

  const { script, outfile } = await bundleWorker();

  // Build target host → mock URL map. The Worker talks to both api-m.paypal.com
  // and api-m.sandbox.paypal.com (PayPal REST) — both are rewritten to the mock
  // listener. The neon HTTP driver fetches `https://${dbHost}:${dbPort}/sql`,
  // which we rewrite to the neon bridge if one is configured.
  const mockHostRewrites = new Map([
    ['api-m.paypal.com', paypalMockUrl],
    ['api-m.sandbox.paypal.com', paypalMockUrl],
  ]);
  if (neonBridgeUrl) {
    // The Neon serverless driver rewrites the DSN hostname before issuing its
    // /sql fetch — it strips the leading label and replaces it with `api.`
    // (see @neondatabase/serverless index.mjs `fetchEndpoint` default). So a
    // DATABASE_URL pointing at `crai-test.neon.local` results in an outbound
    // fetch to `https://api.neon.local/sql`. We therefore map BOTH the raw
    // hostname AND the `api.` rewrite to the bridge.
    mockHostRewrites.set(fakeDbHost, neonBridgeUrl);
    const firstDot = fakeDbHost.indexOf('.');
    if (firstDot > 0) {
      const apiHost = 'api.' + fakeDbHost.slice(firstDot + 1);
      mockHostRewrites.set(apiHost, neonBridgeUrl);
    }
  }

  const mf = new Miniflare({
    modules: true,
    script,
    scriptPath: outfile,
    compatibilityDate: '2024-09-01',
    compatibilityFlags: ['nodejs_compat'],
    bindings: {
      PAYPAL_WEBHOOK_ID: webhookId,
      PAYPAL_CLIENT_ID: 'TEST_CLIENT_ID',
      PAYPAL_CLIENT_SECRET: 'TEST_CLIENT_SECRET',
      PAYPAL_MODE: 'sandbox',
      PAYPAL_PLAN_FOUNDING: 'P-56E25614FA1705931NHIKAWQ',
      PAYPAL_PLAN_STANDARD: 'P-14B91836MB2948547NHIKAYQ',
      DATABASE_URL: effectiveDatabaseUrl,
      DASHBOARD_API_SECRET: 'TEST_DASHBOARD_SECRET',
      PORTAL_SHARED_SECRET: 'TEST_PORTAL_SECRET',
      // Worker code reads env.SEED_API_SECRET for /api/internal/seed-prospect
      // (workers/index.js:2761). SEED_PROSPECT_SECRET is kept as a legacy alias
      // in case documentation or other callers reference it.
      SEED_API_SECRET: 'TEST_SEED_SECRET',
      SEED_PROSPECT_SECRET: 'TEST_SEED_SECRET',
      ...extraBindings,
    },
    kvNamespaces: ['RATE_LIMIT_KV'],
    // Route outbound fetch() calls:
    //   - api-m.(sandbox.)paypal.com → the msw-backed PayPal mock
    //   - <fakeDbHost>               → the neon HTTP bridge (when configured)
    //
    // Reconstructs the outgoing fetch as (urlString, { method, headers, body })
    // so msw's ClientRequest interceptor and the neon bridge's plain HTTP
    // server both see a well-formed absolute URL. Passing a Request as the
    // second arg trips undici's ERR_INVALID_URL inside the msw interceptor.
    async outboundService(request) {
      const url = new URL(request.url);
      const rewrite = mockHostRewrites.get(url.hostname);
      const targetUrlStr = rewrite
        ? (() => {
            const mockUrl = new URL(rewrite);
            url.protocol = mockUrl.protocol;
            url.hostname = mockUrl.hostname;
            url.port = mockUrl.port;
            return url.toString();
          })()
        : url.toString();

      const headers = {};
      request.headers.forEach((v, k) => { headers[k] = v; });
      const init = { method: request.method, headers };
      if (!['GET', 'HEAD'].includes(request.method)) {
        init.body = await request.text();
      }
      try {
        return await fetch(targetUrlStr, init);
      } catch (err) {
        const msg = `[outboundService] fetch ${targetUrlStr} failed: ${err.message} cause=${err.cause?.message ?? ''}`;
        console.error(msg);
        return new Response(msg, { status: 502 });
      }
    },
  });

  // Miniflare bootstraps lazily; forcing ready here surfaces config errors early.
  await mf.ready;

  return {
    mf,
    dispatch: async (request) => {
      // Miniflare's dispatchFetch() expects (url, init?) and internally wraps
      // the args via its bundled undici. Passing a host-created Request object
      // directly confuses undici's Request constructor (ERR_INVALID_URL),
      // so we unwrap to (url, { method, headers, body }).
      if (request instanceof Request) {
        const headers = {};
        request.headers.forEach((v, k) => { headers[k] = v; });
        const init = {
          method: request.method,
          headers,
        };
        if (!['GET', 'HEAD'].includes(request.method)) {
          init.body = await request.text();
        }
        return mf.dispatchFetch(request.url, init);
      }
      return mf.dispatchFetch(request);
    },
    close: async () => {
      await mf.dispose();
    },
  };
}
