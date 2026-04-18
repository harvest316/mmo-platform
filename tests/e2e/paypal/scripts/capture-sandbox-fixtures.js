#!/usr/bin/env node

/**
 * capture-sandbox-fixtures.js — one-off fixture capture harness.
 *
 * STATUS: stub (2026-04-14). The committed fixtures under
 *   tests/e2e/paypal/fixtures/{api-php,crai-worker,m333-worker}/
 * are hand-crafted to match PayPal's real payload shapes per the sandbox
 * docs. They are sufficient for the Phase 4 test suite (signatures are
 * mocked via /v1/notifications/verify-webhook-signature). This script
 * documents the process for regenerating them from a live PayPal sandbox
 * when/if we need genuinely-signed payloads or up-to-date fields from
 * PayPal API schema changes.
 *
 * PROCESS (when re-capturing):
 *
 * 1. Add a temporary `paypal-webhook-capture` action to api.php that:
 *      - reads `php://input`
 *      - writes `{ headers, body_raw, body_parsed, captured_at }` to
 *        <siteDir>/tmp/fixtures/<event_type>-<timestamp>.json
 *      - returns 200 JSON ack
 *    See plan `~/.claude/plans/cosmic-wibbling-bird.md` §Phase 2 for the
 *    exact code shape. Commit the action, FTP-deploy to $BRAND_URL.
 *
 * 2. In the PayPal Developer Dashboard, point three sandbox webhooks at:
 *      - https://$BRAND_URL/api.php?action=paypal-webhook-capture
 *        (subscribed to ALL events you need in fixtures/)
 *
 * 3. Drive each event from the sandbox:
 *      - Subscription lifecycle: create a subscription via the /create-2step-subscription
 *        endpoint with `?sandbox=<E2E_SANDBOX_KEY>`, approve in the sandbox browser,
 *        then cancel/suspend via PayPal's Subscriptions API.
 *      - Order/capture events: POST to /v2/checkout/orders with a test price override
 *        and complete approval in the sandbox browser.
 *      - Dispute: use the PayPal Sandbox "Simulate test transactions" page to open
 *        a dispute against a completed capture.
 *      - Subscription renewal / payment failure: trigger via the sandbox Schedules
 *        tab (force next billing) or let the recurring cycle run.
 *
 * 4. Pull captured files from the live site via SFTP/HTTP (whatever's
 *    easiest — the site has SSH+FTP). Normalise filenames to:
 *      - `<handler>/<event-stub>.json`
 *      - event-stub ∈ {subscription-activated-<country>-<tier>, subscription-cancelled, ...}
 *
 * 5. Sanity-check each payload:
 *      - `headers.paypal-transmission-id` is a real UUID
 *      - `body_parsed.event_type` matches the filename intent
 *      - `body_parsed.resource.plan_id` matches get2StepPlanId() for the claimed
 *        country+tier (or PAYPAL_PLAN_FOUNDING/STANDARD for CRAI)
 *      - `body_raw` round-trips JSON.parse/stringify to `body_parsed` (modulo
 *        key ordering — store `body_raw` as PayPal sent it)
 *
 * 6. Commit to `tests/e2e/paypal/fixtures/<handler>/` and update
 *    `helpers/fixture-loader.js::FIXTURE_INDEX` if new events were added.
 *
 * 7. Revert the `paypal-webhook-capture` action in a follow-up commit + FTP
 *    re-deploy. Do NOT leave a capture endpoint live in production.
 *
 * TODO (when implementing):
 *   [ ] Add the temp action to api.php (guarded by a CAPTURE_SECRET env so
 *       public POSTs don't fill the disk with garbage).
 *   [ ] Write the SFTP pull here (ssh-agent key already works on the host —
 *       tests should not run this from the container, since ssh keys aren't
 *       available in-sandbox).
 *   [ ] Normalise and commit new fixture files.
 *   [ ] Print a diff of event types captured vs event types listed in
 *       FIXTURE_INDEX so missing fixtures are flagged.
 *   [ ] Drop/revert the temp action.
 *
 * This stub exits 1 with a reminder message to keep CI from silently treating
 * it as a passing run.
 */

/* eslint-disable no-console */

const REQUIRED_FIXTURES = {
  'api-php': [
    'BILLING.SUBSCRIPTION.ACTIVATED (AU monthly_4)',
    'BILLING.SUBSCRIPTION.ACTIVATED (US monthly_8)',
    'BILLING.SUBSCRIPTION.ACTIVATED (GB monthly_12)',
    'BILLING.SUBSCRIPTION.CANCELLED',
    'BILLING.SUBSCRIPTION.SUSPENDED',
  ],
  'crai-worker': [
    'BILLING.SUBSCRIPTION.ACTIVATED (founding)',
    'BILLING.SUBSCRIPTION.ACTIVATED (standard)',
    'BILLING.SUBSCRIPTION.ACTIVATED (SANDBOX-*)',
    'BILLING.SUBSCRIPTION.RENEWED',
    'BILLING.SUBSCRIPTION.CANCELLED',
    'BILLING.SUBSCRIPTION.SUSPENDED',
    'PAYMENT.SALE.COMPLETED',
    'PAYMENT.SALE.DENIED',
  ],
  'm333-worker': [
    'CHECKOUT.ORDER.APPROVED',
    'PAYMENT.CAPTURE.COMPLETED',
    'PAYMENT.CAPTURE.DENIED',
    'PAYMENT.CAPTURE.REFUNDED',
    'CUSTOMER.DISPUTE.CREATED',
    'BILLING.SUBSCRIPTION.CREATED',
    'BILLING.SUBSCRIPTION.CANCELLED',
    'BILLING.SUBSCRIPTION.SUSPENDED',
    'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
    'BILLING.SUBSCRIPTION.RENEWED',
  ],
};

function printInstructions() {
  console.log('');
  console.log('capture-sandbox-fixtures.js — NOT IMPLEMENTED');
  console.log('');
  console.log('The committed fixtures under tests/e2e/paypal/fixtures/ are');
  console.log('hand-crafted to match real PayPal payload shapes. They are');
  console.log('sufficient for the Phase 4 test suite. Rebuild only when we');
  console.log('need genuinely-signed payloads or a PayPal API change has');
  console.log('altered the resource shape.');
  console.log('');
  console.log('Required fixture matrix:');
  for (const [handler, events] of Object.entries(REQUIRED_FIXTURES)) {
    console.log(`  [${handler}]`);
    for (const ev of events) console.log(`    - ${ev}`);
  }
  console.log('');
  console.log('See the comment block at the top of this file for the full');
  console.log('re-capture process. Host-side access is required (SFTP +');
  console.log('PayPal Developer Dashboard + FTP deploy of the temporary');
  console.log('paypal-webhook-capture action).');
  console.log('');
}

printInstructions();
process.exit(1);
