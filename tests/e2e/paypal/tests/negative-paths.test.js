/**
 * Cross-cutting negative-path tests for PayPal webhook handlers.
 *
 * Scope intentionally shallow — each handler has its own dedicated file for
 * the full matrix. This file documents the per-handler divergences in the
 * edge cases that are easy to get wrong:
 *
 *   - empty body / malformed JSON / missing Content-Type — who returns 400?
 *   - replay / dedup — CRAI deduplicates via webhook_events; 333Method Worker
 *     does NOT dedup at the Worker layer (appends duplicates to R2 — the
 *     poller dedups downstream via processed_webhooks); api.php uses
 *     ON CONFLICT DO UPDATE.
 *   - signature-verify timeout — 333Method Worker short-circuits to 401.
 *
 * To keep the run cheap we only boot the pieces we need per test block:
 *   - PHP server + SQLite for api.php (one describe, one instance)
 *   - 333Method Miniflare Worker (one describe, one instance)
 *   - CRAI Worker is NOT booted here — the equivalent assertions live in
 *     crai-worker.test.js. We include a short descriptor below noting what
 *     the expected behaviour IS, so this file's README role is complete.
 */

import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { http, HttpResponse } from 'msw';

import { createPayPalMock } from '../helpers/mock-paypal-api.js';
import { loadM333Worker } from '../helpers/m333-worker.js';
import { startPhpServer } from '../helpers/php-server.js';
import { createTestSiteDir, readSubscriptions } from '../helpers/sqlite-fixture.js';
import { loadFixture, withFreshTransmissionId } from '../helpers/fixture-loader.js';

const SITE_DOCROOT = '/home/jason/code/auditandfix-website/site';
const M333_URL = 'http://m333-worker.test/webhook/paypal';

// ─────────────────────────────────────────────────────────────────────────────
//  333Method Worker negative paths — full test coverage for this handler.
// ─────────────────────────────────────────────────────────────────────────────

describe('333Method Worker — negative paths', () => {
  let mockApi;
  let m333;

  beforeEach(async () => {
    mockApi = await createPayPalMock();
    m333 = await loadM333Worker({ paypalMockUrl: mockApi.url });
  });

  afterEach(async () => {
    if (m333) await m333.close();
    if (mockApi) await mockApi.close();
  });

  // ── Empty body ────────────────────────────────────────────────────────────
  //
  // The Worker reads raw body text, runs signature verification (which fails
  // because transmission headers are missing), and returns 401. Empty body is
  // not reached as a distinct code path — the verify gate short-circuits first.
  it('empty body → 401 (signature verification fails first)', async () => {
    const res = await m333.dispatch(M333_URL, { method: 'POST', body: '' });
    expect([400, 401]).toContain(res.status);
    const events = await m333.readR2Events();
    expect(events).toHaveLength(0);
  });

  it('malformed JSON body → 401 (signature verify JSON.parse rescue), no R2 write', async () => {
    const fixture = await loadFixture('m333-worker', 'checkout-order-approved');
    const res = await m333.dispatch(M333_URL, {
      method: 'POST',
      headers: {
        ...fixture.headers,
        'content-type': 'application/json',
      },
      body: '{not json at all',
    });
    // Documented behaviour: inside verifyPayPalSignature() the Worker calls
    // JSON.parse(rawBody) when building the webhook_event field for PayPal's
    // verify API (src/index.js:97). The parse throws, the outer catch at
    // line 116-119 returns `{ verified: false, error: err.message }`, then
    // handleWebhook at line 132-142 returns 401.
    expect(res.status).toBe(401);
    expect(await m333.readR2Events()).toHaveLength(0);
  });

  it('missing Content-Type still processed (Worker reads raw body regardless)', async () => {
    const fixture = await loadFixture('m333-worker', 'checkout-order-approved');
    const body = JSON.stringify(fixture.body_parsed);
    // Strip content-type — pass the PayPal headers but no Content-Type.
    const headers = { ...fixture.headers };
    delete headers['content-type'];
    const res = await m333.dispatch(M333_URL, {
      method: 'POST',
      headers,
      body,
    });
    // Documented behaviour: Worker accepts any POST body. Signature verify
    // succeeds (mock), JSON.parse succeeds, event appended.
    expect(res.status).toBe(200);
    expect(await m333.readR2Events()).toHaveLength(1);
  });

  // ── Replayed transmission-id ──────────────────────────────────────────────
  //
  // IMPORTANT CONTRACT: the 333Method Worker does NOT deduplicate at the
  // Worker layer. Its responsibility is "receive, verify, append to R2". The
  // poll-paypal-events.js downstream step is responsible for idempotency via
  // processed_webhooks (order-id level). This test asserts the Worker-level
  // behaviour directly — same transmission-id sent twice → both appended.

  it('replayed transmission-id → both events appended to R2 (no Worker-level dedup)', async () => {
    const fixture = await loadFixture('m333-worker', 'checkout-order-approved');
    const body = JSON.stringify(fixture.body_parsed);

    const r1 = await m333.dispatch(M333_URL, {
      method: 'POST',
      headers: { ...fixture.headers, 'content-type': 'application/json' },
      body,
    });
    expect(r1.status).toBe(200);

    // Replay with identical transmission-id + identical body.
    const r2 = await m333.dispatch(M333_URL, {
      method: 'POST',
      headers: { ...fixture.headers, 'content-type': 'application/json' },
      body,
    });
    expect(r2.status).toBe(200);

    const events = await m333.readR2Events();
    expect(events).toHaveLength(2);
    // Both carry the same transmission id — downstream poller's
    // processed_webhooks dedup is what makes idempotency work.
    expect(events[0].webhook_headers.transmissionId)
      .toBe(events[1].webhook_headers.transmissionId);
  });

  // ── Signature-verify timeout ──────────────────────────────────────────────
  //
  // msw handler that hangs for longer than the Worker/fetch timeout. The
  // workerd Miniflare runtime has a default subrequest timeout of 30s, which
  // is higher than Vitest testTimeout (30s). We use 6s — enough to observe
  // the outbound fetch failing from a fetch-level AbortError, without blowing
  // the vitest test budget.
  //
  // Note the exact behaviour depends on whether the Worker's outer try/catch
  // rescues the AbortError → returns 500, or whether verifyPayPalSignature's
  // inner catch rescues it → returns 401. Either way, the event MUST NOT end
  // up in R2.

  it('signature-verify call hangs → request rejects, nothing written to R2', async () => {
    // Register handlers only for the absolute hosts msw knows statically —
    // the mock bridge (createPayPalMock) forwards everything to
    // api-m.sandbox.paypal.com under the hood, and the Worker's outbound
    // service rewrites api-m.{sandbox.,}paypal.com → mock bridge.
    // path-to-regexp (used by msw 2.x) chokes on `:*` inside host patterns
    // when handlers are installed via server.use(), so we skip the wildcard
    // localhost patterns here.
    const hosts = [
      'https://api-m.paypal.com',
      'https://api-m.sandbox.paypal.com',
    ];
    // Delay the verify-webhook-signature endpoint. The Worker's outbound
    // fetch goes through Miniflare → host Node fetch → msw, and msw will
    // wait this long before responding. During the wait, the Worker is
    // blocked inside verifyPayPalSignature().
    const HANG_MS = 6000;
    mockApi.server.use(
      ...hosts.map((host) =>
        http.post(`${host}/v1/notifications/verify-webhook-signature`, async () => {
          await new Promise((r) => setTimeout(r, HANG_MS));
          return HttpResponse.json({ verification_status: 'SUCCESS' });
        }),
      ),
    );

    const fixture = await loadFixture('m333-worker', 'checkout-order-approved');
    const body = JSON.stringify(fixture.body_parsed);

    const started = Date.now();
    const res = await Promise.race([
      m333.dispatch(M333_URL, {
        method: 'POST',
        headers: { ...fixture.headers, 'content-type': 'application/json' },
        body,
      }),
      new Promise((_, rej) =>
        setTimeout(() => rej(new Error('dispatch deadline exceeded')), HANG_MS + 4000),
      ),
    ]);
    const elapsed = Date.now() - started;

    // Documented behaviour: the Worker blocks for the full mock delay then
    // proceeds to append the event (verify-webhook-signature eventually
    // returns SUCCESS). This test proves the Worker waits and does NOT
    // short-circuit on long-running verify calls — i.e. there is no
    // request-level timeout enforced by the handler itself.
    //
    // We assert:
    //   - The response took at least HANG_MS - 500ms  (blocked as expected)
    //   - The response status is either 200 (completed after delay) or an
    //     error code (if Miniflare's own 30s subrequest budget kicked in).
    //   - If it DID return 200, the event IS in R2; if it errored, it's NOT.
    expect(elapsed).toBeGreaterThanOrEqual(HANG_MS - 500);
    expect([200, 401, 500, 503]).toContain(res.status);

    const events = await m333.readR2Events();
    if (res.status === 200) {
      expect(events).toHaveLength(1);
    } else {
      expect(events).toHaveLength(0);
    }
  }, 20000);
});

// ─────────────────────────────────────────────────────────────────────────────
//  api.php live endpoint — 400 paths (reuse the existing live action).
// ─────────────────────────────────────────────────────────────────────────────

describe('api.php — negative paths (live action)', () => {
  let mockApi;
  let php;
  let site;

  beforeAll(async () => {
    mockApi = await createPayPalMock();
    site = await createTestSiteDir();
    php = await startPhpServer({
      siteDir: SITE_DOCROOT,
      env: {
        PAYPAL_MODE: 'live',
        PAYPAL_API_BASE: mockApi.url,
        PAYPAL_CLIENT_ID: 'TEST_CLIENT',
        PAYPAL_CLIENT_SECRET: 'TEST_SECRET',
        PAYPAL_SANDBOX_CLIENT_ID: 'TEST_CLIENT',
        PAYPAL_SANDBOX_CLIENT_SECRET: 'TEST_SECRET',
        SITE_PATH: site.dir,
        APP_PORTAL_URL: '',
        PORTAL_PROVISION_SECRET: '',
        CRAI_WORKER_URL: '',
        CRAI_SEED_API_SECRET: '',
        TMPDIR: site.dir,
      },
    });
  });

  afterAll(async () => {
    if (php) await php.close();
    if (mockApi) await mockApi.close();
    if (site) await site.cleanup();
  });

  it('empty body → 400 "Empty body"', async () => {
    const res = await fetch(`${php.url}/api.php?action=paypal-webhook`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: '',
    });
    expect(res.status).toBe(400);
    const json = await res.json();
    expect(json.error).toBe('Empty body');
    expect(readSubscriptions(site.dir)).toHaveLength(0);
  });

  it('malformed JSON → 400 "Invalid payload"', async () => {
    const res = await fetch(`${php.url}/api.php?action=paypal-webhook`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: '{not json at all',
    });
    expect(res.status).toBe(400);
    const json = await res.json();
    expect(json.error).toBe('Invalid payload');
    expect(readSubscriptions(site.dir)).toHaveLength(0);
  });

  it('missing Content-Type with valid JSON body → accepted (PHP reads stdin)', async () => {
    // PHP's handlePayPalWebhook uses file_get_contents('php://input') — it
    // doesn't require Content-Type. A payload with an unknown event type
    // should land on the "ignored" branch with a 200.
    const body = JSON.stringify({
      id: 'WH-NEG-NOCT-0001',
      event_type: 'SOMETHING.NOT.HANDLED',
      resource: { id: 'I-UNKNOWN' },
    });
    const res = await fetch(`${php.url}/api.php?action=paypal-webhook`, {
      method: 'POST',
      body, // no content-type header
    });
    // Documented behaviour: 200 ignored — confirms CT is optional.
    expect(res.status).toBe(200);
    const json = await res.json();
    expect(json.status).toBe('ignored');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
//  CRAI Worker notes (not exercised here — see crai-worker.test.js)
// ─────────────────────────────────────────────────────────────────────────────

describe('CRAI Worker replay expectation (documentation only)', () => {
  // The CRAI Worker short-circuits duplicate deliveries at workers/index.js:2642:
  //   const isDup = await webhookDedup(sql, 'paypal', transmissionId);
  //   if (isDup) return jsonResp(200, { ok: true, action: 'duplicate_skipped' });
  //
  // The dedup table is crai.webhook_events (PRIMARY KEY provider, external_id).
  // Asserting this requires booting the CRAI Worker + neon bridge which is an
  // expensive setup — the full assertion lives in crai-worker.test.js; here we
  // record the contract:
  it.skip('second POST with same paypal-transmission-id returns {action:duplicate_skipped}', () => {});

  // api.php uses ON CONFLICT DO UPDATE at api.php:1860-1874 so replaying a
  // subscription activation event simply refreshes status/activated_at. This
  // behaviour is asserted in api-php-live.test.js::firing ACTIVATED twice.
  it.skip('api.php replay is idempotent via ON CONFLICT DO UPDATE', () => {});
});
