-- DR-223: Legal-grade communications archive — Phase 2 schema
-- Adds archive tracking columns to msgs.messages and crai.messages.
--
-- New columns:
--   msgs.messages:
--     twilio_sid        — Twilio MessageSid for outbound SMS (previously not stored)
--     rendered_body     — Post-spintax rendered message body (what the recipient actually received)
--     rendered_subject  — Post-spintax rendered subject line (email only)
--     s3_archive_key    — S3 key of the archived object (populated by archive.js or uploader)
--     archived_at       — When the record was confirmed archived to S3
--
--   crai.messages:
--     s3_archive_key    — S3 key (populated by archive-uploader scanner)
--     archived_at       — When archived

BEGIN;

-- ── msgs.messages ──────────────────────────────────────────────────────────

ALTER TABLE msgs.messages
  ADD COLUMN IF NOT EXISTS twilio_sid       TEXT,
  ADD COLUMN IF NOT EXISTS rendered_body    TEXT,
  ADD COLUMN IF NOT EXISTS rendered_subject TEXT,
  ADD COLUMN IF NOT EXISTS s3_archive_key   TEXT,
  ADD COLUMN IF NOT EXISTS archived_at      TIMESTAMPTZ;

-- Partial index: efficient scan by archive-uploader for unarchived rows.
-- Only indexes rows where s3_archive_key IS NULL (the unarchived set).
CREATE INDEX IF NOT EXISTS idx_messages_unarchived
  ON msgs.messages (created_at)
  WHERE s3_archive_key IS NULL;

-- Index to allow fast lookup by Twilio SID (reconciliation, backfill).
CREATE INDEX IF NOT EXISTS idx_messages_twilio_sid
  ON msgs.messages (twilio_sid)
  WHERE twilio_sid IS NOT NULL;

-- ── crai.messages ──────────────────────────────────────────────────────────

ALTER TABLE crai.messages
  ADD COLUMN IF NOT EXISTS s3_archive_key TEXT,
  ADD COLUMN IF NOT EXISTS archived_at    TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_crai_messages_unarchived
  ON crai.messages (created_at)
  WHERE s3_archive_key IS NULL;

COMMIT;
