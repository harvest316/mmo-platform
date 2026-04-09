/**
 * Unit tests for the pure helpers in 333Method/src/cron/draft-ip-rerequest.js
 * (Phase 6.7)
 *
 * Only stateless, pure functions are tested here.
 * DB queries, the date gate, and email sends rely on external systems and
 * are exercised in integration.
 */

import { describe, it, expect } from 'vitest';
import {
  buildDraftEmail,
  todaySydney,
} from '../../../333Method/src/cron/draft-ip-rerequest.js';

// ── todaySydney ───────────────────────────────────────────────────────────────

describe('todaySydney', () => {
  it('returns a string matching YYYY-MM-DD', () => {
    expect(todaySydney()).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('uses Sydney timezone — UTC midnight maps to the Sydney calendar date', () => {
    // 2026-04-22 00:00:00 UTC = 2026-04-22 10:00:00 AEST → same calendar day
    expect(todaySydney(new Date('2026-04-22T00:00:00Z'))).toBe('2026-04-22');
  });

  it('uses Sydney timezone — UTC 23:30 can be the next day in Sydney', () => {
    // 2026-04-21 23:30:00 UTC = 2026-04-22 09:30:00 AEST → Sydney date is the 22nd
    expect(todaySydney(new Date('2026-04-21T23:30:00Z'))).toBe('2026-04-22');
  });
});

// ── buildDraftEmail ───────────────────────────────────────────────────────────

const BASE = {
  today: '2026-04-22',
  avgBounce: '0.412',
  avgComplaint: '0.008',
  peakBounce: '1.100',
  peakComplaint: '0.021',
  snapshotCount: 408,
  totalEmails: 1247,
  avgDaily: 73,
  maxDaily: 201,
  volumeTable: '  2026-04-05: 73\n  2026-04-06: 201\n  2026-04-07: 95',
  preseedSends: 10,
  preseedRecips: 5,
  currentTier: 'normal',
};

describe('buildDraftEmail', () => {
  it('returns a non-empty string', () => {
    const result = buildDraftEmail(BASE);
    expect(typeof result).toBe('string');
    expect(result.length).toBeGreaterThan(200);
  });

  it('includes the generation date in the header', () => {
    const result = buildDraftEmail(BASE);
    expect(result).toContain('2026-04-22');
  });

  it('includes the total email count', () => {
    expect(buildDraftEmail(BASE)).toContain('1247');
  });

  it('includes the average daily count', () => {
    expect(buildDraftEmail(BASE)).toContain('73');
  });

  it('includes the peak daily count', () => {
    expect(buildDraftEmail(BASE)).toContain('201');
  });

  it('includes the volume table', () => {
    const result = buildDraftEmail(BASE);
    expect(result).toContain('2026-04-05: 73');
    expect(result).toContain('2026-04-06: 201');
  });

  it('includes the average bounce rate', () => {
    expect(buildDraftEmail(BASE)).toContain('0.412');
  });

  it('includes the average complaint rate', () => {
    expect(buildDraftEmail(BASE)).toContain('0.008');
  });

  it('includes the peak bounce rate', () => {
    expect(buildDraftEmail(BASE)).toContain('1.100');
  });

  it('includes the current reputation tier', () => {
    expect(buildDraftEmail(BASE)).toContain('normal');
  });

  it('includes the snapshot count', () => {
    expect(buildDraftEmail(BASE)).toContain('408');
  });

  it('includes the pre-seed recipient count', () => {
    expect(buildDraftEmail(BASE)).toContain('5');
  });

  it('includes the pre-seed send count', () => {
    expect(buildDraftEmail(BASE)).toContain('10');
  });

  it('includes the AWS submission instructions', () => {
    const result = buildDraftEmail(BASE);
    expect(result).toContain('AWS Console');
    expect(result.toLowerCase()).toContain('support');
  });

  it('includes the date in the suggested support case subject', () => {
    const result = buildDraftEmail(BASE);
    expect(result).toContain('2026-04-22');
    expect(result.toLowerCase()).toContain('dedicated ip');
  });

  it('includes the fallback instructions for a second rejection', () => {
    const result = buildDraftEmail(BASE);
    expect(result.toLowerCase()).toContain('rejected');
    expect(result.toLowerCase()).toContain('30 days');
  });

  it('uses N/A gracefully when repStats fields are missing', () => {
    const result = buildDraftEmail({ ...BASE, avgBounce: 'N/A', avgComplaint: 'N/A' });
    expect(result).toContain('N/A');
  });

  it('handles zero pre-seed sends without crashing', () => {
    const result = buildDraftEmail({ ...BASE, preseedSends: 0, preseedRecips: 0 });
    expect(result).toContain('0');
  });

  it('handles empty volume table without crashing', () => {
    const result = buildDraftEmail({ ...BASE, volumeTable: '  (no outbound data in window)' });
    expect(result).toContain('no outbound data');
  });

  it('includes all three use case sections', () => {
    const result = buildDraftEmail(BASE);
    expect(result).toContain('auditandfix.com');
    expect(result).toContain('auditandfix.app');
    expect(result).toContain('auditandfix.net');
  });

  it('mentions split-domain isolation as an operational maturity point', () => {
    const result = buildDraftEmail(BASE);
    expect(result.toLowerCase()).toContain('split-domain');
  });

  it('mentions ZeroBounce validation', () => {
    expect(buildDraftEmail(BASE).toLowerCase()).toContain('zerobounce');
  });
});
