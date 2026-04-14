-- DR-223: Legal-grade communications archive — Phase 7 schema
-- Seeds the archiveUploader cron job row in ops.cron_jobs.
-- The handler must be registered in 333Method/src/cron.js HANDLERS before enabling.

BEGIN;

INSERT INTO ops.cron_jobs (
  task_key, name, description,
  handler_type, handler_value,
  interval_value, interval_unit, enabled
) VALUES (
  'archiveUploader',
  'Comms Archive Uploader',
  'Every 60s: drain local spool (~/.local/state/mmo-comms-archive/) + scan DB for unarchived '
  'msgs.messages / crai.messages / msgs.ses_events rows. Upload to S3 with Object Lock compliance '
  'mode and 7-year retention, then mark archived_at.',
  'function', 'archiveUploader',
  1, 'minutes',
  false  -- enabled after archive-uploader.js is written and tested (Phase 7)
) ON CONFLICT (task_key) DO NOTHING;

COMMIT;
