#!/usr/bin/env node
/**
 * PayPal Sandbox Live-Run Harness (DR-215, Phase 6)
 * ==================================================
 *
 * Drives a real PayPal sandbox subscription through the full auditandfix.com
 * 2Step chain, then prints a chain-of-custody report.
 *
 * This script is NOT run in CI. It touches the real PayPal sandbox API, the
 * real production auditandfix.com site (via the sandbox-gated endpoints), the
 * 333Method R2 test worker, and (informationally) the CRAI staging Worker.
 *
 * Usage:
 *   node harness/sandbox-live-run.js
 *   node harness/sandbox-live-run.js --skip-approval   # rerun after browser approval
 *   SKIP_APPROVAL=1 node harness/sandbox-live-run.js   # same, env-var style
 *
 * See harness/README.md for the full runbook.
 */

import { request as httpsRequest } from 'node:https';
import readline from 'node:readline';
import { setTimeout as delay } from 'node:timers/promises';

// ──────────────────────────────────────────────────────────────────────────
// Constants
// ──────────────────────────────────────────────────────────────────────────

const POLL_TIMEOUT_MS = 60_000;
const POLL_INTERVAL_MS = 3_000;
const M333_TEST_WORKER_BASE = 'https://paypal-webhook-worker-test.auditandfix.workers.dev';

// ──────────────────────────────────────────────────────────────────────────
// Env loading (fail-loud)
// ──────────────────────────────────────────────────────────────────────────

function readEnv(name, { required = true, fallback = undefined } = {}) {
  const v = process.env[name];
  if (v && v.length > 0) return v;
  if (fallback !== undefined) return fallback;
  if (!required) return '';
  console.error(`\n[FATAL] Required env var ${name} is not set.`);
  console.error('        See harness/README.md "Prerequisites" for the full list.');
  process.exit(2);
}

const ENV = {
  E2E_SANDBOX_KEY: readEnv('E2E_SANDBOX_KEY'),
  PAYPAL_SANDBOX_CLIENT_ID: readEnv('PAYPAL_SANDBOX_CLIENT_ID'),
  PAYPAL_SANDBOX_CLIENT_SECRET: readEnv('PAYPAL_SANDBOX_CLIENT_SECRET'),
  PAYPAL_SANDBOX_WEBHOOK_ID: readEnv('PAYPAL_SANDBOX_WEBHOOK_ID', { required: false }),
  AUDITANDFIX_BASE: readEnv('AUDITANDFIX_BASE', { fallback: 'https://auditandfix.com' }),
  PAYPAL_SANDBOX_BUYER_EMAIL: readEnv('PAYPAL_SANDBOX_BUYER_EMAIL', { required: false }),
  PAYPAL_SANDBOX_BUYER_PASSWORD: readEnv('PAYPAL_SANDBOX_BUYER_PASSWORD', { required: false }),
  M333_WORKER_SECRET: readEnv('M333_WORKER_SECRET', { required: false }),
  SKIP_APPROVAL:
    readEnv('SKIP_APPROVAL', { required: false }) === '1' ||
    process.argv.includes('--skip-approval'),
};

const PAYPAL_SANDBOX_API = 'https://api-m.sandbox.paypal.com';

// ──────────────────────────────────────────────────────────────────────────
// HTTP helpers (plain node:https — no external deps)
// ──────────────────────────────────────────────────────────────────────────

function doRequest(urlStr, { method = 'GET', headers = {}, body = null } = {}) {
  return new Promise((resolve, reject) => {
    const url = new URL(urlStr);
    const opts = {
      method,
      hostname: url.hostname,
      port: url.port || 443,
      path: `${url.pathname}${url.search}`,
      headers: { ...headers },
    };
    if (body) {
      opts.headers['Content-Length'] = Buffer.byteLength(body);
    }
    const req = httpsRequest(opts, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => {
        const text = Buffer.concat(chunks).toString('utf8');
        let json = null;
        try { json = JSON.parse(text); } catch {}
        resolve({ status: res.statusCode, headers: res.headers, text, json });
      });
    });
    req.on('error', reject);
    req.setTimeout(30_000, () => {
      req.destroy(new Error(`Timeout contacting ${urlStr}`));
    });
    if (body) req.write(body);
    req.end();
  });
}

// ──────────────────────────────────────────────────────────────────────────
// Step 1: create sandbox 2Step subscription via api.php
// ──────────────────────────────────────────────────────────────────────────

async function createSandboxSubscription() {
  const url = `${ENV.AUDITANDFIX_BASE}/api.php`
    + `?sandbox=${encodeURIComponent(ENV.E2E_SANDBOX_KEY)}`
    + `&action=create-subscription`;

  const payload = {
    email: `sandbox-live-run+${Date.now()}@auditandfix.com`,
    tier: 'monthly_4',
    country: 'AU',
    business_name: 'Sandbox Live Run',
    video_hash: `harness-${Date.now().toString(36)}`,
  };

  console.log('→ POST', url);
  console.log('  payload:', JSON.stringify(payload));

  const res = await doRequest(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
    body: JSON.stringify(payload),
  });

  if (res.status < 200 || res.status >= 300) {
    console.error(`[FATAL] create-subscription returned HTTP ${res.status}`);
    console.error(res.text);
    process.exit(3);
  }

  const { id, approve_url } = res.json || {};
  if (!id || !approve_url) {
    console.error('[FATAL] create-subscription response missing id or approve_url');
    console.error(res.text);
    process.exit(3);
  }

  return { subscriptionId: id, approveUrl: approve_url };
}

// ──────────────────────────────────────────────────────────────────────────
// Step 2: manual approval pause
// ──────────────────────────────────────────────────────────────────────────

async function promptForApproval({ subscriptionId, approveUrl }) {
  const rule = '━'.repeat(70);
  console.log();
  console.log(rule);
  console.log('  ⚠️  ACTION REQUIRED — manual approval in PayPal sandbox');
  console.log(rule);
  console.log();
  console.log('  Subscription:', subscriptionId);
  console.log();
  console.log('  Open this URL in a browser and approve the subscription:');
  console.log();
  console.log('    ' + approveUrl);
  console.log();
  if (ENV.PAYPAL_SANDBOX_BUYER_EMAIL && ENV.PAYPAL_SANDBOX_BUYER_PASSWORD) {
    console.log('  Login with:');
    console.log('    email:    ' + ENV.PAYPAL_SANDBOX_BUYER_EMAIL);
    console.log('    password: ' + ENV.PAYPAL_SANDBOX_BUYER_PASSWORD);
    console.log();
  } else {
    console.log('  (Sandbox buyer credentials not in env — check auditandfix.com .htaccess');
    console.log('   for PAYPAL_SANDBOX_BUYER_EMAIL / PAYPAL_SANDBOX_BUYER_PASSWORD,');
    console.log('   or create a personal sandbox account at developer.paypal.com)');
    console.log();
  }
  console.log(rule);
  console.log();

  if (ENV.SKIP_APPROVAL) {
    console.log('  SKIP_APPROVAL=1 — assuming browser approval is already done.');
    console.log();
    return;
  }

  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  await new Promise((resolve) => {
    rl.question('  Press ENTER after approving (or Ctrl-C to abort)… ', () => {
      rl.close();
      resolve();
    });
  });
  console.log();
}

// ──────────────────────────────────────────────────────────────────────────
// Step 3: poll for ACTIVATED across the three hops
// ──────────────────────────────────────────────────────────────────────────

/**
 * Hop 1 — api.php sandbox DB.
 *
 * TODO: this requires an e2e-only GET endpoint against
 * `data/subscriptions-sandbox.sqlite`. Pattern: follow the
 * `e2eGetMagicLinkToken()` gating at api.php — `E2E_HARNESS_ENABLED=1`
 * env + `Authorization: Bearer <E2E_SHARED_SECRET>` header + an
 * `?action=e2e-sandbox-subscription-status&sub_id=...` route that returns
 * the row. That endpoint does not exist yet (intentionally deferred from
 * this PR — see harness/README.md "Scope gaps"). Until it ships this hop
 * returns `status: 'manual'` and the report flags it as unverified.
 */
async function checkApiPhpSandboxRow(subscriptionId, { expectStatus }) {
  // Placeholder implementation — no way to inspect the sandbox SQLite
  // from outside the host without shell access. Returns 'manual' so the
  // report surfaces the gap instead of silently passing.
  const endpointUrl = `${ENV.AUDITANDFIX_BASE}/api.php?action=e2e-sandbox-subscription-status&sub_id=${encodeURIComponent(subscriptionId)}`;
  const bearer = process.env.E2E_SHARED_SECRET;

  if (!bearer) {
    return {
      status: 'manual',
      detail: 'E2E_SHARED_SECRET unset — need e2e-sandbox-subscription-status endpoint (follow-up)',
    };
  }

  // Stub: if the endpoint is ever added, it should return
  // { row: { paypal_subscription_id, status, activated_at, ... } }
  try {
    const res = await doRequest(endpointUrl, {
      headers: { Authorization: 'Bearer ' + bearer },
    });
    if (res.status === 404 || res.status === 400) {
      return { status: 'not-found', detail: `HTTP ${res.status}` };
    }
    if (res.status === 403) {
      return { status: 'manual', detail: 'endpoint not deployed (403)' };
    }
    if (res.status !== 200) {
      return { status: 'error', detail: `HTTP ${res.status}: ${res.text.slice(0, 200)}` };
    }
    const row = res.json?.row;
    if (!row) return { status: 'not-found', detail: 'empty response body' };
    if (expectStatus && row.status !== expectStatus) {
      return { status: 'wrong-status', detail: `have=${row.status} want=${expectStatus}`, row };
    }
    return { status: 'ok', row };
  } catch (err) {
    return { status: 'error', detail: err.message };
  }
}

/**
 * Hop 2 — CRAI staging Worker.
 *
 * 2Step subscriptions do NOT trigger CRAI lifecycle — CRAI has its own
 * plan_ids. This hop is informational: we document it as N/A for the
 * 2Step live-run. A future harness variant could drive the CRAI staging
 * Worker with a founding/standard CRAI plan instead.
 */
async function checkCraiStagingTenant(_subscriptionId) {
  return {
    status: 'n/a',
    detail: '2Step subscriptions do not create CRAI tenants (separate plan_ids).',
  };
}

/**
 * Hop 3 — 333Method R2 paypal-events.json test bucket.
 *
 * 333Method's test worker mirrors subscription events to R2 for the
 * m333-worker test suite. The poller only processes CHECKOUT/CAPTURE —
 * subscription events are stored but skipped by design (see
 * poll-paypal-events.js:56). We still verify the R2 mirror caught the
 * event because that proves the PayPal→Worker hop is working.
 *
 * Requires M333_WORKER_SECRET (from wrangler secret put PAYPAL_WORKER_SECRET).
 * If not set, the hop returns 'manual'.
 */
async function checkM333R2(subscriptionId, { eventName }) {
  if (!ENV.M333_WORKER_SECRET) {
    return {
      status: 'manual',
      detail: 'M333_WORKER_SECRET not set — cannot query /paypal-events.json',
    };
  }

  try {
    const res = await doRequest(`${M333_TEST_WORKER_BASE}/paypal-events.json`, {
      headers: { 'X-Auth-Secret': ENV.M333_WORKER_SECRET },
    });
    if (res.status === 401) {
      return { status: 'error', detail: 'M333_WORKER_SECRET rejected by test worker' };
    }
    if (res.status !== 200) {
      return { status: 'error', detail: `HTTP ${res.status}` };
    }
    const events = Array.isArray(res.json) ? res.json : [];
    const hit = events.find((e) =>
      e?.event_type === eventName
      && (e?.resource?.id === subscriptionId
          || e?.resource?.billing_agreement_id === subscriptionId)
    );
    if (!hit) {
      return {
        status: 'not-found',
        detail: `no ${eventName} for ${subscriptionId} in ${events.length} events`,
      };
    }
    return { status: 'ok', event: hit };
  } catch (err) {
    return { status: 'error', detail: err.message };
  }
}

/**
 * Poll all three hops until ACTIVATED lands on the one that matters
 * (api.php sandbox row), or the timeout expires.
 */
async function pollForActivated(subscriptionId) {
  console.log('→ Polling for ACTIVATED (up to', Math.round(POLL_TIMEOUT_MS / 1000), 'seconds)');
  const started = Date.now();
  let lastHop1 = { status: 'pending' };
  let lastHop3 = { status: 'pending' };

  while (Date.now() - started < POLL_TIMEOUT_MS) {
    lastHop1 = await checkApiPhpSandboxRow(subscriptionId, { expectStatus: 'ACTIVE' });
    lastHop3 = await checkM333R2(subscriptionId, { eventName: 'BILLING.SUBSCRIPTION.ACTIVATED' });

    const hop1Done = lastHop1.status === 'ok' || lastHop1.status === 'manual';
    const hop3Done = lastHop3.status === 'ok' || lastHop3.status === 'manual';

    const elapsedSec = Math.round((Date.now() - started) / 1000);
    console.log(
      `  [${elapsedSec.toString().padStart(2, ' ')}s] `
      + `hop1=${lastHop1.status.padEnd(10)} `
      + `hop3=${lastHop3.status}`
    );

    if (hop1Done && hop3Done) break;
    await delay(POLL_INTERVAL_MS);
  }

  return { hop1: lastHop1, hop3: lastHop3 };
}

// ──────────────────────────────────────────────────────────────────────────
// Step 5: cancel subscription via real PayPal sandbox API
// ──────────────────────────────────────────────────────────────────────────

async function getSandboxAccessToken() {
  const auth = Buffer
    .from(`${ENV.PAYPAL_SANDBOX_CLIENT_ID}:${ENV.PAYPAL_SANDBOX_CLIENT_SECRET}`)
    .toString('base64');
  const res = await doRequest(`${PAYPAL_SANDBOX_API}/v1/oauth2/token`, {
    method: 'POST',
    headers: {
      'Authorization': 'Basic ' + auth,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: 'grant_type=client_credentials',
  });
  if (res.status !== 200 || !res.json?.access_token) {
    throw new Error(`sandbox oauth failed: HTTP ${res.status}: ${res.text}`);
  }
  return res.json.access_token;
}

async function cancelSandboxSubscription(subscriptionId) {
  console.log('→ Cancelling', subscriptionId, 'via PayPal sandbox API');
  const token = await getSandboxAccessToken();
  const res = await doRequest(
    `${PAYPAL_SANDBOX_API}/v1/billing/subscriptions/${encodeURIComponent(subscriptionId)}/cancel`,
    {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + token,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ reason: 'Sandbox live-run harness cleanup' }),
    }
  );
  // 204 = success per PayPal spec. 422 often = already-cancelled.
  if (res.status !== 204 && res.status !== 422) {
    console.warn(`  cancel returned HTTP ${res.status}: ${res.text.slice(0, 200)}`);
  } else {
    console.log(`  cancel accepted (HTTP ${res.status})`);
  }
  return res.status;
}

async function pollForCancelled(subscriptionId) {
  console.log('→ Polling for CANCELLED (up to', Math.round(POLL_TIMEOUT_MS / 1000), 'seconds)');
  const started = Date.now();
  let lastHop1 = { status: 'pending' };
  let lastHop3 = { status: 'pending' };

  while (Date.now() - started < POLL_TIMEOUT_MS) {
    lastHop1 = await checkApiPhpSandboxRow(subscriptionId, { expectStatus: 'CANCELLED' });
    lastHop3 = await checkM333R2(subscriptionId, { eventName: 'BILLING.SUBSCRIPTION.CANCELLED' });

    const hop1Done = lastHop1.status === 'ok' || lastHop1.status === 'manual';
    const hop3Done = lastHop3.status === 'ok' || lastHop3.status === 'manual';

    const elapsedSec = Math.round((Date.now() - started) / 1000);
    console.log(
      `  [${elapsedSec.toString().padStart(2, ' ')}s] `
      + `hop1=${lastHop1.status.padEnd(10)} `
      + `hop3=${lastHop3.status}`
    );

    if (hop1Done && hop3Done) break;
    await delay(POLL_INTERVAL_MS);
  }
  return { hop1: lastHop1, hop3: lastHop3 };
}

// ──────────────────────────────────────────────────────────────────────────
// Chain-of-custody report
// ──────────────────────────────────────────────────────────────────────────

function glyph(status) {
  switch (status) {
    case 'ok':        return '✓';
    case 'n/a':       return '—';
    case 'manual':    return '?';
    case 'not-found': return '✗';
    default:          return '✗';
  }
}

function formatRow(label, result) {
  const g = glyph(result.status);
  const detail = result.detail ? `  (${result.detail})` : '';
  return `│   ${g}  ${label.padEnd(38)}${detail}`;
}

function printReport({ subscriptionId, activated, cancelled, cancelStatus }) {
  const lines = [];
  const border = '└' + '─'.repeat(70) + '┘';
  const top = '┌' + '─'.repeat(70) + '┐';
  const mid = '├' + '─'.repeat(70) + '┤';
  const pad = (s) => '│ ' + s.padEnd(69) + '│';

  lines.push('');
  lines.push(top);
  lines.push(pad('PayPal Sandbox Live-Run Report'));
  lines.push(mid);
  lines.push(pad(`Subscription: ${subscriptionId}`));
  lines.push(pad(''));
  lines.push(pad('Hop 1 — PayPal → api.php?action=paypal-webhook-sandbox'));
  lines.push(formatRow('ACTIVATED (subscriptions-sandbox.sqlite)', activated.hop1).padEnd(71) + '│');
  lines.push(formatRow('CANCELLED (subscriptions-sandbox.sqlite)', cancelled.hop1).padEnd(71) + '│');
  lines.push(pad(''));
  lines.push(pad('Hop 2 — PayPal → CRAI staging Worker'));
  lines.push(formatRow('(N/A — 2Step test, not CRAI)', { status: 'n/a' }).padEnd(71) + '│');
  lines.push(pad(''));
  lines.push(pad('Hop 3 — PayPal → 333Method R2 test worker'));
  lines.push(formatRow('ACTIVATED event in paypal-events.json', activated.hop3).padEnd(71) + '│');
  lines.push(formatRow('CANCELLED event in paypal-events.json', cancelled.hop3).padEnd(71) + '│');
  lines.push(pad(''));
  lines.push(pad(`Cancel call: HTTP ${cancelStatus}`));
  lines.push(pad(''));
  // Segregation: this is observational until hop1 has a real endpoint. We
  // display the row as "verified" only when hop1 reports ok for the ACTIVATED
  // step with a sandbox-suffixed DB file.
  const segregationOk = activated.hop1.status === 'ok'
    && activated.hop1.row
    && typeof activated.hop1.row.db_path === 'string'
    && activated.hop1.row.db_path.includes('-sandbox');
  const segregationLabel = segregationOk
    ? `${glyph('ok')} segregation verified (sandbox row, no live-DB write)`
    : `${glyph('manual')} segregation manual check required`;
  lines.push(pad(segregationLabel));
  lines.push(border);
  lines.push('');

  console.log(lines.join('\n'));

  // Also emit a machine-readable summary for diffing across runs.
  const summary = {
    subscription_id: subscriptionId,
    cancel_status: cancelStatus,
    hops: {
      hop1_activated: activated.hop1,
      hop1_cancelled: cancelled.hop1,
      hop3_activated: activated.hop3,
      hop3_cancelled: cancelled.hop3,
    },
    generated_at: new Date().toISOString(),
  };
  console.log('--- json-summary ---');
  console.log(JSON.stringify(summary, null, 2));
  console.log('--------------------');

  // Exit code: 0 only if both ACTIVATED + CANCELLED verified on the primary
  // hop (api.php). 'manual' counts as unverified (requires follow-up endpoint).
  const primaryOk =
    activated.hop1.status === 'ok' && cancelled.hop1.status === 'ok';
  if (!primaryOk) {
    console.log('✗ MISSING HOP — api.php sandbox DB not verified. Either:');
    console.log('  - add the e2e-sandbox-subscription-status endpoint (deferred,');
    console.log('    see harness/README.md "Scope gaps"), or');
    console.log('  - SSH to the auditandfix.com host and run:');
    console.log(`      sqlite3 data/subscriptions-sandbox.sqlite \\`);
    console.log(`        "SELECT paypal_subscription_id,status,activated_at`);
    console.log(`         FROM subscriptions WHERE paypal_subscription_id='${subscriptionId}'"`);
    console.log();
    return 1;
  }
  return 0;
}

// ──────────────────────────────────────────────────────────────────────────
// Main
// ──────────────────────────────────────────────────────────────────────────

async function main() {
  console.log('PayPal Sandbox Live-Run Harness (DR-215)');
  console.log('========================================');
  console.log('base:', ENV.AUDITANDFIX_BASE);
  console.log();

  const { subscriptionId, approveUrl } = await createSandboxSubscription();
  console.log('← sub created:', subscriptionId);

  await promptForApproval({ subscriptionId, approveUrl });

  const activated = await pollForActivated(subscriptionId);

  const cancelStatus = await cancelSandboxSubscription(subscriptionId);
  const cancelled = await pollForCancelled(subscriptionId);

  const exitCode = printReport({ subscriptionId, activated, cancelled, cancelStatus });
  process.exit(exitCode);
}

main().catch((err) => {
  console.error('\n[FATAL]', err.message);
  console.error(err.stack);
  process.exit(1);
});
