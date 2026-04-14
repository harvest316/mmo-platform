/**
 * msw-based mock of the PayPal REST API.
 *
 * The factory starts a node-HTTP server that intercepts requests for three
 * endpoints that all three handlers touch:
 *
 *   POST /v1/oauth2/token                          — OAuth client_credentials
 *   GET  /v1/billing/subscriptions/:id             — retrieve-verify (api.php)
 *   POST /v1/notifications/verify-webhook-signature — signature verify
 *     (CRAI Worker + 333Method R2 Worker + 333Method Express path)
 *
 * Why msw/node + an actual listener rather than fetch interception:
 *   - CRAI Worker + m333 R2 Worker both hardcode `https://api-m.paypal.com` or
 *     `https://api-m.sandbox.paypal.com` based on env.PAYPAL_MODE. They do NOT
 *     honour a PAYPAL_API_BASE override. Miniflare forwards outbound fetch()
 *     calls to the host Node process, so msw's node server interceptor (via
 *     `setupServer`) catches them regardless of target hostname as long as
 *     the handlers register the absolute URLs.
 *   - For api.php (PHP cURL) we inject PAYPAL_API_BASE as env into the PHP
 *     subprocess pointing at the msw listener address (e.g. http://127.0.0.1:PORT).
 *   - For the 333Method Express server we point process.env.PAYPAL_MODE='sandbox'
 *     and override the hardcoded api-m.sandbox.paypal.com at the fetch level
 *     using msw too (same setupServer instance handles both hostnames).
 *
 * Callers construct the mock via createPayPalMock() and can override any
 * endpoint per test with `server.use(...handlers)`.
 */

import { setupServer } from 'msw/node';
import { http, HttpResponse } from 'msw';
import { createServer } from 'node:http';

const DEFAULT_SUBSCRIPTION_PLAN_ID = 'P-4CS71501D47643346NHBAEGI'; // AU monthly_4

/**
 * Build the default handler set. Each handler returns a shape that matches
 * what PayPal actually returns today. Individual tests override per-call.
 *
 * @param {object} [opts]
 * @param {string} [opts.accessToken='TEST_ACCESS_TOKEN']
 * @param {object} [opts.subscriptionOverrides]  Merged onto default sub shape.
 * @param {string} [opts.verificationStatus='SUCCESS']
 */
function buildDefaultHandlers(opts = {}) {
  const accessToken = opts.accessToken ?? 'TEST_ACCESS_TOKEN';
  const subOverrides = opts.subscriptionOverrides ?? {};
  const verificationStatus = opts.verificationStatus ?? 'SUCCESS';

  // Handlers must cover BOTH api-m.paypal.com and api-m.sandbox.paypal.com
  // (plus the arbitrary localhost address used for PHP PAYPAL_API_BASE)
  // so we use absolute URL patterns with a wildcard. msw's matcher accepts
  // glob-style paths.
  const hosts = [
    'https://api-m.paypal.com',
    'https://api-m.sandbox.paypal.com',
    'http://127.0.0.1:*',
    'http://localhost:*',
  ];

  const handlers = [];

  for (const host of hosts) {
    handlers.push(
      http.post(`${host}/v1/oauth2/token`, () => {
        return HttpResponse.json({
          scope: 'https://api-m.paypal.com/v1/payments/.*',
          access_token: accessToken,
          token_type: 'Bearer',
          app_id: 'APP-TEST',
          expires_in: 3600,
          nonce: 'test-nonce',
        });
      }),
    );

    handlers.push(
      http.get(`${host}/v1/billing/subscriptions/:id`, ({ params }) => {
        const subId = params.id;
        const body = {
          id: subId,
          plan_id: DEFAULT_SUBSCRIPTION_PLAN_ID,
          status: 'ACTIVE',
          status_update_time: new Date().toISOString(),
          quantity: '1',
          subscriber: {
            name: { given_name: 'Test', surname: 'User' },
            email_address: 'test@example.com',
            payer_id: 'TESTPAYER001',
          },
          billing_info: {
            outstanding_balance: { currency_code: 'AUD', value: '0.0' },
            failed_payments_count: 0,
          },
          ...subOverrides,
        };
        return HttpResponse.json(body);
      }),
    );

    handlers.push(
      http.post(`${host}/v1/notifications/verify-webhook-signature`, () => {
        return HttpResponse.json({ verification_status: verificationStatus });
      }),
    );
  }

  return handlers;
}

/**
 * Create a standalone HTTP listener that uses msw under the hood. Listens on
 * an ephemeral port. Use `url` as PAYPAL_API_BASE for PHP subprocesses.
 *
 * For Worker tests (Miniflare) the msw `server` is used as a global fetch
 * interceptor, and the listener is not contacted directly — Miniflare's
 * outbound fetch goes through the host Node fetch(), which msw patches.
 *
 * @returns {Promise<{
 *   url: string,
 *   port: number,
 *   server: import('msw/node').SetupServer,
 *   close: () => Promise<void>,
 *   reset: () => void,
 * }>}
 */
export async function createPayPalMock(opts = {}) {
  const handlers = buildDefaultHandlers(opts);
  const server = setupServer(...handlers);
  server.listen({ onUnhandledRequest: 'bypass' });

  // Separately, boot a tiny real HTTP server that proxies into the same
  // handler set via the node fetch() path. This is what PHP will talk to.
  // We can reuse msw's fetch interception by having the listener forward
  // to a hostname msw knows about.
  const listener = createServer(async (req, res) => {
    try {
      const chunks = [];
      for await (const c of req) chunks.push(c);
      const body = Buffer.concat(chunks);

      // Rewrite the incoming host to https://api-m.sandbox.paypal.com so the
      // msw handler set matches. msw intercepts the outbound fetch from this
      // bridge, returns the mock response, and we stream it back to PHP.
      const targetUrl = `https://api-m.sandbox.paypal.com${req.url}`;

      const upstream = await fetch(targetUrl, {
        method: req.method,
        headers: Object.fromEntries(
          Object.entries(req.headers).filter(
            ([k]) => !['host', 'connection', 'content-length'].includes(k.toLowerCase()),
          ),
        ),
        body: ['GET', 'HEAD'].includes(req.method) ? undefined : body,
      });

      res.statusCode = upstream.status;
      upstream.headers.forEach((v, k) => {
        if (!['content-encoding', 'transfer-encoding', 'connection'].includes(k.toLowerCase())) {
          res.setHeader(k, v);
        }
      });
      const buf = Buffer.from(await upstream.arrayBuffer());
      res.end(buf);
    } catch (err) {
      res.statusCode = 500;
      res.end(JSON.stringify({ error: 'mock-paypal-api bridge error', message: err.message }));
    }
  });

  await new Promise((resolve) => listener.listen(0, '127.0.0.1', resolve));
  const { port } = listener.address();

  return {
    url: `http://127.0.0.1:${port}`,
    port,
    server,
    close: async () => {
      server.close();
      await new Promise((resolve) => listener.close(resolve));
    },
    reset: () => {
      server.resetHandlers(...buildDefaultHandlers(opts));
    },
  };
}

export { buildDefaultHandlers };
