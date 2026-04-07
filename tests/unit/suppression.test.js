/**
 * Cross-project opt-out suppression system — unit tests
 *
 * Tests the PG-backed suppression list (msgs.suppression_list).
 * Uses a dedicated test_msgs schema for isolation — no production data touched.
 */

import { describe, it, expect, beforeAll, beforeEach, afterAll } from 'vitest';
import { existsSync } from 'fs';
import pg from 'pg';
import {
  openDb,
  setPool,
  normaliseEmail,
  normalisePhone,
  addSuppression,
  isSuppressed,
  isEmailSuppressed,
  isPhoneSuppressed,
  getSuppressionsAfter,
  getAllSuppressions,
  removeSuppression,
  countSuppressions,
  checkBeforeSend,
  batchImport,
} from '../../src/suppression.js';

const hasPg = existsSync('/run/postgresql/.s.PGSQL.5432');

// ── Test PG pool in isolated schema ────────────────────────────────────────

describe.skipIf(!hasPg)('Suppression list (requires PostgreSQL)', () => {

let pool;

beforeAll(async () => {
  pool = new pg.Pool({
    connectionString: 'postgresql://jason@/mmo?host=/run/postgresql',
    max: 2,
  });

  // Create isolated test schema + table
  await pool.query('CREATE SCHEMA IF NOT EXISTS test_msgs');
  await pool.query('CREATE EXTENSION IF NOT EXISTS citext SCHEMA test_msgs');
  await pool.query(`
    CREATE TABLE IF NOT EXISTS test_msgs.suppression_list (
      id           SERIAL PRIMARY KEY,
      email        citext,
      phone        TEXT,
      source       TEXT NOT NULL,
      opted_out_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      reason       TEXT,
      created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
  `);
  await pool.query(`
    CREATE UNIQUE INDEX IF NOT EXISTS idx_test_supp_email
    ON test_msgs.suppression_list (email) WHERE email IS NOT NULL
  `);
  await pool.query(`
    CREATE UNIQUE INDEX IF NOT EXISTS idx_test_supp_phone
    ON test_msgs.suppression_list (phone) WHERE phone IS NOT NULL
  `);

  // Inject the pool into the suppression module with test schema search path
  const testPool = new pg.Pool({
    connectionString: 'postgresql://jason@/mmo?host=/run/postgresql',
    max: 2,
  });
  testPool.on('connect', async (client) => {
    await client.query('SET search_path TO test_msgs, public');
  });
  // Prime a connection so search_path is set
  const client = await testPool.connect();
  await client.query('SET search_path TO test_msgs, public');
  client.release();

  setPool(testPool);
});

beforeEach(async () => {
  await pool.query('TRUNCATE test_msgs.suppression_list RESTART IDENTITY CASCADE');
});

afterAll(async () => {
  await pool.query('DROP SCHEMA IF EXISTS test_msgs CASCADE');
  await pool.end();
});

// ── Normalisation ───────────────────────────────────────────────────────────

describe('normaliseEmail', () => {
  it('lowercases email', () => {
    expect(normaliseEmail('Owner@Example.COM')).toBe('owner@example.com');
  });

  it('trims whitespace', () => {
    expect(normaliseEmail('  test@example.com  ')).toBe('test@example.com');
  });

  it('returns null for empty string', () => {
    expect(normaliseEmail('')).toBeNull();
    expect(normaliseEmail('   ')).toBeNull();
  });

  it('returns null for null/undefined', () => {
    expect(normaliseEmail(null)).toBeNull();
    expect(normaliseEmail(undefined)).toBeNull();
  });

  it('returns null for non-string', () => {
    expect(normaliseEmail(42)).toBeNull();
    expect(normaliseEmail({})).toBeNull();
  });
});

describe('normalisePhone', () => {
  it('strips whitespace and formatting characters', () => {
    expect(normalisePhone('+61 400 000 001')).toBe('+61400000001');
    expect(normalisePhone('(02) 9876-5432')).toBe('0298765432');
  });

  it('returns null for empty/null/undefined', () => {
    expect(normalisePhone('')).toBeNull();
    expect(normalisePhone(null)).toBeNull();
    expect(normalisePhone(undefined)).toBeNull();
  });

  it('returns null for non-string', () => {
    expect(normalisePhone(42)).toBeNull();
  });
});

// ── addSuppression ──────────────────────────────────────────────────────────

describe('addSuppression', () => {
  it('inserts a new email suppression', async () => {
    const result = await addSuppression({
      email: 'owner@example.com',
      phone: null,
      source: '333method',
      reason: 'stop_keyword',
    });

    expect(result.id).toBeGreaterThan(0);
    expect(result.merged).toBe(false);
    expect(await countSuppressions()).toBe(1);
  });

  it('inserts a new phone suppression', async () => {
    const result = await addSuppression({
      email: null,
      phone: '+61400000001',
      source: '2step',
      reason: 'unsubscribe',
    });

    expect(result.id).toBeGreaterThan(0);
    expect(result.merged).toBe(false);
    expect(await countSuppressions()).toBe(1);
  });

  it('inserts with both email and phone', async () => {
    const result = await addSuppression({
      email: 'owner@example.com',
      phone: '+61400000001',
      source: '333method',
      reason: 'bounce',
    });

    expect(result.id).toBeGreaterThan(0);
    expect(result.merged).toBe(false);
  });

  it('deduplicates on email — merges into existing row', async () => {
    await addSuppression({
      email: 'owner@example.com',
      phone: null,
      source: '333method',
      reason: 'stop_keyword',
    });

    const result = await addSuppression({
      email: 'owner@example.com',
      phone: '+61400000001',
      source: '2step',
      reason: 'unsubscribe',
    });

    expect(result.merged).toBe(true);
    expect(await countSuppressions()).toBe(1);

    const all = await getAllSuppressions();
    expect(all[0].phone).toBe('+61400000001');
    expect(all[0].email).toBe('owner@example.com');
    expect(all[0].source).toBe('2step');
  });

  it('deduplicates on phone — merges into existing row', async () => {
    await addSuppression({
      email: null,
      phone: '+61400000001',
      source: '333method',
      reason: 'stop_keyword',
    });

    const result = await addSuppression({
      email: 'owner@example.com',
      phone: '+61400000001',
      source: '2step',
      reason: 'complaint',
    });

    expect(result.merged).toBe(true);
    expect(await countSuppressions()).toBe(1);

    const all = await getAllSuppressions();
    expect(all[0].email).toBe('owner@example.com');
    expect(all[0].phone).toBe('+61400000001');
  });

  it('email dedup is case-insensitive', async () => {
    await addSuppression({
      email: 'OWNER@EXAMPLE.COM',
      phone: null,
      source: '333method',
    });

    const result = await addSuppression({
      email: 'owner@example.com',
      phone: null,
      source: '2step',
    });

    expect(result.merged).toBe(true);
    expect(await countSuppressions()).toBe(1);
  });

  it('throws if neither email nor phone provided', async () => {
    await expect(addSuppression({
      email: null,
      phone: null,
      source: '333method',
    })).rejects.toThrow('at least one of email or phone');
  });

  it('throws if source is missing', async () => {
    await expect(addSuppression({
      email: 'test@example.com',
      phone: null,
      source: null,
    })).rejects.toThrow('source string');
  });

  it('throws if source is empty string', async () => {
    await expect(addSuppression({
      email: 'test@example.com',
      phone: null,
      source: '',
    })).rejects.toThrow('source string');
  });

  it('normalises email before insert', async () => {
    await addSuppression({
      email: '  Owner@Example.COM  ',
      phone: null,
      source: '333method',
    });

    const all = await getAllSuppressions();
    expect(all[0].email).toBe('owner@example.com');
  });

  it('normalises phone before insert', async () => {
    await addSuppression({
      email: null,
      phone: '+61 400 000 001',
      source: '2step',
    });

    const all = await getAllSuppressions();
    expect(all[0].phone).toBe('+61400000001');
  });

  it('uses custom opted_out_at when provided', async () => {
    const ts = '2026-01-15T08:30:00.000Z';
    await addSuppression({
      email: 'test@example.com',
      phone: null,
      source: '333method',
      opted_out_at: ts,
    });

    const all = await getAllSuppressions();
    expect(new Date(all[0].opted_out_at).toISOString()).toBe(ts);
  });

  it('reason defaults to null when not provided', async () => {
    await addSuppression({
      email: 'test@example.com',
      phone: null,
      source: '333method',
    });

    const all = await getAllSuppressions();
    expect(all[0].reason).toBeNull();
  });
});

// ── isSuppressed ────────────────────────────────────────────────────────────

describe('isSuppressed', () => {
  beforeEach(async () => {
    await addSuppression({
      email: 'blocked@example.com',
      phone: '+61400000001',
      source: '333method',
      reason: 'stop_keyword',
    });
  });

  it('returns true for suppressed email', async () => {
    expect(await isSuppressed('blocked@example.com')).toBe(true);
  });

  it('returns true for suppressed phone', async () => {
    expect(await isSuppressed('+61400000001')).toBe(true);
  });

  it('returns false for non-suppressed email', async () => {
    expect(await isSuppressed('clean@example.com')).toBe(false);
  });

  it('returns false for non-suppressed phone', async () => {
    expect(await isSuppressed('+61400000002')).toBe(false);
  });

  it('returns false for null/undefined/empty', async () => {
    expect(await isSuppressed(null)).toBe(false);
    expect(await isSuppressed(undefined)).toBe(false);
    expect(await isSuppressed('')).toBe(false);
    expect(await isSuppressed('   ')).toBe(false);
  });

  it('email check is case-insensitive', async () => {
    expect(await isSuppressed('BLOCKED@EXAMPLE.COM')).toBe(true);
    expect(await isSuppressed('Blocked@Example.Com')).toBe(true);
  });

  it('phone check normalises formatting', async () => {
    expect(await isSuppressed('+61 400 000 001')).toBe(true);
  });
});

// ── isEmailSuppressed / isPhoneSuppressed ───────────────────────────────────

describe('isEmailSuppressed', () => {
  beforeEach(async () => {
    await addSuppression({
      email: 'blocked@example.com',
      phone: null,
      source: '333method',
    });
  });

  it('returns true for suppressed email', async () => {
    expect(await isEmailSuppressed('blocked@example.com')).toBe(true);
  });

  it('is case-insensitive', async () => {
    expect(await isEmailSuppressed('BLOCKED@EXAMPLE.COM')).toBe(true);
  });

  it('returns false for non-suppressed email', async () => {
    expect(await isEmailSuppressed('clean@example.com')).toBe(false);
  });

  it('returns false for null/empty', async () => {
    expect(await isEmailSuppressed(null)).toBe(false);
    expect(await isEmailSuppressed('')).toBe(false);
  });
});

describe('isPhoneSuppressed', () => {
  beforeEach(async () => {
    await addSuppression({
      email: null,
      phone: '+61400000001',
      source: '2step',
    });
  });

  it('returns true for suppressed phone', async () => {
    expect(await isPhoneSuppressed('+61400000001')).toBe(true);
  });

  it('returns false for non-suppressed phone', async () => {
    expect(await isPhoneSuppressed('+61400000002')).toBe(false);
  });

  it('returns false for null/empty', async () => {
    expect(await isPhoneSuppressed(null)).toBe(false);
    expect(await isPhoneSuppressed('')).toBe(false);
  });
});

// ── Cross-channel suppression ───────────────────────────────────────────────

describe('cross-channel suppression', () => {
  it('email-only suppression does not block phone check for different contact', async () => {
    await addSuppression({
      email: 'blocked@example.com',
      phone: null,
      source: '333method',
    });

    expect(await isPhoneSuppressed('+61400000099')).toBe(false);
  });

  it('phone-only suppression does not block email check for different contact', async () => {
    await addSuppression({
      email: null,
      phone: '+61400000001',
      source: '2step',
    });

    expect(await isEmailSuppressed('other@example.com')).toBe(false);
  });

  it('suppression with both email and phone blocks both independently', async () => {
    await addSuppression({
      email: 'blocked@example.com',
      phone: '+61400000001',
      source: '333method',
    });

    expect(await isEmailSuppressed('blocked@example.com')).toBe(true);
    expect(await isPhoneSuppressed('+61400000001')).toBe(true);
  });

  it('merged suppression blocks by either identifier', async () => {
    await addSuppression({
      email: null,
      phone: '+61400000001',
      source: '333method',
    });

    await addSuppression({
      email: 'blocked@example.com',
      phone: '+61400000001',
      source: '2step',
    });

    expect(await isEmailSuppressed('blocked@example.com')).toBe(true);
    expect(await isPhoneSuppressed('+61400000001')).toBe(true);
    expect(await countSuppressions()).toBe(1);
  });
});

// ── Cross-project suppression ───────────────────────────────────────────────

describe('cross-project suppression', () => {
  it('333Method opt-out blocks 2Step outreach to same email', async () => {
    await addSuppression({
      email: 'owner@business.com',
      phone: null,
      source: '333method',
      reason: 'unsubscribe',
    });

    const check = await checkBeforeSend({ email: 'owner@business.com', phone: null });
    expect(check.blocked).toBe(true);
    expect(check.matchedOn).toBe('email');
  });

  it('2Step opt-out blocks 333Method outreach to same phone', async () => {
    await addSuppression({
      email: null,
      phone: '+61400000001',
      source: '2step',
      reason: 'stop_keyword',
    });

    const check = await checkBeforeSend({ email: null, phone: '+61400000001' });
    expect(check.blocked).toBe(true);
    expect(check.matchedOn).toBe('phone');
  });

  it('suppression from any project blocks all projects', async () => {
    await addSuppression({
      email: 'owner@business.com',
      phone: '+61400000001',
      source: '333method',
    });

    expect(await isSuppressed('owner@business.com')).toBe(true);
    expect(await isSuppressed('+61400000001')).toBe(true);

    expect((await checkBeforeSend({ email: 'owner@business.com' })).blocked).toBe(true);
    expect((await checkBeforeSend({ phone: '+61400000001' })).blocked).toBe(true);
  });
});

// ── getSuppressionsAfter (sync polling) ─────────────────────────────────────

describe('getSuppressionsAfter', () => {
  it('returns entries after the given timestamp', async () => {
    await addSuppression({
      email: 'old@example.com',
      phone: null,
      source: '333method',
      opted_out_at: '2026-01-01T00:00:00.000Z',
    });

    await addSuppression({
      email: 'new@example.com',
      phone: null,
      source: '2step',
      opted_out_at: '2026-03-26T12:00:00.000Z',
    });

    const results = await getSuppressionsAfter('2026-03-01T00:00:00.000Z');
    expect(results).toHaveLength(1);
    expect(results[0].email).toBe('new@example.com');
  });

  it('returns empty array when no entries match', async () => {
    await addSuppression({
      email: 'test@example.com',
      phone: null,
      source: '333method',
      opted_out_at: '2026-01-01T00:00:00.000Z',
    });

    const results = await getSuppressionsAfter('2026-12-31T00:00:00.000Z');
    expect(results).toHaveLength(0);
  });

  it('returns all entries when timestamp is very old', async () => {
    await addSuppression({ email: 'a@example.com', phone: null, source: '333method', opted_out_at: '2026-01-01T00:00:00.000Z' });
    await addSuppression({ email: 'b@example.com', phone: null, source: '2step', opted_out_at: '2026-02-01T00:00:00.000Z' });
    await addSuppression({ email: 'c@example.com', phone: null, source: '333method', opted_out_at: '2026-03-01T00:00:00.000Z' });

    const results = await getSuppressionsAfter('2020-01-01T00:00:00.000Z');
    expect(results).toHaveLength(3);
  });

  it('results are ordered by opted_out_at ascending', async () => {
    await addSuppression({ email: 'c@example.com', phone: null, source: '333method', opted_out_at: '2026-03-01T00:00:00.000Z' });
    await addSuppression({ email: 'a@example.com', phone: null, source: '333method', opted_out_at: '2026-01-01T00:00:00.000Z' });
    await addSuppression({ email: 'b@example.com', phone: null, source: '2step', opted_out_at: '2026-02-01T00:00:00.000Z' });

    const results = await getSuppressionsAfter('2020-01-01T00:00:00.000Z');
    expect(results[0].email).toBe('a@example.com');
    expect(results[1].email).toBe('b@example.com');
    expect(results[2].email).toBe('c@example.com');
  });

  it('throws if timestamp is null or missing', async () => {
    await expect(getSuppressionsAfter(null)).rejects.toThrow('ISO timestamp');
    await expect(getSuppressionsAfter(undefined)).rejects.toThrow('ISO timestamp');
  });

  it('includes all expected fields in results', async () => {
    await addSuppression({
      email: 'test@example.com',
      phone: '+61400000001',
      source: '333method',
      reason: 'bounce',
      opted_out_at: '2026-03-26T12:00:00.000Z',
    });

    const results = await getSuppressionsAfter('2026-01-01T00:00:00.000Z');
    expect(results).toHaveLength(1);

    const row = results[0];
    expect(row).toHaveProperty('id');
    expect(row.email).toBe('test@example.com');
    expect(row.phone).toBe('+61400000001');
    expect(row.source).toBe('333method');
    expect(row.reason).toBe('bounce');
    expect(row).toHaveProperty('opted_out_at');
    expect(row).toHaveProperty('created_at');
    expect(row).toHaveProperty('updated_at');
  });
});

// ── checkBeforeSend ─────────────────────────────────────────────────────────

describe('checkBeforeSend', () => {
  it('returns blocked=false when email and phone are clean', async () => {
    const result = await checkBeforeSend({ email: 'clean@example.com', phone: '+61400000099' });
    expect(result.blocked).toBe(false);
    expect(result.reason).toBeUndefined();
    expect(result.matchedOn).toBeUndefined();
  });

  it('returns blocked=true with matchedOn=email when email is suppressed', async () => {
    await addSuppression({ email: 'blocked@example.com', phone: null, source: '333method', reason: 'stop_keyword' });

    const result = await checkBeforeSend({ email: 'blocked@example.com', phone: '+61400000099' });
    expect(result.blocked).toBe(true);
    expect(result.matchedOn).toBe('email');
    expect(result.reason).toBe('suppressed_stop_keyword');
  });

  it('returns blocked=true with matchedOn=phone when phone is suppressed', async () => {
    await addSuppression({ email: null, phone: '+61400000001', source: '2step', reason: 'complaint' });

    const result = await checkBeforeSend({ email: 'clean@example.com', phone: '+61400000001' });
    expect(result.blocked).toBe(true);
    expect(result.matchedOn).toBe('phone');
    expect(result.reason).toBe('suppressed_complaint');
  });

  it('returns blocked=false when neither email nor phone provided', async () => {
    const result = await checkBeforeSend({ email: null, phone: null });
    expect(result.blocked).toBe(false);
  });

  it('email match takes priority over phone match', async () => {
    await addSuppression({
      email: 'blocked@example.com',
      phone: '+61400000001',
      source: '333method',
      reason: 'bounce',
    });

    const result = await checkBeforeSend({ email: 'blocked@example.com', phone: '+61400000001' });
    expect(result.blocked).toBe(true);
    expect(result.matchedOn).toBe('email');
  });

  it('includes reason with suppressed_ prefix', async () => {
    await addSuppression({ email: 'test@example.com', phone: null, source: '333method', reason: 'unsubscribe' });

    const result = await checkBeforeSend({ email: 'test@example.com' });
    expect(result.reason).toBe('suppressed_unsubscribe');
  });

  it('uses opt_out as default reason when reason is null', async () => {
    await addSuppression({ email: 'test@example.com', phone: null, source: '333method' });

    const result = await checkBeforeSend({ email: 'test@example.com' });
    expect(result.reason).toBe('suppressed_opt_out');
  });
});

// ── removeSuppression ───────────────────────────────────────────────────────

describe('removeSuppression', () => {
  it('removes a suppressed email', async () => {
    await addSuppression({ email: 'blocked@example.com', phone: null, source: '333method' });
    expect(await isSuppressed('blocked@example.com')).toBe(true);

    const removed = await removeSuppression('blocked@example.com');
    expect(removed).toBe(true);
    expect(await isSuppressed('blocked@example.com')).toBe(false);
  });

  it('removes a suppressed phone', async () => {
    await addSuppression({ email: null, phone: '+61400000001', source: '2step' });
    expect(await isSuppressed('+61400000001')).toBe(true);

    const removed = await removeSuppression('+61400000001');
    expect(removed).toBe(true);
    expect(await isSuppressed('+61400000001')).toBe(false);
  });

  it('returns false when nothing to remove', async () => {
    const removed = await removeSuppression('nonexistent@example.com');
    expect(removed).toBe(false);
  });

  it('returns false for null/empty', async () => {
    expect(await removeSuppression(null)).toBe(false);
    expect(await removeSuppression('')).toBe(false);
  });

  it('removal for all projects — cross-project UNSTOP', async () => {
    await addSuppression({ email: 'blocked@example.com', phone: null, source: '333method' });

    expect((await checkBeforeSend({ email: 'blocked@example.com' })).blocked).toBe(true);

    await removeSuppression('blocked@example.com');

    expect((await checkBeforeSend({ email: 'blocked@example.com' })).blocked).toBe(false);
    expect(await isSuppressed('blocked@example.com')).toBe(false);
  });
});

// ── batchImport ─────────────────────────────────────────────────────────────

describe('batchImport', () => {
  it('imports multiple entries', async () => {
    const entries = [
      { email: 'a@example.com', phone: null, source: '333method', reason: 'stop_keyword' },
      { email: 'b@example.com', phone: '+61400000002', source: '2step', reason: 'bounce' },
      { email: 'c@example.com', phone: null, source: 'manual', reason: 'manual' },
    ];

    const result = await batchImport(entries);
    expect(result.imported).toBe(3);
    expect(result.merged).toBe(0);
    expect(result.errors).toBe(0);
    expect(await countSuppressions()).toBe(3);
  });

  it('merges duplicates within the batch', async () => {
    const entries = [
      { email: 'a@example.com', phone: null, source: '333method' },
      { email: 'a@example.com', phone: '+61400000001', source: '2step' },
    ];

    const result = await batchImport(entries);
    expect(result.imported).toBe(1);
    expect(result.merged).toBe(1);
    expect(result.errors).toBe(0);
    expect(await countSuppressions()).toBe(1);
  });

  it('counts errors for invalid entries', async () => {
    const entries = [
      { email: 'valid@example.com', phone: null, source: '333method' },
      { email: null, phone: null, source: '333method' },
      { email: 'also-valid@example.com', phone: null, source: '2step' },
    ];

    const result = await batchImport(entries);
    expect(result.imported).toBe(2);
    expect(result.errors).toBe(1);
  });

  it('is atomic — all or nothing on success', async () => {
    const entries = [
      { email: 'a@example.com', phone: null, source: '333method' },
      { email: 'b@example.com', phone: null, source: '2step' },
    ];

    await batchImport(entries);
    expect(await countSuppressions()).toBe(2);
  });
});

// ── getAllSuppressions ───────────────────────────────────────────────────────

describe('getAllSuppressions', () => {
  it('returns empty array when no suppressions', async () => {
    const all = await getAllSuppressions();
    expect(all).toHaveLength(0);
  });

  it('returns all entries', async () => {
    await addSuppression({ email: 'a@example.com', phone: null, source: '333method' });
    await addSuppression({ email: 'b@example.com', phone: null, source: '2step' });

    const all = await getAllSuppressions();
    expect(all).toHaveLength(2);
  });
});

// ── countSuppressions ───────────────────────────────────────────────────────

describe('countSuppressions', () => {
  it('returns 0 for empty database', async () => {
    expect(await countSuppressions()).toBe(0);
  });

  it('returns correct count', async () => {
    await addSuppression({ email: 'a@example.com', phone: null, source: '333method' });
    await addSuppression({ email: 'b@example.com', phone: null, source: '2step' });
    expect(await countSuppressions()).toBe(2);
  });

  it('does not double-count merged entries', async () => {
    await addSuppression({ email: 'a@example.com', phone: null, source: '333method' });
    await addSuppression({ email: 'a@example.com', phone: '+61400000001', source: '2step' });
    expect(await countSuppressions()).toBe(1);
  });
});

// ── openDb (legacy compat) ──────────────────────────────────────────────────

describe('openDb', () => {
  it('returns an object with a no-op close()', () => {
    const handle = openDb();
    expect(handle).toBeDefined();
    expect(typeof handle.close).toBe('function');
    handle.close(); // should not throw
  });
});

// ── Full lifecycle ──────────────────────────────────────────────────────────

describe('full opt-out lifecycle', () => {
  it('add -> check -> remove -> check', async () => {
    await addSuppression({
      email: 'owner@business.com',
      phone: '+61400000001',
      source: '333method',
      reason: 'stop_keyword',
    });

    expect((await checkBeforeSend({ email: 'owner@business.com' })).blocked).toBe(true);
    expect((await checkBeforeSend({ phone: '+61400000001' })).blocked).toBe(true);
    expect(await countSuppressions()).toBe(1);

    await removeSuppression('+61400000001');

    expect((await checkBeforeSend({ email: 'owner@business.com' })).blocked).toBe(false);
    expect((await checkBeforeSend({ phone: '+61400000001' })).blocked).toBe(false);
    expect(await countSuppressions()).toBe(0);
  });

  it('multiple suppressions — partial removal', async () => {
    await addSuppression({ email: 'a@example.com', phone: null, source: '333method' });
    await addSuppression({ email: 'b@example.com', phone: null, source: '2step' });
    await addSuppression({ email: 'c@example.com', phone: null, source: '333method' });

    expect(await countSuppressions()).toBe(3);

    await removeSuppression('b@example.com');

    expect(await countSuppressions()).toBe(2);
    expect(await isSuppressed('a@example.com')).toBe(true);
    expect(await isSuppressed('b@example.com')).toBe(false);
    expect(await isSuppressed('c@example.com')).toBe(true);
  });

  it('sync polling after batch operations', async () => {
    await batchImport([
      { email: 'old1@example.com', phone: null, source: '333method', opted_out_at: '2026-01-01T00:00:00.000Z' },
      { email: 'old2@example.com', phone: null, source: '333method', opted_out_at: '2026-02-01T00:00:00.000Z' },
    ]);

    await addSuppression({
      email: 'new@example.com',
      phone: null,
      source: '2step',
      opted_out_at: '2026-03-26T15:00:00.000Z',
    });

    const since = await getSuppressionsAfter('2026-03-01T00:00:00.000Z');
    expect(since).toHaveLength(1);
    expect(since[0].email).toBe('new@example.com');

    const sinceMid = await getSuppressionsAfter('2026-01-15T00:00:00.000Z');
    expect(sinceMid).toHaveLength(2);
  });
});

// ── Edge cases ──────────────────────────────────────────────────────────────

describe('edge cases', () => {
  it('handles international phone formats', async () => {
    await addSuppression({ email: null, phone: '+44 20 7946 0958', source: '333method' });
    expect(await isPhoneSuppressed('+442079460958')).toBe(true);
    expect(await isSuppressed('+44 20 7946 0958')).toBe(true);
  });

  it('handles very long email addresses', async () => {
    const longEmail = 'a'.repeat(200) + '@example.com';
    await addSuppression({ email: longEmail, phone: null, source: '333method' });
    expect(await isEmailSuppressed(longEmail)).toBe(true);
  });

  it('handles email with special characters', async () => {
    const specialEmail = 'owner+tag@sub.example.co.uk';
    await addSuppression({ email: specialEmail, phone: null, source: '2step' });
    expect(await isEmailSuppressed(specialEmail)).toBe(true);
  });

  it('does not treat email addresses as phone numbers', async () => {
    await addSuppression({ email: 'test@example.com', phone: null, source: '333method' });
    expect(await isSuppressed('test@example.com')).toBe(true);
  });

  it('concurrent adds for same contact merge correctly', async () => {
    await addSuppression({ email: 'owner@biz.com', phone: null, source: '333method', reason: 'bounce' });
    await addSuppression({ email: null, phone: '+61400000001', source: '2step', reason: 'stop_keyword' });

    expect(await countSuppressions()).toBe(2);

    await addSuppression({ email: 'owner@biz.com', phone: '+61400000001', source: '333method', reason: 'manual' });

    expect(await countSuppressions()).toBe(1);
    expect((await checkBeforeSend({ email: 'owner@biz.com' })).blocked).toBe(true);
    expect((await checkBeforeSend({ phone: '+61400000001' })).blocked).toBe(true);
  });
});

}); // describe.skipIf(!hasPg)
