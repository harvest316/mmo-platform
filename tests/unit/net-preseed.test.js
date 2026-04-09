/**
 * Unit tests for the pure helpers in 333Method/src/cron/send-net-preseed.js
 *
 * Only the pure, stateless functions are tested here:
 *   isoWeekNumber   — week number derivation
 *   buildEmailContent — email body/subject rotation
 *
 * DB, send, and day-of-week logic are not unit-tested here —
 * they rely on actual PG + SES and are exercised in integration.
 */

import { describe, it, expect } from 'vitest';
import {
  isoWeekNumber,
  buildEmailContent,
} from '../../../333Method/src/cron/send-net-preseed.js';

// ── isoWeekNumber ────────────────────────────────────────────────────────────

describe('isoWeekNumber', () => {
  it('week 1 of 2026 starts on 29 Dec 2025 (ISO)', () => {
    // ISO week 1 is the week containing 4 Jan
    expect(isoWeekNumber('2026-01-01')).toBe(1);
    expect(isoWeekNumber('2026-01-04')).toBe(1);
  });

  it('returns 14 for 2026-04-02 (a Thursday)', () => {
    // 2026-04-02 is in ISO week 14
    expect(isoWeekNumber('2026-04-02')).toBe(14);
  });

  it('returns 15 for 2026-04-07 (a Tuesday)', () => {
    expect(isoWeekNumber('2026-04-07')).toBe(15);
  });

  it('returns 53 or 1 for the last days of some years', () => {
    // 2020-12-31 is in ISO week 53
    expect(isoWeekNumber('2020-12-31')).toBe(53);
  });

  it('returns values in the range 1–53', () => {
    for (let month = 1; month <= 12; month++) {
      const isoDate = `2026-${String(month).padStart(2, '0')}-15`;
      const wn = isoWeekNumber(isoDate);
      expect(wn).toBeGreaterThanOrEqual(1);
      expect(wn).toBeLessThanOrEqual(53);
    }
  });
});

// ── buildEmailContent ────────────────────────────────────────────────────────

describe('buildEmailContent', () => {
  it('returns text and html', () => {
    const { text, html } = buildEmailContent('2026-04-08');
    expect(typeof text).toBe('string');
    expect(typeof html).toBe('string');
    expect(text.length).toBeGreaterThan(50);
    expect(html).toContain('<!DOCTYPE html>');
  });

  it('html contains the text content', () => {
    const { text, html } = buildEmailContent('2026-04-08');
    // First line of body text should appear somewhere in the html (minus any html-escaping)
    const firstLine = text.split('\n')[0];
    expect(html).toContain(firstLine);
  });

  it('varies body text across the 4 week variants', () => {
    // Collect distinct texts across 4 consecutive weeks
    const texts = new Set();
    // Week offsets: pick dates known to be in different weeks
    const dates = ['2026-04-07', '2026-04-14', '2026-04-21', '2026-04-28'];
    for (const d of dates) {
      texts.add(buildEmailContent(d).text);
    }
    // All four weeks should produce distinct content
    expect(texts.size).toBe(4);
  });

  it('cycles back after 4 weeks', () => {
    // Week N and week N+4 should produce the same text
    const a = buildEmailContent('2026-04-07');  // week 15
    const b = buildEmailContent('2026-05-05');  // week 19 (15 + 4)
    expect(a.text).toBe(b.text);
  });

  it('never contains business metrics keywords', () => {
    // RF-23: no business data leaking to external inboxes
    const problematic = [
      'outreach sent this week',
      'reports delivered',
      'bounce rate',
      'complaint rate',
      'deliverability tier',
    ];
    for (let week = 0; week < 4; week++) {
      const date = `2026-0${4 + week}-07`;
      const { text } = buildEmailContent(`2026-0${week + 1}-15`);
      for (const phrase of problematic) {
        expect(text.toLowerCase()).not.toContain(phrase);
      }
    }
  });

  it('includes reply/unsubscribe instruction in every variant', () => {
    const dates = ['2026-04-07', '2026-04-14', '2026-04-21', '2026-04-28'];
    for (const d of dates) {
      const { text } = buildEmailContent(d);
      const lower = text.toLowerCase();
      // Each variant must mention how to opt out
      const hasOptOut = lower.includes('stop') || lower.includes('unsubscribe') || lower.includes('removed');
      expect(hasOptOut, `variant for ${d} missing opt-out instruction`).toBe(true);
    }
  });

  it('html is valid enough — has body and charset', () => {
    const { html } = buildEmailContent('2026-04-09');
    expect(html).toContain('<body');
    expect(html).toContain('charset');
    expect(html).toContain('</html>');
  });
});
