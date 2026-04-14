/**
 * Local Postgres schema setup/teardown for E2E tests.
 *
 * Creates throwaway schemas `crai_test` and `m333_test` on the local mmo
 * database (unix socket at /run/postgresql) so tests don't touch production
 * `crai` / `m333` data.
 *
 * The `crai_test` DDL mirrors ContactReplyAI's production schema as of
 * migration 008 — specifically the tables the PayPal webhook handler reads
 * or writes: tenants, prospect_hints, site_stats, webhook_events,
 * billing_events.
 *
 * The `m333_test` DDL covers only what webhook-handler.js + poll-paypal-events.js
 * touch: sites, messages, processed_webhooks, purchases.
 *
 * Tests wire the Worker / host code at these schemas by setting the
 * search_path on the connection (CRAI Worker uses `${sql}` with qualified
 * table names like `crai.tenants` — for tests we rewrite the binding to
 * point at `crai_test`).
 */

import pg from 'pg';

const { Pool } = pg;

let pool;

function getPool() {
  if (!pool) {
    pool = new Pool({
      // PGHOST/PGDATABASE etc. are set by the vitest.setup.js if absent.
      connectionTimeoutMillis: 5_000,
      idleTimeoutMillis: 10_000,
      max: 4,
    });
  }
  return pool;
}

export async function closeTestPool() {
  if (pool) {
    try { await pool.end(); } catch {}
    pool = null;
  }
}

/**
 * DDL for the crai_test schema. Kept in sync with:
 *   ~/code/ContactReplyAI/migrations/001-initial.sql   (tenants)
 *   ~/code/ContactReplyAI/migrations/002-portal-additions.sql
 *     (site_stats, webhook_events, billing_events)
 *   ~/code/ContactReplyAI/migrations/008-prospect-hints.sql  (prospect_hints)
 *
 * Omitted vs prod: RLS policies, DB roles, emergency_templates seed rows,
 * channels / conversations / messages / pending_replies tables — the webhook
 * handler doesn't read from those and they'd complicate cleanup.
 */
const CRAI_TEST_DDL = `
CREATE SCHEMA IF NOT EXISTS crai_test;

CREATE TABLE IF NOT EXISTS crai_test.tenants (
  id                     SERIAL PRIMARY KEY,
  name                   TEXT NOT NULL,
  slug                   TEXT UNIQUE NOT NULL,
  vertical               TEXT NOT NULL DEFAULT 'general',
  knowledge_base         JSONB NOT NULL DEFAULT '{}',
  settings               JSONB NOT NULL DEFAULT '{}',
  billing_status         TEXT NOT NULL DEFAULT 'trial',
  billing_plan           TEXT,
  paypal_subscription_id TEXT,
  paypal_payer_id        TEXT,
  approved_reply_count   INTEGER NOT NULL DEFAULT 0,
  owner_email            TEXT,
  owner_phone            TEXT,
  created_at             TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at             TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_crai_test_tenants_paypal
  ON crai_test.tenants(paypal_subscription_id)
  WHERE paypal_subscription_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS crai_test.site_stats (
  key        TEXT PRIMARY KEY,
  int_value  INTEGER,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS crai_test.webhook_events (
  provider     TEXT NOT NULL,
  external_id  TEXT NOT NULL,
  processed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  PRIMARY KEY (provider, external_id)
);

CREATE TABLE IF NOT EXISTS crai_test.billing_events (
  id         SERIAL PRIMARY KEY,
  tenant_id  INTEGER NOT NULL REFERENCES crai_test.tenants(id) ON DELETE CASCADE,
  action     TEXT NOT NULL,
  source_ip  TEXT,
  user_agent TEXT,
  metadata   JSONB DEFAULT '{}',
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS crai_test.prospect_hints (
  id                   SERIAL PRIMARY KEY,
  email                TEXT NOT NULL,
  source               TEXT NOT NULL DEFAULT '333method',
  business_name        TEXT,
  phone                TEXT,
  city                 TEXT,
  state                TEXT,
  country_code         TEXT,
  trade                TEXT,
  service_area         TEXT,
  conversation_topics  TEXT[],
  conversation_excerpt TEXT,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  used_at              TIMESTAMPTZ
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_crai_test_prospect_hints_email
  ON crai_test.prospect_hints (lower(email));
`;

/**
 * DDL for m333_test. Covers sites, messages, processed_webhooks, purchases —
 * the subset the PayPal payment path touches.
 */
const M333_TEST_DDL = `
CREATE SCHEMA IF NOT EXISTS m333_test;

CREATE TABLE IF NOT EXISTS m333_test.sites (
  id                  BIGSERIAL PRIMARY KEY,
  landing_page_url    TEXT,
  country_code        TEXT,
  resulted_in_sale    INTEGER DEFAULT 0,
  sale_amount         NUMERIC(12, 2),
  conversation_status TEXT,
  created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS m333_test.messages (
  id              BIGSERIAL PRIMARY KEY,
  site_id         BIGINT REFERENCES m333_test.sites(id) ON DELETE SET NULL,
  payment_id      TEXT,
  payment_amount  NUMERIC(12, 2),
  created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS m333_test.processed_webhooks (
  order_id     TEXT PRIMARY KEY,
  processed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  amount       NUMERIC(12, 2),
  currency     TEXT
);

CREATE TABLE IF NOT EXISTS m333_test.purchases (
  id                SERIAL PRIMARY KEY,
  email             TEXT NOT NULL,
  landing_page_url  TEXT NOT NULL,
  paypal_order_id   TEXT UNIQUE,
  amount            INTEGER NOT NULL,
  currency          TEXT NOT NULL,
  amount_usd        INTEGER NOT NULL,
  country_code      TEXT,
  message_id        BIGINT REFERENCES m333_test.messages(id) ON DELETE SET NULL,
  site_id           BIGINT REFERENCES m333_test.sites(id),
  status            TEXT NOT NULL DEFAULT 'paid',
  error_message     TEXT,
  created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
`;

export async function setupCraiTestSchema() {
  const p = getPool();
  await p.query(CRAI_TEST_DDL);
  // Seed the same row production migration 002 inserts.
  await p.query(
    `INSERT INTO crai_test.site_stats (key, int_value) VALUES ('founding_taken', 0)
     ON CONFLICT (key) DO NOTHING`,
  );
}

export async function setupM333TestSchema() {
  const p = getPool();
  await p.query(M333_TEST_DDL);
}

/**
 * Truncate all writable tables between tests. Faster than drop+create; keeps
 * sequences where they are (tests shouldn't depend on specific ids).
 */
export async function resetCraiTestSchema() {
  const p = getPool();
  await p.query(`
    TRUNCATE TABLE crai_test.billing_events RESTART IDENTITY CASCADE;
    TRUNCATE TABLE crai_test.prospect_hints RESTART IDENTITY CASCADE;
    TRUNCATE TABLE crai_test.webhook_events;
    TRUNCATE TABLE crai_test.tenants RESTART IDENTITY CASCADE;
    UPDATE crai_test.site_stats SET int_value = 0, updated_at = NOW()
      WHERE key = 'founding_taken';
  `);
}

export async function resetM333TestSchema() {
  const p = getPool();
  await p.query(`
    TRUNCATE TABLE m333_test.processed_webhooks;
    TRUNCATE TABLE m333_test.purchases RESTART IDENTITY CASCADE;
    TRUNCATE TABLE m333_test.messages RESTART IDENTITY CASCADE;
    TRUNCATE TABLE m333_test.sites RESTART IDENTITY CASCADE;
  `);
}

export async function teardownCraiTestSchema() {
  const p = getPool();
  await p.query('DROP SCHEMA IF EXISTS crai_test CASCADE');
}

export async function teardownM333TestSchema() {
  const p = getPool();
  await p.query('DROP SCHEMA IF EXISTS m333_test CASCADE');
}

/**
 * Get a connected pg client for direct assertions. Caller must release.
 */
export async function getTestClient() {
  return getPool().connect();
}

/** Convenience: run a query against the test pool and return rows. */
export async function query(text, params) {
  const res = await getPool().query(text, params);
  return res.rows;
}
