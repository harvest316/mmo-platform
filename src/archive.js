/**
 * Legal-grade communications archive capture module (DR-222)
 *
 * Every email and SMS send/receive routes through this module before being dispatched
 * to SES or Twilio. Two things happen synchronously per capture call:
 *
 *   1. fsync to local spool at ~/.local/state/mmo-comms-archive/ (or ARCHIVE_SPOOL_DIR)
 *      Fail-CLOSED: if the spool write fails, the error propagates and the caller
 *      must NOT proceed with the send.
 *
 *   2. Fire-and-forget PUT to S3 with Object Lock COMPLIANCE mode + SSE-KMS.
 *      Fail-OPEN: if S3 is temporarily unavailable, the error is logged and the
 *      archive-uploader cron drains the spool later.
 *
 * S3 key schema (deterministic, sortable, queryable):
 *   <channel>/<project>/<direction>/<yyyy>/<mm>/<dd>/<iso8601>_<hash12>[_<sid>].<ext>
 *
 * Spool entry fields (read by archive-uploader.js):
 *   { id, channel, project, direction, key, blobFile, contentType, metadata, spooledAt, s3Uploaded }
 *
 * Required env vars:
 *   ARCHIVE_AWS_ACCESS_KEY_ID      — write-only IAM user (mmo-comms-archive-writer)
 *   ARCHIVE_AWS_SECRET_ACCESS_KEY  — corresponding secret
 *
 * Optional env vars:
 *   ARCHIVE_S3_BUCKET    — bucket name (default: mmo-comms-archive)
 *   ARCHIVE_S3_REGION    — AWS region (default: ap-southeast-2)
 *   ARCHIVE_SPOOL_DIR    — spool root (default: ~/.local/state/mmo-comms-archive)
 *                          Override in tests to use a temp directory.
 */

import { S3Client, PutObjectCommand } from '@aws-sdk/client-s3';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { randomBytes } from 'node:crypto';

// ── Spool directory ────────────────────────────────────────────────────────

function getSpoolDir() {
  return process.env.ARCHIVE_SPOOL_DIR
    || path.join(os.homedir(), '.local', 'state', 'mmo-comms-archive');
}

function getSpoolFile() { return path.join(getSpoolDir(), 'spool.jsonl'); }
function getBlobsDir()  { return path.join(getSpoolDir(), 'blobs'); }

function ensureSpoolDirs() {
  fs.mkdirSync(getBlobsDir(), { recursive: true });
}

// ── S3 client (lazy singleton) ─────────────────────────────────────────────

let _s3Client = null;

function getArchiveS3Client() {
  if (!_s3Client) {
    if (!process.env.ARCHIVE_AWS_ACCESS_KEY_ID) {
      throw new Error('[archive] Missing env var: ARCHIVE_AWS_ACCESS_KEY_ID');
    }
    if (!process.env.ARCHIVE_AWS_SECRET_ACCESS_KEY) {
      throw new Error('[archive] Missing env var: ARCHIVE_AWS_SECRET_ACCESS_KEY');
    }
    _s3Client = new S3Client({
      region: process.env.ARCHIVE_S3_REGION || 'ap-southeast-2',
      credentials: {
        accessKeyId: process.env.ARCHIVE_AWS_ACCESS_KEY_ID,
        secretAccessKey: process.env.ARCHIVE_AWS_SECRET_ACCESS_KEY,
      },
    });
  }
  return _s3Client;
}

// ── S3 key builder ─────────────────────────────────────────────────────────

function buildS3Key(channel, project, direction, now, sid, ext) {
  // Strip milliseconds for cleaner keys: 2026-04-14T03:22:11Z
  const ts = now.toISOString().replace(/\.\d{3}Z$/, 'Z');
  const yyyy = now.getUTCFullYear();
  const mm = String(now.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(now.getUTCDate()).padStart(2, '0');
  const hash = randomBytes(6).toString('hex'); // 12 hex chars for uniqueness
  const sidSuffix = sid ? `_${String(sid).slice(0, 20)}` : '';
  return `${channel}/${project}/${direction}/${yyyy}/${mm}/${dd}/${ts}_${hash}${sidSuffix}.${ext}`;
}

// ── Spool write (synchronous + fsync) ──────────────────────────────────────

function spoolWrite(entry, blobPath, blobData) {
  ensureSpoolDirs();

  // Write blob file first, then spool index entry.
  // Both are fsynced so a power failure between the two leaves a recoverable state
  // (orphan blob with no spool entry → uploader ignores; orphan spool entry with
  // no blob → uploader logs and skips).
  const blobFd = fs.openSync(blobPath, 'w');
  try {
    const buf = Buffer.isBuffer(blobData) ? blobData : Buffer.from(blobData);
    fs.writeSync(blobFd, buf);
    fs.fsyncSync(blobFd);
  } finally {
    fs.closeSync(blobFd);
  }

  const line = JSON.stringify(entry) + '\n';
  const spoolFd = fs.openSync(getSpoolFile(), 'a');
  try {
    fs.writeSync(spoolFd, line);
    fs.fsyncSync(spoolFd);
  } finally {
    fs.closeSync(spoolFd);
  }
}

// ── S3 PUT (fire-and-forget) ───────────────────────────────────────────────

// Retain until ~7 years from now (2557 days ≈ 7y accounting for leap years).
// The bucket already has a 7-year default retention, so this is belt-and-suspenders.
const RETENTION_MS = 2557 * 24 * 60 * 60 * 1000;

function putToS3Async(bucket, key, body, contentType) {
  const retainUntil = new Date(Date.now() + RETENTION_MS);

  const cmd = new PutObjectCommand({
    Bucket: bucket,
    Key: key,
    Body: body,
    ContentType: contentType,
    ServerSideEncryption: 'aws:kms',
    ObjectLockMode: 'COMPLIANCE',
    ObjectLockRetainUntilDate: retainUntil,
  });

  // Errors are logged, not thrown — S3 outages must not halt sends.
  getArchiveS3Client().send(cmd).catch((err) => {
    console.error(`[archive] S3 PUT failed (${key}): ${err.message} — spool retained`);
  });
}

function getBucket() {
  return process.env.ARCHIVE_S3_BUCKET || 'mmo-comms-archive';
}

// ── Credential pre-check ───────────────────────────────────────────────────
// Called at the start of each capture function so misconfiguration is caught
// before any spool write, not swallowed inside the fire-and-forget.

function assertArchiveCreds() {
  if (!process.env.ARCHIVE_AWS_ACCESS_KEY_ID) {
    throw new Error('[archive] Missing env var: ARCHIVE_AWS_ACCESS_KEY_ID — archive is not configured');
  }
  if (!process.env.ARCHIVE_AWS_SECRET_ACCESS_KEY) {
    throw new Error('[archive] Missing env var: ARCHIVE_AWS_SECRET_ACCESS_KEY — archive is not configured');
  }
}

// ── Public API ─────────────────────────────────────────────────────────────

/**
 * Capture an outbound email before it is sent via SES.
 *
 * Call this BEFORE the SES send. On success it writes the raw MIME to the spool
 * and fires a best-effort S3 PUT. The returned S3 key should be stored in
 * msgs.messages.s3_archive_key after the send succeeds.
 *
 * @param {object}        params
 * @param {string}        params.project   - Lowercase project slug: '333method', '2step', 'crai', 'auditandfix'
 * @param {string|Buffer} params.rawMime   - Full RFC 5322 MIME document (rendered, ready to send)
 * @param {object}        [params.metadata] - { sesMessageId?, templateId?, campaignTag?, siteId?, approver?, configSet? }
 * @returns {string} S3 key — store in msgs.messages.s3_archive_key
 * @throws If spool write fails or archive credentials are missing (fail-closed — do not send)
 */
export async function captureOutboundEmail({ project, rawMime, metadata = {} }) {
  assertArchiveCreds();

  const now = new Date();
  const entryId = randomBytes(8).toString('hex');
  // SES MessageId is not available until after the send; include it if the caller
  // pre-populates metadata.sesMessageId (e.g. when retrying with a known ID).
  const key = buildS3Key('email', project, 'outbound', now, metadata.sesMessageId || null, 'eml');
  const blobFile = `${entryId}.eml`;
  const blobPath = path.join(getBlobsDir(), blobFile);

  const entry = {
    id: entryId,
    channel: 'email',
    project,
    direction: 'outbound',
    key,
    blobFile,
    contentType: 'message/rfc822',
    metadata: { ...metadata, capturedAt: now.toISOString() },
    spooledAt: now.toISOString(),
    s3Uploaded: false,
  };

  const mimeBytes = Buffer.isBuffer(rawMime) ? rawMime : Buffer.from(rawMime);

  // Step 1 — durable spool write (fail-closed: error propagates to caller)
  spoolWrite(entry, blobPath, mimeBytes);

  // Step 2 — best-effort S3 PUT (fail-open: error is logged, not thrown)
  putToS3Async(getBucket(), key, mimeBytes, 'message/rfc822');

  // Sidecar .meta.json alongside the .eml — fire-and-forget; uploader can reconstruct
  const metaKey = key.replace(/\.eml$/, '.meta.json');
  const metaBytes = Buffer.from(JSON.stringify({
    project,
    capturedAt: now.toISOString(),
    archiveVersion: 1,
    ...metadata,
  }));
  putToS3Async(getBucket(), metaKey, metaBytes, 'application/json');

  return key;
}

/**
 * Capture an inbound email event (SNS bounce / complaint / delivery notification).
 *
 * Cloudflare Workers (email-webhook) cannot write to the spool; they persist to
 * msgs.ses_events instead. This function is for the Node-side path only.
 *
 * @param {object}         params
 * @param {string}         params.project
 * @param {string|object}  params.snsPayload - Full SNS message body (string or parsed object)
 * @param {object}         [params.metadata]  - { eventType?, sesMessageId?, recipient? }
 * @returns {string} S3 key
 * @throws If spool write fails or credentials are missing (fail-closed)
 */
export async function captureInboundEmailEvent({ project, snsPayload, metadata = {} }) {
  assertArchiveCreds();

  const now = new Date();
  const entryId = randomBytes(8).toString('hex');
  const key = buildS3Key('email', project, 'inbound', now, metadata.sesMessageId || null, 'json');
  const blobFile = `${entryId}.json`;
  const blobPath = path.join(getBlobsDir(), blobFile);

  const payloadStr = typeof snsPayload === 'string' ? snsPayload : JSON.stringify(snsPayload);
  const payloadBytes = Buffer.from(payloadStr);

  const entry = {
    id: entryId,
    channel: 'email',
    project,
    direction: 'inbound',
    key,
    blobFile,
    contentType: 'application/json',
    metadata: { ...metadata, capturedAt: now.toISOString() },
    spooledAt: now.toISOString(),
    s3Uploaded: false,
  };

  spoolWrite(entry, blobPath, payloadBytes);
  putToS3Async(getBucket(), key, payloadBytes, 'application/json');

  return key;
}

/**
 * Capture an outbound SMS send (call immediately after Twilio returns the SID).
 *
 * @param {object} params
 * @param {string} params.project
 * @param {string} params.body       - Rendered (post-spintax) message body
 * @param {string} params.from       - Sender number or alphanumeric ID
 * @param {string} params.to         - Recipient number (E.164)
 * @param {string} [params.twilioSid] - Twilio MessageSid (strongly recommended)
 * @param {object} [params.metadata]  - { templateId?, campaignTag?, siteId?, approver? }
 * @returns {string} S3 key — store in msgs.messages.s3_archive_key
 * @throws If spool write fails or credentials are missing (fail-closed)
 */
export async function captureOutboundSms({ project, body, from, to, twilioSid, metadata = {} }) {
  assertArchiveCreds();

  const now = new Date();
  const entryId = randomBytes(8).toString('hex');
  const key = buildS3Key('sms', project, 'outbound', now, twilioSid || null, 'json');
  const blobFile = `${entryId}.json`;
  const blobPath = path.join(getBlobsDir(), blobFile);

  const record = JSON.stringify({
    project,
    from,
    to,
    bodyRendered: body,
    twilioSid: twilioSid || null,
    sentAt: now.toISOString(),
    archiveVersion: 1,
    ...metadata,
  });
  const recordBytes = Buffer.from(record);

  const entry = {
    id: entryId,
    channel: 'sms',
    project,
    direction: 'outbound',
    key,
    blobFile,
    contentType: 'application/json',
    metadata: { from, to, twilioSid: twilioSid || null, ...metadata, capturedAt: now.toISOString() },
    spooledAt: now.toISOString(),
    s3Uploaded: false,
  };

  spoolWrite(entry, blobPath, recordBytes);
  putToS3Async(getBucket(), key, recordBytes, 'application/json');

  return key;
}

/**
 * Capture an inbound SMS received from Twilio (poller or webhook path).
 *
 * Cloudflare Workers (CRAI) rely on the archive-uploader DB-fallback path instead.
 * This function is for the Node-side poller.
 *
 * @param {object}         params
 * @param {string}         params.project
 * @param {string|object}  params.rawTwilioPayload - Full Twilio webhook / poller payload
 * @param {object}         [params.metadata]        - { twilioSid?, from?, to? }
 * @returns {string} S3 key
 * @throws If spool write fails or credentials are missing (fail-closed)
 */
export async function captureInboundSms({ project, rawTwilioPayload, metadata = {} }) {
  assertArchiveCreds();

  const now = new Date();
  const entryId = randomBytes(8).toString('hex');
  const key = buildS3Key('sms', project, 'inbound', now, metadata.twilioSid || null, 'json');
  const blobFile = `${entryId}.json`;
  const blobPath = path.join(getBlobsDir(), blobFile);

  const payloadStr = typeof rawTwilioPayload === 'string'
    ? rawTwilioPayload
    : JSON.stringify(rawTwilioPayload);
  const payloadBytes = Buffer.from(payloadStr);

  const entry = {
    id: entryId,
    channel: 'sms',
    project,
    direction: 'inbound',
    key,
    blobFile,
    contentType: 'application/json',
    metadata: { ...metadata, capturedAt: now.toISOString() },
    spooledAt: now.toISOString(),
    s3Uploaded: false,
  };

  spoolWrite(entry, blobPath, payloadBytes);
  putToS3Async(getBucket(), key, payloadBytes, 'application/json');

  return key;
}

// ── Test helpers ───────────────────────────────────────────────────────────

/** Reset the S3 client singleton. For use in tests only. */
export function _resetS3Client() {
  _s3Client = null;
}
