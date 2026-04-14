/**
 * Shared email transport module
 *
 * Sends all email via AWS SES (SESv2).
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

// ── SES send ───────────────────────────────────────────────────────────────

async function sendViaSes({ from, to, subject, html, text, headers, tags, replyTo, bcc }) {
  const command = new SendEmailCommand({
    FromEmailAddress: from,
    Destination: {
      ToAddresses: [to],
      BccAddresses: bcc ? [bcc] : undefined,
    },
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
    return { id: response.MessageId };
  } catch (err) {
    throw new Error(`SES send failed [${err.name || 'Error'}]: ${err.message}`);
  }
}

// ── Public API ─────────────────────────────────────────────────────────────

/**
 * Send an email via AWS SES.
 *
 * @param {object} params
 * @param {string} params.from        - Sender address
 * @param {string} params.to          - Recipient address
 * @param {string} params.subject     - Email subject
 * @param {string} [params.html]      - HTML body
 * @param {string} [params.text]      - Plain-text body
 * @param {object} [params.headers]   - Extra headers as { 'Header-Name': 'value' }
 * @param {object} [params.tags]      - Tracking tags as { key: 'value' }
 * @param {string} [params.replyTo]   - Reply-To address
 * @param {string} [params.bcc]       - BCC address
 * @param {'cold'|'transactional'|'auth'} [params.kind='cold']
 *                                      - 'cold': prospecting/outreach (paused by reputation tier ≥ elevated)
 *                                      - 'transactional': receipts, reports (only paused on state=all)
 *                                      - 'auth': magic-link login emails — NEVER paused; customers must be
 *                                        able to log in even when all other sends are paused (DR-190)
 * @returns {Promise<{ id: string }>}
 */
export async function sendEmail({ from, to, subject, html, text, headers, tags, replyTo, bcc, kind }) {
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

  return sendViaSes({ from, to, subject, html, text, headers, tags, replyTo, bcc });
}

export default { sendEmail };
