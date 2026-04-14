-- DR-223: Legal-grade communications archive — Phase 2 schema
-- Creates msgs.ses_events for SES bounce/complaint/delivery/send notifications.
--
-- Purpose: Cloudflare Workers (email-webhook) cannot write to the local spool.
-- They persist full SNS payloads here; the archive-uploader scanner syncs them
-- to S3 within ~60 seconds.

BEGIN;

CREATE TABLE IF NOT EXISTS msgs.ses_events (
  id             BIGSERIAL PRIMARY KEY,
  event_type     TEXT,          -- Bounce | Complaint | Delivery | Send | Open | Click | ...
  ses_message_id TEXT,          -- SES MessageId from the notification
  recipient      TEXT,          -- Primary affected email address
  payload        JSONB NOT NULL, -- Full SNS message body, verbatim
  received_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  s3_archive_key TEXT,          -- Populated by archive-uploader after S3 PUT
  archived_at    TIMESTAMPTZ
);

-- Uploader scan: unarchived rows ordered by age
CREATE INDEX IF NOT EXISTS idx_ses_events_unarchived
  ON msgs.ses_events (received_at)
  WHERE s3_archive_key IS NULL;

-- Lookup by SES message ID (correlate with msgs.messages.email_id)
CREATE INDEX IF NOT EXISTS idx_ses_events_msgid
  ON msgs.ses_events (ses_message_id)
  WHERE ses_message_id IS NOT NULL;

COMMIT;
