-- DR-223: Legal-grade communications archive — Phase 9 schema
-- Creates tel.worm_test_log for the quarterly WORM tamper-resistance drill.
-- Also seeds the archiveWormDrill cron job row (runs quarterly).

BEGIN;

CREATE TABLE IF NOT EXISTS tel.worm_test_log (
  id        BIGSERIAL PRIMARY KEY,
  ran_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  test_num  INT         NOT NULL,
  action    TEXT        NOT NULL,
  expected  TEXT        NOT NULL,
  actual    TEXT,
  passed    BOOLEAN     NOT NULL,
  aws_error TEXT
);

-- Quick lookup: all results for a given drill run (grouped by ran_at)
CREATE INDEX IF NOT EXISTS idx_worm_test_log_ran_at
  ON tel.worm_test_log (ran_at DESC);

-- Seed the quarterly drill cron job (first Monday of each quarter).
-- The archiveWormDrill handler must be registered in 333Method/src/cron.js HANDLERS.
INSERT INTO ops.cron_jobs (
  task_key, name, description,
  handler_type, handler_value,
  interval_value, interval_unit, enabled
) VALUES (
  'archiveWormDrill',
  'Archive WORM Tamper-Resistance Drill',
  'Quarterly: run the 20-point WORM tamper-resistance test suite against mmo-comms-archive-sandbox. '
  'Alerts if any test row has passed=false. Results logged to tel.worm_test_log.',
  'function', 'archiveWormDrill',
  13, 'weeks',
  false  -- enabled manually after Phase 9 worm-e2e-test.sh is built and verified
) ON CONFLICT (task_key) DO NOTHING;

COMMIT;
