/**
 * Global setup/teardown for the PayPal webhook E2E suite.
 *
 * beforeAll — provisions:
 *   - crai_test schema (mirrors production `crai` minus RLS policies for
 *     simplicity — tests assert data in a single connection so RLS isn't
 *     necessary and adding it would make per-test cleanup harder).
 *   - m333_test schema (sites, messages, purchases, processed_webhooks
 *     subset needed for the 333Method payment path).
 *
 * afterAll — drops both schemas.
 *
 * Between tests, helpers/neon-test.js::resetCraiTestSchema() and the
 * equivalent m333 reset truncate all tables (faster than drop+create).
 */

import { afterAll, beforeAll } from 'vitest';
import {
  setupCraiTestSchema,
  teardownCraiTestSchema,
  setupM333TestSchema,
  teardownM333TestSchema,
  closeTestPool,
} from './helpers/neon-test.js';

beforeAll(async () => {
  // Only set up Postgres-backed schemas if a PG socket/DSN is available.
  // When running in an environment without Postgres, individual tests that
  // need PG will be skipped via their own guard clauses.
  if (!process.env.DATABASE_URL && !process.env.PGHOST) {
    process.env.PGHOST = '/run/postgresql';
    process.env.PGDATABASE = process.env.PGDATABASE || 'mmo';
  }
  // pg's libpq-shim doesn't read OS user like psql does — set PGUSER explicitly
  // so connection startup packets include a user name on sockets that don't
  // have one wired in DATABASE_URL.
  if (!process.env.PGUSER && !process.env.DATABASE_URL) {
    process.env.PGUSER = process.env.USER || 'jason';
  }

  await setupCraiTestSchema();
  await setupM333TestSchema();
}, 60_000);

afterAll(async () => {
  try {
    await teardownCraiTestSchema();
    await teardownM333TestSchema();
  } finally {
    await closeTestPool();
  }
}, 60_000);
