# PayPal Webhook E2E Test Suite (DR-215)

End-to-end coverage for the three PayPal webhook handlers in the mmo workspace:

| Handler | Location | Purpose |
|---|---|---|
| `api.php` | `auditandfix-website/site/api.php` `handlePayPalWebhook()` | 2Step subscription lifecycle (AU/US/GB/CA/NZ, monthly_4/8/12) |
| CRAI Worker | `ContactReplyAI/workers/index.js` `/webhooks/paypal` | ContactReplyAI tenant billing (founding $99 / standard $197) |
| 333Method R2 Worker | `333Method/workers/paypal-webhook/src/index.js` + `src/payment/poll-paypal-events.js` | Checkout/capture events collected to R2, polled + processed locally |

## Status

- **Phase 1 (api.php segregation — DR-214):** shipped separately.
- **Phase 2 (fixtures):** done — 23 hand-crafted JSON fixtures.
- **Phase 3 (helpers + scaffold):** done — this directory.
- **Phase 4 (per-handler tests):** pending.
- **Phase 5 (cross-service):** pending.
- **Phase 6 (sandbox live harness):** stub.

## Running (once Phase 4 tests exist)

```bash
cd ~/code/mmo-platform/tests/e2e/paypal
npm install          # first time only
npm test             # run
npm run test:watch
npm run test:coverage
```

Requires:
- `php` 8.3+ on PATH (container provides this)
- Postgres unix socket at `/run/postgresql` with a `mmo` database
- Outbound Internet NOT required — msw intercepts all PayPal API calls

## Architecture

```
┌───────────────┐    fixture    ┌────────────────┐
│ fixtures/*    │──────────────▶│  test file     │
└───────────────┘               └────────┬───────┘
                                         │
            ┌────────────────────────────┼─────────────────────────────┐
            ▼                            ▼                             ▼
   ┌─────────────────┐         ┌──────────────────┐         ┌──────────────────┐
   │  php -S spawn   │         │  Miniflare CRAI  │         │  Miniflare m333  │
   │  (api.php)      │         │  Worker          │         │  R2 Worker       │
   └────────┬────────┘         └─────────┬────────┘         └─────────┬────────┘
            │                            │                            │
            │ PAYPAL_API_BASE            │ outboundService redirects  │ outboundService
            │ override                   │ api-m.*.paypal.com         │ redirects
            ▼                            ▼                            ▼
               ┌───────────────────────────────────────────────┐
               │  createPayPalMock() — msw + node:http bridge  │
               │    /v1/oauth2/token                            │
               │    GET /v1/billing/subscriptions/:id           │
               │    POST /v1/notifications/verify-webhook-...   │
               └───────────────────────────────────────────────┘

   ┌──────────────────┐         ┌──────────────────┐
   │ SQLite per-test  │         │ Postgres schemas │
   │ subscriptions*   │         │ crai_test /      │
   │ (temp dir)       │         │ m333_test        │
   └──────────────────┘         └──────────────────┘
```

## Key implementation notes

- **Fixtures** are hand-crafted and committed. `scripts/capture-sandbox-fixtures.js`
  documents the process for regenerating from real PayPal sandbox payloads —
  read it before re-capturing.
- **Signatures** aren't re-validated. The PayPal `/v1/notifications/verify-webhook-signature`
  endpoint is mocked to return `SUCCESS` by default. Tests of the rejection
  path override the mock with `server.use(...)` per-test.
- **Neither Worker honours `PAYPAL_API_BASE`.** Miniflare's `outboundService`
  hook rewrites `api-m.paypal.com` / `api-m.sandbox.paypal.com` to the local
  msw bridge — the Workers are not patched.
- **`api.php` DOES honour `PAYPAL_API_BASE`** (DR-214). Tests set it via env
  when spawning `php -S`.
- **333Method payment path uses Postgres.** We create a `m333_test` schema and
  set `PG_SEARCH_PATH=m333_test, ops, tel, msgs, public` so unqualified
  queries in `webhook-handler.js` resolve to the test schema.
- **CRAI Worker uses qualified `crai.*` everywhere.** For test DB isolation,
  run the crai DDL into a `crai` schema on a throwaway DB, or use schema
  rewriting via `search_path`. See `helpers/crai-worker.js` docstring for
  the trade-off.

## Regenerating fixtures

See `scripts/capture-sandbox-fixtures.js` (currently stubbed — exits 1 with
instructions).

## Live sandbox harness

See `harness/README.md`.

## Directory structure

```
tests/e2e/paypal/
├── package.json
├── vitest.config.js
├── vitest.setup.js
├── README.md                    (this file)
├── .gitignore
├── fixtures/                    23 committed JSON payloads
│   ├── api-php/                 (5 events)
│   ├── crai-worker/             (8 events)
│   └── m333-worker/             (10 events)
├── helpers/                     Test infrastructure modules
│   ├── mock-paypal-api.js       msw + http bridge for PayPal API mocks
│   ├── php-server.js            php -S spawn/teardown
│   ├── sqlite-fixture.js        Per-test site dir + SQLite readers
│   ├── neon-test.js             crai_test / m333_test schema setup
│   ├── crai-worker.js           Miniflare loader for CRAI Worker
│   ├── m333-worker.js           Miniflare loader for 333Method R2 Worker
│   ├── m333-payment.js          Host-side webhook-handler / poller loader
│   ├── fixture-loader.js        loadFixture() + withFreshTransmissionId()
│   └── assertions.js            Shared expect helpers
├── scripts/
│   └── capture-sandbox-fixtures.js   Regeneration process (stub)
├── tests/                       (Phase 4 — to be written)
└── harness/
    └── README.md                (Phase 6 sandbox harness)
```
