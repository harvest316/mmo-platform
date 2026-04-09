/**
 * Unit tests for the pure helpers in 333Method/src/cron/canary-magic-link.js
 * (Phase 6.6b, RF-12)
 *
 * Only stateless, pure functions are tested here.
 * HTTP calls, DB writes, and email sends all rely on external systems and
 * are exercised in integration.
 */

import { describe, it, expect } from 'vitest';
import {
  buildAlertEmail,
  shouldAlert,
  parseHarnessToken,
  canRunFullCanary,
} from '../../../333Method/src/cron/canary-magic-link.js';

// ── shouldAlert ───────────────────────────────────────────────────────────────

describe('shouldAlert', () => {
  it('returns false for 0 failures', () => {
    expect(shouldAlert(0)).toBe(false);
  });

  it('returns false for 1 failure (below threshold)', () => {
    expect(shouldAlert(1)).toBe(false);
  });

  it('returns true at exactly the threshold (2)', () => {
    expect(shouldAlert(2)).toBe(true);
  });

  it('returns false at 3 failures (above threshold, not a multiple)', () => {
    expect(shouldAlert(3)).toBe(false);
  });

  it('returns true at double the threshold (4)', () => {
    expect(shouldAlert(4)).toBe(true);
  });

  it('returns true at triple the threshold (6)', () => {
    expect(shouldAlert(6)).toBe(true);
  });

  it('honours a custom threshold', () => {
    expect(shouldAlert(5, 5)).toBe(true);
    expect(shouldAlert(3, 5)).toBe(false);
    expect(shouldAlert(10, 5)).toBe(true);
  });
});

// ── parseHarnessToken ─────────────────────────────────────────────────────────

describe('parseHarnessToken', () => {
  it('returns the token from a valid JSON response', () => {
    const body = JSON.stringify({ token: 'abc123xyz', email: 'test@example.com' });
    expect(parseHarnessToken(body)).toBe('abc123xyz');
  });

  it('returns null for an empty token string', () => {
    expect(parseHarnessToken(JSON.stringify({ token: '' }))).toBeNull();
  });

  it('returns null if token key is missing', () => {
    expect(parseHarnessToken(JSON.stringify({ success: true }))).toBeNull();
  });

  it('returns null for invalid JSON', () => {
    expect(parseHarnessToken('not json')).toBeNull();
  });

  it('returns null for an empty body', () => {
    expect(parseHarnessToken('')).toBeNull();
  });

  it('returns null when token is a non-string type', () => {
    expect(parseHarnessToken(JSON.stringify({ token: 12345 }))).toBeNull();
  });
});

// ── canRunFullCanary ──────────────────────────────────────────────────────────

describe('canRunFullCanary', () => {
  it('returns false when e2eSecret is not set', () => {
    expect(canRunFullCanary({ e2eSecret: undefined, canaryEmail: 'test+canary@auditandfix.com' })).toBe(false);
  });

  it('returns false when e2eSecret is empty string', () => {
    expect(canRunFullCanary({ e2eSecret: '', canaryEmail: 'test+canary@auditandfix.com' })).toBe(false);
  });

  it('returns false when canaryEmail is not set', () => {
    expect(canRunFullCanary({ e2eSecret: 'secret123', canaryEmail: '' })).toBe(false);
  });

  it('returns false when canaryEmail is undefined', () => {
    expect(canRunFullCanary({ e2eSecret: 'secret123', canaryEmail: undefined })).toBe(false);
  });

  it('returns true when both e2eSecret and canaryEmail are set', () => {
    expect(canRunFullCanary({ e2eSecret: 'secret123', canaryEmail: 'test+canary@auditandfix.com' })).toBe(true);
  });
});

// ── buildAlertEmail ───────────────────────────────────────────────────────────

describe('buildAlertEmail', () => {
  const base = {
    consecutiveFailures: 2,
    lastStep: 'send',
    error: 'HTTP 500: Internal Server Error',
    portalUrl: 'https://auditandfix.app',
    alertTo: 'jason@example.com',
  };

  it('sets kind to transactional', () => {
    const email = buildAlertEmail(base);
    expect(email.kind).toBe('transactional');
  });

  it('sends to the alertTo address', () => {
    const email = buildAlertEmail(base);
    expect(email.to).toBe('jason@example.com');
  });

  it('sends from marcus@auditandfix.app', () => {
    const email = buildAlertEmail(base);
    expect(email.from).toContain('auditandfix.app');
  });

  it('includes the failure count in the subject', () => {
    const email = buildAlertEmail(base);
    expect(email.subject).toContain('2');
    expect(email.subject.toLowerCase()).toContain('canary');
  });

  it('includes the failed step in the body', () => {
    const email = buildAlertEmail(base);
    expect(email.text).toContain('send');
  });

  it('includes the error message in the body', () => {
    const email = buildAlertEmail(base);
    expect(email.text).toContain('HTTP 500');
  });

  it('includes the portal URL in the body', () => {
    const email = buildAlertEmail(base);
    expect(email.text).toContain('https://auditandfix.app');
  });

  it('mentions the failure count in the body text', () => {
    const email = buildAlertEmail({ ...base, consecutiveFailures: 4 });
    expect(email.text).toContain('4');
  });

  it('has a non-empty text body', () => {
    const email = buildAlertEmail(base);
    expect(email.text.length).toBeGreaterThan(50);
  });
});
