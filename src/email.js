/**
 * Shared email transport module
 *
 * Sends all email via AWS SES (SESv2).
 * Every send is captured to the legal-grade WORM archive (DR-223) before
 * the SES call — fail-closed: if the archive spool write fails, the send is
 * aborted. S3 upload is best-effort; the archive-uploader cron drains the spool.
 *
 * Integration:
 *   - 333Method: src/outreach/email.js
 *   - 2Step: src/stages/outreach.js
 *   - ContactReplyAI: src/email.js
 *
 * Imported via: ../../../mmo-platform/src/email.js
 */

import { SESv2Client, SendEmailCommand } from '@aws-sdk/client-sesv2';
import { captureOutboundEmail } from './archive.js';
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

// ── Config set helper ──────────────────────────────────────────────────────

/**
 * Resolve the SES configuration set name from the trackEngagement flag.
 * Centralised here so archive metadata and SES send always use the same set.
 */
function getConfigSet(trackEngagement) {
  return trackEngagement === false
    ? (process.env.SES_CONFIGURATION_SET_NOTRACK || 'mmo-outbound-notrack')
    : (process.env.SES_CONFIGURATION_SET || 'mmo-outbound');
}

// ── MIME document builder (archive copy) ──────────────────────────────────
//
// SES is called with Content.Simple (unchanged — preserves existing test
// coverage).  We separately build a standards-compliant RFC 5322 MIME
// document for the archive so the legal record contains the full rendered
// content, headers, and config-set name.  The two paths carry the same
// content; they are not byte-identical.

function buildMimeDocument({ from, to, subject, html, text, headers, replyTo, configSet }) {
  const boundary = `mmo_${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`;
  const now = new Date();
  const msgId = `<${now.getTime()}.${Math.random().toString(36).slice(2)}@mmo-archive.local>`;

  const headerLines = [
    'MIME-Version: 1.0',
    `Date: ${now.toUTCString()}`,
    `Message-ID: ${msgId}`,
    `From: ${from}`,
    `To: ${to}`,
  ];
  if (replyTo) headerLines.push(`Reply-To: ${replyTo}`);
  headerLines.push(`Subject: ${subject}`);
  if (headers && typeof headers === 'object') {
    for (const [k, v] of Object.entries(headers)) headerLines.push(`${k}: ${v}`);
  }
  if (configSet) headerLines.push(`X-Mmo-Config-Set: ${configSet}`);

  if (html && text) {
    headerLines.push(`Content-Type: multipart/alternative; boundary="${boundary}"`);
    return (
      headerLines.join('\r\n') + '\r\n' +
      `\r\n--${boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n` +
      Buffer.from(text, 'utf8').toString('base64') +
      `\r\n--${boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n` +
      Buffer.from(html, 'utf8').toString('base64') +
      `\r\n--${boundary}--`
    );
  }
  const body = text || html || '';
  headerLines.push(
    `Content-Type: ${html ? 'text/html' : 'text/plain'}; charset=UTF-8`,
    'Content-Transfer-Encoding: base64',
  );
  return headerLines.join('\r\n') + '\r\n\r\n' + Buffer.from(body, 'utf8').toString('base64');
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

async function sendViaSes({ from, to, subject, html, text, headers, tags, replyTo, bcc, configurationSet }) {
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
    ConfigurationSetName: configurationSet,
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
 * Send an email via AWS SES, capturing it to the legal-grade WORM archive
 * before dispatch (DR-223).
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
 * @param {boolean} [params.trackEngagement=true]
 *                                      - Whether to route through the engagement-tracked SES configuration
 *                                        set (open pixel + click rewriting). Pass `false` for image-less
 *                                        cold outreach to avoid injecting a 1×1 pixel that would be the only
 *                                        image and trigger text:image ratio spam heuristics. See DR-214.
 * @param {string} [params.project='unknown']
 *                                      - Lowercase project slug for archive key: '333method', '2step',
 *                                        'crai', 'auditandfix'. Default 'unknown' for backwards compat.
 * @returns {Promise<{ id: string, s3ArchiveKey: string }>}
 */
export async function sendEmail({ from, to, subject, html, text, headers, tags, replyTo, bcc, kind, trackEngagement, project = 'unknown' }) {
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

  // Compute config set once — used for both archive metadata and SES send.
  const configurationSet = getConfigSet(trackEngagement);

  // Build RFC 5322 MIME document for the legal archive (DR-223).
  // This is separate from the Content.Simple path used for the SES send so
  // existing SES behaviour is unchanged.
  const rawMime = buildMimeDocument({ from, to, subject, html, text, headers, replyTo, configSet: configurationSet });

  // Archive capture — fail-CLOSED: if the spool write fails, the send is aborted.
  // S3 PUT is fire-and-forget; uploader drains the spool within ~60s.
  const s3ArchiveKey = await captureOutboundEmail({
    project,
    rawMime,
    metadata: { configSet: configurationSet },
  });

  const { id } = await sendViaSes({ from, to, subject, html, text, headers, tags, replyTo, bcc, configurationSet });
  return { id, s3ArchiveKey };
}

export default { sendEmail };
