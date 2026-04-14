/**
 * api.php — sandbox PayPal webhook endpoint (`?action=paypal-webhook-sandbox`).
 *
 * Verifies DR-214: a dedicated sandbox endpoint forces PAYPAL_MODE=sandbox
 * regardless of query params, segregates writes to data/subscriptions-sandbox.sqlite,
 * and fails loudly if PAYPAL_SANDBOX_CLIENT_ID / PAYPAL_SANDBOX_CLIENT_SECRET
 * are missing (no silent fallback to live creds).
 *
 * For each test we:
 *   1. Start a mock PayPal API bridge (msw + http)
 *   2. Spawn one or two php -S subprocesses with different env layouts
 *      depending on what we're asserting (sandbox-creds-missing needs its own
 *      server because PHP reads env once at request time).
 *   3. POST fixtures and assert which SQLite file receives the row.
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
import { loadFixture } from '../helpers/fixture-loader.js';

const SITE_DOCROOT = '/home/jason/code/auditandfix-website/site';
const SANDBOX_ACTION = 'paypal-webhook-sandbox';
const LIVE_ACTION = 'paypal-webhook';

// PHP cURL targets the local bridge which rewrites to api-m.sandbox.paypal.com,
// so we only install handlers against the sandbox + live api-m hosts. Wildcard
// patterns break path-to-regexp v6+ used inside msw.
const MOCK_HOSTS = ['https://api-m.paypal.com', 'https://api-m.sandbox.paypal.com'];

function mockSubscriptionLookup(mockApi, subscriptionId, body) {
  const handlers = MOCK_HOSTS.map((host) =>
    http.get(`${host}/v1/billing/subscriptions/${subscriptionId}`, () =>
      HttpResponse.json(body),
    ),
  );
  mockApi.server.use(...handlers);
}

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

/**
 * Build the full baseline env for a sandbox-capable PHP server.
 * Callers pass `overrides` to strip creds for fail-loud tests.
 */
function sandboxCapableEnv(site, mockApi, overrides = {}) {
  return {
    PAYPAL_MODE: 'live', // dispatch forces sandbox via config.php detection
    PAYPAL_API_BASE: mockApi.url,
    PAYPAL_CLIENT_ID: 'TEST_LIVE_CLIENT',
    PAYPAL_CLIENT_SECRET: 'TEST_LIVE_SECRET',
    PAYPAL_SANDBOX_CLIENT_ID: 'TEST_SANDBOX_CLIENT',
    PAYPAL_SANDBOX_CLIENT_SECRET: 'TEST_SANDBOX_SECRET',
    SITE_PATH: site.dir,
    APP_PORTAL_URL: '',
    PORTAL_PROVISION_SECRET: '',
    CRAI_WORKER_URL: '',
    CRAI_SEED_API_SECRET: '',
    TMPDIR: site.dir,
    ...overrides,
  };
}

describe('api.php sandbox PayPal webhook (?action=paypal-webhook-sandbox)', () => {
  let mockApi;
  let php;
  let site;

  beforeEach(async () => {
    mockApi = await createPayPalMock();
    site = await createTestSiteDir();
  });

  afterEach(async () => {
    if (php) { await php.close(); php = null; }
    if (mockApi) await mockApi.close();
    if (site) await site.cleanup();
  });

  it('ACTIVATED AU monthly_4 writes to sandbox DB only, live DB is untouched', async () => {
    php = await startPhpServer({
      siteDir: SITE_DOCROOT,
      env: sandboxCapableEnv(site, mockApi),
    });

    const fixture = await loadFixture('api-php', 'subscription-activated-au-monthly4');
    const subId = fixture.body_parsed.resource.id;
    const planId = fixture.body_parsed.resource.plan_id;
    const email = fixture.body_parsed.resource.subscriber.email_address;

    mockSubscriptionLookup(mockApi, subId, {
      id: subId, plan_id: planId, status: 'ACTIVE',
      subscriber: { email_address: email, name: { given_name: 'Jane', surname: 'Smith' } },
    });

    const res = await postFixture(php.url, SANDBOX_ACTION, fixture);
    expect(res.status).toBe(200);

    // Row lands ONLY in sandbox DB.
    const sandboxRows = readSubscriptions(site.dir, { sandbox: true });
    expect(sandboxRows).toHaveLength(1);
    expect(sandboxRows[0].paypal_subscription_id).toBe(subId);
    expect(sandboxRows[0].email).toBe(email);
    expect(sandboxRows[0].status).toBe('active');
    expect(sandboxRows[0].plan_tier).toBe('monthly_4');

    // Live DB is empty (segregation guarantee).
    expect(readSubscriptions(site.dir, { sandbox: false })).toHaveLength(0);
  });

  it('same subscription_id fired at both live and sandbox endpoints produces one row in each DB', async () => {
    php = await startPhpServer({
      siteDir: SITE_DOCROOT,
      env: sandboxCapableEnv(site, mockApi),
    });

    const fixture = await loadFixture('api-php', 'subscription-activated-au-monthly4');
    const subId = fixture.body_parsed.resource.id;
    const planId = fixture.body_parsed.resource.plan_id;
    const email = fixture.body_parsed.resource.subscriber.email_address;

    mockSubscriptionLookup(mockApi, subId, {
      id: subId, plan_id: planId, status: 'ACTIVE',
      subscriber: { email_address: email, name: { given_name: 'Jane', surname: 'Smith' } },
    });

    const live = await postFixture(php.url, LIVE_ACTION, fixture);
    expect(live.status).toBe(200);
    const sandbox = await postFixture(php.url, SANDBOX_ACTION, fixture);
    expect(sandbox.status).toBe(200);

    const liveRows = readSubscriptions(site.dir, { sandbox: false });
    const sandboxRows = readSubscriptions(site.dir, { sandbox: true });
    expect(liveRows).toHaveLength(1);
    expect(sandboxRows).toHaveLength(1);
    // Both carry the same paypal_subscription_id (UNIQUE within each DB, not
    // across them — by design).
    expect(liveRows[0].paypal_subscription_id).toBe(subId);
    expect(sandboxRows[0].paypal_subscription_id).toBe(subId);
    // Cross-checks confirm no contamination.
    expect(findSubscription(site.dir, subId, { sandbox: false })).toBeTruthy();
    expect(findSubscription(site.dir, subId, { sandbox: true })).toBeTruthy();
  });

  it('CANCELLED sandbox event updates ONLY the sandbox row', async () => {
    php = await startPhpServer({
      siteDir: SITE_DOCROOT,
      env: sandboxCapableEnv(site, mockApi),
    });

    const activated = await loadFixture('api-php', 'subscription-activated-au-monthly4');
    const subId = activated.body_parsed.resource.id;
    const planId = activated.body_parsed.resource.plan_id;
    const email = activated.body_parsed.resource.subscriber.email_address;

    // Seed BOTH DBs with an active row (live via live endpoint, sandbox via sandbox endpoint).
    mockSubscriptionLookup(mockApi, subId, {
      id: subId, plan_id: planId, status: 'ACTIVE',
      subscriber: { email_address: email, name: { given_name: 'Jane', surname: 'Smith' } },
    });
    await postFixture(php.url, LIVE_ACTION, activated);
    await postFixture(php.url, SANDBOX_ACTION, activated);

    expect(findSubscription(site.dir, subId, { sandbox: false }).status).toBe('active');
    expect(findSubscription(site.dir, subId, { sandbox: true }).status).toBe('active');

    // Now cancel via sandbox endpoint only.
    const cancelled = await loadFixture('api-php', 'subscription-cancelled');
    mockSubscriptionLookup(mockApi, subId, {
      id: subId, plan_id: planId, status: 'CANCELLED',
      subscriber: { email_address: email, name: { given_name: 'Jane', surname: 'Smith' } },
    });
    const res = await postFixture(php.url, SANDBOX_ACTION, cancelled);
    expect(res.status).toBe(200);

    expect(findSubscription(site.dir, subId, { sandbox: true }).status).toBe('cancelled');
    // Live DB row remains active — cross-DB segregation on updates.
    expect(findSubscription(site.dir, subId, { sandbox: false }).status).toBe('active');
  });

  it('missing PAYPAL_SANDBOX_CLIENT_ID → sandbox endpoint returns 500 sandbox_credentials_missing', async () => {
    php = await startPhpServer({
      siteDir: SITE_DOCROOT,
      env: sandboxCapableEnv(site, mockApi, {
        PAYPAL_SANDBOX_CLIENT_ID: '', // stripped — triggers fail-loud path
      }),
    });

    const fixture = await loadFixture('api-php', 'subscription-activated-au-monthly4');
    const res = await postFixture(php.url, SANDBOX_ACTION, fixture);

    expect(res.status).toBe(500);
    expect(res.json).toBeTruthy();
    expect(res.json.error).toBe('sandbox_credentials_missing');
    expect(readSubscriptions(site.dir, { sandbox: true })).toHaveLength(0);
    expect(readSubscriptions(site.dir, { sandbox: false })).toHaveLength(0);
  });

  it('missing PAYPAL_SANDBOX_CLIENT_SECRET → sandbox endpoint returns 500 sandbox_credentials_missing', async () => {
    php = await startPhpServer({
      siteDir: SITE_DOCROOT,
      env: sandboxCapableEnv(site, mockApi, {
        PAYPAL_SANDBOX_CLIENT_SECRET: '',
      }),
    });

    const fixture = await loadFixture('api-php', 'subscription-activated-au-monthly4');
    const res = await postFixture(php.url, SANDBOX_ACTION, fixture);

    expect(res.status).toBe(500);
    expect(res.json.error).toBe('sandbox_credentials_missing');
    expect(readSubscriptions(site.dir, { sandbox: true })).toHaveLength(0);
  });

  it('live endpoint still works when sandbox creds are missing (only sandbox path fails loud)', async () => {
    // Strip sandbox creds but keep live creds intact. Live endpoint must keep
    // serving so a misconfigured sandbox env cannot take down production.
    php = await startPhpServer({
      siteDir: SITE_DOCROOT,
      env: sandboxCapableEnv(site, mockApi, {
        PAYPAL_SANDBOX_CLIENT_ID: '',
        PAYPAL_SANDBOX_CLIENT_SECRET: '',
      }),
    });

    const fixture = await loadFixture('api-php', 'subscription-activated-us-monthly8');
    const subId = fixture.body_parsed.resource.id;
    const planId = fixture.body_parsed.resource.plan_id;
    const email = fixture.body_parsed.resource.subscriber.email_address;

    mockSubscriptionLookup(mockApi, subId, {
      id: subId, plan_id: planId, status: 'ACTIVE',
      subscriber: { email_address: email, name: { given_name: 'John', surname: 'Doe' } },
    });

    // Live still works — config.php only trips sandbox fail-loud when PAYPAL_MODE === 'sandbox'.
    const liveRes = await postFixture(php.url, LIVE_ACTION, fixture);
    expect(liveRes.status).toBe(200);
    expect(findSubscription(site.dir, subId, { sandbox: false })).toBeTruthy();

    // Sandbox still fails loud — different request, different config.php run.
    const sandboxRes = await postFixture(php.url, SANDBOX_ACTION, fixture);
    expect(sandboxRes.status).toBe(500);
    expect(sandboxRes.json.error).toBe('sandbox_credentials_missing');
  });
});
