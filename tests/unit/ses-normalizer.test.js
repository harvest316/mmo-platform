/**
 * Unit tests for SES/SNS pure-function helpers in the email-webhook CF Worker.
 *
 * All four exported functions are side-effect-free, so these tests run without
 * any Cloudflare Worker runtime, AWS credentials, or network access.
 */

import { describe, it, expect } from 'vitest';
import {
  normalizeSesEvent,
  normalizeSesReceipt,
  buildSnsCanonicalString,
  isSnsSigningCertUrlValid,
} from '../../../333Method/workers/email-webhook/src/ses-normalizer.js';

// ── normalizeSesEvent ────────────────────────────────────────────────────────

describe('normalizeSesEvent', () => {
  const baseMail = {
    messageId: '<test-message-id@us-east-1.amazonses.com>',
    timestamp: '2026-04-06T00:00:00.000Z',
    tags: {
      campaign_id: ['camp-001'],
      site_id: ['site-999'],
    },
  };

  it('normalises a Delivery event', () => {
    const sesEvent = { eventType: 'Delivery', mail: baseMail };
    const result = normalizeSesEvent(sesEvent);

    expect(result.type).toBe('email.delivered');
    expect(result.source).toBe('ses');
    expect(result.version).toBe(1);
    expect(result.data.email_id).toBe(baseMail.messageId);
    expect(result.data.tags.campaign_id).toBe('camp-001');
    expect(result.data.tags.site_id).toBe('site-999');
    expect(result).toHaveProperty('created_at');
  });

  it('normalises a Permanent Bounce as hard_bounce', () => {
    const sesEvent = {
      eventType: 'Bounce',
      mail: baseMail,
      bounce: { bounceType: 'Permanent', bounceSubType: 'General' },
    };
    const result = normalizeSesEvent(sesEvent);

    expect(result.type).toBe('email.bounced');
    expect(result.data.type).toBe('hard_bounce');
    expect(result.data.email_id).toBe(baseMail.messageId);
    expect(result.source).toBe('ses');
    expect(result.version).toBe(1);
  });

  it('normalises a Transient Bounce as soft_bounce', () => {
    const sesEvent = {
      eventType: 'Bounce',
      mail: baseMail,
      bounce: { bounceType: 'Transient', bounceSubType: 'MailboxFull' },
    };
    const result = normalizeSesEvent(sesEvent);

    expect(result.type).toBe('email.bounced');
    expect(result.data.type).toBe('soft_bounce');
  });

  it('normalises a Complaint event', () => {
    const sesEvent = { eventType: 'Complaint', mail: baseMail };
    const result = normalizeSesEvent(sesEvent);

    expect(result.type).toBe('email.complained');
    expect(result.data.email_id).toBe(baseMail.messageId);
    expect(result.source).toBe('ses');
  });

  it('returns null for Send events (intentionally ignored)', () => {
    const sesEvent = { eventType: 'Send', mail: baseMail };
    expect(normalizeSesEvent(sesEvent)).toBeNull();
  });

  it('returns null for unknown event types', () => {
    const sesEvent = { eventType: 'Open', mail: baseMail };
    expect(normalizeSesEvent(sesEvent)).toBeNull();
  });

  it('flattens SES tag arrays to single string values', () => {
    const sesEvent = {
      eventType: 'Delivery',
      mail: {
        ...baseMail,
        tags: {
          campaign_id: ['camp-001'],      // array — should be flattened
          source: 'ses',                  // already a string — should pass through
        },
      },
    };
    const result = normalizeSesEvent(sesEvent);

    expect(result.data.tags.campaign_id).toBe('camp-001');
    expect(result.data.tags.source).toBe('ses');
  });

  it('handles missing tags gracefully (empty tags object)', () => {
    const sesEvent = {
      eventType: 'Delivery',
      mail: { messageId: 'msg-001' },
    };
    const result = normalizeSesEvent(sesEvent);

    expect(result).not.toBeNull();
    expect(result.data.tags).toEqual({});
  });

  it('includes created_at ISO timestamp', () => {
    const before = Date.now();
    const result = normalizeSesEvent({ eventType: 'Delivery', mail: baseMail });
    const after = Date.now();

    const ts = new Date(result.created_at).getTime();
    expect(ts).toBeGreaterThanOrEqual(before);
    expect(ts).toBeLessThanOrEqual(after);
  });
});

// ── normalizeSesReceipt ──────────────────────────────────────────────────────

describe('normalizeSesReceipt', () => {
  const receiptEvent = {
    mail: {
      messageId: '<inbound-msg-001@inbound.contactreplyai.com>',
      source: 'customer@example.com',
      destination: ['reply@inbound.contactreplyai.com'],
      commonHeaders: { subject: 'Re: Your Audit Report' },
    },
    receipt: {
      action: {
        type: 'S3',
        bucketName: 'auditandfix-ses-inbound',
        objectKey: 'emails/inbound-msg-001',
      },
    },
  };

  it('normalises an inbound receipt notification', () => {
    const result = normalizeSesReceipt(receiptEvent);

    expect(result.type).toBe('email.received');
    expect(result.source).toBe('ses');
    expect(result.version).toBe(1);
    expect(result.data.from).toBe('customer@example.com');
    expect(result.data.to).toBe('reply@inbound.contactreplyai.com');
    expect(result.data.subject).toBe('Re: Your Audit Report');
    expect(result.data.email_id).toBe('<inbound-msg-001@inbound.contactreplyai.com>');
    expect(result.data.s3_bucket).toBe('auditandfix-ses-inbound');
    expect(result.data.s3_key).toBe('emails/inbound-msg-001');
    expect(result).toHaveProperty('created_at');
  });

  it('uses first destination address for the "to" field', () => {
    const event = {
      ...receiptEvent,
      mail: {
        ...receiptEvent.mail,
        destination: ['primary@inbound.contactreplyai.com', 'cc@example.com'],
      },
    };
    const result = normalizeSesReceipt(event);
    expect(result.data.to).toBe('primary@inbound.contactreplyai.com');
  });

  it('falls back to empty string when subject is absent', () => {
    const event = {
      ...receiptEvent,
      mail: { ...receiptEvent.mail, commonHeaders: {} },
    };
    const result = normalizeSesReceipt(event);
    expect(result.data.subject).toBe('');
  });

  it('passes S3 bucket and key through correctly', () => {
    const result = normalizeSesReceipt(receiptEvent);
    expect(result.data.s3_bucket).toBe('auditandfix-ses-inbound');
    expect(result.data.s3_key).toBe('emails/inbound-msg-001');
  });

  it('includes created_at ISO timestamp', () => {
    const before = Date.now();
    const result = normalizeSesReceipt(receiptEvent);
    const after = Date.now();

    const ts = new Date(result.created_at).getTime();
    expect(ts).toBeGreaterThanOrEqual(before);
    expect(ts).toBeLessThanOrEqual(after);
  });
});

// ── buildSnsCanonicalString ──────────────────────────────────────────────────

describe('buildSnsCanonicalString', () => {
  it('builds canonical string for Notification type', () => {
    const msg = {
      Type: 'Notification',
      Message: '{"eventType":"Delivery"}',
      MessageId: 'abc-123',
      Timestamp: '2026-04-06T00:00:00.000Z',
      TopicArn: 'arn:aws:sns:ap-southeast-2:575751781585:auditandfix',
    };

    const canonical = buildSnsCanonicalString(msg);

    expect(canonical).toContain('Message\n{"eventType":"Delivery"}\n');
    expect(canonical).toContain('MessageId\nabc-123\n');
    expect(canonical).toContain('Timestamp\n2026-04-06T00:00:00.000Z\n');
    expect(canonical).toContain('TopicArn\narn:aws:sns:ap-southeast-2:575751781585:auditandfix\n');
    expect(canonical).toContain('Type\nNotification\n');
  });

  it('includes Subject in Notification canonical string when present', () => {
    const msg = {
      Type: 'Notification',
      Message: 'body',
      MessageId: 'abc-123',
      Subject: 'Test Subject',
      Timestamp: '2026-04-06T00:00:00.000Z',
      TopicArn: 'arn:aws:sns:...',
    };

    const canonical = buildSnsCanonicalString(msg);
    expect(canonical).toContain('Subject\nTest Subject\n');
  });

  it('omits Subject from Notification canonical string when null', () => {
    const msg = {
      Type: 'Notification',
      Message: 'body',
      MessageId: 'abc-123',
      Subject: null,
      Timestamp: '2026-04-06T00:00:00.000Z',
      TopicArn: 'arn:aws:sns:...',
    };

    const canonical = buildSnsCanonicalString(msg);
    expect(canonical).not.toContain('Subject\n');
  });

  it('omits Subject from Notification canonical string when undefined', () => {
    const msg = {
      Type: 'Notification',
      Message: 'body',
      MessageId: 'abc-123',
      Timestamp: '2026-04-06T00:00:00.000Z',
      TopicArn: 'arn:aws:sns:...',
    };

    const canonical = buildSnsCanonicalString(msg);
    expect(canonical).not.toContain('Subject\n');
  });

  it('builds canonical string for SubscriptionConfirmation type', () => {
    const msg = {
      Type: 'SubscriptionConfirmation',
      Message: 'You have chosen to subscribe to the topic.',
      MessageId: 'conf-456',
      SubscribeURL: 'https://sns.ap-southeast-2.amazonaws.com/confirm?token=abc',
      Timestamp: '2026-04-06T00:00:00.000Z',
      Token: 'abc-token',
      TopicArn: 'arn:aws:sns:ap-southeast-2:575751781585:auditandfix',
    };

    const canonical = buildSnsCanonicalString(msg);

    expect(canonical).toContain('Message\nYou have chosen to subscribe to the topic.\n');
    expect(canonical).toContain('MessageId\nconf-456\n');
    expect(canonical).toContain('SubscribeURL\nhttps://sns.ap-southeast-2.amazonaws.com/confirm?token=abc\n');
    expect(canonical).toContain('Token\nabc-token\n');
    expect(canonical).toContain('TopicArn\narn:aws:sns:ap-southeast-2:575751781585:auditandfix\n');
    expect(canonical).toContain('Type\nSubscriptionConfirmation\n');
    // SubscriptionConfirmation must NOT include Subject
    expect(canonical).not.toContain('Subject\n');
  });

  it('builds canonical string for UnsubscribeConfirmation type', () => {
    const msg = {
      Type: 'UnsubscribeConfirmation',
      Message: 'Unsubscribe message',
      MessageId: 'unsub-789',
      SubscribeURL: 'https://sns.ap-southeast-2.amazonaws.com/resubscribe?token=xyz',
      Timestamp: '2026-04-06T00:00:00.000Z',
      Token: 'xyz-token',
      TopicArn: 'arn:aws:sns:ap-southeast-2:575751781585:auditandfix',
    };

    const canonical = buildSnsCanonicalString(msg);
    expect(canonical).toContain('Type\nUnsubscribeConfirmation\n');
    expect(canonical).toContain('Token\nxyz-token\n');
  });

  it('throws for unknown message Type', () => {
    const msg = { Type: 'InvalidType', MessageId: 'x' };
    expect(() => buildSnsCanonicalString(msg)).toThrow('Unknown SNS message Type: InvalidType');
  });

  it('Notification canonical fields appear in correct order', () => {
    const msg = {
      Type: 'Notification',
      Message: 'M',
      MessageId: 'ID',
      Timestamp: 'TS',
      TopicArn: 'ARN',
    };
    const canonical = buildSnsCanonicalString(msg);
    const messagePos = canonical.indexOf('Message\n');
    const messageIdPos = canonical.indexOf('MessageId\n');
    const timestampPos = canonical.indexOf('Timestamp\n');
    const topicArnPos = canonical.indexOf('TopicArn\n');
    const typePos = canonical.indexOf('Type\n');

    // AWS-spec field order: Message, MessageId, [Subject], Timestamp, TopicArn, Type
    expect(messagePos).toBeLessThan(messageIdPos);
    expect(messageIdPos).toBeLessThan(timestampPos);
    expect(timestampPos).toBeLessThan(topicArnPos);
    expect(topicArnPos).toBeLessThan(typePos);
  });
});

// ── isSnsSigningCertUrlValid ─────────────────────────────────────────────────

describe('isSnsSigningCertUrlValid', () => {
  it('accepts valid AWS SNS cert URLs', () => {
    expect(isSnsSigningCertUrlValid(
      'https://sns.ap-southeast-2.amazonaws.com/SimpleNotificationService-abc123.pem'
    )).toBe(true);

    expect(isSnsSigningCertUrlValid(
      'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-def456.pem'
    )).toBe(true);

    expect(isSnsSigningCertUrlValid(
      'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-ghi789.pem'
    )).toBe(true);
  });

  it('rejects http:// (non-HTTPS)', () => {
    expect(isSnsSigningCertUrlValid(
      'http://sns.us-east-1.amazonaws.com/cert.pem'
    )).toBe(false);
  });

  it('rejects non-SNS AWS domains', () => {
    // s3 bucket — not sns.*
    expect(isSnsSigningCertUrlValid(
      'https://s3.amazonaws.com/attacker-bucket/cert.pem'
    )).toBe(false);

    // sqs — not sns.*
    expect(isSnsSigningCertUrlValid(
      'https://sqs.us-east-1.amazonaws.com/cert.pem'
    )).toBe(false);
  });

  it('rejects SSRF attempts with amazonaws.com in path but wrong hostname', () => {
    expect(isSnsSigningCertUrlValid(
      'https://attacker.com/https://sns.us-east-1.amazonaws.com/cert.pem'
    )).toBe(false);

    expect(isSnsSigningCertUrlValid(
      'https://evil.example.com/sns.us-east-1.amazonaws.com/cert.pem'
    )).toBe(false);
  });

  it('rejects malformed / non-URLs', () => {
    expect(isSnsSigningCertUrlValid('not-a-url')).toBe(false);
    expect(isSnsSigningCertUrlValid('')).toBe(false);
    expect(isSnsSigningCertUrlValid(null)).toBe(false);
    expect(isSnsSigningCertUrlValid(undefined)).toBe(false);
  });

  it('rejects subdomain spoofing (fake subdomain containing sns.)', () => {
    // hostname: sns.us-east-1.amazonaws.com.evil.com — ends with .com not .amazonaws.com
    expect(isSnsSigningCertUrlValid(
      'https://sns.us-east-1.amazonaws.com.evil.com/cert.pem'
    )).toBe(false);
  });

  it('rejects URLs where hostname is only amazonaws.com (no sns. prefix)', () => {
    expect(isSnsSigningCertUrlValid(
      'https://amazonaws.com/cert.pem'
    )).toBe(false);
  });
});
