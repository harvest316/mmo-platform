#!/usr/bin/env node
/**
 * Pre-flight check for SES configuration sets (DR-214).
 *
 * Verifies that both the engagement-tracked config set (mmo-outbound) and the
 * no-tracking config set (mmo-outbound-notrack) exist, so that the shared email
 * transport never hits `ConfigurationSetDoesNotExist` at send time.
 *
 * Exit codes:
 *   0  — both config sets exist and pass the strict engagement-tracking check
 *   1  — at least one config set is missing, or engagement-tracking misconfigured
 *   2  — AWS API error (credentials, network, permissions)
 *
 * Usage:
 *   npm run check:ses-config              # strict (default)
 *   node scripts/check-ses-config.mjs --warn-only   # engagement mismatches = warnings
 *
 * Intended invocation points:
 *   - Manual: before a deploy or when debugging send failures
 *   - CI: as part of deploy pipeline
 *   - Cron: daily or hourly (shares the check-ses-reputation cadence on the host)
 */

import { checkSesConfigSets, formatReport } from '../src/ses-config-check.js';

const args = new Set(process.argv.slice(2));
const strict = !args.has('--warn-only');

async function main() {
  const report = await checkSesConfigSets({ strict });
  console.log(formatReport(report));
  process.exit(report.ok ? 0 : 1);
}

main().catch(err => {
  console.error('[check-ses-config] AWS error:', err.message);
  if (err.stack) console.error(err.stack);
  process.exit(2);
});
