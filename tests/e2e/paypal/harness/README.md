# PayPal Sandbox Live-Run Harness

Drives a real PayPal sandbox subscription end-to-end through the 2Step chain
(`api.php?action=paypal-webhook-sandbox`) and the 333Method R2 test worker, then
prints a chain-of-custody report. Used for pre-release validation before taking
real money. **Not run in CI** — it touches live endpoints and requires a human
to complete PayPal's browser approval flow.

## Purpose

CI (Vitest) covers the handler logic with captured fixtures + mocked HTTP.
This harness verifies that the *actually deployed* webhook URLs, DNS, TLS,
`.htaccess` env vars, PayPal dashboard webhook subscriptions, and R2 bucket
permissions all work together for real sandbox events. Run before bumping
production `PAYPAL_MODE=live` or after any infrastructure change that might
break webhook delivery.

## Prerequisites

### PayPal sandbox dashboard

All three webhook URLs should already be registered for BILLING.SUBSCRIPTION.*
events (per DR-213 follow-up):

| URL | Events | Purpose |
|---|---|---|
| `https://<BRAND_URL>/api.php?action=paypal-webhook-sandbox` | `BILLING.SUBSCRIPTION.ACTIVATED`, `CANCELLED`, `SUSPENDED` | 2Step subscription lifecycle |
| `https://api-staging.contactreply.app/webhooks/paypal` | CRAI lifecycle + `PAYMENT.SALE.*` | ContactReplyAI tenant billing (informational — not exercised by this 2Step harness) |
| `https://paypal-webhook-worker-test.auditandfix.workers.dev/webhook/paypal` | `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.*`, `BILLING.SUBSCRIPTION.*`, etc. | 333Method R2 mirror (used as cross-check) |

Verify at <https://developer.paypal.com/dashboard/applications/sandbox> → your
app → Webhooks.

### Sandbox buyer credentials

The harness prints these for you if they're in env. To find them:

- Production `.htaccess` on the brand host — `PAYPAL_SANDBOX_BUYER_EMAIL` and
  `PAYPAL_SANDBOX_BUYER_PASSWORD`.
- Or create a personal sandbox account:
  <https://developer.paypal.com/dashboard/accounts> → "Create Account" →
  Personal → copy the generated email + system-generated password.

They are redacted from this README on purpose — look them up before running.

### Env vars

```
# required
E2E_SANDBOX_KEY=…                   # matches the brand host .htaccess
PAYPAL_SANDBOX_CLIENT_ID=…          # from ~/code/ContactReplyAI/.env or PayPal dashboard
PAYPAL_SANDBOX_CLIENT_SECRET=…      # same

# optional but recommended
PAYPAL_SANDBOX_BUYER_EMAIL=…        # included in the approval prompt
PAYPAL_SANDBOX_BUYER_PASSWORD=…     # same
BRAND_URL=https://auditandfix.com   # default if unset
M333_WORKER_SECRET=…                # enables Hop 3 R2 cross-check

# unused today but documented for future symmetry
PAYPAL_SANDBOX_WEBHOOK_ID=…

# follow-up (see "Scope gaps")
E2E_SHARED_SECRET=…                 # if/when the sandbox-status endpoint ships
SKIP_APPROVAL=1                     # skip the stdin prompt on rerun
```

## Running

```
cd ~/code/mmo-platform/tests/e2e/paypal
node harness/sandbox-live-run.js
```

Expected duration: 2–5 minutes (depends on how quickly you click through the
PayPal approval screen and on PayPal sandbox webhook delivery latency, which
ranges from ~5 s to ~90 s).

Flow:

1. Harness POSTs `create-subscription` with an AU monthly_4 plan.
2. Prints the approval URL + sandbox buyer creds.
3. **You** open the URL in a browser, log in, click "Agree & Subscribe".
4. Return to the terminal, press ENTER.
5. Harness polls both hops for up to 60 s for ACTIVATED.
6. Harness calls `/v1/billing/subscriptions/{id}/cancel` on the real sandbox.
7. Harness polls for CANCELLED for up to 60 s.
8. Prints the chain-of-custody report + JSON summary.

Rerun after manual approval is already done:

```
SKIP_APPROVAL=1 node harness/sandbox-live-run.js --skip-approval
```

(You'll need to edit the hard-coded `video_hash`/`email` in the script to reuse
the same subscription — easier to just run again end-to-end.)

## Interpreting the report

```
┌──────────────────────────────────────────────────────────────────────┐
│ PayPal Sandbox Live-Run Report                                       │
├──────────────────────────────────────────────────────────────────────┤
│ Subscription: I-BW452GLLEP1G                                         │
│                                                                      │
│ Hop 1 — PayPal → api.php?action=paypal-webhook-sandbox               │
│   ✓  ACTIVATED (subscriptions-sandbox.sqlite)                        │
│   ✓  CANCELLED (subscriptions-sandbox.sqlite)                        │
│                                                                      │
│ Hop 2 — PayPal → CRAI staging Worker                                 │
│   —  (N/A — 2Step test, not CRAI)                                    │
│                                                                      │
│ Hop 3 — PayPal → 333Method R2 test worker                            │
│   ✓  ACTIVATED event in paypal-events.json                           │
│   ✓  CANCELLED event in paypal-events.json                           │
│                                                                      │
│ Cancel call: HTTP 204                                                │
│                                                                      │
│ ✓ segregation verified (sandbox row, no live-DB write)              │
└──────────────────────────────────────────────────────────────────────┘
```

Glyphs:

| Glyph | Meaning |
|---|---|
| `✓` | Verified — row/event present, status matches |
| `✗` | Expected effect missing after full poll timeout |
| `—` | Not applicable (e.g. CRAI hop during a 2Step run) |
| `?` | Manual check required — automation couldn't verify (e.g. auth not wired) |

Exit code: `0` only if Hop 1 ACTIVATED and CANCELLED both show `✓`. A `?` on
Hop 1 today means the sandbox-status endpoint is still a follow-up (see "Scope
gaps") — fall back to the SSH command printed by the harness.

### Common failure causes

| Symptom | Likely cause | Fix |
|---|---|---|
| Hop 1 stays `not-found` | PayPal didn't retry the webhook, or api.php returned non-2xx | Check `logs/api-error.log` on the host; look for the retrieve-verify call |
| Hop 1 `wrong-status` | Retrieve-verify hit live API instead of sandbox — DR-220 regression | Re-check `config.php` sandbox detection block, confirm `?action=paypal-webhook-sandbox` routes to `$_isSandboxWebhook` |
| Hop 3 stays `not-found` | `paypal-webhook-worker-test` dashboard subscription missing the event type | PayPal developer dashboard → app → webhooks → edit → tick the event |
| `cancel returned HTTP 401` | Sandbox OAuth creds wrong, likely a copy-paste error in `PAYPAL_SANDBOX_CLIENT_ID/SECRET` | Pull from `~/code/ContactReplyAI/.env` or regenerate in PayPal dashboard |
| `create-subscription returned HTTP 500` | Strict-sandbox-creds check in config.php fired — `.htaccess` missing `PAYPAL_SANDBOX_*` | Verify .htaccess, look for "sandbox creds missing" in `logs/api-error.log` |
| Hop 1 `manual` | Sandbox DB inspection endpoint not deployed (expected today) | See "Scope gaps" below |

## Resetting between runs

Each run generates a unique sub_id so replays don't collide, but the
`subscriptions-sandbox.sqlite` table grows over time. Options:

- **Drop the sandbox DB** (simplest): on the brand host (`$BRAND_URL`),
  `rm data/subscriptions-sandbox.sqlite` — next sandbox POST re-creates it
  with schema migrations.
- **Add an e2e cleanup endpoint** (follow-up): `?action=e2e-cleanup-sandbox-subs`
  behind `E2E_HARNESS_ENABLED` + bearer, matching the existing
  `e2eCleanupTestData` pattern.

The 333Method R2 bucket can be cleared with:

```
curl -X DELETE \
     -H "X-Auth-Secret: $M333_WORKER_SECRET" \
     https://paypal-webhook-worker-test.auditandfix.workers.dev/paypal-events.json
```

## Scope gaps

The following follow-ups are intentionally deferred from the DR-220/DR-215 PR:

1. **`?action=e2e-sandbox-subscription-status&sub_id=…` in api.php.**
   Without this, Hop 1 verification is manual (SSH + `sqlite3`). Should gate
   behind `E2E_HARNESS_ENABLED` + `Authorization: Bearer <E2E_SHARED_SECRET>`
   and return the row as `{ row: { paypal_subscription_id, status,
   activated_at, db_path } }`. Mirror `e2eGetMagicLinkToken()`'s audit-log
   pattern. The harness already has the plumbing — just flip `manual` to `ok`
   once the endpoint responds.
2. **CRAI staging tenant read endpoint.** No admin/tenant lookup exists on the
   CRAI Worker today. A harness variant that drives a CRAI plan instead of a
   2Step plan would need one, or a direct Neon query from the host (requires
   host-side psql, not container-accessible).
3. **`?action=e2e-cleanup-sandbox-subs`** for between-run isolation (see
   "Resetting between runs").

## Troubleshooting

- **"Approve URL doesn't redirect back"** — the `return_url` in
  `createSubscription()` uses the `video_hash` path (`/v/<hash>`). It doesn't
  need to resolve to a real video for the webhook to fire — PayPal only uses
  it after approval. If you get a 404 after approval, ignore it and return to
  the terminal; the webhook fires independently.
- **"ACTIVATED never lands"** — check the PayPal sandbox dashboard → your app →
  Webhooks → Event History. If PayPal shows a delivery attempt but the
  handler returned non-2xx, tail `logs/api-error.log` on the brand host.
 If PayPal shows no attempt, the webhook URL isn't subscribed to the
  event — fix in the dashboard.
- **"Sandbox creds missing 500"** — DR-220 strict-creds behaviour. The config
  refuses to fall back to live creds when sandbox mode is forced by the
  endpoint. Add the missing `PAYPAL_SANDBOX_CLIENT_ID` /
  `PAYPAL_SANDBOX_CLIENT_SECRET` to `.htaccess`.
- **"hop1=manual forever"** — expected today, see "Scope gaps" #1.
- **Node version** — harness uses top-level `await` and `node:test`-free
  imports; requires Node 18+. `node --version` should report ≥ 22 in-container.
