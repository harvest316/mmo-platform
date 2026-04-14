-- DR-223: Legal-grade communications archive — Phase 3 schema (333Method)
-- Adds archive tracking columns to m333.messages.
--
-- m333.messages is the 333Method-specific messages table (separate schema from
-- msgs.messages which was covered by migration 001-archive-columns.sql).
--
-- New columns:
--   twilio_sid        — Twilio MessageSid for outbound SMS (not applicable to email
--                       but kept for schema parity with msgs.messages)
--   rendered_body     — Post-spintax rendered HTML body (what the recipient actually received)
--   rendered_subject  — Post-spintax rendered subject line
--   s3_archive_key    — S3 key of the archived object (populated by archive.js or uploader)
--   archived_at       — When the record was confirmed archived to S3

BEGIN;

ALTER TABLE m333.messages
  ADD COLUMN IF NOT EXISTS twilio_sid       TEXT,
  ADD COLUMN IF NOT EXISTS rendered_body    TEXT,
  ADD COLUMN IF NOT EXISTS rendered_subject TEXT,
  ADD COLUMN IF NOT EXISTS s3_archive_key   TEXT,
  ADD COLUMN IF NOT EXISTS archived_at      TIMESTAMPTZ;

-- Partial index: efficient scan by archive-uploader for unarchived rows.
CREATE INDEX IF NOT EXISTS idx_m333_messages_unarchived
  ON m333.messages (created_at)
  WHERE s3_archive_key IS NULL;

-- Index to allow fast lookup by Twilio SID (reconciliation, backfill).
CREATE INDEX IF NOT EXISTS idx_m333_messages_twilio_sid
  ON m333.messages (twilio_sid)
  WHERE twilio_sid IS NOT NULL;

COMMIT;
