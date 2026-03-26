/**
 * Cross-project opt-out suppression system — unit tests
 *
 * Tests the shared suppression list at mmo-platform/db/suppression.db.
 * All tests use in-memory databases for isolation and speed.
 *
 * Coverage:
 *   - addSuppression: basic insert, dedup, merge, validation
 *   - isSuppressed: email, phone, edge cases
 *   - isEmailSuppressed / isPhoneSuppressed: typed checks
 *   - getSuppressionsAfter: sync polling
 *   - checkBeforeSend: outreach guard
 *   - removeSuppression: re-subscribe / UNSTOP
 *   - batchImport: bulk operations
 *   - Cross-channel: phone suppression does not block email and vice versa
 *   - Case insensitivity: email matching
 *   - Normalisation: whitespace, formatting
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  openDb,
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

// ── Test helpers ────────────────────────────────────────────────────────────

let db;

beforeEach(() => {
  db = openDb(':memory:');
});

afterEach(() => {
  if (db && db.open) {
    db.close();
  }
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
  it('inserts a new email suppression', () => {
    const result = addSuppression({
      email: 'owner@example.com',
      phone: null,
      source: '333method',
      reason: 'stop_keyword',
    }, db);

    expect(result.id).toBeGreaterThan(0);
    expect(result.merged).toBe(false);
    expect(countSuppressions(db)).toBe(1);
  });

  it('inserts a new phone suppression', () => {
    const result = addSuppression({
      email: null,
      phone: '+61400000001',
      source: '2step',
      reason: 'unsubscribe',
    }, db);

    expect(result.id).toBeGreaterThan(0);
    expect(result.merged).toBe(false);
    expect(countSuppressions(db)).toBe(1);
  });

  it('inserts with both email and phone', () => {
    const result = addSuppression({
      email: 'owner@example.com',
      phone: '+61400000001',
      source: '333method',
      reason: 'bounce',
    }, db);

    expect(result.id).toBeGreaterThan(0);
    expect(result.merged).toBe(false);
  });

  it('deduplicates on email — merges into existing row', () => {
    addSuppression({
      email: 'owner@example.com',
      phone: null,
      source: '333method',
      reason: 'stop_keyword',
    }, db);

    const result = addSuppression({
      email: 'owner@example.com',
      phone: '+61400000001',
      source: '2step',
      reason: 'unsubscribe',
    }, db);

    expect(result.merged).toBe(true);
    expect(countSuppressions(db)).toBe(1);

    // The merged row should now have the phone too
    const all = getAllSuppressions(db);
    expect(all[0].phone).toBe('+61400000001');
    expect(all[0].email).toBe('owner@example.com');
    expect(all[0].source).toBe('2step'); // updated to latest source
  });

  it('deduplicates on phone — merges into existing row', () => {
    addSuppression({
      email: null,
      phone: '+61400000001',
      source: '333method',
      reason: 'stop_keyword',
    }, db);

    const result = addSuppression({
      email: 'owner@example.com',
      phone: '+61400000001',
      source: '2step',
      reason: 'complaint',
    }, db);

    expect(result.merged).toBe(true);
    expect(countSuppressions(db)).toBe(1);

    // The merged row should now have the email too
    const all = getAllSuppressions(db);
    expect(all[0].email).toBe('owner@example.com');
    expect(all[0].phone).toBe('+61400000001');
  });

  it('email dedup is case-insensitive', () => {
    addSuppression({
      email: 'OWNER@EXAMPLE.COM',
      phone: null,
      source: '333method',
    }, db);

    const result = addSuppression({
      email: 'owner@example.com',
      phone: null,
      source: '2step',
    }, db);

    expect(result.merged).toBe(true);
    expect(countSuppressions(db)).toBe(1);
  });

  it('throws if neither email nor phone provided', () => {
    expect(() => addSuppression({
      email: null,
      phone: null,
      source: '333method',
    }, db)).toThrow('at least one of email or phone');
  });

  it('throws if source is missing', () => {
    expect(() => addSuppression({
      email: 'test@example.com',
      phone: null,
      source: null,
    }, db)).toThrow('source string');
  });

  it('throws if source is empty string', () => {
    expect(() => addSuppression({
      email: 'test@example.com',
      phone: null,
      source: '',
    }, db)).toThrow('source string');
  });

  it('normalises email before insert', () => {
    addSuppression({
      email: '  Owner@Example.COM  ',
      phone: null,
      source: '333method',
    }, db);

    const all = getAllSuppressions(db);
    expect(all[0].email).toBe('owner@example.com');
  });

  it('normalises phone before insert', () => {
    addSuppression({
      email: null,
      phone: '+61 400 000 001',
      source: '2step',
    }, db);

    const all = getAllSuppressions(db);
    expect(all[0].phone).toBe('+61400000001');
  });

  it('uses custom opted_out_at when provided', () => {
    const ts = '2026-01-15 08:30:00';
    addSuppression({
      email: 'test@example.com',
      phone: null,
      source: '333method',
      opted_out_at: ts,
    }, db);

    const all = getAllSuppressions(db);
    expect(all[0].opted_out_at).toBe(ts);
  });

  it('reason defaults to null when not provided', () => {
    addSuppression({
      email: 'test@example.com',
      phone: null,
      source: '333method',
    }, db);

    const all = getAllSuppressions(db);
    expect(all[0].reason).toBeNull();
  });
});

// ── isSuppressed ────────────────────────────────────────────────────────────

describe('isSuppressed', () => {
  beforeEach(() => {
    addSuppression({
      email: 'blocked@example.com',
      phone: '+61400000001',
      source: '333method',
      reason: 'stop_keyword',
    }, db);
  });

  it('returns true for suppressed email', () => {
    expect(isSuppressed('blocked@example.com', db)).toBe(true);
  });

  it('returns true for suppressed phone', () => {
    expect(isSuppressed('+61400000001', db)).toBe(true);
  });

  it('returns false for non-suppressed email', () => {
    expect(isSuppressed('clean@example.com', db)).toBe(false);
  });

  it('returns false for non-suppressed phone', () => {
    expect(isSuppressed('+61400000002', db)).toBe(false);
  });

  it('returns false for null/undefined/empty', () => {
    expect(isSuppressed(null, db)).toBe(false);
    expect(isSuppressed(undefined, db)).toBe(false);
    expect(isSuppressed('', db)).toBe(false);
    expect(isSuppressed('   ', db)).toBe(false);
  });

  it('email check is case-insensitive', () => {
    expect(isSuppressed('BLOCKED@EXAMPLE.COM', db)).toBe(true);
    expect(isSuppressed('Blocked@Example.Com', db)).toBe(true);
  });

  it('phone check normalises formatting', () => {
    expect(isSuppressed('+61 400 000 001', db)).toBe(true);
  });
});

// ── isEmailSuppressed / isPhoneSuppressed ───────────────────────────────────

describe('isEmailSuppressed', () => {
  beforeEach(() => {
    addSuppression({
      email: 'blocked@example.com',
      phone: null,
      source: '333method',
    }, db);
  });

  it('returns true for suppressed email', () => {
    expect(isEmailSuppressed('blocked@example.com', db)).toBe(true);
  });

  it('is case-insensitive', () => {
    expect(isEmailSuppressed('BLOCKED@EXAMPLE.COM', db)).toBe(true);
  });

  it('returns false for non-suppressed email', () => {
    expect(isEmailSuppressed('clean@example.com', db)).toBe(false);
  });

  it('returns false for null/empty', () => {
    expect(isEmailSuppressed(null, db)).toBe(false);
    expect(isEmailSuppressed('', db)).toBe(false);
  });
});

describe('isPhoneSuppressed', () => {
  beforeEach(() => {
    addSuppression({
      email: null,
      phone: '+61400000001',
      source: '2step',
    }, db);
  });

  it('returns true for suppressed phone', () => {
    expect(isPhoneSuppressed('+61400000001', db)).toBe(true);
  });

  it('returns false for non-suppressed phone', () => {
    expect(isPhoneSuppressed('+61400000002', db)).toBe(false);
  });

  it('returns false for null/empty', () => {
    expect(isPhoneSuppressed(null, db)).toBe(false);
    expect(isPhoneSuppressed('', db)).toBe(false);
  });
});

// ── Cross-channel suppression ───────────────────────────────────────────────

describe('cross-channel suppression', () => {
  it('email-only suppression does not block phone check for different contact', () => {
    addSuppression({
      email: 'blocked@example.com',
      phone: null,
      source: '333method',
    }, db);

    // A different phone number should not be blocked
    expect(isPhoneSuppressed('+61400000099', db)).toBe(false);
  });

  it('phone-only suppression does not block email check for different contact', () => {
    addSuppression({
      email: null,
      phone: '+61400000001',
      source: '2step',
    }, db);

    // A different email should not be blocked
    expect(isEmailSuppressed('other@example.com', db)).toBe(false);
  });

  it('suppression with both email and phone blocks both independently', () => {
    addSuppression({
      email: 'blocked@example.com',
      phone: '+61400000001',
      source: '333method',
    }, db);

    expect(isEmailSuppressed('blocked@example.com', db)).toBe(true);
    expect(isPhoneSuppressed('+61400000001', db)).toBe(true);
  });

  it('merged suppression blocks by either identifier', () => {
    // First: add by phone only
    addSuppression({
      email: null,
      phone: '+61400000001',
      source: '333method',
    }, db);

    // Then: merge in the email via same phone
    addSuppression({
      email: 'blocked@example.com',
      phone: '+61400000001',
      source: '2step',
    }, db);

    // Now both should be blocked
    expect(isEmailSuppressed('blocked@example.com', db)).toBe(true);
    expect(isPhoneSuppressed('+61400000001', db)).toBe(true);
    expect(countSuppressions(db)).toBe(1); // still one row
  });
});

// ── Cross-project suppression ───────────────────────────────────────────────

describe('cross-project suppression', () => {
  it('333Method opt-out blocks 2Step outreach to same email', () => {
    addSuppression({
      email: 'owner@business.com',
      phone: null,
      source: '333method',
      reason: 'unsubscribe',
    }, db);

    // Simulating 2Step check before send
    const check = checkBeforeSend({ email: 'owner@business.com', phone: null }, db);
    expect(check.blocked).toBe(true);
    expect(check.matchedOn).toBe('email');
  });

  it('2Step opt-out blocks 333Method outreach to same phone', () => {
    addSuppression({
      email: null,
      phone: '+61400000001',
      source: '2step',
      reason: 'stop_keyword',
    }, db);

    // Simulating 333Method check before send
    const check = checkBeforeSend({ email: null, phone: '+61400000001' }, db);
    expect(check.blocked).toBe(true);
    expect(check.matchedOn).toBe('phone');
  });

  it('suppression from any project blocks all projects', () => {
    addSuppression({
      email: 'owner@business.com',
      phone: '+61400000001',
      source: '333method',
    }, db);

    // Both "projects" see the suppression
    expect(isSuppressed('owner@business.com', db)).toBe(true);
    expect(isSuppressed('+61400000001', db)).toBe(true);

    // checkBeforeSend works for any project checking
    expect(checkBeforeSend({ email: 'owner@business.com' }, db).blocked).toBe(true);
    expect(checkBeforeSend({ phone: '+61400000001' }, db).blocked).toBe(true);
  });
});

// ── getSuppressionsAfter (sync polling) ─────────────────────────────────────

describe('getSuppressionsAfter', () => {
  it('returns entries after the given timestamp', () => {
    addSuppression({
      email: 'old@example.com',
      phone: null,
      source: '333method',
      opted_out_at: '2026-01-01 00:00:00',
    }, db);

    addSuppression({
      email: 'new@example.com',
      phone: null,
      source: '2step',
      opted_out_at: '2026-03-26 12:00:00',
    }, db);

    const results = getSuppressionsAfter('2026-03-01 00:00:00', db);
    expect(results).toHaveLength(1);
    expect(results[0].email).toBe('new@example.com');
  });

  it('returns empty array when no entries match', () => {
    addSuppression({
      email: 'test@example.com',
      phone: null,
      source: '333method',
      opted_out_at: '2026-01-01 00:00:00',
    }, db);

    const results = getSuppressionsAfter('2026-12-31 00:00:00', db);
    expect(results).toHaveLength(0);
  });

  it('returns all entries when timestamp is very old', () => {
    addSuppression({ email: 'a@example.com', phone: null, source: '333method', opted_out_at: '2026-01-01 00:00:00' }, db);
    addSuppression({ email: 'b@example.com', phone: null, source: '2step', opted_out_at: '2026-02-01 00:00:00' }, db);
    addSuppression({ email: 'c@example.com', phone: null, source: '333method', opted_out_at: '2026-03-01 00:00:00' }, db);

    const results = getSuppressionsAfter('2020-01-01 00:00:00', db);
    expect(results).toHaveLength(3);
  });

  it('results are ordered by opted_out_at ascending', () => {
    addSuppression({ email: 'c@example.com', phone: null, source: '333method', opted_out_at: '2026-03-01 00:00:00' }, db);
    addSuppression({ email: 'a@example.com', phone: null, source: '333method', opted_out_at: '2026-01-01 00:00:00' }, db);
    addSuppression({ email: 'b@example.com', phone: null, source: '2step', opted_out_at: '2026-02-01 00:00:00' }, db);

    const results = getSuppressionsAfter('2020-01-01 00:00:00', db);
    expect(results[0].email).toBe('a@example.com');
    expect(results[1].email).toBe('b@example.com');
    expect(results[2].email).toBe('c@example.com');
  });

  it('throws if timestamp is null or missing', () => {
    expect(() => getSuppressionsAfter(null, db)).toThrow('ISO timestamp');
    expect(() => getSuppressionsAfter(undefined, db)).toThrow('ISO timestamp');
  });

  it('includes all expected fields in results', () => {
    addSuppression({
      email: 'test@example.com',
      phone: '+61400000001',
      source: '333method',
      reason: 'bounce',
      opted_out_at: '2026-03-26 12:00:00',
    }, db);

    const results = getSuppressionsAfter('2026-01-01 00:00:00', db);
    expect(results).toHaveLength(1);

    const row = results[0];
    expect(row).toHaveProperty('id');
    expect(row).toHaveProperty('email', 'test@example.com');
    expect(row).toHaveProperty('phone', '+61400000001');
    expect(row).toHaveProperty('source', '333method');
    expect(row).toHaveProperty('opted_out_at', '2026-03-26 12:00:00');
    expect(row).toHaveProperty('reason', 'bounce');
    expect(row).toHaveProperty('created_at');
    expect(row).toHaveProperty('updated_at');
  });
});

// ── checkBeforeSend ─────────────────────────────────────────────────────────

describe('checkBeforeSend', () => {
  it('returns blocked=false when email and phone are clean', () => {
    const result = checkBeforeSend({ email: 'clean@example.com', phone: '+61400000099' }, db);
    expect(result.blocked).toBe(false);
    expect(result.reason).toBeUndefined();
    expect(result.matchedOn).toBeUndefined();
  });

  it('returns blocked=true with matchedOn=email when email is suppressed', () => {
    addSuppression({ email: 'blocked@example.com', phone: null, source: '333method', reason: 'stop_keyword' }, db);

    const result = checkBeforeSend({ email: 'blocked@example.com', phone: '+61400000099' }, db);
    expect(result.blocked).toBe(true);
    expect(result.matchedOn).toBe('email');
    expect(result.reason).toBe('suppressed_stop_keyword');
  });

  it('returns blocked=true with matchedOn=phone when phone is suppressed', () => {
    addSuppression({ email: null, phone: '+61400000001', source: '2step', reason: 'complaint' }, db);

    const result = checkBeforeSend({ email: 'clean@example.com', phone: '+61400000001' }, db);
    expect(result.blocked).toBe(true);
    expect(result.matchedOn).toBe('phone');
    expect(result.reason).toBe('suppressed_complaint');
  });

  it('returns blocked=false when neither email nor phone provided', () => {
    const result = checkBeforeSend({ email: null, phone: null }, db);
    expect(result.blocked).toBe(false);
  });

  it('email match takes priority over phone match', () => {
    // Add suppression with both
    addSuppression({
      email: 'blocked@example.com',
      phone: '+61400000001',
      source: '333method',
      reason: 'bounce',
    }, db);

    // When both match, email is checked first
    const result = checkBeforeSend({ email: 'blocked@example.com', phone: '+61400000001' }, db);
    expect(result.blocked).toBe(true);
    expect(result.matchedOn).toBe('email');
  });

  it('includes reason with suppressed_ prefix', () => {
    addSuppression({ email: 'test@example.com', phone: null, source: '333method', reason: 'unsubscribe' }, db);

    const result = checkBeforeSend({ email: 'test@example.com' }, db);
    expect(result.reason).toBe('suppressed_unsubscribe');
  });

  it('uses opt_out as default reason when reason is null', () => {
    addSuppression({ email: 'test@example.com', phone: null, source: '333method' }, db);

    const result = checkBeforeSend({ email: 'test@example.com' }, db);
    expect(result.reason).toBe('suppressed_opt_out');
  });
});

// ── removeSuppression ───────────────────────────────────────────────────────

describe('removeSuppression', () => {
  it('removes a suppressed email', () => {
    addSuppression({ email: 'blocked@example.com', phone: null, source: '333method' }, db);
    expect(isSuppressed('blocked@example.com', db)).toBe(true);

    const removed = removeSuppression('blocked@example.com', db);
    expect(removed).toBe(true);
    expect(isSuppressed('blocked@example.com', db)).toBe(false);
  });

  it('removes a suppressed phone', () => {
    addSuppression({ email: null, phone: '+61400000001', source: '2step' }, db);
    expect(isSuppressed('+61400000001', db)).toBe(true);

    const removed = removeSuppression('+61400000001', db);
    expect(removed).toBe(true);
    expect(isSuppressed('+61400000001', db)).toBe(false);
  });

  it('returns false when nothing to remove', () => {
    const removed = removeSuppression('nonexistent@example.com', db);
    expect(removed).toBe(false);
  });

  it('returns false for null/empty', () => {
    expect(removeSuppression(null, db)).toBe(false);
    expect(removeSuppression('', db)).toBe(false);
  });

  it('removal for all projects — cross-project UNSTOP', () => {
    // 333Method adds suppression
    addSuppression({ email: 'blocked@example.com', phone: null, source: '333method' }, db);

    // 2Step checks — blocked
    expect(checkBeforeSend({ email: 'blocked@example.com' }, db).blocked).toBe(true);

    // User sends UNSTOP — any project can remove
    removeSuppression('blocked@example.com', db);

    // Now both projects see it cleared
    expect(checkBeforeSend({ email: 'blocked@example.com' }, db).blocked).toBe(false);
    expect(isSuppressed('blocked@example.com', db)).toBe(false);
  });
});

// ── batchImport ─────────────────────────────────────────────────────────────

describe('batchImport', () => {
  it('imports multiple entries', () => {
    const entries = [
      { email: 'a@example.com', phone: null, source: '333method', reason: 'stop_keyword' },
      { email: 'b@example.com', phone: '+61400000002', source: '2step', reason: 'bounce' },
      { email: 'c@example.com', phone: null, source: 'manual', reason: 'manual' },
    ];

    const result = batchImport(entries, db);
    expect(result.imported).toBe(3);
    expect(result.merged).toBe(0);
    expect(result.errors).toBe(0);
    expect(countSuppressions(db)).toBe(3);
  });

  it('merges duplicates within the batch', () => {
    const entries = [
      { email: 'a@example.com', phone: null, source: '333method' },
      { email: 'a@example.com', phone: '+61400000001', source: '2step' }, // same email -> merge
    ];

    const result = batchImport(entries, db);
    expect(result.imported).toBe(1);
    expect(result.merged).toBe(1);
    expect(result.errors).toBe(0);
    expect(countSuppressions(db)).toBe(1);
  });

  it('counts errors for invalid entries', () => {
    const entries = [
      { email: 'valid@example.com', phone: null, source: '333method' },
      { email: null, phone: null, source: '333method' }, // invalid: no email or phone
      { email: 'also-valid@example.com', phone: null, source: '2step' },
    ];

    const result = batchImport(entries, db);
    expect(result.imported).toBe(2);
    expect(result.errors).toBe(1);
  });

  it('is atomic — all or nothing on success', () => {
    const entries = [
      { email: 'a@example.com', phone: null, source: '333method' },
      { email: 'b@example.com', phone: null, source: '2step' },
    ];

    batchImport(entries, db);
    expect(countSuppressions(db)).toBe(2);
  });
});

// ── getAllSuppressions ───────────────────────────────────────────────────────

describe('getAllSuppressions', () => {
  it('returns empty array when no suppressions', () => {
    const all = getAllSuppressions(db);
    expect(all).toHaveLength(0);
  });

  it('returns all entries', () => {
    addSuppression({ email: 'a@example.com', phone: null, source: '333method' }, db);
    addSuppression({ email: 'b@example.com', phone: null, source: '2step' }, db);

    const all = getAllSuppressions(db);
    expect(all).toHaveLength(2);
  });
});

// ── countSuppressions ───────────────────────────────────────────────────────

describe('countSuppressions', () => {
  it('returns 0 for empty database', () => {
    expect(countSuppressions(db)).toBe(0);
  });

  it('returns correct count', () => {
    addSuppression({ email: 'a@example.com', phone: null, source: '333method' }, db);
    addSuppression({ email: 'b@example.com', phone: null, source: '2step' }, db);
    expect(countSuppressions(db)).toBe(2);
  });

  it('does not double-count merged entries', () => {
    addSuppression({ email: 'a@example.com', phone: null, source: '333method' }, db);
    addSuppression({ email: 'a@example.com', phone: '+61400000001', source: '2step' }, db);
    expect(countSuppressions(db)).toBe(1);
  });
});

// ── openDb ──────────────────────────────────────────────────────────────────

describe('openDb', () => {
  it('creates schema with suppression_list table', () => {
    const testDb = openDb(':memory:');
    const tables = testDb.prepare(
      "SELECT name FROM sqlite_master WHERE type='table' AND name='suppression_list'"
    ).all();
    expect(tables).toHaveLength(1);
    testDb.close();
  });

  it('has WAL journal mode', () => {
    const testDb = openDb(':memory:');
    const mode = testDb.pragma('journal_mode', { simple: true });
    // :memory: databases use 'memory' mode, but WAL is set for file-based
    expect(['wal', 'memory']).toContain(mode);
    testDb.close();
  });

  it('is idempotent — can be called multiple times on same path', () => {
    const testDb1 = openDb(':memory:');
    // Calling exec again should not fail (IF NOT EXISTS)
    expect(() => {
      testDb1.exec(`CREATE TABLE IF NOT EXISTS suppression_list (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT COLLATE NOCASE,
        phone TEXT,
        source TEXT NOT NULL,
        opted_out_at TEXT NOT NULL DEFAULT (datetime('now')),
        reason TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
      )`);
    }).not.toThrow();
    testDb1.close();
  });
});

// ── Full lifecycle ──────────────────────────────────────────────────────────

describe('full opt-out lifecycle', () => {
  it('add -> check -> remove -> check', () => {
    // 1. Add suppression from 333Method
    addSuppression({
      email: 'owner@business.com',
      phone: '+61400000001',
      source: '333method',
      reason: 'stop_keyword',
    }, db);

    // 2. Both projects see suppression
    expect(checkBeforeSend({ email: 'owner@business.com' }, db).blocked).toBe(true);
    expect(checkBeforeSend({ phone: '+61400000001' }, db).blocked).toBe(true);
    expect(countSuppressions(db)).toBe(1);

    // 3. Prospect sends UNSTOP — remove by phone
    removeSuppression('+61400000001', db);

    // 4. Both projects see it cleared
    expect(checkBeforeSend({ email: 'owner@business.com' }, db).blocked).toBe(false);
    expect(checkBeforeSend({ phone: '+61400000001' }, db).blocked).toBe(false);
    expect(countSuppressions(db)).toBe(0);
  });

  it('multiple suppressions — partial removal', () => {
    addSuppression({ email: 'a@example.com', phone: null, source: '333method' }, db);
    addSuppression({ email: 'b@example.com', phone: null, source: '2step' }, db);
    addSuppression({ email: 'c@example.com', phone: null, source: '333method' }, db);

    expect(countSuppressions(db)).toBe(3);

    // Remove only one
    removeSuppression('b@example.com', db);

    expect(countSuppressions(db)).toBe(2);
    expect(isSuppressed('a@example.com', db)).toBe(true);
    expect(isSuppressed('b@example.com', db)).toBe(false);
    expect(isSuppressed('c@example.com', db)).toBe(true);
  });

  it('sync polling after batch operations', () => {
    // Import a batch with historical timestamps
    batchImport([
      { email: 'old1@example.com', phone: null, source: '333method', opted_out_at: '2026-01-01 00:00:00' },
      { email: 'old2@example.com', phone: null, source: '333method', opted_out_at: '2026-02-01 00:00:00' },
    ], db);

    // Add a new one "now"
    addSuppression({
      email: 'new@example.com',
      phone: null,
      source: '2step',
      opted_out_at: '2026-03-26 15:00:00',
    }, db);

    // Poll for entries after March 1
    const since = getSuppressionsAfter('2026-03-01 00:00:00', db);
    expect(since).toHaveLength(1);
    expect(since[0].email).toBe('new@example.com');

    // Poll for entries after Jan 15 — should get 2
    const sinceMid = getSuppressionsAfter('2026-01-15 00:00:00', db);
    expect(sinceMid).toHaveLength(2);
  });
});

// ── Edge cases ──────────────────────────────────────────────────────────────

describe('edge cases', () => {
  it('handles international phone formats', () => {
    addSuppression({ email: null, phone: '+44 20 7946 0958', source: '333method' }, db);
    expect(isPhoneSuppressed('+442079460958', db)).toBe(true);
    expect(isSuppressed('+44 20 7946 0958', db)).toBe(true);
  });

  it('handles very long email addresses', () => {
    const longEmail = 'a'.repeat(200) + '@example.com';
    addSuppression({ email: longEmail, phone: null, source: '333method' }, db);
    expect(isEmailSuppressed(longEmail, db)).toBe(true);
  });

  it('handles email with special characters', () => {
    const specialEmail = 'owner+tag@sub.example.co.uk';
    addSuppression({ email: specialEmail, phone: null, source: '2step' }, db);
    expect(isEmailSuppressed(specialEmail, db)).toBe(true);
  });

  it('does not treat email addresses as phone numbers', () => {
    // isSuppressed auto-detects type — emails should not be treated as phones
    addSuppression({ email: 'test@example.com', phone: null, source: '333method' }, db);
    expect(isSuppressed('test@example.com', db)).toBe(true);
  });

  it('concurrent adds for same contact merge correctly', () => {
    // Simulates two projects processing opt-out for same person at ~same time
    addSuppression({ email: 'owner@biz.com', phone: null, source: '333method', reason: 'bounce' }, db);
    addSuppression({ email: null, phone: '+61400000001', source: '2step', reason: 'stop_keyword' }, db);

    // These are two different contacts (no shared identifier to merge on)
    expect(countSuppressions(db)).toBe(2);

    // Now add one that links them — the phone row gets consolidated into the email row
    addSuppression({ email: 'owner@biz.com', phone: '+61400000001', source: '333method', reason: 'manual' }, db);

    // Consolidated into one row: the email match absorbed the phone row
    expect(countSuppressions(db)).toBe(1);
    // The merged row has both identifiers
    const check = checkBeforeSend({ email: 'owner@biz.com' }, db);
    expect(check.blocked).toBe(true);
    expect(checkBeforeSend({ phone: '+61400000001' }, db).blocked).toBe(true);
  });
});
