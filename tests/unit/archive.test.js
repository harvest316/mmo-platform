/**
 * archive.js — unit tests (DR-222)
 *
 * Strategy:
 *   - @aws-sdk/client-s3 → vi.mock() with constructable stubs; mockSend exposed
 *     at module scope so all tests can inspect calls.
 *   - node:fs  → NOT mocked. A real temp spool dir is used (set via ARCHIVE_SPOOL_DIR
 *     env var), following the email.test.js pattern of real I/O for durability-path code.
 *   - Singleton reset → _resetS3Client() exported test helper + vi.resetModules()
 *     + freshImport() pattern (same as email.test.js).
 */

import { vi, describe, it, expect, beforeEach, afterEach, beforeAll, afterAll } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

// ── S3 SDK mock ────────────────────────────────────────────────────────────

const mockSend = vi.fn();

vi.mock('@aws-sdk/client-s3', () => {
  function S3Client() {
    this.send = mockSend;
  }
  function PutObjectCommand(params) {
    Object.assign(this, params);
  }
  return { S3Client, PutObjectCommand };
});

// ── Spool dir setup ────────────────────────────────────────────────────────

const TEST_SPOOL_DIR = path.join(os.tmpdir(), `mmo-archive-test-${process.pid}`);

beforeAll(() => {
  fs.mkdirSync(path.join(TEST_SPOOL_DIR, 'blobs'), { recursive: true });
});

afterAll(() => {
  fs.rmSync(TEST_SPOOL_DIR, { recursive: true, force: true });
});

// ── Module import helper ───────────────────────────────────────────────────

async function freshImport() {
  vi.resetModules();
  const mod = await import('../../src/archive.js');
  return mod;
}

// ── Env setup ─────────────────────────────────────────────────────────────

const BASE_ENV = {
  ARCHIVE_AWS_ACCESS_KEY_ID: 'test-archive-key',
  ARCHIVE_AWS_SECRET_ACCESS_KEY: 'test-archive-secret',
  ARCHIVE_S3_BUCKET: 'mmo-comms-archive-test',
  ARCHIVE_S3_REGION: 'ap-southeast-2',
  ARCHIVE_SPOOL_DIR: TEST_SPOOL_DIR,
};

beforeEach(() => {
  for (const [k, v] of Object.entries(BASE_ENV)) process.env[k] = v;
  vi.clearAllMocks();
  mockSend.mockResolvedValue({ VersionId: 'test-version-id' });

  // Clean spool between tests
  const spoolFile = path.join(TEST_SPOOL_DIR, 'spool.jsonl');
  if (fs.existsSync(spoolFile)) fs.unlinkSync(spoolFile);
  const blobsDir = path.join(TEST_SPOOL_DIR, 'blobs');
  for (const f of fs.readdirSync(blobsDir)) {
    fs.unlinkSync(path.join(blobsDir, f));
  }
});

afterEach(() => {
  for (const k of Object.keys(BASE_ENV)) delete process.env[k];
});

// ── Helpers ────────────────────────────────────────────────────────────────

function readSpoolEntries() {
  const spoolFile = path.join(TEST_SPOOL_DIR, 'spool.jsonl');
  if (!fs.existsSync(spoolFile)) return [];
  return fs.readFileSync(spoolFile, 'utf8')
    .split('\n')
    .filter(Boolean)
    .map((l) => JSON.parse(l));
}

function readBlob(blobFile) {
  return fs.readFileSync(path.join(TEST_SPOOL_DIR, 'blobs', blobFile));
}

// ── 1. captureOutboundEmail ────────────────────────────────────────────────

describe('captureOutboundEmail', () => {
  it('returns a key with email/outbound/<date> prefix', async () => {
    const { captureOutboundEmail } = await freshImport();
    const key = await captureOutboundEmail({ project: '333method', rawMime: 'MIME content' });
    expect(key).toMatch(/^email\/333method\/outbound\/\d{4}\/\d{2}\/\d{2}\//);
    expect(key).toMatch(/\.eml$/);
  });

  it('writes one spool entry with correct shape', async () => {
    const { captureOutboundEmail } = await freshImport();
    await captureOutboundEmail({
      project: '333method',
      rawMime: 'From: a@b.com\r\nSubject: test\r\n\r\nBody',
      metadata: { campaignTag: 'cold-au', templateId: 't42' },
    });

    const entries = readSpoolEntries();
    expect(entries).toHaveLength(1);
    const e = entries[0];
    expect(e.channel).toBe('email');
    expect(e.project).toBe('333method');
    expect(e.direction).toBe('outbound');
    expect(e.key).toMatch(/^email\/333method\/outbound\//);
    expect(e.key).toMatch(/\.eml$/);
    expect(e.blobFile).toMatch(/^[0-9a-f]{16}\.eml$/);
    expect(e.contentType).toBe('message/rfc822');
    expect(e.s3Uploaded).toBe(false);
    expect(e.spooledAt).toBeTruthy();
    expect(e.metadata.campaignTag).toBe('cold-au');
    expect(e.metadata.templateId).toBe('t42');
    expect(e.metadata.capturedAt).toBeTruthy();
  });

  it('writes the raw MIME bytes to the blob file', async () => {
    const { captureOutboundEmail } = await freshImport();
    const mimeStr = 'From: sender@example.com\r\nSubject: Hello\r\n\r\nBody text';
    await captureOutboundEmail({ project: '333method', rawMime: mimeStr });

    const [entry] = readSpoolEntries();
    const blob = readBlob(entry.blobFile);
    expect(blob.toString('utf8')).toBe(mimeStr);
  });

  it('accepts Buffer rawMime', async () => {
    const { captureOutboundEmail } = await freshImport();
    const mimeBuf = Buffer.from('From: a@b.com\r\n\r\nBody');
    await captureOutboundEmail({ project: '333method', rawMime: mimeBuf });

    const [entry] = readSpoolEntries();
    const blob = readBlob(entry.blobFile);
    expect(blob).toEqual(mimeBuf);
  });

  it('fires S3 PutObjectCommand for the .eml (best-effort)', async () => {
    const { captureOutboundEmail } = await freshImport();
    await captureOutboundEmail({ project: '333method', rawMime: 'MIME' });

    // First call is the .eml, second is the .meta.json sidecar
    const emlCall = mockSend.mock.calls.find(([cmd]) => cmd.Key?.endsWith('.eml'));
    expect(emlCall).toBeTruthy();
    const cmd = emlCall[0];
    expect(cmd.Bucket).toBe('mmo-comms-archive-test');
    expect(cmd.ContentType).toBe('message/rfc822');
    expect(cmd.ServerSideEncryption).toBe('aws:kms');
    expect(cmd.ObjectLockMode).toBe('COMPLIANCE');
    expect(cmd.ObjectLockRetainUntilDate).toBeInstanceOf(Date);
    // Retain-until must be ≥7 years from now
    const sevenYearsMs = 7 * 365 * 24 * 3600 * 1000;
    expect(cmd.ObjectLockRetainUntilDate.getTime()).toBeGreaterThan(Date.now() + sevenYearsMs - 1000);
  });

  it('also fires S3 PutObjectCommand for the .meta.json sidecar', async () => {
    const { captureOutboundEmail } = await freshImport();
    await captureOutboundEmail({ project: '333method', rawMime: 'MIME', metadata: { templateId: 't1' } });

    const metaCall = mockSend.mock.calls.find(([cmd]) => cmd.Key?.endsWith('.meta.json'));
    expect(metaCall).toBeTruthy();
    const cmd = metaCall[0];
    expect(cmd.ContentType).toBe('application/json');
    const meta = JSON.parse(cmd.Body.toString());
    expect(meta.templateId).toBe('t1');
    expect(meta.project).toBe('333method');
    expect(meta.archiveVersion).toBe(1);
  });

  it('S3 PUT failure does not throw (fail-open)', async () => {
    mockSend.mockRejectedValue(new Error('S3 throttled'));
    const { captureOutboundEmail } = await freshImport();
    // Should not throw
    await expect(
      captureOutboundEmail({ project: '333method', rawMime: 'MIME' })
    ).resolves.toMatch(/\.eml$/);
    // Spool entry still written
    expect(readSpoolEntries()).toHaveLength(1);
  });

  it('throws if ARCHIVE_AWS_ACCESS_KEY_ID is missing (fail-closed)', async () => {
    delete process.env.ARCHIVE_AWS_ACCESS_KEY_ID;
    const { captureOutboundEmail } = await freshImport();
    await expect(
      captureOutboundEmail({ project: '333method', rawMime: 'MIME' })
    ).rejects.toThrow('ARCHIVE_AWS_ACCESS_KEY_ID');
  });

  it('throws if ARCHIVE_AWS_SECRET_ACCESS_KEY is missing (fail-closed)', async () => {
    delete process.env.ARCHIVE_AWS_SECRET_ACCESS_KEY;
    const { captureOutboundEmail } = await freshImport();
    await expect(
      captureOutboundEmail({ project: '333method', rawMime: 'MIME' })
    ).rejects.toThrow('ARCHIVE_AWS_SECRET_ACCESS_KEY');
  });

  it('spool.jsonl contains valid JSONL (each line parseable)', async () => {
    const { captureOutboundEmail } = await freshImport();
    await captureOutboundEmail({ project: '333method', rawMime: 'MIME 1' });
    await captureOutboundEmail({ project: '2step', rawMime: 'MIME 2' });

    const spoolFile = path.join(TEST_SPOOL_DIR, 'spool.jsonl');
    const lines = fs.readFileSync(spoolFile, 'utf8').split('\n').filter(Boolean);
    expect(lines).toHaveLength(2);
    for (const line of lines) {
      expect(() => JSON.parse(line)).not.toThrow();
    }
  });
});

// ── 2. captureInboundEmailEvent ────────────────────────────────────────────

describe('captureInboundEmailEvent', () => {
  it('returns a key with email/inbound prefix and .json ext', async () => {
    const { captureInboundEmailEvent } = await freshImport();
    const key = await captureInboundEmailEvent({
      project: '333method',
      snsPayload: '{"Type":"Notification","Message":"..."}',
    });
    expect(key).toMatch(/^email\/333method\/inbound\//);
    expect(key).toMatch(/\.json$/);
  });

  it('accepts object snsPayload (serialises to JSON)', async () => {
    const { captureInboundEmailEvent } = await freshImport();
    const payload = { Type: 'Notification', Message: JSON.stringify({ notificationType: 'Bounce' }) };
    await captureInboundEmailEvent({ project: '333method', snsPayload: payload });

    const [entry] = readSpoolEntries();
    const blob = readBlob(entry.blobFile);
    const parsed = JSON.parse(blob.toString());
    expect(parsed.Type).toBe('Notification');
  });

  it('includes sesMessageId in key when provided in metadata', async () => {
    const { captureInboundEmailEvent } = await freshImport();
    const key = await captureInboundEmailEvent({
      project: '333method',
      snsPayload: '{}',
      metadata: { sesMessageId: '0100018fab123456' },
    });
    expect(key).toContain('0100018fab123456');
  });

  it('fires S3 PutObjectCommand with COMPLIANCE lock', async () => {
    const { captureInboundEmailEvent } = await freshImport();
    await captureInboundEmailEvent({ project: '333method', snsPayload: '{}' });

    expect(mockSend).toHaveBeenCalledOnce();
    const [cmd] = mockSend.mock.calls[0];
    expect(cmd.ObjectLockMode).toBe('COMPLIANCE');
    expect(cmd.ServerSideEncryption).toBe('aws:kms');
  });
});

// ── 3. captureOutboundSms ──────────────────────────────────────────────────

describe('captureOutboundSms', () => {
  it('returns a key with sms/outbound prefix', async () => {
    const { captureOutboundSms } = await freshImport();
    const key = await captureOutboundSms({
      project: '333method',
      body: 'Hello from 333Method',
      from: '+61400000001',
      to: '+61400000002',
      twilioSid: 'SMabc123',
    });
    expect(key).toMatch(/^sms\/333method\/outbound\//);
    expect(key).toMatch(/\.json$/);
  });

  it('includes twilioSid in the S3 key', async () => {
    const { captureOutboundSms } = await freshImport();
    const key = await captureOutboundSms({
      project: '333method',
      body: 'Hi',
      from: '+61400000001',
      to: '+61400000002',
      twilioSid: 'SMxyz789',
    });
    expect(key).toContain('SMxyz789');
  });

  it('serialises the expected JSON record to the blob', async () => {
    const { captureOutboundSms } = await freshImport();
    await captureOutboundSms({
      project: '2step',
      body: 'Rendered body text',
      from: '+61400000001',
      to: '+61400000002',
      twilioSid: 'SMdef456',
      metadata: { templateId: 'sms-cold-au' },
    });

    const [entry] = readSpoolEntries();
    const blob = JSON.parse(readBlob(entry.blobFile).toString());
    expect(blob.project).toBe('2step');
    expect(blob.from).toBe('+61400000001');
    expect(blob.to).toBe('+61400000002');
    expect(blob.bodyRendered).toBe('Rendered body text');
    expect(blob.twilioSid).toBe('SMdef456');
    expect(blob.templateId).toBe('sms-cold-au');
    expect(blob.archiveVersion).toBe(1);
  });

  it('works without a twilioSid (null in record)', async () => {
    const { captureOutboundSms } = await freshImport();
    await captureOutboundSms({
      project: '333method',
      body: 'Hi',
      from: '+61400000001',
      to: '+61400000002',
    });

    const [entry] = readSpoolEntries();
    const blob = JSON.parse(readBlob(entry.blobFile).toString());
    expect(blob.twilioSid).toBeNull();
  });

  it('fires S3 PutObjectCommand with COMPLIANCE lock', async () => {
    const { captureOutboundSms } = await freshImport();
    await captureOutboundSms({ project: '333method', body: 'Hi', from: '+1', to: '+2', twilioSid: 'SM1' });

    expect(mockSend).toHaveBeenCalledOnce();
    const [cmd] = mockSend.mock.calls[0];
    expect(cmd.ObjectLockMode).toBe('COMPLIANCE');
    expect(cmd.Bucket).toBe('mmo-comms-archive-test');
  });

  it('throws on missing creds (fail-closed)', async () => {
    delete process.env.ARCHIVE_AWS_ACCESS_KEY_ID;
    const { captureOutboundSms } = await freshImport();
    await expect(
      captureOutboundSms({ project: '333method', body: 'Hi', from: '+1', to: '+2' })
    ).rejects.toThrow('ARCHIVE_AWS_ACCESS_KEY_ID');
  });
});

// ── 4. captureInboundSms ───────────────────────────────────────────────────

describe('captureInboundSms', () => {
  it('returns a key with sms/inbound prefix', async () => {
    const { captureInboundSms } = await freshImport();
    const key = await captureInboundSms({
      project: 'crai',
      rawTwilioPayload: '{"MessageSid":"SMabc","Body":"reply text"}',
    });
    expect(key).toMatch(/^sms\/crai\/inbound\//);
    expect(key).toMatch(/\.json$/);
  });

  it('accepts object rawTwilioPayload', async () => {
    const { captureInboundSms } = await freshImport();
    const payload = { MessageSid: 'SMabc', Body: 'reply' };
    await captureInboundSms({ project: 'crai', rawTwilioPayload: payload });

    const [entry] = readSpoolEntries();
    const blob = JSON.parse(readBlob(entry.blobFile).toString());
    expect(blob.MessageSid).toBe('SMabc');
  });

  it('includes twilioSid in key when provided in metadata', async () => {
    const { captureInboundSms } = await freshImport();
    const key = await captureInboundSms({
      project: 'crai',
      rawTwilioPayload: '{}',
      metadata: { twilioSid: 'SMreply123' },
    });
    expect(key).toContain('SMreply123');
  });

  it('fires S3 PUT with COMPLIANCE lock', async () => {
    const { captureInboundSms } = await freshImport();
    await captureInboundSms({ project: 'crai', rawTwilioPayload: '{}' });

    expect(mockSend).toHaveBeenCalledOnce();
    const [cmd] = mockSend.mock.calls[0];
    expect(cmd.ObjectLockMode).toBe('COMPLIANCE');
  });
});

// ── 5. S3 key format ───────────────────────────────────────────────────────

describe('S3 key format', () => {
  it('key uses ISO date without milliseconds (2026-04-14T03:22:11Z form)', async () => {
    const { captureOutboundEmail } = await freshImport();
    const key = await captureOutboundEmail({ project: '333method', rawMime: 'MIME' });
    // Should match ISO timestamp without ms: 2026-04-14T03:22:11Z
    expect(key).toMatch(/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/);
    // Should NOT have milliseconds
    expect(key).not.toMatch(/\.\d{3}Z/);
  });

  it('key hash portion is 12 hex chars', async () => {
    const { captureOutboundEmail } = await freshImport();
    const key = await captureOutboundEmail({ project: '333method', rawMime: 'MIME' });
    // Extract the filename portion after the last /
    const filename = key.split('/').pop();
    // filename: <ts>_<hash12>[_<sid>].eml
    const match = filename.match(/_([0-9a-f]{12})(?:_[^.]+)?\.eml$/);
    expect(match).not.toBeNull();
    expect(match[1]).toHaveLength(12);
  });

  it('different calls produce different keys (randomness)', async () => {
    const { captureOutboundEmail } = await freshImport();
    const k1 = await captureOutboundEmail({ project: '333method', rawMime: 'MIME' });
    const k2 = await captureOutboundEmail({ project: '333method', rawMime: 'MIME' });
    expect(k1).not.toBe(k2);
  });

  it('sms outbound key uses sms/<project>/outbound/<date>/<ts>_<hash>_<sid>.json', async () => {
    const { captureOutboundSms } = await freshImport();
    const key = await captureOutboundSms({
      project: 'crai',
      body: 'Hi',
      from: '+1',
      to: '+2',
      twilioSid: 'SMtestSid',
    });
    expect(key).toMatch(/^sms\/crai\/outbound\/\d{4}\/\d{2}\/\d{2}\//);
    expect(key).toContain('SMtestSid');
    expect(key).toMatch(/\.json$/);
  });
});

// ── 6. Bucket config ───────────────────────────────────────────────────────

describe('bucket configuration', () => {
  it('uses ARCHIVE_S3_BUCKET env var', async () => {
    process.env.ARCHIVE_S3_BUCKET = 'my-custom-bucket';
    const { captureOutboundEmail } = await freshImport();
    await captureOutboundEmail({ project: '333method', rawMime: 'MIME' });

    const emlCall = mockSend.mock.calls.find(([cmd]) => cmd.Key?.endsWith('.eml'));
    expect(emlCall[0].Bucket).toBe('my-custom-bucket');
  });

  it('defaults to mmo-comms-archive when ARCHIVE_S3_BUCKET is unset', async () => {
    delete process.env.ARCHIVE_S3_BUCKET;
    const { captureOutboundEmail } = await freshImport();
    await captureOutboundEmail({ project: '333method', rawMime: 'MIME' });

    const emlCall = mockSend.mock.calls.find(([cmd]) => cmd.Key?.endsWith('.eml'));
    expect(emlCall[0].Bucket).toBe('mmo-comms-archive');
  });
});

// ── 7. _resetS3Client helper ───────────────────────────────────────────────

describe('_resetS3Client', () => {
  it('forces a new S3Client to be created on next call', async () => {
    const mod = await freshImport();
    // First call — client created
    await mod.captureOutboundEmail({ project: '333method', rawMime: 'MIME' });
    const callCount1 = mockSend.mock.calls.length;

    // Reset and change region
    mod._resetS3Client();
    process.env.ARCHIVE_S3_REGION = 'us-east-1';
    await mod.captureOutboundEmail({ project: '333method', rawMime: 'MIME' });

    // Both calls should have fired S3
    expect(mockSend.mock.calls.length).toBeGreaterThan(callCount1);
  });
});
