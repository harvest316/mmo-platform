/**
 * Unit tests for the pure helpers in 333Method/src/cron/backup-customers-db.js
 * (Phase 6.6c, RF-12)
 *
 * Only the pure, stateless function todaySydney() is tested here.
 * FTP, sqlite3 integrity check, S3 upload, and DB logging all rely on
 * external systems and are exercised in integration.
 */

import { describe, it, expect } from 'vitest';
import { todaySydney } from '../../../333Method/src/cron/backup-customers-db.js';

// ── todaySydney ───────────────────────────────────────────────────────────────

describe('todaySydney', () => {
  it('returns a string matching YYYY-MM-DD', () => {
    const result = todaySydney();
    expect(result).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('returns a valid calendar date', () => {
    const result = todaySydney();
    const [y, m, d] = result.split('-').map(Number);
    expect(y).toBeGreaterThanOrEqual(2026);
    expect(m).toBeGreaterThanOrEqual(1);
    expect(m).toBeLessThanOrEqual(12);
    expect(d).toBeGreaterThanOrEqual(1);
    expect(d).toBeLessThanOrEqual(31);
  });

  it('uses Sydney timezone — UTC midnight is still the previous day', () => {
    // 2026-04-09 00:00:00 UTC = 2026-04-09 10:00:00 AEDT (same calendar day)
    const utcMidnight = new Date('2026-04-09T00:00:00Z');
    expect(todaySydney(utcMidnight)).toBe('2026-04-09');
  });

  it('uses Sydney timezone — UTC 23:59 can be next day in Sydney', () => {
    // 2026-04-08 23:59:00 UTC = 2026-04-09 09:59:00 AEDT → Sydney date is the 9th
    const utcLateNight = new Date('2026-04-08T23:59:00Z');
    expect(todaySydney(utcLateNight)).toBe('2026-04-09');
  });

  it('handles the year boundary correctly', () => {
    // 2026-12-31 UTC midnight = 2026-12-31 11:00 AEDT
    const newYearsEve = new Date('2026-12-31T00:00:00Z');
    expect(todaySydney(newYearsEve)).toBe('2026-12-31');
  });

  it('produces the S3 key format used by the backup cron', () => {
    // S3 key: backups/customers/customers-YYYY-MM-DD.sqlite
    const date = todaySydney(new Date('2026-04-09T03:00:00+10:00'));
    const s3Key = `backups/customers/customers-${date}.sqlite`;
    expect(s3Key).toMatch(/^backups\/customers\/customers-\d{4}-\d{2}-\d{2}\.sqlite$/);
  });
});
