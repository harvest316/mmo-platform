/**
 * Shared email transport module — unit tests
 *
 * Tests the SES send path, format helpers, reputation pause flag, and
 * the DR-223 archive capture wiring.
 *
 * Mocking strategy:
 *   - @aws-sdk/client-sesv2 → vi.mock() with constructable stubs; mockSend exposed via
 *     module-level ref so tests can configure return values and inspect calls.
 *   - ./archive.js → vi.mock() with a mockCaptureOutboundEmail stub; same pattern.
 *   - Module-level singleton state (_sesClient, _pauseCache): reset via
 *     vi.resetModules() + dynamic import() in freshImport(). The vi.mock() at top level
 *     is hoisted by vitest so it persists across module resets automatically.
 */

import { vi, describe, it, expect, beforeEach, afterEach } from 'vitest';

// ── SES SDK mock ────────────────────────────────────────────────────────────
//
// SendEmailCommand must be a proper constructor (function, not arrow) because
// the module calls `new SendEmailCommand(...)`.  We keep the mockSend ref
// at module scope so every test can reach it after any number of freshImport()s.

const mockSend = vi.fn();

vi.mock('@aws-sdk/client-sesv2', () => {
  // Arrow functions are not constructable — use named function expressions.
  function SESv2Client() {
    this.send = mockSend;
  }
  function SendEmailCommand(params) {
    Object.assign(this, params);
  }
  return { SESv2Client, SendEmailCommand };
});

// ── Archive mock ─────────────────────────────────────────────────────────────
//
// captureOutboundEmail is mocked so tests don't need ARCHIVE_* env vars and
// don't touch the spool or S3. The mock persists across vi.resetModules() calls
// for the same reason as the SES mock above.

const mockCaptureOutboundEmail = vi.fn();

vi.mock('../../src/archive.js', () => ({
  captureOutboundEmail: mockCaptureOutboundEmail,
}));

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Import a fresh copy of email.js, resetting module-level singletons.
 * The top-level vi.mock() stays in effect after vi.resetModules() because
 * vitest re-applies hoisted mocks on each new module resolution cycle.
 */
async function freshImport() {
  vi.resetModules();
  const mod = await import('../../src/email.js');
  return mod.sendEmail;
}

// ── Env / mock setup ────────────────────────────────────────────────────────

const BASE_ENV = {
  AWS_ACCESS_KEY_ID: 'test-access-key',
  AWS_SECRET_ACCESS_KEY: 'test-secret-key',
  AWS_REGION: 'us-east-1',
};

beforeEach(() => {
  for (const [k, v] of Object.entries(BASE_ENV)) process.env[k] = v;
  delete process.env.SES_CONFIGURATION_SET;
  delete process.env.SES_CONFIGURATION_SET_NOTRACK;

  vi.clearAllMocks();
  mockSend.mockResolvedValue({ MessageId: 'ses-msg-id-1' });
  mockCaptureOutboundEmail.mockResolvedValue('email/test/outbound/2026/04/14/ts_abc123.eml');
});

afterEach(() => {
  delete process.env.SES_CONFIGURATION_SET;
  delete process.env.SES_CONFIGURATION_SET_NOTRACK;
  vi.useRealTimers();
});

const BASE_PARAMS = {
  from: 'sender@example.com',
  to: 'recipient@example.com',
  subject: 'Test subject',
  text: 'Plain body',
};

// ── 1. SES send path ────────────────────────────────────────────────────────

describe('SES send path', () => {
  it('returns { id, s3ArchiveKey } on successful send', async () => {
    mockSend.mockResolvedValue({ MessageId: 'abc-123' });
    mockCaptureOutboundEmail.mockResolvedValue('email/test/outbound/key.eml');
    const sendEmail = await freshImport();
    const result = await sendEmail(BASE_PARAMS);
    expect(result).toEqual({ id: 'abc-123', s3ArchiveKey: 'email/test/outbound/key.eml' });
  });

  it('sends all params to SES (from, to, subject, html, text, headers, tags, replyTo)', async () => {
    const sendEmail = await freshImport();
    await sendEmail({
      from: 'a@example.com',
      to: 'b@example.com',
      subject: 'Hello',
      html: '<p>Hi</p>',
      text: 'Hi',
      headers: { 'X-Custom': 'value' },
      tags: { campaign: 'test' },
      replyTo: 'reply@example.com',
    });

    // The command is the first argument passed to mockSend
    const command = mockSend.mock.calls[0][0];
    expect(command.FromEmailAddress).toBe('a@example.com');
    expect(command.Destination.ToAddresses).toEqual(['b@example.com']);
    expect(command.Content.Simple.Subject.Data).toBe('Hello');
    expect(command.Content.Simple.Body.Html.Data).toBe('<p>Hi</p>');
    expect(command.Content.Simple.Body.Text.Data).toBe('Hi');
    expect(command.ReplyToAddresses).toEqual(['reply@example.com']);
    expect(command.Content.Simple.Headers).toEqual([{ Name: 'X-Custom', Value: 'value' }]);
    expect(command.EmailTags).toEqual([{ Name: 'campaign', Value: 'test' }]);
  });

  it('sends with minimal params (text only, no html/headers/tags/replyTo)', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ from: 'a@example.com', to: 'b@example.com', subject: 'Sub', text: 'body' });

    const command = mockSend.mock.calls[0][0];
    expect(command.Content.Simple.Body.Html).toBeUndefined();
    expect(command.Content.Simple.Headers).toBeUndefined();
    expect(command.EmailTags).toBeUndefined();
    expect(command.ReplyToAddresses).toBeUndefined();
  });

  it('uses SES_CONFIGURATION_SET env var when set', async () => {
    process.env.SES_CONFIGURATION_SET = 'my-config-set';
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    const command = mockSend.mock.calls[0][0];
    expect(command.ConfigurationSetName).toBe('my-config-set');
  });

  it('falls back to mmo-outbound when SES_CONFIGURATION_SET is unset', async () => {
    delete process.env.SES_CONFIGURATION_SET;
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    const command = mockSend.mock.calls[0][0];
    expect(command.ConfigurationSetName).toBe('mmo-outbound');
  });

  it('routes to mmo-outbound-notrack when trackEngagement=false (DR-214)', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, trackEngagement: false });
    const command = mockSend.mock.calls[0][0];
    expect(command.ConfigurationSetName).toBe('mmo-outbound-notrack');
  });

  it('respects SES_CONFIGURATION_SET_NOTRACK env var when trackEngagement=false', async () => {
    process.env.SES_CONFIGURATION_SET_NOTRACK = 'custom-notrack-set';
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, trackEngagement: false });
    const command = mockSend.mock.calls[0][0];
    expect(command.ConfigurationSetName).toBe('custom-notrack-set');
  });

  it('uses tracked config set when trackEngagement=true (explicit opt-in)', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, trackEngagement: true });
    const command = mockSend.mock.calls[0][0];
    expect(command.ConfigurationSetName).toBe('mmo-outbound');
  });

  it('uses tracked config set when trackEngagement is undefined (default behaviour)', async () => {
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    const command = mockSend.mock.calls[0][0];
    expect(command.ConfigurationSetName).toBe('mmo-outbound');
  });

  it('throws clear error when AWS_ACCESS_KEY_ID is missing', async () => {
    delete process.env.AWS_ACCESS_KEY_ID;
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('AWS_ACCESS_KEY_ID');
  });

  it('throws clear error when AWS_SECRET_ACCESS_KEY is missing', async () => {
    delete process.env.AWS_SECRET_ACCESS_KEY;
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('AWS_SECRET_ACCESS_KEY');
  });

  it('rethrows SES SDK errors with descriptive message including error name', async () => {
    const sdkError = new Error('Throttled by SES');
    sdkError.name = 'TooManyRequestsException';
    mockSend.mockRejectedValue(sdkError);
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow(
      'SES send failed [TooManyRequestsException]: Throttled by SES'
    );
  });

  it('rethrows SES errors with "Error" as the name when name is not overridden', async () => {
    mockSend.mockRejectedValue(new Error('Unexpected failure'));
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('SES send failed [Error]: Unexpected failure');
  });

  it('includes BCC in Destination.BccAddresses when provided', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, bcc: 'bcc@example.com' });
    const command = mockSend.mock.calls[0][0];
    expect(command.Destination.BccAddresses).toEqual(['bcc@example.com']);
  });

  it('omits BccAddresses when bcc not provided', async () => {
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    const command = mockSend.mock.calls[0][0];
    expect(command.Destination.BccAddresses).toBeUndefined();
  });
});

// ── 2. Tag and header formatting ────────────────────────────────────────────

describe('tag and header formatting', () => {
  it('buildSesTags: produces [{ Name, Value }] for non-empty object', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, tags: { campaign: 'promo', region: 'au' } });
    const command = mockSend.mock.calls[0][0];
    expect(command.EmailTags).toEqual([
      { Name: 'campaign', Value: 'promo' },
      { Name: 'region', Value: 'au' },
    ]);
  });

  it('buildSesTags: returns undefined for empty object', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, tags: {} });
    const command = mockSend.mock.calls[0][0];
    expect(command.EmailTags).toBeUndefined();
  });

  it('buildSesTags: returns undefined for null', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, tags: null });
    const command = mockSend.mock.calls[0][0];
    expect(command.EmailTags).toBeUndefined();
  });

  it('buildSesHeaders: produces [{ Name, Value }] for non-empty object', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, headers: { 'X-Custom': 'header-val' } });
    const command = mockSend.mock.calls[0][0];
    expect(command.Content.Simple.Headers).toEqual([{ Name: 'X-Custom', Value: 'header-val' }]);
  });

  it('buildSesHeaders: returns undefined for empty object', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, headers: {} });
    const command = mockSend.mock.calls[0][0];
    expect(command.Content.Simple.Headers).toBeUndefined();
  });

  it('tag values are coerced to strings', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, tags: { count: 42 } });
    const command = mockSend.mock.calls[0][0];
    expect(command.EmailTags[0].Value).toBe('42');
  });
});

// ── 3. Reputation pause flag ────────────────────────────────────────────────
//
// The shared transport reads /home/jason/code/mmo-platform/.email-pause-state.json
// (path resolved relative to email.js) on every send. The cron writes it.
// We use a real temp file in the expected location so the integration is exercised
// end-to-end without mocking node:fs.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
// Path must match mmo-platform/src/email.js: path.resolve(__dirname, '../.email-pause-state.json')
// Resolved from src/, that's mmo-platform/.email-pause-state.json (one level up).
const PAUSE_FILE = path.resolve(path.dirname(__filename), '../../.email-pause-state.json');

function writePauseFile(doc) {
  fs.writeFileSync(PAUSE_FILE, JSON.stringify(doc));
}
function clearPauseFile() {
  try { fs.unlinkSync(PAUSE_FILE); } catch (e) { if (e.code !== 'ENOENT') throw e; }
}

describe('reputation pause flag', () => {
  beforeEach(() => clearPauseFile());
  afterEach(() => clearPauseFile());

  it('no file → sends proceed (fail-open)', async () => {
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).resolves.toMatchObject({ id: 'ses-msg-id-1' });
  });

  it("state='none' → sends proceed", async () => {
    writePauseFile({ state: 'none' });
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).resolves.toMatchObject({ id: 'ses-msg-id-1' });
  });

  it("state='cold' → blocks default (cold) sends", async () => {
    writePauseFile({ state: 'cold', reason: 'test cold' });
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('Cold outreach paused');
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('test cold');
  });

  it("state='cold' → allows transactional sends", async () => {
    writePauseFile({ state: 'cold', reason: 'test cold' });
    const sendEmail = await freshImport();
    await expect(sendEmail({ ...BASE_PARAMS, kind: 'transactional' }))
      .resolves.toMatchObject({ id: 'ses-msg-id-1' });
  });

  it("state='all' → blocks everything including transactional", async () => {
    writePauseFile({ state: 'all', reason: 'critical reputation' });
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('Email paused');
    await expect(sendEmail({ ...BASE_PARAMS, kind: 'transactional' }))
      .rejects.toThrow('Email paused');
  });

  it("state='all' → kind='auth' bypasses pause (magic links must always work) (DR-190)", async () => {
    writePauseFile({ state: 'all', reason: 'critical reputation' });
    const sendEmail = await freshImport();
    await expect(sendEmail({ ...BASE_PARAMS, kind: 'auth' }))
      .resolves.toMatchObject({ id: 'ses-msg-id-1' });
  });

  it("state='cold' → kind='auth' bypasses pause", async () => {
    writePauseFile({ state: 'cold', reason: 'cold outreach paused' });
    const sendEmail = await freshImport();
    await expect(sendEmail({ ...BASE_PARAMS, kind: 'auth' }))
      .resolves.toMatchObject({ id: 'ses-msg-id-1' });
  });

  it('error message includes the pause file path for operator clarity', async () => {
    writePauseFile({ state: 'all', reason: 'r' });
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('.email-pause-state.json');
  });
});

// ── 4. Archive capture (DR-223) ─────────────────────────────────────────────

describe('archive capture (DR-223)', () => {
  it('returns { id, s3ArchiveKey } with key from captureOutboundEmail', async () => {
    mockSend.mockResolvedValue({ MessageId: 'ses-id-1' });
    mockCaptureOutboundEmail.mockResolvedValue('email/test/outbound/key.eml');
    const sendEmail = await freshImport();
    const result = await sendEmail(BASE_PARAMS);
    expect(result).toEqual({ id: 'ses-id-1', s3ArchiveKey: 'email/test/outbound/key.eml' });
  });

  it('calls captureOutboundEmail with the project param', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, project: '333method' });
    expect(mockCaptureOutboundEmail).toHaveBeenCalledWith(
      expect.objectContaining({ project: '333method' })
    );
  });

  it('defaults project to "unknown" when not provided', async () => {
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    expect(mockCaptureOutboundEmail).toHaveBeenCalledWith(
      expect.objectContaining({ project: 'unknown' })
    );
  });

  it('passes configSet metadata matching the SES configuration set', async () => {
    process.env.SES_CONFIGURATION_SET = 'my-config-set';
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    expect(mockCaptureOutboundEmail).toHaveBeenCalledWith(
      expect.objectContaining({
        metadata: expect.objectContaining({ configSet: 'my-config-set' }),
      })
    );
  });

  it('passes notrack configSet when trackEngagement=false', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, trackEngagement: false });
    expect(mockCaptureOutboundEmail).toHaveBeenCalledWith(
      expect.objectContaining({
        metadata: expect.objectContaining({ configSet: 'mmo-outbound-notrack' }),
      })
    );
    // SES also gets the notrack set
    const command = mockSend.mock.calls[0][0];
    expect(command.ConfigurationSetName).toBe('mmo-outbound-notrack');
  });

  it('fail-closed: archive failure blocks send (SES not called)', async () => {
    mockCaptureOutboundEmail.mockRejectedValue(new Error('spool full'));
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('spool full');
    expect(mockSend).not.toHaveBeenCalled();
  });

  it('archive is called before SES (ordering: captureOutboundEmail before mockSend)', async () => {
    const callOrder = [];
    mockCaptureOutboundEmail.mockImplementation(async () => { callOrder.push('archive'); return 'key.eml'; });
    mockSend.mockImplementation(async () => { callOrder.push('ses'); return { MessageId: 'id-1' }; });
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    expect(callOrder).toEqual(['archive', 'ses']);
  });

  it('captureOutboundEmail receives rawMime as a non-empty string', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, html: '<p>Hello</p>' });
    const { rawMime } = mockCaptureOutboundEmail.mock.calls[0][0];
    expect(typeof rawMime).toBe('string');
    expect(rawMime.length).toBeGreaterThan(0);
    expect(rawMime).toContain('MIME-Version: 1.0');
    expect(rawMime).toContain('Subject: Test subject');
  });
});
