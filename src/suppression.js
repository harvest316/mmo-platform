/**
 * Cross-project opt-out suppression system
 *
 * Centralised suppression list for all mmo-platform projects (333Method, 2Step, etc.).
 * When a prospect opts out in any project, they are suppressed across ALL projects.
 *
 * Table: msgs.suppression_list (PostgreSQL)
 * Both 333Method and 2Step include msgs in their search_path, so unqualified
 * queries resolve correctly.
 *
 * Integration:
 *   - 333Method: src/outreach/email.js, src/outreach/sms.js call checkBeforeSend()
 *   - 2Step: src/stages/outreach.js calls checkBeforeSend()
 *   - Both call addSuppression() when an opt-out is received
 *
 * Migration: Converted from SQLite (suppression.db) to PG on 2026-03-28 (DR-106).
 */

import pg from 'pg';

// ── PG connection ──────────────────────────────────────────────────────────

let _pool = null;

/**
 * Get or create the PG pool. Uses the same DATABASE_URL as the calling project.
 * For tests, call setPool() to inject a mock.
 */
function getPool() {
  if (!_pool) {
    const connectionString = process.env.DATABASE_URL || 'postgresql://jason@/mmo?host=/run/postgresql';
    _pool = new pg.Pool({
      connectionString,
      max: 3, // suppression checks are lightweight
      idleTimeoutMillis: 60_000,
    });
    _pool.on('connect', async (client) => {
      try {
        await client.query("SET search_path TO msgs, public");
      } catch (_) { /* */ }
    });
    _pool.on('error', () => { /* prevent crash on idle error */ });
  }
  return _pool;
}

/**
 * Inject a pool (for testing or when the caller already has one).
 */
export function setPool(pool) {
  _pool = pool;
}

/**
 * Legacy compatibility — callers that did openDb() + close().
 * Returns a lightweight handle with a no-op close().
 */
export function openDb() {
  return { close() {} };
}

// ── Normalisation helpers ───────────────────────────────────────────────────

export function normaliseEmail(email) {
  if (!email || typeof email !== 'string') return null;
  const trimmed = email.trim().toLowerCase();
  return trimmed.length > 0 ? trimmed : null;
}

export function normalisePhone(phone) {
  if (!phone || typeof phone !== 'string') return null;
  const trimmed = phone.replace(/[\s()-]/g, '');
  return trimmed.length > 0 ? trimmed : null;
}

// ── Core API ────────────────────────────────────────────────────────────────

/**
 * Add a suppression entry. Deduplicates on email and phone independently.
 * The `db` parameter is accepted for backward compatibility but ignored.
 */
export async function addSuppression({ email, phone, source, reason, opted_out_at }, _db) {
  const normEmail = normaliseEmail(email);
  const normPhone = normalisePhone(phone);

  if (!normEmail && !normPhone) {
    throw new Error('addSuppression requires at least one of email or phone');
  }
  if (!source || typeof source !== 'string') {
    throw new Error('addSuppression requires a source string');
  }

  const ts = opted_out_at || new Date().toISOString();
  const pool = getPool();

  // Check for existing row by email or phone
  const existing = await findExistingRow(normEmail, normPhone, pool);

  if (existing) {
    const mergedEmail = existing.email || normEmail;
    const mergedPhone = existing.phone || normPhone;

    // Consolidate conflicting rows
    if (normPhone && !existing.phone) {
      await pool.query('DELETE FROM suppression_list WHERE phone = $1 AND id != $2', [normPhone, existing.id]);
    }
    if (normEmail && !existing.email) {
      await pool.query('DELETE FROM suppression_list WHERE LOWER(email) = LOWER($1) AND id != $2', [normEmail, existing.id]);
    }

    await pool.query(
      `UPDATE suppression_list
       SET email = $1, phone = $2, source = $3, reason = $4,
           opted_out_at = $5, updated_at = NOW()
       WHERE id = $6`,
      [mergedEmail, mergedPhone, source, reason ?? existing.reason, ts, existing.id]
    );
    return { id: existing.id, merged: true };
  }

  // New row
  const result = await pool.query(
    `INSERT INTO suppression_list (email, phone, source, reason, opted_out_at)
     VALUES ($1, $2, $3, $4, $5) RETURNING id`,
    [normEmail, normPhone, source, reason ?? null, ts]
  );
  return { id: result.rows[0].id, merged: false };
}

async function findExistingRow(email, phone, pool) {
  if (email) {
    const r = await pool.query('SELECT * FROM suppression_list WHERE LOWER(email) = LOWER($1) LIMIT 1', [email]);
    if (r.rows[0]) return r.rows[0];
  }
  if (phone) {
    const r = await pool.query('SELECT * FROM suppression_list WHERE phone = $1 LIMIT 1', [phone]);
    if (r.rows[0]) return r.rows[0];
  }
  return null;
}

/**
 * Check if an identifier (email or phone) is suppressed.
 * The `db` parameter is accepted for backward compatibility but ignored.
 */
export async function isSuppressed(identifier, _db) {
  if (!identifier || typeof identifier !== 'string') return false;
  const trimmed = identifier.trim();
  if (trimmed.length === 0) return false;

  const pool = getPool();
  const looksLikePhone = /^\+?\d[\d\s()-]*$/.test(trimmed);

  if (looksLikePhone) {
    const norm = normalisePhone(trimmed);
    const r = await pool.query('SELECT 1 FROM suppression_list WHERE phone = $1 LIMIT 1', [norm]);
    return r.rows.length > 0;
  }

  const norm = normaliseEmail(trimmed);
  const r = await pool.query('SELECT 1 FROM suppression_list WHERE LOWER(email) = LOWER($1) LIMIT 1', [norm]);
  return r.rows.length > 0;
}

export async function isEmailSuppressed(email, _db) {
  const norm = normaliseEmail(email);
  if (!norm) return false;
  const r = await getPool().query('SELECT 1 FROM suppression_list WHERE LOWER(email) = LOWER($1) LIMIT 1', [norm]);
  return r.rows.length > 0;
}

export async function isPhoneSuppressed(phone, _db) {
  const norm = normalisePhone(phone);
  if (!norm) return false;
  const r = await getPool().query('SELECT 1 FROM suppression_list WHERE phone = $1 LIMIT 1', [norm]);
  return r.rows.length > 0;
}

/**
 * Check suppression before sending outreach.
 * The `db` parameter is accepted for backward compatibility but ignored.
 */
export async function checkBeforeSend({ email, phone }, _db) {
  const normEmail = normaliseEmail(email);
  const normPhone = normalisePhone(phone);
  if (!normEmail && !normPhone) return { blocked: false };

  const pool = getPool();

  if (normEmail) {
    const r = await pool.query(
      'SELECT source, reason FROM suppression_list WHERE LOWER(email) = LOWER($1) LIMIT 1',
      [normEmail]
    );
    if (r.rows[0]) {
      return { blocked: true, reason: `suppressed_${r.rows[0].reason || 'opt_out'}`, matchedOn: 'email' };
    }
  }

  if (normPhone) {
    const r = await pool.query(
      'SELECT source, reason FROM suppression_list WHERE phone = $1 LIMIT 1',
      [normPhone]
    );
    if (r.rows[0]) {
      return { blocked: true, reason: `suppressed_${r.rows[0].reason || 'opt_out'}`, matchedOn: 'phone' };
    }
  }

  return { blocked: false };
}

export async function getSuppressionsAfter(timestamp, _db) {
  if (!timestamp || typeof timestamp !== 'string') {
    throw new Error('getSuppressionsAfter requires an ISO timestamp string');
  }
  const r = await getPool().query(
    `SELECT id, email, phone, source, opted_out_at, reason, created_at, updated_at
     FROM suppression_list WHERE opted_out_at > $1 ORDER BY opted_out_at ASC`,
    [timestamp]
  );
  return r.rows;
}

export async function getAllSuppressions(_db) {
  const r = await getPool().query(
    'SELECT id, email, phone, source, opted_out_at, reason, created_at, updated_at FROM suppression_list ORDER BY opted_out_at ASC'
  );
  return r.rows;
}

export async function removeSuppression(identifier, _db) {
  if (!identifier || typeof identifier !== 'string') return false;
  const trimmed = identifier.trim();
  if (trimmed.length === 0) return false;

  const pool = getPool();
  const looksLikePhone = /^\+?\d[\d\s()-]*$/.test(trimmed);

  if (looksLikePhone) {
    const norm = normalisePhone(trimmed);
    const r = await pool.query('DELETE FROM suppression_list WHERE phone = $1', [norm]);
    return r.rowCount > 0;
  }

  const norm = normaliseEmail(trimmed);
  const r = await pool.query('DELETE FROM suppression_list WHERE LOWER(email) = LOWER($1)', [norm]);
  return r.rowCount > 0;
}

export async function countSuppressions(_db) {
  const r = await getPool().query('SELECT COUNT(*) as count FROM suppression_list');
  return Number(r.rows[0].count);
}

export async function batchImport(entries, _db) {
  let imported = 0;
  let merged = 0;
  let errors = 0;

  for (const entry of entries) {
    try {
      const result = await addSuppression(entry);
      if (result.merged) merged++;
      else imported++;
    } catch (_) {
      errors++;
    }
  }

  return { imported, merged, errors };
}

export default {
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
};
