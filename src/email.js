/**
 * Shared email transport module
 *
 * Abstracts email sending behind a provider toggle supporting SES and Resend.
 * During SES warmup, routes overflow back to Resend based on a daily send limit.
 *
 * Provider selection:
 *   EMAIL_PROVIDER=resend  → always use Resend (default, pre-migration)
 *   EMAIL_PROVIDER=ses     → use SES; overflow to Resend if SES_WARMUP_DAILY_LIMIT is set
 *
 * Integration:
 *   - 333Method: src/outreach/email.js
 *   - 2Step: src/stages/outreach.js
 *   - ContactReplyAI: src/email.js
 *
 * Imported via: ../../../mmo-platform/src/email.js
 */

import { SESv2Client, SendEmailCommand } from '@aws-sdk/client-sesv2';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// ── Reputation pause flag (file-based, written by check-ses-reputation cron) ─

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const PAUSE_FILE = path.resolve(__dirname, '../.email-pause-state.json');
const PAUSE_CACHE_TTL_MS = 60 * 1000; // 60s — fresh enough for hourly cron

let _pauseCache = null;
let _pauseCacheAt = 0;

/**
 * Read the pause state file with a 60s in-process cache.
 * Returns one of:
 *   { state: 'none' }                        — no pause
 *   { state: 'cold', reason, since }         — pause cold outreach (333Method, 2Step)
 *   { state: 'all',  reason, since }         — pause everything
 *
 * Missing file → { state: 'none' } (fail-open: never block sends because the
 * monitoring system itself is unavailable; the cron logs and AWS metrics are
 * the safety nets).
 */
function readPauseState() {
  const now = Date.now();
  if (_pauseCache && now - _pauseCacheAt < PAUSE_CACHE_TTL_MS) {
    return _pauseCache;
  }
  try {
    const raw = fs.readFileSync(PAUSE_FILE, 'utf8');
    _pauseCache = JSON.parse(raw);
  } catch (err) {
    if (err.code !== 'ENOENT') {
      // Don't crash sends on a corrupt file — log once per cache cycle and treat as no-pause.
      console.warn(`[email.js] readPauseState: ${err.message} — treating as 'none'`);
    }
    _pauseCache = { state: 'none' };
  }
  _pauseCacheAt = now;
  return _pauseCache;
}

/**
 * Test-only: clear the pause state cache so a freshImport() reads the file.
 * Not part of the public contract.
 */
export function _resetPauseCache() {
  _pauseCache = null;
  _pauseCacheAt = 0;
}

// ── SES client (lazy singleton) ────────────────────────────────────────────

let _sesClient;

function getSesClient() {
  if (!_sesClient) {
    if (!process.env.AWS_ACCESS_KEY_ID) throw new Error('Missing env var: AWS_ACCESS_KEY_ID');
    if (!process.env.AWS_SECRET_ACCESS_KEY) throw new Error('Missing env var: AWS_SECRET_ACCESS_KEY');
    _sesClient = new SESv2Client({
      region: process.env.AWS_REGION || 'ap-southeast-2',
      credentials: {
        accessKeyId: process.env.AWS_ACCESS_KEY_ID,
        secretAccessKey: process.env.AWS_SECRET_ACCESS_KEY,
      },
    });
  }
  return _sesClient;
}

// ── SES warmup counter ─────────────────────────────────────────────────────

let sesSentToday = 0;
let _counterDate = new Date().toISOString().slice(0, 10); // YYYY-MM-DD UTC

function incrementSesCounter() {
  const today = new Date().toISOString().slice(0, 10);
  if (today !== _counterDate) {
    sesSentToday = 0;
    _counterDate = today;
  }
  sesSentToday++;
}

function sesOverLimit() {
  const limit = process.env.SES_WARMUP_DAILY_LIMIT;
  if (!limit) return false;
  const today = new Date().toISOString().slice(0, 10);
  if (today !== _counterDate) {
    sesSentToday = 0;
    _counterDate = today;
  }
  return sesSentToday >= Number(limit);
}

// ── Format helpers ─────────────────────────────────────────────────────────

function buildSesHeaders(headers) {
  if (!headers || typeof headers !== 'object') return undefined;
  const entries = Object.entries(headers);
  if (entries.length === 0) return undefined;
  return entries.map(([Name, Value]) => ({ Name, Value: String(Value) }));
}

function buildSesTags(tags) {
  if (!tags || typeof tags !== 'object') return undefined;
  const entries = Object.entries(tags);
  if (entries.length === 0) return undefined;
  return entries.map(([Name, Value]) => ({ Name, Value: String(Value) }));
}

function buildResendTags(tags) {
  if (!tags || typeof tags !== 'object') return undefined;
  const entries = Object.entries(tags);
  if (entries.length === 0) return undefined;
  return entries.map(([name, value]) => ({ name, value: String(value) }));
}

// ── SES send ───────────────────────────────────────────────────────────────

async function sendViaSes({ from, to, subject, html, text, headers, tags, replyTo }) {
  const command = new SendEmailCommand({
    FromEmailAddress: from,
    Destination: { ToAddresses: [to] },
    ReplyToAddresses: replyTo ? [replyTo] : undefined,
    Content: {
      Simple: {
        Subject: { Data: subject, Charset: 'UTF-8' },
        Body: {
          Text: text ? { Data: text, Charset: 'UTF-8' } : undefined,
          Html: html ? { Data: html, Charset: 'UTF-8' } : undefined,
        },
        Headers: buildSesHeaders(headers),
      },
    },
    ConfigurationSetName: process.env.SES_CONFIGURATION_SET || 'mmo-outbound',
    EmailTags: buildSesTags(tags),
  });

  try {
    const response = await getSesClient().send(command);
    incrementSesCounter();
    return { id: response.MessageId };
  } catch (err) {
    throw new Error(`SES send failed [${err.name || 'Error'}]: ${err.message}`);
  }
}

// ── Resend send ────────────────────────────────────────────────────────────

async function sendViaResend({ from, to, subject, html, text, headers, tags, replyTo, attachments, bcc }) {
  if (!process.env.RESEND_API_KEY) throw new Error('Missing env var: RESEND_API_KEY');

  const body = {
    from,
    to,
    subject,
    html,
    text,
    headers,
    tags: buildResendTags(tags),
    reply_to: replyTo,
  };

  if (bcc) {
    body.bcc = bcc;
  }

  if (attachments && attachments.length > 0) {
    body.attachments = attachments;
  }

  const response = await fetch('https://api.resend.com/emails', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${process.env.RESEND_API_KEY}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(body),
  });

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    const message = data.message || data.error || 'Unknown error';
    throw new Error(`Resend send failed [HTTP ${response.status}]: ${message}`);
  }

  return { id: data.id };
}

// ── Public API ─────────────────────────────────────────────────────────────

/**
 * Send an email via the configured provider.
 *
 * @param {object} params
 * @param {string} params.from        - Sender address
 * @param {string} params.to          - Recipient address
 * @param {string} params.subject     - Email subject
 * @param {string} [params.html]      - HTML body
 * @param {string} [params.text]      - Plain-text body
 * @param {object} [params.headers]   - Extra headers as { 'Header-Name': 'value' }
 * @param {object} [params.tags]        - Tracking tags as { key: 'value' }
 * @param {string} [params.replyTo]     - Reply-To address
 * @param {Array}  [params.attachments] - Resend-format attachments: [{ filename, content }]
 *                                        Only honoured when EMAIL_PROVIDER=resend (or overflow).
 *                                        SES attachment support requires MIME encoding — not yet implemented.
 * @param {string} [params.bcc]         - BCC address. Only honoured when EMAIL_PROVIDER=resend (or overflow).
 * @param {'cold'|'transactional'|'auth'} [params.kind='cold']
 *                                        - 'cold': prospecting/outreach (paused by reputation tier ≥ elevated)
 *                                        - 'transactional': receipts, reports (only paused on state=all)
 *                                        - 'auth': magic-link login emails — NEVER paused; customers must be
 *                                          able to log in even when all other sends are paused (DR-190)
 * @returns {Promise<{ id: string }>}
 */
export async function sendEmail({ from, to, subject, html, text, headers, tags, replyTo, attachments, bcc, kind }) {
  // Reputation pause check — set by check-ses-reputation cron.
  // 'kind' lets transactional sends bypass a 'cold' pause; defaults to 'cold'
  // (the safe default — anything that doesn't pass kind:'transactional' is
  // treated as cold outreach).
  const pause = readPauseState();
  // kind='auth' is never paused — customers must be able to log in even when all sends are paused (DR-190).
  if (kind !== 'auth') {
    if (pause.state === 'all') {
      throw new Error(
        `Email paused (state=all): ${pause.reason || 'no reason recorded'}. ` +
        `Edit ${PAUSE_FILE} to reset.`
      );
    }
    if (pause.state === 'cold' && kind !== 'transactional') {
      throw new Error(
        `Cold outreach paused (state=cold): ${pause.reason || 'no reason recorded'}. ` +
        `Pass { kind: 'transactional' } to bypass for transactional sends.`
      );
    }
  }

  const provider = process.env.EMAIL_PROVIDER || 'resend';

  if (provider === 'ses') {
    if (sesOverLimit()) {
      if (!process.env.RESEND_API_KEY) {
        throw new Error(
          `SES daily warmup limit (${process.env.SES_WARMUP_DAILY_LIMIT}) reached and RESEND_API_KEY is not set for overflow`
        );
      }
      return sendViaResend({ from, to, subject, html, text, headers, tags, replyTo, attachments, bcc });
    }
    return sendViaSes({ from, to, subject, html, text, headers, tags, replyTo });
  }

  return sendViaResend({ from, to, subject, html, text, headers, tags, replyTo, attachments, bcc });
}

export default { sendEmail };
