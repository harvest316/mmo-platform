#!/usr/bin/env node
/**
 * backfill-archive.js — One-time archive backfill for historical sends (DR-223)
 *
 * Uploads historical msgs.messages rows (email + SMS only) that predate the
 * DR-223 live-capture wiring. All records are marked reconstructed=true because
 * the exact rendered output cannot be recovered for pre-DR-223 sends.
 *
 * Run ONCE after the archive uploader has been green for 48 hours:
 *   node ~/code/mmo-platform/scripts/backfill-archive.js [--dry-run] [--batch=500]
 *
 * Exit codes:
 *   0 — complete (all rows processed or skipped)
 *   1 — fatal error (credentials missing, S3 bucket unreachable, DB error)
 *
 * Safe to re-run: rows with s3_archive_key already set are skipped.
 *
 * Scope: email + SMS only (form/linkedin/x are not in the archive contract).
 * Tables: msgs.messages only (crai.messages + msgs.ses_events had 0 rows at
 *         time of DR-223 implementation — uploader handles them going forward).
 */

import { S3Client, PutObjectCommand } from '@aws-sdk/client-s3';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { config as dotenvConfig } from 'dotenv';
import { getAll, run } from '../../333Method/src/utils/db.js';
import '../../333Method/src/utils/load-env.js';

// Also load mmo-platform's own .env so ARCHIVE_* vars are available.
// dotenv never overwrites already-set env vars.
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const mmoRoot = path.resolve(__dirname, '..');
dotenvConfig({ path: path.join(mmoRoot, '.env'),        quiet: true });
dotenvConfig({ path: path.join(mmoRoot, '.env.secrets'), quiet: true });

// ── Config ──────────────────────────────────────────────────────────────────

const DRY_RUN    = process.argv.includes('--dry-run');
const BATCH_SIZE = (() => {
  const a = process.argv.find(x => x.startsWith('--batch='));
  return a ? parseInt(a.split('=')[1], 10) : 500;
})();

const BUCKET     = process.env.ARCHIVE_S3_BUCKET  || 'mmo-comms-archive';
const REGION     = process.env.ARCHIVE_S3_REGION  || 'ap-southeast-2';
const KMS_KEY_ID = process.env.ARCHIVE_KMS_KEY_ID || 'alias/mmo-comms-archive-cmk';

/** 7-year retention — same constant as archive.js and archive-uploader.js. */
const RETENTION_MS = 2557 * 24 * 60 * 60 * 1000;

/** Channels we archive (others like form/linkedin/x are out of scope). */
const ARCHIVABLE_CHANNELS = new Set(['email', 'sms']);

// ── Credentials ─────────────────────────────────────────────────────────────

function getS3() {
  const accessKeyId     = process.env.ARCHIVE_AWS_ACCESS_KEY_ID;
  const secretAccessKey = process.env.ARCHIVE_AWS_SECRET_ACCESS_KEY;
  if (!accessKeyId || !secretAccessKey) {
    throw new Error(
      'ARCHIVE_AWS_ACCESS_KEY_ID and ARCHIVE_AWS_SECRET_ACCESS_KEY must be set'
    );
  }
  return new S3Client({
    region: REGION,
    credentials: { accessKeyId, secretAccessKey },
  });
}

// ── Key builder ─────────────────────────────────────────────────────────────

/**
 * Builds an S3 key from the archive key schema:
 *   <channel>/<project>/<direction>/<yyyy>/<mm>/<dd>/<iso8601>_<hash12>[_<sid>].<ext>
 */
function buildKey(channel, project, direction, ts, bodyStr, sid, ext) {
  const d   = new Date(ts);
  const iso = d.toISOString().replace(/\.\d{3}Z$/, 'Z').replace(/[:.]/g, '-');
  const datePrefix = [
    String(d.getUTCFullYear()),
    String(d.getUTCMonth() + 1).padStart(2, '0'),
    String(d.getUTCDate()).padStart(2, '0'),
  ].join('/');
  const hash12 = createHash('sha256').update(bodyStr).digest('hex').slice(0, 12);
  const sidPart = sid ? `_${sid}` : '';
  return `${channel}/${project}/${direction}/${datePrefix}/${iso}_${hash12}${sidPart}.${ext}`;
}

// ── S3 PUT helper ────────────────────────────────────────────────────────────

async function putToS3(s3, key, body, contentType, retainUntilDate) {
  await s3.send(new PutObjectCommand({
    Bucket:                        BUCKET,
    Key:                           key,
    Body:                          Buffer.isBuffer(body) ? body : Buffer.from(body, 'utf8'),
    ContentType:                   contentType,
    ObjectLockMode:                'COMPLIANCE',
    ObjectLockRetainUntilDate:     retainUntilDate,
    ServerSideEncryption:          'aws:kms',
    SSEKMSKeyId:                   KMS_KEY_ID,
    Metadata: {
      'x-mmo-archive-reconstructed': 'true',
    },
  }));
}

// ── Record builders ──────────────────────────────────────────────────────────

/**
 * Build a synthetic RFC 5322-like blob for a historical email row.
 * We don't have the raw MIME, so we synthesise one from available columns.
 * Marked as reconstructed so it can never be mistaken for an original capture.
 */
function buildEmailMime(row) {
  const messageId  = row.email_id
    ? `<${row.email_id}>`
    : `<reconstructed-${row.id}@${process.env.BRAND_DOMAIN}>`;
  const date       = row.sent_at
    ? new Date(row.sent_at).toUTCString()
    : new Date(row.created_at).toUTCString();
  const subject    = row.rendered_subject || row.subject_line || '(no subject)';
  const body       = row.rendered_body    || row.message_body || '';

  return [
    `Date: ${date}`,
    `Message-ID: ${messageId}`,
    `From: (reconstructed)`,
    `To: ${row.contact_uri}`,
    `Subject: ${subject}`,
    `MIME-Version: 1.0`,
    `Content-Type: text/plain; charset=UTF-8`,
    `X-Mmo-Archive-Reconstructed: true`,
    `X-Mmo-Original-Db-Id: ${row.id}`,
    `X-Mmo-Project: ${row.project}`,
    ``,
    body,
  ].join('\r\n');
}

/**
 * Build a JSON archive record for a historical SMS row.
 */
function buildSmsJson(row) {
  return JSON.stringify({
    archive_version:  '1',
    reconstructed:    true,
    db_id:            row.id,
    project:          row.project,
    channel:          'sms',
    direction:        row.direction,
    to:               row.contact_uri,
    body:             row.rendered_body || row.message_body || '',
    twilio_sid:       row.twilio_sid    || null,
    ses_message_id:   null,
    sent_at:          row.sent_at       || row.created_at,
    delivery_status:  row.delivery_status,
    template_id:      row.template_id   || null,
    campaign_tag:     row.campaign_tag  || null,
  }, null, 2);
}

// ── Main ─────────────────────────────────────────────────────────────────────

async function main() {
  console.log(`[backfill-archive] Starting${DRY_RUN ? ' (DRY RUN)' : ''} — batch=${BATCH_SIZE}`);

  let s3;
  try {
    s3 = getS3();
  } catch (err) {
    console.error(`[backfill-archive] FATAL: ${err.message}`);
    process.exit(1);
  }

  // Verify bucket is reachable by doing a dry run of a HEAD before processing
  if (!DRY_RUN) {
    try {
      // Just instantiate the command to confirm credentials parse — actual
      // reachability is confirmed on the first PutObject attempt.
      console.log(`[backfill-archive] S3 client initialised — bucket=${BUCKET}, region=${REGION}`);
    } catch (err) {
      console.error(`[backfill-archive] FATAL: S3 init failed: ${err.message}`);
      process.exit(1);
    }
  }

  let offset      = 0;
  let uploaded    = 0;
  let skipped     = 0;
  let errored     = 0;
  let sidMatched  = 0;
  let outOfScope  = 0;

  // eslint-disable-next-line no-constant-condition
  while (true) {
    const rows = await getAll(
      `SELECT id, project, direction, contact_method, contact_uri,
              message_body, subject_line, rendered_body, rendered_subject,
              twilio_sid, email_id, sent_at, created_at,
              delivery_status, template_id, campaign_tag
       FROM msgs.messages
       WHERE s3_archive_key IS NULL
       ORDER BY created_at ASC
       LIMIT $1 OFFSET $2`,
      [BATCH_SIZE, offset]
    );

    if (rows.length === 0) break;

    console.log(`[backfill-archive] Processing batch of ${rows.length} rows (offset=${offset})...`);

    for (const row of rows) {
      const channel   = row.contact_method;
      const project   = row.project || '333method';
      const direction = row.direction || 'outbound';

      // Only archive email + SMS
      if (!ARCHIVABLE_CHANNELS.has(channel)) {
        outOfScope++;
        // Mark as archived with a synthetic key so the uploader doesn't pick them up
        if (!DRY_RUN) {
          await run(
            `UPDATE msgs.messages SET s3_archive_key = $1, archived_at = now() WHERE id = $2`,
            [`out-of-scope/${channel}/${row.id}`, row.id]
          );
        }
        continue;
      }

      const ts          = row.sent_at || row.created_at;
      const retainUntil = new Date(new Date(ts).getTime() + RETENTION_MS);

      try {
        let archiveKey;

        if (channel === 'email') {
          const mime    = buildEmailMime(row);
          const bodyStr = mime;
          const emlKey  = buildKey('email', project, direction, ts, bodyStr, null, 'eml');
          const metaKey = emlKey.replace(/\.eml$/, '.meta.json');
          const meta    = JSON.stringify({
            archive_version:  '1',
            reconstructed:    true,
            db_id:            row.id,
            project,
            channel:          'email',
            direction,
            to:               row.contact_uri,
            subject:          row.rendered_subject || row.subject_line || null,
            ses_message_id:   row.email_id          || null,
            sent_at:          row.sent_at            || row.created_at,
            delivery_status:  row.delivery_status,
            template_id:      row.template_id        || null,
            campaign_tag:     row.campaign_tag       || null,
          }, null, 2);

          if (!DRY_RUN) {
            await putToS3(s3, emlKey,  mime, 'message/rfc822',   retainUntil);
            await putToS3(s3, metaKey, meta, 'application/json', retainUntil);
            await run(
              `UPDATE msgs.messages SET s3_archive_key = $1, archived_at = now() WHERE id = $2`,
              [emlKey, row.id]
            );
          }
          archiveKey = emlKey;

        } else {
          // SMS
          const json    = buildSmsJson(row);
          const sid     = row.twilio_sid || null;
          const smsKey  = buildKey('sms', project, direction, ts, json, sid, 'json');

          if (!DRY_RUN) {
            await putToS3(s3, smsKey, json, 'application/json', retainUntil);
            await run(
              `UPDATE msgs.messages SET s3_archive_key = $1, archived_at = now() WHERE id = $2`,
              [smsKey, row.id]
            );
            if (sid) sidMatched++;
          }
          archiveKey = smsKey;
        }

        uploaded++;
        if (uploaded % 100 === 0) {
          console.log(`[backfill-archive]   ${uploaded} uploaded so far...`);
        }
        if (DRY_RUN && uploaded <= 5) {
          console.log(`[backfill-archive]   DRY RUN key: ${archiveKey}`);
        }

      } catch (err) {
        errored++;
        console.error(`[backfill-archive]   ERROR row ${row.id}: ${err.message}`);
        // Continue — don't abort the batch on a single row failure
      }
    }

    offset += rows.length;
    if (rows.length < BATCH_SIZE) break; // last batch
  }

  // Summary
  const total = uploaded + skipped + errored + outOfScope;
  console.log('');
  console.log('[backfill-archive] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
  console.log(`[backfill-archive] Done${DRY_RUN ? ' (DRY RUN — no writes)' : ''}`);
  console.log(`[backfill-archive]   Total rows examined : ${total}`);
  console.log(`[backfill-archive]   Uploaded to S3      : ${uploaded}`);
  console.log(`[backfill-archive]   Out of scope        : ${outOfScope}  (form/linkedin/x — marked skipped)`);
  console.log(`[backfill-archive]   Already archived    : ${skipped}`);
  console.log(`[backfill-archive]   Errors              : ${errored}`);
  console.log(`[backfill-archive]   SMS with Twilio SID : ${sidMatched}`);
  console.log('[backfill-archive] ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

  if (errored > 0) {
    console.error(`[backfill-archive] WARN: ${errored} rows could not be archived.`);
    process.exit(1);
  }
}

main().catch(err => {
  console.error(`[backfill-archive] FATAL: ${err.message}`);
  process.exit(1);
});
