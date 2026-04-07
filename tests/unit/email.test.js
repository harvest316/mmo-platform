/**
 * Shared email transport module — unit tests
 *
 * Tests provider routing (SES / Resend / warmup overflow), send paths,
 * format helpers, and the daily warmup counter.
 *
 * Mocking strategy:
 *   - @aws-sdk/client-sesv2 → vi.mock() with constructable stubs; mockSend exposed via
 *     module-level ref so tests can configure return values and inspect calls.
 *   - global.fetch → vi.fn() reset in beforeEach.
 *   - Module-level singleton state (_sesClient, sesSentToday, _counterDate): reset via
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

function resendOkResponse(id = 'resend-msg-1') {
  return {
    ok: true,
    status: 200,
    json: vi.fn().mockResolvedValue({ id }),
  };
}

function resendErrorResponse(status, bodyObj) {
  return {
    ok: false,
    status,
    json: vi.fn().mockResolvedValue(bodyObj),
  };
}

// ── Env / mock setup ────────────────────────────────────────────────────────

const BASE_ENV = {
  AWS_ACCESS_KEY_ID: 'test-access-key',
  AWS_SECRET_ACCESS_KEY: 'test-secret-key',
  AWS_REGION: 'us-east-1',
  RESEND_API_KEY: 're_test_key',
};

beforeEach(() => {
  for (const [k, v] of Object.entries(BASE_ENV)) process.env[k] = v;
  delete process.env.EMAIL_PROVIDER;
  delete process.env.SES_WARMUP_DAILY_LIMIT;
  delete process.env.SES_CONFIGURATION_SET;

  vi.clearAllMocks();
  mockSend.mockResolvedValue({ MessageId: 'ses-msg-id-1' });
  global.fetch = vi.fn().mockResolvedValue(resendOkResponse());
});

afterEach(() => {
  delete process.env.EMAIL_PROVIDER;
  delete process.env.SES_WARMUP_DAILY_LIMIT;
  delete process.env.SES_CONFIGURATION_SET;
  vi.useRealTimers();
});

const BASE_PARAMS = {
  from: 'sender@example.com',
  to: 'recipient@example.com',
  subject: 'Test subject',
  text: 'Plain body',
};

// ── 1. Provider routing ─────────────────────────────────────────────────────

describe('provider routing', () => {
  it('defaults to Resend when EMAIL_PROVIDER is unset', async () => {
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    expect(global.fetch).toHaveBeenCalledOnce();
    expect(mockSend).not.toHaveBeenCalled();
  });

  it('uses Resend when EMAIL_PROVIDER=resend', async () => {
    process.env.EMAIL_PROVIDER = 'resend';
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    expect(global.fetch).toHaveBeenCalledOnce();
    expect(mockSend).not.toHaveBeenCalled();
  });

  it('uses SES when EMAIL_PROVIDER=ses', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    expect(mockSend).toHaveBeenCalledOnce();
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('falls through to Resend for unknown EMAIL_PROVIDER value', async () => {
    process.env.EMAIL_PROVIDER = 'sendgrid';
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    expect(global.fetch).toHaveBeenCalledOnce();
    expect(mockSend).not.toHaveBeenCalled();
  });

  it('routes to SES when under SES_WARMUP_DAILY_LIMIT', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    process.env.SES_WARMUP_DAILY_LIMIT = '100';
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    expect(mockSend).toHaveBeenCalledOnce();
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('overflows to Resend when SES_WARMUP_DAILY_LIMIT is already met (limit=0)', async () => {
    // With a fresh module sesSentToday=0 and limit=0, 0>=0 is true → overflow
    process.env.EMAIL_PROVIDER = 'ses';
    process.env.SES_WARMUP_DAILY_LIMIT = '0';
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    expect(global.fetch).toHaveBeenCalledOnce();
    expect(mockSend).not.toHaveBeenCalled();
  });

  it('throws descriptive error when limit exceeded and no RESEND_API_KEY for overflow', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    process.env.SES_WARMUP_DAILY_LIMIT = '0';
    delete process.env.RESEND_API_KEY;
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('SES daily warmup limit');
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('RESEND_API_KEY');
  });
});

// ── 2. SES send path ────────────────────────────────────────────────────────

describe('SES send path', () => {
  it('returns { id: MessageId } on successful SES send', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    mockSend.mockResolvedValue({ MessageId: 'abc-123' });
    const sendEmail = await freshImport();
    const result = await sendEmail(BASE_PARAMS);
    expect(result).toEqual({ id: 'abc-123' });
  });

  it('sends all params to SES (from, to, subject, html, text, headers, tags, replyTo)', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
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
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await sendEmail({ from: 'a@example.com', to: 'b@example.com', subject: 'Sub', text: 'body' });

    const command = mockSend.mock.calls[0][0];
    expect(command.Content.Simple.Body.Html).toBeUndefined();
    expect(command.Content.Simple.Headers).toBeUndefined();
    expect(command.EmailTags).toBeUndefined();
    expect(command.ReplyToAddresses).toBeUndefined();
  });

  it('uses SES_CONFIGURATION_SET env var when set', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    process.env.SES_CONFIGURATION_SET = 'my-config-set';
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    const command = mockSend.mock.calls[0][0];
    expect(command.ConfigurationSetName).toBe('my-config-set');
  });

  it('falls back to mmo-outbound when SES_CONFIGURATION_SET is unset', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    delete process.env.SES_CONFIGURATION_SET;
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    const command = mockSend.mock.calls[0][0];
    expect(command.ConfigurationSetName).toBe('mmo-outbound');
  });

  it('throws clear error when AWS_ACCESS_KEY_ID is missing', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    delete process.env.AWS_ACCESS_KEY_ID;
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('AWS_ACCESS_KEY_ID');
  });

  it('throws clear error when AWS_SECRET_ACCESS_KEY is missing', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    delete process.env.AWS_SECRET_ACCESS_KEY;
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('AWS_SECRET_ACCESS_KEY');
  });

  it('rethrows SES SDK errors with descriptive message including error name', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    const sdkError = new Error('Throttled by SES');
    sdkError.name = 'TooManyRequestsException';
    mockSend.mockRejectedValue(sdkError);
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow(
      'SES send failed [TooManyRequestsException]: Throttled by SES'
    );
  });

  it('rethrows SES errors with "Error" as the name when name is not overridden', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    mockSend.mockRejectedValue(new Error('Unexpected failure'));
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('SES send failed [Error]: Unexpected failure');
  });
});

// ── 3. Resend send path ─────────────────────────────────────────────────────

describe('Resend send path', () => {
  it('returns { id } on successful Resend send', async () => {
    global.fetch = vi.fn().mockResolvedValue(resendOkResponse('resend-xyz'));
    const sendEmail = await freshImport();
    const result = await sendEmail(BASE_PARAMS);
    expect(result).toEqual({ id: 'resend-xyz' });
  });

  it('calls Resend API endpoint with correct method and auth header', async () => {
    process.env.RESEND_API_KEY = 're_my_key';
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    const [url, opts] = global.fetch.mock.calls[0];
    expect(url).toBe('https://api.resend.com/emails');
    expect(opts.method).toBe('POST');
    expect(opts.headers['Authorization']).toBe('Bearer re_my_key');
  });

  it('throws clear error when RESEND_API_KEY is missing', async () => {
    delete process.env.RESEND_API_KEY;
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('Missing env var: RESEND_API_KEY');
  });

  it('throws with HTTP status code when Resend returns non-ok response', async () => {
    global.fetch = vi.fn().mockResolvedValue(resendErrorResponse(422, { message: 'Invalid email address' }));
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('HTTP 422');
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('Invalid email address');
  });

  it('uses "Unknown error" fallback when Resend error body has no message field', async () => {
    global.fetch = vi.fn().mockResolvedValue(resendErrorResponse(500, {}));
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('Unknown error');
  });

  it('throws gracefully (HTTP 500 / Unknown error) when Resend returns invalid JSON', async () => {
    // json() failure is caught with .catch(() => ({})) in the module, so the non-ok
    // branch fires with an empty body object → "Unknown error"
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 500,
      json: vi.fn().mockRejectedValue(new SyntaxError('Unexpected token')),
    });
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('HTTP 500');
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('Unknown error');
  });

  it('includes bcc field in request body when provided', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, bcc: 'bcc@example.com' });
    const body = JSON.parse(global.fetch.mock.calls[0][1].body);
    expect(body.bcc).toBe('bcc@example.com');
  });

  it('omits bcc field when not provided', async () => {
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS);
    const body = JSON.parse(global.fetch.mock.calls[0][1].body);
    expect(body.bcc).toBeUndefined();
  });

  it('includes attachments in request body when provided', async () => {
    const attachments = [{ filename: 'report.pdf', content: 'base64data' }];
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, attachments });
    const body = JSON.parse(global.fetch.mock.calls[0][1].body);
    expect(body.attachments).toEqual(attachments);
  });

  it('omits attachments field when array is empty', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, attachments: [] });
    const body = JSON.parse(global.fetch.mock.calls[0][1].body);
    expect(body.attachments).toBeUndefined();
  });
});

// ── 4. Tag and header formatting ────────────────────────────────────────────

describe('tag and header formatting', () => {
  it('buildSesTags: produces [{ Name, Value }] for non-empty object', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, tags: { campaign: 'promo', region: 'au' } });
    const command = mockSend.mock.calls[0][0];
    expect(command.EmailTags).toEqual([
      { Name: 'campaign', Value: 'promo' },
      { Name: 'region', Value: 'au' },
    ]);
  });

  it('buildSesTags: returns undefined for empty object', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, tags: {} });
    const command = mockSend.mock.calls[0][0];
    expect(command.EmailTags).toBeUndefined();
  });

  it('buildSesTags: returns undefined for null', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, tags: null });
    const command = mockSend.mock.calls[0][0];
    expect(command.EmailTags).toBeUndefined();
  });

  it('buildResendTags: produces [{ name, value }] for non-empty object', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, tags: { campaign: 'promo' } });
    const body = JSON.parse(global.fetch.mock.calls[0][1].body);
    expect(body.tags).toEqual([{ name: 'campaign', value: 'promo' }]);
  });

  it('buildResendTags: returns undefined for null (omitted from body)', async () => {
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, tags: null });
    const body = JSON.parse(global.fetch.mock.calls[0][1].body);
    expect(body.tags).toBeUndefined();
  });

  it('buildSesHeaders: produces [{ Name, Value }] for non-empty object', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, headers: { 'X-Custom': 'header-val' } });
    const command = mockSend.mock.calls[0][0];
    expect(command.Content.Simple.Headers).toEqual([{ Name: 'X-Custom', Value: 'header-val' }]);
  });

  it('buildSesHeaders: returns undefined for empty object', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, headers: {} });
    const command = mockSend.mock.calls[0][0];
    expect(command.Content.Simple.Headers).toBeUndefined();
  });

  it('tag values are coerced to strings', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await sendEmail({ ...BASE_PARAMS, tags: { count: 42 } });
    const command = mockSend.mock.calls[0][0];
    expect(command.EmailTags[0].Value).toBe('42');
  });
});

// ── 5. Warmup counter ───────────────────────────────────────────────────────

describe('warmup counter', () => {
  it('increments counter on each successful SES send', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    process.env.SES_WARMUP_DAILY_LIMIT = '10';
    const sendEmail = await freshImport();

    await sendEmail(BASE_PARAMS);
    await sendEmail(BASE_PARAMS);
    await sendEmail(BASE_PARAMS);
    // All 3 sent via SES — counter is now 3, limit is 10
    expect(mockSend).toHaveBeenCalledTimes(3);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('overflows to Resend once counter reaches the limit', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    process.env.SES_WARMUP_DAILY_LIMIT = '2';
    const sendEmail = await freshImport();

    await sendEmail(BASE_PARAMS); // SES, counter → 1
    await sendEmail(BASE_PARAMS); // SES, counter → 2
    expect(mockSend).toHaveBeenCalledTimes(2);
    expect(global.fetch).not.toHaveBeenCalled();

    // counter=2 >= limit=2 → overflow to Resend
    await sendEmail(BASE_PARAMS);
    expect(mockSend).toHaveBeenCalledTimes(2); // no new SES call
    expect(global.fetch).toHaveBeenCalledOnce();
  });

  it('does NOT increment counter when Resend is used directly', async () => {
    // Send via Resend (no EMAIL_PROVIDER=ses) → counter stays 0.
    // Then switch to SES with limit=1 — first SES send must go via SES, not overflow.
    const sendEmail = await freshImport();
    await sendEmail(BASE_PARAMS); // Resend path — counter untouched

    process.env.EMAIL_PROVIDER = 'ses';
    process.env.SES_WARMUP_DAILY_LIMIT = '1';
    // sesSentToday is still 0, so 0 >= 1 is false → goes via SES
    await sendEmail(BASE_PARAMS);
    expect(mockSend).toHaveBeenCalledOnce();
  });

  it('resets counter at midnight UTC (simulate date change)', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-04-04T12:00:00.000Z'));

    process.env.EMAIL_PROVIDER = 'ses';
    process.env.SES_WARMUP_DAILY_LIMIT = '1';
    const sendEmail = await freshImport();

    // Day 1: saturate the 1-email limit
    await sendEmail(BASE_PARAMS); // SES, counter → 1
    expect(mockSend).toHaveBeenCalledTimes(1);

    // Still Day 1 — overflow
    await sendEmail(BASE_PARAMS); // Resend overflow
    expect(global.fetch).toHaveBeenCalledTimes(1);

    // Advance to Day 2
    vi.setSystemTime(new Date('2026-04-05T00:01:00.000Z'));
    vi.clearAllMocks();
    mockSend.mockResolvedValue({ MessageId: 'ses-day2' });

    // Counter resets → goes via SES again
    await sendEmail(BASE_PARAMS);
    expect(mockSend).toHaveBeenCalledTimes(1);
    expect(global.fetch).not.toHaveBeenCalled();
  });
});

// ── 6. Reputation pause flag ────────────────────────────────────────────────
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
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).resolves.toEqual({ id: 'ses-msg-id-1' });
  });

  it("state='none' → sends proceed", async () => {
    writePauseFile({ state: 'none' });
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).resolves.toEqual({ id: 'ses-msg-id-1' });
  });

  it("state='cold' → blocks default (cold) sends", async () => {
    writePauseFile({ state: 'cold', reason: 'test cold' });
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('Cold outreach paused');
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('test cold');
  });

  it("state='cold' → allows transactional sends", async () => {
    writePauseFile({ state: 'cold', reason: 'test cold' });
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await expect(sendEmail({ ...BASE_PARAMS, kind: 'transactional' }))
      .resolves.toEqual({ id: 'ses-msg-id-1' });
  });

  it("state='all' → blocks everything including transactional", async () => {
    writePauseFile({ state: 'all', reason: 'critical reputation' });
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('Email paused');
    await expect(sendEmail({ ...BASE_PARAMS, kind: 'transactional' }))
      .rejects.toThrow('Email paused');
  });

  it('error message includes the pause file path for operator clarity', async () => {
    writePauseFile({ state: 'all', reason: 'r' });
    process.env.EMAIL_PROVIDER = 'ses';
    const sendEmail = await freshImport();
    await expect(sendEmail(BASE_PARAMS)).rejects.toThrow('.email-pause-state.json');
  });
});

// ── 7. Warmup overflow end-to-end ───────────────────────────────────────────

describe('warmup overflow end-to-end', () => {
  it('SES limit exceeded → overflows to Resend → returns Resend id', async () => {
    process.env.EMAIL_PROVIDER = 'ses';
    process.env.SES_WARMUP_DAILY_LIMIT = '1';
    global.fetch = vi.fn().mockResolvedValue(resendOkResponse('overflow-id-99'));
    const sendEmail = await freshImport();

    // First send saturates the limit
    await sendEmail(BASE_PARAMS);
    expect(mockSend).toHaveBeenCalledOnce();

    // Second send overflows — must return Resend's id
    const result = await sendEmail(BASE_PARAMS);
    expect(result).toEqual({ id: 'overflow-id-99' });
    expect(global.fetch).toHaveBeenCalledOnce();
  });
});
