/**
 * archive-uploader.js — Drain local spool + DB-fallback scanner (DR-223)
 *
 * Exports runArchiveUploader() — registered as a 333Method cron handler
 * (ops.cron_jobs task_key='archiveUploader', 1-minute interval).
 *
 * Each tick does two things:
 *
 *   1. Spool drain — reads ~/.local/state/mmo-comms-archive/spool.jsonl and
 *      uploads any pending blobs to S3 with COMPLIANCE Object Lock + SSE-KMS.
 *      On success, removes the entry + its blob file (compacts the spool).
 *      On S3 failure, leaves the entry in place for the next tick.
 *
 *   2. DB-fallback scan — queries four tables for rows where s3_archive_key IS NULL
 *      (rows written by paths that don't touch the spool, e.g. Cloudflare Workers):
 *        m333.messages       — 333Method outbound email / SMS
 *        msgs.messages       — 2Step outbound email / SMS
 *        crai.messages       — CRAI inbound + outbound (all channels)
 *        msgs.ses_events     — SES bounce / complaint / delivery events
 *      Constructs a JSON archive record from the row, PUTs it to S3, then sets
 *      s3_archive_key + archived_at on the row.
 *
 * Required env vars:
 *   ARCHIVE_AWS_ACCESS_KEY_ID      — write-only IAM user (mmo-comms-archive-writer)
 *   ARCHIVE_AWS_SECRET_ACCESS_KEY  — corresponding secret
 *
 * Optional env vars:
 *   ARCHIVE_S3_BUCKET    — bucket name (default: mmo-comms-archive)
 *   ARCHIVE_S3_REGION    — AWS region (default: ap-southeast-2)
 *   ARCHIVE_SPOOL_DIR    — spool root (default: ~/.local/state/mmo-comms-archive)
 */

import { S3Client, PutObjectCommand } from '@aws-sdk/client-s3';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { config as dotenvConfig } from 'dotenv';
import { getAll, getOne, run } from '../../333Method/src/utils/db.js';

// Load mmo-platform's own .env files so ARCHIVE_* vars are available when this
// module runs inside the 333Method cron process (which only loads 333Method/.env*).
// dotenv never overwrites already-set env vars, so shell/CI overrides are respected.
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const mmoRoot = path.resolve(__dirname, '..');
dotenvConfig({ path: path.join(mmoRoot, '.env'),        quiet: true });
dotenvConfig({ path: path.join(mmoRoot, '.env.secrets'), quiet: true });

// ── Constants ──────────────────────────────────────────────────────────────

/** 7-year Object Lock retention (2557 days, accounting for 2 leap years). */
const RETENTION_MS = 2557 * 24 * 60 * 60 * 1000;

/** Max rows per DB-fallback scan table per tick — avoids long-running ticks. */
const DB_SCAN_LIMIT = 500;

// ── Spool helpers ──────────────────────────────────────────────────────────

function getSpoolDir() {
  return process.env.ARCHIVE_SPOOL_DIR
    || path.join(os.homedir(), '.local', 'state', 'mmo-comms-archive');
}
function getSpoolFile() { return path.join(getSpoolDir(), 'spool.jsonl'); }
function getBlobsDir()  { return path.join(getSpoolDir(), 'blobs'); }

// ── S3 client (lazy singleton) ─────────────────────────────────────────────

let _s3 = null;

function getS3() {
  if (!_s3) {
    _s3 = new S3Client({
      region: process.env.ARCHIVE_S3_REGION || 'ap-southeast-2',
      credentials: {
        accessKeyId:     process.env.ARCHIVE_AWS_ACCESS_KEY_ID,
        secretAccessKey: process.env.ARCHIVE_AWS_SECRET_ACCESS_KEY,
      },
    });
  }
  return _s3;
}

function getBucket() {
  return process.env.ARCHIVE_S3_BUCKET || 'mmo-comms-archive';
}

/** PUT a buffer to S3 with COMPLIANCE Object Lock + SSE-KMS. Throws on error. */
async function putToS3(key, body, contentType) {
  const retainUntil = new Date(Date.now() + RETENTION_MS);
  await getS3().send(new PutObjectCommand({
    Bucket:                       getBucket(),
    Key:                          key,
    Body:                         body,
    ContentType:                  contentType,
    ServerSideEncryption:         'aws:kms',
    ObjectLockMode:               'COMPLIANCE',
    ObjectLockRetainUntilDate:    retainUntil,
  }));
}

// ── S3 key builder ─────────────────────────────────────────────────────────

/**
 * Build a deterministic, sortable S3 key for a DB-fallback row.
 * Mirrors the schema used by archive.js (but uses a DB timestamp instead of now()).
 *
 * @param {string}      channel    - 'email' | 'sms'
 * @param {string}      project    - slug, e.g. '333method', '2step', 'crai'
 * @param {string}      direction  - 'outbound' | 'inbound'
 * @param {Date|string} ts         - row timestamp (created_at / sent_at / received_at)
 * @param {string|null} sid        - SES message ID or Twilio SID (optional)
 * @param {string}      ext        - file extension without dot ('json', 'eml')
 * @returns {string}
 */
function buildDbS3Key(channel, project, direction, ts, sid, ext) {
  const dt = ts instanceof Date ? ts : new Date(ts);
  const isoTs = dt.toISOString().replace(/\.\d{3}Z$/, 'Z');
  const yyyy  = dt.getUTCFullYear();
  const mm    = String(dt.getUTCMonth() + 1).padStart(2, '0');
  const dd    = String(dt.getUTCDate()).padStart(2, '0');
  const hash  = randomBytes(6).toString('hex');
  const sidSuffix = sid ? `_${String(sid).slice(0, 20)}` : '';
  return `${channel}/${project}/${direction}/${yyyy}/${mm}/${dd}/${isoTs}_${hash}${sidSuffix}.${ext}`;
}

// ── Spool drain ────────────────────────────────────────────────────────────

/**
 * Read spool.jsonl, upload pending blobs to S3, compact the file.
 * @returns {{ uploaded: number, remaining: number, errors: number }}
 */
async function drainSpool() {
  const spoolFile = getSpoolFile();
  const blobsDir  = getBlobsDir();

  if (!fs.existsSync(spoolFile)) {
    return { uploaded: 0, remaining: 0, errors: 0 };
  }

  const raw = fs.readFileSync(spoolFile, 'utf8');
  const lines = raw.split('\n').filter(Boolean);
  if (lines.length === 0) return { uploaded: 0, remaining: 0, errors: 0 };

  let uploaded = 0;
  let errors   = 0;
  const keepLines = [];

  for (const line of lines) {
    let entry;
    try {
      entry = JSON.parse(line);
    } catch {
      console.error('[archive-uploader] Corrupt spool entry — dropping');
      continue;
    }

    // Already uploaded in a prior tick (shouldn't happen often, but clean it up).
    if (entry.s3Uploaded) continue;

    const blobPath = path.join(blobsDir, entry.blobFile);
    if (!fs.existsSync(blobPath)) {
      console.error(`[archive-uploader] Blob missing for ${entry.id} (${entry.blobFile}) — dropping spool entry`);
      continue;
    }

    try {
      const body = fs.readFileSync(blobPath);
      await putToS3(entry.key, body, entry.contentType || 'application/octet-stream');

      // Upload .meta.json sidecar alongside .eml blobs (email archive only).
      if (entry.key.endsWith('.eml') && entry.metadata) {
        const metaKey  = entry.key.replace(/\.eml$/, '.meta.json');
        const metaBody = Buffer.from(JSON.stringify({
          archiveVersion: 1,
          project:        entry.project,
          channel:        entry.channel,
          direction:      entry.direction,
          capturedAt:     entry.spooledAt,
          ...entry.metadata,
        }));
        await putToS3(metaKey, metaBody, 'application/json');
      }

      // Clean up the blob file on success.
      try { fs.unlinkSync(blobPath); } catch { /* ignore if already removed */ }
      uploaded++;
    } catch (err) {
      console.error(`[archive-uploader] S3 PUT failed for spool entry ${entry.id}: ${err.message}`);
      keepLines.push(line); // leave in spool for retry
      errors++;
    }
  }

  // Compact the spool file — write back only the failed entries.
  if (keepLines.length === 0) {
    try { fs.unlinkSync(spoolFile); } catch { /* already gone */ }
  } else {
    fs.writeFileSync(spoolFile, keepLines.join('\n') + '\n');
  }

  return { uploaded, remaining: keepLines.length, errors };
}

// ── DB-fallback scans ──────────────────────────────────────────────────────

/**
 * Scan m333.messages (333Method) for unarchived outbound email / SMS rows.
 * contact_method values: 'sms' | 'email' | 'form' | 'x' | 'linkedin'
 */
async function scanM333Messages() {
  const rows = await getAll(
    `SELECT id, contact_method, contact_uri, email_id, twilio_sid,
            rendered_body, rendered_subject, message_body, subject_line,
            sent_at, created_at
     FROM m333.messages
     WHERE s3_archive_key IS NULL AND created_at < now() - interval '10 seconds'
     ORDER BY created_at
     LIMIT $1`,
    [DB_SCAN_LIMIT]
  );

  let uploaded = 0;
  let errors   = 0;

  for (const row of rows) {
    try {
      const channel = row.contact_method === 'sms' ? 'sms' : 'email';
      const ts      = row.sent_at || row.created_at;
      const sid     = row.twilio_sid || row.email_id || null;
      const key     = buildDbS3Key(channel, '333method', 'outbound', ts, sid, 'json');

      const body = Buffer.from(JSON.stringify({
        archiveVersion:      1,
        project:             '333method',
        channel,
        direction:           'outbound',
        contactUri:          row.contact_uri,
        emailId:             row.email_id,
        twilioSid:           row.twilio_sid,
        renderedBody:        row.rendered_body || row.message_body,
        renderedSubject:     row.rendered_subject || row.subject_line,
        sentAt:              row.sent_at,
        reconstructedFromDb: !row.rendered_body,
      }));

      await putToS3(key, body, 'application/json');
      await run(
        `UPDATE m333.messages SET s3_archive_key = $1, archived_at = now() WHERE id = $2`,
        [key, row.id]
      );
      uploaded++;
    } catch (err) {
      console.error(`[archive-uploader] m333.messages id=${row.id}: ${err.message}`);
      errors++;
    }
  }

  return { uploaded, errors };
}

/**
 * Scan msgs.messages (2Step and other shared-schema projects) for unarchived rows.
 * Has a `project` column (e.g. '2step').
 */
async function scanMsgsMessages() {
  const rows = await getAll(
    `SELECT id, project, contact_method, contact_uri, email_id, twilio_sid,
            rendered_body, rendered_subject, message_body, subject_line,
            sent_at, created_at
     FROM msgs.messages
     WHERE s3_archive_key IS NULL AND created_at < now() - interval '10 seconds'
     ORDER BY created_at
     LIMIT $1`,
    [DB_SCAN_LIMIT]
  );

  let uploaded = 0;
  let errors   = 0;

  for (const row of rows) {
    try {
      const channel = row.contact_method === 'sms' ? 'sms' : 'email';
      const project = row.project || '2step';
      const ts      = row.sent_at || row.created_at;
      const sid     = row.twilio_sid || row.email_id || null;
      const key     = buildDbS3Key(channel, project, 'outbound', ts, sid, 'json');

      const body = Buffer.from(JSON.stringify({
        archiveVersion:      1,
        project,
        channel,
        direction:           'outbound',
        contactUri:          row.contact_uri,
        emailId:             row.email_id,
        twilioSid:           row.twilio_sid,
        renderedBody:        row.rendered_body || row.message_body,
        renderedSubject:     row.rendered_subject || row.subject_line,
        sentAt:              row.sent_at,
        reconstructedFromDb: !row.rendered_body,
      }));

      await putToS3(key, body, 'application/json');
      await run(
        `UPDATE msgs.messages SET s3_archive_key = $1, archived_at = now() WHERE id = $2`,
        [key, row.id]
      );
      uploaded++;
    } catch (err) {
      console.error(`[archive-uploader] msgs.messages id=${row.id}: ${err.message}`);
      errors++;
    }
  }

  return { uploaded, errors };
}

/**
 * Scan crai.messages (ContactReplyAI inbound + outbound) for unarchived rows.
 * Written by the Cloudflare Worker; uploader is the primary archive path.
 */
async function scanCraiMessages() {
  const rows = await getAll(
    `SELECT id, channel, direction, body, external_id, sender, created_at
     FROM crai.messages
     WHERE s3_archive_key IS NULL AND created_at < now() - interval '10 seconds'
     ORDER BY created_at
     LIMIT $1`,
    [DB_SCAN_LIMIT]
  );

  let uploaded = 0;
  let errors   = 0;

  for (const row of rows) {
    try {
      const channel   = row.channel   || 'sms';
      const direction = row.direction || 'outbound';
      const sid       = row.external_id || null;
      const key       = buildDbS3Key(channel, 'crai', direction, row.created_at, sid, 'json');

      const body = Buffer.from(JSON.stringify({
        archiveVersion: 1,
        project:        'crai',
        channel,
        direction,
        body:           row.body,
        externalId:     row.external_id,
        sender:         row.sender,
        createdAt:      row.created_at,
      }));

      await putToS3(key, body, 'application/json');
      await run(
        `UPDATE crai.messages SET s3_archive_key = $1, archived_at = now() WHERE id = $2`,
        [key, row.id]
      );
      uploaded++;
    } catch (err) {
      console.error(`[archive-uploader] crai.messages id=${row.id}: ${err.message}`);
      errors++;
    }
  }

  return { uploaded, errors };
}

/**
 * Scan msgs.ses_events (SES bounce / complaint / delivery notifications) for unarchived rows.
 * Written by the Cloudflare email-webhook Worker; full payload JSONB is the archive content.
 */
async function scanSesEvents() {
  const rows = await getAll(
    `SELECT id, event_type, ses_message_id, recipient, payload, received_at
     FROM msgs.ses_events
     WHERE s3_archive_key IS NULL AND received_at < now() - interval '10 seconds'
     ORDER BY received_at
     LIMIT $1`,
    [DB_SCAN_LIMIT]
  );

  let uploaded = 0;
  let errors   = 0;

  for (const row of rows) {
    try {
      const sid = row.ses_message_id || null;
      const key = buildDbS3Key('email', '333method', 'inbound', row.received_at, sid, 'json');

      // The full SNS notification payload is already the canonical archive content.
      const body = Buffer.from(JSON.stringify(row.payload));

      await putToS3(key, body, 'application/json');
      await run(
        `UPDATE msgs.ses_events SET s3_archive_key = $1, archived_at = now() WHERE id = $2`,
        [key, row.id]
      );
      uploaded++;
    } catch (err) {
      console.error(`[archive-uploader] msgs.ses_events id=${row.id}: ${err.message}`);
      errors++;
    }
  }

  return { uploaded, errors };
}

// ── Lag measurement ────────────────────────────────────────────────────────

/**
 * Return the age in seconds of the oldest unarchived row across all four tables.
 * Returns 0 if all tables are fully archived.
 */
async function measureLagSeconds() {
  try {
    const result = await getOne(
      `SELECT COALESCE(
         EXTRACT(EPOCH FROM (now() - MIN(ts)))::int,
         0
       ) AS lag_s
       FROM (
         SELECT MIN(created_at)  AS ts FROM m333.messages  WHERE s3_archive_key IS NULL
         UNION ALL
         SELECT MIN(created_at)  AS ts FROM msgs.messages  WHERE s3_archive_key IS NULL
         UNION ALL
         SELECT MIN(created_at)  AS ts FROM crai.messages  WHERE s3_archive_key IS NULL
         UNION ALL
         SELECT MIN(received_at) AS ts FROM msgs.ses_events WHERE s3_archive_key IS NULL
       ) sub WHERE ts IS NOT NULL`,
      []
    );
    return result?.lag_s ?? 0;
  } catch (err) {
    console.error(`[archive-uploader] lag measurement failed: ${err.message}`);
    return 0;
  }
}

// ── Main entry point ───────────────────────────────────────────────────────

/**
 * Drain the local spool and scan DB tables for unarchived rows.
 * Called by the 333Method cron framework every minute.
 *
 * @returns {{
 *   uploaded:       number,  — total S3 objects written this tick
 *   spoolRemaining: number,  — pending entries still in local spool after drain
 *   lagSeconds:     number,  — age of oldest unarchived row across all tables
 *   s3Errors:       number,  — total S3 PUT failures this tick
 * }}
 */
export async function runArchiveUploader() {
  if (!process.env.ARCHIVE_AWS_ACCESS_KEY_ID || !process.env.ARCHIVE_AWS_SECRET_ACCESS_KEY) {
    console.error('[archive-uploader] Missing ARCHIVE_AWS_ACCESS_KEY_ID or ARCHIVE_AWS_SECRET_ACCESS_KEY — skipping');
    return { uploaded: 0, spoolRemaining: 0, lagSeconds: 0, s3Errors: 0 };
  }

  // 1. Drain local spool.
  const spool = await drainSpool();

  // 2. DB-fallback scans (run sequentially to avoid hammering the DB).
  const m333  = await scanM333Messages();
  const msgs  = await scanMsgsMessages();
  const crai  = await scanCraiMessages();
  const ses   = await scanSesEvents();

  const uploaded = spool.uploaded + m333.uploaded + msgs.uploaded + crai.uploaded + ses.uploaded;
  const s3Errors = spool.errors   + m333.errors   + msgs.errors   + crai.errors   + ses.errors;

  // 3. Measure how far behind the archive is.
  const lagSeconds = await measureLagSeconds();

  return {
    uploaded,
    spoolRemaining: spool.remaining,
    lagSeconds,
    s3Errors,
  };
}
