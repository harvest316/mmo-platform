/**
 * api.php — live PayPal webhook endpoint (`?action=paypal-webhook`).
 *
 * Exercises handlePayPalWebhook() at auditandfix-website/site/api.php:1800
 * against the production-style LIVE dispatch path (PAYPAL_MODE=live).
 *
 * Test strategy per-test:
 *   1. Spin up a fresh msw/HTTP mock bridge (createPayPalMock)
 *   2. Spin up a fresh php -S subprocess rooted at the real site docroot,
 *      with SITE_PATH pointing at a per-test temp dir so SQLite files are
 *      isolated. PAYPAL_API_BASE points at the mock bridge so the retrieve-verify
 *      cURL call never hits the real PayPal API.
 *   3. POST the captured fixture's raw body to ?action=paypal-webhook
 *   4. Assert the subscriptions.sqlite row in the per-test data dir matches
 *      expectations. Sandbox DB (if present) should be empty.
 *
 * Side effects in handlePayPalWebhook — provisionSubscription(), craiSeedProspect(),
 * sendViaSesSmtp() — all no-op safely when their env vars / SES creds are unset
 * (and/or are wrapped in try/catch at api.php:1935-1945).
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { http, HttpResponse } from 'msw';

import { createPayPalMock } from '../helpers/mock-paypal-api.js';
import { startPhpServer } from '../helpers/php-server.js';
import {
  createTestSiteDir,
  findSubscription,
  readSubscriptions,
} from '../helpers/sqlite-fixture.js';
import {
  loadFixture,
  withFreshTransmissionId,
} from '../helpers/fixture-loader.js';

const SITE_DOCROOT = '/home/jason/code/auditandfix-website/site';
const LIVE_ACTION = 'paypal-webhook';

/**
 * Install a retrieve-verify handler on the msw bridge that returns the given
 * subscription shape for ONE specific subscription id.
 *
 * PHP cURL targets the bridge's 127.0.0.1:<port> URL, but the bridge rewrites
 * the outbound fetch to https://api-m.sandbox.paypal.com (see mock-paypal-api.js)
 * so we only need to install handlers against the sandbox + live api-m hosts.
 */
const MOCK_HOSTS = ['https://api-m.paypal.com', 'https://api-m.sandbox.paypal.com'];

function mockSubscriptionLookup(mockApi, subscriptionId, body) {
  const handlers = MOCK_HOSTS.map((host) =>
    http.get(`${host}/v1/billing/subscriptions/${subscriptionId}`, () =>
      HttpResponse.json(body),
    ),
  );
  mockApi.server.use(...handlers);
}

/**
 * Install a retrieve-verify handler that returns an arbitrary HTTP status
 * (e.g. 404 or 401) with an empty-ish body so the handler goes down the
 * verify_failed path.
 */
function mockSubscriptionLookupStatus(mockApi, subscriptionId, status) {
  const handlers = MOCK_HOSTS.map((host) =>
    http.get(`${host}/v1/billing/subscriptions/${subscriptionId}`, () =>
      HttpResponse.json({ error: 'mocked' }, { status }),
    ),
  );
  mockApi.server.use(...handlers);
}

/**
 * Fire a fixture at the given action via fetch(), preserving the exact raw body
 * and the PayPal headers. Returns { status, text, json }.
 */
async function postFixture(phpUrl, action, fixture) {
  const url = `${phpUrl}/api.php?action=${encodeURIComponent(action)}`;
  const res = await fetch(url, {
    method: 'POST',
    headers: {
      ...Object.fromEntries(
        Object.entries(fixture.headers).map(([k, v]) => [k, String(v)]),
      ),
      'content-type': 'application/json',
    },
    body: fixture.body_raw,
  });
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch {}
  return { status: res.status, text, json };
}

describe('api.php live PayPal webhook (?action=paypal-webhook)', () => {
  let mockApi;
  let php;
  let site;

  beforeEach(async () => {
    mockApi = await createPayPalMock();
    site = await createTestSiteDir();
    php = await startPhpServer({
      siteDir: SITE_DOCROOT,
      env: {
        PAYPAL_MODE: 'live',
        PAYPAL_API_BASE: mockApi.url,
        PAYPAL_CLIENT_ID: 'TEST_LIVE_CLIENT',
        PAYPAL_CLIENT_SECRET: 'TEST_LIVE_SECRET',
        // Sandbox creds set so other sandbox tests running in parallel workers
        // won't bleed through — but this test always hits live endpoint.
        PAYPAL_SANDBOX_CLIENT_ID: 'TEST_SANDBOX_CLIENT',
        PAYPAL_SANDBOX_CLIENT_SECRET: 'TEST_SANDBOX_SECRET',
        SITE_PATH: site.dir,
        // Fire-and-forget side effects — intentionally unset so they no-op.
        APP_PORTAL_URL: '',
        PORTAL_PROVISION_SECRET: '',
        CRAI_WORKER_URL: '',
        CRAI_SEED_API_SECRET: '',
        // Session save path inside a world-writable tmp dir to avoid perms issues.
        TMPDIR: site.dir,
      },
    });
  });

  afterEach(async () => {
    if (php) await php.close();
    if (mockApi) await mockApi.close();
    if (site) await site.cleanup();
  });

  // ── Happy paths ─────────────────────────────────────────────────────────────

  it('ACTIVATED (AU monthly_4) inserts an active row into the live DB only', async () => {
    const fixture = await loadFixture('api-php', 'subscription-activated-au-monthly4');
    const subId = fixture.body_parsed.resource.id;
    const planId = fixture.body_parsed.resource.plan_id;
    const email = fixture.body_parsed.resource.subscriber.email_address;

    mockSubscriptionLookup(mockApi, subId, {
      id: subId,
      plan_id: planId,
      status: 'ACTIVE',
      subscriber: {
        name: { given_name: 'Jane', surname: 'Smith' },
        email_address: email,
      },
    });

    const res = await postFixture(php.url, LIVE_ACTION, fixture);
    expect(res.status).toBe(200);

    const liveRows = readSubscriptions(site.dir, { sandbox: false });
    expect(liveRows).toHaveLength(1);
    const row = liveRows[0];
    expect(row.paypal_subscription_id).toBe(subId);
    expect(row.email).toBe(email);
    expect(row.status).toBe('active');
    expect(row.plan_tier).toBe('monthly_4');
    expect(row.activated_at).toBeTruthy();

    // Sandbox DB MUST NOT be written — segregation.
    expect(readSubscriptions(site.dir, { sandbox: true })).toHaveLength(0);
  });

  it('ACTIVATED (US monthly_8) assigns tier=monthly_8', async () => {
    const fixture = await loadFixture('api-php', 'subscription-activated-us-monthly8');
    const subId = fixture.body_parsed.resource.id;
    const planId = fixture.body_parsed.resource.plan_id;
    const email = fixture.body_parsed.resource.subscriber.email_address;

    mockSubscriptionLookup(mockApi, subId, {
      id: subId,
      plan_id: planId,
      status: 'ACTIVE',
      subscriber: { name: { given_name: 'John', surname: 'Doe' }, email_address: email },
    });

    const res = await postFixture(php.url, LIVE_ACTION, fixture);
    expect(res.status).toBe(200);

    const row = findSubscription(site.dir, subId);
    expect(row).toBeTruthy();
    expect(row.plan_tier).toBe('monthly_8');
    expect(row.status).toBe('active');
    expect(row.email).toBe(email);
  });

  it('ACTIVATED (GB monthly_12) assigns tier=monthly_12', async () => {
    const fixture = await loadFixture('api-php', 'subscription-activated-gb-monthly12');
    const subId = fixture.body_parsed.resource.id;
    const planId = fixture.body_parsed.resource.plan_id;
    const email = fixture.body_parsed.resource.subscriber.email_address;

    mockSubscriptionLookup(mockApi, subId, {
      id: subId,
      plan_id: planId,
      status: 'ACTIVE',
      subscriber: { name: { given_name: 'Alice', surname: 'Williams' }, email_address: email },
    });

    const res = await postFixture(php.url, LIVE_ACTION, fixture);
    expect(res.status).toBe(200);

    const row = findSubscription(site.dir, subId);
    expect(row).toBeTruthy();
    expect(row.plan_tier).toBe('monthly_12');
    expect(row.status).toBe('active');
  });

  it('CANCELLED transitions an existing row to status=cancelled', async () => {
    // Seed with an ACTIVATED first so the CANCELLED has something to update.
    const activated = await loadFixture('api-php', 'subscription-activated-au-monthly4');
    const subId = activated.body_parsed.resource.id;
    const planId = activated.body_parsed.resource.plan_id;
    const email = activated.body_parsed.resource.subscriber.email_address;

    mockSubscriptionLookup(mockApi, subId, {
      id: subId, plan_id: planId, status: 'ACTIVE',
      subscriber: { name: { given_name: 'Jane', surname: 'Smith' }, email_address: email },
    });
    let res = await postFixture(php.url, LIVE_ACTION, activated);
    expect(res.status).toBe(200);
    const activeRow = findSubscription(site.dir, subId);
    expect(activeRow.status).toBe('active');
    const activatedAt = activeRow.activated_at;

    // Now the cancellation.
    const cancelled = await loadFixture('api-php', 'subscription-cancelled');
    // Mock returns CANCELLED status on retrieve-verify.
    mockSubscriptionLookup(mockApi, subId, {
      id: subId, plan_id: planId, status: 'CANCELLED',
      subscriber: { name: { given_name: 'Jane', surname: 'Smith' }, email_address: email },
    });
    res = await postFixture(php.url, LIVE_ACTION, cancelled);
    expect(res.status).toBe(200);

    const rows = readSubscriptions(site.dir);
    expect(rows).toHaveLength(1);
    expect(rows[0].status).toBe('cancelled');
    // activated_at must NOT regress when the event is not ACTIVATED.
    expect(rows[0].activated_at).toBe(activatedAt);
  });

  it('SUSPENDED transitions an existing row to status=suspended', async () => {
    // Seed with an ACTIVATED first (US monthly_8 — matches suspended fixture's subscription).
    const activated = await loadFixture('api-php', 'subscription-activated-us-monthly8');
    const subId = activated.body_parsed.resource.id;
    const planId = activated.body_parsed.resource.plan_id;
    const email = activated.body_parsed.resource.subscriber.email_address;

    mockSubscriptionLookup(mockApi, subId, {
      id: subId, plan_id: planId, status: 'ACTIVE',
      subscriber: { email_address: email, name: { given_name: 'John', surname: 'Doe' } },
    });
    let res = await postFixture(php.url, LIVE_ACTION, activated);
    expect(res.status).toBe(200);
    expect(findSubscription(site.dir, subId).status).toBe('active');

    // Suspended fixture targets the same sub id.
    const suspended = await loadFixture('api-php', 'subscription-suspended');
    expect(suspended.body_parsed.resource.id).toBe(subId);

    mockSubscriptionLookup(mockApi, subId, {
      id: subId, plan_id: planId, status: 'SUSPENDED',
      subscriber: { email_address: email, name: { given_name: 'John', surname: 'Doe' } },
    });
    res = await postFixture(php.url, LIVE_ACTION, suspended);
    expect(res.status).toBe(200);

    const rows = readSubscriptions(site.dir);
    expect(rows).toHaveLength(1);
    expect(rows[0].status).toBe('suspended');
  });

  // ── Idempotency ─────────────────────────────────────────────────────────────

  it('firing ACTIVATED twice produces a single row (UNIQUE paypal_subscription_id)', async () => {
    const fixture = await loadFixture('api-php', 'subscription-activated-au-monthly4');
    const subId = fixture.body_parsed.resource.id;
    const planId = fixture.body_parsed.resource.plan_id;
    const email = fixture.body_parsed.resource.subscriber.email_address;

    mockSubscriptionLookup(mockApi, subId, {
      id: subId, plan_id: planId, status: 'ACTIVE',
      subscriber: { email_address: email, name: { given_name: 'Jane', surname: 'Smith' } },
    });

    const r1 = await postFixture(php.url, LIVE_ACTION, fixture);
    expect(r1.status).toBe(200);
    const firstRow = findSubscription(site.dir, subId);
    const firstActivatedAt = firstRow.activated_at;
    expect(firstActivatedAt).toBeTruthy();

    // Small wait so datetime('now') would differ if ON CONFLICT branch wrote anew.
    await new Promise((r) => setTimeout(r, 1100));

    const r2 = await postFixture(php.url, LIVE_ACTION, withFreshTransmissionId(fixture));
    expect(r2.status).toBe(200);

    const rows = readSubscriptions(site.dir);
    expect(rows).toHaveLength(1);
    // activated_at may rewrite (matches CASE WHEN excluded.status='active' branch
    // in api.php:1883). We only care it didn't REGRESS. Allow equal-or-later.
    expect(rows[0].activated_at >= firstActivatedAt).toBe(true);
    expect(rows[0].status).toBe('active');
  });

  // ── Negative paths ──────────────────────────────────────────────────────────

  it('retrieve-verify returning 404 → 200 verify_failed, no DB row', async () => {
    const fixture = await loadFixture('api-php', 'subscription-activated-au-monthly4');
    const subId = fixture.body_parsed.resource.id;

    mockSubscriptionLookupStatus(mockApi, subId, 404);

    const res = await postFixture(php.url, LIVE_ACTION, fixture);
    // api.php:1849-1851 — acks with 200 to stop PayPal retrying, but writes nothing.
    expect(res.status).toBe(200);
    expect(res.json).toEqual({ status: 'verify_failed' });
    expect(readSubscriptions(site.dir)).toHaveLength(0);
  });

  it('retrieve-verify returning 401 → 200 verify_failed, no DB row', async () => {
    const fixture = await loadFixture('api-php', 'subscription-activated-au-monthly4');
    const subId = fixture.body_parsed.resource.id;

    mockSubscriptionLookupStatus(mockApi, subId, 401);

    const res = await postFixture(php.url, LIVE_ACTION, fixture);
    expect(res.status).toBe(200);
    expect(res.json).toEqual({ status: 'verify_failed' });
    expect(readSubscriptions(site.dir)).toHaveLength(0);
  });

  it('unknown plan_id defaults tier=unknown but still writes the row (current behaviour)', async () => {
    const fixture = await loadFixture('api-php', 'subscription-activated-au-monthly4');
    const subId = fixture.body_parsed.resource.id;
    const email = fixture.body_parsed.resource.subscriber.email_address;

    mockSubscriptionLookup(mockApi, subId, {
      id: subId,
      plan_id: 'P-COMPLETELY-UNKNOWN-PLAN',
      status: 'ACTIVE',
      subscriber: { email_address: email, name: { given_name: 'Jane', surname: 'Smith' } },
    });

    const res = await postFixture(php.url, LIVE_ACTION, fixture);
    expect(res.status).toBe(200);

    const row = findSubscription(site.dir, subId);
    expect(row).toBeTruthy();
    expect(row.plan_tier).toBe('unknown');
    expect(row.status).toBe('active');
  });

  it('unknown event type → 200 ignored, no DB row', async () => {
    const body = JSON.stringify({
      id: 'WH-UNKNOWN-EVENT-0001',
      event_type: 'BILLING.SUBSCRIPTION.RENEWED', // not in handled list
      resource: { id: 'I-DOESNT-MATTER' },
    });
    const res = await fetch(`${php.url}/api.php?action=${LIVE_ACTION}`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body,
    });
    expect(res.status).toBe(200);
    const json = await res.json();
    expect(json.status).toBe('ignored');
    expect(readSubscriptions(site.dir)).toHaveLength(0);
  });

  it('empty body → 400 Empty body', async () => {
    const res = await fetch(`${php.url}/api.php?action=${LIVE_ACTION}`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: '',
    });
    expect(res.status).toBe(400);
    const json = await res.json();
    expect(json.error).toBe('Empty body');
    expect(readSubscriptions(site.dir)).toHaveLength(0);
  });

  it('malformed JSON body → 400 Invalid payload', async () => {
    const res = await fetch(`${php.url}/api.php?action=${LIVE_ACTION}`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: '{not json at all',
    });
    expect(res.status).toBe(400);
    const json = await res.json();
    expect(json.error).toBe('Invalid payload');
    expect(readSubscriptions(site.dir)).toHaveLength(0);
  });

  it('missing resource.id → 400 "No subscription ID in payload"', async () => {
    const body = JSON.stringify({
      id: 'WH-NO-RESOURCE-ID',
      event_type: 'BILLING.SUBSCRIPTION.ACTIVATED',
      resource: {
        // no id field
        plan_id: 'P-4CS71501D47643346NHBAEGI',
      },
    });
    const res = await fetch(`${php.url}/api.php?action=${LIVE_ACTION}`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body,
    });
    expect(res.status).toBe(400);
    const json = await res.json();
    expect(json.error).toBe('No subscription ID in payload');
    expect(readSubscriptions(site.dir)).toHaveLength(0);
  });
});
