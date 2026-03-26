/**
 * Cross-project opt-out suppression system
 *
 * Centralised suppression list for all mmo-platform projects (333Method, 2Step, etc.).
 * When a prospect opts out in any project, they are suppressed across ALL projects.
 *
 * DB: ~/code/mmo-platform/db/suppression.db
 * Schema: suppression_list table — one row per (email, phone) pair.
 *
 * Integration:
 *   - 333Method: src/outreach/email.js, src/outreach/sms.js call isSuppressed() before send
 *   - 2Step: src/stages/outreach.js calls isSuppressed() before send
 *   - Both call addSuppression() when an opt-out is received (STOP, unsubscribe, bounce, etc.)
 *
 * The module is designed for injection: pass a db instance or let it open the default path.
 * Tests use :memory: databases via openDb(':memory:').
 */

import Database from 'better-sqlite3';
import { resolve } from 'path';
import { existsSync, mkdirSync } from 'fs';

// ── Default DB path ─────────────────────────────────────────────────────────

const DEFAULT_DB_DIR = resolve(
  import.meta.dirname ?? new URL('.', import.meta.url).pathname,
  '..',
  'db'
);
const DEFAULT_DB_PATH = resolve(DEFAULT_DB_DIR, 'suppression.db');

// ── Schema ──────────────────────────────────────────────────────────────────

const SCHEMA_SQL = `
  CREATE TABLE IF NOT EXISTS suppression_list (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    COLLATE NOCASE,
    phone         TEXT,
    source        TEXT    NOT NULL,
    opted_out_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    reason        TEXT,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
  );

  -- Unique on normalised email (case-insensitive) to prevent duplicates
  CREATE UNIQUE INDEX IF NOT EXISTS idx_suppression_email
    ON suppression_list(email COLLATE NOCASE) WHERE email IS NOT NULL;

  -- Unique on phone to prevent duplicates
  CREATE UNIQUE INDEX IF NOT EXISTS idx_suppression_phone
    ON suppression_list(phone) WHERE phone IS NOT NULL;

  -- Fast lookup for sync polling
  CREATE INDEX IF NOT EXISTS idx_suppression_opted_out_at
    ON suppression_list(opted_out_at);

  -- Source filter
  CREATE INDEX IF NOT EXISTS idx_suppression_source
    ON suppression_list(source);
`;

// ── Database lifecycle ──────────────────────────────────────────────────────

/**
 * Open (or create) the suppression database.
 * @param {string} [dbPath] - Path to the SQLite file, or ':memory:' for tests.
 * @returns {Database} - better-sqlite3 instance with schema applied
 */
export function openDb(dbPath = DEFAULT_DB_PATH) {
  if (dbPath !== ':memory:') {
    const dir = resolve(dbPath, '..');
    if (!existsSync(dir)) {
      mkdirSync(dir, { recursive: true });
    }
  }

  const db = new Database(dbPath);

  // Performance + concurrency pragmas
  db.pragma('journal_mode = WAL');
  db.pragma('busy_timeout = 10000');
  db.pragma('foreign_keys = ON');

  db.exec(SCHEMA_SQL);

  return db;
}

// ── Normalisation helpers ───────────────────────────────────────────────────

/**
 * Normalise an email to lowercase for consistent dedup.
 * @param {string|null|undefined} email
 * @returns {string|null}
 */
export function normaliseEmail(email) {
  if (!email || typeof email !== 'string') return null;
  const trimmed = email.trim().toLowerCase();
  return trimmed.length > 0 ? trimmed : null;
}

/**
 * Normalise a phone number: strip whitespace, ensure E.164 prefix.
 * Does NOT perform full validation — just basic cleanup.
 * @param {string|null|undefined} phone
 * @returns {string|null}
 */
export function normalisePhone(phone) {
  if (!phone || typeof phone !== 'string') return null;
  const trimmed = phone.replace(/[\s()-]/g, '');
  return trimmed.length > 0 ? trimmed : null;
}

// ── Core API ────────────────────────────────────────────────────────────────

/**
 * Add a suppression entry. Deduplicates on email and phone independently.
 *
 * If the same email or phone already exists, the row is updated with the
 * latest source, reason, and opted_out_at timestamp.
 *
 * @param {Object} opts
 * @param {string|null} opts.email - Email address (normalised internally)
 * @param {string|null} opts.phone - Phone number in E.164 (normalised internally)
 * @param {string} opts.source - Which project triggered the opt-out ('333method', '2step', 'manual', etc.)
 * @param {string} [opts.reason] - Why the opt-out happened ('stop_keyword', 'unsubscribe', 'bounce', 'complaint', 'manual')
 * @param {string} [opts.opted_out_at] - ISO timestamp; defaults to now
 * @param {Database} db - better-sqlite3 instance
 * @returns {{ id: number, merged: boolean }} - ID of the inserted/updated row, and whether it was a merge
 */
export function addSuppression({ email, phone, source, reason, opted_out_at }, db) {
  const normEmail = normaliseEmail(email);
  const normPhone = normalisePhone(phone);

  if (!normEmail && !normPhone) {
    throw new Error('addSuppression requires at least one of email or phone');
  }

  if (!source || typeof source !== 'string') {
    throw new Error('addSuppression requires a source string');
  }

  const ts = opted_out_at || new Date().toISOString().replace('T', ' ').slice(0, 19);

  // Strategy: check if a row already exists for this email or phone.
  // If it does, merge the data (add missing email/phone, update timestamp).
  // If not, insert a new row.

  const existing = findExistingRow(normEmail, normPhone, db);

  if (existing) {
    // Merge: update the row with any new identifiers and refresh the timestamp.
    // But first, check if the other identifier already lives on a DIFFERENT row.
    // If so, delete that other row and consolidate into `existing`.
    const mergedEmail = existing.email || normEmail;
    const mergedPhone = existing.phone || normPhone;

    // Check for conflicts: does the new phone/email belong to a different row?
    if (normPhone && !existing.phone) {
      const phoneRow = db.prepare(
        'SELECT id FROM suppression_list WHERE phone = ? AND id != ? LIMIT 1'
      ).get(normPhone, existing.id);
      if (phoneRow) {
        // Delete the conflicting row — we are consolidating into `existing`
        db.prepare('DELETE FROM suppression_list WHERE id = ?').run(phoneRow.id);
      }
    }
    if (normEmail && !existing.email) {
      const emailRow = db.prepare(
        'SELECT id FROM suppression_list WHERE email = ? COLLATE NOCASE AND id != ? LIMIT 1'
      ).get(normEmail, existing.id);
      if (emailRow) {
        db.prepare('DELETE FROM suppression_list WHERE id = ?').run(emailRow.id);
      }
    }

    db.prepare(`
      UPDATE suppression_list
      SET email = ?, phone = ?, source = ?, reason = ?,
          opted_out_at = ?, updated_at = datetime('now')
      WHERE id = ?
    `).run(mergedEmail, mergedPhone, source, reason ?? existing.reason, ts, existing.id);

    return { id: existing.id, merged: true };
  }

  // New row
  const result = db.prepare(`
    INSERT INTO suppression_list (email, phone, source, reason, opted_out_at)
    VALUES (?, ?, ?, ?, ?)
  `).run(normEmail, normPhone, source, reason ?? null, ts);

  return { id: Number(result.lastInsertRowid), merged: false };
}

/**
 * Find an existing suppression row by email or phone.
 * @param {string|null} email
 * @param {string|null} phone
 * @param {Database} db
 * @returns {Object|null} - Row object or null
 */
function findExistingRow(email, phone, db) {
  if (email && phone) {
    // Check email first, then phone — prefer the email match
    const byEmail = db.prepare(
      'SELECT * FROM suppression_list WHERE email = ? COLLATE NOCASE LIMIT 1'
    ).get(email);
    if (byEmail) return byEmail;

    return db.prepare(
      'SELECT * FROM suppression_list WHERE phone = ? LIMIT 1'
    ).get(phone) ?? null;
  }

  if (email) {
    return db.prepare(
      'SELECT * FROM suppression_list WHERE email = ? COLLATE NOCASE LIMIT 1'
    ).get(email) ?? null;
  }

  if (phone) {
    return db.prepare(
      'SELECT * FROM suppression_list WHERE phone = ? LIMIT 1'
    ).get(phone) ?? null;
  }

  return null;
}

/**
 * Check if an identifier (email or phone) is suppressed.
 *
 * @param {string} identifier - An email address or phone number
 * @param {Database} db - better-sqlite3 instance
 * @returns {boolean} - true if the identifier appears in the suppression list
 */
export function isSuppressed(identifier, db) {
  if (!identifier || typeof identifier !== 'string') return false;

  const trimmed = identifier.trim();
  if (trimmed.length === 0) return false;

  // Determine if this looks like a phone number (starts with + or is all digits)
  const looksLikePhone = /^\+?\d[\d\s()-]*$/.test(trimmed);

  if (looksLikePhone) {
    const normPhone = normalisePhone(trimmed);
    const row = db.prepare(
      'SELECT 1 FROM suppression_list WHERE phone = ? LIMIT 1'
    ).get(normPhone);
    return Boolean(row);
  }

  // Treat as email
  const normEmail = normaliseEmail(trimmed);
  const row = db.prepare(
    'SELECT 1 FROM suppression_list WHERE email = ? COLLATE NOCASE LIMIT 1'
  ).get(normEmail);
  return Boolean(row);
}

/**
 * Check if a specific email is suppressed.
 * @param {string} email
 * @param {Database} db
 * @returns {boolean}
 */
export function isEmailSuppressed(email, db) {
  const norm = normaliseEmail(email);
  if (!norm) return false;

  const row = db.prepare(
    'SELECT 1 FROM suppression_list WHERE email = ? COLLATE NOCASE LIMIT 1'
  ).get(norm);
  return Boolean(row);
}

/**
 * Check if a specific phone number is suppressed.
 * @param {string} phone
 * @param {Database} db
 * @returns {boolean}
 */
export function isPhoneSuppressed(phone, db) {
  const norm = normalisePhone(phone);
  if (!norm) return false;

  const row = db.prepare(
    'SELECT 1 FROM suppression_list WHERE phone = ? LIMIT 1'
  ).get(norm);
  return Boolean(row);
}

/**
 * Get all suppressions added after a given timestamp (for sync polling).
 *
 * Child projects can poll this periodically to pull new suppressions into
 * their local opt_outs tables.
 *
 * @param {string} timestamp - ISO datetime string (e.g. '2026-03-26 10:00:00')
 * @param {Database} db - better-sqlite3 instance
 * @returns {Array<Object>} - Array of suppression rows
 */
export function getSuppressionsAfter(timestamp, db) {
  if (!timestamp || typeof timestamp !== 'string') {
    throw new Error('getSuppressionsAfter requires an ISO timestamp string');
  }

  return db.prepare(`
    SELECT id, email, phone, source, opted_out_at, reason, created_at, updated_at
    FROM suppression_list
    WHERE opted_out_at > ?
    ORDER BY opted_out_at ASC
  `).all(timestamp);
}

/**
 * Get all suppressions (for full sync / debugging).
 * @param {Database} db
 * @returns {Array<Object>}
 */
export function getAllSuppressions(db) {
  return db.prepare(`
    SELECT id, email, phone, source, opted_out_at, reason, created_at, updated_at
    FROM suppression_list
    ORDER BY opted_out_at ASC
  `).all();
}

/**
 * Remove a suppression entry (re-subscribe / UNSTOP).
 *
 * @param {string} identifier - Email or phone to remove
 * @param {Database} db
 * @returns {boolean} - true if a row was deleted
 */
export function removeSuppression(identifier, db) {
  if (!identifier || typeof identifier !== 'string') return false;

  const trimmed = identifier.trim();
  if (trimmed.length === 0) return false;

  const looksLikePhone = /^\+?\d[\d\s()-]*$/.test(trimmed);

  if (looksLikePhone) {
    const normPhone = normalisePhone(trimmed);
    const result = db.prepare('DELETE FROM suppression_list WHERE phone = ?').run(normPhone);
    return result.changes > 0;
  }

  const normEmail = normaliseEmail(trimmed);
  const result = db.prepare(
    'DELETE FROM suppression_list WHERE email = ? COLLATE NOCASE'
  ).run(normEmail);
  return result.changes > 0;
}

/**
 * Count total suppressions (for monitoring / dashboards).
 * @param {Database} db
 * @returns {number}
 */
export function countSuppressions(db) {
  const row = db.prepare('SELECT COUNT(*) as count FROM suppression_list').get();
  return row.count;
}

// ── Sync helper for child projects ──────────────────────────────────────────

/**
 * Check the suppression list before sending outreach.
 *
 * This is the function each project should call in its outreach pipeline.
 * It checks both email and phone, returning a clear block/allow result.
 *
 * @param {Object} opts
 * @param {string|null} opts.email
 * @param {string|null} opts.phone
 * @param {Database} db
 * @returns {{ blocked: boolean, reason?: string, matchedOn?: string }}
 */
export function checkBeforeSend({ email, phone }, db) {
  const normEmail = normaliseEmail(email);
  const normPhone = normalisePhone(phone);

  if (!normEmail && !normPhone) {
    return { blocked: false };
  }

  // Check email first
  if (normEmail) {
    const row = db.prepare(
      'SELECT source, reason FROM suppression_list WHERE email = ? COLLATE NOCASE LIMIT 1'
    ).get(normEmail);

    if (row) {
      return {
        blocked: true,
        reason: `suppressed_${row.reason || 'opt_out'}`,
        matchedOn: 'email',
      };
    }
  }

  // Check phone
  if (normPhone) {
    const row = db.prepare(
      'SELECT source, reason FROM suppression_list WHERE phone = ? LIMIT 1'
    ).get(normPhone);

    if (row) {
      return {
        blocked: true,
        reason: `suppressed_${row.reason || 'opt_out'}`,
        matchedOn: 'phone',
      };
    }
  }

  return { blocked: false };
}

// ── Batch operations ────────────────────────────────────────────────────────

/**
 * Import multiple suppressions at once (e.g. from a CSV or initial migration).
 *
 * Uses a transaction for atomicity and performance.
 *
 * @param {Array<Object>} entries - Array of { email, phone, source, reason } objects
 * @param {Database} db
 * @returns {{ imported: number, merged: number, errors: number }}
 */
export function batchImport(entries, db) {
  let imported = 0;
  let merged = 0;
  let errors = 0;

  const runBatch = db.transaction((items) => {
    for (const entry of items) {
      try {
        const result = addSuppression(entry, db);
        if (result.merged) {
          merged++;
        } else {
          imported++;
        }
      } catch (err) {
        errors++;
      }
    }
  });

  runBatch(entries);

  return { imported, merged, errors };
}

// ── Default export ──────────────────────────────────────────────────────────

export default {
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
};
