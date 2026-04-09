/**
 * Unit tests for the inbound email forwarder (Phase 5.7).
 *
 * Tests shouldDrop, getForwardDestination, and buildWrapAndNotifyPayload.
 * All three are pure functions — no AWS, no CF runtime, no network.
 */

import { describe, it, expect } from 'vitest';
import {
  shouldDrop,
  getForwardDestination,
  buildWrapAndNotifyPayload,
} from '../../../333Method/workers/email-webhook/src/forwarder.js';

// ── shouldDrop (RF-8 pre-filter) ─────────────────────────────────────────────

describe('shouldDrop', () => {
  it('passes a normal reply', () => {
    expect(shouldDrop({
      from: 'customer@gmail.com',
      headers: [
        { name: 'Content-Type', value: 'text/plain' },
      ],
    })).toBeNull();
  });

  it('drops MAILER-DAEMON sender', () => {
    expect(shouldDrop({ from: 'MAILER-DAEMON@mailserver.net', headers: [] }))
      .toMatch(/mailer-daemon/i);
  });

  it('drops postmaster sender', () => {
    expect(shouldDrop({ from: 'postmaster@example.com', headers: [] }))
      .toMatch(/mailer-daemon/i);
  });

  it('drops noreply sender', () => {
    expect(shouldDrop({ from: 'noreply@service.com', headers: [] }))
      .toMatch(/mailer-daemon/i);
  });

  it('drops no-reply sender', () => {
    expect(shouldDrop({ from: 'no-reply@newsletter.io', headers: [] }))
      .toMatch(/mailer-daemon/i);
  });

  it('drops Auto-Submitted: auto-replied', () => {
    expect(shouldDrop({
      from: 'person@gmail.com',
      headers: [{ name: 'Auto-Submitted', value: 'auto-replied' }],
    })).toMatch(/Auto-Submitted/);
  });

  it('does NOT drop Auto-Submitted: no (manual reply)', () => {
    expect(shouldDrop({
      from: 'person@gmail.com',
      headers: [{ name: 'Auto-Submitted', value: 'no' }],
    })).toBeNull();
  });

  it('drops Precedence: bulk', () => {
    expect(shouldDrop({
      from: 'newsletter@example.com',
      headers: [{ name: 'Precedence', value: 'bulk' }],
    })).toMatch(/Precedence/);
  });

  it('drops Precedence: list', () => {
    expect(shouldDrop({
      from: 'list@groups.io',
      headers: [{ name: 'Precedence', value: 'list' }],
    })).toMatch(/Precedence/);
  });

  it('drops when List-Id header is present', () => {
    expect(shouldDrop({
      from: 'list@groups.io',
      headers: [{ name: 'List-Id', value: '<my-list.groups.io>' }],
    })).toMatch(/mailing-list/);
  });

  it('drops when List-Unsubscribe header is present', () => {
    expect(shouldDrop({
      from: 'newsletter@company.com',
      headers: [{ name: 'List-Unsubscribe', value: '<mailto:unsub@company.com>' }],
    })).toMatch(/mailing-list/);
  });

  it('drops when X-Forwarded-By header is present (loop guard)', () => {
    expect(shouldDrop({
      from: 'customer@gmail.com',
      headers: [{ name: 'X-Forwarded-By', value: 'email-webhook-worker' }],
    })).toMatch(/X-Forwarded-By/);
  });

  it('drops self-loop from our own domain', () => {
    expect(shouldDrop({ from: 'marcus@auditandfix.app', headers: [] }))
      .toMatch(/self-loop/);
    expect(shouldDrop({ from: 'marcus@auditandfix.com', headers: [] }))
      .toMatch(/self-loop/);
    expect(shouldDrop({ from: 'status@auditandfix.net', headers: [] }))
      .toMatch(/self-loop/);
  });

  it('drops when Received header chain depth ≥ 8', () => {
    const headers = Array.from({ length: 8 }, (_, i) => ({
      name: 'Received',
      value: `from mail${i}.example.com`,
    }));
    expect(shouldDrop({ from: 'customer@gmail.com', headers }))
      .toMatch(/Received header chain/);
  });

  it('allows Received chain depth of 7', () => {
    const headers = Array.from({ length: 7 }, (_, i) => ({
      name: 'Received',
      value: `from mail${i}.example.com`,
    }));
    expect(shouldDrop({ from: 'customer@gmail.com', headers })).toBeNull();
  });

  it('handles missing headers array gracefully', () => {
    expect(shouldDrop({ from: 'customer@gmail.com' })).toBeNull();
  });
});

// ── getForwardDestination ────────────────────────────────────────────────────

describe('getForwardDestination', () => {
  const env = {
    FORWARD_TO_MARCUS_APP:  'jason@personal.com',
    FORWARD_TO_NET_STATUS:  'jason@personal.com',
    FORWARD_TO_CRAI_MARCUS: 'gary@example.com',
  };

  it('returns destination for marcus@auditandfix.app', () => {
    expect(getForwardDestination('marcus@auditandfix.app', env))
      .toBe('jason@personal.com');
  });

  it('returns destination for status@auditandfix.net', () => {
    expect(getForwardDestination('status@auditandfix.net', env))
      .toBe('jason@personal.com');
  });

  it('returns destination for marcus@contactreply.app', () => {
    expect(getForwardDestination('marcus@contactreply.app', env))
      .toBe('gary@example.com');
  });

  it('returns null for marcus@auditandfix.com (handled by reply processor)', () => {
    expect(getForwardDestination('marcus@auditandfix.com', env)).toBeNull();
  });

  it('returns null for unknown address', () => {
    expect(getForwardDestination('unknown@somewhere.com', env)).toBeNull();
  });

  it('is case-insensitive on the To address', () => {
    expect(getForwardDestination('Marcus@Auditandfix.App', env))
      .toBe('jason@personal.com');
  });

  it('returns null when forward secret is not configured', () => {
    expect(getForwardDestination('marcus@auditandfix.app', {})).toBeNull();
  });
});

// ── buildWrapAndNotifyPayload ────────────────────────────────────────────────

describe('buildWrapAndNotifyPayload', () => {
  const base = {
    from: 'customer@gmail.com',
    to: 'marcus@auditandfix.app',
    subject: 'Question about my report',
    messageId: '<abc123@gmail.com>',
    forwardTo: 'jason@personal.com',
  };

  it('prefixes subject with [Reply]', () => {
    const { subject } = buildWrapAndNotifyPayload(base);
    expect(subject).toBe('[Reply] Question about my report');
  });

  it('includes the original From in the body', () => {
    const { body } = buildWrapAndNotifyPayload(base);
    expect(body).toContain('customer@gmail.com');
  });

  it('includes the inbox that received the reply', () => {
    const { body } = buildWrapAndNotifyPayload(base);
    expect(body).toContain('marcus@auditandfix.app');
  });

  it('includes the original Message-ID', () => {
    const { body } = buildWrapAndNotifyPayload(base);
    expect(body).toContain('<abc123@gmail.com>');
  });

  it('handles missing subject gracefully', () => {
    const { subject, body } = buildWrapAndNotifyPayload({ ...base, subject: '' });
    expect(subject).toBe('[Reply] (no subject)');
    expect(body).toContain('(no subject)');
  });

  it('handles missing messageId gracefully', () => {
    const { body } = buildWrapAndNotifyPayload({ ...base, messageId: null });
    expect(body).toContain('(unknown)');
  });
});
