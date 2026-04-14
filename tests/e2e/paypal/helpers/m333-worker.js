/**
 * Miniflare loader for the 333Method PayPal R2 collector Worker.
 *
 * Investigation note (2026-04-14): The Worker at
 *   ~/code/333Method/workers/paypal-webhook/src/index.js
 * does NOT honour a PAYPAL_API_BASE env var. It hardcodes
 *   - https://api-m.paypal.com
 *   - https://api-m.sandbox.paypal.com
 * based on env.ENVIRONMENT === 'test' (line 60-61).
 *
 * Same approach as crai-worker.js: use Miniflare's `outboundService` to
 * redirect outbound fetch() calls for those hostnames to the msw-backed
 * HTTP mock listener. The Worker is not patched.
 *
 * R2 bindings are in-memory. Helpers to read/write the in-memory R2 bucket
 * are exposed on the return value for test assertions.
 */

import { Miniflare } from 'miniflare';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const M333_WORKER_PATH =
  process.env.M333_WORKER_PATH ??
  join(
    HERE, '..', '..', '..', '..', '..',
    '333Method', 'workers', 'paypal-webhook', 'src', 'index.js',
  );

/**
 * @param {object} opts
 * @param {string} opts.paypalMockUrl          URL of the PayPal mock bridge.
 * @param {string} [opts.webhookId='TEST_WEBHOOK_ID']
 * @param {string} [opts.workerSecret='TEST_WORKER_SECRET']  Value for
 *                                                           PAYPAL_WORKER_SECRET
 *                                                           (required by
 *                                                           /paypal-events.json GET).
 * @param {object} [opts.extraBindings]
 */
export async function loadM333Worker(opts) {
  const {
    paypalMockUrl,
    webhookId = 'TEST_WEBHOOK_ID',
    workerSecret = 'TEST_WORKER_SECRET',
    extraBindings = {},
  } = opts ?? {};

  if (!paypalMockUrl) {
    throw new Error('loadM333Worker: paypalMockUrl is required');
  }

  const script = await readFile(M333_WORKER_PATH, 'utf8');

  const mockHostRewrites = new Map([
    ['api-m.paypal.com', paypalMockUrl],
    ['api-m.sandbox.paypal.com', paypalMockUrl],
  ]);

  // Note: omitting `scriptPath` here. workerd refuses to resolve module paths
  // containing `..`, which our path always has (it points at a sibling repo).
  // Without scriptPath, Miniflare treats the `script` string as a
  // self-contained module — fine because the Worker has no local imports.
  const mf = new Miniflare({
    modules: [
      { type: 'ESModule', path: 'worker.mjs', contents: script },
    ],
    compatibilityDate: '2024-09-01',
    compatibilityFlags: ['nodejs_compat'],
    bindings: {
      PAYPAL_WEBHOOK_ID: webhookId,
      PAYPAL_CLIENT_ID: 'TEST_CLIENT_ID',
      PAYPAL_CLIENT_SECRET: 'TEST_CLIENT_SECRET',
      PAYPAL_WORKER_SECRET: workerSecret,
      ENVIRONMENT: 'test',
      ...extraBindings,
    },
    r2Buckets: ['PAYPAL_EVENTS_BUCKET'],
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

  await mf.ready;

  return {
    mf,
    dispatch: async (urlOrRequest, init) => mf.dispatchFetch(urlOrRequest, init),

    /** Read the current paypal-events.json from the in-memory R2 bucket. */
    readR2Events: async () => {
      const bucket = await mf.getR2Bucket('PAYPAL_EVENTS_BUCKET');
      const obj = await bucket.get('paypal-events.json');
      if (!obj) return [];
      const text = await obj.text();
      try {
        return JSON.parse(text);
      } catch {
        return [];
      }
    },

    /** Read the worker-errors.json log (rolling 100-entry window). */
    readR2Errors: async () => {
      const bucket = await mf.getR2Bucket('PAYPAL_EVENTS_BUCKET');
      const obj = await bucket.get('worker-errors.json');
      if (!obj) return [];
      const text = await obj.text();
      try {
        return JSON.parse(text);
      } catch {
        return [];
      }
    },

    /** Overwrite paypal-events.json in the in-memory bucket (for poller tests). */
    writeR2Events: async (events) => {
      const bucket = await mf.getR2Bucket('PAYPAL_EVENTS_BUCKET');
      await bucket.put('paypal-events.json', JSON.stringify(events, null, 2), {
        httpMetadata: { contentType: 'application/json' },
      });
    },

    /** Clear everything in the R2 bucket. */
    clearR2: async () => {
      const bucket = await mf.getR2Bucket('PAYPAL_EVENTS_BUCKET');
      await bucket.delete('paypal-events.json').catch(() => {});
      await bucket.delete('worker-errors.json').catch(() => {});
    },

    close: async () => {
      await mf.dispose();
    },
  };
}
