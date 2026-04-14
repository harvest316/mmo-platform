# PayPal Webhook E2E Test Suite (DR-215)

End-to-end regression coverage for the three PayPal webhook handlers in the mmo
workspace. Covers every event × every handler path with captured sandbox
fixtures and mocked PayPal HTTP, plus a separate manual harness for real
sandbox runs.

| Handler | Location | Purpose |
|---|---|---|
| `api.php` | `auditandfix-website/site/api.php` `handlePayPalWebhook()` | 2Step subscription lifecycle (AU/US/GB/CA, monthly_4/8/12) |
| CRAI Worker | `ContactReplyAI/workers/index.js` `/webhooks/paypal` | ContactReplyAI tenant billing (founding $99 / standard $197) |
| 333Method R2 Worker + poller | `333Method/workers/paypal-webhook/src/index.js`, `333Method/src/payment/poll-paypal-events.js` | Checkout/capture events collected to R2, polled + processed locally |

## Architecture

```
                           ┌──────────────────┐
                           │  PayPal Sandbox  │
                           └────────┬─────────┘
                                    │ webhooks
                  ┌─────────────────┼─────────────────┐
                  ▼                 ▼                 ▼
    auditandfix.com/api.php    CRAI Worker      333Method R2 Worker
      ?action=paypal-         /webhooks/paypal   /webhook/paypal
       webhook-sandbox         │                      │
       │                       │                      ▼
       │ retrieve-verify       │ signature-verify    R2: paypal-events.json
       │ (GET /billing/…)      │ (POST /verify-…)    │
       ▼                       ▼                     ▼
  data/subscriptions-          crai.tenants      poll-paypal-events.js
  sandbox.sqlite               crai.prospect_     webhook-handler.js
                                 hints            m333.processed_webhooks
                               crai.webhook_      m333.messages
                                 events           m333.sites
                                                  m333.purchases
```

Test architecture (DR-215):

```
┌───────────────┐    fixture    ┌────────────────┐
│ fixtures/*    │──────────────▶│  vitest test   │
└───────────────┘               └────────┬───────┘
                                         │
            ┌────────────────────────────┼─────────────────────────────┐
            ▼                            ▼                             ▼
   ┌─────────────────┐         ┌──────────────────┐         ┌──────────────────┐
   │  php -S spawn   │         │  Miniflare CRAI  │         │  Miniflare m333  │
   │  (api.php)      │         │  Worker          │         │  R2 Worker       │
   └────────┬────────┘         └─────────┬────────┘         └─────────┬────────┘
            │ PAYPAL_API_BASE            │ outboundService redirects  │ outboundService
            │ override                   │ api-m.*.paypal.com         │ redirects
            ▼                            ▼                            ▼
               ┌───────────────────────────────────────────────┐
               │  createPayPalMock()  — msw + node:http bridge │
               │    /v1/oauth2/token                           │
               │    GET  /v1/billing/subscriptions/:id         │
               │    POST /v1/notifications/verify-webhook-…    │
               └───────────────────────────────────────────────┘

   ┌──────────────────┐         ┌──────────────────┐
   │ SQLite per-test  │         │ Postgres schemas │
   │ subscriptions*   │         │ crai_test /      │
   │ (temp dir)       │         │ m333_test        │
   └──────────────────┘         └──────────────────┘
```

## Running the test suite

```
cd ~/code/mmo-platform/tests/e2e/paypal
npm install          # first time only
npm test             # full suite
npm run test:watch
npm run test:coverage
```

Postgres env (the helper auto-detects `/run/postgresql` as a socket path, but
explicit env makes failures louder):

```
export PGUSER=jason
export PGHOST=/run/postgresql
export PGDATABASE=mmo
npx vitest run
```

Requirements:

- `php` 8.3+ on PATH (container provides this).
- Postgres unix socket at `/run/postgresql` with the `mmo` database.
- Node ≥ 18 (container ships 22).
- Outbound network is **not** required — msw intercepts every PayPal call and
  `Miniflare.outboundService` rewrites `api-m.*.paypal.com` to the local
  bridge. A test that hits the real internet indicates a mock regression.

## Per-file running

```
# Single handler
npx vitest run tests/api-php-sandbox.test.js
npx vitest run tests/crai-worker.test.js
npx vitest run tests/m333-worker.test.js
npx vitest run tests/m333-poller.test.js

# Single test name
npx vitest run tests/api-php-live.test.js -t "ACTIVATED AU monthly_4"

# With coverage scoped to one handler
npx vitest run tests/crai-worker.test.js --coverage
```

## Regenerating fixtures

See `scripts/capture-sandbox-fixtures.js`. It currently documents the manual
process:

1. Temporarily add a `paypal-webhook-capture` action to api.php (write-only
   dump of body + PAYPAL-TRANSMISSION-* headers to `tmp/fixtures/`).
2. FTP-deploy, register the URL as a PayPal sandbox webhook.
3. Trigger each event type via sandbox UI or `sandbox-live-run.js`.
4. Pull captured JSON, normalise filenames to `<handler>-<event>.json`,
   commit to `fixtures/`.
5. Revert the capture action and redeploy.

**Fixtures are hand-crafted and committed today.** Rebuild only when a
handler adds a new event type, PayPal changes the payload shape, or the
signature-verify flow itself is under test (the rest of the tests mock
verify to SUCCESS, so signatures need not be valid).

## Running the live harness

See `harness/README.md` — runs from the host (or container), POSTs a real
sandbox `create-subscription`, pauses for browser approval, then polls the
three deployed endpoints for the ACTIVATED + CANCELLED hops and prints a
chain-of-custody report. Not run in CI.

## Troubleshooting

### Postgres

- **`FATAL: role "jason" does not exist`** — you're on a machine where the
  `mmo` DB isn't provisioned. `psql -h /run/postgresql -U postgres` first,
  then `CREATE DATABASE mmo; CREATE ROLE jason SUPERUSER LOGIN;` (or match
  your host setup).
- **`ENOENT: /run/postgresql/.s.PGSQL.5432`** — socket not mounted into the
  container. `ls -la /run/postgresql/` must show the socket. If missing,
  restart VSCodium so the Containerfile re-mounts it.
- **`permission denied for schema crai_test`** — stale schema grants from a
  previous run. Drop and recreate: `psql … -c 'DROP SCHEMA crai_test CASCADE;
  DROP SCHEMA m333_test CASCADE;'`. The `vitest.setup.js` `beforeAll` hook
  re-creates them from scratch.
- **Socket permission errors with `_peer_cred` failures** — verify
  `ls -la /run/postgresql/` shows the socket as accessible to your user.
  PostgreSQL 16 defaults to `peer` auth on unix sockets, which matches the
  OS user against the PG role.

### Miniflare

- **Port conflicts (`EADDRINUSE`)** — tests allocate ephemeral ports. A
  flaky test likely leaked a Miniflare instance. `lsof -i :<port>` or
  `pgrep -af miniflare` to find leftovers; `pkill -f miniflare` to clean up.
- **"Outbound fetch to unmocked URL"** — check `helpers/mock-paypal-api.js` —
  the msw server must have a handler registered for the called path. If the
  Worker adds a new PayPal API call, add it to the default handler set.
- **R2 binding empty between tests** — Miniflare R2 is in-memory and scoped
  per-instance. Use `beforeEach` cleanup rather than assuming freshness;
  tests that share a Miniflare instance must `env.PAYPAL_EVENTS_BUCKET
  .delete('paypal-events.json')` between assertions.

### PHP server

- **`php --version` missing 8.3+** — container ships 8.3.6. On the host,
  `nix-env -iA nixpkgs.php83` or run from the container.
- **"Address already in use" on php -S** — previous run leaked. `pgrep -af
  'php -S'` to find; `pkill -f 'php -S 127.0.0.1'` to clean up. The
  `helpers/php-server.js` spawn picks a random high port, but stuck children
  from a crashed test can linger.
- **`data/subscriptions.sqlite` grows across runs** — each test uses its own
  `-t <tmpdir>` so there's no cross-test bleed. If you see production-side
  contamination, check that the test fixture's `DOCUMENT_ROOT` is actually
  pointing at the per-test copy (see `helpers/php-server.js` for the layout).

### Schema pollution

If a test failure leaves `crai_test` or `m333_test` in a dirty state (orphaned
rows, half-applied migrations, etc.) the next run may silently pick up
unexpected data. Force a clean slate:

```
TEARDOWN_TEST_SCHEMAS=1 npx vitest run
# or manually:
psql -h /run/postgresql -d mmo -c 'DROP SCHEMA crai_test CASCADE; DROP SCHEMA m333_test CASCADE;'
```

The setup hook re-creates them from `helpers/neon-test.js`.

### Fixture replay behaviour

- Fixtures were captured against a real sandbox; their PayPal signatures
  don't validate on replay. The default mock at
  `helpers/mock-paypal-api.js` returns `verification_status=SUCCESS` for
  every `/v1/notifications/verify-webhook-signature` POST. Tests that need
  the failure path install an msw per-test override.
- `withFreshTransmissionId(fixture)` stamps a new UUID on the
  `paypal-transmission-id` header. Use it when firing the same event twice
  to distinguish "idempotent replay" (same id) from "fresh redelivery"
  (new id).

## DR references

- **DR-213** — Original PayPal webhook production cutover. Flagged Gap C:
  sandbox webhooks landing in live mode. Status moved from "accepted
  limitation" to "resolved by DR-220".
- **DR-220** — api.php sandbox endpoint + SQLite segregation. Dedicated
  `paypal-webhook-sandbox` action forces sandbox mode; per-mode DB files;
  strict sandbox creds; `PAYPAL_API_BASE` env override for test plumbing.
- **DR-215** — E2E test architecture. Vitest at
  `mmo-platform/tests/e2e/paypal/`; msw for HTTP mocks; Miniflare for both
  Workers; local PG schemas; PHP built-in server for api.php; captured
  fixtures with signatures mocked per-test; live-run harness separate from
  CI.
- **DR-216 / DR-217 / DR-218** — bugs surfaced while writing the suite
  (check `~/code/mmo-platform/docs/decisions.md` for current descriptions).

## Directory layout

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
├── helpers/
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
│   └── capture-sandbox-fixtures.js   Regeneration runbook
├── tests/
│   ├── api-php-live.test.js
│   ├── api-php-sandbox.test.js
│   ├── crai-worker.test.js
│   ├── m333-worker.test.js
│   ├── m333-poller.test.js
│   ├── seed-prospect.test.js
│   ├── negative-paths.test.js
│   └── cross-service.test.js    (Phase 5 — pending)
└── harness/
    ├── sandbox-live-run.js      Manual sandbox live-run (Phase 6, DR-215)
    └── README.md
```
